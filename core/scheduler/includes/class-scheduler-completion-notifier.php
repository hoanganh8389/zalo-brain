<?php
/**
 * BizCity Scheduler — Completion Notifier (R-SCH §3, SCH-NC W4)
 *
 * Listen `bizcity_scheduler_event_completed` + `bizcity_scheduler_event_failed`.
 * Khi event chuyển status → done, tự reply lại đúng channel inbound đã tạo
 * việc (Zalo / FB / Telegram / WebChat) qua BizCity_Gateway_Sender.
 *
 * Fallback resolve khi event không có metadata.inbound:
 *   1. user_meta('bizcity_default_notify_channel') = { platform, chat_id }
 *   2. site option 'bizcity_default_notify_channel'
 *   3. skip — event vẫn hiện trên dashboard.
 *
 * Per-event opt-out: metadata.notify = false → skip.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W4)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Completion_Notifier' ) ) {
	return;
}

final class BizCity_Scheduler_Completion_Notifier {

	/** @var bool */
	private static $booted = false;

	/**
	 * Bootstrap — gọi từ scheduler bootstrap.php.
	 *
	 * @return void
	 */
	public static function init() {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — register listeners.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'bizcity_scheduler_event_completed', [ __CLASS__, 'on_completed' ], 10, 2 );
		add_action( 'bizcity_scheduler_event_failed',    [ __CLASS__, 'on_failed' ],    10, 3 );
		// [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — message ① for cron scenario rows (fired by BizCity_Scheduler_Run_Ledger::running()).
		add_action( 'bizcity_scheduler_run_started',     [ __CLASS__, 'on_run_started' ], 10, 2 );
	}

	/**
	 * [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — message ① "📥 Bắt đầu «tên» (#id) · n bước".
	 * Only automation_workflow rows (cron scenarios) at notify level `full`; a cell-requested run
	 * (automation_run) already told the user in the chat turn, so it never gets ①.
	 * Proactive cap ("near the cap ⇒ drop ① first", D91-36 / D91-26) is enforced cell-side, not here.
	 *
	 * @param int          $event_id
	 * @param object|array $event Decoded ledger row.
	 * @return void
	 */
	public static function on_run_started( $event_id, $event ) {
		$row = self::row_to_array( $event );
		if ( empty( $row ) || 'automation_workflow' !== (string) ( $row['event_type'] ?? '' ) ) {
			return;
		}
		$meta = self::decode_meta( $row );
		if ( 'full' !== self::automation_notify_level( $meta ) ) {
			return;
		}
		$target = self::resolve_target( $row, $meta );
		if ( ! $target ) {
			return; // no channel ⇒ the trace on the Lịch row is the record (doc 103 §3a).
		}
		$progress = isset( $meta['progress'] ) && is_array( $meta['progress'] ) ? $meta['progress'] : [];
		$total    = isset( $progress['total'] ) ? (int) $progress['total'] : 0;
		$msg      = sprintf( '📥 Bắt đầu «%s» (#%d)', self::automation_name( $row ), (int) $event_id );
		if ( $total > 0 ) {
			$msg .= sprintf( ' · %d bước', $total );
		}
		$msg    = apply_filters( 'bizcity_scheduler_run_started_message', $msg, $row, $meta, $target );
		$result = self::dispatch( $target, $msg );
		self::patch_delivery( (int) $event_id, $target, $result, 'delivery_started' );
	}

	/**
	 * Handle event_completed.
	 *
	 * @param int            $event_id
	 * @param object|array   $event Row hoặc object từ Manager.
	 * @return void
	 */
	public static function on_completed( $event_id, $event ) {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — reply-back về inbound channel.
		$row = self::row_to_array( $event );
		if ( empty( $row ) ) {
			return;
		}
		$meta = self::decode_meta( $row );

		// [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — automation rows: message ② by notify.level (full|result ⇒ send).
		if ( self::is_automation_row( $row ) ) {
			if ( ! in_array( self::automation_notify_level( $meta ), [ 'full', 'result' ], true ) ) {
				return;
			}
			self::send_automation_result( (int) $event_id, $row, $meta, 'done', '' );
			return;
		}

		// Per-event opt-out.
		if ( isset( $meta['notify'] ) && $meta['notify'] === false ) {
			return;
		}
		if ( isset( $meta['notify'] ) && is_array( $meta['notify'] ) && isset( $meta['notify']['enabled'] ) && $meta['notify']['enabled'] === false ) {
			return;
		}

		$target = self::resolve_target( $row, $meta );
		if ( ! $target ) {
			return;
		}

		$msg = self::compose_done_message( $row, $meta );

		/**
		 * Filter cho phép module khác override message.
		 *
		 * @param string $msg
		 * @param array  $row    Event row.
		 * @param array  $meta   Decoded metadata.
		 * @param array  $target { platform, chat_id }
		 */
		$msg = apply_filters( 'bizcity_scheduler_completion_message', $msg, $row, $meta, $target );

		$result = self::dispatch( $target, $msg );

		// Persist delivery audit vào metadata.
		self::patch_delivery( (int) $event_id, $target, $result );
	}

	/**
	 * Handle event_failed.
	 *
	 * @param int          $event_id
	 * @param object|array $event
	 * @param string       $reason
	 * @return void
	 */
	public static function on_failed( $event_id, $event, $reason = '' ) {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — notify failure cho user inbound.
		$row = self::row_to_array( $event );
		if ( empty( $row ) ) {
			return;
		}
		$meta = self::decode_meta( $row );
		// [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — automation rows: failure ② by notify.level (full|result|failed ⇒ send).
		if ( self::is_automation_row( $row ) ) {
			if ( 'off' === self::automation_notify_level( $meta ) ) {
				return;
			}
			self::send_automation_result( (int) $event_id, $row, $meta, 'failed', (string) $reason );
			return;
		}
		if ( isset( $meta['notify'] ) && $meta['notify'] === false ) {
			return;
		}
		$target = self::resolve_target( $row, $meta );
		if ( ! $target ) {
			return;
		}
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		$msg   = sprintf( '⚠️ Việc "%s" (#%d) lỗi: %s', $title, (int) $event_id, $reason !== '' ? $reason : 'unknown' );
		$msg   = apply_filters( 'bizcity_scheduler_failure_message', $msg, $row, $meta, $target, $reason );
		$result = self::dispatch( $target, $msg );
		self::patch_delivery( (int) $event_id, $target, $result );
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Automation milestones (PHASE-0.91 AS-7, doc 103 §3a tier 2)
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — rows owned by BizCity_Scheduler_Run_Ledger.
	 *
	 * @param array $row
	 * @return bool
	 */
	private static function is_automation_row( array $row ) {
		return in_array( (string) ( $row['event_type'] ?? '' ), [ 'automation_workflow', 'automation_run' ], true );
	}

	/**
	 * [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — metadata.notify ⇒ full|result|failed|off.
	 * Legacy: notify === false or notify.enabled === false ⇒ off; missing/true/unknown ⇒ full (the owner's default).
	 *
	 * @param array $meta
	 * @return string
	 */
	private static function automation_notify_level( array $meta ) {
		if ( ! array_key_exists( 'notify', $meta ) || true === $meta['notify'] ) {
			return 'full';
		}
		$n = $meta['notify'];
		if ( false === $n || null === $n ) {
			return 'off';
		}
		if ( is_string( $n ) ) {
			return in_array( $n, [ 'full', 'result', 'failed', 'off' ], true ) ? $n : 'full';
		}
		if ( is_array( $n ) ) {
			if ( isset( $n['enabled'] ) && false === $n['enabled'] ) {
				return 'off';
			}
			$level = isset( $n['level'] ) ? (string) $n['level'] : 'full';
			return in_array( $level, [ 'full', 'result', 'failed', 'off' ], true ) ? $level : 'full';
		}
		return 'full';
	}

	/**
	 * @param array $row
	 * @return string
	 */
	private static function automation_name( array $row ) {
		$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
		return $title !== '' ? $title : 'kịch bản';
	}

	/**
	 * [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — message ② (replaces "✅ Đã chạy automation xong").
	 *   done   ⇒ "✅ Xong «tên»: ok/total bước · <log_url|artifact url>"
	 *   failed ⇒ "⚠️ «tên» dừng ở bước k/n «label/block»: lý do · đã xong k−1 bước · <log_url>"
	 * Proactive cap (D91-36: at the cap ② folds into one daily summary) is handled cell-side.
	 *
	 * @param int    $event_id
	 * @param array  $row
	 * @param array  $meta
	 * @param string $outcome done|failed
	 * @param string $reason  hook reason (failed only)
	 * @return void
	 */
	private static function send_automation_result( $event_id, array $row, array $meta, $outcome, $reason ) {
		$target = self::resolve_target( $row, $meta );
		if ( ! $target ) {
			return; // no channel ⇒ the trace on the Lịch row is the record (doc 103 §3a).
		}
		if ( 'done' === $outcome ) {
			$msg = self::compose_automation_done( $row, $meta );
			$msg = apply_filters( 'bizcity_scheduler_completion_message', $msg, $row, $meta, $target );
		} else {
			$msg = self::compose_automation_failed( $row, $meta, $reason );
			$msg = apply_filters( 'bizcity_scheduler_failure_message', $msg, $row, $meta, $target, $reason );
		}
		$result = self::dispatch( $target, $msg );
		self::patch_delivery( (int) $event_id, $target, $result );
	}

	/**
	 * @param array $meta
	 * @return array {ok:int,total:int,failed_step:int,failed_block:string}
	 */
	private static function automation_progress( array $meta ) {
		$p = isset( $meta['progress'] ) && is_array( $meta['progress'] ) ? $meta['progress'] : [];
		return [
			'ok'           => isset( $p['ok'] ) ? (int) $p['ok'] : 0,
			'total'        => isset( $p['total'] ) ? (int) $p['total'] : 0,
			'failed_step'  => isset( $p['failed_step'] ) ? (int) $p['failed_step'] : 0,
			'failed_block' => isset( $p['failed_block'] ) ? (string) $p['failed_block'] : '',
		];
	}

	/**
	 * @param array $row
	 * @param array $meta
	 * @return string
	 */
	private static function compose_automation_done( array $row, array $meta ) {
		$p   = self::automation_progress( $meta );
		$msg = sprintf( '✅ Xong «%s»', self::automation_name( $row ) );
		if ( $p['total'] > 0 ) {
			$msg .= sprintf( ': %d/%d bước', $p['ok'], $p['total'] );
		}
		$link = ! empty( $meta['log_url'] ) ? (string) $meta['log_url'] : '';
		if ( $link === '' && ! empty( $meta['artifacts'] ) && is_array( $meta['artifacts'] ) ) {
			foreach ( $meta['artifacts'] as $a ) {
				if ( is_array( $a ) && ! empty( $a['url'] ) ) {
					$link = (string) $a['url'];
					break;
				}
			}
		}
		if ( $link !== '' ) {
			$msg .= ' · ' . $link;
		}
		return $msg;
	}

	/**
	 * @param array  $row
	 * @param array  $meta
	 * @param string $reason
	 * @return string
	 */
	private static function compose_automation_failed( array $row, array $meta, $reason ) {
		$p    = self::automation_progress( $meta );
		$name = self::automation_name( $row );
		$why  = ! empty( $meta['error'] ) ? (string) $meta['error'] : (string) $reason;
		if ( $why === '' || $why === 'failed' ) {
			$why = 'không rõ lý do';
		}

		if ( $p['failed_step'] > 0 ) {
			$k     = $p['failed_step'];
			$label = self::step_label( $meta, $k );
			if ( $label === '' ) {
				$label = $p['failed_block'];
			}
			$msg = sprintf( '⚠️ «%s» dừng ở bước %d', $name, $k );
			if ( $p['total'] > 0 ) {
				$msg .= '/' . $p['total'];
			}
			if ( $label !== '' ) {
				$msg .= sprintf( ' «%s»', $label );
			}
			$msg .= sprintf( ': %s · đã xong %d bước', $why, max( 0, $k - 1 ) );
		} else {
			// Failed before any step reported (e.g. the runner could not start).
			$msg = sprintf( '⚠️ «%s» lỗi: %s', $name, $why );
			if ( $p['total'] > 0 ) {
				$msg .= sprintf( ' · đã xong %d/%d bước', $p['ok'], $p['total'] );
			}
		}
		if ( ! empty( $meta['log_url'] ) ) {
			$msg .= ' · ' . (string) $meta['log_url'];
		}
		return $msg;
	}

	/**
	 * Label (or block id) of the failing step k from the ledger trace.
	 *
	 * @param array $meta
	 * @param int   $k
	 * @return string
	 */
	private static function step_label( array $meta, $k ) {
		$trace = isset( $meta['trace'] ) && is_array( $meta['trace'] ) ? $meta['trace'] : [];
		for ( $i = count( $trace ) - 1; $i >= 0; $i-- ) {
			$e = $trace[ $i ];
			if ( is_array( $e ) && ( $e['m'] ?? '' ) === 'step' && (int) ( $e['step'] ?? 0 ) === (int) $k ) {
				if ( ! empty( $e['label'] ) ) {
					return (string) $e['label'];
				}
				return ! empty( $e['block_id'] ) ? (string) $e['block_id'] : '';
			}
		}
		return '';
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * @param object|array $event
	 * @return array
	 */
	private static function row_to_array( $event ) {
		if ( is_array( $event ) ) {
			return $event;
		}
		if ( is_object( $event ) ) {
			return (array) $event;
		}
		return [];
	}

	/**
	 * @param array $row
	 * @return array
	 */
	private static function decode_meta( array $row ) {
		$raw = isset( $row['metadata'] ) ? $row['metadata'] : '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	/**
	 * Resolve { platform, chat_id } theo thứ tự:
	 *   1. metadata.notify.target
	 *   2. metadata.inbound
	 *   3. user_meta 'bizcity_default_notify_channel'
	 *   4. option 'bizcity_default_notify_channel'
	 *
	 * @param array $row
	 * @param array $meta
	 * @return array|null  { platform, chat_id } hoặc null nếu skip.
	 */
	private static function resolve_target( array $row, array $meta ) {
		// [2026-08-16 Johnny Chu] R-SCH-TARGET — Scheduler and progress notices share one precedence contract.
		return class_exists( 'BizCity_Scheduler_Notify_Target_Resolver' )
			? BizCity_Scheduler_Notify_Target_Resolver::resolve( $row, $meta )
			: null;
	}

	/**
	 * Compose tin nhắn done — Vietnamese fixed (D2 LOCKED).
	 *
	 * @param array $row
	 * @param array $meta
	 * @return string
	 */
	private static function compose_done_message( array $row, array $meta ) {
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		$type  = isset( $row['event_type'] ) ? (string) $row['event_type'] : '';
		$id    = isset( $row['id'] ) ? (int) $row['id'] : 0;

		$verbs = [
			'fb_post'             => 'đăng FB',
			'web_post'            => 'đăng web',
			'reminder_zalo'       => 'gửi nhắc Zalo',
			'telegram_send'       => 'gửi Telegram',
			'reminder_personal'   => 'nhắc',
			'automation_workflow' => 'chạy automation',
			'woo_product_create'  => 'tạo sản phẩm',
			'woo_product_edit'    => 'cập nhật sản phẩm',
			'woo_order_create'    => 'tạo đơn hàng',
			'lead_report'         => 'làm báo cáo',
		];
		$verb = isset( $verbs[ $type ] ) ? $verbs[ $type ] : 'xử lý';

		// Adapter có label tốt hơn — ưu tiên dùng nếu có.
		if ( class_exists( 'BizCity_Scheduler_Adapter_Registry' ) ) {
			$adapter = BizCity_Scheduler_Adapter_Registry::get( $type );
			if ( $adapter !== null ) {
				$label = (string) $adapter->label();
				if ( $label !== '' ) {
					$verb = mb_strtolower( $label, 'UTF-8' );
				}
			}
		}

		// Permalink đính kèm nếu có (fb_post, web_post).
		$extras = [];
		if ( ! empty( $meta['fb_permalink'] ) ) {
			$extras[] = (string) $meta['fb_permalink'];
		} elseif ( ! empty( $meta['web_permalink'] ) ) {
			$extras[] = (string) $meta['web_permalink'];
		}

		$base = sprintf( '✅ Đã %s xong: %s (#%d)', $verb, $title, $id );
		if ( ! empty( $extras ) ) {
			$base .= "\n🔗 " . implode( ' · ', $extras );
		}
		return $base;
	}

	/**
	 * @param array  $target { platform, chat_id }
	 * @param string $msg
	 * @return array { sent: bool, error: string, platform: string }
	 */
	private static function dispatch( array $target, $msg ) {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — gateway sender hoặc fail-OPEN.
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return [ 'sent' => false, 'error' => 'gateway_unavailable', 'platform' => $target['platform'] ];
		}
		try {
			$result = BizCity_Gateway_Sender::instance()->send(
				(string) $target['chat_id'],
				(string) $msg,
				'text',
				[]
			);
			if ( ! is_array( $result ) ) {
				return [ 'sent' => false, 'error' => 'invalid_sender_result', 'platform' => $target['platform'] ];
			}
			return $result;
		} catch ( \Throwable $e ) {
			return [ 'sent' => false, 'error' => 'exception:' . $e->getMessage(), 'platform' => $target['platform'] ];
		}
	}

	/**
	 * Persist delivery audit vào metadata (R-SCH §2 metadata.delivery).
	 *
	 * @param int   $event_id
	 * @param array $target
	 * @param array $result
	 * @return void
	 */
	private static function patch_delivery( $event_id, array $target, array $result, $key = 'delivery' ) {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return;
		}
		$mgr   = BizCity_Scheduler_Manager::instance();
		$event = $mgr->get_event( (int) $event_id );
		if ( ! $event ) {
			return;
		}
		$meta = [];
		if ( ! empty( $event->metadata ) && is_string( $event->metadata ) ) {
			$decoded = json_decode( $event->metadata, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		// [2026-10-05 04:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.91-AS-7 — message ① audits under delivery_started; every other caller keeps 'delivery'.
		$meta[ $key ] = [
			'status'     => ! empty( $result['sent'] ) ? 'sent' : 'failed',
			'platform'   => isset( $result['platform'] ) ? (string) $result['platform'] : (string) $target['platform'],
			'chat_id'    => (string) $target['chat_id'],
			'sent_at'    => current_time( 'mysql' ),
			'message_id' => isset( $result['message_id'] ) ? (string) $result['message_id'] : null,
			'error'      => empty( $result['sent'] ) ? ( isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ) : null,
		];
		$mgr->update_event( (int) $event_id, [ 'metadata' => $meta ] );
	}
}
