<?php
/**
 * BizCity_Twin_Sheet_Service::apply_changes() — the ONE change door of Twin Sheet ("Sổ") (PHASE-0.123 W2, doc 20 §4 / §6, doc 32 §4).
 *
 * The grid saves its draft buffer here (≤ 100 changes per call); later the paste and import paths use the same door (R-JN-13).
 * Every change is validated against the sheet's typed columns, owner-scoped, and applied on its own — one bad change never blocks
 * the others, and every outcome comes back per change (applied · created · voided · conflicts · errors, each with the 4 error
 * fields). Rows are journal rows; nothing is written anywhere else (D123-1).
 *
 *   edit_policy `rev`          — update the card in place; `rev + 1`; `metadata.history[]` keeps the last 20 edits (R-JN-2: the
 *                                 original file is never touched; the previous card stays in `prev_card`).
 *   edit_policy `void_reenter` — money (R-JN-25): record the corrected row FIRST (`derived_from` = the old row), THEN void the old
 *                                 one; if voiding fails the new row is voided again, so a number is never counted twice.
 *   create                     — a change without entry_id; needs a client `change_id` ⇒ idempotency key `web:<blog>:<owner>:<id>`
 *                                 (the journal's UNIQUE key makes a retry return the same row, never a second one).
 *   void                       — `void:true` ⇒ state void (row kept).
 *
 * Optimistic concurrency: `expected_rev` is compared with the row's revision twice — before the work, and again inside the SQL
 * UPDATE (`WHERE revision = expected`, class-journal-database.php update()); whoever loses gets a `conflict`, never a lost edit.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 10:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W2 (B9/B10) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Twin_Sheet_Snapshot_Source', false ) ) {
	/** Optional second door of a Writer: the person's snapshot rows of one sheet. */
	interface BizCity_Twin_Sheet_Snapshot_Source {
		/** @return array[] journal-entry@1.1.0 of kind sheet.snapshot (OWN rows), newest first, card.sheet_key === $sheet_key */
		public function list_snapshots( int $owner, string $sheet_key ): array;
	}
}

if ( ! interface_exists( 'BizCity_Twin_Sheet_Checkpoint_Store', false ) ) {
	/** Optional third door of a Writer (PHASE-0.123 W3): keep the frozen rows of a checkpoint, and re-open a voided row. */
	interface BizCity_Twin_Sheet_Checkpoint_Store {
		/** @return bool stored */
		public function save_frozen( int $id, int $owner, string $blob ): bool;

		/** @return string '' when none */
		public function load_frozen( int $id, int $owner ): string;

		/** @return array|WP_Error journal-entry@1.1.0 with the new state */
		public function set_state( int $id, int $owner, string $state );
	}
}

if ( ! interface_exists( 'BizCity_Twin_Sheet_Writer', false ) ) {
	/** What the service needs from the journal; the default implementation is below, tests swap in a fake. */
	interface BizCity_Twin_Sheet_Writer {
		/** @return array|null journal-entry@1.1.0 of an OWN row (null = not found / not yours) */
		public function get_entry( int $id, int $owner );

		/** @return array|null journal-entry@1.1.0 by idempotency key (OWN rows only) */
		public function find_by_key( string $key, int $owner );

		/**
		 * Write a new card (+ columns) on an own row, append one history item, `rev + 1`.
		 *
		 * @param array $data {card:array, doc_date?:string, title?:string, history:array, expected_rev:int}
		 * @return array|WP_Error journal-entry@1.1.0
		 */
		public function update_card( int $id, int $owner, array $data );

		/** @return array|WP_Error journal-entry@1.1.0 */
		public function record( array $entry );

		/** @return array|WP_Error journal-entry@1.1.0 */
		public function void( int $id, int $owner );
	}
}

if ( ! class_exists( 'BizCity_Twin_Sheet_Journal_Writer', false ) ) {
	final class BizCity_Twin_Sheet_Journal_Writer implements BizCity_Twin_Sheet_Writer, BizCity_Twin_Sheet_Snapshot_Source, BizCity_Twin_Sheet_Checkpoint_Store {

		const HISTORY_MAX = 20;

		public function get_entry( int $id, int $owner ) {
			return class_exists( 'BizCity_Journal_Index' ) && $id > 0 && $owner > 0 ? BizCity_Journal_Index::get( $id, $owner ) : null;
		}

		public function find_by_key( string $key, int $owner ) {
			return class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::get_by_idempotency_key( $key, $owner ) : null;
		}

		public function update_card( int $id, int $owner, array $data ) {
			$db  = BizCity_Journal_Database::instance();
			$row = $db->get( $id, $owner );
			if ( ! $row ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_row_not_found', 'Không tìm thấy dòng này.', 'Tải lại sổ rồi thử lại.', 404 );
			}
			$meta = is_array( json_decode( (string) ( $row['metadata'] ?? '' ), true ) ) ? json_decode( (string) $row['metadata'], true ) : array();
			$meta['prev_card'] = is_array( $meta['card'] ?? null ) ? $meta['card'] : array();
			$meta['card']      = $data['card'];
			$hist = is_array( $meta['history'] ?? null ) ? $meta['history'] : array();
			$hist[] = $data['history'];
			$meta['history'] = array_slice( $hist, -self::HISTORY_MAX );
			if ( 'ok' === (string) ( $meta['mirror']['sheet'] ?? '' ) ) {
				$meta['mirror']['sheet'] = 'pending'; // the Google copy is stale now (R-JN-15: the journal stays the truth)
			}
			$write = array( 'metadata' => $meta, 'expected_revision' => (int) $data['expected_rev'] );
			if ( isset( $data['title'] ) && '' !== $data['title'] ) {
				$write['title'] = (string) $data['title'];
			}
			if ( isset( $data['doc_date'] ) && '' !== $data['doc_date'] ) {
				$write['doc_date'] = (string) $data['doc_date'];
			}
			$updated = $db->update( $id, $owner, $write );
			if ( is_wp_error( $updated ) ) {
				return 'journal_revision_conflict' === $updated->get_error_code()
					? BizCity_Twin_Sheet_Schema::error( 'version_conflict', 'Dòng này vừa được sửa ở nơi khác.', 'Tải lại để lấy bản mới nhất rồi sửa lại.', 409 )
					: BizCity_Twin_Sheet_Schema::error( 'save_failed', 'Chưa lưu được dòng này.', 'Thử lại sau ít phút.', 500 );
			}
			if ( class_exists( 'BizCity_Journal_Doc_Index_Pack_Exporter' ) ) {
				BizCity_Journal_Doc_Index_Pack_Exporter::flush( $owner );
			}
			return BizCity_Journal_Index::to_journal_entry( $updated );
		}

		// [2026-10-20 01:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — the person's "Chốt sổ" rows of one sheet (newest first)
		public function list_snapshots( int $owner, string $sheet_key ): array {
			if ( $owner <= 0 || ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
				return array();
			}
			$db  = BizCity_Journal_Database::instance();
			$ids = $db->slice_ids( $owner, BizCity_Twin_Sheet_Snapshot::KIND, '', '', 500 );
			$out = array();
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				foreach ( $db->rows_by_ids( $chunk, $owner ) as $row ) {
					$e = BizCity_Journal_Index::to_journal_entry( $row );
					if ( (string) ( $e['card']['sheet_key'] ?? '' ) === $sheet_key ) {
						$out[] = $e;
					}
				}
			}
			usort( $out, static function ( $a, $b ) {
				return (int) $b['entry_id'] <=> (int) $a['entry_id'];
			} );
			return $out;
		}

		// [2026-10-20 03:35 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — checkpoints: the frozen rows live in the snapshot row's metadata.frozen (gzip+base64), never in the card
		// [2026-10-21 07:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 — the frozen rows live in a NON-autoloaded option per checkpoint, not in the journal row's metadata:
		// the journal listing selects whole rows, and 20 checkpoints × up to 400 KB would be read on every save and every journal write. The owner is checked on the journal row first.
		private static function frozen_option( int $id ): string {
			return 'twinsheet_frozen_' . $id;
		}

		public function save_frozen( int $id, int $owner, string $blob ): bool {
			if ( '' === $blob || ! BizCity_Journal_Database::instance()->get( $id, $owner ) ) {
				return false;
			}
			return (bool) update_option( self::frozen_option( $id ), $blob, false ) || (string) get_option( self::frozen_option( $id ), '' ) === $blob;
		}

		public function load_frozen( int $id, int $owner ): string {
			if ( ! BizCity_Journal_Database::instance()->get( $id, $owner ) ) {
				return '';
			}
			$blob = get_option( self::frozen_option( $id ), '' );
			return is_string( $blob ) ? $blob : '';
		}

		public function set_state( int $id, int $owner, string $state ) {
			return BizCity_Journal_Index::set_state( $id, $owner, $state, array(), $owner );
		}

		public function record( array $entry ) {
			return function_exists( 'bizcity_journal_record' ) ? bizcity_journal_record( $entry ) : BizCity_Twin_Sheet_Schema::error( 'journal_unavailable', 'Sổ ghi chép chưa sẵn sàng trên website này.', 'Cập nhật website rồi thử lại.', 503 );
		}

		public function void( int $id, int $owner ) {
			return BizCity_Journal_Index::set_state( $id, $owner, 'void' );
		}
	}
}

if ( ! class_exists( 'BizCity_Twin_Sheet_Service', false ) ) {
	final class BizCity_Twin_Sheet_Service {

		const MAX_CHANGES = 100;

		/**
		 * @param array                          $sheet normalized twin-sheet@1
		 * @param array                          $body  {changes: array[]}
		 * @param array                          $ctx   {owner_user_id, role}
		 * @param BizCity_Twin_Sheet_Writer|null $w     test seam
		 * @return array|WP_Error {applied[], created[], voided[], conflicts[], errors[], skipped[]}
		 */
		public static function apply_changes( array $sheet, array $body, array $ctx, ?BizCity_Twin_Sheet_Writer $w = null, ?string $today = null ) {
			$owner = (int) ( $ctx['owner_user_id'] ?? 0 );
			if ( $owner <= 0 ) {
				return BizCity_Twin_Sheet_Schema::error( 'rest_forbidden', 'Bạn chưa đăng nhập.', 'Đăng nhập rồi thử lại.', 401 );
			}
			if ( empty( $sheet['editable'] ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_read_only', 'Sổ này chỉ để xem.', 'Sổ có sẵn của trợ lý (thu chi, công nợ, việc…) mới sửa được trên bảng.', 403 );
			}
			$changes = array_values( (array) ( $body['changes'] ?? array() ) );
			if ( array() === $changes ) {
				return BizCity_Twin_Sheet_Schema::error( 'changes_empty', 'Chưa có thay đổi nào để lưu.', 'Sửa ít nhất một ô rồi bấm Lưu.', 400 );
			}
			if ( count( $changes ) > self::MAX_CHANGES ) {
				return BizCity_Twin_Sheet_Schema::error( 'changes_too_many', 'Một lần lưu tối đa ' . self::MAX_CHANGES . ' thay đổi.', 'Lưu bớt rồi sửa tiếp.', 413 );
			}
			$w     = $w ?? new BizCity_Twin_Sheet_Journal_Writer();
			$today = $today ?? BizCity_Twin_Sheet_API::today();
			// [2026-10-20 01:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — periods closed with "Khóa kỳ": read once per save
			if ( class_exists( 'BizCity_Twin_Sheet_Snapshot', false ) && $w instanceof BizCity_Twin_Sheet_Snapshot_Source ) {
				$ctx['_locks'] = BizCity_Twin_Sheet_Snapshot::locked_periods( $sheet, $owner, $w );
			}
			$out   = array( 'applied' => array(), 'created' => array(), 'voided' => array(), 'conflicts' => array(), 'skipped' => array(), 'errors' => array() );
			foreach ( $changes as $i => $change ) {
				$r = is_array( $change ) ? self::apply_one( $sheet, $change, $ctx, $owner, $w, $today ) : self::fail( 'change_invalid', 'Thay đổi không đúng dạng.', 'Tải lại trang rồi sửa lại.', 400 );
				$r['index'] = $i;
				$bucket = (string) ( $r['bucket'] ?? 'errors' );
				unset( $r['bucket'] );
				$out[ $bucket ][] = $r;
			}
			return $out;
		}

		// ── one change ────────────────────────────────────────────────

		private static function apply_one( array $sheet, array $c, array $ctx, int $owner, BizCity_Twin_Sheet_Writer $w, string $today ): array {
			$change_id = (string) ( $c['change_id'] ?? '' );
			if ( '' !== $change_id && 1 !== preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $change_id ) ) {
				return self::fail( 'change_invalid', 'Mã thay đổi không hợp lệ.', 'Tải lại trang rồi sửa lại.', 400 );
			}
			$id = (int) ( $c['entry_id'] ?? 0 );
			// [2026-10-20 01:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — a row inside a locked period is read-only, and nothing moves into one
			// [2026-10-21 07:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 — a retry of a create that already made its row (same change_id) is answered as before, even if the period has been locked since
			$already = $id <= 0 && '' !== $change_id && is_array( $w->find_by_key( self::key( $owner, $change_id ), $owner ) );
			if ( ! empty( $ctx['_locks'] ) && ! $already ) {
				$dates = array();
				if ( $id > 0 && is_array( $old = $w->get_entry( $id, $owner ) ) ) {
					$dates[] = (string) ( $old['doc_date'] ?? '' );
				}
				$new_date = (string) ( ( (array) ( $c['values'] ?? array() ) )['doc_date'] ?? '' );
				if ( '' !== $new_date ) {
					$dates[] = $new_date;
				} elseif ( $id <= 0 ) {
					$dates[] = $today;
				}
				foreach ( $dates as $d ) {
					if ( BizCity_Twin_Sheet_Snapshot::covers( (array) $ctx['_locks'], $d ) ) {
						return self::fail( 'period_locked', 'Kỳ này đã chốt sổ và đang khóa.', 'Mở khóa kỳ trong “Chốt sổ” nếu cần sửa, rồi khóa lại sau.', 423 );
					}
				}
			}
			if ( ! empty( $c['void'] ) ) {
				return $id > 0 ? self::do_void( $sheet, $id, $owner, $w ) : self::fail( 'change_invalid', 'Thiếu dòng cần hủy.', 'Chọn một dòng rồi thử lại.', 400 );
			}
			$values = self::editable_values( $sheet, (array) ( $c['values'] ?? array() ) );
			if ( is_wp_error( $values ) ) {
				return self::from_error( $values );
			}
			if ( $id <= 0 ) {
				return self::do_create( $sheet, $values, $change_id, $ctx, $owner, $w, $today );
			}
			return 'void_reenter' === $sheet['edit_policy']
				? self::do_void_reenter( $sheet, $id, $values, (int) ( $c['expected_rev'] ?? 0 ), $change_id, $ctx, $owner, $w )
				: self::do_rev( $sheet, $id, $values, (int) ( $c['expected_rev'] ?? 0 ), $ctx, $owner, $w );
		}

		/**
		 * Typed + allow-listed values: only editable columns of THIS sheet, each through validate_value; unknown / hidden / computed ⇒ error.
		 *
		 * @return array|WP_Error {key => value}
		 */
		public static function editable_values( array $sheet, array $raw ) {
			$out = array();
			foreach ( $raw as $key => $v ) {
				$f = BizCity_Twin_Sheet_Schema::field( $sheet, (string) $key );
				if ( ! $f ) {
					return BizCity_Twin_Sheet_Schema::error( 'field_unknown', 'Cột "' . BizCity_Twin_Sheet_Schema::clean_text( (string) $key, 40 ) . '" không có trong sổ.', 'Tải lại sổ rồi sửa lại.', 400 );
				}
				if ( empty( $f['editable'] ) ) {
					return BizCity_Twin_Sheet_Schema::error( 'field_readonly', 'Cột "' . $f['label'] . '" không sửa được.', 'Cột này do trợ lý quản lý.', 400 );
				}
				$chk = BizCity_Twin_Sheet_Schema::validate_value( $f, $v );
				if ( ! $chk['ok'] ) {
					return self::field_error( $f, $chk['code'] );
				}
				$out[ $f['key'] ] = 'boolean' === $f['type'] ? (int) $chk['value'] : $chk['value']; // the cell writes done as 0 / 1
			}
			return $out;
		}

		private static function field_error( array $f, string $code ): WP_Error {
			$msg = array(
				'field_type_invalid' => 'Giá trị ở cột "' . $f['label'] . '" không đúng dạng.',
				'field_required'     => 'Cột "' . $f['label'] . '" không được để trống.',
				'field_too_long'     => 'Cột "' . $f['label'] . '" quá dài.',
				'field_out_of_range' => 'Giá trị ở cột "' . $f['label'] . '" vượt giới hạn cho phép.',
			);
			$hint = array(
				'field_type_invalid' => 'Nhập đúng dạng (số tiền là số nguyên, ngày dạng 19/10/2026, chọn trong danh sách).',
				'field_required'     => 'Nhập giá trị cho cột này.',
				'field_too_long'     => 'Rút gọn nội dung.',
				'field_out_of_range' => 'Nhập giá trị nhỏ hơn.',
			);
			return BizCity_Twin_Sheet_Schema::error( $code, $msg[ $code ] ?? 'Giá trị không hợp lệ.', $hint[ $code ] ?? 'Sửa lại giá trị.', 400 );
		}

		/** Split values into card fields and the two editable index columns (doc_date, title). */
		private static function split( array $sheet, array $values ): array {
			$card = array();
			$cols = array();
			foreach ( $values as $k => $v ) {
				$f = BizCity_Twin_Sheet_Schema::field( $sheet, $k );
				if ( 'column' === $f['source'] ) {
					$cols[ $k ] = $v;
				} else {
					$card[ $k ] = $v;
				}
			}
			return array( $card, $cols );
		}

		private static function loadable( array $sheet, int $id, int $owner, BizCity_Twin_Sheet_Writer $w, ?array &$entry ): ?array {
			$entry = $w->get_entry( $id, $owner );
			if ( ! is_array( $entry ) || (string) ( $entry['kind'] ?? '' ) !== (string) $sheet['kind'] ) {
				return self::fail( 'sheet_row_not_found', 'Không tìm thấy dòng này.', 'Tải lại sổ rồi thử lại.', 404 );
			}
			if ( 'void' === (string) ( $entry['state'] ?? '' ) ) {
				return self::fail( 'sheet_row_void', 'Dòng này đã bị hủy nên không sửa được.', 'Thêm một dòng mới thay cho nó.', 409 );
			}
			return null;
		}

		private static function conflict( array $sheet, array $entry, int $expected ): array {
			return array(
				'bucket'       => 'conflicts',
				'code'         => 'version_conflict',
				'message'      => 'Dòng này vừa được sửa ở nơi khác.',
				'hint'         => 'Tải lại để lấy bản mới nhất rồi sửa lại.',
				'help_code'    => 'S123-TS-version_conflict',
				'entry_id'     => (int) $entry['entry_id'],
				'expected_rev' => $expected,
				'current'      => BizCity_Twin_Sheet_Engine::project( $entry, $sheet ),
			);
		}

		private static function history_item( array $before, array $after, array $ctx, string $via = 'web' ): array {
			$changes = array();
			foreach ( $after as $k => $v ) {
				$old = $before[ $k ] ?? null;
				if ( (string) $old !== (string) $v ) {
					$changes[ $k ] = array( $old, $v );
				}
			}
			return array( 'at' => gmdate( 'c' ), 'by' => (string) ( $ctx['role'] ?? 'owner' ), 'via' => $via, 'changes' => $changes );
		}

		/** card fields that really differ (string compare: 0 vs "0" is not a change) */
		private static function diff( array $card_now, array $card_new ): array {
			$d = array();
			foreach ( $card_new as $k => $v ) {
				if ( (string) ( $card_now[ $k ] ?? '' ) !== (string) $v ) {
					$d[ $k ] = $v;
				}
			}
			return $d;
		}

		// ── rev ───────────────────────────────────────────────────────

		private static function do_rev( array $sheet, int $id, array $values, int $expected, array $ctx, int $owner, BizCity_Twin_Sheet_Writer $w ): array {
			$entry = null;
			if ( null !== ( $err = self::loadable( $sheet, $id, $owner, $w, $entry ) ) ) {
				return $err;
			}
			if ( $expected > 0 && $expected !== (int) $entry['rev'] ) {
				return self::conflict( $sheet, $entry, $expected );
			}
			list( $card_new, $cols ) = self::split( $sheet, $values );
			$card_now = is_array( $entry['card'] ?? null ) ? $entry['card'] : array();
			$delta    = self::diff( $card_now, $card_new );
			$col_diff = array();
			foreach ( $cols as $k => $v ) {
				if ( (string) ( $entry[ $k ] ?? '' ) !== (string) $v ) {
					$col_diff[ $k ] = $v;
				}
			}
			if ( array() === $delta && array() === $col_diff ) {
				return array( 'bucket' => 'skipped', 'entry_id' => $id, 'reason' => 'unchanged' );
			}
			$merged = array_merge( $card_now, $delta );
			$write  = array(
				'card'         => $merged,
				'history'      => self::history_item( array_merge( $card_now, array_intersect_key( $entry, $col_diff ) ), array_merge( $delta, $col_diff ), $ctx ),
				'expected_rev' => (int) $entry['rev'],
			);
			if ( isset( $col_diff['doc_date'] ) ) {
				$write['doc_date'] = $col_diff['doc_date'];
			}
			$title = self::title_for( $sheet, $merged, $col_diff['title'] ?? (string) $entry['title'], (string) ( $col_diff['doc_date'] ?? $entry['doc_date'] ?? '' ) );
			if ( $title !== (string) $entry['title'] ) {
				$write['title'] = $title;
			}
			$res = $w->update_card( $id, $owner, $write );
			if ( is_wp_error( $res ) ) {
				return 'version_conflict' === $res->get_error_code() ? self::conflict( $sheet, (array) $w->get_entry( $id, $owner ) + $entry, (int) $entry['rev'] ) : self::from_error( $res );
			}
			return array( 'bucket' => 'applied', 'entry_id' => (int) $res['entry_id'], 'ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $res ), 'rev' => (int) $res['rev'] );
		}

		// ── void + re-enter (money) ───────────────────────────────────

		private static function do_void_reenter( array $sheet, int $id, array $values, int $expected, string $change_id, array $ctx, int $owner, BizCity_Twin_Sheet_Writer $w ): array {
			if ( '' === $change_id ) {
				return self::fail( 'change_invalid', 'Thiếu mã thay đổi.', 'Tải lại trang rồi sửa lại.', 400 );
			}
			$key = self::key( $owner, $change_id );
			$old = $w->get_entry( $id, $owner );
			if ( is_array( $old ) && 'void' === (string) ( $old['state'] ?? '' ) ) {
				$had = $w->find_by_key( $key, $owner ); // a retry of a change that already went through
				if ( is_array( $had ) ) {
					return array( 'bucket' => 'applied', 'entry_id' => $id, 'new_entry_id' => (int) $had['entry_id'], 'ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $had ), 'voided_ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $old ), 'already' => true );
				}
			}
			$entry = null;
			if ( null !== ( $err = self::loadable( $sheet, $id, $owner, $w, $entry ) ) ) {
				return $err;
			}
			if ( $expected > 0 && $expected !== (int) $entry['rev'] ) {
				return self::conflict( $sheet, $entry, $expected );
			}
			list( $card_new, $cols ) = self::split( $sheet, $values );
			$card_now = is_array( $entry['card'] ?? null ) ? $entry['card'] : array();
			$delta    = self::diff( $card_now, $card_new );
			$col_diff = array();
			foreach ( $cols as $k => $v ) {
				if ( (string) ( $entry[ $k ] ?? '' ) !== (string) $v ) {
					$col_diff[ $k ] = $v;
				}
			}
			if ( array() === $delta && array() === $col_diff ) {
				return array( 'bucket' => 'skipped', 'entry_id' => $id, 'reason' => 'unchanged' );
			}
			$card = array_merge( $card_now, $delta );
			unset( $card['reverses'] ); // the new row is a fresh entry, not a reversal pair (the old row is void, so it never counts)
			$date = (string) ( $col_diff['doc_date'] ?? $entry['doc_date'] ?? '' );
			$new  = $w->record( array(
				'owner_user_id'   => $owner,
				'kind'            => $sheet['kind'],
				'title'           => self::title_for( $sheet, $card, '', $date ),
				'doc_date'        => $date,
				'card'            => $card,
				'tags'            => (array) ( $entry['tags'] ?? array() ),
				'derived_from'    => array( $id ),
				'state'           => 'captured',
				'source_type'     => 'menu',
				'source_ref'      => (string) ( $entry['source_ref'] ?? '' ),
				'idempotency_key' => $key,
				'origin'          => array( 'surface' => 'web', 'ref' => 'sheet:' . $sheet['sheet_key'] ),
				'by'              => array( 'role' => (string) ( $ctx['role'] ?? 'owner' ) ),
			) );
			if ( is_wp_error( $new ) ) {
				return self::from_error( $new );
			}
			$voided = $w->void( $id, $owner );
			if ( is_wp_error( $voided ) ) {
				$w->void( (int) $new['entry_id'], $owner ); // never leave both counted
				return self::fail( 'save_failed', 'Chưa lưu được thay đổi này.', 'Dòng cũ vẫn giữ nguyên. Thử lại sau ít phút.', 500 );
			}
			return array(
				'bucket'          => 'applied',
				'entry_id'        => $id,
				'new_entry_id'    => (int) $new['entry_id'],
				'ref_code'        => BizCity_Twin_Sheet_Engine::ref_code( $new ),
				'voided_ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $entry ),
			);
		}

		// ── create / void ─────────────────────────────────────────────

		private static function do_create( array $sheet, array $values, string $change_id, array $ctx, int $owner, BizCity_Twin_Sheet_Writer $w, string $today ): array {
			if ( '' === $change_id ) {
				return self::fail( 'change_invalid', 'Thiếu mã thay đổi.', 'Tải lại trang rồi thử lại.', 400 );
			}
			list( $card, $cols ) = self::split( $sheet, $values );
			$card = array_merge( (array) ( $sheet['create_defaults'] ?? array() ), $card );
			foreach ( (array) $sheet['fields'] as $f ) {
				$present = 'column' === $f['source'] ? isset( $cols[ $f['key'] ] ) : array_key_exists( $f['key'], $card );
				if ( ! empty( $f['required'] ) && 'doc_date' !== $f['key'] && ( ! $present || '' === (string) ( 'column' === $f['source'] ? $cols[ $f['key'] ] : $card[ $f['key'] ] ) ) ) {
					return self::from_error( self::field_error( $f, 'field_required' ) );
				}
			}
			$date = (string) ( $cols['doc_date'] ?? $today );
			$key  = self::key( $owner, $change_id );
			$had  = $w->find_by_key( $key, $owner );
			$src  = '';
			if ( is_array( $sheet['latest_by'] ?? null ) ) {
				$src = 'web_' . substr( hash( 'sha256', $key ), 0, 16 ); // one record key per task (versions share it)
			}
			$row = $w->record( array(
				'owner_user_id'   => $owner,
				'kind'            => $sheet['kind'],
				'title'           => self::title_for( $sheet, $card, (string) ( $cols['title'] ?? '' ), $date ),
				'doc_date'        => $date,
				'card'            => $card,
				'state'           => 'captured',
				'source_type'     => 'menu',
				'source_ref'      => $src,
				'idempotency_key' => $key,
				'origin'          => array( 'surface' => 'web', 'ref' => 'sheet:' . $sheet['sheet_key'] ),
				'by'              => array( 'role' => (string) ( $ctx['role'] ?? 'owner' ) ),
			) );
			if ( is_wp_error( $row ) ) {
				return self::from_error( $row );
			}
			return array( 'bucket' => 'created', 'entry_id' => (int) $row['entry_id'], 'ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $row ), 'rev' => (int) $row['rev'], 'already' => is_array( $had ) );
		}

		private static function do_void( array $sheet, int $id, int $owner, BizCity_Twin_Sheet_Writer $w ): array {
			$entry = $w->get_entry( $id, $owner );
			if ( ! is_array( $entry ) || (string) ( $entry['kind'] ?? '' ) !== (string) $sheet['kind'] ) {
				return self::fail( 'sheet_row_not_found', 'Không tìm thấy dòng này.', 'Tải lại sổ rồi thử lại.', 404 );
			}
			if ( 'void' === (string) ( $entry['state'] ?? '' ) ) {
				return array( 'bucket' => 'voided', 'entry_id' => $id, 'ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $entry ), 'already' => true );
			}
			$res = $w->void( $id, $owner );
			return is_wp_error( $res ) ? self::from_error( $res ) : array( 'bucket' => 'voided', 'entry_id' => $id, 'ref_code' => BizCity_Twin_Sheet_Engine::ref_code( $entry ), 'already' => false );
		}

		// ── the person's own sheets (W3, B11) ─────────────────────────

		/** Same columns (key:type, in order) = the same sheet. */
		private static function signature( array $def ): string {
			$parts = array();
			foreach ( (array) ( $def['fields'] ?? array() ) as $f ) {
				if ( is_array( $f ) && ! in_array( (string) ( $f['key'] ?? '' ), array( 'doc_date', 'ref_code' ), true ) ) {
					$parts[] = (string) $f['key'] . ':' . (string) ( $f['type'] ?? '' );
				}
			}
			return implode( '|', $parts );
		}

		/**
		 * Make a sheet. Asking twice for the same name and the same columns returns the first one (`already`), never a copy.
		 * The new sheet is a journal kind (`data.s<ID>`) from this moment on, so the one write door accepts its rows.
		 *
		 * @param array $input {display_name, fields[{label, type, options?, required?}], icon?, folder?}
		 * @return array|WP_Error twin-sheet@1 (+ `already`)
		 */
		public static function create_sheet( int $owner, array $input, ?BizCity_Twin_Sheet_Defs $defs = null ) {
			if ( $owner <= 0 ) {
				return BizCity_Twin_Sheet_Schema::error( 'rest_forbidden', 'Bạn chưa đăng nhập.', 'Đăng nhập rồi thử lại.', 401 );
			}
			$defs = $defs ?? BizCity_Twin_Sheet_Registry::defs();
			$def  = BizCity_Twin_Sheet_Builder::build( $input );
			if ( is_wp_error( $def ) ) {
				return $def;
			}
			foreach ( $defs->all() as $rec ) {
				if ( (int) $rec['owner'] === $owner && 0 === strcasecmp( (string) $rec['title'], (string) $def['display_name'] ) && self::signature( (array) $rec['def'] ) === self::signature( $def ) ) {
					$sheet = BizCity_Twin_Sheet_Registry::normalize_record( $rec );
					return is_wp_error( $sheet ) ? $sheet : $sheet + array( 'already' => true );
				}
			}
			if ( $defs->count_for_owner( $owner ) >= BizCity_Twin_Sheet_Builder::MAX_SHEETS ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_limit_reached', 'Bạn đã có ' . BizCity_Twin_Sheet_Builder::MAX_SHEETS . ' sổ tự tạo.', 'Lưu trữ bớt sổ cũ rồi tạo sổ mới.', 409 );
			}
			$id = $defs->insert( $owner, array( 'title' => $def['display_name'], 'icon' => $def['icon'], 'folder' => $def['folder'], 'def' => $def ) );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( class_exists( 'BizCity_Journal_Kinds' ) ) {
				BizCity_Journal_Kinds::reset(); // the new kind is declared from now on
			}
			$sheet = BizCity_Twin_Sheet_Registry::normalize_record( array( 'id' => (int) $id, 'key' => 'data.s' . (int) $id, 'owner' => $owner, 'title' => $def['display_name'], 'icon' => $def['icon'], 'folder' => $def['folder'], 'status' => 'active', 'def' => $def ) );
			return is_wp_error( $sheet ) ? $sheet : $sheet + array( 'already' => false );
		}

		/**
		 * Rename, change icon / folder, or archive (`archived: true` ⇒ hidden from the lists; the rows stay in the journal). Restoring an archived
		 * sheet is not offered yet: the active list is all this reads.
		 * Only the sheets the person made; the built-in books keep their names.
		 *
		 * @return array|WP_Error {sheet_key, display_name, icon, folder, archived}
		 */
		public static function update_sheet( int $owner, string $key, array $patch, ?BizCity_Twin_Sheet_Defs $defs = null ) {
			if ( $owner <= 0 ) {
				return BizCity_Twin_Sheet_Schema::error( 'rest_forbidden', 'Bạn chưa đăng nhập.', 'Đăng nhập rồi thử lại.', 401 );
			}
			if ( ! BizCity_Twin_Sheet_Registry::is_custom_key( $key ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_not_custom', 'Chỉ đổi được sổ bạn tự tạo.', 'Các sổ có sẵn (thu chi, công nợ…) giữ nguyên tên.', 400 );
			}
			$defs = $defs ?? BizCity_Twin_Sheet_Registry::defs();
			$rec  = $defs->get_by_key( $key );
			if ( ! $rec || (int) $rec['owner'] !== $owner ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_not_found', 'Không tìm thấy sổ này.', 'Mở danh sách sổ và chọn lại.', 404 );
			}
			$write = array();
			if ( array_key_exists( 'display_name', $patch ) ) {
				$name = BizCity_Twin_Sheet_Schema::clean_text( (string) $patch['display_name'], 80 );
				if ( '' === $name ) {
					return BizCity_Twin_Sheet_Schema::error( 'sheet_invalid', 'Sổ cần có tên.', 'Nhập tên mới cho sổ.', 422 );
				}
				$write['title'] = $name;
			}
			if ( array_key_exists( 'icon', $patch ) && in_array( (string) $patch['icon'], BizCity_Twin_Sheet_Builder::ICONS, true ) ) {
				$write['icon'] = (string) $patch['icon'];
			}
			if ( array_key_exists( 'folder', $patch ) ) {
				$write['folder'] = BizCity_Twin_Sheet_Schema::clean_text( (string) $patch['folder'], 60 );
			}
			if ( array_key_exists( 'archived', $patch ) ) {
				$write['status'] = ! empty( $patch['archived'] ) ? 'trash' : 'active';
			}
			if ( array() === $write ) {
				return BizCity_Twin_Sheet_Schema::error( 'changes_empty', 'Chưa có gì để đổi.', 'Nhập tên, biểu tượng hoặc thư mục mới.', 400 );
			}
			$r = $defs->update( (int) $rec['id'], $write );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( class_exists( 'BizCity_Journal_Kinds' ) ) {
				BizCity_Journal_Kinds::reset();
			}
			return array(
				'sheet_key'    => $key,
				'display_name' => $write['title'] ?? (string) $rec['title'],
				'icon'         => $write['icon'] ?? (string) $rec['icon'],
				'folder'       => $write['folder'] ?? (string) $rec['folder'],
				'archived'     => isset( $write['status'] ) ? 'trash' === $write['status'] : false,
			);
		}

		// ── titles / keys / errors ────────────────────────────────────

		/** Idempotency key of a web change: one per (blog, owner, change_id), ≤ 191 chars. */
		public static function key( int $owner, string $change_id ): string {
			return 'web:' . (int) get_current_blog_id() . ':' . $owner . ':' . $change_id;
		}

		/**
		 * The row title the journal lists and the assistant reads back: "Chi 250.000 ₫ — văn phòng phẩm", "☐ Gửi báo giá", or the typed title.
		 * `$typed` wins when the sheet has a title column the person edited.
		 */
		public static function title_for( array $sheet, array $card, string $typed, string $date ): string {
			$rule = (string) ( $sheet['title_rule'] ?? '' );
			if ( 'card:' === substr( $rule, 0, 5 ) ) {
				$t = (string) ( $card[ substr( $rule, 5 ) ] ?? '' );
				return BizCity_Twin_Sheet_Schema::clean_text( '' !== $typed ? $typed : ( '' !== $t ? $t : $sheet['display_name'] ), 255 );
			}
			if ( 'todo' === $rule ) {
				$done = true === BizCity_Twin_Sheet_Schema::to_bool( $card['done'] ?? 0 );
				return BizCity_Twin_Sheet_Schema::clean_text( ( $done ? '☑ ' : '☐ ' ) . (string) ( $card['title'] ?? '' ), 255 );
			}
			$kind = '';
			foreach ( array( 'type', 'metric', 'direction' ) as $k ) {
				$f = BizCity_Twin_Sheet_Schema::field( $sheet, $k );
				if ( $f && 'select' === $f['type'] && isset( $card[ $k ] ) ) {
					foreach ( (array) $f['options'] as $o ) {
						if ( (string) $card[ $k ] === $o['v'] ) {
							$kind = $o['label'];
							break 2;
						}
					}
				}
			}
			$money = '';
			foreach ( array( 'amount', 'target', 'total' ) as $k ) {
				$n = BizCity_Twin_Sheet_Schema::money_to_int( $card[ $k ] ?? null );
				if ( null !== $n ) {
					$money = number_format( $n, 0, ',', '.' ) . ' ₫';
					break;
				}
			}
			$who = '';
			foreach ( array( 'counterparty', 'party', 'name', 'subject', 'category' ) as $k ) {
				if ( '' !== trim( (string) ( $card[ $k ] ?? '' ) ) ) {
					$who = trim( (string) $card[ $k ] );
					break;
				}
			}
			$t = trim( $kind . ( '' !== $money ? ' ' . $money : '' ) . ( '' !== $who ? ' — ' . $who : '' ) );
			return BizCity_Twin_Sheet_Schema::clean_text( '' !== $t ? $t : $sheet['display_name'] . ' ' . $date, 255 );
		}

		private static function fail( string $code, string $message, string $hint, int $status ): array {
			return self::from_error( BizCity_Twin_Sheet_Schema::error( $code, $message, $hint, $status ) );
		}

		private static function from_error( WP_Error $e ): array {
			$d = $e->get_error_data();
			return array(
				'bucket'    => 'errors',
				'code'      => $e->get_error_code(),
				'message'   => $e->get_error_message(),
				'hint'      => is_array( $d ) ? (string) ( $d['hint'] ?? '' ) : '',
				'help_code' => is_array( $d ) && ! empty( $d['help_code'] ) ? (string) $d['help_code'] : 'S123-TS-' . $e->get_error_code(),
			);
		}
	}
}
