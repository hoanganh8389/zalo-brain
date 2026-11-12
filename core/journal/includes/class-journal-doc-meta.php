<?php
/**
 * BizCity_Journal_Doc_Meta — the three optional top-level keys `entry-meta@1.1` adds to metadata.meta (PHASE-0.140 doc 20 §2, decided nested shape:
 * zalo-hub/contracts/fixtures/s140/entry-meta.v1_1.json):
 *
 *   doc     what kind of paper it is + its structured facts: type (DOC-TYPES-v1, 18 keys) · type_conf · title ≤ 160 · date · parties ≤ 6 · dues ≤ 12 ·
 *           totals{amount, currency, direction in|out|unknown, vat} · fields ≤ 40 · uncertain ≤ 20 · sensitive · book{state, family, refs ≤ 10, at}
 *   read    how well it was read: status full|partial · method text_layer|ocr|mixed|stt|video · pages · pages_read · pages_failed ≤ 50 · score ·
 *           progress{pages_read, pages_total} (background-read checkpoint, S140-L6)
 *   points  ≤ 5 summary lines (≤ 300 each) — one copy shared by the vault Markdown and the chat summary
 *
 * BizCity_Journal_Entry_Meta::normalize() / merge() / find_text() / score() / card(), BizCity_Journal_Pipeline::of() and
 * BizCity_Brain_Vault_Store::frontmatter() call in here; BizCity_Brain_Access::is_sensitive() asks is_sensitive() (R-JN-11, D140-13). The cell extracts,
 * the site stores (EM-2 — no model call in PHP). Everything here is pure.
 *
 * merge rule (S140-L1): `doc` and `read` replace as a whole object, EXCEPT `doc.book` — the cell may send `{doc:{book:{…}}}` alone (offer made, row
 * booked, owner declined) and only the book of the stored doc changes; a full `doc` without `book` keeps the stored book (a re-read never forgets
 * that the paper was already booked).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * // [2026-10-25 09:49 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.140 S140-L1 — new file (entry-meta@1.1 doc · read · points; book line; read progress; vault read block; crm card clean).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Doc_Meta', false ) ) {
	return;
}

final class BizCity_Journal_Doc_Meta {

	/** DOC-TYPES-v1 keys (zalo-hub/contracts/DOC-TYPES-v1.json — a unit test compares) ⇒ the label a person reads. */
	const TYPE_LABELS = array(
		'invoice' => 'Hoá đơn', 'receipt_in' => 'Phiếu thu', 'receipt_out' => 'Phiếu chi / uỷ nhiệm chi', 'bill' => 'Biên lai / bill bán lẻ',
		'bank_statement' => 'Sao kê / biến động số dư', 'quote' => 'Báo giá', 'order' => 'Đơn hàng / phiếu giao hàng', 'contract' => 'Hợp đồng / phụ lục',
		'minutes' => 'Biên bản', 'drawing' => 'Bản vẽ kỹ thuật', 'tech_dossier' => 'Hồ sơ kỹ thuật', 'handwritten_note' => 'Ghi chú chép tay',
		'id_card' => 'Giấy tờ tuỳ thân / giấy phép', 'admin_letter' => 'Công văn / giấy tờ hành chính', 'business_card' => 'Danh thiếp',
		'customer_form' => 'Phiếu thông tin khách', 'photo' => 'Ảnh thường', 'other' => 'Khác',
	);
	const DOC_TYPES       = array( 'invoice', 'receipt_in', 'receipt_out', 'bill', 'bank_statement', 'quote', 'order', 'contract', 'minutes', 'drawing', 'tech_dossier', 'handwritten_note', 'id_card', 'admin_letter', 'business_card', 'customer_form', 'photo', 'other' );
	const SENSITIVE_TYPES = array( 'bank_statement', 'id_card' ); // DOC-TYPES-v1 sensitive:true, in list order (D140-13)
	const DOC_KEYS        = array( 'type', 'type_conf', 'title', 'date', 'parties', 'dues', 'totals', 'fields', 'uncertain', 'sensitive', 'book' );
	const READ_KEYS       = array( 'status', 'method', 'pages', 'pages_read', 'pages_failed', 'score', 'progress' );
	const DIRECTIONS      = array( 'in', 'out', 'unknown' );
	const BOOK_STATES     = array( 'none', 'offered', 'recorded', 'declined', 'expired' );
	const BOOK_FAMILIES   = array( 'cashbook', 'debts', 'customer' );
	const READ_STATUSES   = array( 'full', 'partial' );
	const READ_METHODS    = array( 'text_layer', 'ocr', 'mixed', 'stt', 'video' );
	const DEAL_STAGES     = array( 'quote', 'order', 'contract', 'paid' );
	const DEAL_REF_RE     = '/^(CN|TC|DH)-\d{1,12}$/';
	const MAX_TITLE       = 160;
	const MAX_PARTIES     = 6;
	const MAX_DUES        = 12;
	const MAX_FIELDS      = 40;
	const MAX_FIELD_LIST  = 30;
	const MAX_FIELD_CHARS = 300;
	const MAX_UNCERTAIN   = 20;
	const MAX_REFS        = 10;
	const MAX_FAILED      = 50;
	const MAX_POINTS      = 5;
	const MAX_POINT_CHARS = 300;

	// ── normalize (pure) ─────────────────────────────────────────────────────

	/**
	 * Add the normalized `doc` / `read` / `points` of `$in` to `$out`. Strict errors (exact strings of the s140 fixture: bad_value:doc.type,
	 * unknown_field:doc.secret, too_many:points …) go to `$errors`; lax mode drops what is wrong instead. Pure.
	 */
	public static function normalize_into( array $in, array $out, bool $strict, array &$errors ): array {
		if ( array_key_exists( 'doc', $in ) ) {
			if ( is_array( $in['doc'] ) ) {
				$doc = self::doc( $in['doc'], $strict, $errors );
				if ( $doc ) {
					$out['doc'] = $doc;
				}
			} else {
				$errors[] = 'bad_value:doc';
			}
		}
		if ( array_key_exists( 'read', $in ) ) {
			if ( is_array( $in['read'] ) ) {
				$read = self::read( $in['read'], $errors );
				if ( $read ) {
					$out['read'] = $read;
				}
			} else {
				$errors[] = 'bad_value:read';
			}
		}
		if ( array_key_exists( 'points', $in ) ) {
			$list = array();
			foreach ( (array) $in['points'] as $p ) {
				if ( ! is_scalar( $p ) ) {
					continue;
				}
				$s = self::clean( (string) $p );
				if ( '' === $s ) {
					continue;
				}
				if ( mb_strlen( $s ) > self::MAX_POINT_CHARS ) {
					$errors[] = 'too_long:points';
				}
				$list[] = mb_substr( $s, 0, self::MAX_POINT_CHARS );
			}
			if ( count( $list ) > self::MAX_POINTS ) {
				$errors[] = 'too_many:points';
			}
			$out['points'] = array_slice( $list, 0, self::MAX_POINTS );
		}
		return $out;
	}

	private static function doc( array $d, bool $strict, array &$errors ): array {
		$out = array();
		foreach ( array_keys( $d ) as $k ) {
			if ( ! in_array( (string) $k, self::DOC_KEYS, true ) ) {
				$errors[] = 'unknown_field:doc.' . $k;
			}
		}
		if ( array_key_exists( 'type', $d ) ) {
			$t = (string) ( is_scalar( $d['type'] ) ? $d['type'] : '' );
			if ( in_array( $t, self::DOC_TYPES, true ) ) {
				$out['type'] = $t;
			} else {
				$errors[] = 'bad_value:doc.type';
				if ( ! $strict ) {
					$out['type'] = 'other'; // DOC-TYPES-v1: outside the list ⇒ other
				}
			}
		}
		if ( array_key_exists( 'type_conf', $d ) ) {
			if ( is_numeric( $d['type_conf'] ) ) {
				$out['type_conf'] = max( 0.0, min( 1.0, (float) $d['type_conf'] ) );
			} else {
				$errors[] = 'bad_value:doc.type_conf';
			}
		}
		if ( array_key_exists( 'title', $d ) ) {
			$s = self::clean( (string) ( is_scalar( $d['title'] ) ? $d['title'] : '' ) );
			if ( mb_strlen( $s ) > self::MAX_TITLE ) {
				$errors[] = 'too_long:doc.title';
			}
			$out['title'] = mb_substr( $s, 0, self::MAX_TITLE );
		}
		if ( array_key_exists( 'date', $d ) ) {
			if ( self::is_date( $d['date'] ) ) {
				$out['date'] = (string) $d['date'];
			} else {
				$errors[] = 'bad_value:doc.date';
			}
		}
		if ( array_key_exists( 'parties', $d ) ) {
			$list = array();
			foreach ( (array) $d['parties'] as $p ) {
				if ( ! is_array( $p ) || '' === self::clean( (string) ( is_scalar( $p['name'] ?? null ) ? $p['name'] : '' ) ) ) {
					continue;
				}
				$row = array(
					'role' => substr( (string) preg_replace( '/[^a-z_]/', '', strtolower( (string) ( $p['role'] ?? '' ) ) ), 0, 20 ) ?: 'party',
					'name' => mb_substr( self::clean( (string) $p['name'] ), 0, 120 ),
				);
				$tax = substr( (string) preg_replace( '/[^0-9-]/', '', (string) ( is_scalar( $p['tax_id'] ?? null ) ? $p['tax_id'] : '' ) ), 0, 20 );
				if ( '' !== $tax ) {
					$row['tax_id'] = $tax;
				}
				if ( (int) ( $p['contact_id'] ?? 0 ) > 0 ) {
					$row['contact_id'] = (int) $p['contact_id']; // S140-B29: only when the cell matched the contact list ≥ 0.9
				}
				$list[] = $row;
			}
			if ( count( $list ) > self::MAX_PARTIES ) {
				$errors[] = 'too_many:doc.parties';
			}
			$out['parties'] = array_slice( $list, 0, self::MAX_PARTIES );
		}
		if ( array_key_exists( 'dues', $d ) ) {
			$list = array();
			foreach ( (array) $d['dues'] as $u ) {
				if ( ! is_array( $u ) ) {
					continue;
				}
				if ( ! self::is_date( $u['date'] ?? null ) ) {
					$errors[] = 'bad_value:doc.dues.date';
					continue;
				}
				if ( ! is_numeric( $u['amount'] ?? null ) ) {
					$errors[] = 'bad_value:doc.dues.amount';
					continue;
				}
				$row = array( 'date' => (string) $u['date'], 'amount' => self::money( $u['amount'] ) );
				$l   = mb_substr( self::clean( (string) ( is_scalar( $u['label'] ?? null ) ? $u['label'] : '' ) ), 0, 60 );
				if ( '' !== $l ) {
					$row['label'] = $l;
				}
				$list[] = $row;
			}
			if ( count( $list ) > self::MAX_DUES ) {
				$errors[] = 'too_many:doc.dues';
			}
			$out['dues'] = array_slice( $list, 0, self::MAX_DUES );
		}
		if ( array_key_exists( 'totals', $d ) ) {
			$t   = is_array( $d['totals'] ) ? $d['totals'] : array();
			$row = array();
			foreach ( array_keys( $t ) as $k ) {
				if ( ! in_array( (string) $k, array( 'amount', 'currency', 'direction', 'vat' ), true ) ) {
					$errors[] = 'unknown_field:doc.totals.' . $k;
				}
			}
			foreach ( array( 'amount', 'vat' ) as $k ) {
				if ( array_key_exists( $k, $t ) ) {
					if ( is_numeric( $t[ $k ] ) ) {
						$row[ $k ] = self::money( $t[ $k ] ); // R-TWIN-SHEET: integer money, the site adds — never the model
					} else {
						$errors[] = 'bad_value:doc.totals.' . $k;
					}
				}
			}
			if ( array_key_exists( 'currency', $t ) ) {
				$row['currency'] = strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', (string) $t['currency'] ), 0, 3 ) ) ?: 'VND';
			}
			if ( array_key_exists( 'direction', $t ) ) {
				if ( in_array( (string) $t['direction'], self::DIRECTIONS, true ) ) {
					$row['direction'] = (string) $t['direction'];
				} else {
					$errors[] = 'bad_value:doc.totals.direction';
				}
			}
			if ( $row ) {
				$out['totals'] = $row;
			}
		}
		if ( array_key_exists( 'fields', $d ) ) {
			$out['fields'] = self::fields( is_array( $d['fields'] ) ? $d['fields'] : array(), $errors );
		}
		if ( array_key_exists( 'uncertain', $d ) ) {
			$list = array();
			foreach ( (array) $d['uncertain'] as $u ) {
				$s = is_scalar( $u ) ? mb_substr( self::clean( (string) $u ), 0, 60 ) : '';
				if ( '' !== $s ) {
					$list[] = $s;
				}
			}
			if ( count( $list ) > self::MAX_UNCERTAIN ) {
				$errors[] = 'too_many:doc.uncertain';
			}
			$out['uncertain'] = array_slice( $list, 0, self::MAX_UNCERTAIN );
		}
		if ( array_key_exists( 'sensitive', $d ) ) {
			$out['sensitive'] = (bool) $d['sensitive'];
		}
		if ( array_key_exists( 'book', $d ) ) {
			$book = self::book( is_array( $d['book'] ) ? $d['book'] : array(), $errors );
			if ( $book ) {
				$out['book'] = $book;
			}
		}
		return $out;
	}

	/** doc.fields: ≤ 40 keys; a value is a scalar ≤ 300 chars or a list ≤ 30 of scalars / flat objects (lines of an invoice). Pure. */
	private static function fields( array $f, array &$errors ): array {
		if ( count( $f ) > self::MAX_FIELDS ) {
			$errors[] = 'too_many:doc.fields';
		}
		$out = array();
		foreach ( array_slice( $f, 0, self::MAX_FIELDS, true ) as $k => $v ) {
			$key = substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $k ) ), 0, 40 );
			if ( '' === $key ) {
				$errors[] = 'bad_value:doc.fields';
				continue;
			}
			if ( null === $v || is_scalar( $v ) ) {
				$out[ $key ] = self::field_scalar( $v, 'doc.fields.' . $key, $errors );
				continue;
			}
			if ( ! is_array( $v ) || ( array() !== $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) ) {
				$errors[] = 'bad_value:doc.fields.' . $key; // a nested map is not a field (flat objects live inside a list only)
				continue;
			}
			if ( count( $v ) > self::MAX_FIELD_LIST ) {
				$errors[] = 'too_many:doc.fields.' . $key;
			}
			$list = array();
			foreach ( array_slice( $v, 0, self::MAX_FIELD_LIST ) as $item ) {
				if ( null === $item || is_scalar( $item ) ) {
					$list[] = self::field_scalar( $item, 'doc.fields.' . $key, $errors );
				} elseif ( is_array( $item ) ) {
					$obj = array();
					foreach ( $item as $ik => $iv ) {
						if ( null === $iv || is_scalar( $iv ) ) {
							$obj[ substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $ik ) ), 0, 40 ) ] = self::field_scalar( $iv, 'doc.fields.' . $key, $errors );
						} else {
							$errors[] = 'bad_value:doc.fields.' . $key;
						}
					}
					unset( $obj[''] );
					$list[] = $obj;
				}
			}
			$out[ $key ] = $list;
		}
		return $out;
	}

	private static function field_scalar( $v, string $name, array &$errors ) {
		if ( ! is_string( $v ) ) {
			return $v; // int · float · bool · null keep their type
		}
		$s = self::clean( $v );
		if ( mb_strlen( $s ) > self::MAX_FIELD_CHARS ) {
			$errors[] = 'too_long:' . $name;
		}
		return mb_substr( $s, 0, self::MAX_FIELD_CHARS );
	}

	private static function book( array $b, array &$errors ): array {
		$out = array();
		foreach ( array_keys( $b ) as $k ) {
			if ( ! in_array( (string) $k, array( 'state', 'family', 'refs', 'at' ), true ) ) {
				$errors[] = 'unknown_field:doc.book.' . $k;
			}
		}
		if ( array_key_exists( 'state', $b ) ) {
			if ( in_array( (string) $b['state'], self::BOOK_STATES, true ) ) {
				$out['state'] = (string) $b['state'];
			} else {
				$errors[] = 'bad_value:doc.book.state';
			}
		}
		if ( array_key_exists( 'family', $b ) ) {
			if ( in_array( (string) $b['family'], self::BOOK_FAMILIES, true ) ) {
				$out['family'] = (string) $b['family'];
			} else {
				$errors[] = 'bad_value:doc.book.family';
			}
		}
		if ( array_key_exists( 'refs', $b ) ) {
			$refs = array();
			foreach ( (array) $b['refs'] as $r ) {
				$r = strtoupper( trim( (string) ( is_scalar( $r ) ? $r : '' ) ) );
				if ( 1 === preg_match( '/^[A-Z]{2}-\d{1,12}$/', $r ) ) {
					$refs[] = $r;
				} else {
					$errors[] = 'bad_value:doc.book.refs';
				}
			}
			$refs = array_values( array_unique( $refs ) );
			if ( count( $refs ) > self::MAX_REFS ) {
				$errors[] = 'too_many:doc.book.refs';
			}
			$out['refs'] = array_slice( $refs, 0, self::MAX_REFS );
		}
		if ( array_key_exists( 'at', $b ) ) {
			$at = substr( (string) preg_replace( '/[^0-9T:Z+.-]/', '', (string) $b['at'] ), 0, 32 );
			if ( '' !== $at ) {
				$out['at'] = $at;
			}
		}
		return $out;
	}

	private static function read( array $r, array &$errors ): array {
		$out = array();
		foreach ( array_keys( $r ) as $k ) {
			if ( ! in_array( (string) $k, self::READ_KEYS, true ) ) {
				$errors[] = 'unknown_field:read.' . $k;
			}
		}
		if ( array_key_exists( 'status', $r ) ) {
			if ( in_array( (string) $r['status'], self::READ_STATUSES, true ) ) {
				$out['status'] = (string) $r['status'];
			} else {
				$errors[] = 'bad_value:read.status';
			}
		}
		if ( array_key_exists( 'method', $r ) ) {
			if ( in_array( (string) $r['method'], self::READ_METHODS, true ) ) {
				$out['method'] = (string) $r['method'];
			} else {
				$errors[] = 'bad_value:read.method';
			}
		}
		foreach ( array( 'pages', 'pages_read' ) as $k ) {
			if ( array_key_exists( $k, $r ) ) {
				if ( is_numeric( $r[ $k ] ) && (int) $r[ $k ] >= 0 ) {
					$out[ $k ] = (int) $r[ $k ];
				} else {
					$errors[] = 'bad_value:read.' . $k;
				}
			}
		}
		if ( array_key_exists( 'pages_failed', $r ) ) {
			$list = array();
			foreach ( (array) $r['pages_failed'] as $p ) {
				if ( is_numeric( $p ) && (int) $p >= 1 ) {
					$list[] = (int) $p;
				} else {
					$errors[] = 'bad_value:read.pages_failed';
				}
			}
			$list = array_values( array_unique( $list ) );
			if ( count( $list ) > self::MAX_FAILED ) {
				$errors[] = 'too_many:read.pages_failed';
			}
			$out['pages_failed'] = array_slice( $list, 0, self::MAX_FAILED );
		}
		if ( array_key_exists( 'score', $r ) ) {
			if ( is_numeric( $r['score'] ) ) {
				$out['score'] = max( 0.0, min( 1.0, (float) $r['score'] ) );
			} else {
				$errors[] = 'bad_value:read.score';
			}
		}
		if ( array_key_exists( 'progress', $r ) ) {
			$p   = is_array( $r['progress'] ) ? $r['progress'] : array();
			$row = array();
			foreach ( array_keys( $p ) as $k ) {
				if ( ! in_array( (string) $k, array( 'pages_read', 'pages_total' ), true ) ) {
					$errors[] = 'unknown_field:read.progress.' . $k;
				}
			}
			foreach ( array( 'pages_read', 'pages_total' ) as $k ) {
				if ( array_key_exists( $k, $p ) ) {
					if ( is_numeric( $p[ $k ] ) && (int) $p[ $k ] >= 0 ) {
						$row[ $k ] = (int) $p[ $k ];
					} else {
						$errors[] = 'bad_value:read.progress.' . $k;
					}
				}
			}
			if ( $row ) {
				$out['progress'] = $row;
			}
		}
		return $out;
	}

	// ── merge (pure) ─────────────────────────────────────────────────────────

	/**
	 * The `doc` to store when `$incoming` arrives over `$stored` (S140-L1): a book-only patch `{book:{…}}` changes only the book of the stored doc;
	 * a full doc replaces the stored one but keeps the stored book when it brings none. Pure.
	 */
	public static function merge_doc( $stored, array $incoming ): array {
		$stored = is_array( $stored ) ? $stored : array();
		if ( array( 'book' ) === array_keys( $incoming ) ) {
			$stored['book'] = $incoming['book'];
			return $stored;
		}
		if ( ! array_key_exists( 'book', $incoming ) && is_array( $stored['book'] ?? null ) ) {
			$incoming['book'] = $stored['book'];
		}
		return $incoming;
	}

	// ── reads of a stored meta (pure) ────────────────────────────────────────

	/** A sensitive paper (D140-13): doc.sensitive true, or a type DOC-TYPES-v1 marks sensitive. Pure. */
	public static function is_sensitive( array $meta ): bool {
		$doc = is_array( $meta['doc'] ?? null ) ? $meta['doc'] : array();
		return ! empty( $doc['sensitive'] ) || in_array( (string) ( $doc['type'] ?? '' ), self::SENSITIVE_TYPES, true );
	}

	public static function type_label( string $type ): string {
		return self::TYPE_LABELS[ $type ] ?? '';
	}

	/**
	 * What journal.find can match on, by score() part (weights stay those of doc 81 §4): title = doc.title + the type label ("hoá đơn"),
	 * about = points, card = party names + the booked codes + doc.fields values. A sensitive paper gives no party / field values. Pure.
	 *
	 * @return array{title:string,about:string,card:string}
	 */
	public static function find_parts( array $meta ): array {
		$doc   = is_array( $meta['doc'] ?? null ) ? $meta['doc'] : array();
		$title = trim( (string) ( $doc['title'] ?? '' ) . ' ' . self::type_label( (string) ( $doc['type'] ?? '' ) ) );
		$about = implode( ' ', array_map( 'strval', (array) ( $meta['points'] ?? array() ) ) );
		$card  = array();
		foreach ( (array) ( $doc['book']['refs'] ?? array() ) as $r ) {
			$card[] = (string) $r;
		}
		if ( ! self::is_sensitive( $meta ) ) {
			foreach ( (array) ( $doc['parties'] ?? array() ) as $p ) {
				$card[] = is_array( $p ) ? (string) ( $p['name'] ?? '' ) : '';
			}
			foreach ( (array) ( $doc['fields'] ?? array() ) as $v ) {
				if ( is_scalar( $v ) ) {
					$card[] = (string) $v;
				} elseif ( is_array( $v ) ) {
					foreach ( array_slice( $v, 0, 10 ) as $item ) {
						$card[] = is_scalar( $item ) ? (string) $item : ( is_array( $item ) ? implode( ' ', array_filter( $item, 'is_string' ) ) : '' );
					}
				}
			}
		}
		return array( 'title' => $title, 'about' => $about, 'card' => trim( implode( ' ', array_filter( array_map( 'trim', $card ), 'strlen' ) ) ) );
	}

	/** "Đã ghi sổ: CN-7, TC-45" (recorded) · "Chờ ghi sổ" (offered / expired — the offer stays in the menu) · '' otherwise (S140-L3). Pure. */
	public static function book_line( array $meta ): string {
		$book  = is_array( $meta['doc']['book'] ?? null ) ? $meta['doc']['book'] : array();
		$state = (string) ( $book['state'] ?? '' );
		if ( 'recorded' === $state ) {
			$refs = array_map( 'strval', (array) ( $book['refs'] ?? array() ) );
			return $refs ? 'Đã ghi sổ: ' . implode( ', ', $refs ) : 'Đã ghi sổ';
		}
		return in_array( $state, array( 'offered', 'expired' ), true ) ? 'Chờ ghi sổ' : '';
	}

	/** The entry-card@1 section of the book line (null when there is none). Pure. */
	public static function card_section( array $meta ): ?array {
		$line = self::book_line( $meta );
		if ( '' === $line ) {
			return null;
		}
		return array( 'id' => 'book', 'label' => 'Ghi sổ', 'text' => $line, 'refs' => array_values( array_map( 'strval', (array) ( $meta['doc']['book']['refs'] ?? array() ) ) ) );
	}

	/**
	 * Read progress for the "Đọc" step (S140-L6): pages_read / pages_total from read.progress, else read.pages_read / read.pages. A partial read,
	 * or a background read still on its way (pages_read < pages_total), labels the step "Đọc 10/12 trang". null when no page count is known. Pure.
	 *
	 * @return array{pages_read:int,pages_total:int,read_status:string,label?:string}|null
	 */
	public static function read_progress( array $meta ): ?array {
		$read  = is_array( $meta['read'] ?? null ) ? $meta['read'] : array();
		$prog  = is_array( $read['progress'] ?? null ) ? $read['progress'] : array();
		$total = (int) ( $prog['pages_total'] ?? ( $read['pages'] ?? 0 ) );
		$done  = (int) ( $prog['pages_read'] ?? ( $read['pages_read'] ?? 0 ) );
		if ( $total <= 0 ) {
			return null;
		}
		$status = (string) ( $read['status'] ?? '' );
		$failed = count( (array) ( $read['pages_failed'] ?? array() ) );
		$ok     = 'partial' === $status ? max( 0, min( $done, $total ) - $failed ) : min( $done, $total ); // a partial read: failed pages are not "read"
		$out    = array( 'pages_read' => $ok, 'pages_total' => $total, 'read_status' => '' !== $status ? $status : ( $done >= $total ? 'full' : 'partial' ) );
		if ( 'partial' === $status || $done < $total ) {
			$out['label'] = 'Đọc ' . $ok . '/' . $total . ' trang';
		}
		return $out;
	}

	/**
	 * The `read:` block of the vault Markdown frontmatter (md-reading@1, S140-L2): {status, method, pages, pages_failed, doc{type, title, date,
	 * parties[names], total "<amount> <currency>", dues["YYYY-MM-DD <amount>"]}} — lists of scalars only (emit_yaml). A sensitive paper keeps
	 * doc{type, title, date}. null when the meta has neither read nor doc. Pure.
	 */
	public static function vault_read( array $meta ): ?array {
		$read = is_array( $meta['read'] ?? null ) ? $meta['read'] : array();
		$doc  = is_array( $meta['doc'] ?? null ) ? $meta['doc'] : array();
		if ( ! $read && ! $doc ) {
			return null;
		}
		$out = array();
		foreach ( array( 'status', 'method' ) as $k ) {
			if ( '' !== (string) ( $read[ $k ] ?? '' ) ) {
				$out[ $k ] = (string) $read[ $k ];
			}
		}
		if ( isset( $read['pages'] ) ) {
			$out['pages'] = (int) $read['pages'];
		}
		if ( ! empty( $read['pages_failed'] ) ) {
			$out['pages_failed'] = array_values( array_map( 'intval', (array) $read['pages_failed'] ) );
		}
		$d = array();
		foreach ( array( 'type', 'title', 'date' ) as $k ) {
			if ( '' !== (string) ( $doc[ $k ] ?? '' ) ) {
				$d[ $k ] = (string) $doc[ $k ];
			}
		}
		if ( ! self::is_sensitive( $meta ) ) {
			$names = array();
			foreach ( (array) ( $doc['parties'] ?? array() ) as $p ) {
				if ( is_array( $p ) && '' !== (string) ( $p['name'] ?? '' ) ) {
					$names[] = (string) $p['name'];
				}
			}
			if ( $names ) {
				$d['parties'] = $names;
			}
			if ( isset( $doc['totals']['amount'] ) ) {
				$d['total'] = (int) $doc['totals']['amount'] . ' ' . ( (string) ( $doc['totals']['currency'] ?? '' ) ?: 'VND' );
			}
			$dues = array();
			foreach ( (array) ( $doc['dues'] ?? array() ) as $u ) {
				if ( is_array( $u ) && isset( $u['date'], $u['amount'] ) ) {
					$dues[] = (string) $u['date'] . ' ' . (int) $u['amount'];
				}
			}
			if ( $dues ) {
				$d['dues'] = $dues;
			}
		}
		if ( $d ) {
			$out['doc'] = $d;
		}
		return $out ?: null;
	}

	// ── card of a crm.* / fin.* row (S140-L7 / L8, pure) ─────────────────────

	/**
	 * Clean the card of a row before it is stored: `deal_ref` (fin.* / crm.*) ⇒ upper case, dropped unless ^(CN|TC|DH)-\d{1,12}$; crm.deal `stage`
	 * outside quote|order|contract|paid dropped, `amount` ⇒ integer; `source_doc` (crm.*) ⇒ TL-… code or dropped. Other kinds pass unchanged. Pure.
	 */
	public static function clean_card( string $kind, array $card ): array {
		$fin = 0 === strpos( $kind, 'fin.' );
		$crm = 0 === strpos( $kind, 'crm.' );
		if ( ! $fin && ! $crm ) {
			return $card;
		}
		if ( array_key_exists( 'deal_ref', $card ) ) {
			$r = strtoupper( trim( (string) ( is_scalar( $card['deal_ref'] ) ? $card['deal_ref'] : '' ) ) );
			if ( 1 === preg_match( self::DEAL_REF_RE, $r ) ) {
				$card['deal_ref'] = $r;
			} else {
				unset( $card['deal_ref'] );
			}
		}
		if ( $crm && array_key_exists( 'source_doc', $card ) ) {
			$s = strtoupper( trim( (string) ( is_scalar( $card['source_doc'] ) ? $card['source_doc'] : '' ) ) );
			if ( 1 === preg_match( '/^TL-\d{1,12}(@V\d{1,6})?$/', $s ) ) {
				$card['source_doc'] = str_replace( '@V', '@v', $s );
			} else {
				unset( $card['source_doc'] );
			}
		}
		if ( 'crm.deal' === $kind ) {
			if ( array_key_exists( 'stage', $card ) && ! in_array( (string) $card['stage'], self::DEAL_STAGES, true ) ) {
				unset( $card['stage'] );
			}
			if ( array_key_exists( 'amount', $card ) ) {
				if ( is_numeric( $card['amount'] ) ) {
					$card['amount'] = self::money( $card['amount'] );
				} else {
					unset( $card['amount'] );
				}
			}
		}
		return $card;
	}

	// ── helpers (pure) ───────────────────────────────────────────────────────

	private static function clean( string $s ): string {
		return trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s ) );
	}

	private static function is_date( $v ): bool {
		return is_string( $v ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function money( $v ): int {
		return (int) round( (float) $v );
	}
}
