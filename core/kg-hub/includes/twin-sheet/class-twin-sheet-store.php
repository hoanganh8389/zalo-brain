<?php
/**
 * Twin Sheet store (Storage Adapter, D123-7) + the pure engine that filters, sorts, pages and aggregates sheet rows.
 *
 *   BizCity_Twin_Sheet_Store          — the adapter interface `twinsheet.*` talks to (doc 20 §7)
 *   BizCity_Twin_Sheet_Journal_Store  — the only adapter in W1: rows = bizcity_journal_entries rows of the sheet's kind
 *   BizCity_Twin_Sheet_Engine         — pure functions over journal-entry@1.1.0 arrays (unit-tested without a database)
 *
 * Reads only, owner-scoped (the owner comes from the authenticated principal, never from the caller's arguments). A scan is
 * bounded: ≤ SCAN_MAX rows of ONE kind of ONE owner in the requested doc_date range; past that the answer says `truncated`.
 * Money is summed as integers (D123-10). `void` / `failed` rows never count in an aggregate; a reversal pair (fin.cash
 * `status: reversal` + `reverses`) cancels out of an aggregate but stays visible in the grid.
 *
 * Cache contract: the scanned slice (decoded entries) is cached in BizCity_Cache group `journal` under
 * blog + owner + kind + from + to when it holds ≤ CACHE_MAX_ROWS rows; every journal write flushes the group
 * (BizCity_Journal_Database::flush_cache), so a cached slice is never older than the last write (R-CACHE).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-19 09:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W1 (B4/B5) — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub\Twin_Sheet
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Twin_Sheet_Store', false ) ) {
	interface BizCity_Twin_Sheet_Store {

		/** @return array{rows:array,total:int,rows_scanned:int,truncated:bool,totals:array} */
		public function rows( array $sheet, array $query, int $owner_user_id ): array;

		/** @return array|WP_Error aggregate result */
		public function aggregate( array $sheet, array $query, array $spec, int $owner_user_id );
	}
}

if ( ! class_exists( 'BizCity_Twin_Sheet_Engine', false ) ) {
	final class BizCity_Twin_Sheet_Engine {

		/** Raw value of one field on one journal entry (card / index column / computed). */
		public static function value_of( array $entry, array $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( 'computed' === ( $field['source'] ?? '' ) ) {
				return 'ref_code' === $key ? self::ref_code( $entry ) : null;
			}
			if ( 'column' === ( $field['source'] ?? '' ) ) {
				if ( 'tags' === $key ) {
					return implode( ', ', array_map( 'strval', (array) ( $entry['tags'] ?? array() ) ) );
				}
				return $entry[ $key ] ?? null;
			}
			$card = is_array( $entry['card'] ?? null ) ? $entry['card'] : array();
			return array_key_exists( $key, $card ) ? $card[ $key ] : null;
		}

		/** The code a person types back: TC-/CN-/TD-/VC-/KH- for the five books, TL- for a document, JE- for anything else. */
		public static function ref_code( array $entry ): string {
			$id = (int) ( $entry['entry_id'] ?? 0 );
			if ( class_exists( 'BizCity_Journal_MCP_Tools' ) ) {
				$c = BizCity_Journal_MCP_Tools::ref_code( (string) ( $entry['kind'] ?? '' ), $id );
				if ( '' !== $c ) {
					return $c;
				}
			}
			$doc = (string) ( $entry['original']['doc_code'] ?? '' );
			return '' !== $doc ? $doc : ( $id > 0 ? 'JE-' . $id : '' );
		}

		/** Comparable form of a value for its field type (ints for numbers, bools, lowercased strings). */
		private static function comparable( array $field, $v ) {
			$type = (string) ( $field['type'] ?? 'text' );
			if ( in_array( $type, array( 'money', 'integer', 'percent' ), true ) ) {
				return BizCity_Twin_Sheet_Schema::money_to_int( $v );
			}
			if ( 'boolean' === $type ) {
				return BizCity_Twin_Sheet_Schema::to_bool( $v );
			}
			if ( null === $v || ( is_string( $v ) && '' === $v ) ) {
				return null;
			}
			return is_scalar( $v ) ? ( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $v ) : strtolower( (string) $v ) ) : null;
		}

		/** Does one entry pass every allow-listed condition + the free text search? */
		public static function matches( array $entry, array $sheet, array $query ): bool {
			$state = (string) ( $entry['state'] ?? 'captured' );
			if ( 'void' === $state && empty( $query['include_void'] ) ) {
				return false;
			}
			// [2026-10-20 05:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 S124-X1 — chỉ dòng của nhóm (tag grp-<gid>; cùng chủ, cùng loại sổ)
			$group = (string) ( $query['group'] ?? '' );
			if ( '' !== $group && ! in_array( 'grp-' . $group, array_map( 'strval', (array) ( $entry['tags'] ?? array() ) ), true ) ) {
				return false;
			}
			foreach ( (array) ( $query['where'] ?? array() ) as $w ) {
				$f = BizCity_Twin_Sheet_Schema::field( $sheet, (string) $w['field'] );
				if ( ! $f ) {
					return false;
				}
				$have = self::comparable( $f, self::value_of( $entry, $f ) );
				$op   = (string) $w['op'];
				if ( 'empty' === $op ) {
					if ( null !== $have ) {
						return false;
					}
					continue;
				}
				if ( 'not_empty' === $op ) {
					if ( null === $have ) {
						return false;
					}
					continue;
				}
				if ( null === $have ) {
					return false;
				}
				if ( 'eq' === $op && $have !== self::comparable( $f, $w['value'] ) ) {
					return false;
				}
				if ( 'in' === $op ) {
					$set = array_map( static function ( $x ) use ( $f ) { return self::comparable( $f, $x ); }, (array) $w['value'] );
					if ( ! in_array( $have, $set, true ) ) {
						return false;
					}
				}
				if ( 'contains' === $op ) {
					$needle = (string) self::comparable( array( 'type' => 'text' ), (string) $w['value'] );
					if ( '' !== $needle && false === strpos( (string) $have, $needle ) ) {
						return false;
					}
				}
				if ( 'between' === $op ) {
					$lo = self::comparable( $f, $w['value'][0] ?? null );
					$hi = self::comparable( $f, $w['value'][1] ?? null );
					if ( ( null !== $lo && $have < $lo ) || ( null !== $hi && $have > $hi ) ) {
						return false;
					}
				}
			}
			$q = (string) ( $query['q'] ?? '' );
			if ( '' !== $q ) {
				$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );
				$hay    = array( (string) ( $entry['title'] ?? '' ), self::ref_code( $entry ) );
				foreach ( (array) ( $sheet['fields'] ?? array() ) as $f ) {
					if ( ! empty( $f['search'] ) ) {
						$v = self::value_of( $entry, $f );
						if ( is_scalar( $v ) ) {
							$hay[] = (string) $v;
						}
					}
				}
				$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( implode( "\n", $hay ) ) : strtolower( implode( "\n", $hay ) );
				if ( false === strpos( $text, $needle ) ) {
					return false;
				}
			}
			return true;
		}

		/** Keep only the newest version of each record (task.todo writes one row per version `job:<id>.v<n>`). */
		public static function latest_only( array $entries, ?array $latest_by ): array {
			if ( null === $latest_by ) {
				return $entries;
			}
			$order = (string) ( $latest_by['order_field'] ?? 'version' );
			$best  = array();
			$loose = array();
			foreach ( $entries as $e ) {
				$k = (string) ( $e['source_ref'] ?? '' );
				if ( '' === $k ) {
					$loose[] = $e;
					continue;
				}
				$v = (int) ( $e['card'][ $order ] ?? 0 );
				if ( ! isset( $best[ $k ] ) || $v > (int) ( $best[ $k ]['card'][ $order ] ?? 0 ) || ( $v === (int) ( $best[ $k ]['card'][ $order ] ?? 0 ) && (int) $e['entry_id'] > (int) $best[ $k ]['entry_id'] ) ) {
					$best[ $k ] = $e;
				}
			}
			return array_merge( array_values( $best ), $loose );
		}

		/** Filter + newest-version + sort (stable: newest doc_date, then newest id). */
		public static function select( array $entries, array $sheet, array $query ): array {
			$entries = self::latest_only( $entries, $sheet['latest_by'] ?? null );
			$out     = array();
			foreach ( $entries as $e ) {
				if ( self::matches( $e, $sheet, $query ) ) {
					$out[] = $e;
				}
			}
			$sort = (array) ( $query['sort'] ?? array() );
			usort( $out, static function ( $a, $b ) use ( $sheet, $sort ) {
				foreach ( $sort as $s ) {
					$f  = BizCity_Twin_Sheet_Schema::field( $sheet, (string) $s['field'] );
					$va = $f ? self::comparable( $f, self::value_of( $a, $f ) ) : null;
					$vb = $f ? self::comparable( $f, self::value_of( $b, $f ) ) : null;
					if ( $va === $vb ) {
						continue;
					}
					if ( null === $va ) {
						return 1; // empty last, whatever the direction
					}
					if ( null === $vb ) {
						return -1;
					}
					$c = $va <=> $vb;
					return 'asc' === $s['dir'] ? $c : -$c;
				}
				$c = strcmp( (string) ( $b['doc_date'] ?? '' ), (string) ( $a['doc_date'] ?? '' ) );
				return 0 !== $c ? $c : ( (int) $b['entry_id'] <=> (int) $a['entry_id'] );
			} );
			return $out;
		}

		/** One grid row: declared fields only (no original / attachment / metadata leaks). */
		public static function project( array $entry, array $sheet ): array {
			$values = array();
			foreach ( (array) ( $sheet['fields'] ?? array() ) as $f ) {
				$v = self::value_of( $entry, $f );
				$values[ $f['key'] ] = is_scalar( $v ) || null === $v ? $v : null;
			}
			return array(
				'entry_id'   => (int) ( $entry['entry_id'] ?? 0 ),
				'ref_code'   => self::ref_code( $entry ),
				'rev'        => (int) ( $entry['rev'] ?? 1 ),
				'state'      => (string) ( $entry['state'] ?? 'captured' ),
				'doc_date'   => $entry['doc_date'] ?? null,
				'created_at' => (string) ( $entry['created_at'] ?? '' ),
				'by_role'    => (string) ( $entry['by']['role'] ?? '' ),
				'surface'    => (string) ( $entry['origin']['surface'] ?? '' ),
				'values'     => $values,
				// [2026-10-20 12:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — row history (newest last), only changes to declared columns
				'history'    => self::history_of( $entry, $sheet ),
			);
		}

		/** @return array<int,array{at:string,by:string,via:string,changes:array}> */
		public static function history_of( array $entry, array $sheet ): array {
			$keys = array();
			foreach ( (array) ( $sheet['fields'] ?? array() ) as $f ) {
				$keys[ $f['key'] ] = true;
			}
			$out = array();
			foreach ( (array) ( $entry['sheet_history'] ?? array() ) as $h ) {
				if ( ! is_array( $h ) ) {
					continue;
				}
				$ch = array();
				foreach ( (array) ( $h['changes'] ?? array() ) as $k => $pair ) {
					if ( isset( $keys[ $k ] ) && is_array( $pair ) ) {
						$ch[ $k ] = array( is_scalar( $pair[0] ?? null ) ? $pair[0] : null, is_scalar( $pair[1] ?? null ) ? $pair[1] : null );
					}
				}
				$out[] = array( 'at' => (string) ( $h['at'] ?? '' ), 'by' => (string) ( $h['by'] ?? '' ), 'via' => (string) ( $h['via'] ?? '' ), 'changes' => $ch );
			}
			return $out;
		}

		/** Entries that count in an aggregate: not void / failed, reversal pairs removed. */
		public static function countable( array $entries, array $sheet ): array {
			$entries = array_values( array_filter( $entries, static function ( $e ) {
				return ! in_array( (string) ( $e['state'] ?? '' ), array( 'void', 'failed' ), true );
			} ) );
			$pc = $sheet['pair_cancel'] ?? null;
			if ( ! is_array( $pc ) ) {
				return $entries;
			}
			$mf = (string) ( $pc['marker_field'] ?? '' );
			$mk = (string) ( $pc['marker'] ?? '' );
			$lf = (string) ( $pc['link_field'] ?? '' );
			$reversed = array();
			$refs     = array();
			foreach ( $entries as $e ) {
				$refs[ (string) ( $e['source_ref'] ?? '' ) ] = true;
			}
			foreach ( $entries as $e ) {
				if ( $mk === (string) ( $e['card'][ $mf ] ?? '' ) && '' !== (string) ( $e['card'][ $lf ] ?? '' ) ) {
					$reversed[ (string) $e['card'][ $lf ] ] = true;
				}
			}
			return array_values( array_filter( $entries, static function ( $e ) use ( $mf, $mk, $lf, $reversed, $refs ) {
				$is_reversal = $mk === (string) ( $e['card'][ $mf ] ?? '' );
				if ( $is_reversal ) {
					return ! isset( $refs[ (string) ( $e['card'][ $lf ] ?? '' ) ] ); // a reversal whose original is outside the range still counts
				}
				return ! isset( $reversed[ (string) ( $e['source_ref'] ?? '' ) ] );
			} ) );
		}

		/** Bucket key of an entry for a group-by: a groupable field, or day / week / month / year of doc_date. */
		public static function group_key( array $entry, array $sheet, string $by ): string {
			if ( in_array( $by, array( 'day', 'week', 'month', 'year' ), true ) ) {
				$d = (string) ( $entry['doc_date'] ?? '' );
				if ( ! BizCity_Twin_Sheet_Schema::valid_date( $d ) ) {
					return '';
				}
				if ( 'day' === $by ) {
					return $d;
				}
				if ( 'month' === $by ) {
					return substr( $d, 0, 7 );
				}
				if ( 'year' === $by ) {
					return substr( $d, 0, 4 );
				}
				return gmdate( 'o-\WW', (int) strtotime( $d . ' UTC' ) );
			}
			$f = BizCity_Twin_Sheet_Schema::field( $sheet, $by );
			$v = $f ? self::value_of( $entry, $f ) : null;
			if ( $f && 'boolean' === $f['type'] ) {
				$b = BizCity_Twin_Sheet_Schema::to_bool( $v );
				return null === $b ? '' : ( $b ? '1' : '0' );
			}
			return is_scalar( $v ) ? trim( (string) $v ) : '';
		}

		/**
		 * Validate an aggregate spec against the sheet: fn ∈ field.aggregate (count always allowed), group_by / split_by a
		 * groupable field or a date bucket.
		 *
		 * @return array|WP_Error {fn, field, group_by, split_by}
		 */
		public static function normalize_spec( array $sheet, array $spec ) {
			$fn    = (string) ( $spec['fn'] ?? 'sum' );
			$field = (string) ( $spec['field'] ?? '' );
			if ( ! in_array( $fn, array( 'sum', 'count', 'min', 'max', 'avg' ), true ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'aggregate_invalid', 'Phép tính không hỗ trợ.', 'Dùng tổng, đếm, nhỏ nhất, lớn nhất hoặc trung bình.', 400 );
			}
			$f = '' !== $field ? BizCity_Twin_Sheet_Schema::field( $sheet, $field ) : null;
			if ( 'count' !== $fn && ( ! $f || ! in_array( $fn, (array) $f['aggregate'], true ) ) ) {
				return BizCity_Twin_Sheet_Schema::error( 'field_not_queryable', 'Không tính được trên cột này.', 'Chọn một cột số tiền / số lượng của sổ.', 400 );
			}
			$out = array( 'fn' => $fn, 'field' => $f ? $f['key'] : '', 'group_by' => '', 'split_by' => '' );
			foreach ( array( 'group_by', 'split_by' ) as $k ) {
				$by = (string) ( $spec[ $k ] ?? '' );
				if ( '' === $by ) {
					continue;
				}
				$g = BizCity_Twin_Sheet_Schema::field( $sheet, $by );
				if ( ! in_array( $by, array( 'day', 'week', 'month', 'year' ), true ) && ( ! $g || empty( $g['group'] ) ) ) {
					return BizCity_Twin_Sheet_Schema::error( 'field_not_queryable', 'Không gom nhóm được theo cột này.', 'Gom theo ngày / tuần / tháng / năm hoặc một cột có thể gom.', 400 );
				}
				$out[ $k ] = $by;
			}
			return $out;
		}

		/**
		 * Integer aggregate over entries already filtered by the query. avg rounds half up to an integer.
		 *
		 * @return array{fn:string,field:string,value:int,count:int,groups:array}
		 */
		public static function aggregate( array $entries, array $sheet, array $spec ): array {
			$f     = '' !== $spec['field'] ? BizCity_Twin_Sheet_Schema::field( $sheet, $spec['field'] ) : null;
			$cells = array(); // [group][split] => {sum, n, min, max}
			$all   = array( 'sum' => 0, 'n' => 0, 'min' => null, 'max' => null );
			$feed  = static function ( array &$c, int $v ) {
				$c['sum'] += $v;
				$c['n']++;
				$c['min'] = null === $c['min'] ? $v : min( $c['min'], $v );
				$c['max'] = null === $c['max'] ? $v : max( $c['max'], $v );
			};
			foreach ( self::countable( $entries, $sheet ) as $e ) {
				if ( 'count' === $spec['fn'] ) {
					$v = 1; // count = rows that passed the filter
				} else {
					$v = BizCity_Twin_Sheet_Schema::money_to_int( self::value_of( $e, $f ) );
					if ( null === $v ) {
						continue; // an empty / non-numeric cell does not count
					}
				}
				$g = '' !== $spec['group_by'] ? self::group_key( $e, $sheet, $spec['group_by'] ) : '*';
				$s = '' !== $spec['split_by'] ? self::group_key( $e, $sheet, $spec['split_by'] ) : '*';
				if ( ! isset( $cells[ $g ][ $s ] ) ) {
					$cells[ $g ][ $s ] = array( 'sum' => 0, 'n' => 0, 'min' => null, 'max' => null );
				}
				$feed( $cells[ $g ][ $s ], $v );
				$feed( $all, $v );
			}
			$pick = static function ( array $c, string $fn ): int {
				if ( 'count' === $fn ) {
					return (int) $c['n'];
				}
				if ( 'avg' === $fn ) {
					return $c['n'] > 0 ? (int) floor( $c['sum'] / $c['n'] + 0.5 ) : 0;
				}
				if ( 'min' === $fn || 'max' === $fn ) {
					return (int) ( $c[ $fn ] ?? 0 );
				}
				return (int) $c['sum'];
			};
			$groups = array();
			ksort( $cells );
			foreach ( $cells as $g => $splits ) {
				ksort( $splits );
				$row = array( 'key' => (string) $g, 'label' => self::label_of( $sheet, $spec['group_by'], (string) $g ), 'splits' => array() );
				$tot = array( 'sum' => 0, 'n' => 0, 'min' => null, 'max' => null );
				foreach ( $splits as $s => $c ) {
					$row['splits'][] = array( 'key' => (string) $s, 'label' => self::label_of( $sheet, $spec['split_by'], (string) $s ), 'value' => $pick( $c, $spec['fn'] ), 'count' => (int) $c['n'] );
					$tot['sum'] += $c['sum'];
					$tot['n']   += $c['n'];
					$tot['min']  = null === $tot['min'] ? $c['min'] : ( null === $c['min'] ? $tot['min'] : min( $tot['min'], $c['min'] ) );
					$tot['max']  = null === $tot['max'] ? $c['max'] : ( null === $c['max'] ? $tot['max'] : max( $tot['max'], $c['max'] ) );
				}
				$row['value'] = $pick( $tot, $spec['fn'] );
				$row['count'] = (int) $tot['n'];
				$groups[] = $row;
			}
			return array(
				'fn'     => $spec['fn'],
				'field'  => $spec['field'],
				'value'  => $pick( $all, $spec['fn'] ),
				'count'  => (int) $all['n'],
				'groups' => '' === $spec['group_by'] && '' === $spec['split_by'] ? array() : $groups,
			);
		}

		/** Human label of a group key (select option label, ✓/— for booleans, the key itself otherwise). */
		public static function label_of( array $sheet, string $by, string $key ): string {
			if ( '' === $by || '*' === $key ) {
				return '';
			}
			if ( '' === $key ) {
				return '(trống)';
			}
			$f = BizCity_Twin_Sheet_Schema::field( $sheet, $by );
			if ( $f && 'select' === $f['type'] ) {
				foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
					if ( $key === $o['v'] ) {
						return $o['label'];
					}
				}
			}
			if ( $f && 'boolean' === $f['type'] ) {
				return '1' === $key ? 'Có' : 'Không';
			}
			return $key;
		}
	}
}

if ( ! class_exists( 'BizCity_Twin_Sheet_Journal_Store', false ) ) {
	final class BizCity_Twin_Sheet_Journal_Store implements BizCity_Twin_Sheet_Store {

		const BATCH          = 500;
		const CACHE_MAX_ROWS = 3000;

		/**
		 * All entries of the sheet's slice for one owner (bounded), decoded to journal-entry@1.1.0.
		 *
		 * @return array{entries:array,rows_scanned:int,truncated:bool}
		 */
		public function scan( array $sheet, array $query, int $owner_user_id ): array {
			if ( $owner_user_id <= 0 || ! class_exists( 'BizCity_Journal_Database' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
				return array( 'entries' => array(), 'rows_scanned' => 0, 'truncated' => false );
			}
			$kind = (string) $sheet['kind'];
			$from = (string) ( $query['from'] ?? '' );
			$to   = (string) ( $query['to'] ?? '' );
			$key  = 'twinsheet_slice_' . (int) get_current_blog_id() . '_' . $owner_user_id . '_' . md5( $kind . '|' . $from . '|' . $to );
			$hit  = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( BizCity_Journal_Database::CACHE_GROUP, $key ) : false;
			if ( is_array( $hit ) && isset( $hit['entries'] ) ) {
				return $hit;
			}
			BizCity_Journal_Database::maybe_install();
			$db   = BizCity_Journal_Database::instance();
			$ids  = $db->slice_ids( $owner_user_id, $kind, $from, $to, BizCity_Twin_Sheet_Schema::SCAN_MAX + 1 );
			$trunc = count( $ids ) > BizCity_Twin_Sheet_Schema::SCAN_MAX;
			$ids   = array_slice( $ids, 0, BizCity_Twin_Sheet_Schema::SCAN_MAX );
			$entries = array();
			foreach ( array_chunk( $ids, self::BATCH ) as $chunk ) {
				foreach ( $db->rows_by_ids( $chunk, $owner_user_id ) as $row ) {
					$one  = BizCity_Journal_Index::to_journal_entry( $row );
					// [2026-10-20 12:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.123 W3 — keep the last edits of the row so the row sheet can show "Lịch sử" (declared columns only, see project())
					$meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
					if ( is_array( $meta ) && ! empty( $meta['history'] ) && is_array( $meta['history'] ) ) {
						$one['sheet_history'] = array_slice( $meta['history'], -20 );
					}
					$entries[] = $one;
				}
			}
			$out = array( 'entries' => $entries, 'rows_scanned' => count( $ids ), 'truncated' => $trunc );
			if ( class_exists( 'BizCity_Cache' ) && count( $entries ) <= self::CACHE_MAX_ROWS ) {
				BizCity_Cache::set( BizCity_Journal_Database::CACHE_GROUP, $key, $out, 300 );
			}
			return $out;
		}

		public function rows( array $sheet, array $query, int $owner_user_id ): array {
			$scan  = $this->scan( $sheet, $query, $owner_user_id );
			$list  = BizCity_Twin_Sheet_Engine::select( $scan['entries'], $sheet, $query );
			$page  = array_slice( $list, (int) $query['offset'], (int) $query['limit'] );
			$totals = array();
			foreach ( (array) ( $this->view_of( $sheet, (string) ( $query['view'] ?? '' ) )['totals'] ?? array() ) as $t ) {
				$spec = BizCity_Twin_Sheet_Engine::normalize_spec( $sheet, array( 'fn' => $t['fn'] ?? 'sum', 'field' => $t['field'] ?? '', 'split_by' => $t['split_by'] ?? '' ) );
				if ( ! is_wp_error( $spec ) ) {
					$totals[] = BizCity_Twin_Sheet_Engine::aggregate( $list, $sheet, $spec );
				}
			}
			return array(
				'rows'         => array_map( static function ( $e ) use ( $sheet ) { return BizCity_Twin_Sheet_Engine::project( $e, $sheet ); }, $page ),
				'total'        => count( $list ),
				'offset'       => (int) $query['offset'],
				'limit'        => (int) $query['limit'],
				'rows_scanned' => $scan['rows_scanned'],
				'truncated'    => $scan['truncated'],
				'totals'       => $totals,
			);
		}

		public function aggregate( array $sheet, array $query, array $spec, int $owner_user_id ) {
			$spec = BizCity_Twin_Sheet_Engine::normalize_spec( $sheet, $spec );
			if ( is_wp_error( $spec ) ) {
				return $spec;
			}
			$scan = $this->scan( $sheet, $query, $owner_user_id );
			$list = BizCity_Twin_Sheet_Engine::select( $scan['entries'], $sheet, $query );
			$out  = BizCity_Twin_Sheet_Engine::aggregate( $list, $sheet, $spec );
			$f    = '' !== $spec['field'] ? BizCity_Twin_Sheet_Schema::field( $sheet, $spec['field'] ) : null;
			$out['unit']         = $f && 'money' === $f['type'] ? 'VND' : '';
			$out['from']         = (string) ( $query['from'] ?? '' );
			$out['to']           = (string) ( $query['to'] ?? '' );
			$out['rows_scanned'] = $scan['rows_scanned'];
			$out['truncated']    = $scan['truncated'];
			return $out;
		}

		private function view_of( array $sheet, string $key ): array {
			foreach ( (array) ( $sheet['views'] ?? array() ) as $v ) {
				if ( $key === $v['key'] ) {
					return $v;
				}
			}
			return (array) ( $sheet['views'][0] ?? array() );
		}
	}
}
