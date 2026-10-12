<?php
/**
 * BizCity_MCP_Library — loader of a plugin's MCP tool library `mcp/tools.json` (`bizcity-mcp-library@1.0.0`).
 *
 * PHASE-0.101 S101-L4 (doc 30 §2.2–2.3, zalo-brain-extension@1.1): a plugin ships its tool descriptors as data, one schema with
 * the registry descriptor (`tool-registry@1` + `bizcity-mcp-tool@1`), and registers them with ONE call:
 *
 *   add_action( 'zalo_brain_register', static function () {
 *       BizCity_Zalo_Brain::register_from_manifest( __DIR__ . '/zalo-brain.json' );
 *       BizCity_MCP_Library::register( __DIR__ . '/mcp/tools.json', array(
 *           'crm.task.create' => array( 'handler' => array( 'My_MCP', 'task_create' ), 'preview' => array( 'My_MCP', 'task_preview' ) ),
 *       ), 'my-plugin' );
 *   } );
 *
 * The library is a PROJECTION of the code: `bin/validate-zalo-brain-extension.mjs` compares it with the manifest's `mcp_tools`
 * and the `@mcp` markers. Rules enforced here (checklist of doc 30 §2.2): dotted name, `mode` required, non-empty title /
 * description / input_schema, a write ⇒ `confirm: always` + `preview: true` + `required_cap` (or `no_standard_cap`), ≤ 40 tools.
 * A tool that breaks a rule is skipped (WP_DEBUG log), never half-registered. No model, no database, no cell call here.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-11 Johnny Chu - Chu Hoàng Anh] PHASE-0.101 S101-L4 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_MCP_Library', false ) ) {
	return;
}

final class BizCity_MCP_Library {

	const CONTRACT  = 'bizcity-mcp-library@1.0.0';
	const MAX_TOOLS = 40;
	const NAME_RE   = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,2}$/';

	/** @var array<string,array{owner:string,tools:string[],skipped:array}> file => summary */
	private static $loaded = array();

	/**
	 * Read and validate a library file. @return array<string,array> name => descriptor (valid tools only; [] when unreadable).
	 */
	public static function load( string $file ): array {
		$file = str_replace( '\\', '/', $file );
		if ( '' === $file || ! is_readable( $file ) ) {
			return array();
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$lib = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $lib ) || ! isset( $lib['tools'] ) || ! is_array( $lib['tools'] ) ) {
			self::note( $file, 'library is not a JSON object with tools[]' );
			return array();
		}
		if ( self::CONTRACT !== (string) ( $lib['contract'] ?? '' ) ) {
			self::note( $file, 'contract must be ' . self::CONTRACT );
			return array();
		}
		$owner   = sanitize_key( (string) ( $lib['owner'] ?? '' ) );
		$out     = array();
		$skipped = array();
		foreach ( array_slice( $lib['tools'], 0, self::MAX_TOOLS ) as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$why = self::validate_tool( $t );
			$n   = (string) ( $t['name'] ?? '?' );
			if ( null !== $why ) {
				$skipped[ $n ] = $why;
				self::note( $file, 'tool ' . $n . ' skipped: ' . $why );
				continue;
			}
			$out[ $n ] = $t;
		}
		self::$loaded[ $file ] = array( 'owner' => $owner, 'tools' => array_keys( $out ), 'skipped' => $skipped );
		return $out;
	}

	/** @return string|null reason the descriptor is refused, null when valid. */
	public static function validate_tool( array $t ) {
		$name = (string) ( $t['name'] ?? '' );
		if ( ! preg_match( self::NAME_RE, $name ) ) {
			return 'name must be <group>.<verb> or <group>.<entity>.<verb> (lowercase)';
		}
		foreach ( array( 'title', 'description', 'mode' ) as $k ) {
			if ( '' === trim( (string) ( $t[ $k ] ?? '' ) ) ) {
				return $k . ' required';
			}
		}
		if ( ! isset( $t['input_schema'] ) || ! is_array( $t['input_schema'] ) || 'object' !== (string) ( $t['input_schema']['type'] ?? '' ) ) {
			return 'input_schema must be a JSON schema object';
		}
		if ( empty( $t['scopes'] ) || ! is_array( $t['scopes'] ) ) {
			return 'scopes[] required';
		}
		$write = array_key_exists( 'read_only', $t ) && false === $t['read_only'];
		if ( $write ) {
			if ( 'always' !== (string) ( $t['confirm'] ?? '' ) ) {
				return 'a write must declare confirm: always';
			}
			if ( true !== ( $t['preview'] ?? null ) ) {
				return 'a write must declare preview: true (the plugin passes the preview callable at register())';
			}
			if ( '' === trim( (string) ( $t['required_cap'] ?? '' ) ) && empty( $t['no_standard_cap'] ) ) {
				return 'a write must declare required_cap (or no_standard_cap: true)';
			}
		} elseif ( 'always' === (string) ( $t['confirm'] ?? '' ) && true !== ( $t['preview'] ?? null ) ) {
			return 'confirm: always needs preview: true';
		}
		if ( isset( $t['handler'] ) ) {
			return 'handler is code, not data: pass it to register()';
		}
		// [2026-10-12 09:48 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L1 — bizcity-mcp-library@1.1 optional keys
		// (doc 30 §3, LANE-CL S100-L1): when declared, `need` must sum to 100 (hard error, same rule as brain-policy
		// packs) and `audience` is restricted to owner/staff/guest, guest only for a read tool or an automation.* tool.
		if ( isset( $t['when_to_use'] ) && mb_strlen( (string) $t['when_to_use'] ) > 200 ) {
			return 'when_to_use must be <= 200 chars';
		}
		if ( isset( $t['need'] ) ) {
			if ( ! is_array( $t['need'] ) || ! $t['need'] ) {
				return 'need must be a non-empty object of int weights';
			}
			$sum = array_sum( array_map( 'floatval', $t['need'] ) );
			if ( abs( $sum - 100 ) > 1e-6 ) {
				return 'need weights must sum to 100';
			}
		}
		if ( isset( $t['audience'] ) ) {
			if ( ! is_array( $t['audience'] ) || array_diff( $t['audience'], array( 'owner', 'staff', 'guest' ) ) ) {
				return 'audience must be a subset of owner/staff/guest';
			}
			if ( in_array( 'guest', $t['audience'], true ) && $write && 0 !== strpos( $name, 'automation.' ) ) {
				return 'guest_write_tool: audience=guest is only allowed for a read tool or an automation.* tool';
			}
		}
		return null;
	}

	/**
	 * Load + register every tool of a library through BizCity_Zalo_Brain::register_tool() (the registry when the framework class
	 * is absent). `$handlers[name]` is a callable, or `{handler, preview}`. A tool without a handler is skipped.
	 *
	 * @return string[] names registered
	 */
	public static function register( string $file, array $handlers, string $owner = '' ): array {
		$done = array();
		foreach ( self::load( $file ) as $name => $t ) {
			$h = $handlers[ $name ] ?? ( $handlers[ (string) ( $t['llm_alias'] ?? '' ) ] ?? null );
			if ( is_array( $h ) && isset( $h['handler'] ) ) {
				$t['handler'] = $h['handler'];
				if ( isset( $h['preview'] ) ) {
					$t['preview'] = $h['preview'];
				}
			} elseif ( is_callable( $h ) ) {
				$t['handler'] = $h;
			} else {
				self::note( $file, 'tool ' . $name . ' has no handler' );
				continue;
			}
			if ( true === ( $t['preview'] ?? null ) ) {
				unset( $t['preview'] ); // the registry needs a callable; `true` only meant "the plugin provides one"
				if ( 'always' === (string) ( $t['confirm'] ?? '' ) ) {
					self::note( $file, 'tool ' . $name . ' declares confirm: always but register() got no preview callable' );
					continue;
				}
			}
			if ( '' !== $owner ) {
				$t['owner'] = $owner;
			}
			$ok = class_exists( 'BizCity_Zalo_Brain' ) && method_exists( 'BizCity_Zalo_Brain', 'register_tool' )
				? BizCity_Zalo_Brain::register_tool( $t )
				: ( class_exists( 'BizCity_MCP_Tool_Registry' ) && false !== BizCity_MCP_Tool_Registry::register( $name, $t ) );
			if ( $ok ) {
				$done[] = $name;
			}
		}
		return $done;
	}

	/** @return array<string,array{owner:string,tools:string[],skipped:array}> libraries loaded in this request (Bot Studio / health). */
	public static function libraries(): array {
		return self::$loaded;
	}

	private static function note( string $file, string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[bizcity-mcp-library] ' . basename( dirname( $file ) ) . '/' . basename( $file ) . ': ' . $msg . ' — see docs/developer/README.md §4' ); // [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.103 S103-L7 — log text only, behaviour unchanged
		}
	}
}
