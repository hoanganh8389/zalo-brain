<?php
/**
 * BizCity_Journal_Entry_Meta — `entry-meta@1` (PHASE-0.133 doc 81, R-ENTRY-META EM-1…10): one readable, searchable card for every journal row.
 *
 *   metadata.meta        about · caption · ocr{text ≤ 4000, chars, truncated, lang, pages} · keywords[] · topics[] · entities[] · people[] · amounts[]
 *                        · file (site) · source (site) · by{actor, model, at, op, confidence} · status · error · locked[] · rev
 *   find_text (column)   title + about/body + caption + ocr + keywords + topics + entities.norm + people.name + visible card fields,
 *                        stored twice: as written and folded (lower case, no diacritics, đ→d) — built by CODE here, never sent by anyone
 *   view=card            the one read shape every UI renders (sections with Vietnamese labels from the kind's `card_view`; internal keys hidden)
 *   metadata.candidates  values extracted from a source (amount · date · party…) that are PROPOSED, never booked until a person decides (SF-5)
 *
 * The cell extracts (vision / OCR / summary), the site stores (EM-2 — no model call in PHP). Everything here is pure (unit-tested without WordPress)
 * except set(), which writes through BizCity_Journal_Index::file().
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-24 05:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma / S133-K3 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Entry_Meta', false ) ) {
	return;
}

final class BizCity_Journal_Entry_Meta {

	const CONTRACT      = 'entry-meta@1.0.0';
	const STATUSES      = array( 'done', 'pending', 'failed', 'skipped' );
	const ENTITY_TYPES  = array( 'person', 'org', 'place', 'date', 'amount', 'product', 'topic', 'other' );
	const PEOPLE_MATCH  = array( 'verified', 'suggested', 'text' );
	const SOURCES       = array( 'cell_auto', 'cell_tool', 'owner_edit', 'site_code' );
	const MAX_ABOUT     = 600;
	const MAX_CAPTION   = 500;
	const MAX_OCR       = 4000;
	const MAX_KEYWORDS  = 20;
	const MAX_TOPICS    = 5;
	const MAX_ENTITIES  = 30;
	const MAX_PEOPLE    = 10;
	const MAX_AMOUNTS   = 10;
	const MAX_FIND_TEXT = 12000;
	const LOCKABLE      = array( 'about', 'caption', 'keywords', 'topics', 'people', 'entities', 'amounts', 'title' );
	/** Keys a card never shows (EM-5): internal bookkeeping of writers. */
	const HIDDEN_CARD_KEYS = array( 'title', 'summary', 'as_of', 'seen', 'session', 'summary_by', 'summary_at', 'confidence', 'prev_card', 'mirror', 'items', 'messages_raw', 'version_family', 'version_parent' );
	/** Honorifics / kinship words dropped when a name is normalized ("ông Phương Nam" ⇒ "phuong nam"). */
	const HONORIFICS = array( 'ong', 'ba', 'anh', 'chi', 'co', 'chu', 'bac', 'em', 'cau', 'mo', 'di', 'thim', 'mr', 'mrs', 'ms', 'miss', 'dr', 'sir', 'madam', 'sep', 'boss', 'a', 'c' );
	/** Common label dictionary when a kind has no `card_view` (EM-5: never a raw key). */
	const LABELS = array(
		'count' => 'Số lượng', 'messages' => 'Số tin', 'amount' => 'Số tiền', 'currency' => 'Tiền tệ', 'party' => 'Người / đơn vị', 'direction' => 'Chiều',
		'due_date' => 'Hạn', 'note' => 'Ghi chú', 'doc_date' => 'Ngày', 'type' => 'Loại', 'status' => 'Trạng thái', 'channel' => 'Kênh', 'published_at' => 'Đăng lúc',
		'url_host' => 'Trang', 'instruction' => 'Lời dặn', 'scope' => 'Phạm vi', 'requested_by' => 'Người nhờ', 'skill' => 'Kỹ năng', 'steps_total' => 'Số bước',
		'steps_done' => 'Đã xong', 'due_at' => 'Hạn', 'evidence_id' => 'Mã bằng chứng', 'method' => 'Cách xác minh', 'ref_kind' => 'Loại', 'page' => 'Trang',
		'quote' => 'Trích', 'provider' => 'Nền tảng', 'provider_native_id' => 'Mã bài trên nền tảng', 'provider_account_ref' => 'Tài khoản / trang',
		'canonical_url' => 'Đường dẫn', 'verification' => 'Xác minh', 'recipient' => 'Người nhận', 'sent_at' => 'Gửi lúc', 'version' => 'Phiên bản',
		'total' => 'Tổng', 'qty' => 'Số lượng', 'price' => 'Đơn giá', 'customer' => 'Khách', 'phone' => 'Điện thoại', 'email' => 'Email', 'form' => 'Biểu mẫu',
		'leads' => 'Số khách', 'sheet_key' => 'Mã sổ', 'display_name' => 'Tên sổ', 'origin' => 'Nguồn', 'schema_version' => 'Bản cấu trúc', 'kind' => 'Loại',
	);

	// ── entry-meta@1 normalize (pure) ───────────────────────────────────────

	/**
	 * Normalize one meta block. $strict ⇒ unknown top-level keys or over-limit values are an error (journal.meta.set); otherwise they are trimmed.
	 *
	 * @return array{meta:array,errors:string[]}
	 */
	public static function normalize( array $in, bool $strict = false ): array {
		$errors = array();
		$known  = array( 'contract', 'about', 'caption', 'ocr', 'keywords', 'topics', 'entities', 'people', 'amounts', 'file', 'source', 'by', 'status', 'error', 'locked', 'rev', 'title', 'folder' );
		foreach ( array_keys( $in ) as $k ) {
			if ( ! in_array( (string) $k, $known, true ) ) {
				$errors[] = 'unknown_field:' . $k;
			}
		}
		$text = static function ( $v, int $max, string $name ) use ( &$errors, $strict ): string {
			$s = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v ) );
			if ( $strict && mb_strlen( $s ) > $max ) {
				$errors[] = 'too_long:' . $name;
			}
			return mb_substr( $s, 0, $max );
		};
		$out = array( 'contract' => self::CONTRACT );
		if ( array_key_exists( 'about', $in ) ) {
			$out['about'] = $text( $in['about'], self::MAX_ABOUT, 'about' );
		}
		if ( array_key_exists( 'caption', $in ) ) {
			$out['caption'] = $text( $in['caption'], self::MAX_CAPTION, 'caption' );
		}
		if ( array_key_exists( 'title', $in ) ) {
			$out['title'] = $text( $in['title'], 200, 'title' );
		}
		if ( array_key_exists( 'folder', $in ) ) { // the digital folder (twin-vault D134-10): "30" or "30/slug", else dropped
			$fl = trim( (string) $in['folder'] );
			if ( '' === $fl || 1 === preg_match( '#^[0-9]{2}(/[a-z0-9-]{1,40})?$#', $fl ) ) {
				$out['folder'] = $fl;
			} elseif ( $strict ) {
				$errors[] = 'bad_value:folder';
			}
		}
		if ( is_array( $in['ocr'] ?? null ) ) {
			$o    = $in['ocr'];
			$full = trim( (string) ( $o['text'] ?? '' ) );
			$out['ocr'] = array(
				'text'      => mb_substr( $full, 0, self::MAX_OCR ),
				'chars'     => max( (int) ( $o['chars'] ?? 0 ), mb_strlen( $full ) ),
				'truncated' => ! empty( $o['truncated'] ) || mb_strlen( $full ) > self::MAX_OCR,
				'lang'      => substr( preg_replace( '/[^a-z-]/', '', strtolower( (string) ( $o['lang'] ?? '' ) ) ), 0, 8 ),
				'pages'     => max( 0, (int) ( $o['pages'] ?? 0 ) ),
			);
		}
		foreach ( array( 'keywords' => self::MAX_KEYWORDS, 'topics' => self::MAX_TOPICS ) as $k => $max ) {
			if ( array_key_exists( $k, $in ) ) {
				$list = array();
				foreach ( (array) $in[ $k ] as $w ) {
					$w = mb_substr( trim( (string) $w ), 0, 40 ); // EM: keep diacritics (find_text holds the folded copy)
					if ( '' !== $w && ! in_array( mb_strtolower( $w ), array_map( 'mb_strtolower', $list ), true ) ) {
						$list[] = $w;
					}
				}
				if ( $strict && count( $list ) > $max ) {
					$errors[] = 'too_many:' . $k;
				}
				$out[ $k ] = array_slice( $list, 0, $max );
			}
		}
		if ( array_key_exists( 'entities', $in ) ) {
			$list = array();
			foreach ( (array) $in['entities'] as $e ) {
				if ( ! is_array( $e ) || '' === trim( (string) ( $e['text'] ?? '' ) ) ) {
					continue;
				}
				$type = in_array( (string) ( $e['type'] ?? '' ), self::ENTITY_TYPES, true ) ? (string) $e['type'] : 'other';
				$t    = mb_substr( trim( (string) $e['text'] ), 0, 120 );
				$row  = array( 'type' => $type, 'text' => $t, 'norm' => 'person' === $type ? self::person_norm( $t ) : self::fold( $t ) );
				if ( isset( $e['value'] ) && is_scalar( $e['value'] ) ) {
					$row['value'] = mb_substr( (string) $e['value'], 0, 60 );
				}
				$list[] = $row;
			}
			if ( $strict && count( $list ) > self::MAX_ENTITIES ) {
				$errors[] = 'too_many:entities';
			}
			$out['entities'] = array_slice( $list, 0, self::MAX_ENTITIES );
		}
		if ( array_key_exists( 'people', $in ) ) {
			$list = array();
			foreach ( (array) $in['people'] as $p ) {
				if ( ! is_array( $p ) || '' === trim( (string) ( $p['name'] ?? '' ) ) ) {
					continue;
				}
				$ref   = strtolower( trim( (string) ( $p['contact_ref'] ?? '' ) ) );
				$match = in_array( (string) ( $p['match'] ?? '' ), self::PEOPLE_MATCH, true ) ? (string) $p['match'] : 'text';
				$row   = array(
					'name' => mb_substr( trim( (string) $p['name'] ), 0, 80 ),
					'norm' => self::person_norm( (string) $p['name'] ),
					'role' => substr( preg_replace( '/[^a-z_]/', '', strtolower( (string) ( $p['role'] ?? 'mentioned' ) ) ), 0, 20 ) ?: 'mentioned',
				);
				// EM-6 / R-CID-6/11: a contact ref only with evidence (verified | suggested), and only as a hashed ref — never a raw uid / phone
				if ( '' !== $ref && 'text' !== $match && 1 === preg_match( '/^(ct_[0-9a-f]{4,32}|[hp]:[0-9a-f]{16}|contact:\d{1,12})$/', $ref ) ) {
					$row['contact_ref'] = $ref;
					$row['match']       = $match;
				} else {
					$row['match'] = 'text';
				}
				$list[] = $row;
			}
			if ( $strict && count( $list ) > self::MAX_PEOPLE ) {
				$errors[] = 'too_many:people';
			}
			$out['people'] = array_slice( $list, 0, self::MAX_PEOPLE );
		}
		if ( array_key_exists( 'amounts', $in ) ) {
			$list = array();
			foreach ( (array) $in['amounts'] as $a ) {
				if ( ! is_array( $a ) || ! is_numeric( $a['value'] ?? null ) ) {
					continue;
				}
				$list[] = array(
					'value'    => (int) round( (float) $a['value'] ), // R-TWIN-SHEET: integer money, the site adds — never the model
					'currency' => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $a['currency'] ?? 'VND' ) ), 0, 3 ) ) ?: 'VND',
					'label'    => mb_substr( trim( (string) ( $a['label'] ?? '' ) ), 0, 40 ),
				);
			}
			$out['amounts'] = array_slice( $list, 0, self::MAX_AMOUNTS );
		}
		if ( is_array( $in['by'] ?? null ) ) {
			$b = $in['by'];
			$out['by'] = array(
				'actor'      => in_array( (string) ( $b['actor'] ?? '' ), array( 'cell', 'site', 'owner', 'staff' ), true ) ? (string) $b['actor'] : 'cell',
				'model'      => substr( preg_replace( '/[^A-Za-z0-9_.:\/-]/', '', (string) ( $b['model'] ?? '' ) ), 0, 80 ),
				'at'         => (string) ( $b['at'] ?? '' ),
				'op'         => substr( preg_replace( '/[^a-z0-9_.]/', '', strtolower( (string) ( $b['op'] ?? '' ) ) ), 0, 40 ),
				'confidence' => isset( $b['confidence'] ) ? max( 0.0, min( 1.0, (float) $b['confidence'] ) ) : null,
			);
		}
		if ( array_key_exists( 'status', $in ) ) {
			$out['status'] = in_array( (string) $in['status'], self::STATUSES, true ) ? (string) $in['status'] : 'done';
		}
		if ( is_array( $in['error'] ?? null ) ) {
			$e = $in['error'];
			$out['error'] = array(
				'code'      => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $e['code'] ?? 'entry_meta_failed' ) ) ), 0, 40 ),
				'message'   => mb_substr( (string) ( $e['message'] ?? '' ), 0, 200 ),
				'hint'      => mb_substr( (string) ( $e['hint'] ?? '' ), 0, 200 ),
				'help_code' => 'entry_meta',
			);
		}
		if ( array_key_exists( 'locked', $in ) ) {
			$out['locked'] = array_values( array_intersect( self::LOCKABLE, array_map( 'strval', (array) $in['locked'] ) ) );
		}
		return array( 'meta' => $out, 'errors' => $strict ? $errors : array() );
	}

	/**
	 * Merge an incoming meta into the stored one (journal.meta.set). Fields the owner locked are kept unless the writer IS the owner; an owner edit
	 * locks the fields it changed. `keywords` / `entities` / `people` / `amounts` replace the whole array (doc 81 §5). Pure.
	 */
	public static function merge( array $stored, array $incoming, string $source, bool $merge = true ): array {
		$locked = array_values( array_intersect( self::LOCKABLE, array_map( 'strval', (array) ( $stored['locked'] ?? array() ) ) ) );
		$base   = $merge ? $stored : array_intersect_key( $stored, array_flip( array( 'file', 'source', 'locked', 'rev' ) ) );
		foreach ( $incoming as $k => $v ) {
			if ( in_array( $k, array( 'file', 'source', 'rev', 'contract', 'locked' ), true ) ) {
				continue; // site-owned
			}
			if ( 'owner_edit' !== $source && in_array( $k, $locked, true ) ) {
				continue; // EM-4: the next automatic pass never overwrites what a person fixed
			}
			$base[ $k ] = $v;
			if ( 'owner_edit' === $source && in_array( $k, self::LOCKABLE, true ) && ! in_array( $k, $locked, true ) ) {
				$locked[] = $k;
			}
		}
		$base['contract'] = self::CONTRACT;
		$base['locked']   = $locked;
		$base['rev']      = (int) ( $stored['rev'] ?? 0 ) + 1;
		if ( ! isset( $base['status'] ) ) {
			$base['status'] = 'done';
		}
		return $base;
	}

	// ── the one write door (EM-4, journal.meta.set) ─────────────────────────

	/**
	 * Write metadata.meta of ONE row of the owner. `$opt` = {merge?: bool (default true), expected_rev?: int, source?: cell_auto|cell_tool|owner_edit|site_code}.
	 * Strict shape (unknown field / over limit ⇒ entry_meta_invalid, 400, four-field error); a stale expected_rev ⇒ entry_meta_conflict (409); fields the
	 * owner locked are kept on automatic passes. find_text, the pack and the card follow through BizCity_Journal_Index::file().
	 *
	 * @return array|WP_Error journal-entry@1 (with `meta`)
	 */
	public static function set( int $owner, array $target, array $incoming, array $opt = array() ) {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng trên site này.' );
		}
		$row = '' !== trim( (string) ( $target['ref'] ?? '' ) ) ? BizCity_Journal_Index::find_by_ref( (string) $target['ref'], $owner ) : BizCity_Journal_Index::get( (int) ( $target['entry_id'] ?? 0 ), $owner );
		if ( ! $row ) {
			return new WP_Error( 'journal_not_found', 'Không có dòng này trong sổ của chủ.', array( 'status' => 404 ) );
		}
		$n = self::normalize( $incoming, true );
		if ( $n['errors'] ) {
			return new WP_Error( 'entry_meta_invalid', 'Thông tin của dòng chưa đúng hình dạng: ' . implode( ', ', $n['errors'] ) . '.', array( 'status' => 400, 'fields' => $n['errors'], 'hint' => 'Xem entry-meta@1 (PHASE-0.133 doc 81 §2): bỏ trường lạ, cắt bớt phần quá dài.', 'help_code' => 'entry_meta' ) );
		}
		$stored = is_array( $row['meta'] ?? null ) ? $row['meta'] : array();
		$expect = (int) ( $opt['expected_rev'] ?? 0 );
		if ( $expect > 0 && $expect !== (int) ( $stored['rev'] ?? 0 ) ) {
			return new WP_Error( 'entry_meta_conflict', 'Thông tin của dòng vừa được sửa ở nơi khác.', array( 'status' => 409, 'rev' => (int) ( $stored['rev'] ?? 0 ), 'hint' => 'Tải lại thẻ rồi sửa lại.', 'help_code' => 'entry_meta' ) );
		}
		$source = in_array( (string) ( $opt['source'] ?? '' ), self::SOURCES, true ) ? (string) $opt['source'] : 'cell_tool';
		$meta   = self::merge( $stored, $n['meta'], $source, false !== ( $opt['merge'] ?? true ) );
		if ( ! isset( $n['meta']['status'] ) && ( isset( $n['meta']['about'] ) || isset( $n['meta']['ocr'] ) || isset( $n['meta']['keywords'] ) ) ) {
			$meta['status'] = 'done';
			unset( $meta['error'] );
		}
		return BizCity_Journal_Index::file( (int) $row['entry_id'], $owner, array( 'meta' => $meta ) );
	}

	// ── text folding (pure) ─────────────────────────────────────────────────

	/** Lower case, NFC, Vietnamese diacritics removed (đ→d), punctuation ⇒ space, single spaces. Pure. */
	public static function fold( string $s ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_D );
			$s = false === $n ? $s : $n;
			$s = (string) preg_replace( '/\p{Mn}+/u', '', $s );
		} else {
			$s = strtr( $s, self::fallback_map() );
		}
		$s = str_replace( array( 'đ', 'Đ' ), 'd', $s );
		$s = mb_strtolower( $s );
		$s = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/** A person / organisation name as a key: folded, honorifics dropped from the front ("ông Phương Nam" ⇒ "phuong nam"). Pure. */
	public static function person_norm( string $name ): string {
		$words = explode( ' ', self::fold( $name ) );
		while ( count( $words ) > 1 && in_array( $words[0], self::HONORIFICS, true ) ) {
			array_shift( $words );
		}
		return implode( ' ', array_filter( $words, 'strlen' ) );
	}

	/** Search terms of a free query, folded and with honorifics dropped when it looks like a name. Pure. */
	public static function query_terms( string $q, int $max = 8 ): array {
		$out = array();
		foreach ( preg_split( '/[,;|]+/u', $q ) ?: array() as $chunk ) {
			$f = self::fold( (string) $chunk );
			if ( '' !== $f && ! in_array( $f, $out, true ) ) {
				$out[] = mb_substr( $f, 0, 60 );
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * `find_text` (doc 81 §3): what can be searched about a row, as written and folded, " | " between parts. Built by code from the row + meta. Pure.
	 *
	 * @param array $entry journal-entry@1 (title, summary, kind, card)
	 * @param array $meta  entry-meta@1
	 */
	public static function find_text( array $entry, array $meta, array $card_fields = array() ): string {
		$parts = array(
			(string) ( $entry['title'] ?? '' ),
			(string) ( $meta['title'] ?? '' ),
			(string) ( $meta['about'] ?? '' ),
			(string) ( $entry['summary'] ?? '' ),
			(string) ( $meta['caption'] ?? '' ),
			mb_substr( (string) ( $meta['ocr']['text'] ?? '' ), 0, self::MAX_OCR ),
			implode( ' ', array_map( 'strval', (array) ( $meta['keywords'] ?? array() ) ) ),
			implode( ' ', array_map( 'strval', (array) ( $meta['topics'] ?? array() ) ) ),
		);
		foreach ( (array) ( $meta['entities'] ?? array() ) as $e ) {
			$parts[] = is_array( $e ) ? (string) ( $e['text'] ?? '' ) : '';
		}
		foreach ( (array) ( $meta['people'] ?? array() ) as $p ) {
			$parts[] = is_array( $p ) ? (string) ( $p['name'] ?? '' ) . ' ' . (string) ( $p['norm'] ?? '' ) : '';
		}
		$card = is_array( $entry['card'] ?? null ) ? $entry['card'] : array();
		foreach ( $card_fields ?: array_keys( $card ) as $k ) {
			$v = $card[ $k ] ?? null;
			if ( is_scalar( $v ) && ! in_array( (string) $k, self::HIDDEN_CARD_KEYS, true ) ) {
				$parts[] = (string) $v;
			}
		}
		$raw = trim( (string) preg_replace( '/\s+/u', ' ', implode( ' | ', array_filter( array_map( 'trim', $parts ), 'strlen' ) ) ) );
		if ( '' === $raw ) {
			return '';
		}
		$text = $raw . ' || ' . self::fold( $raw );
		return mb_substr( $text, 0, self::MAX_FIND_TEXT );
	}

	/**
	 * Score a row against a query (journal.find v2, doc 81 §4): which parts matched (`why`) and a score. `$terms` are folded phrases; `$match` all|any.
	 * Returns null when the row does not match. Pure.
	 */
	public static function score( array $entry, array $terms, string $match = 'all', array $person = array() ): ?array {
		$meta  = is_array( $entry['meta'] ?? null ) ? $entry['meta'] : array();
		$parts = array(
			'title'    => self::fold( (string) ( $entry['title'] ?? '' ) . ' ' . (string) ( $meta['title'] ?? '' ) ),
			'about'    => self::fold( (string) ( $meta['about'] ?? '' ) . ' ' . (string) ( $entry['summary'] ?? '' ) . ' ' . (string) ( $meta['caption'] ?? '' ) ),
			'ocr'      => self::fold( (string) ( $meta['ocr']['text'] ?? '' ) ),
			'keywords' => self::fold( implode( ' ', array_map( 'strval', array_merge( (array) ( $meta['keywords'] ?? array() ), (array) ( $meta['topics'] ?? array() ) ) ) ) ),
			'person'   => self::fold( implode( ' ', array_map( static function ( $p ) { return is_array( $p ) ? (string) ( $p['norm'] ?? '' ) : ''; }, (array) ( $meta['people'] ?? array() ) ) ) . ' ' . implode( ' ', array_map( static function ( $e ) { return is_array( $e ) ? (string) ( $e['norm'] ?? '' ) : ''; }, (array) ( $meta['entities'] ?? array() ) ) ) ),
			'card'     => self::fold( implode( ' ', array_filter( array_map( static function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; }, is_array( $entry['card'] ?? null ) ? $entry['card'] : array() ) ) ) ),
		);
		$weights = array( 'title' => 4, 'keywords' => 3, 'person' => 3, 'about' => 2, 'ocr' => 2, 'card' => 1 );
		$why     = array();
		$score   = 0;
		$hit     = 0;
		foreach ( $terms as $t ) {
			$t = self::fold( (string) $t );
			if ( '' === $t ) {
				continue;
			}
			$found = false;
			foreach ( $parts as $name => $text ) {
				if ( '' !== $text && false !== strpos( ' ' . $text . ' ', $t ) ) {
					$score += $weights[ $name ];
					$found  = true;
					if ( ! in_array( $name, $why, true ) ) {
						$why[] = $name;
					}
				}
			}
			$hit += $found ? 1 : 0;
			if ( ! $found && 'all' === $match ) {
				return null;
			}
		}
		if ( $terms && 0 === $hit ) {
			return null;
		}
		if ( $person ) {
			$want = self::person_norm( (string) ( $person['name'] ?? '' ) );
			$ref  = strtolower( (string) ( $person['contact_ref'] ?? '' ) );
			$ok   = false;
			foreach ( (array) ( $meta['people'] ?? array() ) as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				if ( ( '' !== $ref && $ref === (string) ( $p['contact_ref'] ?? '' ) ) || ( '' !== $want && ( (string) ( $p['norm'] ?? '' ) === $want || false !== strpos( ' ' . (string) ( $p['norm'] ?? '' ) . ' ', ' ' . $want . ' ' ) ) ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok && '' !== $want ) {
				$party = self::person_norm( (string) ( $entry['card']['party'] ?? '' ) );
				$ok    = '' !== $party && ( $party === $want || false !== strpos( ' ' . $party . ' ', ' ' . $want . ' ' ) );
			}
			if ( ! $ok ) {
				return null;
			}
			$score += 5;
			$why[]  = 'person';
		}
		return array( 'score' => $score, 'why' => array_values( array_unique( $why ) ) );
	}

	/** The row has the parts asked for (`has: ["ocr","about","people"]`). Pure. */
	public static function has( array $entry, array $want ): bool {
		$meta = is_array( $entry['meta'] ?? null ) ? $entry['meta'] : array();
		foreach ( $want as $w ) {
			$ok = 'ocr' === $w ? '' !== (string) ( $meta['ocr']['text'] ?? '' )
				: ( 'about' === $w ? '' !== (string) ( $meta['about'] ?? '' )
				: ( 'people' === $w ? ! empty( $meta['people'] ) : ( 'keywords' === $w ? ! empty( $meta['keywords'] ) : true ) ) );
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	// ── view=card (pure) ────────────────────────────────────────────────────

	/**
	 * The one read shape (doc 81 §7). `$kind_def` = the kind's declaration (label, card_fields, card_view?, hide?); `$preview_url` signed by the caller
	 * only when the principal may download. Pure.
	 */
	public static function card( array $entry, array $kind_def = array(), string $preview_url = '' ): array {
		$meta     = is_array( $entry['meta'] ?? null ) ? $entry['meta'] : array();
		$card     = is_array( $entry['card'] ?? null ) ? $entry['card'] : array();
		$orig     = is_array( $entry['original'] ?? null ) ? $entry['original'] : array();
		$sections = array();
		$about    = (string) ( $meta['about'] ?? '' );
		if ( '' === $about ) {
			$about = (string) ( $entry['summary'] ?? '' );
		}
		if ( '' !== $about ) {
			$sections[] = array( 'id' => 'about', 'label' => 'Nội dung', 'text' => $about );
		}
		if ( '' !== (string) ( $meta['caption'] ?? '' ) ) {
			$sections[] = array( 'id' => 'caption', 'label' => 'Lời nhắn kèm', 'text' => (string) $meta['caption'] );
		}
		if ( '' !== (string) ( $meta['ocr']['text'] ?? '' ) ) {
			$sections[] = array( 'id' => 'ocr', 'label' => self::is_image( $entry ) ? 'Chữ trong ảnh' : 'Chữ đọc được', 'text' => (string) $meta['ocr']['text'], 'more' => ! empty( $meta['ocr']['truncated'] ) );
		}
		$rows = self::field_rows( $card, $kind_def );
		foreach ( (array) ( $meta['amounts'] ?? array() ) as $a ) {
			if ( is_array( $a ) ) {
				$rows[] = array( 'label' => '' !== (string) ( $a['label'] ?? '' ) ? 'Số tiền (' . $a['label'] . ')' : 'Số tiền', 'value' => self::money( (int) $a['value'], (string) ( $a['currency'] ?? 'VND' ) ) );
			}
		}
		if ( $rows ) {
			$sections[] = array( 'id' => 'fields', 'label' => 'Thông tin', 'rows' => $rows );
		}
		$people = array();
		foreach ( (array) ( $meta['people'] ?? array() ) as $p ) {
			if ( is_array( $p ) ) {
				$people[] = array( 'name' => (string) $p['name'], 'match' => (string) ( $p['match'] ?? 'text' ), 'link' => isset( $p['contact_ref'] ) ? 'crm:' . $p['contact_ref'] : '' );
			}
		}
		if ( $people ) {
			$sections[] = array( 'id' => 'people', 'label' => 'Người / đơn vị', 'people' => $people );
		}
		$chips = array_merge( (array) ( $meta['keywords'] ?? array() ), (array) ( $meta['topics'] ?? array() ) );
		if ( $chips ) {
			$sections[] = array( 'id' => 'keywords', 'label' => 'Từ khoá', 'chips' => array_values( array_unique( array_map( 'strval', $chips ) ) ) );
		}
		$src = array();
		$surface = (string) ( $entry['origin']['surface'] ?? '' );
		if ( '' !== $surface ) {
			$src[] = array( 'label' => 'Kênh', 'value' => self::surface_label( $surface ) );
		}
		if ( is_array( $entry['group'] ?? null ) && '' !== (string) ( $entry['group']['name'] ?? '' ) ) {
			$src[] = array( 'label' => 'Nhóm', 'value' => (string) $entry['group']['name'] );
			if ( '' !== (string) ( $entry['group']['sender_label'] ?? '' ) ) {
				$src[] = array( 'label' => 'Người gửi', 'value' => (string) $entry['group']['sender_label'] );
			}
		}
		if ( '' !== (string) ( $entry['created_at'] ?? '' ) ) {
			$src[] = array( 'label' => 'Lưu lúc', 'value' => (string) $entry['created_at'] );
		}
		if ( $src ) {
			$sections[] = array( 'id' => 'source', 'label' => 'Nguồn', 'rows' => $src );
		}
		$file = array();
		if ( '' !== (string) ( $orig['mime'] ?? '' ) ) {
			$file[] = array( 'label' => 'Loại', 'value' => self::mime_label( (string) $orig['mime'] ) );
		}
		if ( (int) ( $orig['size'] ?? 0 ) > 0 ) {
			$file[] = array( 'label' => 'Cỡ', 'value' => self::bytes( (int) $orig['size'] ) );
		}
		if ( (int) ( $meta['ocr']['pages'] ?? 0 ) > 0 ) {
			$file[] = array( 'label' => 'Số trang', 'value' => (string) (int) $meta['ocr']['pages'] );
		}
		if ( (int) ( $entry['version']['n'] ?? 1 ) > 1 ) {
			$file[] = array( 'label' => 'Phiên bản', 'value' => 'bản ' . (int) $entry['version']['n'] );
		}
		if ( $file ) {
			$sections[] = array( 'id' => 'file', 'label' => 'Tệp', 'rows' => $file );
		}
		$edges = array();
		if ( is_array( $entry['group'] ?? null ) && '' !== (string) ( $entry['group']['gid'] ?? '' ) ) {
			$edges[] = array( 'rel' => 'in_group', 'ref' => 'grp-' . $entry['group']['gid'], 'label' => (string) ( $entry['group']['name'] ?? 'Nhóm' ) );
		}
		if ( '' !== (string) ( $entry['version']['root_ref'] ?? '' ) ) {
			$edges[] = array( 'rel' => 'version_of', 'ref' => (string) $entry['version']['root_ref'], 'label' => 'Bản gốc ' . $entry['version']['root_ref'] );
		}
		if ( '' !== (string) ( $entry['job']['job_ref'] ?? '' ) ) {
			$edges[] = array( 'rel' => 'job', 'ref' => (string) $entry['job']['job_ref'], 'label' => 'Việc ' . $entry['job']['job_ref'] );
		}
		foreach ( (array) ( $entry['resource']['links']['sheet_refs'] ?? array() ) as $s ) {
			$edges[] = array( 'rel' => 'writes_sheet', 'ref' => (string) $s, 'label' => 'Dòng sổ ' . $s );
		}
		if ( $edges ) {
			$sections[] = array( 'id' => 'links', 'label' => 'Liên kết', 'edges' => $edges );
		}
		$status = (string) ( $meta['status'] ?? '' );
		if ( '' === $status ) {
			$status = '' !== $about || '' !== (string) ( $meta['ocr']['text'] ?? '' ) ? 'done' : ( self::is_image( $entry ) || (int) ( $orig['attachment_id'] ?? 0 ) > 0 ? 'none' : 'done' );
		}
		$out = array(
			'contract'    => 'entry-card@1.0.0',
			'ref'         => (string) ( $entry['ref_code'] ?? '' ),
			'entry_id'    => (int) ( $entry['entry_id'] ?? 0 ),
			'kind'        => (string) ( $entry['kind'] ?? '' ),
			'kind_label'  => (string) ( $kind_def['label'] ?? ( $entry['kind'] ?? '' ) ),
			'title'       => '' !== (string) ( $meta['title'] ?? '' ) ? (string) $meta['title'] : (string) ( $entry['title'] ?? '' ),
			'state'       => (string) ( $entry['state'] ?? '' ),
			'sections'    => $sections,
			'meta_status' => $status,
			'meta_error'  => is_array( $meta['error'] ?? null ) ? $meta['error'] : null,
			'learning'    => array( 'level' => (string) ( $entry['tier'] ?? '' ), 'label' => self::tier_label( (string) ( $entry['tier'] ?? '' ), '' !== $about || '' !== (string) ( $meta['ocr']['text'] ?? '' ) ) ),
			'candidates'  => array_values( array_filter( (array) ( $entry['candidates'] ?? array() ), static function ( $c ) { return is_array( $c ) && 'proposed' === (string) ( $c['status'] ?? '' ); } ) ),
			'source_ref'  => (string) ( $entry['resource']['source_ref'] ?? '' ),
			'source_uuid' => (string) ( $entry['resource']['source_uuid'] ?? '' ), // [2026-10-24 06:21 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 — the Source Drawer lists the source's other rows / evidence by it
			'meta_at'     => (string) ( $meta['by']['at'] ?? '' ),
			'actions'     => array( 'download', 'learn', 'pin', 'edit_meta', 'reextract' ),
			'locked'      => array_values( (array) ( $meta['locked'] ?? array() ) ),
			'rev'         => (int) ( $entry['rev'] ?? 1 ),
		);
		// [2026-10-25 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.137 S137-B2 (D137-1) — the card carries the four-step pipeline of the row (level left to brain-map: null here)
		if ( class_exists( 'BizCity_Journal_Pipeline' ) ) {
			$out['pipeline'] = BizCity_Journal_Pipeline::of( $entry, null );
		}
		if ( '' !== $preview_url && self::is_image( $entry ) ) {
			$out['preview'] = array( 'type' => 'image', 'url' => $preview_url );
		}
		return $out;
	}

	/** Card fields as labelled rows (card_view first, else card_fields with the common dictionary; internal keys hidden). Pure. */
	public static function field_rows( array $card, array $kind_def ): array {
		$rows = array();
		$hide = array_merge( self::HIDDEN_CARD_KEYS, array_map( 'strval', (array) ( $kind_def['hide'] ?? array() ) ) );
		$view = is_array( $kind_def['card_view'] ?? null ) ? $kind_def['card_view'] : array();
		if ( $view ) {
			foreach ( $view as $f ) {
				if ( ! is_array( $f ) || ! array_key_exists( (string) ( $f['key'] ?? '' ), $card ) ) {
					continue;
				}
				$v = self::format_value( $card[ $f['key'] ], $f, $card );
				if ( '' !== $v ) {
					$rows[] = array( 'label' => (string) ( $f['label'] ?? $f['key'] ), 'value' => $v );
				}
			}
			return $rows;
		}
		$keys = (array) ( $kind_def['card_fields'] ?? array() ) ?: array_keys( $card );
		foreach ( $keys as $k ) {
			$k = (string) $k;
			if ( in_array( $k, $hide, true ) || ! array_key_exists( $k, $card ) || ! is_scalar( $card[ $k ] ) || '' === (string) $card[ $k ] ) {
				continue;
			}
			$rows[] = array( 'label' => self::LABELS[ $k ] ?? self::humanize( $k ), 'value' => (string) $card[ $k ] );
		}
		return $rows;
	}

	/** One value by its card_view type (text · long_text · money · number · date · datetime · enum · person · list · link · count). Pure. */
	public static function format_value( $v, array $f, array $card = array() ): string {
		$type = (string) ( $f['type'] ?? 'text' );
		if ( 'items' === $type && is_array( $v ) ) { // crm.digest / crm.lead: [{t, title}] ⇒ "09:12 Khách #12; 08:40 Tin nhắn #3"
			$parts = array();
			foreach ( array_slice( $v, 0, 10 ) as $it ) {
				if ( is_array( $it ) && '' !== trim( (string) ( $it['title'] ?? '' ) ) ) {
					$parts[] = trim( (string) ( $it['t'] ?? '' ) . ' ' . (string) $it['title'] );
				}
			}
			return implode( '; ', $parts );
		}
		if ( is_array( $v ) ) {
			return 'list' === $type || 'count' === $type ? ( 'count' === $type ? (string) count( $v ) : implode( ', ', array_map( static function ( $x ) { return is_scalar( $x ) ? (string) $x : ''; }, $v ) ) ) : '';
		}
		$s = (string) $v;
		switch ( $type ) {
			case 'money':
				return is_numeric( $v ) ? self::money( (int) round( (float) $v ), (string) ( $card[ (string) ( $f['currency_key'] ?? 'currency' ) ] ?? 'VND' ) ) : $s;
			case 'number':
				return is_numeric( $v ) ? number_format( (float) $v, 0, ',', '.' ) : $s;
			case 'enum':
				return (string) ( ( (array) ( $f['enum'] ?? array() ) )[ $s ] ?? $s );
			case 'date':
				return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $s, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : $s;
			case 'datetime':
				return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/', $s, $m ) ? $m[4] . ':' . $m[5] . ' ' . $m[3] . '/' . $m[2] . '/' . $m[1] : $s;
			default:
				return $s;
		}
	}

	// ── candidates (SF-5, pure) ─────────────────────────────────────────────

	/**
	 * `metadata.candidates[]`: {id, field, value, unit?, label?, target_kind?, source_ref, page_anchor?, confidence, extraction_run_id?, status, decided?}.
	 * A candidate is never a booked row; status proposed → approved | rejected by a person. Pure.
	 */
	public static function normalize_candidates( array $list ): array {
		$out = array();
		foreach ( array_slice( $list, 0, 30 ) as $c ) {
			if ( ! is_array( $c ) || '' === trim( (string) ( $c['field'] ?? '' ) ) ) {
				continue;
			}
			$sr = strtoupper( trim( (string) ( $c['source_ref'] ?? '' ) ) );
			$st = in_array( (string) ( $c['status'] ?? '' ), array( 'proposed', 'approved', 'rejected', 'superseded' ), true ) ? (string) $c['status'] : 'proposed';
			$row = array(
				'id'          => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $c['id'] ?? '' ) ) ), 0, 24 ) ?: 'c' . substr( md5( (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $c ) : json_encode( $c ) ) ), 0, 10 ),
				'field'       => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $c['field'] ) ), 0, 40 ),
				'value'       => is_scalar( $c['value'] ?? null ) ? mb_substr( (string) $c['value'], 0, 200 ) : '',
				'unit'        => mb_substr( (string) ( $c['unit'] ?? '' ), 0, 12 ),
				'label'       => mb_substr( (string) ( $c['label'] ?? '' ), 0, 80 ),
				'target_kind' => substr( preg_replace( '/[^a-z0-9._]/', '', strtolower( (string) ( $c['target_kind'] ?? '' ) ) ), 0, 48 ),
				'source_ref'  => 1 === preg_match( '/^[A-Z]{2}-\d{1,12}@V\d{1,6}$/', $sr ) ? str_replace( '@V', '@v', $sr ) : '',
				'page_anchor' => substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) ( $c['page_anchor'] ?? '' ) ), 0, 40 ),
				'confidence'  => max( 0.0, min( 1.0, (float) ( $c['confidence'] ?? 0 ) ) ),
				'run_id'      => substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) ( $c['extraction_run_id'] ?? $c['run_id'] ?? '' ) ), 0, 64 ),
				'status'      => $st,
			);
			if ( is_array( $c['decided'] ?? null ) ) {
				$row['decided'] = array( 'by' => (int) ( $c['decided']['by'] ?? 0 ), 'at' => (string) ( $c['decided']['at'] ?? '' ), 'row_ref' => strtoupper( (string) ( $c['decided']['row_ref'] ?? '' ) ) );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * The row a person's "Duyệt" turns a candidate into (SF-5): kind = the candidate's target_kind (a declared kind, never a guess), card {field: value, unit?},
	 * derived_from the source row, origin = the source_ref + page anchor, idempotent per (source row, candidate). null when it cannot be booked. Pure.
	 */
	public static function candidate_entry( array $source, array $cand, int $owner, array $kind_def ): ?array {
		$kind = (string) ( $cand['target_kind'] ?? '' );
		if ( '' === $kind || ! $kind_def || '' === (string) ( $cand['field'] ?? '' ) || 'proposed' !== (string) ( $cand['status'] ?? '' ) ) {
			return null;
		}
		$card = array( (string) $cand['field'] => (string) ( $cand['value'] ?? '' ) );
		if ( '' !== (string) ( $cand['unit'] ?? '' ) ) {
			$card['currency' === (string) $cand['field'] ? 'unit' : 'currency'] = (string) $cand['unit'];
		}
		$label = '' !== (string) ( $cand['label'] ?? '' ) ? (string) $cand['label'] : (string) ( $kind_def['label'] ?? $kind );
		return array(
			'owner_user_id'   => $owner,
			'kind'            => $kind,
			'title'           => mb_substr( $label . ': ' . (string) ( $cand['value'] ?? '' ), 0, 200 ),
			'doc_date'        => (string) ( $source['doc_date'] ?? '' ),
			'card'            => $card,
			'state'           => 'captured',
			'source_type'     => 'candidate',
			'source_ref'      => (string) ( $cand['source_ref'] ?? '' ),
			'idempotency_key' => 'cand:' . (int) ( $source['entry_id'] ?? 0 ) . ':' . (string) $cand['id'],
			'derived_from'    => array( (int) ( $source['entry_id'] ?? 0 ) ),
			'origin'          => array( 'surface' => 'candidate', 'ref' => (string) ( $cand['source_ref'] ?? '' ), 'page_anchor' => (string) ( $cand['page_anchor'] ?? '' ) ),
		);
	}

	/** The candidate list after a person decided one: status approved | rejected + decided{by, at, row_ref}. null when the id is unknown / already decided. Pure. */
	public static function decide_candidate( array $list, string $id, string $decision, int $by, string $row_ref = '', string $at = '' ): ?array {
		foreach ( $list as $i => $c ) {
			if ( is_array( $c ) && (string) ( $c['id'] ?? '' ) === $id ) {
				if ( 'proposed' !== (string) ( $c['status'] ?? '' ) || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
					return null;
				}
				$list[ $i ]['status']  = 'approve' === $decision ? 'approved' : 'rejected';
				$list[ $i ]['decided'] = array( 'by' => $by, 'at' => '' !== $at ? $at : gmdate( 'Y-m-d\TH:i:s\Z' ), 'row_ref' => strtoupper( $row_ref ) );
				return $list;
			}
		}
		return null;
	}

	/** A file version / OCR revision changed a value already booked ⇒ the discrepancy to propose, never an automatic edit (SF-5). Pure. */
	public static function discrepancy( array $booked_card, array $candidate ): ?array {
		$field = (string) ( $candidate['field'] ?? '' );
		if ( '' === $field || ! array_key_exists( $field, $booked_card ) ) {
			return null;
		}
		$was = (string) $booked_card[ $field ];
		$now = (string) ( $candidate['value'] ?? '' );
		return self::fold( $was ) === self::fold( $now ) ? null : array( 'field' => $field, 'booked' => $was, 'extracted' => $now, 'source_ref' => (string) ( $candidate['source_ref'] ?? '' ) );
	}

	// ── helpers (pure) ──────────────────────────────────────────────────────

	public static function is_image( array $entry ): bool {
		$k = (string) ( $entry['kind'] ?? '' );
		return 'common.photo' === $k || 'media.image.output' === $k || 0 === strpos( (string) ( $entry['original']['mime'] ?? '' ), 'image/' );
	}

	public static function money( int $v, string $cur = 'VND' ): string {
		return number_format( $v, 0, ',', '.' ) . ( 'VND' === strtoupper( $cur ) ? ' đ' : ' ' . strtoupper( $cur ) );
	}

	public static function bytes( int $b ): string {
		return $b >= 1048576 ? number_format( $b / 1048576, 1, ',', '.' ) . ' MB' : ( $b >= 1024 ? (string) round( $b / 1024 ) . ' KB' : $b . ' B' );
	}

	public static function mime_label( string $mime ): string {
		$map = array( 'image/jpeg' => 'Ảnh JPEG', 'image/png' => 'Ảnh PNG', 'image/webp' => 'Ảnh WebP', 'application/pdf' => 'PDF', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel', 'text/csv' => 'CSV', 'text/plain' => 'Văn bản', 'audio/mpeg' => 'Âm thanh', 'audio/ogg' => 'Ghi âm' );
		return $map[ strtolower( $mime ) ] ?? strtoupper( (string) ( explode( '/', $mime )[1] ?? $mime ) );
	}

	public static function surface_label( string $s ): string {
		$map = array( 'zalo_1_1' => 'Zalo riêng', 'zalo_group' => 'Nhóm Zalo', 'web_upload' => 'Tải lên web', 'cell' => 'Trợ lý', 'job' => 'Việc nền', 'automation' => 'Tự động', 'crm' => 'CRM', 'plugin' => 'Website' );
		return $map[ $s ] ?? $s;
	}

	public static function tier_label( string $tier, bool $has_content ): string {
		if ( 'L3' === $tier ) {
			return 'Đã liên kết';
		}
		if ( 'L2' === $tier ) {
			return 'Hiểu nội dung';
		}
		return $has_content ? 'Tìm theo nội dung' : 'Đã lưu';
	}

	private static function humanize( string $k ): string {
		$k = str_replace( '_', ' ', $k );
		return mb_strtoupper( mb_substr( $k, 0, 1 ) ) . mb_substr( $k, 1 );
	}

	/** Diacritic fallback when ext-intl is missing (Vietnamese letters only). */
	private static function fallback_map(): array {
		static $map = null;
		if ( null === $map ) {
			$groups = array(
				'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ',
				'A' => 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ', 'E' => 'ÈÉẸẺẼÊỀẾỆỂỄ', 'I' => 'ÌÍỊỈĨ', 'O' => 'ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ', 'U' => 'ÙÚỤỦŨƯỪỨỰỬỮ', 'Y' => 'ỲÝỴỶỸ',
			);
			$map = array();
			foreach ( $groups as $base => $chars ) {
				foreach ( preg_split( '//u', $chars, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
					$map[ $ch ] = $base;
				}
			}
		}
		return $map;
	}
}
