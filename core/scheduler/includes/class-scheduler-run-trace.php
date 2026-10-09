<?php
/**
 * BizCity_Scheduler_Run_Trace — the trace of ONE run on the Lịch (run_id / job id), for the "⌁ Trace" button
 * (PHASE-0.92 S92-RT-1, doc 61). Read-only: everything comes from what the row already holds (metadata written by
 * Run_Ledger / Report_Back) plus the node logs the Automation plugin adds through the filter
 * `bizcity_scheduler_run_trace_steps`. No new table, no write.
 *
 * Shape (doc 61 §4): { event_id, run_id, trace_id, status, title, started_at, done_at, ms, scenario{}, requester{},
 * hops[7], steps[], reply{text,artifacts}, report{}, actions{} }.
 * Never returns a full user hash, a UID, a phone number, a token or a secret: keys matching SECRET_KEYS become ••••,
 * strings are cut at TEXT_MAX characters.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả
 * số 8877/2026/QTG (Cục Bản quyền tác giả, 14/09/2026).
 *
 * // [2026-10-06 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-RT-1 — new file.
 *
 * @package BizCity_Twin_AI
 * @subpackage Scheduler
 * @since 2026-10-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Run_Trace' ) ) {
	return;
}

final class BizCity_Scheduler_Run_Trace {

	const TEXT_MAX    = 300;
	const SECRET_KEYS = '/(secret|token|password|passwd|api[_-]?key|authorization|cookie|user_hash|platform_uid|phone|chat_id)/i';

	/** Row types that carry a run. */
	const RUN_TYPES = array( 'automation_run', 'automation_workflow' );

	/**
	 * Trace of a Lịch row, or null when the row has no run (manual task, wrong type, missing row).
	 *
	 * @param array $row Run_Ledger::get() row (metadata decoded).
	 */
	public static function build( array $row ) {
		// [2026-10-06 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.92-S92-RT-1
		$meta = isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : array();
		$type = (string) ( $row['event_type'] ?? '' );
		$run  = (string) ( $meta['run_id'] ?? '' );
		if ( ! in_array( $type, self::RUN_TYPES, true ) || ( '' === $run && empty( $meta['trace'] ) ) ) {
			return null;
		}
		$event_id = (int) ( $row['id'] ?? 0 );
		$blog     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$report   = isset( $meta['report'] ) && is_array( $meta['report'] ) ? $meta['report'] : array();
		$attempt  = max( 1, (int) ( $report['attempts'] ?? 0 ) + 1 );
		$cell     = isset( $meta['_cell'] ) && is_array( $meta['_cell'] ) ? $meta['_cell'] : array();
		$trace    = isset( $meta['trace'] ) && is_array( $meta['trace'] ) ? $meta['trace'] : array();
		$status   = self::status( (string) ( $row['status'] ?? '' ) );

		$started = self::milestone_at( $trace, 'started' );
		$done_at = (string) ( $meta['done_at'] ?? self::milestone_at( $trace, 'finished' ) );
		$steps   = self::steps( $trace, $run, $meta );
		$reply   = array(
			'text'      => self::cut( (string) ( $meta['reply_text'] ?? '' ), 4000 ),
			'artifacts' => isset( $meta['artifacts'] ) && is_array( $meta['artifacts'] ) ? array_values( $meta['artifacts'] ) : array(),
		);

		$out = array(
			'event_id'   => $event_id,
			'run_id'     => $run,
			'trace_id'   => 'sch.' . $blog . '.' . $event_id . '.' . $attempt,
			'status'     => $status,
			'title'      => (string) ( $row['title'] ?? '' ),
			'type'       => 'automation_run' === $type ? 'cell' : 'scheduled',
			'received_at'=> self::milestone_at( $trace, 'received' ),
			'started_at' => $started,
			'done_at'    => $done_at,
			'ms'         => self::ms_between( '' !== $started ? $started : (string) ( $row['start_at'] ?? '' ), $done_at ),
			'scenario'   => array(
				'slug'        => (string) ( $meta['scenario_ref'] ?? '' ),
				'workflow_id' => (int) ( $meta['workflow_id'] ?? 0 ),
				'name'        => (string) ( $meta['workflow_name'] ?? $row['title'] ?? '' ),
			),
			'requester'  => self::requester( $cell, $type ),
			'steps'      => $steps,
			'reply'      => $reply,
			'report'     => self::report( $meta, $report ),
			'error'      => self::cut( (string) ( $meta['error'] ?? '' ), 500 ),
			'log_url'    => (string) ( $meta['log_url'] ?? '' ),
		);
		$out['hops']    = self::hops( $out, $meta, $trace );
		$out['actions'] = array(
			'rerun'  => 'failed' === $status && '' !== $run,
			'resend' => in_array( $status, array( 'done', 'failed' ), true ) && ! empty( $meta['report_back'] ) && empty( $out['report']['delivered'] ),
		);
		return $out;
	}

	/** Lịch status ⇒ one of: running · done · failed · missed · cancelled · waiting. */
	public static function status( string $s ): string {
		$map = array( 'done' => 'done', 'failed' => 'failed', 'running' => 'running', 'missed' => 'missed', 'cancelled' => 'cancelled' );
		return $map[ $s ] ?? 'waiting';
	}

	/** Vietnamese label of a status (S92-FIX-6 — one wording on every Lịch surface). */
	public static function status_label( string $s ): string {
		$labels = array( 'done' => 'Đã chạy', 'failed' => 'Lỗi', 'running' => 'Đang chạy', 'missed' => 'Lỡ giờ', 'cancelled' => 'Đã hủy', 'waiting' => 'Chờ' );
		return $labels[ self::status( $s ) ] ?? 'Chờ';
	}

	/**
	 * Mask secrets and cut long strings, recursively (depth ≤ 4, ≤ 30 keys per level).
	 *
	 * @param mixed $v
	 * @return mixed
	 */
	public static function mask( $v, int $depth = 0 ) {
		if ( is_array( $v ) ) {
			if ( $depth >= 4 ) {
				return '…';
			}
			$out = array();
			$n   = 0;
			foreach ( $v as $k => $x ) {
				if ( ++$n > 30 ) {
					$out['…'] = ( count( $v ) - 30 ) . ' mục nữa';
					break;
				}
				$out[ $k ] = is_string( $k ) && preg_match( self::SECRET_KEYS, $k ) ? '••••' : self::mask( $x, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $v ) ) {
			$v = preg_replace( '/\b(biz-[a-f0-9]{8})[a-f0-9]{8,}\b/i', '$1••••', $v ); // a 1API key typed into a field
			return self::cut( $v, self::TEXT_MAX );
		}
		return is_scalar( $v ) || null === $v ? $v : '…';
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	private static function requester( array $cell, string $type ): array {
		$hash = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) ( $cell['user_hash'] ?? '' ) ) );
		$role = (string) ( $cell['role'] ?? '' );
		return array(
			'role'    => '' !== $role ? $role : ( 'automation_workflow' === $type ? 'schedule' : '' ),
			'surface' => (string) ( $cell['surface'] ?? ( 'automation_workflow' === $type ? 'schedule' : '' ) ),
			'hash8'   => '' !== $hash ? substr( $hash, 0, 8 ) : '',
			'turn_id' => self::cut( (string) ( $cell['turn_id'] ?? '' ), 64 ),
		);
	}

	/** Steps from the ledger trace, enriched by the Automation plugin (input/output/ms) when it is loaded. */
	private static function steps( array $trace, string $run_id, array $meta ): array {
		$steps = array();
		foreach ( $trace as $e ) {
			if ( ! is_array( $e ) || 'step' !== (string) ( $e['m'] ?? '' ) ) {
				continue;
			}
			$steps[] = array(
				'i'     => (int) ( $e['step'] ?? count( $steps ) + 1 ),
				'block' => (string) ( $e['block_id'] ?? '' ),
				'label' => (string) ( $e['label'] ?? '' ),
				'state' => in_array( (string) ( $e['status'] ?? '' ), array( 'ok', 'fail', 'skip' ), true ) ? (string) $e['status'] : 'ok',
				'at'    => (string) ( $e['at'] ?? '' ),
				'error' => self::cut( (string) ( $e['error'] ?? '' ), 500 ),
			);
		}
		if ( function_exists( 'apply_filters' ) && '' !== $run_id ) {
			$rich = apply_filters( 'bizcity_scheduler_run_trace_steps', null, $run_id, $steps, $meta );
			if ( is_array( $rich ) ) {
				$steps = $rich;
			}
		}
		$out = array();
		foreach ( $steps as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			if ( isset( $s['input'] ) ) {
				$s['input'] = self::mask( $s['input'] );
			}
			if ( isset( $s['output'] ) ) {
				$s['output'] = self::mask( $s['output'] );
			}
			if ( ! isset( $s['hint'] ) || '' === (string) $s['hint'] ) {
				$s['hint'] = self::hint( (string) ( $s['error'] ?? '' ) );
			}
			$out[] = $s;
		}
		return $out;
	}

	/** One sentence telling the owner what to fix, from the error text (doc 61 §3). */
	public static function hint( string $error ): string {
		if ( '' === $error ) {
			return '';
		}
		if ( preg_match( '/\{\{\s*([a-z0-9_.]+)\s*\}\}|unresolved_placeholder|placeholder/i', $error, $m ) ) {
			return isset( $m[1] ) && '' !== $m[1]
				? 'Khối này dùng biến {{' . $m[1] . '}} nhưng kịch bản không có trường đó. Sửa tên biến cho khớp "Thông tin trợ lý cần hỏi".'
				: 'Còn biến {{…}} chưa thay. Kiểm tra tên biến trong khối và "Thông tin trợ lý cần hỏi" của kịch bản.';
		}
		if ( preg_match( '/category|chuyên mục/i', $error ) ) {
			return 'Chọn chuyên mục bài viết trong khối Đăng web.';
		}
		if ( preg_match( '/page|fanpage/i', $error ) ) {
			return 'Chọn Fanpage trong khối Đăng Facebook (hoặc kết nối Facebook).';
		}
		if ( preg_match( '/timeout|timed out|cURL error 28/i', $error ) ) {
			return 'Dịch vụ bên ngoài trả lời chậm. Bấm "Chạy lại" sau ít phút.';
		}
		if ( preg_match( '/quota|429|rate/i', $error ) ) {
			return 'Đã chạm giới hạn dịch vụ. Thử lại sau.';
		}
		return '';
	}

	private static function report( array $meta, array $r ): array {
		if ( empty( $meta['report_back'] ) ) {
			return array( 'asked' => false, 'status' => 'none', 'delivered' => false, 'skip_reason' => '', 'attempts' => 0, 'http' => 0, 'label' => 'Lần chạy này không gửi kết quả qua trợ lý.' );
		}
		$status = (string) ( $r['status'] ?? '' );
		$reason = (string) ( $r['skip_reason'] ?? '' );
		$labels = array(
			''          => 'Chưa gửi (đang chờ lần chạy xong).',
			'accepted'  => ! empty( $r['delivered'] ) ? 'Đã gửi cho người giao.' : 'Trợ lý đã nhận, chưa giao được: ' . self::reason_label( $reason ),
			'duplicate' => 'Đã gửi trước đó (không gửi trùng).',
			'retrying'  => 'Đang thử lại (' . (int) ( $r['attempts'] ?? 0 ) . '/5): ' . self::reason_label( $reason ),
			'fallback'  => 'Người giao chưa có chat Zalo ⇒ đã báo trong TwinChat.',
			'rejected'  => 'Không gửi được: ' . self::reason_label( $reason ),
			'skipped'   => 'Bỏ qua: ' . self::reason_label( $reason ),
		);
		return array(
			'asked'       => true,
			'status'      => '' !== $status ? $status : 'pending',
			'delivered'   => ! empty( $r['delivered'] ),
			'skip_reason' => $reason,
			'attempts'    => (int) ( $r['attempts'] ?? 0 ),
			'http'        => (int) ( $r['http'] ?? 0 ),
			'label'       => $labels[ $status ] ?? ( 'Trạng thái gửi: ' . $status ),
		);
	}

	private static function reason_label( string $r ): string {
		$map = array(
			'hub_unavailable' => 'Hub chưa nhận (sẽ thử lại).',
			'hub_not_ready'   => 'Website chưa kết nối Hub (khoá 1API).',
			'no_zalo_chat'    => 'người giao chưa có chat Zalo với số của cửa hàng.',
			'bot_disabled'    => 'bot đang tắt trên số này.',
			'account_stopped' => 'số Zalo đang dừng.',
			'no_thread'       => 'chưa có cuộc trò chuyện với người giao.',
			'no_run'          => 'dòng này không có lần chạy.',
		);
		if ( isset( $map[ $r ] ) ) {
			return $map[ $r ];
		}
		if ( 0 === strpos( $r, 'gave_up_' ) ) {
			return 'đã thử 5 lần không được (' . substr( $r, 8 ) . ').';
		}
		return '' !== $r ? $r : 'không rõ lý do.';
	}

	/** Seven hops (doc 20 §1). Hops 1–4 exist only for cell runs; a scheduled run starts at its clock. */
	private static function hops( array $t, array $meta, array $trace ): array {
		$cell = 'cell' === $t['type'];
		$fail = false;
		foreach ( $t['steps'] as $s ) {
			if ( 'fail' === ( $s['state'] ?? '' ) ) {
				$fail = true;
			}
		}
		$run_state = 'running' === $t['status'] ? 'wait' : ( 'failed' === $t['status'] || $fail ? 'fail' : ( 'done' === $t['status'] ? 'ok' : 'wait' ) );
		$ok_count  = 0;
		foreach ( $t['steps'] as $s ) {
			if ( 'ok' === ( $s['state'] ?? '' ) ) {
				$ok_count++;
			}
		}
		$total = (int) ( $meta['progress']['total'] ?? count( $t['steps'] ) );
		$rep   = $t['report'];
		$rep_state = ! $rep['asked'] ? 'na' : ( in_array( $rep['status'], array( 'accepted', 'duplicate', 'fallback' ), true ) ? 'ok' : ( in_array( $rep['status'], array( 'rejected', 'skipped' ), true ) ? 'fail' : 'wait' ) );
		$deliver   = ! $rep['asked'] ? 'na' : ( $rep['delivered'] || 'duplicate' === $rep['status'] ? 'ok' : ( 'fallback' === $rep['status'] ? 'ok' : ( 'fail' === $rep_state ? 'fail' : 'wait' ) ) );
		$via       = (string) ( $t['requester']['surface'] ?? '' );
		return array(
			array( 'n' => 1, 'key' => 'ask',     'label' => $cell ? 'Người nhắn' : 'Đến giờ hẹn', 'state' => $cell || '' !== $t['received_at'] ? 'ok' : 'na', 'at' => $t['received_at'], 'detail' => $cell ? self::surface_label( $via ) : 'Không có người nhắn — kết quả gửi cho người đặt lịch.' ),
			array( 'n' => 2, 'key' => 'pick',    'label' => 'Trợ lý chọn kịch bản', 'state' => $cell ? 'ok' : 'na', 'at' => '', 'detail' => '' !== $t['scenario']['slug'] ? 'Mã kịch bản: ' . $t['scenario']['slug'] : '' ),
			array( 'n' => 3, 'key' => 'confirm', 'label' => 'Xác nhận', 'state' => $cell ? 'ok' : 'na', 'at' => '', 'detail' => $cell ? 'Đã xác nhận trước khi chạy (hoặc chủ đã cho chạy ngay).' : '' ),
			array( 'n' => 4, 'key' => 'call',    'label' => 'Gọi website (MCP)', 'state' => $cell ? 'ok' : 'na', 'at' => $t['received_at'], 'detail' => $cell ? 'Mở dòng Lịch #' . $t['event_id'] : '' ),
			array( 'n' => 5, 'key' => 'run',     'label' => 'Workflow chạy', 'state' => $run_state, 'at' => $t['started_at'], 'detail' => $ok_count . '/' . max( $total, $ok_count ) . ' bước' . ( '' !== $t['error'] ? ' · ' . $t['error'] : '' ) ),
			array( 'n' => 6, 'key' => 'report',  'label' => 'Báo lại cho trợ lý', 'state' => $rep_state, 'at' => '', 'detail' => $rep['label'] ),
			array( 'n' => 7, 'key' => 'deliver', 'label' => 'Trợ lý trả lời người giao', 'state' => $deliver, 'at' => '', 'detail' => 'ok' === $deliver ? ( 'fallback' === $rep['status'] ? 'Đã báo trong TwinChat.' : 'Đã giao.' ) : '' ),
		);
	}

	private static function surface_label( string $s ): string {
		$map = array( 'zalo_1_1' => 'Zalo 1-1', 'wp' => 'Website (TwinChat / /gpt/)', 'schedule' => 'Hẹn giờ' );
		return $map[ $s ] ?? $s;
	}

	private static function milestone_at( array $trace, string $m ): string {
		foreach ( $trace as $e ) {
			if ( is_array( $e ) && $m === (string) ( $e['m'] ?? '' ) ) {
				return (string) ( $e['at'] ?? '' );
			}
		}
		return '';
	}

	private static function ms_between( string $a, string $b ): int {
		$ta = '' !== $a ? strtotime( $a ) : false;
		$tb = '' !== $b ? strtotime( $b ) : false;
		return ( false !== $ta && false !== $tb && $tb >= $ta ) ? (int) ( ( $tb - $ta ) * 1000 ) : 0;
	}

	private static function cut( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}
}
