<?php
/**
 * Twin Sheet — STRUCTURE versions of a sheet the person made (PHASE-0.123 W3, A13, Q123-13; R-FILE-VERSION for the sheet itself).
 *
 * What may change in a sheet's columns (all-or-nothing per request, ≤ 20 operations):
 *   add_field     a new column (any type of the builder; a money column only into a sheet that already is a money book)
 *   rename_field  the label only — the key never changes, so rows and links keep working
 *   hide_field / show_field   a hidden column keeps its data and is not editable; a required column cannot be hidden
 *   add_options   more choices for a "chọn một" column (never fewer: rows may use them)
 *   move_field    order of the columns
 * Never: delete a column, change its type, remove an option. "Delete" is "hide": nothing a person typed is ever lost.
 *
 * Every applied request is ONE structure version (`schema_version` + 1) with a note computed by code (FV-6). The versions
 * live in the post meta `_twin_versions` (compact snapshots, newest 20) — not in `post_content`, so reading a sheet stays light —
 * and WordPress revisions of the post stay on as a second net. Restoring version N makes a NEW version (numbers never go back):
 * labels, hidden flags and order return to N; a column added after N stays, hidden; a required column stays visible.
 *
 * Pure functions here; the use cases (edit / versions / restore_version) reach the posts through BizCity_Twin_Sheet_Defs and the
 * optional BizCity_Twin_Sheet_Version_Store, so tests swap in fakes.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-20 06:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 (A13) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Sheet_Structure', false ) ) {
	final class BizCity_Twin_Sheet_Structure {

		const MAX_OPS      = 20;
		const KEEP         = 20;
		const SYSTEM_KEYS  = array( 'doc_date', 'ref_code' );

		// ── pure: snapshots and diffs ─────────────────────────────────

		/** Compact picture of the columns a person can see or hide (no doc_date / ref_code). */
		public static function snapshot( array $def ): array {
			$out = array();
			foreach ( (array) ( $def['fields'] ?? array() ) as $f ) {
				if ( ! is_array( $f ) || in_array( (string) ( $f['key'] ?? '' ), self::SYSTEM_KEYS, true ) ) {
					continue;
				}
				$one = array( 'k' => (string) $f['key'], 'l' => (string) ( $f['label'] ?? '' ), 't' => (string) ( $f['type'] ?? 'text' ), 'h' => ! empty( $f['hidden'] ) ? 1 : 0, 'r' => ! empty( $f['required'] ) ? 1 : 0 );
				if ( 'select' === $one['t'] ) {
					$one['o'] = array();
					foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
						$one['o'][] = array( (string) ( $o['v'] ?? '' ), (string) ( $o['label'] ?? '' ) );
					}
				}
				$out[] = $one;
			}
			return $out;
		}

		/** Notes (plain Vietnamese, computed) of what differs between two snapshots, $b being the newer. */
		public static function diff_notes( array $a, array $b ): array {
			$ia = array();
			foreach ( $a as $i => $f ) {
				$ia[ $f['k'] ] = array( $i, $f );
			}
			$notes = array();
			$ib    = array();
			foreach ( $b as $i => $f ) {
				$ib[ $f['k'] ] = $i;
				if ( ! isset( $ia[ $f['k'] ] ) ) {
					$notes[] = 'Thêm cột “' . $f['l'] . '” (' . self::type_label( $f['t'] ) . ')';
					continue;
				}
				$old = $ia[ $f['k'] ][1];
				if ( $old['l'] !== $f['l'] ) {
					$notes[] = 'Đổi tên cột “' . $old['l'] . '” → “' . $f['l'] . '”';
				}
				if ( $old['h'] !== $f['h'] ) {
					$notes[] = ( $f['h'] ? 'Ẩn' : 'Hiện' ) . ' cột “' . $f['l'] . '”';
				}
				$new_opts = count( (array) ( $f['o'] ?? array() ) ) - count( (array) ( $old['o'] ?? array() ) );
				if ( $new_opts > 0 ) {
					$notes[] = 'Thêm ' . $new_opts . ' lựa chọn vào “' . $f['l'] . '”';
				}
			}
			foreach ( $a as $f ) {
				if ( ! isset( $ib[ $f['k'] ] ) ) {
					$notes[] = 'Cột “' . $f['l'] . '” không còn trong bản này'; // cannot happen through this class; reported, never hidden
				}
			}
			$ka = array_values( array_filter( array_column( $a, 'k' ), static function ( $k ) use ( $ib ) { return isset( $ib[ $k ] ); } ) );
			$kb = array_values( array_filter( array_column( $b, 'k' ), static function ( $k ) use ( $ia ) { return isset( $ia[ $k ] ); } ) );
			if ( $ka !== $kb ) {
				$notes[] = 'Đổi thứ tự cột';
			}
			return $notes;
		}

		public static function type_label( string $t ): string {
			$map = array( 'text' => 'chữ ngắn', 'long_text' => 'chữ dài', 'integer' => 'số', 'money' => 'tiền', 'boolean' => 'có/không', 'date' => 'ngày', 'select' => 'chọn một', 'percent' => 'phần trăm' );
			return $map[ $t ] ?? $t;
		}

		// ── pure: operations ──────────────────────────────────────────

		private static function bad( string $message, string $hint, int $i = -1, int $status = 422 ): WP_Error {
			return BizCity_Twin_Sheet_Schema::error( 'structure_invalid', $message . ( $i >= 0 ? ' (thao tác ' . ( $i + 1 ) . ')' : '' ), $hint, $status );
		}

		/** @return int index in $fields of a user field, -1 if none */
		private static function index_of( array $fields, string $key ): int {
			foreach ( $fields as $i => $f ) {
				if ( (string) ( $f['key'] ?? '' ) === $key && ! in_array( $key, self::SYSTEM_KEYS, true ) ) {
					return (int) $i;
				}
			}
			return -1;
		}

		private static function user_count( array $fields ): int {
			$n = 0;
			foreach ( $fields as $f ) {
				if ( ! in_array( (string) ( $f['key'] ?? '' ), self::SYSTEM_KEYS, true ) ) {
					$n++;
				}
			}
			return $n;
		}

		/**
		 * Apply operations to a stored definition. All or nothing.
		 *
		 * @return array|WP_Error {def, notes[]}
		 */
		public static function apply( array $def, array $ops ) {
			$ops = array_values( array_filter( $ops, 'is_array' ) );
			if ( array() === $ops ) {
				return self::bad( 'Chưa có thay đổi nào về cột.', 'Chọn một thay đổi (thêm, đổi tên, ẩn…) rồi lưu.', -1, 400 );
			}
			if ( count( $ops ) > self::MAX_OPS ) {
				return self::bad( 'Một lần lưu tối đa ' . self::MAX_OPS . ' thay đổi về cột.', 'Lưu bớt rồi sửa tiếp.', -1, 413 );
			}
			$before = self::snapshot( $def );
			$fields = array_values( (array) ( $def['fields'] ?? array() ) );
			$used   = array();
			foreach ( $fields as $f ) {
				$used[ (string) $f['key'] ] = true;
			}
			foreach ( $ops as $i => $op ) {
				$kind = (string) ( $op['op'] ?? '' );
				$key  = (string) ( $op['key'] ?? '' );
				switch ( $kind ) {
					case 'add_field':
						if ( self::user_count( $fields ) >= BizCity_Twin_Sheet_Builder::MAX_USER_FIELDS ) {
							return self::bad( 'Sổ đã đủ ' . BizCity_Twin_Sheet_Builder::MAX_USER_FIELDS . ' cột.', 'Ẩn bớt cột không dùng hoặc tạo sổ mới.', $i, 409 );
						}
						$type = (string) ( $op['type'] ?? 'text' );
						if ( 'money' === $type && 'void_reenter' !== (string) ( $def['edit_policy'] ?? '' ) ) {
							return self::bad( 'Sổ này chưa phải sổ tiền nên chưa thêm được cột tiền.', 'Tạo một sổ mới có cột tiền; sổ tiền không sửa đè số cũ nên không đổi ngược được.', $i, 409 );
						}
						$probe = BizCity_Twin_Sheet_Builder::build( array( 'display_name' => 'x', 'fields' => array( array( 'label' => $op['label'] ?? '', 'type' => $type, 'options' => $op['options'] ?? array() ) ) ) );
						if ( is_wp_error( $probe ) ) {
							return self::bad( $probe->get_error_message(), (string) ( ( $probe->get_error_data() )['hint'] ?? 'Kiểm tra lại cột mới.' ), $i );
						}
						$new        = $probe['fields'][1];
						$new['key'] = BizCity_Twin_Sheet_Builder::unique_key( BizCity_Twin_Sheet_Builder::slug( (string) $new['label'] ), $used );
						$new['required'] = false; // rows written before this column existed must stay valid
						array_splice( $fields, count( $fields ) - 1, 0, array( $new ) ); // before the computed code column
						break;
					case 'rename_field':
						$ix    = self::index_of( $fields, $key );
						$label = BizCity_Twin_Sheet_Schema::clean_text( (string) ( $op['label'] ?? '' ), 60 );
						if ( $ix < 0 ) {
							return self::bad( 'Không thấy cột cần đổi tên.', 'Tải lại sổ rồi thử lại.', $i, 404 );
						}
						if ( '' === $label ) {
							return self::bad( 'Tên cột không được để trống.', 'Nhập tên mới cho cột.', $i );
						}
						$fields[ $ix ]['label'] = $label;
						break;
					case 'hide_field':
					case 'show_field':
						$ix = self::index_of( $fields, $key );
						if ( $ix < 0 ) {
							return self::bad( 'Không thấy cột.', 'Tải lại sổ rồi thử lại.', $i, 404 );
						}
						if ( 'hide_field' === $kind && ! empty( $fields[ $ix ]['required'] ) ) {
							return self::bad( 'Cột “' . $fields[ $ix ]['label'] . '” bắt buộc nên chưa ẩn được.', 'Muốn ẩn, hãy tạo sổ mới không bắt buộc cột này.', $i, 409 );
						}
						if ( 'hide_field' === $kind ) {
							$fields[ $ix ]['hidden'] = true;
						} else {
							unset( $fields[ $ix ]['hidden'] );
						}
						break;
					case 'add_options':
						$ix = self::index_of( $fields, $key );
						if ( $ix < 0 || 'select' !== (string) ( $fields[ $ix ]['type'] ?? '' ) ) {
							return self::bad( 'Chỉ thêm được lựa chọn vào cột kiểu “chọn một”.', 'Chọn đúng cột.', $i );
						}
						$have = array();
						$vals = array();
						foreach ( (array) $fields[ $ix ]['options'] as $o ) {
							$have[ function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $o['label'], 'UTF-8' ) : strtolower( (string) $o['label'] ) ] = true;
							$vals[ (string) $o['v'] ] = true;
						}
						$added = 0;
						foreach ( (array) ( $op['options'] ?? array() ) as $o ) {
							$ol = BizCity_Twin_Sheet_Schema::clean_text( is_array( $o ) ? (string) ( $o['label'] ?? '' ) : (string) $o, 60 );
							$lk = function_exists( 'mb_strtolower' ) ? mb_strtolower( $ol, 'UTF-8' ) : strtolower( $ol );
							if ( '' === $ol || isset( $have[ $lk ] ) ) {
								continue;
							}
							$ov = BizCity_Twin_Sheet_Builder::slug( $ol );
							$n  = 2;
							while ( isset( $vals[ $ov ] ) ) {
								$ov = substr( BizCity_Twin_Sheet_Builder::slug( $ol ), 0, 26 ) . '_' . $n++;
							}
							$vals[ $ov ] = true;
							$have[ $lk ] = true;
							$fields[ $ix ]['options'][] = array( 'v' => $ov, 'label' => $ol );
							$added++;
						}
						if ( count( $fields[ $ix ]['options'] ) > BizCity_Twin_Sheet_Builder::MAX_OPTIONS ) {
							return self::bad( 'Một cột chọn một tối đa ' . BizCity_Twin_Sheet_Builder::MAX_OPTIONS . ' lựa chọn.', 'Gộp bớt lựa chọn.', $i, 409 );
						}
						if ( 0 === $added ) {
							return self::bad( 'Các lựa chọn này đã có sẵn.', 'Nhập lựa chọn khác.', $i );
						}
						break;
					case 'move_field':
						$ix = self::index_of( $fields, $key );
						if ( $ix < 0 ) {
							return self::bad( 'Không thấy cột cần chuyển.', 'Tải lại sổ rồi thử lại.', $i, 404 );
						}
						$after = (string) ( $op['after'] ?? '' );
						if ( '' !== $after && ( $after === $key || self::index_of( $fields, $after ) < 0 ) ) {
							return self::bad( 'Không chuyển được cột tới chỗ đó.', 'Chọn một cột khác làm mốc.', $i );
						}
						$moving = array_splice( $fields, $ix, 1 );
						$pos    = '' === $after ? 1 : self::index_of( $fields, $after ) + 1; // 0 is doc_date
						array_splice( $fields, $pos, 0, $moving );
						break;
					default:
						return self::bad( 'Thao tác không hỗ trợ.', 'Thêm, đổi tên, ẩn/hiện, thêm lựa chọn hoặc chuyển cột.', $i );
				}
			}
			$def['fields'] = array_values( $fields );
			$after_snap    = self::snapshot( $def );
			$notes         = self::diff_notes( $before, $after_snap );
			if ( array() === $notes ) {
				return self::bad( 'Chưa có gì thay đổi.', 'Sửa tên, ẩn/hiện, thêm cột hoặc đổi thứ tự rồi lưu.', -1, 400 );
			}
			$def['schema_version'] = max( 1, (int) ( $def['schema_version'] ?? 1 ) ) + 1;
			return array( 'def' => $def, 'notes' => $notes );
		}

		/**
		 * Put labels, hidden flags and order back to a snapshot. Columns added since stay (hidden), required ones stay visible, types and
		 * options are never reduced.
		 *
		 * @return array|WP_Error {def, notes[]}
		 */
		public static function restore( array $def, array $snap, int $v ) {
			$fields = array_values( (array) ( $def['fields'] ?? array() ) );
			$by     = array();
			foreach ( $fields as $f ) {
				$by[ (string) $f['key'] ] = $f;
			}
			$head = array();
			$tail = array();
			foreach ( $fields as $f ) {
				if ( 'doc_date' === $f['key'] ) {
					$head[] = $f;
				} elseif ( 'ref_code' === $f['key'] ) {
					$tail[] = $f;
				}
			}
			$mid  = array();
			$seen = array();
			foreach ( $snap as $s ) {
				$k = (string) ( $s['k'] ?? '' );
				if ( ! isset( $by[ $k ] ) || in_array( $k, self::SYSTEM_KEYS, true ) ) {
					continue;
				}
				$f = $by[ $k ];
				$seen[ $k ] = true;
				if ( '' !== (string) ( $s['l'] ?? '' ) ) {
					$f['label'] = (string) $s['l'];
				}
				if ( ! empty( $s['h'] ) && empty( $f['required'] ) ) {
					$f['hidden'] = true;
				} else {
					unset( $f['hidden'] );
				}
				$mid[] = $f;
			}
			foreach ( $fields as $f ) {
				$k = (string) $f['key'];
				if ( ! isset( $seen[ $k ] ) && ! in_array( $k, self::SYSTEM_KEYS, true ) ) {
					if ( empty( $f['required'] ) ) {
						$f['hidden'] = true;
					}
					$mid[] = $f;
				}
			}
			$new           = $def;
			$new['fields'] = array_merge( $head, $mid, $tail );
			$notes         = self::diff_notes( self::snapshot( $def ), self::snapshot( $new ) );
			if ( array() === $notes ) {
				return self::bad( 'Cấu trúc hiện tại đã giống bản v' . $v . '.', 'Chọn bản khác để khôi phục.', -1, 400 );
			}
			$new['schema_version'] = max( 1, (int) ( $def['schema_version'] ?? 1 ) ) + 1;
			return array( 'def' => $new, 'notes' => $notes );
		}

		// ── use cases ─────────────────────────────────────────────────

		/** @return array|WP_Error the record of an OWN custom sheet */
		private static function own( int $owner, string $key, BizCity_Twin_Sheet_Defs $defs ) {
			if ( $owner <= 0 ) {
				return BizCity_Twin_Sheet_Schema::error( 'rest_forbidden', 'Bạn chưa đăng nhập.', 'Đăng nhập rồi thử lại.', 401 );
			}
			if ( ! BizCity_Twin_Sheet_Registry::is_custom_key( $key ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_not_custom', 'Chỉ đổi cột được ở sổ bạn tự tạo.', 'Các sổ có sẵn (thu chi, công nợ…) có cột cố định.', 400 );
			}
			$rec = $defs->get_by_key( $key );
			if ( ! $rec || (int) $rec['owner'] !== $owner ) {
				return BizCity_Twin_Sheet_Schema::error( 'sheet_not_found', 'Không tìm thấy sổ này.', 'Mở danh sách sổ và chọn lại.', 404 );
			}
			return $rec;
		}

		/**
		 * [2026-10-21 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W4 — optimistic lock on the STRUCTURE: the person saw version N; if the sheet moved on (another tab, another device) nothing is written.
		 * 0 = the caller did not say (older client) ⇒ no check.
		 *
		 * @return WP_Error|null
		 */
		private static function stale( array $rec, int $expected ) {
			$cur = max( 1, (int) ( $rec['def']['schema_version'] ?? 1 ) );
			return $expected > 0 && $expected !== $cur
				? BizCity_Twin_Sheet_Schema::error( 'version_conflict', 'Cấu trúc sổ vừa được đổi ở nơi khác (đã lên v' . $cur . ').', 'Tải lại sổ rồi làm lại thay đổi.', 409 )
				: null;
		}

		/** Stored versions, oldest first; a sheet never edited reports its current structure as the baseline. */
		private static function stored( array $rec, BizCity_Twin_Sheet_Defs $defs, string $now ): array {
			$list = $defs instanceof BizCity_Twin_Sheet_Version_Store ? $defs->versions( (int) $rec['id'] ) : array();
			if ( array() === $list ) {
				$list = array( array( 'v' => max( 1, (int) ( $rec['def']['schema_version'] ?? 1 ) ), 'at' => $now, 'by' => (int) $rec['owner'], 'note' => 'Bản gốc', 'source' => 'baseline', 'fields' => self::snapshot( (array) $rec['def'] ) ) );
			}
			return $list;
		}

		/**
		 * Versions newest first, each with the notes against the one before (computed from the snapshots, not remembered).
		 *
		 * @return array|WP_Error {items:[{v, at, note, source, changes[], columns, current}]}
		 */
		/**
		 * [2026-10-22 02:35 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W7 (A13) — what changed between ANY two stored versions a → b (either order), notes computed from the snapshots.
		 *
		 * @return array|WP_Error {a, b, changes[], columns_a, columns_b}
		 */
		public static function compare( int $owner, string $key, int $a, int $b, ?BizCity_Twin_Sheet_Defs $defs = null, string $now = '' ) {
			$defs = $defs ?? BizCity_Twin_Sheet_Registry::defs();
			$rec  = self::own( $owner, $key, $defs );
			if ( is_wp_error( $rec ) ) {
				return $rec;
			}
			$by = array();
			foreach ( self::stored( $rec, $defs, '' !== $now ? $now : gmdate( 'c' ) ) as $ver ) {
				$by[ (int) $ver['v'] ] = (array) $ver['fields'];
			}
			if ( ! isset( $by[ $a ], $by[ $b ] ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'version_not_found', 'Không có bản cấu trúc này trong sổ.', 'Chọn lại hai bản trong danh sách "Các bản".', 404 );
			}
			return array( 'a' => $a, 'b' => $b, 'changes' => $a === $b ? array() : self::diff_notes( $by[ $a ], $by[ $b ] ), 'columns_a' => count( $by[ $a ] ), 'columns_b' => count( $by[ $b ] ) );
		}

		public static function versions( int $owner, string $key, ?BizCity_Twin_Sheet_Defs $defs = null, string $now = '' ) {
			$defs = $defs ?? BizCity_Twin_Sheet_Registry::defs();
			$rec  = self::own( $owner, $key, $defs );
			if ( is_wp_error( $rec ) ) {
				return $rec;
			}
			$list = self::stored( $rec, $defs, '' !== $now ? $now : gmdate( 'c' ) );
			$cur  = (int) ( $rec['def']['schema_version'] ?? 1 );
			$out  = array();
			foreach ( $list as $i => $ver ) {
				$prev  = $i > 0 ? (array) $list[ $i - 1 ]['fields'] : null;
				$out[] = array(
					'v'       => (int) $ver['v'],
					'at'      => (string) $ver['at'],
					'note'    => (string) $ver['note'],
					'source'  => (string) $ver['source'],
					'changes' => null === $prev ? array() : self::diff_notes( $prev, (array) $ver['fields'] ),
					'columns' => count( (array) $ver['fields'] ),
					'current' => (int) $ver['v'] === $cur,
				);
			}
			return array( 'items' => array_reverse( $out ), 'current' => $cur );
		}

		/** Write the new definition, then the version record. @return array|WP_Error */
		private static function commit( array $rec, array $res, string $note, string $source, BizCity_Twin_Sheet_Defs $defs, string $now ) {
			$versions = self::stored( $rec, $defs, $now );
			if ( 1 === count( $versions ) && 'baseline' === $versions[0]['source'] && $defs instanceof BizCity_Twin_Sheet_Version_Store && array() === $defs->versions( (int) $rec['id'] ) ) {
				$versions[0]['at'] = $now; // first edit: the baseline is the structure as it was right before
			}
			$versions[] = array( 'v' => (int) $res['def']['schema_version'], 'at' => $now, 'by' => (int) $rec['owner'], 'note' => $note, 'source' => $source, 'fields' => self::snapshot( $res['def'] ) );
			$versions   = array_slice( $versions, -self::KEEP );
			$r = $defs->update( (int) $rec['id'], array( 'def' => $res['def'] ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$kept = $defs instanceof BizCity_Twin_Sheet_Version_Store ? $defs->save_versions( (int) $rec['id'], $versions ) : false;
			if ( class_exists( 'BizCity_Journal_Kinds' ) ) {
				BizCity_Journal_Kinds::reset(); // a new column is a new card field of the journal kind
			}
			$updated = $rec;
			$updated['def'] = $res['def'];
			$sheet = BizCity_Twin_Sheet_Registry::normalize_record( $updated );
			return is_wp_error( $sheet ) ? $sheet : array( 'sheet' => $sheet, 'version' => (int) $res['def']['schema_version'], 'notes' => $res['notes'], 'history_saved' => $kept );
		}

		/**
		 * @param array $input {ops: array[]}
		 * @return array|WP_Error {sheet, version, notes[], history_saved}
		 */
		public static function edit( int $owner, string $key, array $input, ?BizCity_Twin_Sheet_Defs $defs = null, string $now = '' ) {
			$defs = $defs ?? BizCity_Twin_Sheet_Registry::defs();
			$rec  = self::own( $owner, $key, $defs );
			if ( is_wp_error( $rec ) ) {
				return $rec;
			}
			$stale = self::stale( $rec, (int) ( $input['expected_version'] ?? 0 ) );
			if ( null !== $stale ) {
				return $stale;
			}
			$res = self::apply( (array) $rec['def'], (array) ( $input['ops'] ?? array() ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return self::commit( $rec, $res, implode( '; ', $res['notes'] ), 'edit', $defs, '' !== $now ? $now : gmdate( 'c' ) );
		}

		/** @return array|WP_Error {sheet, version, notes[], history_saved} */
		public static function restore_version( int $owner, string $key, int $v, ?BizCity_Twin_Sheet_Defs $defs = null, string $now = '', int $expected_version = 0 ) {
			$defs = $defs ?? BizCity_Twin_Sheet_Registry::defs();
			$rec  = self::own( $owner, $key, $defs );
			if ( is_wp_error( $rec ) ) {
				return $rec;
			}
			$stale = self::stale( $rec, $expected_version );
			if ( null !== $stale ) {
				return $stale;
			}
			$now  = '' !== $now ? $now : gmdate( 'c' );
			$snap = null;
			foreach ( self::stored( $rec, $defs, $now ) as $ver ) {
				if ( (int) $ver['v'] === $v ) {
					$snap = (array) $ver['fields'];
				}
			}
			if ( null === $snap ) {
				return BizCity_Twin_Sheet_Schema::error( 'version_not_found', 'Không tìm thấy bản cấu trúc v' . $v . '.', 'Mở danh sách các bản rồi chọn lại (chỉ giữ 20 bản gần nhất).', 404 );
			}
			$res = self::restore( (array) $rec['def'], $snap, $v );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return self::commit( $rec, $res, 'Khôi phục cấu trúc về v' . $v . ': ' . implode( '; ', $res['notes'] ), 'restore', $defs, $now );
		}
	}
}
