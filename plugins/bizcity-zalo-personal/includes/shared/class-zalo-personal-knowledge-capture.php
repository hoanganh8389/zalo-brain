<?php
/**
 * BizCity_Zalo_Personal_Knowledge_Capture — what the OWNER of a zalo-hub Zalo Cá nhân number sends the bot in their own
 * 1-1 chat with it goes into a per-number, per-day KG-Hub notebook (PHASE-0.87 CL-D1, contract `owner-capture@1`).
 *
 * // [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-D1 — replaces the PHASE-0.86 S86-1 "Cloud của tôi + groups" source model
 * // (superseded, D-TAA-2/D-TAA-8): the single entry point is now BizCity_Zalo_Owner_Capture_REST, which the cell reaches
 * // through the Hub only when the sender is the account's UID chủ, in a 1-1 chat with the number. No group/self-thread
 * // heuristic runs on the site any more — the REST gate already proved the origin before this class ever schedules a job.
 *
 * Owner decision (2026-09-30, D-TAA-8): a file the owner sends is auto-captured; "ghi nhớ …" ⇒ the owner tool
 * `notebook_remember` sends a text item instead. Anyone else asking to save something never reaches this class — the
 * agent declines in its own reply (R-TAA-15), so the site never sees a non-owner capture request at all.
 *
 * `from_owner_capture()` only schedules a single cron event on the job registered with BizCity_Cron_Manager (R-CRON-META,
 * adopt-only). The download (Zalo CDN URLs expire) and the ingest run in that job, never inside the REST request.
 * Diagnostics CLI never schedules nor runs it (R-CLI-ASYNC-ISOLATION).
 *
 * Silent by construction (D-H7, Bot Studio owns the turn): the `inbound{}` handed to the bridge has no `chat_id` and
 * `notify => false`, so BizCity_KG_Channel_Progress_Notifier never replies into Zalo — the agent's own turn already
 * confirmed the save.
 * [2026-10-17 04:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-L3 — one exception, still through the cell (single sender):
 * after the documents of a batch are really kept, a `job_result` receipt ("✅ Đã lưu an toàn … TL-…" / "đã có sẵn" /
 * "chưa lưu được …") goes back to the sender's own 1-1 chat via BizCity_Scheduler_Hub_Pipe. Off with the private store.
 *
 * This class never attaches a notebook to a Guru and never touches `scope.knowledge` (R-GP-6 / R-GS-3: notebooks are
 * opt-in, off by default) — binding is CL-D3.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Zalo_Personal_Knowledge_Capture {

	const CRON_HOOK = 'bizcity_zalo_personal_knowledge_capture';
	/** BizCity_Cron_Manager job id (R-CRON-META). */
	const JOB_ID    = 'zalo_personal.knowledge_capture';
	const CHANNEL   = 'zalo_personal';
	/** Scope prefix: one notebook per number per day, never per customer. */
	const SCOPE_PREFIX = 'zalo_account:';
	/** Filter: return false to turn capture off for a number (or everywhere). */
	const FILTER_ENABLED = 'bizcity_zalo_personal_knowledge_capture_enabled';

	/** Owner-capture item kinds that carry a downloadable attachment (contract owner-capture@1). */
	const ATTACHMENT_KINDS = array( 'image', 'file', 'audio' );

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_job' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'register_job' ), 20 );
	}

	/**
	 * Register the single-event job with the central cron registry so every run leaves meta (R-CRON-META).
	 * Adopt-only: no recurring loop, the hook only fires for events scheduled by from_owner_capture().
	 */
	public static function register_job(): void {
		if ( self::in_diagnostics_cli() || ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ID,
			'hook'        => self::CRON_HOOK,
			'interval'    => 'hourly',
			'owner'       => 'plugins/bizcity-zalo-personal',
			'description' => 'PHASE-0.87 CL-D1: owner-capture@1 file/remember into the per-number daily KG notebook.',
			'retention'   => 7,
			'adopt_only'  => true,
		) );
	}

	/**
	 * The one rule. Pure, so it is unit-testable. '' = capture; anything else is the reason to skip (logged).
	 * Group/cloud never enter this gate any more: BizCity_Zalo_Owner_Capture_REST only calls from_owner_capture()
	 * once it already proved the message came from the number's UID chủ in a 1-1 chat (R-TAA-15).
	 *
	 * @param array $f { enabled:bool, provider:string, owner_user_id:int, has_items:bool }
	 */
	public static function owner_capture_skip_reason( array $f ): string {
		if ( empty( $f['enabled'] ) ) {
			return 'disabled';
		}
		if ( (string) ( $f['provider'] ?? '' ) !== 'zalo_hub' ) {
			return 'provider_not_zalo_hub';
		}
		if ( (int) ( $f['owner_user_id'] ?? 0 ) <= 0 ) {
			return 'no_owner';
		}
		if ( empty( $f['has_items'] ) ) {
			return 'no_items';
		}
		return '';
	}

	/**
	 * Owner-capture@1 items → this class's internal item shape ({kind, attachment} or {kind:text, content}).
	 * `kind=file` items with an http(s) url; `kind=remember` sends `kind:text, text:` items instead.
	 *
	 * @param array $raw_items contract `items[]` ({kind:image|file|audio, url, file_name?} or {kind:text, text, title_hint?})
	 * @return array<int,array<string,mixed>>
	 */
	public static function items_from_owner_capture( array $raw_items ): array {
		$out = array();
		foreach ( $raw_items as $i => $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			// [2026-10-12 12:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 (owner-capture@1.2.0, I-3) —
			// carry the cell-extracted `card` + the item's position in the RAW payload through: `raw_index`
			// survives any item this method drops (invalid url/empty text), so the journal idempotency_key built
			// in capture() from it keeps matching the position the cell's own `items[]` used — the same position
			// the later "card" patch event's `index` refers to.
			$card = is_array( $it['card'] ?? null ) ? $it['card'] : array();
			$kind = sanitize_key( (string) ( $it['kind'] ?? '' ) );
			if ( 'text' === $kind ) {
				$text = trim( (string) ( $it['text'] ?? '' ) );
				if ( '' === $text ) {
					continue;
				}
				$out[] = array( 'kind' => 'text', 'content' => $text, 'raw_index' => $i, 'card' => $card );
				continue;
			}
			$url = trim( (string) ( $it['url'] ?? '' ) );
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$att_kind = in_array( $kind, self::ATTACHMENT_KINDS, true ) ? $kind : 'file';
			$att      = array( 'kind' => $att_kind, 'url' => $url, 'source_url' => $url );
			$name     = (string) ( $it['file_name'] ?? '' );
			if ( '' !== $name ) {
				$att['file_name'] = $name;
			}
			$out[] = array( 'kind' => $att_kind, 'attachment' => $att, 'raw_index' => $i, 'card' => $card );
		}
		return $out;
	}

	/**
	 * owner-capture@1.2.0 follow-up event `kind:"card"` (I-3): the cell saw the file in a LATER turn than the one
	 * that sent it and now attaches the extracted card by `idempotency_key` (the ORIGINAL file event's) + each
	 * item's `index` (its position in that same file event's `items[]`). Patches the journal index row synchronously
	 * — this is a DB-only patch (no Zalo CDN download, no model call), so unlike `from_owner_capture()` it does not
	 * need a cron hop (R-PF-2 is about avoiding a provider call on the request path, not every DB write).
	 *
	 * @param array $raw_items contract `items[]` ({msg_id?, index, card})
	 * @return array { ok:bool, code:string, patched?:int }
	 */
	public static function from_owner_capture_card( string $bridge_id, string $idempotency_key, array $raw_items ): array {
		if ( self::in_diagnostics_cli() ) {
			return array( 'ok' => false, 'code' => 'cli_isolated' );
		}
		$bridge_id       = sanitize_text_field( $bridge_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( '' === $bridge_id || '' === $idempotency_key || empty( $raw_items ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param' );
		}
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return array( 'ok' => false, 'code' => 'capture_unsupported' );
		}
		$patched = 0;
		foreach ( $raw_items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$index = isset( $it['index'] ) ? (int) $it['index'] : -1;
			$card  = is_array( $it['card'] ?? null ) ? $it['card'] : array();
			if ( $index < 0 || empty( $card ) ) {
				continue;
			}
			$key   = 'oc:' . $idempotency_key . ':' . $index;
			// [2026-10-18 02:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-M2 — a card now exists ⇒ no longer "cần xem lại", UNLESS the cell itself was unsure (handwriting / blurry photo: confidence < 0.6, same threshold as BizCity_Journal_Index::record)
			$patch = array( 'card' => $card, 'review' => isset( $card['confidence'] ) && (float) $card['confidence'] < 0.6 );
			if ( '' !== (string) ( $card['kind'] ?? '' ) ) {
				$patch['kind'] = (string) $card['kind'];
			}
			if ( '' !== (string) ( $card['subject']['hash'] ?? '' ) ) {
				$patch['subject_hash'] = (string) $card['subject']['hash'];
			}
			if ( isset( $card['tags'] ) ) {
				$patch['tags'] = (array) $card['tags'];
			}
			if ( '' !== (string) ( $card['doc_date'] ?? '' ) ) {
				$patch['doc_date'] = (string) $card['doc_date'];
			}
			// [2026-10-18 02:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 A2-4 (S121-R2) — the cell's 1-3 sentence summary (from content it read) becomes the row's summary (T1 of the answer ladder)
			if ( is_string( $card['summary'] ?? null ) && '' !== trim( $card['summary'] ) ) {
				$patch['summary'] = mb_substr( trim( $card['summary'] ), 0, 300 );
			}
			// owner_user_id 0: the Bearer gate on the REST route already proved this account's callback token;
			// the row's own owner stays whatever the original file event wrote it as (R-JN-2: never reassigned here).
			$r = BizCity_Journal_Index::file_by_idempotency_key( $key, 0, $patch );
			if ( ! is_wp_error( $r ) ) {
				$patched++;
			}
		}
		self::log( 'owner_capture_card_patched', array( 'account_id' => $bridge_id, 'patched' => $patched, 'total' => count( $raw_items ) ) );
		return array( 'ok' => true, 'code' => '', 'patched' => $patched );
	}

	/**
	 * Called by BizCity_Zalo_Owner_Capture_REST after the callback Bearer + `owner-capture@1` payload verified.
	 * Schedules the ingest job; never touches Zalo or the KG bridge in the request itself.
	 *
	 * @param string $bridge_id       zalo-hub account id (contract `account_id`)
	 * @param array  $raw_items       contract `items[]`
	 * @param string $idempotency_key contract `idempotency_key` — becomes the per-item dedupe key downstream
	 * @param int    $ts              contract `sent_at` (unix time), 0 = now
	 * @param array  $principal       owner-capture@1.1 {user_hash, role, kind}; empty / no role = the owner (wave 1)
	 * @return array { ok:bool, code:string, duplicate?:bool }
	 */
	public static function from_owner_capture( string $bridge_id, array $raw_items, string $idempotency_key, int $ts = 0, array $principal = array() ): array {
		// [2026-09-30 Claude Opus 5.5] PHASE-0.87 — R-CLI-ASYNC-ISOLATION §3.2: check before any schedule.
		if ( self::in_diagnostics_cli() ) {
			return array( 'ok' => false, 'code' => 'cli_isolated' );
		}
		$bridge_id       = sanitize_text_field( $bridge_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( '' === $bridge_id || '' === $idempotency_key ) {
			return array( 'ok' => false, 'code' => 'invalid_param' );
		}
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array( 'ok' => false, 'code' => 'mapping_unavailable' );
		}
		$db_account = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id );
		if ( ! is_array( $db_account ) ) {
			return array( 'ok' => false, 'code' => 'account_not_found' );
		}
		$items = self::items_from_owner_capture( $raw_items );
		$owner = (int) ( $db_account['owner_user_id'] ?? 0 );
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2-4 — the file lands in the notebook of the person who sent it (doc 50 §5.4).
		$role = 'owner';
		if ( '' !== (string) ( $principal['role'] ?? '' ) ) {
			$who = self::capture_recipient( $bridge_id, $owner, $principal );
			if ( null === $who ) {
				self::log( 'owner_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => 'capture_principal_unknown' ) );
				return array( 'ok' => false, 'code' => 'capture_principal_unknown' );
			}
			$owner = $who['user_id'];
			$role  = $who['role'];
		}
		$reason = self::owner_capture_skip_reason( array(
			'enabled'       => (bool) apply_filters( self::FILTER_ENABLED, true, $bridge_id ),
			'provider'      => class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::provider( $bridge_id ) : '',
			'owner_user_id' => $owner,
			'has_items'     => ! empty( $items ),
		) );
		if ( '' !== $reason ) {
			self::log( 'owner_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => $reason ) );
			return array( 'ok' => false, 'code' => $reason );
		}
		$ts  = $ts > 0 ? $ts : time();
		$job = array(
			'blog_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'bridge_id'      => $bridge_id,
			'owner_user_id'  => $owner,
			'label'          => (string) ( $db_account['label'] ?? '' ),
			'day_key'        => wp_date( 'Ymd', $ts ),
			// Own key namespace: never collides with a plain message_id even if some future source reuses one.
			'dedupe_key'     => 'oc:' . $idempotency_key,
			'origin'         => 'owner_capture',
			'thread_kind'    => 'owner_1to1',
			'crm_message_id' => 0,
			'actor_user_id'  => $owner,
			'role'           => $role,
			'items'          => $items,
		);
		// WP ignores the same hook+args within 10 minutes: a Hub retry of the same idempotency_key schedules nothing new.
		// The real dedupe (survives past that window, and past an already-run job) is the per-item message_id lookup
		// BizCity_KG_Channel_Notebook_Bridge does inside capture() below — this flag is only the fast, best-effort signal.
		$already   = (bool) wp_next_scheduled( self::CRON_HOOK, array( $job ) );
		$scheduled = $already ? true : wp_schedule_single_event( time(), self::CRON_HOOK, array( $job ) );
		if ( false === $scheduled ) {
			self::log( 'knowledge_capture_schedule_failed', array( 'account_id' => $bridge_id, 'origin' => 'owner_capture', 'items' => count( $items ) ), 'error' );
			return array( 'ok' => false, 'code' => 'schedule_failed' );
		}
		if ( ! $already ) {
			self::log( 'knowledge_capture_queued', array( 'account_id' => $bridge_id, 'origin' => 'owner_capture', 'items' => count( $items ), 'thread' => 'owner_1to1' ) );
			// Zalo CDN links expire: wake WP-cron now instead of waiting for the next page view (non-blocking).
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
		return array( 'ok' => true, 'code' => '', 'duplicate' => $already );
	}

	/**
	 * [2026-10-19 10:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W2 (B7, R-JN-27) — tệp của một nhóm Zalo vào SỔ CỦA CHỦ SỐ, cùng đường cron
	 * như owner-capture (tải từ URL CRM → kho riêng tư / R2 → journal). Người gọi (BizCity_CRM_Group_Secretary) đã kiểm bậc quyền / công tắc
	 * theo dõi; ở đây chỉ kiểm số, chủ, bật / tắt và dựng job. Mỗi tệp một khoá `gc:<blog>:<crm_attachment_id>` ⇒ lưu lại / gửi lại không thêm dòng.
	 *
	 * @param array $files [{kind:image|file, url, file_name, crm_attachment_id, crm_message_id, sender_label, sender_role, msg_ref}]
	 * @param array $group {gid, conv_id, name, trigger: command|follow}
	 * @return array { ok:bool, code:string, queued?:int, duplicate?:bool }
	 */
	public static function from_group_files( string $bridge_id, array $files, array $group ): array {
		if ( self::in_diagnostics_cli() ) {
			return array( 'ok' => false, 'code' => 'cli_isolated' );
		}
		$bridge_id = sanitize_text_field( $bridge_id );
		$gid       = preg_match( '/^[0-9]{3,30}$/', (string) ( $group['gid'] ?? '' ) ) ? (string) $group['gid'] : '';
		if ( '' === $bridge_id || '' === $gid ) {
			return array( 'ok' => false, 'code' => 'invalid_param' );
		}
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array( 'ok' => false, 'code' => 'mapping_unavailable' );
		}
		$db_account = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id );
		if ( ! is_array( $db_account ) ) {
			return array( 'ok' => false, 'code' => 'account_not_found' );
		}
		$job = self::group_job( $bridge_id, $db_account, $files, $group );
		$reason = self::owner_capture_skip_reason( array(
			'enabled'       => (bool) apply_filters( self::FILTER_ENABLED, true, $bridge_id ),
			'provider'      => class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::provider( $bridge_id ) : '',
			'owner_user_id' => (int) $job['owner_user_id'],
			'has_items'     => ! empty( $job['items'] ),
		) );
		if ( '' !== $reason ) {
			self::log( 'group_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => $reason, 'trigger' => $job['trigger'] ) );
			return array( 'ok' => false, 'code' => $reason );
		}
		$already   = (bool) wp_next_scheduled( self::CRON_HOOK, array( $job ) );
		$scheduled = $already ? true : wp_schedule_single_event( time(), self::CRON_HOOK, array( $job ) );
		if ( false === $scheduled ) {
			self::log( 'knowledge_capture_schedule_failed', array( 'account_id' => $bridge_id, 'origin' => 'group_capture', 'items' => count( $job['items'] ) ), 'error' );
			return array( 'ok' => false, 'code' => 'schedule_failed' );
		}
		if ( ! $already ) {
			self::log( 'knowledge_capture_queued', array( 'account_id' => $bridge_id, 'origin' => 'group_capture', 'items' => count( $job['items'] ), 'thread' => 'group', 'trigger' => $job['trigger'] ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron(); // URL CDN Zalo hết hạn: chạy ngay, không đợi lượt xem trang
			}
		}
		return array( 'ok' => true, 'code' => '', 'queued' => count( $job['items'] ), 'duplicate' => $already );
	}

	/**
	 * PHASE-0.124 W2 — job cron của tệp nhóm (thuần, test được). Tệp hỏng URL / thiếu mã tệp CRM bị bỏ riêng.
	 *
	 * @param array $db_account {owner_user_id, label}
	 */
	public static function group_job( string $bridge_id, array $db_account, array $files, array $group ): array {
		$blog    = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$gid     = (string) ( $group['gid'] ?? '' );
		$conv_id = (int) ( $group['conv_id'] ?? 0 );
		$trigger = 'follow' === (string) ( $group['trigger'] ?? '' ) ? 'follow' : 'command';
		$name    = substr( sanitize_text_field( (string) ( $group['name'] ?? '' ) ), 0, 120 );
		$items   = array();
		foreach ( array_values( $files ) as $i => $fl ) {
			$url = trim( (string) ( $fl['url'] ?? '' ) );
			$att = (int) ( $fl['crm_attachment_id'] ?? 0 );
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) || $att <= 0 ) {
				continue;
			}
			$kind  = 'image' === (string) ( $fl['kind'] ?? '' ) ? 'image' : 'file';
			$a     = array( 'kind' => $kind, 'url' => $url, 'source_url' => $url );
			$fname = sanitize_text_field( (string) ( $fl['file_name'] ?? '' ) );
			if ( '' !== $fname ) {
				$a['file_name'] = $fname;
			}
			$items[] = array(
				'kind'              => $kind,
				'attachment'        => $a,
				'raw_index'         => $i,
				'card'              => array( 'tags' => array( 'grp-' . $gid ) ),
				'item_key'          => 'gc:' . $blog . ':' . $att,
				'crm_attachment_id' => $att,
				'group'             => array(
					'ref'          => $conv_id > 0 ? 'NH-' . $conv_id : '',
					'gid'          => $gid,
					'name'         => $name,
					'sender_label' => (string) ( $fl['sender_label'] ?? '' ),
					'sender_role'  => (string) ( $fl['sender_role'] ?? 'member' ),
					'msg_ref'      => (string) ( $fl['msg_ref'] ?? '' ),
					'trigger'      => $trigger,
				),
			);
		}
		return array(
			'blog_id'        => $blog,
			'bridge_id'      => $bridge_id,
			'owner_user_id'  => (int) ( $db_account['owner_user_id'] ?? 0 ),
			'label'          => (string) ( $db_account['label'] ?? '' ),
			'day_key'        => function_exists( 'wp_date' ) ? wp_date( 'Ymd', time() ) : gmdate( 'Ymd' ),
			'dedupe_key'     => 'gc:' . $blog . ':' . $gid,
			'origin'         => 'group_capture',
			'thread_kind'    => 'group',
			'crm_message_id' => (int) ( $files[0]['crm_message_id'] ?? 0 ),
			'actor_user_id'  => (int) ( $db_account['owner_user_id'] ?? 0 ),
			'role'           => 'owner',
			'trigger'        => $trigger,
			'group_name'     => $name,
			'items'          => $items,
		);
	}

	/**
	 * Wave 2 (W2-4): who receives an owner-capture@1.1 item. The owner by hash (or `role: owner` with no staff match), or an
	 * active staff member of THIS number whose `notebook` mode + matching capture switch are on. null = refuse (403).
	 *
	 * @param array $principal {user_hash, role, kind}
	 * @return array{user_id:int,role:string}|null
	 */
	public static function capture_recipient( string $bridge_id, int $owner_user_id, array $principal ): ?array {
		$hash = strtolower( trim( (string) ( $principal['user_hash'] ?? '' ) ) );
		$role = sanitize_key( (string) ( $principal['role'] ?? '' ) );
		$kind = 'remember' === (string) ( $principal['kind'] ?? '' ) ? 'remember' : 'files';
		if ( 'owner' === $role ) {
			$owner_hash = $owner_user_id > 0 && class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::user_hash( $owner_user_id ) : '';
			return ( '' === $hash || ( '' !== $owner_hash && hash_equals( $owner_hash, $hash ) ) ) && $owner_user_id > 0 ? array( 'user_id' => $owner_user_id, 'role' => 'owner' ) : null;
		}
		if ( 'staff' === $role ) {
			if ( '' === $hash || ! class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
				return null;
			}
			$p = BizCity_Zalo_Agent_Principals::by_hash( $bridge_id, $hash );
			if ( null === $p || 'staff' !== $p['role'] || empty( $p['capture'][ $kind ] ) ) {
				return null;
			}
			return array( 'user_id' => (int) $p['user_id'], 'role' => 'staff' );
		}
		// [2026-10-12 11:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 D100-25 owner-only: the PHASE-0.100 S100-L15 guest branch (filter bizcity_zalo_personal_capture_guest_allowed) is removed —
		// a guest/student is not an audience, so any role other than owner/staff is refused (fail-closed, as before).
		return null;
	}

	/**
	 * Cron job: resolve the per-number daily notebook and ingest the files. Never replies anywhere.
	 *
	 * @param array $job { blog_id, bridge_id, owner_user_id, label, day_key, dedupe_key, origin, thread_kind, actor_user_id, items[] }
	 */
	public static function run_job( $job ): void {
		// [2026-09-30 Claude Opus 5.5] PHASE-0.87 — R-CLI-ASYNC-ISOLATION §3.3: first line of the worker.
		if ( self::in_diagnostics_cli() || ! is_array( $job ) ) {
			return;
		}
		$bridge_id = (string) ( $job['bridge_id'] ?? '' );
		$blog_id   = (int) ( $job['blog_id'] ?? 0 );
		$switched  = false;
		if ( $blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && (int) get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			self::capture( $job, $bridge_id );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * "Zalo <number label> dd/mm" — stable per number and day, so the bridge finds the same notebook all day.
	 * A label that is a phone number is masked to its last 3 digits (the title travels to the cell bundle).
	 * The title is the per-day lookup key: it is built in the site locale, so a locale change starts a new notebook.
	 */
	public static function notebook_title( string $label, string $bridge_id, string $day_key ): string {
		$name = self::mask_label( trim( $label ) !== '' ? trim( $label ) : $bridge_id );
		$date = preg_match( '/^\d{8}$/', $day_key ) ? substr( $day_key, 6, 2 ) . '/' . substr( $day_key, 4, 2 ) : '';
		// [2026-10-01] Fixed ASCII-safe format, no gettext: the title is the per-day lookup key and travels to the cell, and a translated/decorated
		// format showed tofu boxes around the number and date in the notebook list and in the answer. Invisible/bidi characters are stripped.
		$title = trim( 'Zalo ' . $name . ' ' . $date );
		$title = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $title );
		return str_replace( '…', '...', $title );
	}

	/** A label made mostly of digits (a phone number) shows only "…" + its last 3 digits. */
	public static function mask_label( string $label ): string {
		$digits = preg_replace( '/\D+/', '', $label );
		if ( strlen( (string) $digits ) >= 8 && strlen( (string) $digits ) >= (int) ( strlen( $label ) * 0.6 ) ) {
			return '…' . substr( (string) $digits, -3 );
		}
		return $label;
	}

	// ── internals ────────────────────────────────────────────────────────

	private static function capture( array $job, string $bridge_id ): void {
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
			self::done( 'knowledge_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => 'kg_hub_not_loaded' ), 'invalid_metadata' );
			return;
		}
		$owner   = (int) ( $job['owner_user_id'] ?? 0 );
		$day_key = (string) ( $job['day_key'] ?? '' );
		if ( $owner <= 0 || '' === $bridge_id || ! get_userdata( $owner ) ) {
			self::done( 'knowledge_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => 'no_owner' ), 'invalid_metadata' );
			return;
		}
		$title = self::notebook_title( (string) ( $job['label'] ?? '' ), $bridge_id, $day_key );
		$items = array();
		// [2026-10-12 01:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 — per-item journal metadata, keyed
		// by its PUSH POSITION in $items (0..n-1 — the same index capture_batch()'s succeeded[]/queued[] use),
		// not by `$i` (the loop key, which skips a gap when an earlier item is dropped).
		$card_meta = array();
		foreach ( (array) ( $job['items'] ?? array() ) as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			// [2026-10-12 01:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15 — `raw_index` (set by
			// items_from_owner_capture()) is the item's position in the RAW owner-capture payload; a later
			// owner-capture@1.2.0 "card" patch event's `index` refers to that same raw position, not to `$i`
			// here (which can shift when an earlier raw item was dropped as invalid).
			$raw_index = isset( $item['raw_index'] ) ? (int) $item['raw_index'] : $i;
			// One message id per item: the bridge dedupes on it, so a retried job never adds the same item twice.
			$mid = (string) ( $job['dedupe_key'] ?? '' ) . ':' . $raw_index;
			// [2026-10-19 10:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 W2 — tệp nhóm mang khoá riêng theo tệp CRM (lưu theo lệnh rồi lại theo dõi ⇒ vẫn một dòng)
			if ( '' !== (string) ( $item['item_key'] ?? '' ) ) {
				$mid = (string) $item['item_key'];
			}
			$row = array(
				'kind'       => (string) ( $item['kind'] ?? 'file' ),
				'title_hint' => $title,
				'message_id' => $mid,
			);
			if ( isset( $item['attachment'] ) && is_array( $item['attachment'] ) ) {
				$row['attachment'] = array_merge( $item['attachment'], array( 'message_id' => $mid ) );
			} elseif ( isset( $item['content'] ) ) {
				$row['content'] = (string) $item['content'];
			} else {
				continue;
			}
			$card_meta[ count( $items ) ] = array(
				'card'             => is_array( $item['card'] ?? null ) ? $item['card'] : array(),
				'idempotency_key'  => $mid,
				'kind_hint'        => (string) ( $item['kind'] ?? 'file' ),
				'group'            => is_array( $item['group'] ?? null ) ? $item['group'] : null,
				'crm_attachment_id'=> (int) ( $item['crm_attachment_id'] ?? 0 ),
			);
			$items[] = $row;
		}
		if ( empty( $items ) ) {
			return;
		}

		// A fixed "Zalo <number> dd/mm" title: no LLM naming call per file.
		add_filter( 'bizcity_kg_notebook_bridge_use_llm_title', '__return_false', 99 );
		try {
			$res = BizCity_KG_Channel_Notebook_Bridge::instance()->capture_batch( array(
				'user_id'    => $owner,
				'channel'    => self::CHANNEL,
				'chat_id'    => '',
				'chat_kind'  => 'private',
				'scope_type' => 'private',
				'scope_id'   => self::SCOPE_PREFIX . $bridge_id,
				'title_hint' => $title,
				'day_key'    => $day_key,
				// No chat_id + notify:false ⇒ the progress notifier stays silent (D-H7). The rest is provenance only.
				'inbound'    => array(
					'platform'       => 'ZALO_PERSONAL',
					'account_id'     => $bridge_id,
					'thread_kind'    => (string) ( $job['thread_kind'] ?? '' ),
					'crm_message_id' => (int) ( $job['crm_message_id'] ?? 0 ),
					'origin'         => (string) ( $job['origin'] ?? '' ),
					'actor_user_id'  => (int) ( $job['actor_user_id'] ?? 0 ),
					'notify'         => false,
				),
			), $items );
		} finally {
			remove_filter( 'bizcity_kg_notebook_bridge_use_llm_title', '__return_false', 99 );
		}

		if ( is_wp_error( $res ) ) {
			self::done( 'knowledge_capture_failed', array( 'account_id' => $bridge_id, 'reason' => $res->get_error_code(), 'items' => count( $items ) ), 'invalid_param', 'error' );
			// [2026-10-17 04:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-L3 — the whole batch failed: the documents in it get a "chưa lưu được" receipt.
			$all_failed = array();
			foreach ( $items as $i => $row ) {
				$all_failed[] = array( 'index' => $i, 'kind' => (string) $row['kind'], 'code' => 'store_failed', 'file_name' => (string) ( $row['attachment']['file_name'] ?? '' ) );
			}
			self::send_receipt( $job, $owner, $bridge_id, array( 'failed' => $all_failed ) );
			return;
		}
		self::send_receipt( $job, $owner, $bridge_id, $res );
		// [2026-10-12 01:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.100 S100-L15/L17 (R-JOURNAL R-JN-1/R-JN-3/R-JN-6) —
		// K4 "đánh chỉ mục": every item the bridge actually captured (succeeded now, or queued for async KG ingest)
		// gets one journal-entry@1 row, then K3 "xếp ngăn" files it into the day inbox (already done by the bridge
		// above) plus its kind shelf / per-subject notebook (BizCity_Journal_Filer). A card-less item still gets a
		// row (kind falls back to common.*, flags.review=true inside BizCity_Journal_Index::add() — R-JN-2: the
		// original file is never blocked on this). Duplicates (same idempotency_key as an earlier capture) are
		// skipped — they already have a row.
		self::index_captured_items( $job, $owner, $bridge_id, $day_key, $card_meta, $res );
		// [2026-10-01] capture_batch() returns a normal array even when an item failed (download/sideload before any placeholder
		// source exists) — it used to be reported as "done" with succeeded=0, so a lost file left no trace here. Surface it.
		$failed = self::failed_items_summary( $res );
		if ( ! empty( $failed ) ) {
			self::done( 'knowledge_capture_failed', array(
				'account_id' => $bridge_id,
				'reason'     => 'items_failed',
				'items'      => count( $items ),
				'failed'     => count( $failed ),
				'succeeded'  => (int) ( $res['succeeded'] ?? 0 ),
				'queued'     => (int) ( $res['queued'] ?? 0 ),
				'errors'     => $failed,
			), 'invalid_param', 'error' );
			return;
		}
		self::done( 'knowledge_capture_done', array(
			'account_id'       => $bridge_id,
			'origin'           => (string) ( $job['origin'] ?? '' ),
			'notebook_id'      => (int) ( $res['notebook_id'] ?? 0 ),
			'notebook_created' => ! empty( $res['notebook_created'] ),
			'total'            => (int) ( $res['total'] ?? count( $items ) ),
			'succeeded'        => (int) ( $res['succeeded'] ?? 0 ),
			'queued'           => (int) ( $res['queued'] ?? 0 ),
		) );
	}

	/**
	 * PHASE-0.100 S100-L15/L17 — one `BizCity_Journal_Index::add()` + `BizCity_Journal_Filer` placement per item
	 * the bridge captured (succeeded or queued; duplicates and failures are skipped — a duplicate already has a
	 * row, a failure never reached the client's filestore at all, R-JN-2 "lưu gốc không bao giờ phụ thuộc thẻ"
	 * cuts the other way too: no gốc, no row).
	 *
	 * @param array $job       the cron job ({role, dedupe_key, ...}).
	 * @param array $card_meta keyed by capture_batch() item index: {card, idempotency_key, kind_hint}.
	 * @param array $res       capture_batch()'s return.
	 */
	private static function index_captured_items( array $job, int $owner, string $bridge_id, string $day_key, array $card_meta, array $res ): void {
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return;
		}
		$notebook_id = (int) ( $res['notebook_id'] ?? 0 );
		$role        = (string) ( $job['role'] ?? 'owner' );
		$rows        = array_merge( (array) ( $res['items'] ?? array() ), (array) ( $res['queued_jobs'] ?? array() ) );
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$idx = isset( $r['index'] ) ? (int) $r['index'] : -1;
			// [2026-10-21 12:05 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.124 — một tin NHÓM trùng nội dung (sha256) với tệp đã lưu từ chỗ khác vẫn được ghi một dòng sổ riêng (khoá theo tin):
			// trước đây bị bỏ qua nên tệp gửi vào nhóm "biến mất" khỏi sổ, tin CRM không nhận mã TL-, dòng "lưu lỗi" cũ không được mở lại. Chat riêng giữ luật cũ (đã có dòng).
			if ( ! empty( $r['duplicate'] ) && ! ( $idx >= 0 && isset( $card_meta[ $idx ] ) && is_array( $card_meta[ $idx ]['group'] ?? null ) ) ) {
				continue; // already indexed by the capture that first wrote this item.
			}
			if ( $idx < 0 || ! isset( $card_meta[ $idx ] ) ) {
				continue;
			}
			$meta = $card_meta[ $idx ];
			$card = (array) $meta['card'];
			$kind = class_exists( 'BizCity_Journal_Kinds' )
				? BizCity_Journal_Kinds::resolve( (string) ( $card['kind'] ?? '' ), (string) $meta['kind_hint'] )
				: 'common.document';
			$title = sanitize_text_field( (string) ( $card['title'] ?? '' ) );
			if ( '' === $title && '' !== (string) ( $r['file_name'] ?? '' ) ) {
				$title = sanitize_text_field( (string) $r['file_name'] ); // the file name beats a bare kind label in the register
			}
			// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J1 (R-JN-2, R-JN-16, doc 76 §2) — the row points at the original: attachment, doc code, size, sha256
			// (private store postmeta `_bizcity_sb_*`), so the cell registry can quote TL-… and re-send / deep-search ONE document.
			$attach_id = (int) ( $r['attachment_id'] ?? 0 );
			$original  = array( 'mime' => (string) $meta['kind_hint'], 'attachment_id' => $attach_id, 'file_name' => (string) ( $r['file_name'] ?? '' ) );
			if ( $attach_id > 0 && function_exists( 'get_post_meta' ) ) {
				$original['sha256'] = (string) get_post_meta( $attach_id, '_bizcity_sb_sha256', true );
				$original['size']   = (int) get_post_meta( $attach_id, '_bizcity_sb_size', true );
				$mime_real          = (string) get_post_meta( $attach_id, '_bizcity_sb_mime', true );
				if ( '' !== $mime_real ) {
					$original['mime'] = $mime_real;
				}
			}
			$grp   = is_array( $meta['group'] ?? null ) ? $meta['group'] : null; // PHASE-0.124 W2: tệp nhóm
			$entry = BizCity_Journal_Index::add( array(
				'owner_user_id'   => $owner,
				'kind'            => $kind,
				'title'           => $title,
				'summary'         => (string) ( $card['summary'] ?? ( $card['fields']['summary'] ?? '' ) ),
				'origin'          => null !== $grp ? array( 'surface' => 'zalo_group', 'ref' => '' !== (string) $grp['ref'] ? (string) $grp['ref'] : 'g:' . (string) $grp['gid'] ) : array( 'surface' => 'zalo_1_1', 'ref' => (string) ( $job['dedupe_key'] ?? '' ) ),
				'group'           => $grp,
				'learning'        => array( 'level' => $attach_id > 0 || (int) ( $r['kg_source_id'] ?? 0 ) > 0 ? 'L1' : 'L0', 'text_ready' => (int) ( $r['kg_source_id'] ?? 0 ) > 0 ),
				'doc_date'        => $card['doc_date'] ?? null,
				'subject'         => is_array( $card['subject'] ?? null ) ? $card['subject'] : array(),
				'tags'            => (array) ( $card['tags'] ?? array() ),
				'card'            => is_array( $card['fields'] ?? null ) ? $card['fields'] : array(),
				'confidence'      => isset( $card['confidence'] ) ? (float) $card['confidence'] : ( empty( $card ) ? 0.0 : 1.0 ),
				'original'        => $original,
				'notebook_id'     => $notebook_id,
				'kg_source_id'    => (int) ( $r['kg_source_id'] ?? 0 ),
				'source_type'     => null !== $grp ? 'group_capture' : 'owner_capture',
				'idempotency_key' => (string) $meta['idempotency_key'],
				'by'              => array( 'role' => $role ),
			) );
			if ( is_wp_error( $entry ) ) {
				continue;
			}
			// PHASE-0.124 W2: tin nhóm trên CRM trỏ về bản gốc đã giữ (group-transcript@1 `file_ref` = mã TL-)
			if ( null !== $grp && (int) ( $meta['crm_attachment_id'] ?? 0 ) > 0 && class_exists( 'BizCity_CRM_Group_Secretary', false ) ) {
				BizCity_CRM_Group_Secretary::mark_attachment_kept( (int) $meta['crm_attachment_id'], (string) ( $entry['original']['doc_code'] ?? '' ), (int) $entry['entry_id'] );
			}
			if ( 'failed' === (string) ( $entry['state'] ?? '' ) ) {
				// an earlier attempt on this key failed (Q121-13 row); this one kept the file ⇒ reopen the same row, never a second one
				BizCity_Journal_Index::file( (int) $entry['entry_id'], $owner, array( 'original' => $original, 'learning' => array( 'level' => 'L1' ) ) );
				$entry = BizCity_Journal_Index::set_state( (int) $entry['entry_id'], $owner, 'captured' );
				if ( is_wp_error( $entry ) ) {
					continue;
				}
			}
			if ( ! class_exists( 'BizCity_Journal_Filer' ) ) {
				continue;
			}
			$decision = BizCity_Journal_Filer::place( $entry );
			BizCity_Journal_Filer::apply( $decision, $owner, $bridge_id, (int) ( $r['kg_source_id'] ?? 0 ), $day_key );
		}
		// [2026-10-17 07:44 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-J3 (R-JN-14, Q121-13 chủ chốt) — a file that could not be kept still gets a row (state failed +
		// error code) so the owner can follow it on the register; a later successful retry on the same key is a replay of
		// this row, so the success loop above flips it back to captured (same row, never a second one).
		foreach ( (array) ( $res['failed'] ?? array() ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$idx = isset( $f['index'] ) ? (int) $f['index'] : -1;
			if ( $idx < 0 || ! isset( $card_meta[ $idx ] ) ) {
				continue;
			}
			$meta = $card_meta[ $idx ];
			$name = sanitize_text_field( (string) ( $f['file_name'] ?? '' ) );
			BizCity_Journal_Index::add( array(
				'owner_user_id'   => $owner,
				'kind'            => class_exists( 'BizCity_Journal_Kinds' ) ? BizCity_Journal_Kinds::resolve( '', (string) $meta['kind_hint'] ) : 'common.document',
				'title'           => '' !== $name ? $name : 'Tài liệu chưa lưu được',
				'state'           => 'failed',
				'error'           => array( 'code' => (string) ( $f['code'] ?? 'store_failed' ), 'message' => (string) ( $f['error'] ?? '' ) ),
				'original'        => array( 'mime' => (string) $meta['kind_hint'], 'file_name' => $name ),
				'origin'          => is_array( $meta['group'] ?? null ) ? array( 'surface' => 'zalo_group', 'ref' => 'g:' . (string) $meta['group']['gid'] ) : array( 'surface' => 'zalo_1_1', 'ref' => (string) ( $job['dedupe_key'] ?? '' ) ),
				'group'           => is_array( $meta['group'] ?? null ) ? $meta['group'] : null,
				'tags'            => is_array( $meta['group'] ?? null ) ? array( 'grp-' . (string) $meta['group']['gid'] ) : array(),
				'source_type'     => is_array( $meta['group'] ?? null ) ? 'group_capture' : 'owner_capture',
				'idempotency_key' => (string) $meta['idempotency_key'],
				'by'              => array( 'role' => $role ),
			) );
		}
	}

	/**
	 * Failed items of a capture_batch() result as [{kind, error}] (error text capped). Pure.
	 *
	 * @param mixed $res capture_batch() return
	 * @return array<int,array{kind:string,error:string}>
	 */
	public static function failed_items_summary( $res ): array {
		$out = array();
		foreach ( (array) ( is_array( $res ) ? ( $res['failed'] ?? array() ) : array() ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$out[] = array( 'kind' => (string) ( $f['kind'] ?? '' ), 'error' => substr( (string) ( $f['error'] ?? '' ), 0, 200 ) );
		}
		return $out;
	}

	// ── PHASE-0.121 S121-L3 — "Đã lưu an toàn" receipt ────────────────────

	/**
	 * The owner's receipt for the DOCUMENTS (kind `file`) of one capture batch, or null when the batch had none.
	 * Pure. Never carries a URL, a hash or a path — only file names, the short code and a reason in words.
	 *
	 * @param array $res capture_batch() return (items[], queued_jobs[], failed[]) or {failed[]} for a batch-level failure
	 * @return array{event_type:string,text:string,stored:int,duplicate:int,failed:int}|null
	 */
	public static function receipt( array $res ): ?array {
		$stored = array();
		$dups   = array();
		$failed = array();
		$name   = static function ( array $r ): string {
			$n = trim( wp_strip_all_tags( (string) ( $r['file_name'] ?? '' ) ) );
			return '' !== $n ? mb_substr( $n, 0, 80 ) : 'tài liệu';
		};
		$code   = static function ( array $r ): string {
			$id = (int) ( $r['attachment_id'] ?? 0 );
			return $id > 0 ? ' (' . ( class_exists( 'BizCity_KG_Private_File_Store' ) ? BizCity_KG_Private_File_Store::doc_code( $id ) : 'TL-' . $id ) . ')' : '';
		};
		foreach ( array_merge( (array) ( $res['items'] ?? array() ), (array) ( $res['queued_jobs'] ?? array() ) ) as $r ) {
			if ( ! is_array( $r ) || 'file' !== (string) ( $r['kind'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $r['duplicate'] ) ) {
				$dups[] = '- ' . $name( $r ) . $code( $r );
			} else {
				$stored[] = '- ' . $name( $r ) . $code( $r );
			}
		}
		foreach ( (array) ( $res['failed'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) || 'file' !== (string) ( $r['kind'] ?? '' ) ) {
				continue;
			}
			$why      = class_exists( 'BizCity_KG_Private_File_Store' ) ? BizCity_KG_Private_File_Store::reason_text( (string) ( $r['code'] ?? '' ) ) : 'lỗi khi lưu trên trang';
			// [2026-10-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.120 — mã lỗi thật đi kèm lý do chung ("lỗi khi lưu trên trang" không cho biết gì): để chủ/kỹ thuật thấy ngay nguyên nhân.
			$rc       = preg_replace( '/[^a-z0-9_]/i', '', (string) ( $r['code'] ?? '' ) );
			$detail   = trim( mb_substr( wp_strip_all_tags( (string) ( $r['error'] ?? '' ) ), 0, 160 ) );
			$failed[] = '- ' . $name( $r ) . ' — ' . $why . ( '' !== $rc ? ' [' . $rc . ( '' !== $detail ? ': ' . $detail : '' ) . ']' : '' );
		}
		if ( ! $stored && ! $dups && ! $failed ) {
			return null;
		}
		$parts = array();
		if ( $stored ) {
			$parts[] = '✅ Đã lưu an toàn ' . count( $stored ) . ' tài liệu vào Bộ não thứ hai (bản gốc chỉ anh/chị xem được):' . "\n" . implode( "\n", $stored );
		}
		if ( $dups ) {
			$parts[] = '📎 Đã có sẵn, em không lưu lại lần nữa:' . "\n" . implode( "\n", $dups );
		}
		if ( $failed ) {
			$parts[] = '⚠️ Chưa lưu được:' . "\n" . implode( "\n", $failed );
		}
		return array(
			'event_type' => ( $stored || $dups ) ? 'second_brain.stored' : 'second_brain.failed',
			'text'       => implode( "\n\n", $parts ),
			'stored'     => count( $stored ),
			'duplicate'  => count( $dups ),
			'failed'     => count( $failed ),
		);
	}

	/**
	 * The `channel-inbound@1.1.0` `job_result` letter carrying a receipt (same envelope as BizCity_Scheduler_Report_Back).
	 * `status` is always "done": the cell wraps a "failed" letter in automation wording ("Kế hoạch … dừng ở bước …"); the
	 * real outcome is `event_type` (second_brain.stored|failed). Pure.
	 */
	public static function receipt_letter( array $receipt, string $dedupe_key, string $user_hash, int $key_id, string $instance, int $blog_id ): array {
		$event_id = (int) ( sprintf( '%u', crc32( $dedupe_key ) ) % 2147483646 ) + 1;
		$channel  = array( 'platform' => 'scheduler', 'channel_ref' => $instance );
		return array(
			'contract'   => 'channel-inbound@1.1.0',
			'kind'       => 'job_result',
			'trace_id'   => 'sb.' . $blog_id . '.' . $event_id,
			'idem'       => 'sb_' . $blog_id . '_' . substr( hash( 'sha256', $dedupe_key ), 0, 32 ),
			'thread_key' => 'sb:' . $event_id,
			'tenant'     => array( 'key_id' => $key_id ),
			'channel'    => $channel,
			'contact'    => $channel + array( 'platform_uid' => $user_hash ),
			'job'        => array(
				'event_type'     => (string) $receipt['event_type'],
				'event_id'       => $event_id,
				'status'         => 'done',
				'compose'        => false,
				'origin_surface' => 'zalo_1_1',
				'data'           => array( 'text' => (string) $receipt['text'], 'artifacts' => array() ),
			),
		);
	}

	/**
	 * [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-2 (S121-R5, I-5) — `doc-register@1` rows of the documents KEPT in this capture (stored, not duplicates), looked up
	 * by their doc code. Pure apart from the injected lookup. ≤ 20 rows (one receipt).
	 *
	 * @param array    $res    capture_batch() return
	 * @param callable $lookup fn( string $doc_code ): ?array journal-entry@1.1.0
	 */
	public static function register_rows_for( array $res, callable $lookup ): array {
		$out = array();
		foreach ( array_merge( (array) ( $res['items'] ?? array() ), (array) ( $res['queued_jobs'] ?? array() ) ) as $r ) {
			if ( ! is_array( $r ) || 'file' !== (string) ( $r['kind'] ?? '' ) || ! empty( $r['duplicate'] ) || (int) ( $r['attachment_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$entry = $lookup( 'TL-' . (int) $r['attachment_id'] );
			if ( is_array( $entry ) && class_exists( 'BizCity_Journal_Index' ) ) {
				$out[] = BizCity_Journal_Index::register_row( $entry );
			}
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-2 (S121-R5, I-5) — does this person keep a `docs_v1` book? Reads the raw binding meta (the site TEMPLATES list
	 * learns docs_v1 together with the cell template, lane A2-3), or the filter `bizcity_second_brain_register_rows`.
	 */
	public static function wants_register_rows( int $user_id ): bool {
		$has = false;
		if ( $user_id > 0 && function_exists( 'get_user_meta' ) ) {
			$raw  = get_user_meta( $user_id, 'bizcity_sheet_bindings', true );
			$list = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : $raw;
			foreach ( is_array( $list ) ? $list : array() as $b ) {
				if ( is_array( $b ) && 'docs_v1' === (string) ( $b['template'] ?? '' ) ) {
					$has = true;
					break;
				}
			}
		}
		return (bool) apply_filters( 'bizcity_second_brain_register_rows', $has, $user_id );
	}

	/**
	 * [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 — doc code of the FIRST file kept (stored, not a duplicate) in a capture result, or ''. Pure.
	 */
	public static function first_kept_code( array $res ): string {
		$all = self::kept_codes( $res );
		return $all[0] ?? '';
	}

	/**
	 * [2026-10-22 07:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #5 — doc codes of the files KEPT (stored, not duplicates), filed rows (`items`) before
	 * the ones still queued, ≤ 20, no repeats. Pure.
	 *
	 * @return string[]
	 */
	public static function kept_codes( array $res ): array {
		$out = array();
		foreach ( array_merge( (array) ( $res['items'] ?? array() ), (array) ( $res['queued_jobs'] ?? array() ) ) as $r ) {
			if ( is_array( $r ) && 'file' === (string) ( $r['kind'] ?? '' ) && empty( $r['duplicate'] ) && (int) ( $r['attachment_id'] ?? 0 ) > 0 ) {
				$out[ 'TL-' . (int) $r['attachment_id'] ] = true;
			}
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/**
	 * PHASE-0.129 W2 — the brain.map link of a receipt (null when the brain map, the panel code or every row is absent, or the person switched it off).
	 * [2026-10-22 07:18 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #5 — tries each kept file until one has THIS owner's row (a row not filed yet, or another
	 * owner's row with the same code, no longer drops the link).
	 */
	private static function map_link_for( array $res, int $owner ): ?array {
		if ( ! class_exists( 'BizCity_Journal_Brain_Map' ) || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return null;
		}
		foreach ( self::kept_codes( $res ) as $code ) {
			$e = BizCity_Journal_Index::find_by_ref( $code, $owner );
			if ( is_array( $e ) && (int) ( $e['entry_id'] ?? 0 ) > 0 ) {
				return BizCity_Journal_Brain_Map::receipt_panel_link( $owner, (int) $e['entry_id'] );
			}
		}
		return null;
	}

	/** Best effort: a receipt that cannot be sent is logged, never retried in W1 (the notebook still has the file). */
	private static function send_receipt( array $job, int $owner, string $bridge_id, array $res ): void {
		// PHASE-0.124 W2: tệp nhóm lưu THEO LỆNH ⇒ chủ nhận biên nhận (kèm tên nhóm); tự lưu khi theo dõi ⇒ im lặng (không spam chủ)
		$origin    = (string) ( $job['origin'] ?? '' );
		$group_cmd = 'group_capture' === $origin && 'command' === (string) ( $job['trigger'] ?? '' );
		if ( ( 'owner_capture' !== $origin && ! $group_cmd ) || ! class_exists( 'BizCity_KG_Private_File_Store' ) || ! BizCity_KG_Private_File_Store::enabled() ) {
			return;
		}
		$receipt = self::receipt( $res );
		if ( null === $receipt ) {
			return;
		}
		if ( $group_cmd ) {
			$receipt['text'] = '📁 Nhóm ' . ( '' !== (string) ( $job['group_name'] ?? '' ) ? '"' . (string) $job['group_name'] . '"' : 'Zalo' ) . "\n" . $receipt['text'];
		}
		$ctx = array( 'account_id' => $bridge_id, 'event_type' => $receipt['event_type'], 'stored' => $receipt['stored'], 'duplicate' => $receipt['duplicate'], 'failed' => $receipt['failed'] );
		try {
			$hash = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::user_hash( $owner ) : '';
			if ( '' === $hash || ! class_exists( 'BizCity_Scheduler_Hub_Pipe' ) ) {
				self::log( 'second_brain_receipt_skipped', $ctx + array( 'reason' => '' === $hash ? 'no_user_hash' : 'hub_pipe_missing' ), 'warn' );
				return;
			}
			$target   = BizCity_Scheduler_Hub_Pipe::target();
			$key_id   = BizCity_Scheduler_Hub_Pipe::key_id();
			$instance = BizCity_Scheduler_Hub_Pipe::client_instance();
			if ( null === $target || $key_id <= 0 || '' === $instance ) {
				self::log( 'second_brain_receipt_skipped', $ctx + array( 'reason' => 'hub_not_ready' ), 'warn' );
				return;
			}
			BizCity_Scheduler_Hub_Pipe::ensure_registered( $target, $instance );
			$letter = self::receipt_letter( $receipt, (string) ( $job['dedupe_key'] ?? '' ), $hash, $key_id, $instance, (int) ( $job['blog_id'] ?? 0 ) );
			// [2026-10-17 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 B2-2 (S121-R5, I-5) — the sender keeps a docs_v1 book ("Sổ của tôi" › TaiLieu): the cell mirrors these rows
			// AFTER the journal rows exist (R-JN-15) and reports back with journal.mirror. No book ⇒ no key (older cells ignore it anyway).
			if ( self::wants_register_rows( $owner ) ) {
				$rows = self::register_rows_for( $res, static function ( string $code ) {
					$row = class_exists( 'BizCity_Journal_Database' ) ? BizCity_Journal_Database::instance()->get_by_source_ref( $code ) : null;
					return $row && class_exists( 'BizCity_Journal_Index' ) ? BizCity_Journal_Index::to_journal_entry( $row ) : null;
				} );
				if ( array() !== $rows ) {
					$letter['job']['register_rows'] = $rows;
				}
			}
			// [2026-10-22 01:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 W2 (brain-map@1 receipt_link) — 1-1 owner capture with a KEPT file: a one-time "Bản đồ não" link opening on
			// that row (the row exists now, so it can say "Đã lưu · TL-… · L1" honestly). The URL rides `job.panel_link` only: the cell sends it as its own fixed sentence and
			// never puts it in history / the log (R-PL-8). ≤ 1 / 10 min, off by the person. Older cells drop the unknown key.
			$panel_link = 'owner_capture' === $origin ? self::map_link_for( $res, $owner ) : null;
			if ( null !== $panel_link ) {
				$letter['job']['panel_link'] = $panel_link;
			}
			$ctx['map_link'] = null !== $panel_link;
			$out    = BizCity_Scheduler_Hub_Pipe::post_letter( $target, $letter );
			$ack    = is_array( $out['body'] ?? null ) ? $out['body'] : array();
			// [2026-10-22 07:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.129 reflect #4 — the 10-minute pause of the map link starts only when the receipt really reached the owner
			if ( null !== $panel_link && (int) $out['status'] >= 200 && (int) $out['status'] < 300 && ! empty( $ack['delivered'] ) && method_exists( 'BizCity_Journal_Brain_Map', 'mark_receipt_sent' ) ) {
				BizCity_Journal_Brain_Map::mark_receipt_sent( $owner );
			}
			self::log( 'second_brain_receipt_sent', $ctx + array(
				'http'        => (int) $out['status'],
				'ack'         => substr( sanitize_key( (string) ( $ack['status'] ?? $ack['code'] ?? '' ) ), 0, 40 ),
				'delivered'   => ! empty( $ack['delivered'] ),
				'skip_reason' => substr( sanitize_key( (string) ( $ack['skip_reason'] ?? '' ) ), 0, 40 ),
			), (int) $out['status'] >= 200 && (int) $out['status'] < 300 ? 'info' : 'warn' );
		} catch ( \Throwable $e ) {
			self::log( 'second_brain_receipt_failed', $ctx + array( 'reason' => get_class( $e ) ), 'error' );
		}
	}

	/** Job result: channel JSONL record + cron run meta (R-CRON-META §4 reason buckets). */
	private static function done( string $event, array $context, string $bucket = '', string $level = 'info' ): void {
		self::log( $event, $context, $level );
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		$cron = BizCity_Cron_Manager::instance();
		$cron->note( array_filter( array(
			'account_id'    => (string) ( $context['account_id'] ?? '' ),
			'reason_bucket' => $bucket,
			'counters'      => array_intersect_key( $context, array_flip( array( 'total', 'succeeded', 'queued', 'items' ) ) ),
		) ), self::JOB_ID );
		$cron->note_event( $event, $context, self::JOB_ID );
	}

	private static function log( string $event, array $context, string $level = 'info' ): void {
		// Structured writer (CONTEXT-BANK §1.2 rule 1). Every record carries the exact account: a record without it is
		// dropped (R-CH-10 `account_scope_required`).
		$account_id = (string) ( $context['account_id'] ?? '' );
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || '' === $account_id ) {
			return;
		}
		unset( $context['account_id'] );
		BizCity_Channel_File_Logger::write_record( array(
			'channel'   => BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
			'level'     => 'error' === $level ? BizCity_Channel_File_Logger::LEVEL_ERROR : BizCity_Channel_File_Logger::LEVEL_INFO,
			'event'     => $event,
			'stage'     => 'kg',
			'direction' => 'internal',
			'message'   => 'Zalo Personal knowledge capture.',
			'producer'  => array( 'module' => 'plugins/bizcity-zalo-personal', 'version' => '1.0.0' ),
			'account'   => array( 'account_id' => $account_id, 'scope' => 'exact' ),
			'context'   => $context,
		) );
	}

	private static function in_diagnostics_cli(): bool {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}
}
