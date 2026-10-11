<?php
/**
 * BizCity_MCP_Plugin_Adapter — Zalo Brain wraps a plugin that has no `zalo-brain.json` (`bizcity-mcp-adapter@1.0.0`).
 *
 * PHASE-0.101 S101-L5 (doc 30 §3, D101-8): a small manifest in `core/mcp/adapters/<slug>.json` turns an existing PHP callable, a
 * WordPress filter or an internal REST route into READ tools of the one MCP door, registered through
 * `BizCity_Zalo_Brain::register_tool()` with `owner = adapter:<slug>`. The adapter is thin: no model, budget of a read (5 s), and
 * it only exists while its `requires` holds (class / filter / function present) — a disabled plugin takes its tools with it.
 *
 * Manifest (one file, ≤ 8 tools):
 *   { "contract": "bizcity-mcp-adapter@1.0.0", "slug": "bizcity-twin-crm", "requires": { "class": "…" | "filter": "…" | "function": "…" },
 *     "tools": [ { "marker": "@mcp bizcity-mcp-standard@1 tool crm.pipeline.stage", "name": "crm.pipeline.stage", "title": …, "description": …,
 *                  "input_schema": {…}, "output": { "stage": "string", … }, "mode": "customers", "scopes": ["crm.read"], "roles": ["owner","staff"],
 *                  "scope": "tenant", "read_only": true, "llm_alias": "crm_pipeline_stage", "since": "0.101.0",
 *                  "source": { "type": "php_callable", "callable": "BizCity_CRM_Customer_Pipeline::detail", "args": ["$contact_id", false],
 *                              "guard": "crm_contact:contact_ref" },
 *                  "output_map": { "stage": "stage", "label": "label" } } ] }
 *
 * Sources: `php_callable` (static `Class::method` or function; `args` are literals or `$placeholders` from the tool arguments / guard),
 * `wp_filter` (`apply_filters( filter, null, $args, $ctx )`; a null answer ⇒ `_degraded`), `rest_route` (internal `rest_do_request`
 * GET; query from `query` map). Guards: `crm_contact:<arg>` resolves `contact_ref` through BizCity_MCP_Action_Support::contact_access()
 * (scope of the acting user, no PII leak) and sets `$contact_id`. Writes and destructive tools are refused by the adapter in this
 * wave (a plugin that writes ships its own library). An adapter whose slug matches a registered extension yields to it.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L5 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_MCP_Plugin_Adapter', false ) ) {
	return;
}

final class BizCity_MCP_Plugin_Adapter {

	const CONTRACT  = 'bizcity-mcp-adapter@1.0.0';
	const MAX_TOOLS = 8;
	const SOURCES   = array( 'php_callable', 'wp_filter', 'rest_route' );
	const BUDGET_MS = 5000;

	/** @var array<string,array> tool name => manifest tool (with slug) registered in this request */
	private static $tools = array();
	/** @var callable|null test seam: () => string[] manifest files */
	public static $files = null;
	/** @var callable|null test seam for the wp_filter source: ( string $filter, array $args, array $ctx ) => mixed (default apply_filters) */
	public static $apply = null;
	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_all' ), 5 );
	}

	/** Directory of the adapter manifests. */
	public static function dir(): string {
		return ( defined( 'BIZCITY_MCP_DIR' ) ? BIZCITY_MCP_DIR : dirname( __DIR__ ) . '/' ) . 'adapters/';
	}

	/** Every `adapters/*.json` (README and `*.example` ignored). */
	public static function register_all(): int {
		$files = is_callable( self::$files ) ? (array) call_user_func( self::$files ) : (array) glob( self::dir() . '*.json' );
		$n     = 0;
		foreach ( $files as $f ) {
			$raw = is_readable( $f ) ? file_get_contents( $f ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$m   = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $m ) ) {
				$n += self::register_from( $m );
			}
		}
		return $n;
	}

	/** @return int tools registered from this manifest (0 when its requirement is not met or the manifest is invalid). */
	public static function register_from( array $m ): int {
		if ( self::CONTRACT !== (string) ( $m['contract'] ?? '' ) ) {
			return 0;
		}
		$slug = sanitize_key( (string) ( $m['slug'] ?? '' ) );
		if ( '' === $slug || ! isset( $m['tools'] ) || ! is_array( $m['tools'] ) ) {
			return 0;
		}
		if ( ! self::requirement_met( (array) ( $m['requires'] ?? array() ) ) ) {
			return 0;
		}
		// an extension with its own manifest + library wins over the adapter of the same slug (doc 30 §3)
		if ( class_exists( 'BizCity_Zalo_Brain' ) && method_exists( 'BizCity_Zalo_Brain', 'extensions' ) ) {
			$ext = (array) BizCity_Zalo_Brain::extensions();
			if ( isset( $ext[ $slug ] ) && ! empty( $ext[ $slug ]['mcp_library'] ) ) {
				return 0;
			}
		}
		$n = 0;
		foreach ( array_slice( $m['tools'], 0, self::MAX_TOOLS ) as $t ) {
			if ( ! is_array( $t ) || null !== self::validate_tool( $t ) ) {
				continue;
			}
			$name = (string) $t['name'];
			if ( class_exists( 'BizCity_MCP_Tool_Registry' ) && in_array( $name, BizCity_MCP_Tool_Registry::all_registered_tool_names(), true ) ) {
				continue; // a real tool of that name exists ⇒ the adapter never shadows it
			}
			$t['slug']           = $slug;
			self::$tools[ $name ] = $t;
			$desc = array(
				'name'           => $name,
				'title'          => (string) $t['title'],
				'description'    => (string) $t['description'],
				'input_schema'   => $t['input_schema'],
				'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( self::output_props( (array) ( $t['output'] ?? array() ) ) ),
				'read_only'      => true,
				'destructive'    => false,
				'idempotent'     => true,
				'required_scope' => (string) $t['scopes'][0],
				'handler'        => static function ( array $args, array $ctx ) use ( $name ) {
					return BizCity_MCP_Plugin_Adapter::call( $name, $args, $ctx );
				},
				'mode'           => (string) $t['mode'],
				'scopes'         => array_values( array_map( 'strval', (array) $t['scopes'] ) ),
				'roles'          => array_values( array_map( 'strval', (array) ( $t['roles'] ?? array( 'owner', 'staff' ) ) ) ),
				'scope'          => (string) ( $t['scope'] ?? 'tenant' ),
				'confirm'        => 'never',
				'llm_alias'      => (string) ( $t['llm_alias'] ?? str_replace( '.', '_', $name ) ),
				'fallback_pack'  => isset( $t['fallback_pack'] ) ? (string) $t['fallback_pack'] : null,
				'since'          => (string) ( $t['since'] ?? '0.101.0' ),
				'owner'          => 'adapter:' . $slug,
			);
			$ok = class_exists( 'BizCity_Zalo_Brain' ) && method_exists( 'BizCity_Zalo_Brain', 'register_tool' )
				? BizCity_Zalo_Brain::register_tool( $desc )
				: ( class_exists( 'BizCity_MCP_Tool_Registry' ) && false !== BizCity_MCP_Tool_Registry::register( $name, $desc ) );
			if ( $ok ) {
				$n++;
			}
		}
		return $n;
	}

	/** @return string|null reason, null when valid (reads only in this wave). */
	public static function validate_tool( array $t ) {
		if ( ! preg_match( BizCity_MCP_Library::NAME_RE, (string) ( $t['name'] ?? '' ) ) ) {
			return 'name';
		}
		foreach ( array( 'title', 'description', 'mode' ) as $k ) {
			if ( '' === trim( (string) ( $t[ $k ] ?? '' ) ) ) {
				return $k . ' required';
			}
		}
		if ( empty( $t['scopes'] ) || ! is_array( $t['scopes'] ) || ! isset( $t['input_schema'] ) || ! is_array( $t['input_schema'] ) ) {
			return 'scopes[] and input_schema required';
		}
		if ( ( array_key_exists( 'read_only', $t ) && false === $t['read_only'] ) || ! empty( $t['destructive'] ) ) {
			return 'adapters register READ tools only (a plugin that writes ships its own mcp/tools.json)';
		}
		$src = isset( $t['source'] ) && is_array( $t['source'] ) ? $t['source'] : array();
		if ( ! in_array( (string) ( $src['type'] ?? '' ), self::SOURCES, true ) ) {
			return 'source.type must be one of ' . implode( '|', self::SOURCES );
		}
		if ( 'php_callable' === $src['type'] && ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(::[A-Za-z_][A-Za-z0-9_]*)?$/', (string) ( $src['callable'] ?? '' ) ) ) {
			return 'source.callable must be Class::method or function';
		}
		if ( 'wp_filter' === $src['type'] && ! preg_match( '/^[a-z][a-z0-9_]*$/', (string) ( $src['filter'] ?? '' ) ) ) {
			return 'source.filter';
		}
		if ( 'rest_route' === $src['type'] && ! preg_match( '#^/[a-z0-9_/.{}()?<>+-]+$#i', (string) ( $src['route'] ?? '' ) ) ) {
			return 'source.route';
		}
		return null;
	}

	/** The registered handler: run as the principal, resolve the guard, call the source, map the output. */
	public static function call( string $name, array $args, array $ctx ) {
		$t = self::$tools[ $name ] ?? null;
		if ( ! $t ) {
			return new WP_Error( 'MCP_TOOL_NOT_FOUND', 'Tool không còn khả dụng.', array( 'status' => 404 ) );
		}
		$fn = static function ( $uid ) use ( $t, $args, $ctx ) {
			$resolved = array();
			$guard    = (string) ( $t['source']['guard'] ?? '' );
			if ( '' !== $guard ) {
				$g = self::guard( $guard, $args, $uid );
				if ( is_wp_error( $g ) ) {
					return $g;
				}
				$resolved = $g;
			}
			$started = microtime( true );
			$raw     = self::dispatch( $t['source'], $args, $resolved, $ctx );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
			$out = self::map_output( $raw, (array) ( $t['output_map'] ?? array() ) );
			if ( null === $raw ) {
				$out['_degraded'] = true;
				$out['reason']    = 'provider_unavailable';
			}
			$out['source'] = 'adapter:' . $t['slug'];
			$ms            = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( $ms > self::BUDGET_MS ) {
				$out['_slow_ms'] = $ms; // over the read budget: the cell records it; never a model-side retry
			}
			return $out;
		};
		return class_exists( 'BizCity_MCP_Action_Support' ) ? BizCity_MCP_Action_Support::run_as( $ctx, $fn ) : $fn( (int) ( $ctx['user_id'] ?? 0 ) );
	}

	/* ── internals ────────────────────────────────────────────────────── */

	private static function requirement_met( array $req ): bool {
		if ( empty( $req ) ) {
			return true;
		}
		if ( ! empty( $req['class'] ) && ! class_exists( (string) $req['class'] ) ) {
			return false;
		}
		if ( ! empty( $req['function'] ) && ! function_exists( (string) $req['function'] ) ) {
			return false;
		}
		if ( ! empty( $req['filter'] ) && ( ! function_exists( 'has_filter' ) || false === has_filter( (string) $req['filter'] ) ) ) {
			return false;
		}
		return true;
	}

	/** `crm_contact:<arg>` → ['$contact_id' => int] after the acting user's scope check. @return array|WP_Error */
	private static function guard( string $guard, array $args, $uid ) {
		list( $kind, $arg ) = array_pad( explode( ':', $guard, 2 ), 2, '' );
		if ( 'crm_contact' === $kind ) {
			if ( ! class_exists( 'BizCity_MCP_Action_Support' ) ) {
				return new WP_Error( 'MCP_PERMISSION_DENIED', 'Không kiểm tra được phạm vi khách hàng.', array( 'status' => 503 ) );
			}
			$id = BizCity_MCP_Action_Support::contact_id( $args[ $arg ] ?? '' );
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần ' . $arg . ' (crm:<id>).', 422 );
			}
			$c = BizCity_MCP_Action_Support::contact_access( $id, false, $uid );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			return array( '$contact_id' => $id );
		}
		return new WP_Error( 'MCP_PERMISSION_DENIED', 'Guard không hợp lệ.', array( 'status' => 500 ) );
	}

	/** @return mixed|WP_Error raw answer of the source (null = provider gave nothing) */
	private static function dispatch( array $src, array $args, array $resolved, array $ctx ) {
		switch ( (string) $src['type'] ) {
			case 'php_callable':
				$callable = (string) $src['callable'];
				$parts    = explode( '::', $callable, 2 );
				if ( 2 === count( $parts ) ? ! is_callable( array( $parts[0], $parts[1] ) ) : ! function_exists( $parts[0] ) ) {
					return new WP_Error( 'MCP_TOOL_NOT_FOUND', 'Nguồn của tool không còn.', array( 'status' => 503 ) );
				}
				$call_args = array();
				foreach ( (array) ( $src['args'] ?? array() ) as $a ) {
					$call_args[] = self::value( $a, $args, $resolved );
				}
				try {
					return call_user_func_array( 2 === count( $parts ) ? array( $parts[0], $parts[1] ) : $parts[0], $call_args );
				} catch ( Throwable $e ) {
					return new WP_Error( 'MCP_INTERNAL_ERROR', 'Nguồn của tool báo lỗi.', array( 'status' => 500 ) );
				}
			case 'wp_filter':
				if ( is_callable( self::$apply ) ) {
					return call_user_func( self::$apply, (string) $src['filter'], self::clean_args( $args ), $ctx );
				}
				return function_exists( 'apply_filters' ) ? apply_filters( (string) $src['filter'], null, self::clean_args( $args ), $ctx ) : null;
			case 'rest_route':
				if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
					return null;
				}
				$req = new WP_REST_Request( strtoupper( (string) ( $src['method'] ?? 'GET' ) ), (string) $src['route'] );
				foreach ( (array) ( $src['query'] ?? array() ) as $k => $v ) {
					$req->set_param( (string) $k, self::value( $v, $args, $resolved ) );
				}
				$res = rest_do_request( $req );
				if ( ! is_object( $res ) || ! method_exists( $res, 'get_status' ) ) {
					return null;
				}
				if ( $res->get_status() >= 400 ) {
					return new WP_Error( 'MCP_INTERNAL_ERROR', 'Nguồn của tool trả lỗi ' . (int) $res->get_status() . '.', array( 'status' => 502 ) );
				}
				return $res->get_data();
		}
		return null;
	}

	/** `$name` → tool argument or guard value; anything else is a literal. */
	private static function value( $spec, array $args, array $resolved ) {
		if ( is_string( $spec ) && '' !== $spec && '$' === $spec[0] ) {
			if ( array_key_exists( $spec, $resolved ) ) {
				return $resolved[ $spec ];
			}
			$k = substr( $spec, 1 );
			return array_key_exists( $k, $args ) ? self::scalar( $args[ $k ] ) : null;
		}
		return $spec;
	}

	private static function scalar( $v ) {
		if ( is_string( $v ) ) {
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $v ) : trim( strip_tags( $v ) );
		}
		return is_scalar( $v ) || is_null( $v ) ? $v : ( is_array( $v ) ? array_map( array( __CLASS__, 'scalar' ), $v ) : null );
	}

	private static function clean_args( array $args ): array {
		$out = array();
		foreach ( $args as $k => $v ) {
			$out[ (string) $k ] = self::scalar( $v );
		}
		return $out;
	}

	/** Pick `data.<key>` from the raw answer by dotted path; no map ⇒ the whole (array) answer. */
	private static function map_output( $raw, array $map ): array {
		if ( ! $map ) {
			return is_array( $raw ) ? $raw : ( null === $raw ? array() : array( 'value' => $raw ) );
		}
		$out = array();
		foreach ( $map as $key => $path ) {
			$v = $raw;
			foreach ( explode( '.', (string) $path ) as $seg ) {
				if ( is_array( $v ) && array_key_exists( $seg, $v ) ) {
					$v = $v[ $seg ];
				} elseif ( is_object( $v ) && isset( $v->$seg ) ) {
					$v = $v->$seg;
				} else {
					$v = null;
					break;
				}
			}
			$out[ (string) $key ] = $v;
		}
		return $out;
	}

	/** `{ "stage": "string", "steps": "array" }` → JSON-schema properties for the envelope. */
	private static function output_props( array $output ): array {
		$props = array();
		foreach ( $output as $k => $type ) {
			$props[ (string) $k ] = array( 'type' => in_array( (string) $type, array( 'string', 'integer', 'number', 'boolean', 'array', 'object' ), true ) ? (string) $type : 'string' );
		}
		return $props;
	}

	/** @return array<string,array> tools registered by adapters in this request (name => manifest tool) */
	public static function registered(): array {
		return self::$tools;
	}

	/** Test seam. */
	public static function reset(): void {
		self::$tools = array();
	}
}
