<?php
/**
 * BizCity CRM Contact Identity — multi-platform identity resolution.
 *
 * R-UNIFY Wave 1 (2026-06-15) — decouples "which contact" from "which
 * platform account". One CRM contact can have N identities across Zalo OA,
 * Facebook, WebChat, Telegram, etc.
 *
 * Table: bizcity_crm_contact_identities (per-blog, $wpdb->prefix)
 *
 * Canonical entry-point for CRM_Inbox_Bridge:
 *   $contact_id = BizCity_CRM_Contact_Identity::resolve_or_create(
 *       'ZALO', $platform_uid, $oa_id
 *   );
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-15 (R-UNIFY Wave 1)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — one-shot canonical-platform migration (dry_run / run, never auto-run).
// Above the double-load guard: PHP hoists the class below, so the guard returns even on a first load.
require_once __DIR__ . '/class-crm-identity-canon-migration.php';
// [2026-10-09 03:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — read-only order box (channel · name · masked UID).
require_once __DIR__ . '/class-crm-order-identity-metabox.php';

// [2026-06-15 Johnny Chu] R-UNIFY Wave 1 — double-load guard.
if ( class_exists( 'BizCity_CRM_Contact_Identity', false ) ) {
	return;
}

final class BizCity_CRM_Contact_Identity {

	const TABLE_NAME     = 'bizcity_crm_contact_identities';
	const SCHEMA_VERSION = 1;
	const SCHEMA_OPT     = 'bizcity_crm_contact_identities_schema';

	/** @var string */
	private static $table = '';

	/** @var bool[] Per-request install cache keyed by blog_id. */
	private static $ensured = array();

	// [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — one spelling per platform (R-CID-5b, G95-6a): the cell sends
	// `zalo`, the inbox bridge sent `ZALO_PERSONAL`, older rows hold `ZALO` ⇒ three identities for one person. Keys are lowercase.
	const PLATFORM_CANON = array(
		'zalo'          => 'zalo',
		'zalo_personal' => 'zalo',
		'zalo_oa'       => 'zalo_oa',
		'facebook'      => 'fb',
		'fb'            => 'fb',
		'messenger'     => 'messenger',
		'webchat'       => 'web_guest',
		'web_guest'     => 'web_guest',
		'telegram'      => 'telegram',
	);

	/**
	 * [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — canonical platform of an identity: lowercase, mapped through
	 * PLATFORM_CANON; anything else keeps its own lowercase name (`wp`, `zalo_bot` …), [a-z0-9_] only, ≤ 32 chars.
	 */
	/**
	 * [2026-10-09 10:20 PM Johnny Chu - Chu Hoàng Anh] R-AF-16 — router hint for metadata queries (same rule as BizCity_Table_Metadata::route_hint):
	 * a `wp_<blog>_…` table name in a comment makes BizCity_WPDB_Router run the information_schema query on that blog's shard.
	 */
	/**
	 * [2026-10-09 10:55 PM Johnny Chu - Chu Hoàng Anh] R-AF-16 — the two silent "return 0" of resolve_or_create leave one log line, so the next
	 * failure proves its cause: which branch, which shard the router is on (`current_bizname`), the blog. Never the UID or a name; the
	 * DB error has its digits masked (a duplicate-key message would echo the UID).
	 */
	private static function log_unresolved( string $why, string $table ): void {
		global $wpdb;
		$err = preg_replace( '/\d{4,}/', '#', substr( (string) ( $wpdb->last_error ?? '' ), 0, 160 ) );
		error_log( '[BIZCITY_CRM_IDENTITY] ' . wp_json_encode( array(
			'step'  => $why,
			'blog'  => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'table' => $table,
			'shard' => isset( $wpdb->current_bizname ) ? (string) $wpdb->current_bizname : '',
			'err'   => $err,
		) ) );
	}

	public static function route_hint( string $table ): string {
		return preg_match( '/^wp_\d+_[a-z0-9_]+$/i', $table ) ? ' /* route:' . $table . ' */' : '';
	}

	public static function canon_platform( string $platform ): string {
		$p = strtolower( trim( $platform ) );
		if ( isset( self::PLATFORM_CANON[ $p ] ) ) {
			return self::PLATFORM_CANON[ $p ];
		}
		return substr( (string) preg_replace( '/[^a-z0-9_]/', '', $p ), 0, 32 );
	}

	/**
	 * [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — every stored spelling of a canonical platform (the canon itself
	 * first, then the legacy names mapping to it) so a lookup still finds rows the migration has not rewritten yet.
	 *
	 * @return string[]
	 */
	public static function platform_aliases( string $canon ): array {
		$canon = self::canon_platform( $canon );
		$out   = array( $canon );
		foreach ( self::PLATFORM_CANON as $raw => $to ) {
			if ( $to === $canon && ! in_array( $raw, $out, true ) ) {
				$out[] = $raw;
			}
		}
		return $out;
	}

	/** [2026-10-09 03:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — order meta keys of the person an order is for (doc 25 §3, HPOS order meta). */
	const ORDER_META_KEYS = array( '_bizcity_platform', '_bizcity_channel_ref', '_bizcity_platform_uid', '_bizcity_display_name', '_bizcity_contact_id', '_bizcity_person_ref', '_bizcity_source' );

	/**
	 * [2026-10-09 03:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — the 7 identity meta of an order, pure. `person_ref` = a hash of
	 * (canon | channel_ref | uid) when the UID is known, else the given ref (the cell's sender hash): never the raw UID. The UID itself
	 * is stored only under `_bizcity_platform_uid` (site DB, R-CID-11) — callers never put it in a note or an email.
	 *
	 * @param array $in {platform, channel_ref, platform_uid, display_name, contact_id, person_ref, source}
	 * @return array<string,string|int>
	 */
	public static function order_identity_meta( array $in ): array {
		$canon = self::canon_platform( (string) ( $in['platform'] ?? '' ) );
		$ref   = substr( trim( (string) ( $in['channel_ref'] ?? '' ) ), 0, 190 );
		$uid   = substr( trim( (string) ( $in['platform_uid'] ?? '' ) ), 0, 190 );
		$name  = trim( (string) ( $in['display_name'] ?? '' ) );
		$name  = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120, 'UTF-8' ) : substr( $name, 0, 120 );
		$pref  = '' !== $uid ? substr( hash( 'sha256', $canon . '|' . $ref . '|' . $uid ), 0, 32 ) : substr( (string) preg_replace( '/[^a-f0-9]/i', '', (string) ( $in['person_ref'] ?? '' ) ), 0, 64 );
		return array(
			'_bizcity_platform'     => $canon,
			'_bizcity_channel_ref'  => $ref,
			'_bizcity_platform_uid' => $uid,
			'_bizcity_display_name' => $name,
			'_bizcity_contact_id'   => max( 0, (int) ( $in['contact_id'] ?? 0 ) ),
			'_bizcity_person_ref'   => $pref,
			'_bizcity_source'       => substr( (string) preg_replace( '/[^a-z0-9_.:\-]/i', '', (string) ( $in['source'] ?? '' ) ), 0, 64 ),
		);
	}

	/**
	 * [2026-10-09 03:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F9 — the platform UID of a contact on one channel (canon + account), '' when
	 * none. Used to stamp an order made by a cell run, whose `_cell` never carries the UID.
	 */
	public static function uid_of_contact( int $contact_id, string $platform, string $account_id ): string {
		if ( $contact_id <= 0 ) {
			return '';
		}
		$canon = self::canon_platform( $platform );
		$alias = self::platform_aliases( $canon );
		foreach ( self::get_by_contact( $contact_id ) as $row ) {
			if ( in_array( strtolower( (string) $row['platform'] ), $alias, true ) && (string) $row['account_id'] === $account_id ) {
				return (string) $row['platform_uid'];
			}
		}
		return '';
	}

	/** "%s, %s, …" for an IN list of $n values. */
	private static function in_placeholders( int $n ): string {
		return implode( ', ', array_fill( 0, max( 1, $n ), '%s' ) );
	}

	/* ================================================================
	 *  Table helpers
	 * ================================================================ */

	public static function table(): string {
		if ( '' === self::$table ) {
			global $wpdb;
			self::$table = $wpdb->prefix . self::TABLE_NAME;
		}
		return self::$table;
	}

	/**
	 * Ensure the table exists (ADD-only, idempotent).
	 *
	 * [2026-06-15 Johnny Chu] R-UNIFY Wave 1 — self-installing installer.
	 */
	public static function ensure(): void {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( ! empty( self::$ensured[ $blog_id ] ) ) {
			return;
		}
		$stored = (int) get_option( self::SCHEMA_OPT, 0 );
		if ( $stored >= self::SCHEMA_VERSION ) {
			self::$ensured[ $blog_id ] = true;
			return;
		}
		self::install();
		update_option( self::SCHEMA_OPT, self::SCHEMA_VERSION, false );
		self::$ensured[ $blog_id ] = true;
	}

	private static function install(): void {
		global $wpdb;
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$t} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id   BIGINT UNSIGNED NOT NULL,
			platform     VARCHAR(32) NOT NULL DEFAULT 'unknown',
			platform_uid VARCHAR(190) NOT NULL DEFAULT '',
			account_id   VARCHAR(190) NOT NULL DEFAULT '',
			is_primary   TINYINT(1) NOT NULL DEFAULT 0,
			meta_json    LONGTEXT NULL,
			created_at   DATETIME NOT NULL,
			updated_at   DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_platform_uid_acct (platform, platform_uid, account_id),
			KEY idx_contact (contact_id),
			KEY idx_platform (platform)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ================================================================
	 *  Public API
	 * ================================================================ */

	/**
	 * Resolve an existing identity row or return 0 (no contact yet).
	 *
	 * @param string $platform     FACEBOOK | ZALO | ZALO_OA | ...
	 * @param string $platform_uid Platform-side user ID.
	 * @param string $account_id   Page / OA / bot account ID. '' = N/A.
	 * @return int  contact_id or 0 if not found.
	 */
	public static function find_contact_id( string $platform, string $platform_uid, string $account_id = '' ): int {
		global $wpdb;
		self::ensure();
		// [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — canonical platform; legacy spellings still match until the
		// migration rewrites them (canonical row first).
		$aliases = self::platform_aliases( $platform );
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT contact_id FROM ' . self::table() . ' WHERE platform IN (' . self::in_placeholders( count( $aliases ) ) . ') AND platform_uid = %s AND account_id = %s ORDER BY (platform = %s) DESC, is_primary DESC, id ASC LIMIT 1',
				array_merge( $aliases, array( $platform_uid, $account_id, $aliases[0] ) )
			),
			ARRAY_A
		);
		return $row ? (int) $row['contact_id'] : 0;
	}

	/**
	 * Resolve an existing identity or create a new contact + identity row.
	 *
	 * This is the CANONICAL entry-point for CRM_Inbox_Bridge. It resolves
	 * the contact_id for a platform user without creating duplicate contacts.
	 *
	 * When no identity exists AND $create_contact_data is provided, a new
	 * bizcity_crm_contacts row is inserted first, then the identity is linked.
	 *
	 * @param string $platform           FACEBOOK | ZALO | ZALO_OA | WEBCHAT | TELEGRAM
	 * @param string $platform_uid       Platform-side sender/user ID.
	 * @param string $account_id         Page/OA/bot account ID. '' = N/A.
	 * @param array  $create_contact_data {name, avatar_url, source, phone, email} used when creating a new contact. Empty = skip create.
	 * @return int  contact_id (>0) on success, 0 on failure.
	 */
	public static function resolve_or_create(
		string $platform,
		string $platform_uid,
		string $account_id = '',
		array $create_contact_data = array()
	): int {
		// [2026-06-15 Johnny Chu] R-UNIFY Wave 1 — resolve_or_create canonical.
		self::ensure();
		// [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — the identity row stores the canonical platform; the contacts
		// row keeps the caller's upper-case code (CRM UI / repository read `ZALO_BOT`, `FB_MESS` … there).
		$raw_platform = $platform;
		$platform     = self::canon_platform( $platform );
		$platform_uid = trim( $platform_uid );
		if ( '' === $platform || '' === $platform_uid ) {
			return 0;
		}

		// 1. Check existing identity.
		$contact_id = self::find_contact_id( $platform, $platform_uid, $account_id );
		if ( $contact_id > 0 ) {
			return $contact_id;
		}

		// 2. No identity found; need a contact row.
		if ( empty( $create_contact_data ) ) {
			return 0;
		}

		// 3. Check if contacts table exists (guard: CRM may not be active).
		global $wpdb;
		$contacts_table = $wpdb->prefix . 'bizcity_crm_contacts';
		// [2026-10-09 10:20 PM Johnny Chu - Chu Hoàng Anh] R-AF-16 — shard-aware check. The bare information_schema query carried no route hint, so
		// BizCity_WPDB_Router ran it on the main DB, found no wp_<blog>_ table there and this returned 0 ("CRM not active") for every new
		// sender: the owner of 0562 608 899 got scenario_contact_unresolved on "đăng web" (2026-10-09 20:23).
		$has_contacts = class_exists( 'BizCity_Table_Metadata' )
			? (bool) BizCity_Table_Metadata::table_exists( $contacts_table )
			: (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s' . self::route_hint( $contacts_table ),
				$contacts_table
			) );
		if ( ! $has_contacts ) {
			self::log_unresolved( 'contacts_table_missing', $contacts_table );
			return 0;
		}

		// 4. Upsert contact by (platform, platform_uid) — respect the existing
		//    unique key on bizcity_crm_contacts if still present.
		$contact_name   = sanitize_text_field( $create_contact_data['name'] ?? $platform_uid );
		$contact_source = sanitize_text_field( $create_contact_data['source'] ?? 'crm_inbox' );
		$now            = current_time( 'mysql' );

		// Try to find by existing contacts.platform_uid first (legacy dedup).
		// [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F12 (G95-6b) — only for an identity WITHOUT an account: the same
		// Zalo UID seen by two numbers (two account_id) is two contacts, never folded into the first one by contacts.platform_uid.
		$existing = 0;
		if ( '' === $account_id ) {
			$legacy   = array_map( 'strtoupper', self::platform_aliases( $platform ) );
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$contacts_table} WHERE platform IN (" . self::in_placeholders( count( $legacy ) ) . ') AND platform_uid = %s LIMIT 1',
				array_merge( $legacy, array( $platform_uid ) )
			) );
		}
		if ( $existing ) {
			$contact_id = (int) $existing;
		} else {
			// Insert new contact row.
			$new_contact = array(
				'name'       => $contact_name,
				'platform'   => strtoupper( $raw_platform ),
				'platform_uid' => $platform_uid,
				'source'     => $contact_source,
				'created_at' => $now,
				'updated_at' => $now,
			);
			if ( ! empty( $create_contact_data['avatar_url'] ) ) {
				$new_contact['avatar_url'] = esc_url_raw( $create_contact_data['avatar_url'] );
			}
			if ( ! empty( $create_contact_data['phone'] ) ) {
				$new_contact['phone'] = sanitize_text_field( $create_contact_data['phone'] );
			}
			if ( ! empty( $create_contact_data['email'] ) ) {
				$new_contact['email'] = sanitize_email( $create_contact_data['email'] );
			}
			$wpdb->insert( $contacts_table, $new_contact );
			$contact_id = (int) $wpdb->insert_id;
		}

		if ( $contact_id <= 0 ) {
			self::log_unresolved( 'contact_insert_failed', $contacts_table );
			return 0;
		}

		// 5. Register identity row (INSERT IGNORE for race-safe idempotency).
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . '
			(contact_id, platform, platform_uid, account_id, is_primary, created_at, updated_at)
			VALUES (%d, %s, %s, %s, 1, %s, %s)',
			$contact_id,
			$platform, // [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — canonical, was strtoupper()
			$platform_uid,
			$account_id,
			$now,
			$now
		) );

		return $contact_id;
	}

	/**
	 * Return all identity rows for a contact.
	 *
	 * @param int $contact_id
	 * @return array[]
	 */
	public static function get_by_contact( int $contact_id ): array {
		global $wpdb;
		self::ensure();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE contact_id = %d ORDER BY is_primary DESC, id ASC',
				$contact_id
			),
			ARRAY_A
		) ?: array();
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Link an existing contact to a new platform identity (INSERT IGNORE).
	 *
	 * @param int    $contact_id
	 * @param string $platform
	 * @param string $platform_uid
	 * @param string $account_id
	 * @return bool
	 */
	public static function link( int $contact_id, string $platform, string $platform_uid, string $account_id = '' ): bool {
		global $wpdb;
		self::ensure();
		// [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — a legacy spelling of the same identity already linked ⇒ no
		// second row under the canonical name (INSERT IGNORE alone would not see `ZALO_PERSONAL` vs `zalo`).
		if ( self::find_contact_id( $platform, $platform_uid, $account_id ) > 0 ) {
			return true;
		}
		$now = current_time( 'mysql' );
		return false !== $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . '
			(contact_id, platform, platform_uid, account_id, is_primary, created_at, updated_at)
			VALUES (%d, %s, %s, %s, 0, %s, %s)',
			$contact_id,
			self::canon_platform( $platform ), // [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — canonical, was strtoupper()
			$platform_uid,
			$account_id,
			$now,
			$now
		) );
	}

	/**
	 * Backfill identities from bizcity_crm_contacts (Wave 1 migration).
	 * Runs once — idempotent via INSERT IGNORE.
	 *
	 * @return int  Number of rows inserted.
	 */
	public static function backfill_from_contacts(): int {
		global $wpdb;
		self::ensure();
		$contacts_table = $wpdb->prefix . 'bizcity_crm_contacts';
		$now            = current_time( 'mysql' );
		$result         = $wpdb->query(
			"INSERT IGNORE INTO " . self::table() . "
			(contact_id, platform, platform_uid, account_id, is_primary, created_at, updated_at)
			SELECT id, " . self::canon_sql( 'platform' ) . ", platform_uid, '', 1, '{$now}', '{$now}'
			FROM {$contacts_table}
			WHERE platform_uid IS NOT NULL AND platform_uid <> ''"
		);
		return (int) $result;
	}

	/**
	 * [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — SQL mirror of canon_platform() for a column (backfill): the map
	 * keys are fixed identifiers of this class, never user input.
	 */
	public static function canon_sql( string $column ): string {
		$col  = preg_replace( '/[^a-z0-9_]/i', '', $column );
		$sql  = 'CASE LOWER(TRIM(' . $col . '))';
		foreach ( self::PLATFORM_CANON as $raw => $to ) {
			$sql .= " WHEN '" . $raw . "' THEN '" . $to . "'";
		}
		return $sql . ' ELSE LOWER(TRIM(' . $col . ')) END';
	}

	/* ================================================================
	 *  Internal
	 * ================================================================ */

	private static function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['contact_id'] = (int) $row['contact_id'];
		$row['is_primary'] = (int) $row['is_primary'];
		$row['meta']       = isset( $row['meta_json'] ) && '' !== $row['meta_json']
			? json_decode( $row['meta_json'], true ) : null;
		return $row;
	}
}
