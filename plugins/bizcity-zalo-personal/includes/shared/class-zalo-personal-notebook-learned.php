<?php
/**
 * BizCity_Zalo_Personal_Notebook_Learned — site side of "đã học xong" (PHASE-0.99 S99-P46, doc 90 §3).
 *
 *   POST bizcity/v1/zalo-hub/notebook-learned  (Bearer this site's own 1API key, via BizCity_Zalo_Personal_Hub_Client)
 *
 * The core notifier (`BizCity_KG_Channel_Progress_Notifier`) fires a CHANNEL-AGNOSTIC action,
 * `bizcity_kg_notebook_learned`, right next to its own (silenced) `send_channel_reply()` whenever a capture's step 3
 * (graph learning) finishes — core never decides what `inbound.notify === false` means beyond staying silent itself.
 * This listener is the one piece that knows: for a `zalo_personal` capture (`notify:false`, D-H7: the site must never
 * message a zalo-hub thread directly), it renders the SAME text a human would read on the number's notebook page
 * (title + the skeleton topic/summary already built by S99-P30/P31, the identical data `notebook_meta` carries) and
 * posts it once to the Hub, which relays it to the cell hosting that number — the cell is the one that actually sends
 * it (`POST /wp/notebook-learned`).
 *
 * Idempotency is the SAME key the Hub/cell dedupe on (`idempotency_key`): `kg_<kg_source_id>` for a single capture,
 * `batch_<batch_id>` for a grouped batch — one call here, one send there, a resend is a no-op.
 *
 * Best-effort, no retry queue (small win, R-LEAN-4): the capture itself already fully succeeded by the time this
 * fires; losing this one courtesy message to a transient Hub outage costs the owner nothing they cannot get back by
 * asking "sổ ghi chú của tôi có gì" (notebook_digest, S99-P47) later. Unlike deep-analysis-job@1 (an answer the owner
 * is actively waiting for), there is nothing here worth a WP-cron retry ladder.
 *
 * // @axis twin-agent-axis@1 seam SEAM-8
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.99 (2026-10-13)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Personal_Notebook_Learned', false ) ) {
	return;
}

final class BizCity_Zalo_Personal_Notebook_Learned {

	const RESULT_PATH = '/zalo-hub/notebook-learned';
	const DIGEST_MAX   = 2000;

	/** Test seams: sender(array $body): array{success|ok:bool}. */
	public static $readers = array();

	public static function init(): void {
		add_action( 'bizcity_kg_notebook_learned', array( __CLASS__, 'on_learned' ), 10, 1 );
	}

	/**
	 * `$args` shape fired by the core notifier: {notebook_id, inbound, item_count, idempotency_key}. Only acts when
	 * `inbound.notify === false` AND carries an `account_id` — the exact signal a zalo_personal capture sets
	 * (`class-zalo-personal-knowledge-capture.php`). Any other channel's capture (notify unset/true, or no
	 * account_id) is left untouched - its own listener (if any) decides.
	 */
	public static function on_learned( $args ): void {
		if ( ! is_array( $args ) ) {
			return;
		}
		$inbound = is_array( $args['inbound'] ?? null ) ? $args['inbound'] : array();
		if ( ! array_key_exists( 'notify', $inbound ) || false !== $inbound['notify'] ) {
			return; // not the "deliver through Hub instead" signal.
		}
		// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.99 S99-P46 — đường quay lại (doc 90 §3): tắt được không cần đổi mã.
		if ( 'off' === get_option( 'bizcity_kg_learned_notify', 'on' ) ) {
			return;
		}
		$account_id = (string) ( $inbound['account_id'] ?? '' );
		$notebook_id = (int) ( $args['notebook_id'] ?? 0 );
		$idempotency_key = (string) ( $args['idempotency_key'] ?? '' );
		if ( '' === $account_id || $notebook_id <= 0 || '' === $idempotency_key ) {
			return;
		}
		if ( ! self::ready() ) {
			return; // no managed 1API key on this site — nothing to post with (diagnostics/local dev).
		}

		$item_count = max( 1, (int) ( $args['item_count'] ?? 1 ) );
		$text = self::render( $notebook_id );

		self::send( array(
			'account_id'      => $account_id,
			'notebook_id'     => $notebook_id,
			'idempotency_key' => $idempotency_key,
			'title'           => $text['title'],
			'digest'          => $text['digest'],
			'item_count'      => $item_count,
		) );
	}

	/**
	 * Title + one-line digest, same source the `notebook_meta` pack already ships (title, skeleton topic/summary) and
	 * the SAME "tra sổ <tên>" phrasing `notebook_remember`/S99-P45 teach, so the owner learns one consistent command
	 * across the whole system.
	 *
	 * @return array{title:string,digest:string}
	 */
	public static function render( int $notebook_id ): array {
		$title = '';
		if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
			$title = is_array( $nb ) ? trim( (string) ( $nb['name'] ?? '' ) ) : '';
		}
		if ( '' === $title ) {
			$title = 'Sổ #' . $notebook_id;
		}

		$topic = '';
		$summary = '';
		if ( class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) {
			$cards = BizCity_KG_Skeleton_Adapter::cards( array( $notebook_id ) );
			$card  = is_array( $cards[ $notebook_id ] ?? null ) ? $cards[ $notebook_id ] : array();
			$topic = trim( (string) ( $card['topic'] ?? '' ) );
			$summary = trim( (string) ( $card['summary'] ?? '' ) );
		}

		$lines = array( sprintf( 'Sổ "%s" vừa được cập nhật.', $title ) );
		if ( '' !== $topic ) {
			$lines[] = sprintf( 'Chủ đề: %s.', $topic );
		}
		if ( '' !== $summary ) {
			$lines[] = $summary;
		}
		$lines[] = sprintf( 'Có thể hỏi: "tra sổ %s" để xem chi tiết, hoặc "sổ ghi chú của tôi có gì" để xem tổng hợp.', $title );
		$digest = implode( ' ', $lines );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $digest ) > self::DIGEST_MAX ) {
			$digest = mb_substr( $digest, 0, self::DIGEST_MAX - 1 ) . '…';
		}
		return array( 'title' => $title, 'digest' => $digest );
	}

	private static function ready(): bool {
		if ( isset( self::$readers['ready'] ) ) {
			return (bool) call_user_func( self::$readers['ready'] );
		}
		return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) && BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast();
	}

	private static function send( array $body ): void {
		if ( isset( self::$readers['sender'] ) ) {
			call_user_func( self::$readers['sender'], $body );
			return;
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return;
		}
		// Fire-and-forget on purpose (see class docblock) - the response is only worth a debug log, never a retry.
		$r = BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( self::RESULT_PATH, $body );
		if ( empty( $r['ok'] ) && empty( $r['success'] ) ) {
			self::log( 'notebook_learned_post_failed', array(
				'account_id' => (string) ( $body['account_id'] ?? '' ),
				'code'       => (string) ( $r['code'] ?? $r['error'] ?? '' ),
			), 'error' );
		}
	}

	/** Same channel-log shape as `class-zalo-deep-analysis.php` - never the title/digest text, just event + code. */
	private static function log( string $event, array $context, string $level = 'info' ): void {
		$account_id = (string) ( $context['account_id'] ?? '' );
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || '' === $account_id ) {
			return;
		}
		unset( $context['account_id'] );
		BizCity_Channel_File_Logger::write_record( array(
			'channel'   => BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
			'level'     => 'error' === $level ? BizCity_Channel_File_Logger::LEVEL_ERROR : BizCity_Channel_File_Logger::LEVEL_INFO,
			'event'     => $event,
			'stage'     => 'agent',
			'direction' => 'internal',
			'message'   => 'Zalo Personal notebook-learned (PHASE-0.99 S99-P46).',
			'producer'  => array( 'module' => 'plugins/bizcity-zalo-personal', 'version' => '1.0.0' ),
			'account'   => array( 'account_id' => $account_id, 'scope' => 'exact' ),
			'context'   => $context,
		) );
	}
}
