<?php
/**
 * BizCity_Guru_Seed — the two default Gurus of every site (PHASE-0.136 doc 60, D136-24…29).
 *
 * - **Quản gia** (`butler`, role `personal`): the owner and the staff talk to it — private, reads every folder the asker may see.
 * - **Đối ngoại** (`outward`, role `care`): everybody who is not the owner (Facebook, Messenger, Zalo OA, Zalo cá nhân, groups, web) — public folders only.
 *   It IS the site default Guru (gate 0): an existing default Guru is adopted (Q136-19 = 1), never duplicated.
 * - Default "Ai xem được" of the Kho tri thức folders (D136-27), only for folders the site never saved.
 *
 * One source: contracts/GURU-SEED-v1.json (`version`). Idempotent: runs on plugin activation, on the first request of a site whose stored
 * version is older (new site, upgrade), and before the default Guru would be created (four-step setup, gate 0). A version bump adds what is
 * missing; what the owner changed (name, instruction, folder access) is never overwritten.
 *
 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.136 S136-S1/S2 — new file (owner: "ok rồi … code nhé").
 *
 * @package BizCity_Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Guru_Seed {

	const STATE_OPTION = 'bizcity_guru_seed';
	const OWNER_OPTION = 'bizcity_guru_owner_character_id';
	const LOCK         = 'bizcity_guru_seed_lock';
	const RETRY_LOCK   = 'bizcity_guru_seed_retry';
	const ROLES        = array( 'personal', 'care', 'sales', 'teach', 'legal', 'content', 'none' );

	/** @var array<string,callable> test seams (template, get_option, update_option, character, character_by_slug, create_character, update_character, save_scope, scope_role, default_id, set_default, site, folders, folders_saved, save_folders) */
	public static $readers = array();
	private static $tpl    = null;
	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$booted = true;
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 99 );
	}

	public static function reset(): void {
		self::$tpl = null;
	}

	/** The template, validated. null when the file is missing or broken (the site then keeps its old single default Guru). */
	public static function template(): ?array {
		if ( null !== self::$tpl ) {
			return self::$tpl ?: null;
		}
		$raw = isset( self::$readers['template'] ) ? call_user_func( self::$readers['template'] ) : null;
		if ( null === $raw ) {
			$file = dirname( __DIR__, 2 ) . '/contracts/GURU-SEED-v1.json';
			$raw  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		self::$tpl = self::clean_template( is_array( $raw ) ? $raw : array() ) ?? array();
		return self::$tpl ?: null;
	}

	/** Pure: a template with `version` ≥ 1 and both Gurus (name + slug), else null. */
	public static function clean_template( array $t ): ?array {
		$v = (int) ( $t['version'] ?? 0 );
		if ( $v < 1 ) {
			return null;
		}
		$gurus = array();
		foreach ( array( 'butler', 'outward' ) as $key ) {
			$g = isset( $t['gurus'][ $key ] ) && is_array( $t['gurus'][ $key ] ) ? $t['gurus'][ $key ] : array();
			$name = trim( (string) ( $g['name'] ?? '' ) );
			$slug = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) ( $g['slug'] ?? '' ) ) );
			if ( '' === $name || '' === $slug ) {
				return null;
			}
			$folders = $g['folders'] ?? array();
			$gurus[ $key ] = array(
				'name'           => mb_substr( $name, 0, 100 ),
				'slug'           => $slug,
				'role'           => in_array( (string) ( $g['role'] ?? '' ), self::ROLES, true ) ? (string) $g['role'] : 'none',
				'description'    => mb_substr( trim( (string) ( $g['description'] ?? '' ) ), 0, 500 ),
				'instruction'    => array_values( array_filter( array_map( static function ( $l ) { return is_scalar( $l ) ? trim( (string) $l ) : ''; }, (array) ( $g['instruction'] ?? array() ) ) ) ),
				'folders'        => '*' === $folders ? '*' : array_values( array_filter( array_map( 'strval', (array) $folders ), static function ( $n ) { return 1 === preg_match( '/^[0-9]{2}$/', $n ); } ) ),
				'customer_tools' => array_values( array_map( 'strval', (array) ( $g['customer_tools'] ?? array() ) ) ),
				'adopt_slug'     => preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) ( $g['adopt_slug'] ?? '' ) ) ),
			);
		}
		$access = array();
		foreach ( array( 'public', 'owner', 'staff' ) as $a ) {
			foreach ( (array) ( $t['folders_access'][ $a ] ?? array() ) as $n ) {
				if ( 1 === preg_match( '/^[0-9]{2}$/', (string) $n ) && ! isset( $access[ (string) $n ] ) ) {
					$access[ (string) $n ] = $a;
				}
			}
		}
		return array( 'version' => $v, 'gurus' => $gurus, 'folders_access' => $access );
	}

	/** Pure: the instruction lines with {site} / {tagline} filled. */
	public static function instruction( array $guru, array $site ): string {
		$name    = trim( (string) ( $site['name'] ?? '' ) ) ?: 'cửa hàng';
		$tagline = trim( (string) ( $site['tagline'] ?? '' ) );
		return implode( "\n", array_map( static function ( $l ) use ( $name, $tagline ) {
			return strtr( $l, array( '{site}' => $name, '{tagline}' => '' !== $tagline ? ', ' . $tagline : '' ) );
		}, (array) ( $guru['instruction'] ?? array() ) ) );
	}

	/** The Quản gia of this site (0 = not seeded / deleted). Owner and staff turns answer with it (D136-26). */
	public static function owner_character_id(): int {
		$id = (int) self::opt( self::OWNER_OPTION, 0 );
		return $id > 0 && self::character( $id ) ? $id : 0;
	}

	/** `init` — cheap: one option read unless the stored version is older than the template. */
	public static function maybe_seed(): void {
		$tpl = self::template();
		if ( ! $tpl ) {
			return;
		}
		$state = (array) self::opt( self::STATE_OPTION, array() );
		if ( (int) ( $state['version'] ?? 0 ) >= (int) $tpl['version'] ) {
			return;
		}
		if ( function_exists( 'get_transient' ) && false !== get_transient( self::RETRY_LOCK ) ) {
			return; // a failed seed waits 10 minutes before the next try (tables not created yet on a brand-new site)
		}
		self::seed();
	}

	/**
	 * Seed (idempotent). @return array{ok:bool,code:string,butler:int,outward:int,folders:int}
	 */
	public static function seed( bool $force = false ): array {
		$tpl = self::template();
		if ( ! $tpl ) {
			return array( 'ok' => false, 'code' => 'template_missing', 'butler' => 0, 'outward' => 0, 'folders' => 0 );
		}
		$state = (array) self::opt( self::STATE_OPTION, array() );
		if ( ! $force && (int) ( $state['version'] ?? 0 ) >= (int) $tpl['version'] && self::character( (int) ( $state['gurus']['butler'] ?? 0 ) ) && self::character( (int) ( $state['gurus']['outward'] ?? 0 ) ) ) {
			return array( 'ok' => true, 'code' => 'already', 'butler' => (int) $state['gurus']['butler'], 'outward' => (int) $state['gurus']['outward'], 'folders' => 0 );
		}
		if ( ! isset( self::$readers['get_option'] ) && function_exists( 'get_transient' ) ) {
			if ( false !== get_transient( self::LOCK ) ) {
				return array( 'ok' => false, 'code' => 'busy', 'butler' => 0, 'outward' => 0, 'folders' => 0 );
			}
			set_transient( self::LOCK, 1, 60 );
		}
		try {
			$site    = self::site();
			$outward = self::ensure_outward( $tpl['gurus']['outward'], $state, $site );
			$butler  = self::ensure_butler( $tpl['gurus']['butler'], $state, $site );
			if ( $outward <= 0 || $butler <= 0 ) {
				if ( ! isset( self::$readers['get_option'] ) && function_exists( 'set_transient' ) ) {
					set_transient( self::RETRY_LOCK, 1, 600 );
				}
				return array( 'ok' => false, 'code' => 'guru_create_failed', 'butler' => $butler, 'outward' => $outward, 'folders' => 0 );
			}
			$folders = empty( $state['folders_seeded'] ) ? self::seed_folders( $tpl, $outward ) : 0;
			self::opt_set( self::OWNER_OPTION, $butler );
			self::opt_set( self::STATE_OPTION, array( 'version' => (int) $tpl['version'], 'gurus' => array( 'butler' => $butler, 'outward' => $outward ), 'folders_seeded' => true, 'at' => gmdate( 'c' ) ) );
			if ( function_exists( 'do_action' ) ) {
				do_action( 'bizcity_guru_seeded', $butler, $outward );
				do_action( 'bizcity_bot_config_changed', 'character', $butler ); // the cell bundle gets the Quản gia agent + owner_agent.guru_ref
			}
			return array( 'ok' => true, 'code' => 'seeded', 'butler' => $butler, 'outward' => $outward, 'folders' => $folders );
		} finally {
			if ( ! isset( self::$readers['get_option'] ) && function_exists( 'delete_transient' ) ) {
				delete_transient( self::LOCK );
			}
		}
	}

	/**
	 * Đối ngoại = the site default Guru. Order: seeded id → slug → the current default (adopted; renamed + new instruction only when the
	 * owner never edited it) → legacy character 1 → create. Never two defaults.
	 */
	private static function ensure_outward( array $g, array $state, array $site ): int {
		$id = (int) ( $state['gurus']['outward'] ?? 0 );
		if ( $id > 0 && self::character( $id ) ) {
			return $id;
		}
		$c = self::character_by_slug( $g['slug'] );
		if ( $c ) {
			$id = (int) $c->id;
		}
		if ( $id <= 0 ) {
			$id = self::default_id();
		}
		if ( $id <= 0 && class_exists( 'BizCity_Guru_Context_Resolver' ) ) {
			$legacy = self::character( BizCity_Guru_Context_Resolver::LEGACY_CHARACTER_ID );
			$id     = $legacy && 'active' === (string) ( $legacy->status ?? 'active' ) ? BizCity_Guru_Context_Resolver::LEGACY_CHARACTER_ID : 0;
		}
		if ( $id > 0 ) {
			$c        = self::character( $id );
			$prompt   = trim( (string) ( $c->system_prompt ?? '' ) );
			$untouched = '' === $prompt || ( class_exists( 'BizCity_Guru_Context_Resolver' ) && $prompt === trim( BizCity_Guru_Context_Resolver::default_instruction( $site ) ) );
			if ( $untouched ) {
				self::update_character( $id, array( 'name' => $g['name'], 'system_prompt' => self::instruction( $g, $site ), 'description' => $g['description'] ) );
			}
			if ( 'none' === self::scope_role( $id ) ) {
				self::save_scope( $id, array( 'role' => $g['role'] ) + ( $untouched && $g['customer_tools'] ? array( 'customer_tools' => $g['customer_tools'] ) : array() ) );
			}
		} else {
			$id = self::create( $g, $site );
			if ( $id > 0 ) {
				self::save_scope( $id, array( 'role' => $g['role'] ) + ( $g['customer_tools'] ? array( 'customer_tools' => $g['customer_tools'] ) : array() ) );
			}
		}
		if ( $id > 0 && self::default_id() !== $id ) {
			self::set_default( $id );
		}
		return $id;
	}

	private static function ensure_butler( array $g, array $state, array $site ): int {
		$id = (int) ( $state['gurus']['butler'] ?? 0 );
		if ( $id > 0 && self::character( $id ) ) {
			return $id;
		}
		$c = self::character_by_slug( $g['slug'] );
		if ( $c ) {
			return (int) $c->id; // found again after the option was lost: keep the owner's edits
		}
		$id = self::create( $g, $site );
		if ( $id > 0 ) {
			self::save_scope( $id, array( 'role' => $g['role'] ) );
		}
		return $id;
	}

	/** Folders the site never saved get the template access; the Đối ngoại reads its folders (gurus[]). @return int folders changed */
	private static function seed_folders( array $tpl, int $outward ): int {
		$list  = self::folders();
		$saved = self::folders_saved();
		$want  = is_array( $tpl['gurus']['outward']['folders'] ) ? $tpl['gurus']['outward']['folders'] : array();
		$n_changed = 0;
		foreach ( $list as $i => $f ) {
			$n       = (string) ( $f['n'] ?? '' );
			$changed = false;
			if ( ! in_array( $n, $saved, true ) && isset( $tpl['folders_access'][ $n ] ) && ( $f['access'] ?? '' ) !== $tpl['folders_access'][ $n ] ) {
				$list[ $i ]['access'] = $tpl['folders_access'][ $n ];
				$changed = true;
			}
			if ( in_array( $n, $want, true ) && ! in_array( $outward, array_map( 'intval', (array) ( $f['gurus'] ?? array() ) ), true ) ) {
				$list[ $i ]['gurus'] = array_values( array_merge( array_map( 'intval', (array) ( $f['gurus'] ?? array() ) ), array( $outward ) ) );
				$changed = true;
			}
			$n_changed += $changed ? 1 : 0;
		}
		if ( $n_changed > 0 ) {
			self::save_folders( $list );
		}
		return $n_changed;
	}

	/* ---------------- seams ---------------- */

	private static function opt( string $name, $default ) {
		if ( isset( self::$readers['get_option'] ) ) { return call_user_func( self::$readers['get_option'], $name, $default ); }
		return function_exists( 'get_option' ) ? get_option( $name, $default ) : $default;
	}

	private static function opt_set( string $name, $value ): void {
		if ( isset( self::$readers['update_option'] ) ) { call_user_func( self::$readers['update_option'], $name, $value ); return; }
		if ( function_exists( 'update_option' ) ) { update_option( $name, $value, true ); }
	}

	private static function db() {
		return class_exists( 'BizCity_Knowledge_Database' ) && method_exists( 'BizCity_Knowledge_Database', 'instance' ) ? BizCity_Knowledge_Database::instance() : null;
	}

	private static function character( int $id ) {
		if ( $id <= 0 ) { return null; }
		if ( isset( self::$readers['character'] ) ) { return call_user_func( self::$readers['character'], $id ); }
		$db = self::db();
		return $db ? $db->get_character( $id ) : null;
	}

	private static function character_by_slug( string $slug ) {
		if ( isset( self::$readers['character_by_slug'] ) ) { return call_user_func( self::$readers['character_by_slug'], $slug ); }
		$db = self::db();
		return $db && method_exists( $db, 'get_character_by_slug' ) ? $db->get_character_by_slug( $slug ) : null;
	}

	private static function create( array $g, array $site ): int {
		$data = array( 'name' => $g['name'], 'slug' => $g['slug'], 'description' => $g['description'], 'system_prompt' => self::instruction( $g, $site ), 'status' => 'active', 'author_id' => 0 );
		if ( isset( self::$readers['create_character'] ) ) { return (int) call_user_func( self::$readers['create_character'], $data ); }
		$db = self::db();
		$id = $db ? $db->create_character( $data ) : 0;
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private static function update_character( int $id, array $data ): void {
		if ( isset( self::$readers['update_character'] ) ) { call_user_func( self::$readers['update_character'], $id, $data ); return; }
		$db = self::db();
		if ( $db ) { $db->update_character( $id, $data ); }
	}

	private static function scope_role( int $id ): string {
		if ( isset( self::$readers['scope_role'] ) ) { return (string) call_user_func( self::$readers['scope_role'], $id ); }
		if ( ! class_exists( 'BizCity_Guru_Context_Resolver' ) ) { return 'none'; }
		$s = BizCity_Guru_Context_Resolver::scope( $id );
		return (string) ( $s['role'] ?? 'none' );
	}

	private static function save_scope( int $id, array $scope ): void {
		if ( isset( self::$readers['save_scope'] ) ) { call_user_func( self::$readers['save_scope'], $id, $scope ); return; }
		if ( class_exists( 'BizCity_Bot_Config_Repo' ) ) { BizCity_Bot_Config_Repo::save( $id, array( 'scope' => $scope ) ); }
	}

	private static function default_id(): int {
		if ( isset( self::$readers['default_id'] ) ) { return (int) call_user_func( self::$readers['default_id'] ); }
		return class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::default_character_id( false ) : 0; // false: never create here (no loop)
	}

	private static function set_default( int $id ): void {
		if ( isset( self::$readers['set_default'] ) ) { call_user_func( self::$readers['set_default'], $id ); return; }
		if ( class_exists( 'BizCity_Guru_Context_Resolver' ) ) { BizCity_Guru_Context_Resolver::set_default( $id ); }
	}

	private static function site(): array {
		if ( isset( self::$readers['site'] ) ) { return (array) call_user_func( self::$readers['site'] ); }
		return array(
			'name'    => function_exists( 'get_bloginfo' ) ? wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) : '',
			'tagline' => function_exists( 'get_bloginfo' ) ? wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) : '',
		);
	}

	private static function folders(): array {
		if ( isset( self::$readers['folders'] ) ) { return (array) call_user_func( self::$readers['folders'] ); }
		return class_exists( 'BizCity_Twin_Vault' ) ? BizCity_Twin_Vault::folders() : array();
	}

	/** Numbers present in the saved option (a default that only comes from code is "never saved"). */
	private static function folders_saved(): array {
		if ( isset( self::$readers['folders_saved'] ) ) { return (array) call_user_func( self::$readers['folders_saved'] ); }
		$raw = class_exists( 'BizCity_Twin_Vault' ) && function_exists( 'get_option' ) ? get_option( BizCity_Twin_Vault::FOLDER_OPTION, array() ) : array();
		return array_values( array_map( static function ( $f ) { return (string) ( is_array( $f ) ? ( $f['n'] ?? '' ) : '' ); }, is_array( $raw ) ? $raw : array() ) );
	}

	private static function save_folders( array $list ): void {
		if ( isset( self::$readers['save_folders'] ) ) { call_user_func( self::$readers['save_folders'], $list ); return; }
		if ( class_exists( 'BizCity_Twin_Vault' ) && function_exists( 'update_option' ) ) { update_option( BizCity_Twin_Vault::FOLDER_OPTION, array_values( $list ), false ); }
	}
}
