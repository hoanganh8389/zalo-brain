<?php
/**
 * Biz Central Brain — Guru Studio (one page to manage Agent Gurus).
 *
 * [2026-10-26 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 S142-L1…L10 — kind_of (picker filter), FB_MESS ⇒ FB_PAGE migration, guru-studio-view@1,
 * guru-channels@1, guru-folders@1, clone / retire / remove with guards, Global Memory default fix, "ask as a customer" preview.
 * Binding writes stay on BizCity_Channel_Binding::upsert (the inspector's one writer); vault link stays `gurus[]` on the folders (one store).
 *
 * @package BizCity_Channel_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Guru_Studio {

	const NS             = 'bizcity-channel/v1';
	const VAULT_NS       = 'bizcity-vault/v1';
	const CONTRACT_VIEW  = 'guru-studio-view@1';
	const FB_KEY_FLAG    = 'bizcity_cg_fb_guru_key_v1';
	const FB_KEY_LOG     = 'bizcity_cg_fb_guru_key_log';
	const FB_PLATFORM    = 'FB_PAGE';
	const ZALO_PLATFORM  = 'ZALO_PERSONAL';
	const PREVIEW_LIMIT  = 30; // asks per minute per user

	/** @var array<string,callable> Tests: character(id), characters(), bindings(), folders(), owner_id(), default_id(), fb_pages(), zalo_accounts(), now(). */
	public static $seams = array();

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// reflect R5: on `init` (not admin_init) — the old-bot webhook and the cell must see FB_PAGE before any admin opens wp-admin. One autoloaded flag read after it ran.
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 100 );
		add_action( 'bizcity_guru_studio_push_page', array( __CLASS__, 'push_page' ) );
	}

	/* ---------------- kind (D142-12) ---------------- */

	/**
	 * guru | owner (Quản gia) | system (`__global_memory__`…) | legacy_project (`project-<tên>-<8 hex>` of the old Project Service). Pure.
	 *
	 * @param object|array $row character row
	 */
	public static function kind_of( $row, int $owner_id = -1 ): string {
		$row  = (object) $row;
		$id   = (int) ( $row->id ?? 0 );
		$slug = (string) ( $row->slug ?? '' );
		if ( $owner_id < 0 ) {
			$owner_id = self::owner_id();
		}
		if ( $owner_id > 0 && $id === $owner_id ) {
			return 'owner';
		}
		if ( 1 === preg_match( '/^__.+__$/', $slug ) ) {
			return 'system';
		}
		if ( 1 === preg_match( '/^project-.+-[0-9a-f]{8}$/', $slug ) ) {
			return 'legacy_project';
		}
		return 'guru';
	}

	private static function owner_id(): int {
		if ( isset( self::$seams['owner_id'] ) ) {
			return (int) call_user_func( self::$seams['owner_id'] );
		}
		return class_exists( 'BizCity_Guru_Seed' ) ? (int) BizCity_Guru_Seed::owner_character_id() : 0;
	}

	private static function default_id(): int {
		if ( isset( self::$seams['default_id'] ) ) {
			return (int) call_user_func( self::$seams['default_id'] );
		}
		return (int) get_option( 'bizcity_bot_default_character_id', 0 );
	}

	private static function character( int $id ) {
		if ( isset( self::$seams['character'] ) ) {
			return call_user_func( self::$seams['character'], $id );
		}
		return $id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ? BizCity_Knowledge_Database::instance()->get_character( $id ) : null;
	}

	private static function characters(): array {
		if ( isset( self::$seams['characters'] ) ) {
			return (array) call_user_func( self::$seams['characters'] );
		}
		return class_exists( 'BizCity_Knowledge_Database' ) ? (array) BizCity_Knowledge_Database::instance()->get_characters( array( 'limit' => 200 ) ) : array();
	}

	private static function bindings(): array {
		if ( isset( self::$seams['bindings'] ) ) {
			return (array) call_user_func( self::$seams['bindings'] );
		}
		return class_exists( 'BizCity_Channel_Binding' ) ? (array) BizCity_Channel_Binding::all() : array();
	}

	private static function folders(): array {
		if ( isset( self::$seams['folders'] ) ) {
			return (array) call_user_func( self::$seams['folders'] );
		}
		return class_exists( 'BizCity_Twin_Vault', false ) ? (array) BizCity_Twin_Vault::folders() : array();
	}

	/** Active binding row of an exact (platform, account) — no '*' fallback. */
	private static function exact_binding( string $platform, string $account ): ?array {
		foreach ( self::bindings() as $b ) {
			if ( strtoupper( (string) ( $b['platform'] ?? '' ) ) === $platform && (string) ( $b['account_id'] ?? '' ) === $account && (int) ( $b['status'] ?? 1 ) === 1 ) {
				return $b;
			}
		}
		return null;
	}

	/** gate for the cell's guru routes (S142-L3): does ANY active binding of this blog use the Guru? */
	public static function guru_has_binding( int $character_id ): bool {
		if ( $character_id <= 0 ) {
			return false;
		}
		foreach ( self::bindings() as $b ) {
			if ( (int) ( $b['character_id'] ?? 0 ) === $character_id && (int) ( $b['status'] ?? 1 ) === 1 ) {
				$c = self::character( $character_id );
				return $c && 'guru' === self::kind_of( $c );
			}
		}
		return false;
	}

	/* ---------------- migration FB_MESS ⇒ FB_PAGE (D142-5) ---------------- */

	/** cron: tell the cell the page's Guru after the migration moved it (cell_direct pages only; others read the binding live) */
	public static function push_page( $page ): void {
		if ( class_exists( 'BizCity_FB_Cell_Direct', false ) ) {
			BizCity_FB_Cell_Direct::push_if_active( (string) $page );
		}
	}

	public static function maybe_migrate(): void {
		if ( ! get_option( self::FB_KEY_FLAG ) ) {
			self::migrate_fb_guru_key();
		}
	}

	/**
	 * Once per blog. FB_MESS with a Guru and no FB_PAGE ⇒ copy; FB_PAGE without a Guru ⇒ fill; both different ⇒ keep FB_PAGE (the one customers meet).
	 *
	 * @return array{copied:int,filled:int,conflict_kept_page:int}
	 */
	public static function migrate_fb_guru_key( bool $force = false ): array {
		$log = array( 'copied' => 0, 'filled' => 0, 'conflict_kept_page' => 0 );
		if ( ! $force && get_option( self::FB_KEY_FLAG ) ) {
			return $log;
		}
		if ( ! isset( self::$seams['bindings'] ) && ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return $log;
		}
		$rows  = self::bindings();
		$page  = array();
		$moved = array();
		foreach ( $rows as $b ) {
			if ( self::FB_PLATFORM === strtoupper( (string) ( $b['platform'] ?? '' ) ) ) {
				$page[ (string) $b['account_id'] ] = $b;
			}
		}
		foreach ( $rows as $b ) {
			if ( 'FB_MESS' !== strtoupper( (string) ( $b['platform'] ?? '' ) ) || (int) ( $b['character_id'] ?? 0 ) <= 0 || '*' === (string) $b['account_id'] ) {
				continue;
			}
			$acc = (string) $b['account_id'];
			$p   = $page[ $acc ] ?? null;
			if ( ! $p ) {
				self::write_binding( self::FB_PLATFORM, $acc, (int) $b['character_id'], $b );
				$log['copied']++;
				$moved[] = $acc;
			} elseif ( (int) ( $p['character_id'] ?? 0 ) <= 0 ) {
				self::write_binding( self::FB_PLATFORM, $acc, (int) $b['character_id'], $p );
				$log['filled']++;
				$moved[] = $acc;
			} elseif ( (int) $p['character_id'] !== (int) $b['character_id'] ) {
				$log['conflict_kept_page']++;
			}
		}
		if ( ! isset( self::$seams['bindings'] ) ) {
			update_option( self::FB_KEY_FLAG, gmdate( 'c' ), true );
			update_option( self::FB_KEY_LOG, $log, false );
			foreach ( $moved as $i => $acc ) {
				if ( function_exists( 'wp_schedule_single_event' ) ) {
					wp_schedule_single_event( time() + 5 + $i, 'bizcity_guru_studio_push_page', array( $acc ) );
				}
			}
		}
		return $log;
	}

	/* ---------------- binding write (one writer: Channel_Binding::upsert) ---------------- */

	/**
	 * Upsert changing ONLY the Guru: mode / auto_reply / pool / meta of an existing row are carried over (upsert() rewrites them).
	 *
	 * @param array|null $like row whose settings to keep (the target row, or a source row on copy)
	 */
	private static function write_binding( string $platform, string $account, int $character_id, ?array $like = null ): int {
		$like = $like ?? self::exact_binding( $platform, $account );
		$args = array(
			'platform'     => $platform,
			'account_id'   => $account,
			'character_id' => $character_id,
			'mode'         => (string) ( $like['mode'] ?? 'auto' ) ?: 'auto',
		);
		$pool = json_decode( (string) ( $like['responder_pool_json'] ?? '' ), true );
		if ( is_array( $pool ) && $pool ) {
			$args['responder_pool'] = $pool;
		}
		$meta = json_decode( (string) ( $like['meta_json'] ?? '' ), true );
		if ( is_array( $meta ) && $meta ) {
			$args['meta'] = $meta;
		}
		if ( isset( $like['fallback_assignee'] ) && null !== $like['fallback_assignee'] ) {
			$args['fallback_assignee'] = (int) $like['fallback_assignee'];
		}
		if ( isset( self::$seams['binding_upsert'] ) ) {
			return (int) call_user_func( self::$seams['binding_upsert'], $args );
		}
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return 0;
		}
		$id = (int) BizCity_Channel_Binding::upsert( $args );
		if ( $id > 0 && function_exists( 'do_action' ) ) {
			do_action( 'bizcity_channel_binding_upserted', $platform, $account, $character_id, $id );
		}
		return $id;
	}

	/* ---------------- icon + colour (PHASE-0.144 C-BM7) ---------------- */

	/** [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CL2 — brandbook colour tokens a Guru may wear (C-BM7). */
	const UI_COLORS  = array( 'indigo', 'emerald', 'amber', 'rose', 'sky', 'slate' );
	const UI_ICON    = '🤖'; // R-AGENT-ICON default
	const UI_COLOR   = 'slate';

	/**
	 * [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CL2 (C-BM7, doc 30 BM-4) — whitelist of `characters.settings.ui = {icon, color}` (pure).
	 * icon: 1–8 non-ASCII code points (an emoji, with its variation selector / ZWJ parts) — no letters, no markup; anything else ⇒ 🤖.
	 * color: one of UI_COLORS, else slate. Used by the writer (quick-edit save) and every reader (Guru Studio view, guru-stats@1).
	 *
	 * @param mixed $ui stored / posted `ui`
	 * @return array{icon:string,color:string}
	 */
	public static function sanitize_ui( $ui ): array {
		$ui    = is_array( $ui ) ? $ui : array();
		$icon  = is_scalar( $ui['icon'] ?? null ) ? trim( strip_tags( (string) $ui['icon'] ) ) : '';
		$icon  = ( '' !== $icon && 1 === preg_match( '/^[^\x00-\x7F]{1,8}$/u', $icon ) ) ? $icon : self::UI_ICON;
		$color = is_scalar( $ui['color'] ?? null ) ? strtolower( trim( (string) $ui['color'] ) ) : '';
		return array(
			'icon'  => $icon,
			'color' => in_array( $color, self::UI_COLORS, true ) ? $color : self::UI_COLOR,
		);
	}

	/**
	 * [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CL2 — `ui` of a character row (settings JSON string or array). Pure.
	 *
	 * @param mixed $settings raw `settings` column
	 * @return array{icon:string,color:string}
	 */
	public static function ui_of( $settings ): array {
		$s = is_array( $settings ) ? $settings : json_decode( (string) $settings, true );
		return self::sanitize_ui( is_array( $s ) ? ( $s['ui'] ?? null ) : null );
	}

	/* ---------------- view (guru-studio-view@1) ---------------- */

	public static function build_view(): array {
		$owner_id   = self::owner_id();
		$default_id = self::default_id();
		$folders    = self::folders();
		// [2026-10-26 11:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 D142-17 — sources (tài liệu đưa vào để học) vs knowledge (bản đọc markdown = what the Guru actually knows)
		$counts     = self::folder_counts( false );
		$know       = self::folder_counts( true );
		$bindings   = self::bindings();
		$chan_by_guru = array();
		foreach ( $bindings as $b ) {
			if ( (int) ( $b['status'] ?? 1 ) === 1 && (int) ( $b['character_id'] ?? 0 ) > 0 && in_array( strtoupper( (string) $b['platform'] ), array( self::ZALO_PLATFORM, self::FB_PLATFORM ), true ) && '*' !== (string) $b['account_id'] ) {
				$cid                  = (int) $b['character_id'];
				$chan_by_guru[ $cid ] = ( $chan_by_guru[ $cid ] ?? 0 ) + 1;
			}
		}
		$faq = self::faq_counts();
		$nb  = self::notebook_counts();
		$access = array();
		$fout   = array();
		foreach ( $folders as $f ) {
			$n            = (string) $f['n'];
			$access[ $n ] = (string) ( $f['access'] ?? 'staff' );
			$fout[]       = array( 'n' => $n, 'label' => (string) ( $f['label'] ?? $n ), 'icon' => (string) ( $f['icon'] ?? '' ), 'access' => $access[ $n ], 'docs' => (int) ( $counts[ $n ] ?? 0 ), 'knowledge' => (int) ( $know[ $n ] ?? 0 ), 'gurus' => array_values( array_map( 'intval', (array) ( $f['gurus'] ?? array() ) ) ) );
		}
		$gurus = array();
		foreach ( self::characters() as $c ) {
			$id       = (int) $c->id;
			$kind     = self::kind_of( $c, $owner_id );
			$settings = json_decode( (string) ( $c->settings ?? '' ), true );
			$role     = is_array( $settings ) ? (string) ( $settings['bot']['scope']['role'] ?? '' ) : '';
			$mine     = array();
			foreach ( $fout as $f ) {
				if ( in_array( $id, $f['gurus'], true ) ) {
					$mine[] = $f['n'];
				}
			}
			$ch    = (int) ( $chan_by_guru[ $id ] ?? 0 );
			$flags = array();
			if ( 'guru' === $kind && $ch > 0 ) {
				$public = array_filter( $mine, static function ( $n ) use ( $access ) { return 'public' === ( $access[ $n ] ?? '' ); } );
				if ( ! $public ) {
					$flags[] = 'no_public_folder';
				}
				if ( count( $public ) < count( $mine ) ) {
					$flags[] = 'private_folder_for_customers';
				}
				foreach ( $mine as $n ) {
					if ( 0 === (int) ( $know[ $n ] ?? 0 ) ) {
						$flags[] = 'empty_folder'; // no markdown reading yet ⇒ the Guru learned nothing from this folder
						break;
					}
				}
			}
			$status   = strtolower( (string) ( $c->status ?? '' ) );
			$gurus[] = array(
				'id'          => $id,
				'name'        => (string) $c->name,
				'slug'        => (string) ( $c->slug ?? '' ),
				'avatar'      => (string) ( $c->avatar ?? '' ),
				'status'      => in_array( $status, array( 'active', 'published' ), true ) ? 'active' : 'draft',
				'kind'        => $kind,
				'role'        => $role,
				'is_default'  => $default_id > 0 && $id === $default_id,
				'prompt_head' => mb_substr( trim( (string) ( $c->system_prompt ?? '' ) ), 0, 160 ),
				'faq_count'   => (int) ( $faq[ $id ] ?? 0 ),
				'folders'     => $mine,
				'notebooks'   => (int) ( $nb[ $id ] ?? 0 ),
				'docs'        => array_sum( array_map( static function ( $n ) use ( $know ) { return (int) ( $know[ $n ] ?? 0 ); }, $mine ) ), // what the Guru knows (bản đọc)
				'sources'     => array_sum( array_map( static function ( $n ) use ( $counts ) { return (int) ( $counts[ $n ] ?? 0 ); }, $mine ) ),
				'channels'    => $ch,
				'flags'       => $flags,
				'ui'          => self::sanitize_ui( is_array( $settings ) ? ( $settings['ui'] ?? null ) : null ), // [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CL2 — C-BM7 icon + colour
			);
		}
		$warnings = array();
		$bound    = array();
		foreach ( $gurus as $g ) {
			if ( $g['is_default'] && 'guru' !== $g['kind'] ) {
				$warnings[] = array( 'code' => 'system_default', 'guru_id' => $g['id'] );
			}
			// [2026-10-26 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 reflect R1 — a number / page bound DIRECTLY to Global Memory (or an old project
			// Guru, or the Quản gia) keeps answering with it even after the default is fixed: its own warning, fixed by the same card.
			if ( 'guru' !== $g['kind'] && $g['channels'] > 0 ) {
				$bound[ $g['id'] ] = $g['channels'];
			}
		}
		if ( $bound ) {
			$warnings[] = array( 'code' => 'system_bound', 'gurus' => array_keys( $bound ), 'count' => array_sum( $bound ) );
		}
		$log = get_option( self::FB_KEY_LOG, array() );
		if ( is_array( $log ) && (int) ( $log['conflict_kept_page'] ?? 0 ) > 0 ) {
			$warnings[] = array( 'code' => 'fb_key_conflict', 'count' => (int) $log['conflict_kept_page'] );
		}
		return array(
			'contract'        => self::CONTRACT_VIEW,
			'default_guru_id' => $default_id,
			'owner_guru_id'   => $owner_id,
			'gurus'           => $gurus,
			'channels'        => self::channels_view( $bindings ),
			'folders'         => $fout,
			'warnings'        => $warnings,
		);
	}

	/** Zalo numbers + Fanpages of the site (cell_direct pages, plus pages that only have a binding — e.g. Composio). Labels masked (PRIV). */
	private static function channels_view( array $bindings ): array {
		$by = array();
		foreach ( $bindings as $b ) {
			if ( (int) ( $b['status'] ?? 1 ) === 1 ) {
				$by[ strtoupper( (string) $b['platform'] ) . '|' . (string) $b['account_id'] ] = $b;
			}
		}
		$out = array();
		$zalo = isset( self::$seams['zalo_accounts'] ) ? (array) call_user_func( self::$seams['zalo_accounts'] ) : ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) ) : array() );
		foreach ( $zalo as $a ) {
			$acc = (string) ( ( $a['bridge_account_id'] ?? '' ) !== '' ? $a['bridge_account_id'] : ( $a['id'] ?? '' ) );
			if ( '' === $acc ) {
				continue;
			}
			$b     = $by[ self::ZALO_PLATFORM . '|' . $acc ] ?? null;
			$out[] = array(
				'platform' => self::ZALO_PLATFORM, 'account_id' => $acc,
				'label'    => self::mask( (string) ( ( $a['label'] ?? '' ) !== '' ? $a['label'] : ( $a['account_name'] ?? $acc ) ) ),
				'delivery' => 'zalo', 'guru_id' => (int) ( $b['character_id'] ?? 0 ), 'auto_reply' => (int) ( $b['auto_reply'] ?? 0 ), 'mode' => (string) ( $b['mode'] ?? '' ),
			);
		}
		$pages = isset( self::$seams['fb_pages'] ) ? (array) call_user_func( self::$seams['fb_pages'] ) : ( class_exists( 'BizCity_FB_Channel_Adapter', false ) ? (array) BizCity_FB_Channel_Adapter::pages() : array() );
		$seen  = array();
		foreach ( $pages as $pid => $p ) {
			$pid   = (string) $pid;
			$b     = $by[ self::FB_PLATFORM . '|' . $pid ] ?? null;
			$cell  = class_exists( 'BizCity_FB_Cell_Direct', false ) && BizCity_FB_Cell_Direct::is_cell_direct( $pid );
			$out[] = array(
				'platform' => self::FB_PLATFORM, 'account_id' => $pid,
				'label'    => (string) ( is_array( $p ) ? ( $p['name'] ?? $p['page_name'] ?? 'Fanpage ' . $pid ) : 'Fanpage ' . $pid ),
				'delivery' => $cell ? 'cell_direct' : 'legacy', 'guru_id' => (int) ( $b['character_id'] ?? 0 ), 'auto_reply' => (int) ( $b['auto_reply'] ?? 0 ), 'mode' => (string) ( $b['mode'] ?? '' ),
			);
			$seen[ $pid ] = true;
		}
		foreach ( $by as $key => $b ) {
			if ( 0 === strpos( $key, self::FB_PLATFORM . '|' ) && ! isset( $seen[ (string) $b['account_id'] ] ) && '*' !== (string) $b['account_id'] ) {
				$out[] = array( 'platform' => self::FB_PLATFORM, 'account_id' => (string) $b['account_id'], 'label' => 'Fanpage ' . $b['account_id'], 'delivery' => 'composio', 'guru_id' => (int) $b['character_id'], 'auto_reply' => (int) $b['auto_reply'], 'mode' => (string) $b['mode'] );
			}
		}
		return $out;
	}

	/** phone-like digit runs ⇒ 0562 ••• 899 */
	public static function mask( string $s ): string {
		return (string) preg_replace_callback( '/(?:\d[\s.\-]?){8,}/', static function ( $m ) {
			$d = preg_replace( '/\D/', '', $m[0] );
			return substr( $d, 0, 4 ) . ' ••• ' . substr( $d, -3 );
		}, $s );
	}

	private static function folder_counts( bool $knowledge = false ): array {
		if ( isset( self::$seams['folder_counts'] ) ) {
			return (array) call_user_func( self::$seams['folder_counts'], $knowledge );
		}
		// [2026-10-26 11:49 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 R-SCOPE-FOLDER SF-5 — counted per folder IN the index (not the first 100 rows of tree())
		if ( class_exists( 'BizCity_Twin_Vault', false ) && method_exists( 'BizCity_Twin_Vault', 'folder_counts' ) ) {
			try {
				return (array) BizCity_Twin_Vault::folder_counts( (int) get_current_user_id(), $knowledge );
			} catch ( Throwable $e ) {
				return array();
			}
		}
		$out = array();
		if ( class_exists( 'BizCity_Twin_Vault', false ) ) {
			try {
				$tree = BizCity_Twin_Vault::tree( array( 'owner_user_id' => (int) get_current_user_id(), 'vrole' => 'owner' ), 'folders' );
				foreach ( (array) ( $tree['folders']['list'] ?? array() ) as $f ) {
					$out[ (string) $f['n'] ] = (int) ( $f['count'] ?? 0 );
				}
			} catch ( \Throwable $e ) { // a vault hiccup must not blank the page
				$out = array();
			}
		}
		return $out;
	}

	private static function faq_counts(): array {
		global $wpdb;
		if ( isset( self::$seams['faq_counts'] ) ) {
			return (array) call_user_func( self::$seams['faq_counts'] );
		}
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$t    = $wpdb->prefix . 'bizcity_knowledge_sources';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return array(); // a missing table must not print a DB error into the JSON answer
		}
		$rows = $wpdb->get_results( "SELECT character_id, COUNT(*) c FROM `{$t}` WHERE source_type = 'quick_faq' AND status = 'ready' GROUP BY character_id", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['character_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	private static function notebook_counts(): array {
		global $wpdb;
		if ( isset( self::$seams['notebook_counts'] ) ) {
			return (array) call_user_func( self::$seams['notebook_counts'] );
		}
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$t = $wpdb->prefix . 'bizcity_notebook_character_attachments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT character_id, COUNT(*) c FROM `{$t}` GROUP BY character_id", ARRAY_A ) as $r ) {
			$out[ (int) $r['character_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/* ---------------- guru-channels@1 ---------------- */

	/**
	 * @param array $add    [{platform, account_id, reply_mode?}]
	 * @param array $remove [{platform, account_id}]
	 * @return array{ok:bool,applied:array,failed:array}
	 */
	public static function apply_channels( int $guru_id, array $add, array $remove ): array {
		$applied = array();
		$failed  = array();
		$c       = self::character( $guru_id );
		if ( ! $c ) {
			return array( 'ok' => false, 'applied' => array(), 'failed' => array( array( 'code' => 'guru_not_found' ) ) );
		}
		if ( 'guru' !== self::kind_of( $c ) ) {
			return array( 'ok' => false, 'applied' => array(), 'failed' => array( array( 'code' => 'guru_not_assignable' ) ) );
		}
		if ( ! in_array( strtolower( (string) ( $c->status ?? '' ) ), array( 'active', 'published' ), true ) && $add ) {
			return array( 'ok' => false, 'applied' => array(), 'failed' => array( array( 'code' => 'guru_inactive' ) ) );
		}
		foreach ( $add as $it ) {
			$platform = strtoupper( trim( (string) ( $it['platform'] ?? '' ) ) );
			$acc      = trim( (string) ( $it['account_id'] ?? '' ) );
			if ( ! in_array( $platform, array( self::ZALO_PLATFORM, self::FB_PLATFORM ), true ) || '' === $acc || '*' === $acc ) {
				$failed[] = array( 'platform' => $platform, 'account_id' => $acc, 'code' => 'invalid_channel' );
				continue;
			}
			$before = self::exact_binding( $platform, $acc );
			$from   = (int) ( $before['character_id'] ?? 0 );
			if ( $guru_id === $from ) {
				$applied[] = array( 'platform' => $platform, 'account_id' => $acc, 'from_guru' => $from, 'to_guru' => $guru_id, 'pushed' => 'unchanged' );
				continue;
			}
			if ( self::write_binding( $platform, $acc, $guru_id, $before ) <= 0 ) {
				$failed[] = array( 'platform' => $platform, 'account_id' => $acc, 'code' => 'save_failed' );
				continue;
			}
			$pushed = self::FB_PLATFORM === $platform ? self::push_fb( $acc, $guru_id, (string) ( $it['reply_mode'] ?? '' ), (string) ( $it['delivery'] ?? '' ) ) : 'none';
			if ( 'failed' === $pushed || 'mode_unknown' === $pushed ) {
				// the binding IS saved (the site, guru_gate and the old bot use it); only the copy on the cell is behind — say so, the UI offers "thử lại"
				$failed[] = array( 'platform' => $platform, 'account_id' => $acc, 'code' => 'failed' === $pushed ? 'composio_push_failed' : 'composio_mode_unknown' );
			}
			$applied[] = array( 'platform' => $platform, 'account_id' => $acc, 'from_guru' => $from, 'to_guru' => $guru_id, 'pushed' => $pushed );
		}
		foreach ( $remove as $it ) {
			$platform = strtoupper( trim( (string) ( $it['platform'] ?? '' ) ) );
			$acc      = trim( (string) ( $it['account_id'] ?? '' ) );
			$b        = self::exact_binding( $platform, $acc );
			if ( $b && (int) $b['character_id'] === $guru_id ) {
				self::disable_binding( (int) $b['id'] );
				$applied[] = array( 'platform' => $platform, 'account_id' => $acc, 'from_guru' => $guru_id, 'to_guru' => 0, 'pushed' => 'none' );
			}
		}
		return array( 'ok' => ! $failed, 'applied' => $applied, 'failed' => $failed );
	}

	/**
	 * Where the page's Guru must ALSO be told: cell_direct ⇒ push channel-config; a Composio page ⇒ PUT pages/{id}/auto {mode, guru_id} (the cell applies
	 * guru_id only together with a mode). An old-bot page (`legacy`) needs no push — the old path reads the FB_PAGE binding (UCL, PHASE-0.142).
	 * `$delivery` is the UI's hint (Composio pages are known only to the cell); `reply_mode` present = the page is a Composio page too.
	 *
	 * @return string cell_direct | composio | none | mode_unknown | failed
	 */
	private static function push_fb( string $page, int $guru_id, string $reply_mode, string $delivery = '' ): string {
		if ( isset( self::$seams['push_fb'] ) ) {
			return (string) call_user_func( self::$seams['push_fb'], $page, $guru_id, $reply_mode, $delivery );
		}
		if ( class_exists( 'BizCity_FB_Cell_Direct', false ) && BizCity_FB_Cell_Direct::is_cell_direct( $page ) ) {
			BizCity_FB_Cell_Direct::push_if_active( $page );
			return 'cell_direct';
		}
		if ( ! in_array( $reply_mode, array( 'manual', 'assisted', 'auto' ), true ) ) {
			return 'composio' === $delivery ? 'mode_unknown' : 'none';
		}
		if ( ! function_exists( 'rest_do_request' ) ) {
			return 'failed';
		}
		$req = new WP_REST_Request( 'PUT', '/' . self::NS . '/connect/composio/pages/' . $page . '/auto' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'mode' => $reply_mode, 'guru_id' => $guru_id ) ) );
		$res = rest_do_request( $req );
		return $res->get_status() >= 200 && $res->get_status() < 300 ? 'composio' : 'failed';
	}

	/* ---------------- guru-folders@1 ---------------- */

	/** @return array{added:array,removed:array,flags:array}|WP_Error */
	public static function set_guru_folders( int $guru_id, array $want ) {
		$c = self::character( $guru_id );
		if ( ! $c ) {
			return new WP_Error( 'guru_not_found', 'Guru không tồn tại.', array( 'status' => 404 ) );
		}
		$want    = array_values( array_unique( array_map( 'strval', $want ) ) );
		$added   = array();
		$removed = array();
		$flags   = array();
		$has_ch  = false;
		foreach ( self::bindings() as $b ) {
			if ( (int) ( $b['character_id'] ?? 0 ) === $guru_id && (int) ( $b['status'] ?? 1 ) === 1 ) {
				$has_ch = true;
				break;
			}
		}
		foreach ( self::folders() as $f ) {
			$n    = (string) $f['n'];
			$ids  = array_values( array_map( 'intval', (array) ( $f['gurus'] ?? array() ) ) );
			$in   = in_array( $guru_id, $ids, true );
			$take = in_array( $n, $want, true );
			if ( $take && ! $in ) {
				$ids[] = $guru_id;
				$added[] = $n;
			} elseif ( ! $take && $in ) {
				$ids = array_values( array_diff( $ids, array( $guru_id ) ) );
				$removed[] = $n;
			} else {
				if ( $take && $has_ch && 'public' !== (string) ( $f['access'] ?? '' ) ) {
					$flags[] = 'private_folder_for_customers:' . $n;
				}
				continue;
			}
			if ( ! isset( self::$seams['update_folder'] ) && ! class_exists( 'BizCity_Twin_Vault', false ) ) {
				return new WP_Error( 'module_not_loaded', 'Kho tri thức chưa bật trên site.', array( 'status' => 503 ) );
			}
			$out = isset( self::$seams['update_folder'] ) ? call_user_func( self::$seams['update_folder'], $n, array( 'gurus' => $ids ) ) : BizCity_Twin_Vault::update_folder( $n, array( 'gurus' => $ids ) );
			if ( is_wp_error( $out ) ) {
				return $out;
			}
			if ( $take && $has_ch && 'public' !== (string) ( $f['access'] ?? '' ) ) {
				$flags[] = 'private_folder_for_customers:' . $n;
			}
		}
		if ( ( $added || $removed ) && function_exists( 'do_action' ) ) {
			do_action( 'bizcity_bot_config_changed', 'character', $guru_id ); // etag of the profile moves ⇒ the cell pulls scope.vault_folders again
		}
		return array( 'added' => $added, 'removed' => $removed, 'flags' => $flags );
	}

	/* ---------------- clone · activate · retire · remove ---------------- */

	/**
	 * The Guru table owner, or an error the REST answer can carry (no fatal when the Guru module is off).
	 * [2026-10-26 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.142 reflect R3.
	 *
	 * @return object|WP_Error
	 */
	private static function db() {
		if ( isset( self::$seams['db'] ) ) {
			return call_user_func( self::$seams['db'] );
		}
		return class_exists( 'BizCity_Knowledge_Database' ) ? BizCity_Knowledge_Database::instance() : new WP_Error( 'module_not_loaded', 'Module Agent Guru chưa bật trên site.', array( 'status' => 503 ) );
	}

	private static function disable_binding( int $binding_id ): void {
		if ( isset( self::$seams['binding_disable'] ) ) {
			call_user_func( self::$seams['binding_disable'], $binding_id );
		} elseif ( class_exists( 'BizCity_Channel_Binding' ) ) {
			BizCity_Channel_Binding::disable( $binding_id ); // the channel falls back to the default Guru (gate 0)
		}
	}

	/** @return int|WP_Error new Guru id */
	public static function clone_guru( int $id ) {
		$c = self::character( $id );
		if ( ! $c || 'guru' !== self::kind_of( $c ) ) {
			return new WP_Error( 'guru_not_found', 'Không nhân bản được Guru này.', array( 'status' => 404 ) );
		}
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$new = $db->create_character( array(
			'name'          => mb_substr( (string) $c->name, 0, 90 ) . ' (bản sao)',
			'slug'          => ( sanitize_title( (string) $c->name ) ?: 'guru' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 ),
			'avatar'        => (string) ( $c->avatar ?? '' ),
			'description'   => (string) ( $c->description ?? '' ),
			'system_prompt' => (string) ( $c->system_prompt ?? '' ),
			'settings'      => (string) ( $c->settings ?? '{}' ),
			'status'        => 'draft',
		) );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$new = (int) $new;
		if ( $new <= 0 ) {
			return new WP_Error( 'save_failed', 'Chưa tạo được bản sao.', array( 'status' => 500 ) );
		}
		global $wpdb;
		if ( isset( $wpdb ) && ! isset( self::$seams['db'] ) ) {
			$t = $wpdb->prefix . 'bizcity_knowledge_sources'; // quick FAQ rows travel with the clone
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE character_id = %d AND source_type = 'quick_faq' AND status = 'ready'", $id ), ARRAY_A ) as $row ) {
				unset( $row['id'] );
				$row['character_id'] = $new;
				$wpdb->insert( $t, $row );
			}
		}
		$folders = self::guru_folder_numbers( $id );
		if ( $folders ) {
			self::set_guru_folders( $new, $folders );
		}
		return $new;
	}

	private static function guru_folder_numbers( int $id ): array {
		$out = array();
		foreach ( self::folders() as $f ) {
			if ( in_array( $id, array_map( 'intval', (array) ( $f['gurus'] ?? array() ) ), true ) ) {
				$out[] = (string) $f['n'];
			}
		}
		return $out;
	}

	/** channels of this Guru fall back to the default Guru */
	private static function release_channels( int $id ): int {
		$n = 0;
		foreach ( self::bindings() as $b ) {
			if ( (int) ( $b['character_id'] ?? 0 ) === $id && (int) ( $b['status'] ?? 1 ) === 1 ) {
				self::disable_binding( (int) $b['id'] );
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Guards shared by retire + remove: never the default (pick another first), never the Quản gia, never a system character —
	 * deleting `__global_memory__` would delete the users' memory store (reflect R2).
	 *
	 * @return object|WP_Error
	 */
	private static function guard( int $id ) {
		$c = self::character( $id );
		if ( ! $c ) {
			return new WP_Error( 'guru_not_found', 'Guru không tồn tại.', array( 'status' => 404 ) );
		}
		if ( $id === self::default_id() ) {
			return new WP_Error( 'guru_is_default', 'Đây là Guru mặc định — đặt Guru khác làm mặc định trước.', array( 'status' => 409 ) );
		}
		$kind = self::kind_of( $c );
		if ( 'owner' === $kind ) {
			return new WP_Error( 'guru_is_owner', 'Quản gia không tạm tắt hay xoá được.', array( 'status' => 409 ) );
		}
		if ( 'system' === $kind ) {
			return new WP_Error( 'guru_is_system', 'Đây là bộ nhớ hệ thống, không phải Guru — không tắt hay xoá được.', array( 'status' => 409 ) );
		}
		return $c;
	}

	/** draft ⇒ active (a Guru made by the 3-step wizard starts as a draft until the owner has seen it). @return array{id:int}|WP_Error */
	public static function activate_guru( int $id ) {
		$c = self::character( $id );
		if ( ! $c || 'guru' !== self::kind_of( $c ) ) {
			return new WP_Error( 'guru_not_found', 'Không bật được Guru này.', array( 'status' => 404 ) );
		}
		$db = self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$db->update_character( $id, array( 'status' => 'active' ) );
		return array( 'id' => $id );
	}

	/** @return array{released:int}|WP_Error */
	public static function retire_guru( int $id ) {
		$c = self::guard( $id );
		$db = is_wp_error( $c ) ? $c : self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$db->update_character( $id, array( 'status' => 'draft' ) );
		return array( 'released' => self::release_channels( $id ) );
	}

	/** @return array{released:int}|WP_Error */
	public static function remove_guru( int $id ) {
		$c = self::guard( $id );
		$db = is_wp_error( $c ) ? $c : self::db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$released = self::release_channels( $id );
		if ( self::guru_folder_numbers( $id ) ) {
			$unlinked = self::set_guru_folders( $id, array() ); // the folders themselves stay
			if ( is_wp_error( $unlinked ) ) {
				return $unlinked; // never delete a Guru still listed in a folder's gurus[] (a dangling id would be read by the scope matrix)
			}
		}
		$db->delete_character( $id );
		return array( 'released' => $released );
	}

	/* ---------------- Global Memory / Quản gia / old Guru answering a channel (D142-13) ---------------- */

	/**
	 * One card fixes both cases (reflect R1): the default Guru is not a real Guru ⇒ the seeded Đối ngoại becomes the default; every channel bound
	 * DIRECTLY to a non-Guru (system / old project / Quản gia) ⇒ moved to the default Guru. The old instruction is copied only into an empty Đối ngoại.
	 *
	 * @return array{to:int,moved:int}|WP_Error
	 */
	public static function fix_default() {
		$cur_id  = self::default_id();
		$cur     = self::character( $cur_id );
		$bad_def = $cur_id > 0 && ( ! $cur || 'guru' !== self::kind_of( $cur ) );
		$bad     = array();
		foreach ( self::bindings() as $b ) {
			$cid = (int) ( $b['character_id'] ?? 0 );
			if ( $cid > 0 && (int) ( $b['status'] ?? 1 ) === 1 ) {
				$c = self::character( $cid );
				if ( $c && 'guru' !== self::kind_of( $c ) ) {
					$bad[] = $b;
				}
			}
		}
		if ( ! $bad_def && ! $bad ) {
			return new WP_Error( 'nothing_to_fix', 'Không còn kênh nào trả lời bằng thứ không phải Guru.', array( 'status' => 409 ) );
		}
		$to_id = $cur_id;
		if ( $bad_def || $to_id <= 0 ) {
			$db = self::db();
			if ( is_wp_error( $db ) ) {
				return $db;
			}
			if ( class_exists( 'BizCity_Guru_Seed' ) && ! isset( self::$seams['db'] ) ) {
				BizCity_Guru_Seed::seed(); // creates Đối ngoại (the seed no longer adopts a system character, reflect R1)
			}
			$to = $db->get_character_by_slug( 'bizcity-default-guru' );
			if ( ! $to || 'guru' !== self::kind_of( $to ) ) {
				return new WP_Error( 'no_outward', 'Chưa có Guru Đối ngoại để chuyển sang.', array( 'status' => 409, 'hint' => 'Bấm “+ Tạo Guru”, đặt Guru đó làm mặc định rồi thử lại.' ) );
			}
			$to_id = (int) $to->id;
			if ( $cur && '' === trim( (string) ( $to->system_prompt ?? '' ) ) && '' !== trim( (string) ( $cur->system_prompt ?? '' ) ) ) {
				$db->update_character( $to_id, array( 'system_prompt' => (string) $cur->system_prompt ) );
			}
			$ok = isset( self::$seams['set_default'] ) ? call_user_func( self::$seams['set_default'], $to_id ) : ( class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::set_default( $to_id ) : true );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$moved = 0;
		foreach ( $bad as $b ) {
			if ( self::write_binding( strtoupper( (string) $b['platform'] ), (string) $b['account_id'], $to_id, $b ) > 0 ) {
				$moved++;
			}
		}
		return array( 'to' => $to_id, 'moved' => $moved );
	}

	/* ---------------- "ask as a customer" (D142-16 v1) ---------------- */

	/** @return array{passages:array}|WP_Error */
	public static function preview( int $guru_id, string $text ) {
		$text = trim( mb_substr( $text, 0, 200 ) );
		if ( '' === $text ) {
			return new WP_Error( 'empty', 'Gõ một câu hỏi.', array( 'status' => 400 ) );
		}
		$key = 'bzc_gs_prev_' . get_current_user_id();
		$n   = (int) get_transient( $key );
		if ( $n >= self::PREVIEW_LIMIT ) {
			return new WP_Error( 'rate_limited', 'Hỏi thử nhiều quá — đợi một phút.', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, 60 );
		$rows = isset( self::$seams['public_passages'] )
			? (array) call_user_func( self::$seams['public_passages'], $guru_id, $text )
			: ( class_exists( 'BizCity_Twin_Vault', false ) ? (array) BizCity_Twin_Vault::public_passages( $guru_id, $text, (int) get_current_user_id(), 5 ) : array() );
		return array( 'passages' => $rows, 'folders' => class_exists( 'BizCity_Twin_Vault', false ) ? array_values( (array) BizCity_Twin_Vault::public_folders_for_guru( $guru_id ) ) : array() );
	}

	/* ---------------- REST ---------------- */

	public static function can(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		$p = array( __CLASS__, 'can' );
		register_rest_route( self::NS, '/guru-studio', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_view' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/guru-studio/fix-default', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_fix_default' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/guru/(?P<id>\d+)/channels', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_channels' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/guru/(?P<id>\d+)/clone', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_clone' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/guru/(?P<id>\d+)/activate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_activate' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/guru/(?P<id>\d+)/retire', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_retire' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/guru/(?P<id>\d+)/remove', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_remove' ), 'permission_callback' => $p ) );
		register_rest_route( self::NS, '/guru/(?P<id>\d+)/preview-search', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_preview' ), 'permission_callback' => $p ) );
		register_rest_route( self::VAULT_NS, '/guru/(?P<id>\d+)/folders', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_folders' ), 'permission_callback' => $p ) );
	}

	private static function done( $out, int $ok_status = 200 ): WP_REST_Response {
		if ( is_wp_error( $out ) ) {
			$d = (array) $out->get_error_data();
			return new WP_REST_Response( array( 'ok' => false, 'code' => $out->get_error_code(), 'message' => $out->get_error_message() ), (int) ( $d['status'] ?? 400 ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $out ), $ok_status );
	}

	public static function rest_view(): WP_REST_Response {
		return self::done( self::build_view() );
	}

	public static function rest_fix_default(): WP_REST_Response {
		return self::done( self::fix_default() );
	}

	public static function rest_channels( WP_REST_Request $r ): WP_REST_Response {
		$b   = (array) $r->get_json_params();
		$out = self::apply_channels( (int) $r['id'], array_values( (array) ( $b['add'] ?? array() ) ), array_values( (array) ( $b['remove'] ?? array() ) ) );
		$bad = ! $out['ok'] && ! $out['applied'] && $out['failed'] && in_array( $out['failed'][0]['code'] ?? '', array( 'guru_not_found', 'guru_not_assignable', 'guru_inactive' ), true );
		return new WP_REST_Response( array( 'ok' => $out['ok'], 'applied' => $out['applied'], 'failed' => $out['failed'] ), $bad ? ( 'guru_not_found' === $out['failed'][0]['code'] ? 404 : 422 ) : 200 );
	}

	public static function rest_clone( WP_REST_Request $r ): WP_REST_Response {
		$id = self::clone_guru( (int) $r['id'] );
		return self::done( is_wp_error( $id ) ? $id : array( 'id' => $id ) );
	}

	public static function rest_activate( WP_REST_Request $r ): WP_REST_Response {
		return self::done( self::activate_guru( (int) $r['id'] ) );
	}

	public static function rest_retire( WP_REST_Request $r ): WP_REST_Response {
		return self::done( self::retire_guru( (int) $r['id'] ) );
	}

	public static function rest_remove( WP_REST_Request $r ): WP_REST_Response {
		return self::done( self::remove_guru( (int) $r['id'] ) );
	}

	public static function rest_preview( WP_REST_Request $r ): WP_REST_Response {
		$b = (array) $r->get_json_params();
		return self::done( self::preview( (int) $r['id'], (string) ( $b['text'] ?? '' ) ) );
	}

	public static function rest_folders( WP_REST_Request $r ): WP_REST_Response {
		$b = (array) $r->get_json_params();
		return self::done( self::set_guru_folders( (int) $r['id'], (array) ( $b['folders'] ?? array() ) ) );
	}
}
