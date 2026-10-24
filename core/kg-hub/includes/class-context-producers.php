<?php
/**
 * BizCity_Context_Producers — `context-producer@1`, the fourth door of R-ZALO-BRAIN-FRAMEWORK v1.2 (R-ZBF-4, PHASE-0.104 doc 50).
 *
 * A plugin registers ONE data source and emits records; Zalo Brain gives it a workspace in TwinChat with one notebook per day,
 * and the cell answers "today / this month" from it:
 *
 *   BizCity_Zalo_Brain::register_context_producer( 'woo', array( 'label' => 'WooCommerce', 'metrics' => array( 'revenue' => 'Doanh thu' ) ) );
 *   do_action( 'bizcity_context_emit', 'woo', array( 'occurred_at' => $ts, 'title' => 'Đơn #12', 'card' => array( 'revenue' => 1200000 ) ) );
 *
 * Storage — nothing new, three existing stores:
 *   - ONE journal row per (source, day): idempotency_key `ctx:<id>:<Y-m-d>`, tags [src-<id>, ctx-day], doc_date = the day, card = running totals of
 *     the declared metrics + count + the last 20 titles + the keys already counted (de-dup). Month/period answers read ≤ 31 such rows
 *     (journal summarize on per-event rows would cap at ~1000 rows).
 *   - ONE KG notebook per (source, day) "<Label> · dd/mm/YYYY", settings {producer, day_key, workspace_id ws_src_<id>}; record texts are ingested
 *     into it in the BACKGROUND (embedding is a network call — never inside the plugin's own hook), at most `text_cap` per day.
 *   - Kept forever (owner Q104-3): nothing here deletes a day. UIs list the 10 latest days and page/filter for older ones.
 *
 * Who sees what (Q104-2): owner + site admin see every source; staff when the source's `roles` contain 'staff' (and they can edit_posts);
 * customers/guests never. "Today" numbers for a system that has its own live tool (Woo sales.summary) still come from that tool (Q104-1);
 * this is history, months, comparisons, and sources without a tool of their own.
 *
 * Race note: totals are read-modify-write on one row; two records landing in the same millisecond can lose one increment. Acceptable for an
 * owner digest; de-dup keys make retries safe.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 * // [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 S104-C1…C6 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-12 Johnny Chu - Chu Hoàng Anh] PHASE-0.104 — KHÔNG `if ( class_exists( 'BizCity_Context_Producers', false ) ) { return; }` ở đây: một khai
// báo class top-level được PHP "bind" lúc biên dịch toàn file, nên class_exists() đã trả `true` ngay từ lần nạp ĐẦU TIÊN — `return;` chạy trước khi
// chạm class, bỏ qua luôn `::init()` ở cuối file (class "tồn tại" nhưng không hook gì cả). Đúng lỗi đã gặp ở `class-bizcity-addon-locator.php`
// (PHASE-0.102 S102-L31) và lặp lại ở đây — phát hiện khi chạy live-selfcheck trên production (S104-T2). `BizCity_Safe_Loader::require_file()`
// đã dùng `require_once`, nên không cần canh nạp hai lần.

final class BizCity_Context_Producers {

	const CONTRACT         = 'context-producer@1';
	const FEATURE          = 'context_producer';
	const TAG_DAY          = 'ctx-day'; // journal tags keep [a-z0-9-] only (BizCity_Journal_Database::encode_tags)
	const TEXT_CAP_DEFAULT = 200;
	const ITEMS_KEEP       = 20;
	const SEEN_KEEP        = 500;
	const METRICS_MAX      = 8;
	const CRON             = 'bizcity_context_producer_ingest';
	const PERIODS          = array( 'today', 'yesterday', 'this_week', 'this_month', 'last_month', 'date', 'range' );
	const OWNER_OPTION     = 'bizcity_context_owner_user_id';

	/** @var array<string,array> id => normalised definition */
	private static $producers = array();

	/** @var bool */
	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_context_emit', array( __CLASS__, 'emit_action' ), 10, 2 );
		add_action( self::CRON, array( __CLASS__, 'ingest_text' ), 10, 1 );
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'zalo_brain_register', static function () {
			if ( class_exists( 'BizCity_Zalo_Brain' ) ) {
				BizCity_Zalo_Brain::register_feature( self::FEATURE, '1.0.0', 'core/kg-hub' );
			}
		}, 1 );
	}

	/* ── Registry ─────────────────────────────────────────────────────────────── */

	/** @return true|WP_Error */
	public static function register( string $id, array $def ) {
		$id = strtolower( trim( $id ) );
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $id ) ) {
			return new WP_Error( 'context_producer_invalid_id', 'Mã nguồn dữ liệu phải là chữ thường/số/_ (2–32 ký tự).' );
		}
		$metrics = array();
		foreach ( (array) ( $def['metrics'] ?? array() ) as $k => $label ) {
			$k = (string) $k;
			if ( preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $k ) && count( $metrics ) < self::METRICS_MAX ) {
				$metrics[ $k ] = self::clip( (string) $label, 40 ) ?: $k;
			}
		}
		$roles = array_values( array_intersect( array( 'owner', 'staff' ), array_map( 'strval', (array) ( $def['roles'] ?? array( 'owner', 'staff' ) ) ) ) );
		self::$producers[ $id ] = array(
			'id'       => $id,
			'label'    => self::clip( (string) ( $def['label'] ?? $id ), 60 ) ?: $id,
			'icon'     => preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $def['icon'] ?? 'database' ) ) ),
			'kind'     => (string) ( $def['kind'] ?? 'common.document' ),
			'metrics'  => $metrics,
			'roles'    => $roles ? $roles : array( 'owner' ),
			'text_cap' => max( 0, min( 1000, (int) ( $def['text_cap'] ?? self::TEXT_CAP_DEFAULT ) ) ),
			'owner'    => (string) ( $def['owner'] ?? '' ),
		);
		return true;
	}

	/** @return array<string,array> */
	public static function producers(): array {
		return self::$producers;
	}

	public static function get( string $id ): ?array {
		return self::$producers[ $id ] ?? null;
	}

	/** Test seam. */
	public static function reset(): void {
		self::$producers = array();
	}

	/* ── Emit ─────────────────────────────────────────────────────────────────── */

	/** `do_action( 'bizcity_context_emit', $id, $record )` — errors go to the debug log, never to the plugin's request. */
	public static function emit_action( $id, $record = array() ): void {
		// Runs inside other plugins' hooks (a WooCommerce checkout, a form submit): it must never throw into them.
		try {
			$r = self::emit( (string) $id, is_array( $record ) ? $record : array() );
		} catch ( \Throwable $e ) {
			$r = new WP_Error( 'context_emit_failed', $e->getMessage() );
		}
		if ( is_wp_error( $r ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[context-producer] ' . (string) $id . ': ' . $r->get_error_code() . ' — see docs/developer/STANDARD.md §9' );
		}
	}

	/**
	 * @param array $record {occurred_at?: int|string, title, text?, card?: {metric: number}, entity?: {type, key}}
	 * @return array|WP_Error {day, entry_id, notebook_id, duplicate, totals}
	 */
	public static function emit( string $id, array $record ) {
		$def = self::get( $id );
		if ( null === $def ) {
			return new WP_Error( 'context_producer_unknown', 'Nguồn dữ liệu chưa đăng ký: ' . $id );
		}
		$owner = self::owner_user_id();
		if ( $owner <= 0 ) {
			return new WP_Error( 'context_producer_no_owner', 'Site chưa có chủ để giữ sổ.' );
		}
		$ts = $record['occurred_at'] ?? time();
		$ts = is_numeric( $ts ) ? (int) $ts : (int) strtotime( (string) $ts );
		if ( $ts <= 0 ) {
			$ts = time();
		}
		$day   = self::day_of( $ts );
		$title = self::clip( self::mask_pii( (string) ( $record['title'] ?? '' ) ), 160 );
		$text  = self::clip( self::mask_pii( (string) ( $record['text'] ?? '' ) ), 2000 );
		if ( '' === $title && '' === $text && empty( $record['card'] ) ) {
			return new WP_Error( 'context_record_empty', 'Bản ghi trống.' );
		}
		$row = self::day_row( $def, $day, $owner );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$card = (array) ( $row['card'] ?? array() );
		$ent  = is_array( $record['entity'] ?? null ) ? $record['entity'] : array();
		$key  = '' !== (string) ( $ent['key'] ?? '' ) ? substr( (string) ( $ent['type'] ?? 'item' ) . ':' . (string) $ent['key'], 0, 80 ) : '';
		$seen = array_values( array_map( 'strval', (array) ( $card['seen'] ?? array() ) ) );
		$notebook_id = (int) ( $row['kg']['notebook_ids'][0] ?? 0 );
		if ( '' !== $key && in_array( $key, $seen, true ) ) {
			return array( 'day' => $day, 'entry_id' => (int) $row['entry_id'], 'notebook_id' => $notebook_id, 'duplicate' => true, 'totals' => self::totals_of( $def, $card ) );
		}
		foreach ( $def['metrics'] as $m => $_label ) {
			$v = $record['card'][ $m ] ?? null;
			if ( is_numeric( $v ) ) {
				$card[ $m ] = round( (float) ( $card[ $m ] ?? 0 ) + (float) $v, 2 );
			}
		}
		$card['count'] = (int) ( $card['count'] ?? 0 ) + 1;
		$items         = (array) ( $card['items'] ?? array() );
		array_unshift( $items, array( 't' => self::time_of( $ts ), 'title' => '' !== $title ? $title : self::clip( $text, 80 ) ) );
		$card['items'] = array_slice( $items, 0, self::ITEMS_KEEP );
		if ( '' !== $key ) {
			$seen[]       = $key;
			$card['seen'] = array_slice( $seen, - self::SEEN_KEEP );
		}
		$queued = false;
		if ( '' !== $text && $notebook_id > 0 && (int) ( $card['text_count'] ?? 0 ) < $def['text_cap'] ) {
			$card['text_count'] = (int) ( $card['text_count'] ?? 0 ) + 1;
			$queued             = self::queue_text( array( 'producer' => $def['id'], 'notebook_id' => $notebook_id, 'owner' => $owner, 'day' => $day, 'title' => $title, 'text' => $text ) );
		}
		$card['as_of'] = gmdate( 'c' );
		$patch         = array( 'card' => $card );
		// [2026-10-24 05:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-Ma (doc 82 B6) — the day row's `about` is built by code from the totals (no model call), so the card reads a sentence, not `count`.
		if ( class_exists( 'BizCity_Journal_Entry_Meta' ) ) {
			$stored         = is_array( $row['meta'] ?? null ) ? $row['meta'] : array();
			$patch['meta']  = BizCity_Journal_Entry_Meta::merge( $stored, array( 'about' => self::about_of( $def, $card ) ), 'site_code' );
			$patch['meta']['status'] = 'done';
		}
		$saved = BizCity_Journal_Index::file( (int) $row['entry_id'], $owner, $patch );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array( 'day' => $day, 'entry_id' => (int) $row['entry_id'], 'notebook_id' => $notebook_id, 'duplicate' => false, 'queued_text' => $queued, 'totals' => self::totals_of( $def, $card ) );
	}

	/** "Đội Zalo (CRM) hôm nay: 3 Khách được cập nhật, 12 Tin nhắn." — the code-built about of a day row (PHASE-0.133). Pure. */
	public static function about_of( array $def, array $card ): string {
		$parts = array();
		foreach ( (array) ( $def['metrics'] ?? array() ) as $m => $label ) {
			if ( isset( $card[ $m ] ) && is_numeric( $card[ $m ] ) && 0.0 !== (float) $card[ $m ] ) {
				$n       = (float) $card[ $m ];
				$parts[] = ( floor( $n ) === $n ? number_format( $n, 0, ',', '.' ) : number_format( $n, 2, ',', '.' ) ) . ' ' . mb_strtolower( (string) $label );
			}
		}
		$count = (int) ( $card['count'] ?? 0 );
		return (string) ( $def['label'] ?? '' ) . ': ' . ( $parts ? implode( ', ', $parts ) : $count . ' sự kiện' ) . '.';
	}

	/** The (source, day) journal row — created on the first record of the day together with that day's notebook. @return array|WP_Error */
	private static function day_row( array $def, string $day, int $owner ) {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_Error( 'context_producer_unavailable', 'Chỉ mục nhật ký chưa sẵn sàng.' );
		}
		$key = 'ctx:' . $def['id'] . ':' . $day;
		$row = BizCity_Journal_Index::get_by_idempotency_key( $key, $owner );
		if ( is_array( $row ) ) {
			return $row;
		}
		$notebook_id = self::create_day_notebook( $def, $day, $owner );
		$label       = $def['label'] . ' · ' . self::human_day( $day );
		return BizCity_Journal_Index::add( array(
			'owner_user_id'   => $owner,
			'workspace_id'    => self::workspace_id( $def['id'] ),
			'kind'            => $def['kind'],
			'title'           => $label,
			'doc_date'        => $day,
			'tags'            => array( self::source_tag( $def['id'] ), self::TAG_DAY ),
			'card'            => array( 'count' => 0 ),
			'source_type'     => 'context_producer',
			'source_ref'      => $def['id'],
			'idempotency_key' => $key,
			'notebook_id'     => $notebook_id,
			'by'              => array( 'contract' => self::CONTRACT ),
		) );
	}

	private static function create_day_notebook( array $def, string $day, int $owner ): int {
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return 0;
		}
		$nb = BizCity_KG_Notebook_Service::instance()->create( array(
			'name'        => $def['label'] . ' · ' . self::human_day( $day ),
			'description' => '[' . $def['id'] . '] ' . $def['label'] . ' — sổ ngày ' . $day . ' (' . self::CONTRACT . ')',
			'settings'    => array(
				'producer'     => $def['id'],
				'day_key'      => str_replace( '-', '', $day ),
				'workspace_id' => self::workspace_id( $def['id'] ),
				'auto_created' => true,
				'contract'     => self::CONTRACT,
			),
		), $owner );
		return is_array( $nb ) ? (int) ( $nb['id'] ?? 0 ) : 0;
	}

	private static function queue_text( array $payload ): bool {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		return false !== wp_schedule_single_event( time(), self::CRON, array( $payload ) );
	}

	/** Cron: one record text ⇒ one KG source in that day's notebook (background; embedding is a network call). */
	public static function ingest_text( $payload ): void {
		$p = is_array( $payload ) ? $payload : array();
		if ( empty( $p['notebook_id'] ) || '' === (string) ( $p['text'] ?? '' ) || ! class_exists( 'BizCity_KG_Facade' ) ) {
			return;
		}
		$r = BizCity_KG_Facade::ingest_extension_source(
			array( 'plugin' => 'context:' . (string) $p['producer'], 'notebook_id' => (int) $p['notebook_id'], 'user_id' => (int) ( $p['owner'] ?? 0 ) ),
			array( 'type' => 'context_record', 'title' => (string) ( $p['title'] ?? '' ), 'content' => (string) $p['text'], 'metadata' => array( 'producer' => (string) $p['producer'], 'day' => (string) ( $p['day'] ?? '' ) ) )
		);
		if ( is_wp_error( $r ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[context-producer] ingest ' . (string) $p['producer'] . ': ' . $r->get_error_code() );
		}
	}

	/* ── Read: days + digest ──────────────────────────────────────────────────── */

	/**
	 * Day rows of one source, newest first (UI: 10 latest + page/filter — Q104-3).
	 *
	 * @param array $opt {limit≤50 (10), before?: Y-m-d (exclusive), from?: Y-m-d, to?: Y-m-d}
	 * @return array{days: array, has_more: bool}
	 */
	public static function days( string $id, array $opt = array() ): array {
		$def   = self::get( $id );
		$owner = self::owner_user_id();
		if ( null === $def || $owner <= 0 || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return array( 'days' => array(), 'has_more' => false );
		}
		$limit  = max( 1, min( 50, (int) ( $opt['limit'] ?? 10 ) ) );
		$filter = array( 'owner_user_id' => $owner, 'tags' => array( self::source_tag( $id ), self::TAG_DAY ), 'limit' => $limit + 1 );
		if ( ! empty( $opt['before'] ) && self::is_day( (string) $opt['before'] ) ) {
			$filter['to'] = gmdate( 'Y-m-d', strtotime( $opt['before'] . ' UTC' ) - DAY_IN_SECONDS );
		}
		foreach ( array( 'from', 'to' ) as $k ) {
			if ( ! empty( $opt[ $k ] ) && self::is_day( (string) $opt[ $k ] ) ) {
				$filter[ $k ] = (string) $opt[ $k ];
			}
		}
		$res  = BizCity_Journal_Index::find( $filter );
		$rows = array_slice( (array) $res['items'], 0, $limit );
		$out  = array();
		foreach ( $rows as $r ) {
			$card  = (array) ( $r['card'] ?? array() );
			$out[] = array(
				'day'         => (string) $r['doc_date'],
				'label'       => self::human_day( (string) $r['doc_date'] ),
				'notebook_id' => (int) ( $r['kg']['notebook_ids'][0] ?? 0 ),
				'entry_id'    => (int) $r['entry_id'],
				'count'       => (int) ( $card['count'] ?? 0 ),
				'totals'      => self::totals_of( $def, $card ),
			);
		}
		return array( 'days' => $out, 'has_more' => count( (array) $res['items'] ) > $limit );
	}

	/**
	 * Totals of a period, by day, and (for one day) the latest titles.
	 *
	 * @param array $opt {date?: Y-m-d (period=date), from?/to?: Y-m-d (period=range)}
	 * @return array|WP_Error
	 */
	public static function digest( string $id, string $period = 'today', array $opt = array() ) {
		$def = self::get( $id );
		if ( null === $def ) {
			return new WP_Error( 'context_producer_unknown', 'Nguồn dữ liệu chưa đăng ký: ' . $id );
		}
		$range = self::period_range( $period, $opt );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		$list   = self::days( $id, array( 'from' => $range[0], 'to' => $range[1], 'limit' => 50 ) );
		$totals = array_fill_keys( array_keys( $def['metrics'] ), 0.0 );
		$count  = 0;
		$by_day = array();
		foreach ( $list['days'] as $d ) {
			foreach ( $totals as $m => $v ) {
				$totals[ $m ] = round( $v + (float) ( $d['totals'][ $m ] ?? 0 ), 2 );
			}
			$count   += (int) $d['count'];
			$by_day[] = array( 'day' => $d['day'], 'count' => $d['count'], 'totals' => $d['totals'] );
		}
		$items = array();
		if ( $range[0] === $range[1] && ! empty( $list['days'][0]['entry_id'] ) && class_exists( 'BizCity_Journal_Index' ) ) {
			$row   = BizCity_Journal_Index::get( (int) $list['days'][0]['entry_id'], self::owner_user_id() );
			$items = array_slice( (array) ( $row['card']['items'] ?? array() ), 0, 10 );
		}
		return array(
			'contract'     => self::CONTRACT,
			'producer'     => $id,
			'label'        => $def['label'],
			'period'       => $period,
			'from'         => $range[0],
			'to'           => $range[1],
			'metrics'      => $def['metrics'],
			'totals'       => $totals,
			'count'        => $count,
			'by_day'       => array_reverse( $by_day ),
			'items'        => $items,
			'notebook_ids' => array_values( array_filter( array_map( static function ( $d ) { return (int) $d['notebook_id']; }, $list['days'] ) ) ),
			'as_of'        => gmdate( 'c' ),
		);
	}

	/** @return array{0:string,1:string}|WP_Error [from, to] in the site's timezone */
	public static function period_range( string $period, array $opt = array() ) {
		$now   = time();
		$today = self::day_of( $now );
		switch ( $period ) {
			case 'today':
				return array( $today, $today );
			case 'yesterday':
				$y = self::day_of( $now - DAY_IN_SECONDS );
				return array( $y, $y );
			case 'this_week':
				$dow = (int) self::fmt( 'N', $now ); // 1 = Monday
				return array( self::day_of( $now - ( $dow - 1 ) * DAY_IN_SECONDS ), $today );
			case 'this_month':
				return array( substr( $today, 0, 8 ) . '01', $today );
			case 'last_month':
				$first = strtotime( substr( $today, 0, 8 ) . '01 00:00:00 UTC' );
				$prev  = gmdate( 'Y-m-d', $first - DAY_IN_SECONDS );
				return array( substr( $prev, 0, 8 ) . '01', $prev );
			case 'date':
				$d = (string) ( $opt['date'] ?? '' );
				return self::is_day( $d ) ? array( $d, $d ) : new WP_Error( 'context_period_invalid', 'Ngày phải dạng YYYY-MM-DD.' );
			case 'range':
				$f = (string) ( $opt['from'] ?? '' );
				$t = (string) ( $opt['to'] ?? '' );
				return self::is_day( $f ) && self::is_day( $t ) && $f <= $t ? array( $f, $t ) : new WP_Error( 'context_period_invalid', 'Khoảng ngày không hợp lệ.' );
		}
		return new WP_Error( 'context_period_invalid', 'Kỳ không hỗ trợ: ' . $period );
	}

	/* ── Visibility ───────────────────────────────────────────────────────────── */

	/** Q104-2: owner/admin see every source; staff only when the source allows staff; customers/guests never. */
	public static function visible_to_user( array $def, int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'user_can' ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return in_array( 'staff', $def['roles'], true ) && user_can( $user_id, 'edit_posts' );
	}

	/** Same rule for an MCP principal: role owner ⇒ yes, staff ⇒ when allowed, customer ⇒ never. */
	public static function visible_to_role( array $def, string $role ): bool {
		if ( 'owner' === $role ) {
			return true;
		}
		return 'staff' === $role && in_array( 'staff', $def['roles'], true );
	}

	/* ── REST (TwinChat "Nguồn dữ liệu") ─────────────────────────────────────── */

	public static function register_rest(): void {
		register_rest_route( 'bizcity-knowledge/v2', '/context-producers', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => static function () { return is_user_logged_in(); },
		) );
		register_rest_route( 'bizcity-knowledge/v2', '/context-producers/(?P<id>[a-z][a-z0-9_]{1,31})/digest', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_digest' ),
			'permission_callback' => static function () { return is_user_logged_in(); },
		) );
	}

	/** ?producer=&before=Y-m-d&from=&to=&limit= — every visible source with its latest days (10 by default) + this month's totals. */
	public static function rest_list( $req ) {
		$uid  = get_current_user_id();
		$only = sanitize_key( (string) $req->get_param( 'producer' ) );
		$out  = array();
		foreach ( self::$producers as $id => $def ) {
			if ( ( '' !== $only && $only !== $id ) || ! self::visible_to_user( $def, $uid ) ) {
				continue;
			}
			$days  = self::days( $id, array(
				'limit'  => (int) ( $req->get_param( 'limit' ) ?: 10 ),
				'before' => (string) $req->get_param( 'before' ),
				'from'   => (string) $req->get_param( 'from' ),
				'to'     => (string) $req->get_param( 'to' ),
			) );
			$month = self::digest( $id, 'this_month' );
			$out[] = array(
				'id'       => $id,
				'label'    => $def['label'],
				'icon'     => $def['icon'],
				'metrics'  => $def['metrics'],
				'days'     => $days['days'],
				'has_more' => $days['has_more'],
				'month'    => is_wp_error( $month ) ? null : array( 'from' => $month['from'], 'to' => $month['to'], 'totals' => $month['totals'], 'count' => $month['count'] ),
			);
		}
		return rest_ensure_response( array( 'contract' => self::CONTRACT, 'producers' => $out ) );
	}

	public static function rest_digest( $req ) {
		$id  = (string) $req['id'];
		$def = self::get( $id );
		if ( null === $def || ! self::visible_to_user( $def, get_current_user_id() ) ) {
			return new WP_Error( 'context_producer_not_found', 'Không thấy nguồn dữ liệu này.', array( 'status' => 404 ) );
		}
		$r = self::digest( $id, (string) ( $req->get_param( 'period' ) ?: 'today' ), array(
			'date' => (string) $req->get_param( 'date' ),
			'from' => (string) $req->get_param( 'from' ),
			'to'   => (string) $req->get_param( 'to' ),
		) );
		// A bad period/date is the caller's mistake: 400, not the 500 a bare WP_Error would become.
		return is_wp_error( $r ) ? new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( $r );
	}

	/* ── MCP: context.digest ──────────────────────────────────────────────────── */

	public static function register_tools(): void {
		if ( ! class_exists( 'BizCity_MCP_Tool_Registry' ) ) {
			return;
		}
		$R = 'BizCity_MCP_Tool_Registry';
		// The model must name a source: the ids registered on this site ride in the description and the enum (no separate pack needed).
		$known = array();
		foreach ( self::$producers as $pid => $p ) {
			$known[] = $pid . ' (' . $p['label'] . ': ' . implode( ', ', array_values( $p['metrics'] ) ) . ')';
		}
		$producer_schema = array( 'type' => 'string', 'maxLength' => 32, 'description' => 'mã nguồn' );
		if ( self::$producers ) {
			$producer_schema['enum'] = array_keys( self::$producers );
		}
		// @mcp bizcity-mcp-standard@1 tool context.digest
		$R::register( 'context.digest', array(
			'title'          => 'Số liệu theo ngày / tháng của một nguồn dữ liệu',
			'description'    => 'Tổng số liệu (vd doanh thu, số đơn, số liên hệ) của một nguồn dữ liệu đã đăng ký (WooCommerce, form liên hệ, CRM…) theo kỳ: hôm nay, hôm qua, tuần này, tháng này, tháng trước, một ngày, một khoảng; kèm số theo từng ngày và vài mục mới nhất. Dùng cho lịch sử, tháng, so sánh. Số "hôm nay" của hệ có tool riêng (vd sales.summary) thì gọi tool đó.'
				. ( $known ? ' Nguồn trên site này: ' . implode( '; ', $known ) . '.' : ' Site này chưa có nguồn dữ liệu nào.' ),
			'when_to_use'    => 'Chủ/nhân viên hỏi số liệu của một nguồn theo ngày/tháng: "tháng này bán thế nào", "hôm qua form có mấy người", "so với tháng trước".',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'producer' ), 'properties' => array(
				'producer' => $producer_schema,
				'period'   => array( 'type' => 'string', 'enum' => self::PERIODS, 'default' => 'today' ),
				'date'     => array( 'type' => 'string', 'maxLength' => 10 ),
				'from'     => array( 'type' => 'string', 'maxLength' => 10 ),
				'to'       => array( 'type' => 'string', 'maxLength' => 10 ),
			) ),
			'output_schema'  => $R::envelope_schema( array(
				'totals' => array( 'type' => 'object' ),
				'by_day' => array( 'type' => 'array' ),
				'items'  => array( 'type' => 'array' ),
				'from'   => array( 'type' => 'string' ),
				'to'     => array( 'type' => 'string' ),
			) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'business.read',
			'handler'        => array( __CLASS__, 'mcp_digest' ),
			'mode'           => '*business',
			'roles'          => array( 'owner', 'staff' ),
			'scopes'         => array( 'business.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'context_digest',
			'since'          => '0.104.0',
		) );
	}

	/** @return array|WP_Error */
	public static function mcp_digest( array $args, array $ctx ) {
		$id  = sanitize_key( (string) ( $args['producer'] ?? '' ) );
		$def = self::get( $id );
		if ( null === $def ) {
			$known = implode( ', ', array_keys( self::$producers ) );
			return new WP_Error( 'MCP_NOT_FOUND', 'Không có nguồn dữ liệu «' . $id . '».' . ( '' !== $known ? ' Có: ' . $known . '.' : '' ) );
		}
		if ( ! self::visible_to_role( $def, (string) ( $ctx['role'] ?? 'owner' ) ) ) {
			return new WP_Error( 'MCP_PERMISSION_DENIED', 'Vai này không xem được nguồn dữ liệu «' . $def['label'] . '».' );
		}
		$r = self::digest( $id, (string) ( $args['period'] ?? 'today' ), array(
			'date' => (string) ( $args['date'] ?? '' ),
			'from' => (string) ( $args['from'] ?? '' ),
			'to'   => (string) ( $args['to'] ?? '' ),
		) );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'MCP_QUERY_INVALID', $r->get_error_message() );
		}
		return $r;
	}

	/* ── helpers ──────────────────────────────────────────────────────────────── */

	/** The site owner who holds the notebooks: option `bizcity_context_owner_user_id`, else the first administrator. */
	public static function owner_user_id(): int {
		$o = (int) ( function_exists( 'get_option' ) ? get_option( self::OWNER_OPTION, 0 ) : 0 );
		if ( $o > 0 ) {
			return $o;
		}
		if ( function_exists( 'get_users' ) ) {
			$ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
			return $ids ? (int) $ids[0] : 0;
		}
		return 0;
	}

	/** Journal tag of a source: `src-<id>` with `_` as `-` (ids never contain `-`, so no two sources share a tag). */
	public static function source_tag( string $id ): string {
		return 'src-' . str_replace( '_', '-', $id );
	}

	public static function workspace_id( string $id ): string {
		return 'ws_src_' . $id;
	}

	private static function totals_of( array $def, array $card ): array {
		$t = array();
		foreach ( $def['metrics'] as $m => $_l ) {
			$t[ $m ] = round( (float) ( $card[ $m ] ?? 0 ), 2 );
		}
		return $t;
	}

	/** Mask phone numbers and e-mail addresses before anything reaches a notebook (R-ZBF-8). */
	public static function mask_pii( string $s ): string {
		$s = (string) preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $s );
		return (string) preg_replace( '/(?<!\d)(?:\+?84|0)(?:[\s.-]?\d){8,10}(?!\d)/', '[sđt]', $s );
	}

	private static function clip( string $s, int $max ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}

	private static function fmt( string $f, int $ts ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( $f, $ts ) : gmdate( $f, $ts );
	}

	private static function day_of( int $ts ): string {
		return self::fmt( 'Y-m-d', $ts );
	}

	private static function time_of( int $ts ): string {
		return self::fmt( 'H:i', $ts );
	}

	private static function human_day( string $day ): string {
		return self::is_day( $day ) ? substr( $day, 8, 2 ) . '/' . substr( $day, 5, 2 ) . '/' . substr( $day, 0, 4 ) : $day;
	}

	private static function is_day( string $d ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
	}
}

BizCity_Context_Producers::init();
