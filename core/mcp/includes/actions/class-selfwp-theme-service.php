<?php
/**
 * BizCity_SelfWP_Theme_Service — `wp.theme.info` (R0) and `wp.theme.update` (R2): the cell changes the look of THIS website
 * (colours, logo, header size, fonts) through a WHITELIST of Flatsome theme_mods (PHASE-0.118 W4, doc 20 §3, D118-10).
 *
 * Rules:
 *  - mode `site_admin` and the WordPress capability `edit_theme_options` of the person (re-checked in every handler);
 *  - only keys of core/mcp/contracts/self-wp-theme-mods.json, each value checked against its type — no CSS / JS / HTML key exists there;
 *  - R2: preview (old → new per key) → confirm; the token is bound to the arguments;
 *  - every write keeps the previous values (option `bizcity_selfwp_theme_changes`, ≤ 20 changes — an option, not a new table, D118-18) so
 *    `revert: <change id>` puts them back; a revert is itself a logged change and can be reverted;
 *  - one site-change-log@1 audit line per write (metadata only, see BizCity_SelfWP_Action_MCP_Service::audit_change).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-17 (PHASE-0.118 W4)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-17 06:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L8 — new file: theme_mod executors.
final class BizCity_SelfWP_Theme_Service {

	const OPTION      = 'bizcity_selfwp_theme_changes';
	const KEEP        = 20;
	const REPORT_MAX  = 10;
	const THEME_SLUG  = 'flatsome';

	/**
	 * Test seams, name → callable. Defaults call WordPress.
	 *   user_can(string $cap): bool · active_theme(): array{template:string,stylesheet:string,name:string}
	 *   get_mod(string $key): mixed|null · set_mod(string $key, mixed $value): void · remove_mod(string $key): void
	 *   media_url(int $id): string ('' = not an image) · get_changes(): array · set_changes(array $rows): void
	 *   whitelist(): array (replaces the JSON file)
	 *
	 * @var array<string,callable>
	 */
	public static $seams = array();

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		$S = 'BizCity_MCP_Tool_Registry';

		// @mcp bizcity-mcp-standard@1 tool wp.theme.info
		BizCity_MCP_Tool_Registry::register( 'wp.theme.info', array(
			'executor'       => 'wp.theme.info',
			'title'          => 'Giao diện website và các mục chỉnh được',
			'description'    => 'Cho biết giao diện đang dùng (Flatsome có hỗ trợ không), danh sách MỤC được phép đổi (màu chủ đạo, logo, chiều cao đầu trang, phông chữ…) kèm giá trị hiện tại và giới hạn, và các lần đổi gần đây (change_id, còn hoàn tác được không). Chỉ đọc. Gọi trước khi đổi giao diện.',
			'input_schema'   => array( 'type' => 'object', 'properties' => new stdClass() ),
			'output_schema'  => $S::envelope_schema( array( 'theme' => array( 'type' => 'object' ), 'mods' => array( 'type' => 'array' ), 'changes' => array( 'type' => 'array' ) ), array( 'theme' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'site.admin',
			'required_cap'   => 'edit_theme_options',
			'handler'        => array( __CLASS__, 'theme_info' ),
			'mode'           => 'site_admin',
			'scopes'         => array( 'site.admin' ),
			'confirm'        => 'never',
			'llm_alias'      => 'wp_theme_info',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// [2026-10-17 06:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L8 — R2: đổi giao diện chỉ qua danh sách trắng, xem trước → xác nhận, giữ giá trị cũ để hoàn tác
		// @mcp bizcity-mcp-standard@1 tool wp.theme.update
		BizCity_MCP_Tool_Registry::register( 'wp.theme.update', array(
			'executor'       => 'wp.theme.update',
			'title'          => 'Đổi giao diện website (màu, logo, phông…)',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): đổi các mục giao diện trong danh sách được phép của wp.theme.info (changes = {mục: giá trị}, tối đa 8 mục) HOẶC hoàn tác một lần đổi (revert = change_id). Lần đầu chỉ trả bản xem trước (cũ → mới) + confirm_token, CHƯA ghi; gọi lại ĐÚNG tham số kèm confirm_token sau khi người yêu cầu đồng ý. Không có mục nào chèn được CSS/JS/HTML.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'changes'       => array( 'type' => 'object', 'minProperties' => 1, 'maxProperties' => 8 ),
				'revert'        => array( 'type' => 'string', 'minLength' => 6, 'maxLength' => 16 ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'status' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'change_id' => array( 'type' => 'string' ), 'keys' => array( 'type' => 'array' ), 'changes' => array( 'type' => 'array' ), 'unchanged' => array( 'type' => 'boolean' ) ), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'site.admin',
			'required_cap'   => 'edit_theme_options',
			'handler'        => array( __CLASS__, 'mod_update' ),
			'preview'        => array( __CLASS__, 'mod_update_preview' ),
			'mode'           => 'site_admin',
			'scopes'         => array( 'site.admin' ),
			'confirm'        => 'always',
			'llm_alias'      => 'wp_theme_update',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );
	}

	/* ───────────────────────────── whitelist + value rules ──────────────────── */

	/** @return array{mods:array<string,array>,max:int,family:string,variant:string} */
	public static function whitelist(): array {
		static $cache = null;
		if ( isset( self::$seams['whitelist'] ) ) {
			$w = (array) call_user_func( self::$seams['whitelist'] );
		} elseif ( null !== $cache ) {
			return $cache;
		} else {
			$file = ( defined( 'BIZCITY_MCP_DIR' ) ? BIZCITY_MCP_DIR : dirname( __DIR__, 2 ) . '/' ) . 'contracts/self-wp-theme-mods.json';
			$raw  = is_readable( $file ) ? file_get_contents( $file ) : false; // plain file inside the plugin, never a URL
			$w    = is_string( $raw ) ? (array) json_decode( $raw, true ) : array();
		}
		$mods = array();
		foreach ( (array) ( $w['mods'] ?? array() ) as $k => $rule ) {
			if ( is_string( $k ) && 1 === preg_match( '/^[a-z0-9_]{2,40}$/', $k ) && is_array( $rule ) && in_array( $rule['type'] ?? '', array( 'color', 'int', 'bool', 'media', 'font' ), true ) ) {
				$mods[ $k ] = $rule;
			}
		}
		$out = array(
			'mods'    => $mods,
			'max'     => max( 1, min( 20, (int) ( $w['max_keys_per_call'] ?? 8 ) ) ),
			'family'  => (string) ( $w['font']['family_pattern'] ?? '^[A-Za-z0-9][A-Za-z0-9 \-]{1,39}$' ),
			'variant' => (string) ( $w['font']['variant_pattern'] ?? '^(regular|italic|[1-9]00(italic)?)$' ),
		);
		if ( ! isset( self::$seams['whitelist'] ) ) {
			$cache = $out;
		}
		return $out;
	}

	/** @return array{ok:bool,value?:mixed,error?:string} */
	private static function normalize( string $key, array $rule, $raw, array $wl ): array {
		$type = (string) $rule['type'];
		if ( 'color' === $type ) {
			if ( is_string( $raw ) && 1 === preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', trim( $raw ) ) ) {
				$h = strtolower( ltrim( trim( $raw ), '#' ) );
				return array( 'ok' => true, 'value' => '#' . ( 3 === strlen( $h ) ? $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] : $h ) );
			}
			return array( 'ok' => false, 'error' => 'cần mã màu dạng #rrggbb' );
		}
		if ( 'int' === $type ) {
			$ok = is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '/^-?[0-9]{1,6}$/D', trim( $raw ) ) );
			$n  = $ok ? (int) $raw : 0;
			if ( ! $ok || $n < (int) ( $rule['min'] ?? 0 ) || $n > (int) ( $rule['max'] ?? 1000 ) ) {
				return array( 'ok' => false, 'error' => 'cần số nguyên từ ' . (int) ( $rule['min'] ?? 0 ) . ' đến ' . (int) ( $rule['max'] ?? 1000 ) );
			}
			return array( 'ok' => true, 'value' => $n );
		}
		if ( 'bool' === $type ) {
			if ( true === $raw || 1 === $raw || '1' === $raw ) {
				return array( 'ok' => true, 'value' => 1 );
			}
			if ( false === $raw || 0 === $raw || '0' === $raw ) {
				return array( 'ok' => true, 'value' => 0 );
			}
			return array( 'ok' => false, 'error' => 'cần true hoặc false' );
		}
		if ( 'media' === $type ) {
			$id = is_int( $raw ) ? $raw : ( is_string( $raw ) && 1 === preg_match( '/^[0-9]{1,10}$/D', $raw ) ? (int) $raw : 0 );
			$url = $id > 0 ? (string) self::seam( 'media_url', array( $id ), array( __CLASS__, 'default_media_url' ) ) : '';
			if ( '' === $url ) {
				return array( 'ok' => false, 'error' => 'cần mã ảnh có trong thư viện media (không nhận địa chỉ web tuỳ ý)' );
			}
			return array( 'ok' => true, 'value' => $url );
		}
		// font
		$fam = is_array( $raw ) ? (string) ( $raw['font-family'] ?? $raw['family'] ?? '' ) : '';
		$var = is_array( $raw ) ? (string) ( $raw['variant'] ?? 'regular' ) : '';
		if ( 1 !== preg_match( '/' . str_replace( '/', '\/', $wl['family'] ) . '/D', $fam ) || 1 !== preg_match( '/' . str_replace( '/', '\/', $wl['variant'] ) . '/D', $var ) ) {
			return array( 'ok' => false, 'error' => 'cần {"font-family":"Lato","variant":"700"} (tên phông chỉ gồm chữ, số, khoảng trắng, gạch ngang)' );
		}
		return array( 'ok' => true, 'value' => array( 'font-family' => $fam, 'variant' => $var ) );
	}

	/** compact text of one value for the preview / the info list */
	private static function show( $v ): string {
		if ( null === $v ) {
			return '(mặc định)';
		}
		if ( is_array( $v ) ) {
			return trim( (string) ( $v['font-family'] ?? '' ) . ' ' . (string) ( $v['variant'] ?? '' ) );
		}
		$s = (string) $v;
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 60, 'UTF-8' ) : substr( $s, 0, 60 );
	}

	private static function clean_key( string $k ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_]/', '?', $k ), 0, 40 );
	}

	/* ───────────────────────────── plan (nothing is written) ──────────────────── */

	/** @return array|WP_Error */
	private static function supported_theme() {
		$t = (array) self::seam( 'active_theme', array(), static function () {
			return array(
				'template'   => function_exists( 'get_template' ) ? (string) get_template() : '',
				'stylesheet' => function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '',
				'name'       => function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Name' ) : '',
			);
		} );
		if ( self::THEME_SLUG !== (string) ( $t['template'] ?? '' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Giao diện đang dùng («' . (string) ( $t['name'] ?? '' ) . '») chưa được hỗ trợ đổi từ trợ lý; hiện chỉ hỗ trợ Flatsome (và theme con của nó).', 422, array( 'help_code' => 'selfwp_theme_unsupported' ) );
		}
		return $t;
	}

	/** @return array|WP_Error {mode, keys: key => {before, after, label}, revert_of?} */
	private static function plan( array $args ) {
		$t = self::supported_theme();
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$wl      = self::whitelist();
		$has_chg = isset( $args['changes'] );
		$has_rev = isset( $args['revert'] ) && '' !== trim( (string) $args['revert'] );
		if ( $has_chg === $has_rev ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần đúng MỘT trong hai: changes (đổi các mục) hoặc revert (mã lần đổi cần hoàn tác).', 422, array( 'help_code' => 'selfwp_theme_args' ) );
		}
		$keys = array();
		if ( $has_chg ) {
			$chg = $args['changes'];
			if ( ! is_array( $chg ) || array() === $chg || count( $chg ) > $wl['max'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'changes cần từ 1 đến ' . $wl['max'] . ' mục.', 422, array( 'help_code' => 'selfwp_theme_args' ) );
			}
			foreach ( $chg as $k => $raw ) {
				$k = (string) $k;
				if ( ! isset( $wl['mods'][ $k ] ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Mục «' . self::clean_key( $k ) . '» không nằm trong danh sách được phép đổi (key_not_allowed). Các mục được phép: ' . implode( ', ', array_keys( $wl['mods'] ) ) . '.', 422, array( 'help_code' => 'key_not_allowed' ) );
				}
				$n = self::normalize( $k, $wl['mods'][ $k ], $raw, $wl );
				if ( ! $n['ok'] ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Mục «' . $k . '» chưa hợp lệ: ' . $n['error'] . '.', 422, array( 'help_code' => 'selfwp_theme_value', 'key' => $k ) );
				}
				$keys[ $k ] = array( 'before' => self::current( $k ), 'after' => $n['value'] );
			}
			$mode = 'update';
			$of   = '';
		} else {
			$id = trim( (string) $args['revert'] );
			$row = null;
			foreach ( self::changes() as $c ) {
				if ( 0 === strcasecmp( (string) $c['id'], $id ) ) {
					$row = $c;
					break;
				}
			}
			if ( null === $row ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có lần đổi «{$id}» để hoàn tác (sai mã hoặc đã quá giới hạn lưu). Xem danh sách bằng wp.theme.info.", 404, array( 'help_code' => 'selfwp_change_not_found' ) );
			}
			foreach ( (array) $row['before'] as $k => $old ) {
				if ( ! isset( $wl['mods'][ $k ] ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Lần đổi này có mục «' . self::clean_key( (string) $k ) . '» không còn trong danh sách được phép nên không hoàn tác tự động.', 422, array( 'help_code' => 'key_not_allowed' ) );
				}
				if ( null !== $old ) { // the saved value is data: it goes through the same rule as a new one
					if ( 'media' === $wl['mods'][ $k ]['type'] ) { // a logo is stored as the URL WordPress gave it, so check its shape instead of looking an id up
						$n = array( 'ok' => is_string( $old ) && 1 === preg_match( '#^((https?:)?//|/)[^\s<>"]+$#D', $old ), 'value' => $old );
					} else {
						$n = self::normalize( (string) $k, $wl['mods'][ $k ], $old, $wl );
					}
					if ( ! $n['ok'] ) {
						return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Giá trị cũ của «' . $k . '» không còn hợp lệ nên không hoàn tác tự động.', 422, array( 'help_code' => 'selfwp_theme_value' ) );
					}
					$old = $n['value'];
				}
				$keys[ (string) $k ] = array( 'before' => self::current( (string) $k ), 'after' => $old );
			}
			$mode = 'revert';
			$of   = (string) $row['id'];
		}
		foreach ( $keys as $k => $v ) { // a key that is already at the wanted value is not a change
			if ( $v['before'] === $v['after'] ) {
				unset( $keys[ $k ] );
			}
		}
		$lines = array();
		foreach ( $keys as $k => $v ) {
			$lines[] = $k . ': ' . self::show( $v['before'] ) . ' → ' . self::show( $v['after'] );
		}
		return array( 'mode' => $mode, 'keys' => $keys, 'lines' => $lines, 'revert_of' => $of );
	}

	/* ───────────────────────────── handlers ──────────────────── */

	/** @return array|WP_Error */
	public static function theme_info( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) {
			if ( ! self::can( 'edit_theme_options' ) ) {
				return self::denied();
			}
			$t = (array) self::seam( 'active_theme', array(), static function () {
				return array(
					'template'   => function_exists( 'get_template' ) ? (string) get_template() : '',
					'stylesheet' => function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '',
					'name'       => function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Name' ) : '',
				);
			} );
			$supported = self::THEME_SLUG === (string) ( $t['template'] ?? '' );
			$wl        = self::whitelist();
			$mods      = array();
			if ( $supported ) {
				foreach ( $wl['mods'] as $k => $rule ) {
					$row = array( 'key' => $k, 'label' => (string) ( $rule['label'] ?? $k ), 'type' => (string) $rule['type'], 'current' => self::show( self::current( $k ) ) );
					if ( 'int' === $rule['type'] ) {
						$row['min'] = (int) ( $rule['min'] ?? 0 );
						$row['max'] = (int) ( $rule['max'] ?? 1000 );
					}
					$mods[] = $row;
				}
			}
			$changes = array();
			foreach ( array_slice( array_reverse( self::changes() ), 0, self::REPORT_MAX ) as $c ) {
				$changes[] = array( 'change_id' => $c['id'], 'at' => $c['at'], 'by' => $c['by'], 'via' => $c['via'], 'keys' => array_keys( (array) $c['after'] ), 'reverts' => (string) ( $c['revert_of'] ?? '' ) );
			}
			return array(
				'theme'   => array( 'name' => (string) ( $t['name'] ?? '' ), 'template' => (string) ( $t['template'] ?? '' ), 'stylesheet' => (string) ( $t['stylesheet'] ?? '' ), 'supported' => $supported ),
				'mods'    => $mods,
				'changes' => $changes,
			);
		} );
	}

	/** @return array|WP_Error */
	public static function mod_update_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! self::can( 'edit_theme_options' ) ) {
				return self::denied();
			}
			$plan = self::plan( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( array() === $plan['keys'] ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Các mục này đã đúng giá trị yêu cầu, không có gì để đổi.', 422, array( 'help_code' => 'selfwp_no_change' ) );
			}
			return array(
				'keys'    => array_keys( $plan['keys'] ),
				'changes' => $plan['lines'],
				'summary' => ( 'revert' === $plan['mode'] ? 'Hoàn tác lần đổi giao diện «' . $plan['revert_of'] . '»' : 'Đổi giao diện website' ) . ': ' . count( $plan['keys'] ) . ' mục — khách vào xem thấy ngay. Giá trị cũ được lưu để hoàn tác.',
			);
		} );
	}

	/** @return array|WP_Error */
	public static function mod_update( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			if ( ! self::can( 'edit_theme_options' ) ) {
				return self::denied();
			}
			$plan = self::plan( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( array() === $plan['keys'] ) { // a retry / second tap: already there
				return array( 'status' => 'done', 'unchanged' => true, 'keys' => array(), 'changes' => array(), 'change_id' => '' );
			}
			$before = array();
			$after  = array();
			foreach ( $plan['keys'] as $k => $v ) {
				$before[ $k ] = $v['before'];
				$after[ $k ]  = $v['after'];
			}
			$id  = substr( hash( 'sha256', wp_json_encode( array( $before, $after, gmdate( 'c' ), $uid ) ) ), 0, 12 );
			$row = array( 'id' => $id, 'at' => gmdate( 'c' ), 'by' => $uid, 'via' => 'revert' === $plan['mode'] ? 'revert' : 'update', 'revert_of' => $plan['revert_of'], 'before' => $before, 'after' => $after );
			// keep the old values FIRST: if the writes below stop half way, the way back already exists
			self::seam( 'set_changes', array( array_slice( array_merge( self::changes(), array( $row ) ), -self::KEEP ) ), static function ( $rows ) {
				return update_option( self::OPTION, $rows, false );
			} );
			foreach ( $after as $k => $value ) {
				if ( null === $value ) {
					self::seam( 'remove_mod', array( $k ), static function ( $k ) {
						remove_theme_mod( $k );
					} );
				} else {
					self::seam( 'set_mod', array( $k, $value ), static function ( $k, $value ) {
						set_theme_mod( $k, $value );
					} );
				}
			}
			$entry           = BizCity_SelfWP_Action_MCP_Service::change_entry( 'wp.theme.update', 'R2', $uid, $ctx, 0, '', $id, $plan['lines'], 'theme_mod' );
			$entry['target'] = 'theme_mod:' . implode( ',', array_keys( $after ) );
			$entry['command_line'] = 'wp theme mod set ' . implode( ' ', array_keys( $after ) );
			BizCity_SelfWP_Action_MCP_Service::audit_change( $entry );
			return array( 'status' => 'done', 'unchanged' => false, 'change_id' => $id, 'keys' => array_keys( $after ), 'changes' => $plan['lines'] );
		} );
	}

	/* ───────────────────────────── helpers ──────────────────── */

	/** @return mixed|null the stored value of one whitelisted key (null = not set, theme default) */
	private static function current( string $key ) {
		$v = self::seam( 'get_mod', array( $key ), static function ( $key ) {
			return function_exists( 'get_theme_mod' ) ? get_theme_mod( $key, null ) : null;
		} );
		return ( '' === $v || false === $v ) ? null : $v;
	}

	/** @return array<int,array> oldest first */
	private static function changes(): array {
		$rows = self::seam( 'get_changes', array(), static function () {
			return function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		} );
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( is_array( $r ) && is_string( $r['id'] ?? null ) && is_array( $r['before'] ?? null ) && is_array( $r['after'] ?? null ) ) {
				$out[] = $r + array( 'at' => '', 'by' => 0, 'via' => 'update', 'revert_of' => '' );
			}
		}
		return $out;
	}

	public static function default_media_url( $id ): string {
		$p = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		if ( ! $p || 'attachment' !== $p->post_type || 0 !== strpos( (string) $p->post_mime_type, 'image/' ) ) {
			return '';
		}
		return function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( (int) $id ) : '';
	}

	private static function can( string $cap ): bool {
		if ( isset( self::$seams['user_can'] ) ) {
			return (bool) call_user_func( self::$seams['user_can'], $cap );
		}
		return function_exists( 'current_user_can' ) && current_user_can( $cap );
	}

	private static function seam( string $name, array $args, $default ) {
		$fn = self::$seams[ $name ] ?? $default;
		return call_user_func_array( $fn, $args );
	}

	private static function denied() {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, 'Tài khoản của bạn chưa có quyền đổi giao diện (cần quyền WordPress "edit_theme_options").', 403, array( 'hint' => 'Chỉ quản trị viên đổi được giao diện website.', 'required_cap' => 'edit_theme_options' ) );
	}
}

BizCity_SelfWP_Theme_Service::init();
