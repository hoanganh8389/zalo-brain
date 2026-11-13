<?php
/**
 * Twin Sheet — "Chốt sổ" (snapshot) and the optional period lock (PHASE-0.123 W3, doc 34 §4, Q123-15).
 *
 * A snapshot is ONE journal row of kind `sheet.snapshot`. Its card is a fingerprint, not a copy of the rows:
 *   sheet_key · schema_version · period (YYYY-MM | YYYY | all) · from/to · as_of · row_count · totals[] (every summable column)
 *   · max_entry_id · fingerprint ("<id>.<rev>[v]" for every row in the period, ≤ FP_MAX, `v` = void) · sha256 · lock.
 * That is enough to answer "what changed since I closed October?" (added / changed / voided rows, totals then and now)
 * without storing the rows twice — the journal stays the book (R-JN-15), money rows are never overwritten (void_reenter).
 * Doc 34 §4 also planned a frozen CSV in the private file store; that part is NOT built (the diff replaces "view the frozen grid").
 *
 * [2026-10-20 03:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 (R-FILE-VERSION for the sheet): a snapshot is also a named, numbered CHECKPOINT.
 *   - version v<n> per sheet (tag fv:<sheet_key> + v<n>, version_parent = the checkpoint before), change note computed by code
 *     against the previous checkpoint of the same period (added / changed / voided rows — FV-6, never narrated by a model);
 *   - the rows are FROZEN inside the snapshot row (metadata.frozen: gzip + base64 JSON, ≤ FREEZE_MAX rows) so the book can be
 *     RESTORED to it: rows changed since go back, rows voided since are re-opened, rows added since are voided (nothing is ever deleted),
 *     and an automatic checkpoint "Trước khi khôi phục" is taken first, so a restore can itself be undone;
 *   - a period under "Khóa kỳ" cannot be restored until it is unlocked.
 *
 * Lock (`lock: true`): a row whose doc_date falls in a locked period cannot be edited, voided or created (423 `period_locked`);
 * unlocking is its own action. Pure functions here; the journal is reached through BizCity_Twin_Sheet_Writer /
 * BizCity_Twin_Sheet_Snapshot_Source, so tests swap in fakes.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-20 01:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 (A13 / B13) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Sheet_Snapshot', false ) ) {
	final class BizCity_Twin_Sheet_Snapshot {

		const KIND   = 'sheet.snapshot';
		const FP_MAX = 5000;
		const LIST_MAX = 100;
		const FREEZE_MAX   = 2000;   // rows frozen per checkpoint
		const FREEZE_BYTES = 400000; // gzip+base64 size cap of the frozen rows (non-autoloaded option twinsheet_frozen_<id> since 2026-10-21) // [2026-10-22 05:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W6 (audit) — comment matched the code
		const RESTORE_MAX  = 500;    // operations one restore may apply

		/** @return array|WP_Error [from, to] for 'YYYY-MM' | 'YYYY' | 'all' */
		public static function bounds( string $period ) {
			if ( 'all' === $period ) {
				return array( '', '' );
			}
			if ( 1 === preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $m ) ) {
				$from = $period . '-01';
				return array( $from, gmdate( 'Y-m-t', (int) strtotime( $from . ' UTC' ) ) );
			}
			if ( 1 === preg_match( '/^\d{4}$/', $period ) ) {
				return array( $period . '-01-01', $period . '-12-31' );
			}
			return BizCity_Twin_Sheet_Schema::error( 'period_invalid', 'Kỳ chốt không đúng.', 'Chọn một tháng (2026-10), một năm (2026) hoặc “all”.', 400 );
		}

		/** Does a lock list (periods) cover a doc_date? */
		public static function covers( array $locks, string $date ): bool {
			foreach ( $locks as $p ) {
				$p = (string) $p;
				if ( 'all' === $p || ( '' !== $date && 0 === strpos( $date, $p ) ) ) {
					return true;
				}
			}
			return false;
		}

		/** Pure: the card of a snapshot from the entries of the period. */
		public static function capture( array $sheet, array $entries, string $period, string $from, string $to, string $as_of, bool $lock, bool $truncated = false ): array {
			$countable = BizCity_Twin_Sheet_Engine::countable( $entries, $sheet );
			$totals    = array();
			foreach ( (array) $sheet['fields'] as $f ) {
				if ( in_array( 'sum', (array) ( $f['aggregate'] ?? array() ), true ) ) {
					$spec = BizCity_Twin_Sheet_Engine::normalize_spec( $sheet, array( 'fn' => 'sum', 'field' => $f['key'] ) );
					if ( ! is_wp_error( $spec ) ) {
						$totals[] = array( 'field' => $f['key'], 'label' => $f['label'], 'value' => (int) BizCity_Twin_Sheet_Engine::aggregate( $countable, $sheet, $spec )['value'] );
					}
				}
			}
			$fp = array();
			$max = 0;
			foreach ( $entries as $e ) {
				$id  = (int) ( $e['entry_id'] ?? 0 );
				$max = max( $max, $id );
				$fp[ $id ] = $id . '.' . (int) ( $e['rev'] ?? 1 ) . ( 'void' === (string) ( $e['state'] ?? '' ) ? 'v' : '' );
			}
			ksort( $fp );
			$fp_all = implode( ',', $fp );
			return array(
				'sheet_key'      => (string) $sheet['sheet_key'],
				'schema_version' => (int) ( $sheet['schema_version'] ?? 1 ),
				'period'         => $period,
				'from'           => $from,
				'to'             => $to,
				'as_of'          => $as_of,
				'row_count'      => count( $countable ),
				'totals'         => $totals,
				'max_entry_id'   => $max,
				'fingerprint'    => count( $fp ) <= self::FP_MAX ? $fp_all : '',
				'fp_truncated'   => count( $fp ) > self::FP_MAX || $truncated,
				'sha256'         => hash( 'sha256', $fp_all ),
				'lock'           => $lock ? 1 : 0,
			);
		}

		/**
		 * Pure: what changed since a snapshot. Rows are compared by "<id>.<rev>[v]".
		 *
		 * @return array{added:int,changed:int,voided:int,removed:int,changed_ids:int[],totals:array,same:bool,comparable:bool}
		 */
		public static function diff( array $card, array $entries_now, array $sheet ): array {
			$now = self::capture( $sheet, $entries_now, (string) $card['period'], (string) $card['from'], (string) $card['to'], '', false );
			if ( ! empty( $card['fp_truncated'] ) || '' === (string) ( $card['fingerprint'] ?? '' ) && (int) ( $card['max_entry_id'] ?? 0 ) > 0 ) {
				return array( 'added' => 0, 'changed' => 0, 'voided' => 0, 'removed' => 0, 'changed_ids' => array(), 'totals' => self::totals_pair( $card, $now ), 'same' => (string) $card['sha256'] === $now['sha256'], 'comparable' => false );
			}
			$was = array();
			foreach ( array_filter( explode( ',', (string) $card['fingerprint'] ), 'strlen' ) as $tok ) {
				$id = (int) $tok;
				$was[ $id ] = $tok;
			}
			$cur = array();
			foreach ( array_filter( explode( ',', (string) $now['fingerprint'] ), 'strlen' ) as $tok ) {
				$cur[ (int) $tok ] = $tok;
			}
			$added = $changed = $voided = 0;
			$ids   = array();
			foreach ( $cur as $id => $tok ) {
				if ( ! isset( $was[ $id ] ) ) {
					$added++;
					$ids[] = $id;
				} elseif ( $was[ $id ] !== $tok ) {
					if ( 'v' === substr( $tok, -1 ) && 'v' !== substr( $was[ $id ], -1 ) ) {
						$voided++;
					} else {
						$changed++;
					}
					$ids[] = $id;
				}
			}
			$removed = count( array_diff_key( $was, $cur ) );
			return array( 'added' => $added, 'changed' => $changed, 'voided' => $voided, 'removed' => $removed, 'changed_ids' => array_slice( $ids, 0, 50 ), 'totals' => self::totals_pair( $card, $now ), 'same' => (string) $card['sha256'] === $now['sha256'], 'comparable' => true );
		}

		private static function totals_pair( array $card, array $now ): array {
			$out = array();
			$cur = array();
			foreach ( (array) $now['totals'] as $t ) {
				$cur[ $t['field'] ] = $t['value'];
			}
			foreach ( (array) ( $card['totals'] ?? array() ) as $t ) {
				$out[] = array( 'field' => $t['field'], 'label' => $t['label'], 'then' => (int) $t['value'], 'now' => (int) ( $cur[ $t['field'] ] ?? 0 ) );
			}
			return $out;
		}

		// ── checkpoints: freeze / thaw / plan ─────────────────────────

		/** Freeze the rows of a period. @return string gzip+base64 JSON, '' when too big / no gzip (the checkpoint is then not restorable) */
		public static function freeze( array $entries ): string {
			if ( count( $entries ) > self::FREEZE_MAX || ! function_exists( 'gzcompress' ) ) {
				return '';
			}
			$rows = array();
			foreach ( $entries as $e ) {
				$rows[] = array( 'i' => (int) ( $e['entry_id'] ?? 0 ), 'r' => (int) ( $e['rev'] ?? 1 ), 's' => (string) ( $e['state'] ?? 'captured' ), 'd' => (string) ( $e['doc_date'] ?? '' ), 't' => (string) ( $e['title'] ?? '' ), 'c' => is_array( $e['card'] ?? null ) ? $e['card'] : array() );
			}
			$blob = base64_encode( (string) gzcompress( (string) self::json( $rows ), 6 ) );
			return strlen( $blob ) <= self::FREEZE_BYTES ? $blob : '';
		}

		/** @return array[]|null frozen rows */
		public static function thaw( string $blob ) {
			if ( '' === $blob || ! function_exists( 'gzuncompress' ) ) {
				return null;
			}
			$raw  = base64_decode( $blob, true );
			$json = false === $raw ? false : @gzuncompress( $raw );
			$rows = false === $json ? null : json_decode( (string) $json, true );
			return is_array( $rows ) ? $rows : null;
		}

		private static function json( $v ): string {
			$j = function_exists( 'wp_json_encode' ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : json_encode( $v, JSON_UNESCAPED_UNICODE );
			return false === $j ? '[]' : (string) $j;
		}

		private static function canon( $v ): string {
			if ( is_array( $v ) ) {
				ksort( $v );
				foreach ( $v as $k => $x ) {
					$v[ $k ] = is_array( $x ) ? json_decode( self::canon( $x ), true ) : ( is_scalar( $x ) || null === $x ? (string) $x : '' );
				}
			}
			return (string) self::json( $v );
		}

		/**
		 * Pure: what restoring would do. Rows are never deleted: added-since ⇒ void; voided-since ⇒ re-open; changed-since ⇒ put back.
		 *
		 * @param array[] $frozen  thawed rows
		 * @param array[] $current journal entries of the period now
		 * @return array{ops:array[],unvoid:int,void:int,revert:int,missing:int}
		 */
		public static function plan_restore( array $frozen, array $current ): array {
			$by  = array();
			foreach ( $current as $c ) {
				$by[ (int) ( $c['entry_id'] ?? 0 ) ] = $c;
			}
			$ops = array();
			$seen = array();
			$missing = 0;
			foreach ( $frozen as $f ) {
				$id = (int) ( $f['i'] ?? 0 );
				$seen[ $id ] = true;
				$c = $by[ $id ] ?? null;
				if ( ! $c ) {
					$missing++;
					continue;
				}
				$cur_void = 'void' === (string) ( $c['state'] ?? '' );
				$was_void = 'void' === (string) ( $f['s'] ?? '' );
				if ( $cur_void && ! $was_void ) {
					$ops[] = array( 'op' => 'unvoid', 'id' => $id, 'state' => (string) ( $f['s'] ?? 'captured' ) );
				} elseif ( ! $cur_void && $was_void ) {
					$ops[] = array( 'op' => 'void', 'id' => $id );
					continue;
				} elseif ( $cur_void && $was_void ) {
					continue;
				}
				if ( self::canon( $c['card'] ?? array() ) !== self::canon( $f['c'] ?? array() ) || (string) ( $c['doc_date'] ?? '' ) !== (string) ( $f['d'] ?? '' ) || (string) ( $c['title'] ?? '' ) !== (string) ( $f['t'] ?? '' ) ) {
					$ops[] = array( 'op' => 'revert', 'id' => $id, 'card' => is_array( $f['c'] ?? null ) ? $f['c'] : array(), 'doc_date' => (string) ( $f['d'] ?? '' ), 'title' => (string) ( $f['t'] ?? '' ) );
				}
			}
			foreach ( $by as $id => $c ) {
				if ( ! isset( $seen[ $id ] ) && 'void' !== (string) ( $c['state'] ?? '' ) ) {
					$ops[] = array( 'op' => 'void', 'id' => $id );
				}
			}
			$n = array( 'unvoid' => 0, 'void' => 0, 'revert' => 0 );
			foreach ( $ops as $o ) {
				$n[ $o['op'] ]++;
			}
			return array( 'ops' => $ops, 'unvoid' => $n['unvoid'], 'void' => $n['void'], 'revert' => $n['revert'], 'missing' => $missing );
		}

		/** A locked period that touches the checkpoint's period (equal, inside, or around it). */
		public static function lock_overlaps( array $locks, string $period ): bool {
			foreach ( $locks as $p ) {
				$p = (string) $p;
				if ( 'all' === $p || 'all' === $period || 0 === strpos( $p, $period ) || 0 === strpos( $period, $p ) ) {
					return true;
				}
			}
			return false;
		}

		// ── use cases ─────────────────────────────────────────────────

		/**
		 * Close a period. Same content ⇒ same row (idempotency key carries the content hash).
		 *
		 * @param callable $scan fn(array $sheet, string $from, string $to, int $owner): array{entries:array,truncated:bool}
		 * @return array|WP_Error {entry_id, ref_code, period, row_count, totals, lock, already}
		 */
		public static function create( array $sheet, array $input, array $ctx, callable $scan, BizCity_Twin_Sheet_Writer $w, string $as_of ) {
			$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
			if ( $owner <= 0 ) {
				return BizCity_Twin_Sheet_Schema::error( 'rest_forbidden', 'Bạn chưa đăng nhập.', 'Đăng nhập rồi thử lại.', 401 );
			}
			if ( empty( $sheet['editable'] ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_read_only', 'Sổ này chỉ để xem nên chưa chốt được.', 'Chốt sổ cho sổ thu chi, công nợ, việc cần làm hoặc sổ bạn tự tạo.', 403 );
			}
			$period = (string) ( $input['period'] ?? '' );
			$b      = self::bounds( $period );
			if ( is_wp_error( $b ) ) {
				return $b;
			}
			$lock = ! empty( $input['lock'] );
			if ( $lock && 'all' === $period ) {
				return BizCity_Twin_Sheet_Schema::error( 'period_invalid', 'Khóa kỳ cần một tháng hoặc một năm cụ thể.', 'Chọn tháng (2026-10) hoặc năm (2026) để khóa.', 400 );
			}
			$got  = $scan( $sheet, $b[0], $b[1], $owner );
			$card = self::capture( $sheet, (array) ( $got['entries'] ?? array() ), $period, $b[0], $b[1], $as_of, $lock, ! empty( $got['truncated'] ) );
			if ( ! empty( $got['truncated'] ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'snapshot_too_big', 'Kỳ này nhiều dòng quá để chốt một lần.', 'Chọn kỳ ngắn hơn (một tháng) rồi chốt lại.', 413 );
			}
			// R-FILE-VERSION: number, parent and the change note come from code, not from a model
			$prior   = self::listing( $sheet, $owner, $w );
			$version = count( $prior ) + 1;
			foreach ( $prior as $p ) {
				$version = max( $version, (int) $p['version'] + 1 );
			}
			$card['name']           = BizCity_Twin_Sheet_Schema::clean_text( (string) ( $input['name'] ?? '' ), 80 );
			$card['version']        = $version;
			$card['version_parent'] = $prior ? (int) $prior[0]['entry_id'] : 0;
			// [2026-10-21 11:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 (R-FILE-VERSION FV-7) — the card carries the family (one per sheet) and the time of the version, like every other versioned file
			$card['version_family'] = (string) ( $sheet['sheet_key'] ?? '' );
			$card['version_at']     = $as_of;
			$card['auto']          = ! empty( $input['auto'] ) ? 1 : 0;
			$card['change']         = array( 'added' => 0, 'changed' => 0, 'voided' => 0, 'removed' => 0, 'since' => 0 );
			foreach ( $prior as $p ) {
				if ( $p['period'] === $period ) {
					$was = $w->get_entry( (int) $p['entry_id'], $owner );
					if ( is_array( $was ) && is_array( $was['card'] ?? null ) ) {
						$d = self::diff( $was['card'], (array) $got['entries'], $sheet );
						if ( $d['comparable'] ) {
							$card['change'] = array( 'added' => $d['added'], 'changed' => $d['changed'], 'voided' => $d['voided'], 'removed' => $d['removed'], 'since' => (int) $p['entry_id'] );
						}
					}
					break;
				}
			}
			$frozen = $w instanceof BizCity_Twin_Sheet_Checkpoint_Store ? self::freeze( (array) $got['entries'] ) : '';
			$card['restorable'] = '' !== $frozen ? 1 : 0;
			$key = 'snap:' . (int) get_current_blog_id() . ':' . $owner . ':' . $sheet['sheet_key'] . ':' . $period . ':' . substr( $card['sha256'], 0, 16 ) . ( $lock ? ':L' : '' );
			$had = $w->find_by_key( $key, $owner );
			$row = $w->record( array(
				'owner_user_id'   => $owner,
				'kind'            => self::KIND,
				'title'           => '' !== $card['name'] ? $card['name'] : 'Chốt sổ ' . $sheet['display_name'] . ' · ' . $period,
				'tags'            => array( 'fv:' . $sheet['sheet_key'], 'v' . $version ),
				'doc_date'        => substr( $as_of, 0, 10 ),
				'card'            => $card,
				'state'           => 'captured',
				'source_type'     => 'menu',
				'idempotency_key' => $key,
				'origin'          => array( 'surface' => 'web', 'ref' => 'sheet:' . $sheet['sheet_key'] ),
				'by'              => array( 'role' => (string) ( $ctx['role'] ?? 'owner' ) ),
			) );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			self::forget_locks(); // a new snapshot may carry a lock: the journal door must see it at once
			if ( ! is_array( $had ) && '' !== $frozen && $w instanceof BizCity_Twin_Sheet_Checkpoint_Store ) {
				$w->save_frozen( (int) $row['entry_id'], $owner, $frozen );
			}
			$rc = is_array( $had ) ? (array) ( $had['card'] ?? array() ) : $card;
			return array( 'entry_id' => (int) $row['entry_id'], 'ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $row ), 'period' => $period, 'row_count' => $card['row_count'], 'totals' => $card['totals'], 'lock' => (bool) $lock, 'already' => is_array( $had ), 'version' => (int) ( $rc['version'] ?? $version ), 'restorable' => ! empty( $rc['restorable'] ), 'change' => (array) ( $rc['change'] ?? $card['change'] ) );
		}

		/** @return array[] [{entry_id, ref_code, period, as_of, row_count, totals, lock}] newest first */
		public static function listing( array $sheet, int $owner, BizCity_Twin_Sheet_Writer $w ): array {
			if ( ! $w instanceof BizCity_Twin_Sheet_Snapshot_Source ) {
				return array();
			}
			$out = array();
			foreach ( array_slice( $w->list_snapshots( $owner, (string) $sheet['sheet_key'] ), 0, self::LIST_MAX ) as $e ) {
				if ( 'void' === (string) ( $e['state'] ?? '' ) ) {
					continue;
				}
				$c     = (array) ( $e['card'] ?? array() );
				$out[] = array( 'entry_id' => (int) $e['entry_id'], 'ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $e ), 'period' => (string) ( $c['period'] ?? '' ), 'as_of' => (string) ( $c['as_of'] ?? '' ), 'row_count' => (int) ( $c['row_count'] ?? 0 ), 'totals' => (array) ( $c['totals'] ?? array() ), 'lock' => ! empty( $c['lock'] ), 'rev' => (int) ( $e['rev'] ?? 1 ), 'name' => (string) ( $c['name'] ?? '' ), 'version' => (int) ( $c['version'] ?? 0 ), 'parent' => (int) ( $c['version_parent'] ?? 0 ), 'change' => (array) ( $c['change'] ?? array() ), 'restorable' => ! empty( $c['restorable'] ), 'auto' => ! empty( $c['auto'] ) );
			}
			return $out;
		}

		/** Periods currently locked on a sheet. */
		public static function locked_periods( array $sheet, int $owner, BizCity_Twin_Sheet_Writer $w ): array {
			$p = array();
			foreach ( self::listing( $sheet, $owner, $w ) as $s ) {
				if ( $s['lock'] ) {
					$p[] = $s['period'];
				}
			}
			return array_values( array_unique( $p ) );
		}

		/** Lock or unlock an existing snapshot. @return array|WP_Error {entry_id, lock} */
		public static function set_lock( array $sheet, int $id, bool $lock, array $ctx, BizCity_Twin_Sheet_Writer $w, int $expected_rev = 0 ) {
			$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
			$e     = $owner > 0 ? $w->get_entry( $id, $owner ) : null;
			if ( ! is_array( $e ) || self::KIND !== (string) ( $e['kind'] ?? '' ) || (string) ( $e['card']['sheet_key'] ?? '' ) !== (string) $sheet['sheet_key'] ) {
				return BizCity_Twin_Sheet_Schema::error( 'snapshot_not_found', 'Không tìm thấy bản chốt này.', 'Tải lại danh sách bản chốt rồi thử lại.', 404 );
			}
			$card = (array) $e['card'];
			if ( $lock && 'all' === (string) ( $card['period'] ?? '' ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'period_invalid', 'Khóa kỳ cần một tháng hoặc một năm cụ thể.', 'Chốt lại theo từng tháng rồi khóa.', 400 );
			}
			if ( ( ! empty( $card['lock'] ) ) === $lock ) {
				return array( 'entry_id' => $id, 'lock' => $lock, 'already' => true );
			}
			$new = $card;
			$new['lock'] = $lock ? 1 : 0;
			$res = $w->update_card( $id, $owner, array(
				'card'         => $new,
				'history'      => array( 'at' => gmdate( 'c' ), 'by' => (string) ( $ctx['role'] ?? 'owner' ), 'via' => 'web', 'changes' => array( 'lock' => array( (int) ( $card['lock'] ?? 0 ), $new['lock'] ) ) ),
				'expected_rev' => $expected_rev > 0 ? $expected_rev : (int) $e['rev'],
			) );
			self::forget_locks();
			return is_wp_error( $res ) ? $res : array( 'entry_id' => $id, 'lock' => $lock, 'already' => false );
		}

		/**
		 * Put the book back to a checkpoint. `dry_run` ⇒ only the plan (counts). Real run: refuses a locked period, takes an automatic
		 * checkpoint of NOW first, then applies the plan; each operation reports on its own (partial failure is listed, never hidden).
		 *
		 * @return array|WP_Error {dry_run, plan:{unvoid,void,revert,missing}, applied?, errors?, undo_entry_id?}
		 */
		public static function restore( array $sheet, int $id, array $input, array $ctx, callable $scan, BizCity_Twin_Sheet_Writer $w, string $as_of ) {
			$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
			$e     = $owner > 0 ? $w->get_entry( $id, $owner ) : null;
			if ( ! is_array( $e ) || self::KIND !== (string) ( $e['kind'] ?? '' ) || (string) ( $e['card']['sheet_key'] ?? '' ) !== (string) $sheet['sheet_key'] ) {
				return BizCity_Twin_Sheet_Schema::error( 'snapshot_not_found', 'Không tìm thấy điểm lưu này.', 'Tải lại danh sách điểm lưu rồi thử lại.', 404 );
			}
			if ( empty( $sheet['editable'] ) || ! $w instanceof BizCity_Twin_Sheet_Checkpoint_Store ) {
				return BizCity_Twin_Sheet_Schema::error( 'restore_unavailable', 'Sổ này chưa khôi phục được.', 'Khôi phục chỉ có ở sổ thu chi, công nợ, việc cần làm và sổ bạn tự tạo.', 403 );
			}
			$card   = (array) $e['card'];
			$frozen = self::thaw( (string) $w->load_frozen( $id, $owner ) );
			if ( empty( $card['restorable'] ) || null === $frozen ) {
				return BizCity_Twin_Sheet_Schema::error( 'checkpoint_not_restorable', 'Điểm lưu này không có bản chụp dòng để khôi phục.', 'Kỳ này nhiều dòng quá lúc lưu; chọn điểm lưu khác hoặc lưu theo từng tháng.', 409 );
			}
			$period = (string) ( $card['period'] ?? '' );
			if ( self::lock_overlaps( self::locked_periods( $sheet, $owner, $w ), $period ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'period_locked', 'Kỳ này đang khóa nên chưa khôi phục được.', 'Mở khóa kỳ trong “Chốt sổ”, khôi phục, rồi khóa lại.', 423 );
			}
			$now  = $scan( $sheet, (string) ( $card['from'] ?? '' ), (string) ( $card['to'] ?? '' ), $owner );
			if ( ! empty( $now['truncated'] ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'snapshot_too_big', 'Kỳ này nhiều dòng quá để khôi phục một lần.', 'Khôi phục theo từng tháng.', 413 );
			}
			$plan = self::plan_restore( $frozen, (array) $now['entries'] );
			$sum  = array( 'unvoid' => $plan['unvoid'], 'void' => $plan['void'], 'revert' => $plan['revert'], 'missing' => $plan['missing'] );
			if ( count( $plan['ops'] ) > self::RESTORE_MAX ) {
				return BizCity_Twin_Sheet_Schema::error( 'restore_too_big', 'Khôi phục này đổi quá nhiều dòng một lần.', 'Khôi phục theo từng tháng.', 413 );
			}
			if ( ! empty( $input['dry_run'] ) || array() === $plan['ops'] ) {
				return array( 'dry_run' => ! empty( $input['dry_run'] ), 'plan' => $sum, 'nothing_to_do' => array() === $plan['ops'], 'version' => (int) ( $card['version'] ?? 0 ), 'period' => $period );
			}
			// the way back: a checkpoint of NOW before touching anything
			$undo = self::create( $sheet, array( 'period' => $period, 'name' => 'Tự lưu trước khi khôi phục v' . (int) ( $card['version'] ?? 0 ), 'auto' => 1 ), $ctx, $scan, $w, $as_of );
			if ( is_wp_error( $undo ) ) {
				return $undo;
			}
			$applied = array( 'unvoid' => 0, 'void' => 0, 'revert' => 0 );
			$errors  = array();
			foreach ( $plan['ops'] as $op ) {
				$r = self::apply_op( $op, $owner, $ctx, $w, $card );
				if ( is_wp_error( $r ) ) {
					$errors[] = array( 'entry_id' => $op['id'], 'op' => $op['op'], 'code' => $r->get_error_code(), 'message' => $r->get_error_message() );
				} else {
					$applied[ $op['op'] ]++;
				}
			}
			return array( 'dry_run' => false, 'plan' => $sum, 'applied' => $applied, 'errors' => $errors, 'undo_entry_id' => (int) $undo['entry_id'], 'version' => (int) ( $card['version'] ?? 0 ), 'period' => $period );
		}

		/** @return true|WP_Error */
		private static function apply_op( array $op, int $owner, array $ctx, BizCity_Twin_Sheet_Writer $w, array $card ) {
			$id = (int) $op['id'];
			if ( 'void' === $op['op'] ) {
				$r = $w->void( $id, $owner );
				return is_wp_error( $r ) ? $r : true;
			}
			if ( 'unvoid' === $op['op'] ) {
				$r = $w->set_state( $id, $owner, (string) $op['state'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return true;
			}
			$cur = $w->get_entry( $id, $owner );
			if ( ! is_array( $cur ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_row_not_found', 'Không tìm thấy dòng.', 'Tải lại rồi thử lại.', 404 );
			}
			$data = array(
				'card'         => $op['card'],
				'history'      => array( 'at' => gmdate( 'c' ), 'by' => (string) ( $ctx['role'] ?? 'owner' ), 'via' => 'restore', 'changes' => array( 'restore' => array( 'v' . (int) ( $card['version'] ?? 0 ), '' ) ) ),
				'expected_rev' => (int) $cur['rev'],
			);
			if ( '' !== (string) $op['title'] ) {
				$data['title'] = (string) $op['title'];
			}
			if ( '' !== (string) $op['doc_date'] ) {
				$data['doc_date'] = (string) $op['doc_date'];
			}
			$r = $w->update_card( $id, $owner, $data );
			return is_wp_error( $r ) ? $r : true;
		}

		// ── the lock seen from the journal door (assistant writes) ────

		/** @var array<string,string[]> per request: "<owner>|<sheet_key>" ⇒ locked periods; cleared whenever a lock changes */
		private static $lock_memo = array();

		public static function forget_locks(): void {
			self::$lock_memo = array();
		}

		/** @var BizCity_Twin_Sheet_Writer|null test seam: the journal the guard reads locks from */
		private static $guard_writer = null;

		public static function use_writer( ?BizCity_Twin_Sheet_Writer $w ): void {
			self::$guard_writer = $w;
			self::$lock_memo    = array();
		}

		/**
		 * Filter `bizcity_journal_pre_add` / `bizcity_journal_pre_state`: a row of a sheet kind dated inside a locked period is refused —
		 * the same answer (423 period_locked) whoever writes: the web grid, the assistant, an automation.
		 *
		 * @param mixed  $pass  previous filter result
		 * @param int    $owner owner of the row
		 * @param string $kind  journal kind (= sheet key for the built-in and the custom sheets)
		 * @param string $date  doc_date of the row ('' = today, SITE time)
		 * @param string $idem  idempotency key of an add: when it already names a row, the retry is answered as before, never refused
		 * @return mixed WP_Error to refuse, else $pass
		 */
		public static function guard_journal( $pass, $owner, $kind, $date, $idem = '' ) {
			$owner = (int) $owner;
			$kind  = (string) $kind;
			if ( is_wp_error( $pass ) || $owner <= 0 || self::KIND === $kind || ! class_exists( 'BizCity_Twin_Sheet_Registry', false ) || ! class_exists( 'BizCity_Twin_Sheet_Journal_Writer', false ) ) {
				return $pass;
			}
			$mk = $owner . '|' . $kind;
			// [2026-10-21 07:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 — a retry of a row that was saved BEFORE the lock must return that row, not "period locked" (the assistant would say it failed)
			if ( '' !== (string) $idem ) {
				$w0 = self::$guard_writer ?? ( class_exists( 'BizCity_Twin_Sheet_Journal_Writer', false ) ? new BizCity_Twin_Sheet_Journal_Writer() : null );
				if ( $w0 && is_array( $w0->find_by_key( (string) $idem, $owner ) ) ) {
					return $pass;
				}
			}
			if ( ! isset( self::$lock_memo[ $mk ] ) ) {
				$sheet = BizCity_Twin_Sheet_Registry::get( $kind, $owner );
				self::$lock_memo[ $mk ] = is_wp_error( $sheet ) || empty( $sheet['editable'] ) ? array() : self::locked_periods( $sheet, $owner, self::$guard_writer ?? new BizCity_Twin_Sheet_Journal_Writer() );
			}
			if ( array() === self::$lock_memo[ $mk ] ) {
				return $pass;
			}
			// [2026-10-21 07:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 — the SITE's today (UTC+7 at 03:00 on the 1st is still the old month in UTC)
			$d = '' !== (string) $date ? (string) $date : ( class_exists( 'BizCity_Twin_Sheet_API', false ) ? BizCity_Twin_Sheet_API::today() : gmdate( 'Y-m-d' ) );
			return self::covers( self::$lock_memo[ $mk ], $d )
				? BizCity_Twin_Sheet_Schema::error( 'period_locked', 'Kỳ này đã chốt sổ và đang khóa.', 'Nhờ chủ sổ mở khóa kỳ trong “Chốt sổ” trên màn Sổ nếu cần sửa.', 423 )
				: $pass;
		}

		/** Filter `bizcity_journal_pre_state` (…, $state): same refusal, no idempotency key. */
		public static function guard_state( $pass, $owner, $kind, $date ) {
			return self::guard_journal( $pass, $owner, $kind, $date );
		}

		/** "So với hiện tại". @return array|WP_Error */
		public static function compare( array $sheet, int $id, int $owner, callable $scan, BizCity_Twin_Sheet_Writer $w ) {
			$e = $owner > 0 ? $w->get_entry( $id, $owner ) : null;
			if ( ! is_array( $e ) || self::KIND !== (string) ( $e['kind'] ?? '' ) || (string) ( $e['card']['sheet_key'] ?? '' ) !== (string) $sheet['sheet_key'] ) {
				return BizCity_Twin_Sheet_Schema::error( 'snapshot_not_found', 'Không tìm thấy bản chốt này.', 'Tải lại danh sách bản chốt rồi thử lại.', 404 );
			}
			$card = (array) $e['card'];
			$got  = $scan( $sheet, (string) ( $card['from'] ?? '' ), (string) ( $card['to'] ?? '' ), $owner );
			return array_merge( array( 'entry_id' => $id, 'period' => (string) ( $card['period'] ?? '' ), 'as_of' => (string) ( $card['as_of'] ?? '' ) ), self::diff( $card, (array) ( $got['entries'] ?? array() ), $sheet ) );
		}
	}
}
