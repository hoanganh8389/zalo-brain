<?php
/**
 * BizCity_CRM_Identity_Canon_Migration — one-shot rewrite of bizcity_crm_contact_identities.platform to the canonical
 * spelling (BizCity_CRM_Contact_Identity::canon_platform, PHASE-0.95 S95-F11 / G95-6a).
 *
 * Before: the cell wrote `zalo`, the inbox bridge `ZALO_PERSONAL`, older rows `ZALO` ⇒ the same person (same UID, same
 * number) became two or three CRM contacts. After: one identity row per (canon, platform_uid, account_id).
 *
 *   dry_run()  reads only; counts rows to rename and duplicate groups (no write, no option).
 *   run()      once per MIGRATION_VERSION (option): backup table ⇒ per duplicate group keep ONE row (canonical spelling first,
 *              then is_primary, then lowest id), delete the others, move the identities of a merged contact onto the kept
 *              contact ⇒ rewrite `platform` to canon. Every merged pair is logged by contact id / row id only — never the UID
 *              (R-CID-11). CRM rows of a merged contact (conversations, deals, notes) are NOT moved: the log lists the pairs for
 *              the CRM "gộp khách" tool.
 *
 * Not hooked anywhere: lane CL §8 — maintenance window, backup, dry_run before run (called from the deploy step).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, GCN quyền tác giả số 8877/2026/QTG.
 * // [2026-10-09 03:02 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F11 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 * @since      2026-10-09
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Identity_Canon_Migration', false ) ) {
	return;
}

final class BizCity_CRM_Identity_Canon_Migration {

	const MIGRATION_VERSION = 1;
	const OPTION            = 'bizcity_crm_identity_canon_migration';
	const LOG_OPTION        = 'bizcity_crm_identity_canon_migration_log';
	const LOG_MAX           = 500;

	/** @var callable|null test seam: fn(string $line): void — replaces error_log */
	public static $logger = null;

	/**
	 * Pure plan over identity rows. Rows: {id, contact_id, platform, platform_uid, account_id, is_primary}.
	 *
	 * @return array{rows:int, rename:array<int,array{id:int,to:string}>, groups:array<int,array{keep_id:int,keep_contact:int,drop_ids:int[],merge_contacts:int[]}>, merges:int}
	 */
	public static function plan( array $rows ): array {
		$by = array();
		foreach ( $rows as $r ) {
			$canon = BizCity_CRM_Contact_Identity::canon_platform( (string) ( $r['platform'] ?? '' ) );
			$key   = $canon . "\x1F" . (string) ( $r['platform_uid'] ?? '' ) . "\x1F" . (string) ( $r['account_id'] ?? '' );
			$by[ $key ][] = array(
				'id'         => (int) ( $r['id'] ?? 0 ),
				'contact_id' => (int) ( $r['contact_id'] ?? 0 ),
				'platform'   => (string) ( $r['platform'] ?? '' ),
				'canon'      => $canon,
				'is_primary' => (int) ( $r['is_primary'] ?? 0 ),
			);
		}
		$rename = array();
		$groups = array();
		$merges = 0;
		foreach ( $by as $set ) {
			usort( $set, static function ( $a, $b ) {
				$ca = $a['platform'] === $a['canon'] ? 0 : 1;
				$cb = $b['platform'] === $b['canon'] ? 0 : 1;
				if ( $ca !== $cb ) {
					return $ca - $cb;
				}
				if ( $a['is_primary'] !== $b['is_primary'] ) {
					return $b['is_primary'] - $a['is_primary'];
				}
				return $a['id'] - $b['id'];
			} );
			$keep = $set[0];
			if ( $keep['platform'] !== $keep['canon'] ) {
				$rename[] = array( 'id' => $keep['id'], 'to' => $keep['canon'] );
			}
			if ( count( $set ) < 2 ) {
				continue;
			}
			$drop    = array();
			$contact = array();
			foreach ( array_slice( $set, 1 ) as $d ) {
				$drop[] = $d['id'];
				if ( $d['contact_id'] > 0 && $d['contact_id'] !== $keep['contact_id'] && ! in_array( $d['contact_id'], $contact, true ) ) {
					$contact[] = $d['contact_id'];
				}
			}
			$groups[] = array( 'keep_id' => $keep['id'], 'keep_contact' => $keep['contact_id'], 'drop_ids' => $drop, 'merge_contacts' => $contact );
		}
		// Chains (A keeps 1 and merges 2, B keeps 2 and merges 3) all land on ONE contact: union-find over the merge pairs.
		$parent = array();
		$root   = static function ( $x ) use ( &$parent ) {
			while ( isset( $parent[ $x ] ) && $parent[ $x ] !== $x ) {
				$x = $parent[ $x ];
			}
			return $x;
		};
		foreach ( $groups as $g ) {
			$to = $root( $g['keep_contact'] );
			foreach ( $g['merge_contacts'] as $c ) {
				$rc = $root( $c );
				if ( $rc !== $to ) {
					$parent[ $rc ] = $to;
				}
			}
		}
		$moved = array();
		foreach ( $groups as $i => $g ) {
			$to   = $root( $g['keep_contact'] );
			$from = array();
			foreach ( $g['merge_contacts'] as $c ) {
				if ( $c !== $to && ! isset( $moved[ $c ] ) ) {
					$from[]      = $c;
					$moved[ $c ] = true;
				}
			}
			if ( $g['keep_contact'] !== $to && ! isset( $moved[ $g['keep_contact'] ] ) ) {
				$from[]                      = $g['keep_contact'];
				$moved[ $g['keep_contact'] ] = true;
			}
			$groups[ $i ]['keep_contact']   = $to;
			$groups[ $i ]['merge_contacts'] = $from;
			$merges                        += count( $from );
		}
		return array( 'rows' => count( $rows ), 'rename' => $rename, 'groups' => $groups, 'merges' => $merges );
	}

	/** Counts only — reads the table, writes nothing (no option, no log). */
	public static function dry_run(): array {
		$plan = self::plan( self::read_rows() );
		return array(
			'rows'             => $plan['rows'],
			'to_rename'        => count( $plan['rename'] ),
			'duplicate_groups' => count( $plan['groups'] ),
			'rows_to_delete'   => array_sum( array_map( static function ( $g ) { return count( $g['drop_ids'] ); }, $plan['groups'] ) ),
			'contact_merges'   => $plan['merges'],
			'done'             => self::done(),
		);
	}

	/**
	 * [2026-10-10 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.95 D95-13 — chủ chốt 2026-10-10: triển khai thật, không cần sao lưu.
	 * Before this hook nothing ever called run(): the canonical identities never reached a live site. Runs ONCE (option), on an admin
	 * page of an administrator, behind a 10-minute lock so two tabs do not race. Never throws into admin_init.
	 */
	public static function maybe_run(): void {
		if ( self::done() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_CRM_Contact_Identity' ) || get_transient( 'bizcity_crm_identity_canon_lock' ) ) {
			return;
		}
		set_transient( 'bizcity_crm_identity_canon_lock', 1, 10 * MINUTE_IN_SECONDS );
		try {
			$out = self::run( false );
			if ( is_wp_error( $out ) ) {
				error_log( '[crm][identity-canon] ' . $out->get_error_code() . ': ' . $out->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			error_log( '[crm][identity-canon] swallowed ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
		delete_transient( 'bizcity_crm_identity_canon_lock' );
	}

	public static function done(): bool {
		return (int) get_option( self::OPTION, 0 ) >= self::MIGRATION_VERSION;
	}

	/**
	 * Backup ⇒ merge exact duplicates ⇒ canonical platform. Once per MIGRATION_VERSION.
	 *
	 * @return array|WP_Error {status: done|skipped, backup, renamed, deleted, merged_contacts, log[]}
	 */
	public static function run( bool $backup_first = true ) {
		global $wpdb;
		if ( self::done() ) {
			return array( 'status' => 'skipped', 'reason' => 'already_done' );
		}
		$t      = BizCity_CRM_Contact_Identity::table();
		$backup = '';
		// 1. backup (structure + rows) — nothing else happens without it. The automatic run skips it (owner 2026-10-10, D95-13: little CRM data yet).
		if ( $backup_first ) {
			$backup = $t . '_bak_' . gmdate( 'YmdHis' );
			if ( false === $wpdb->query( "CREATE TABLE `{$backup}` LIKE `{$t}`" ) || false === $wpdb->query( "INSERT INTO `{$backup}` SELECT * FROM `{$t}`" ) ) {
				return new WP_Error( 'identity_backup_failed', 'Không sao lưu được bảng định danh CRM; chưa đổi gì.', array( 'status' => 500 ) );
			}
		}
		$plan    = self::plan( self::read_rows() );
		$log     = array();
		$deleted = 0;
		// 2. duplicates first (the rename would hit the unique key otherwise).
		foreach ( $plan['groups'] as $g ) {
			foreach ( $g['merge_contacts'] as $from ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET contact_id = %d, is_primary = 0 WHERE contact_id = %d", $g['keep_contact'], $from ) );
				$log[] = sprintf( 'merge contact %d -> %d (identity row %d kept)', $from, $g['keep_contact'], $g['keep_id'] );
			}
			$ids = array_map( 'intval', $g['drop_ids'] );
			if ( $ids ) {
				$wpdb->query( "DELETE FROM `{$t}` WHERE id IN (" . implode( ',', $ids ) . ')' );
				$deleted += count( $ids );
				$log[]    = sprintf( 'drop identity rows %s (kept %d)', implode( ',', $ids ), $g['keep_id'] );
			}
		}
		// 3. canonical spelling.
		foreach ( $plan['rename'] as $r ) {
			$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET platform = %s WHERE id = %d", $r['to'], $r['id'] ) );
		}
		$log = array_slice( $log, 0, self::LOG_MAX );
		foreach ( $log as $line ) {
			self::log( $line );
		}
		update_option( self::LOG_OPTION, array( 'at' => gmdate( 'c' ), 'backup' => $backup, 'lines' => $log ), false );
		update_option( self::OPTION, self::MIGRATION_VERSION, false );
		return array(
			'status'          => 'done',
			'backup'          => $backup,
			'renamed'         => count( $plan['rename'] ),
			'deleted'         => $deleted,
			'merged_contacts' => $plan['merges'],
			'log'             => $log,
		);
	}

	private static function read_rows(): array {
		global $wpdb;
		$t = BizCity_CRM_Contact_Identity::table();
		return (array) $wpdb->get_results( "SELECT id, contact_id, platform, platform_uid, account_id, is_primary FROM `{$t}` ORDER BY id ASC", ARRAY_A );
	}

	private static function log( string $line ): void {
		if ( is_callable( self::$logger ) ) {
			call_user_func( self::$logger, $line );
			return;
		}
		error_log( '[bizcity-crm-identity-canon] ' . $line );
	}
}
