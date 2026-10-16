<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 */
// [2026-10-16 04:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-I7 — connector-framework@1: ONE hook, ONE manifest per connector.
/**
 * Connector registry (connector-framework@1.0.0).
 *
 * Every connector (core, add-on, sibling plugin) registers by calling, once:
 *
 *   add_action( 'bizcity_connectors_register', function ( BizCity_Connector_Registry $r ) {
 *       $r->register( __DIR__ . '/contracts/connectors/<id>.json' );
 *   } );
 *
 * The registry reads and validates the manifest (same rules as bin/validate-connect-framework.mjs, section 10) and exposes:
 *   all() / get( $id )            the connectors, ordered by section then `order`
 *   by_section( 'own'|'composio'|'dev' )
 *   module_access_rows()          the rows for the "Phân quyền module" screen: one per defining manifest; manifests with
 *                                 `module_access.shared` add their id to that row's `connectors`
 *
 * Shell wiring (owner decision 2026-10-16: connectors show ONLY on the Phân quyền module screen, never on the ActivityBar):
 * shell_entries() turns each permission row into a BizCity_Twin_Shell_Registry entry with group 'connectors'; the shell registry keeps
 * that group out of the bar, the launcher and the default page, while module-access still governs it (cap bizcity_use_<id>).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Connector_Registry', false ) ) {
	return;
}

final class BizCity_Connector_Registry {

	const CONTRACT = 'connector-framework@1.0.0';
	const HOOK     = 'bizcity_connectors_register';

	const SECTIONS   = array( 'own', 'composio', 'dev' );
	const TRANSPORTS = array( 'cell', 'composio', 'dev_api', 'site_mcp' );

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,array>|null id => manifest */
	private $connectors = null;

	/** @var array<int,string> problems found while registering (id/file: message) */
	private $errors = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Connect the registry to the shell (idempotent). Called from bootstrap.php, NOT at include time: the class declaration is
	 * hoisted, so a trailing statement after the `class_exists` guard would never run (the early-bind guard trap).
	 */
	public static function boot() {
		static $done = false;
		if ( $done || ! function_exists( 'add_filter' ) ) {
			return;
		}
		$done = true;
		add_filter(
			'bizcity_twin_register_plugins',
			static function ( $entries ) {
				return self::instance()->shell_entries( $entries );
			},
			60
		);
	}

	/** Fresh instance for tests. */
	public static function fresh() {
		self::$instance = new self();
		return self::$instance;
	}

	/** Bundled manifests of this plugin (the seven W1 connectors). */
	public static function bundled_dir() {
		return dirname( __DIR__ ) . '/contracts/connectors';
	}

	private function load() {
		if ( null !== $this->connectors ) {
			return;
		}
		$this->connectors = array();
		foreach ( (array) glob( self::bundled_dir() . '/*.json' ) as $file ) {
			$this->register( $file );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( self::HOOK, $this );
		}
	}

	/**
	 * Register one manifest file. Returns the connector id, or null (the reason is in errors()).
	 *
	 * @param string $file
	 * @return string|null
	 */
	public function register( $file ) {
		if ( null === $this->connectors ) {
			$this->connectors = array();
		}
		$raw = is_readable( $file ) ? file_get_contents( $file ) : false;
		$m   = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $m ) ) {
			return $this->fail( basename( (string) $file ), 'manifest unreadable or not JSON' );
		}
		$id = isset( $m['id'] ) ? (string) $m['id'] : '';
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $id ) || basename( $file, '.json' ) !== $id ) {
			return $this->fail( basename( (string) $file ), 'id must equal the file name' );
		}
		if ( self::CONTRACT !== ( isset( $m['contract'] ) ? $m['contract'] : '' ) ) {
			return $this->fail( $id, 'contract must be ' . self::CONTRACT );
		}
		if ( ! in_array( isset( $m['section'] ) ? $m['section'] : '', self::SECTIONS, true ) || ! in_array( isset( $m['transport'] ) ? $m['transport'] : '', self::TRANSPORTS, true ) ) {
			return $this->fail( $id, 'unknown section or transport' );
		}
		// [2026-10-16 09:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-U2: executors may be empty only when the manifest says so (planned / executors_pending)
		$has_ex = ! empty( $m['executors'] ) && is_array( $m['executors'] );
		if ( empty( $m['label'] ) || empty( $m['desc'] ) || ( ! $has_ex && empty( $m['planned'] ) && empty( $m['executors_pending'] ) ) ) {
			return $this->fail( $id, 'label, desc and executors (or planned / executors_pending) are required' );
		}
		if ( empty( $m['module_access'] ) || ! is_array( $m['module_access'] ) ) {
			return $this->fail( $id, 'module_access is required (every connector has a permission row)' );
		}
		if ( isset( $this->connectors[ $id ] ) ) {
			return $this->fail( $id, 'already registered' );
		}
		$this->connectors[ $id ] = $m;
		return $id;
	}

	private function fail( $who, $why ) {
		$this->errors[] = $who . ': ' . $why;
		return null;
	}

	/** @return array<int,string> */
	public function errors() {
		$this->load();
		return $this->errors;
	}

	/** @return array<string,array> ordered by section (own, composio, dev) then `order` */
	public function all() {
		$this->load();
		$rank = array_flip( self::SECTIONS );
		$rows = $this->connectors;
		uasort(
			$rows,
			static function ( $a, $b ) use ( $rank ) {
				$s = $rank[ $a['section'] ] <=> $rank[ $b['section'] ];
				return 0 !== $s ? $s : ( (int) ( isset( $a['order'] ) ? $a['order'] : 100 ) <=> (int) ( isset( $b['order'] ) ? $b['order'] : 100 ) );
			}
		);
		return $rows;
	}

	/** @return array|null */
	public function get( $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** @return array<string,array> */
	public function by_section( $section ) {
		return array_filter(
			$this->all(),
			static function ( $m ) use ( $section ) {
				return $m['section'] === $section;
			}
		);
	}

	/**
	 * [2026-10-16 04:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-I7 — the permission rows as shell-registry entries (filter bizcity_twin_register_plugins).
	 *
	 * @param array $entries
	 * @return array
	 */
	public function shell_entries( $entries ) {
		$entries = is_array( $entries ) ? $entries : array();
		// [2026-10-17 08:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-I7: fail SAFE on a half-deployed site. The shell registry that knows the 'connectors' group (and keeps it off the ActivityBar) is a
		// different file; if it is the old one (partial upload / stale OPcache) these rows would show up as sidebar icons. No guard word ⇒ add nothing.
		if ( ! class_exists( 'BizCity_Twin_Shell_Registry', false ) || ! defined( 'BizCity_Twin_Shell_Registry::CONNECTORS_GROUP' ) ) {
			return $entries;
		}
		foreach ( $this->module_access_rows() as $row ) {
			$entries[] = array(
				'id'         => $row['id'],
				'label'      => $row['label'],
				'mode'       => 'link',
				'group'      => 'connectors',
				'capability' => 'read',
				'access'     => array(
					'mode'          => $row['mode'],
					'default_roles' => $row['default_roles'],
				),
			);
		}
		return $entries;
	}

	/**
	 * [2026-10-16 09:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-I7 (doc 62 §11.2) — is the permission row usable yet? `state`:
	 *   soon          every connector of the row is planned (Telegram)         ⇒ switch disabled, folded, badge "Sắp có"
	 *   needs_connect the row covers Composio connectors and none is connected ⇒ switch disabled, folded, hint "Kết nối ở Công cụ trước"
	 *   open          otherwise (including: connection state unknown — never lock people out on a guess)
	 *
	 * @param string        $module_id       permission row id (connect_*)
	 * @param string[]|null $active_toolkits Composio toolkits with an ACTIVE connection; null = unknown
	 * @return array{state:string,badge:string,hint:string}
	 */
	public function availability( $module_id, $active_toolkits ) {
		$row = $this->module_access_rows()[ $module_id ] ?? null;
		if ( ! $row ) {
			return array( 'state' => 'open', 'badge' => '', 'hint' => '' );
		}
		$members = array_map( array( $this, 'get' ), $row['connectors'] );
		if ( $members && ! array_filter(
			$members,
			static function ( $m ) {
				return empty( $m['planned'] );
			}
		) ) {
			return array( 'state' => 'soon', 'badge' => 'Sắp có', 'hint' => '' );
		}
		$toolkits = array();
		foreach ( $members as $m ) {
			if ( 'composio' === $m['section'] && ! empty( $m['auth']['toolkit'] ) ) {
				$toolkits[] = (string) $m['auth']['toolkit'];
			}
		}
		if ( $toolkits && is_array( $active_toolkits ) && ! array_intersect( $toolkits, $active_toolkits ) ) {
			return array( 'state' => 'needs_connect', 'badge' => '', 'hint' => 'Kết nối ở Công cụ trước' );
		}
		return array( 'state' => 'open', 'badge' => '', 'hint' => '' );
	}

	/**
	 * Rows of the "Phân quyền module" screen (group "connectors"). One row per manifest that DEFINES `module_access`;
	 * a manifest with `module_access.shared` adds itself to that row's `connectors` instead of making a row.
	 * A shared reference to a row nobody defines is reported in errors() and dropped.
	 *
	 * @return array<string,array> module id => row
	 */
	public function module_access_rows() {
		$rows = array();
		foreach ( $this->all() as $cid => $m ) {
			$ma = $m['module_access'];
			if ( ! empty( $ma['shared'] ) ) {
				continue;
			}
			if ( empty( $ma['id'] ) ) {
				$this->fail( $cid, 'module_access.id missing' );
				continue;
			}
			if ( isset( $rows[ $ma['id'] ] ) ) {
				$this->fail( $cid, 'module_access.id ' . $ma['id'] . ' already used' );
				continue;
			}
			$rows[ $ma['id'] ] = array(
				'id'            => (string) $ma['id'],
				'group'         => isset( $ma['group'] ) ? (string) $ma['group'] : 'connectors',
				'parent'        => isset( $ma['parent'] ) ? $ma['parent'] : null,
				'label'         => isset( $ma['label'] ) ? (string) $ma['label'] : (string) $m['label'],
				'mode'          => isset( $ma['mode'] ) ? (string) $ma['mode'] : 'grantable',
				'default_roles' => isset( $ma['default_roles'] ) ? array_values( (array) $ma['default_roles'] ) : array(),
				'availability'  => isset( $ma['availability'] ) ? (string) $ma['availability'] : 'connector_status',
				'connectors'    => array( $cid ),
			);
		}
		foreach ( $this->all() as $cid => $m ) {
			$ref = isset( $m['module_access']['shared'] ) ? (string) $m['module_access']['shared'] : '';
			if ( '' === $ref ) {
				continue;
			}
			if ( ! isset( $rows[ $ref ] ) ) {
				$this->fail( $cid, 'shared module_access ' . $ref . ' is defined by no manifest' );
				continue;
			}
			$rows[ $ref ]['connectors'][] = $cid;
		}
		return $rows;
	}
}
