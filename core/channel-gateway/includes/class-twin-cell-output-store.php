<?php
/**
 * Cell outputs → the site's media library (and from there R2) — PHASE-0.106 S106-C5 (cell-output@1, D106-6/D106-7).
 *
 * The cell keeps a generated file 7 days. For the kinds that carry no customer data (image / audio / video — voice is audio) the site pulls the
 * bytes through the Hub once, as a WordPress attachment: the R2 mu-plugin (`bizcity-r2.php`, hooks `add_attachment`) then offloads it and the URL is
 * the CDN's, with no expiry. Documents (xlsx / docx / pptx / pdf / csv / md / html / txt) are NOT pulled: they may hold customer data and the bucket is
 * public (owner question Q106-4 is open), so they stay on the cell and are downloaded through the owner-checked `cell-chat/media` route.
 *
 * Flow: turn-complete stores `cell_output_created` ⇒ `do_action( 'bizcity_cell_output_created', … )` ⇒ `schedule()` ⇒ WP-Cron
 * `bizcity_cell_output_pull` ⇒ `pull()` ⇒ sideload ⇒ event `cell_output_stored` (parent = the created event) with the attachment id.
 * Idempotent: an output id already holding an attachment (meta `_bizcity_cell_output`) is never pulled twice.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 * // [2026-10-13 01:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.106 S106-C5 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Cell_Output_Store', false ) ) {
	return;
}

final class BizCity_Twin_Cell_Output_Store {

	const HOOK_CREATED = 'bizcity_cell_output_created';
	const HOOK_PULL    = 'bizcity_cell_output_pull';
	const META         = '_bizcity_cell_output';
	/** Kinds the site may copy (no customer data by nature); everything else stays on the cell. */
	/** PHASE-0.110 S110-L3: the cell's `cm_…` media id of the copy — the public `/outputs/<cm_…>/go` link finds the attachment by it. */
	const META_MEDIA   = '_bizcity_cell_output_media';
	const PULL_KINDS   =array( 'image', 'audio', 'video' );
	const MAX_BYTES    = 52428800; // 50 MB = the cell's own per-file cap
	const MAX_TRIES    = 3;
	/** Q106-8 (default): the copies of cell outputs a site keeps. Over it, the OLDEST copies go (the cell's 7-day file and the event stay; the list then shows them expired). */
	const QUOTA_BYTES  = 2147483648; // 2 GB
	/** option holding the last pull outcome (step + code), for the admin diagnostics of `GET /outputs` */
	const OPT_LAST     = 'bizcity_cell_output_last';

	/**
	 * Test seams: fetch(path, dest, max): array{status:int,bytes:int} · sideload(file[name,type,tmp_name,size], meta[]): int|WP_Error ·
	 * url(attachment_id): string · existing(output_id): int · schedule(args): void · ingest(event[]): string · user_hash(user_id, account_id): array{hash:string,target:string}
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;
	/** last sideload error text (diagnostics only) */
	private static $last_error = '';

	/** Last pull outcome, kept in an option for the admin diagnostics. Never throws. */
	private static function note( string $step, int $status, string $detail = '' ): void {
		if ( isset( self::$readers['note'] ) ) {
			call_user_func( self::$readers['note'], $step, $status, $detail );
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPT_LAST, array( 'at' => gmdate( 'c' ), 'step' => $step, 'status' => $status, 'detail' => $detail ), false );
		}
	}

	/**
	 * Admin diagnostics of the copy pipeline (shown in `GET /outputs` to administrators only): is the class loaded, is WP-Cron disabled, how many pulls wait and
	 * for how long, and the last pull outcome. No file names, ids or URLs.
	 *
	 * @return array{loaded:bool,cron_disabled:bool,pending:int,overdue_s:int,last:array}
	 */
	public static function diagnostics( int $now = 0 ): array {
		$now     = $now > 0 ? $now : time();
		$pending = 0;
		$overdue = 0;
		foreach ( self::cron_events() as $when => $ev ) {
			unset( $ev );
			++$pending;
			$overdue = max( $overdue, $now - (int) $when );
		}
		$last = function_exists( 'get_option' ) ? get_option( self::OPT_LAST, array() ) : array();
		return array(
			'loaded'        => true,
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'pending'       => $pending,
			'overdue_s'     => max( 0, $overdue ),
			'last'          => is_array( $last ) ? $last : array(),
		);
	}

	/**
	 * Run the waiting pulls NOW (administrators only, at most 5): WP-Cron needs a loopback request or a system cron, either of which a host may not give.
	 *
	 * @return int pulls run
	 */
	public static function run_pending( int $max = 5 ): int {
		$ran = 0;
		foreach ( self::cron_events() as $when => $ev ) {
			if ( $ran >= $max ) {
				break;
			}
			foreach ( $ev as $sig => $item ) {
				$args = (array) ( $item['args'] ?? array() );
				if ( function_exists( 'wp_unschedule_event' ) ) {
					wp_unschedule_event( (int) $when, self::HOOK_PULL, $args );
				}
				self::pull( $args[0] ?? array() );
				++$ran;
				unset( $sig );
			}
		}
		return $ran;
	}

	/**
	 * [2026-10-14 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.110 S110-L3 — attachment id of the copy of one cell media id (`cm_…`), 0 = not copied (yet).
	 * Test seam `by_media(media_id): int`.
	 */
	public static function attachment_of_media( string $media_id ): int {
		if ( isset( self::$readers['by_media'] ) ) {
			return (int) call_user_func( self::$readers['by_media'], $media_id );
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META_MEDIA, $media_id ) );
	}

	/**
	 * S110-L3: a visitor opened the link before the copy exists. If a pull for this media id waits in WP-Cron, make it run now WITHOUT blocking
	 * (`spawn_cron()` is a non-blocking loopback). Never pulls inline: a 50 MB video would hold the visitor's request. Test seam `nudge(media_id): bool`.
	 *
	 * @return bool a waiting pull was found
	 */
	public static function nudge_media( string $media_id ): bool {
		if ( isset( self::$readers['nudge'] ) ) {
			return (bool) call_user_func( self::$readers['nudge'], $media_id );
		}
		foreach ( self::cron_events() as $ev ) {
			foreach ( $ev as $item ) {
				$job = (array) ( ( (array) ( $item['args'] ?? array() ) )[0] ?? array() );
				if ( $media_id === (string) ( $job['output']['media_id'] ?? '' ) ) {
					if ( function_exists( 'spawn_cron' ) ) {
						spawn_cron();
					}
					return true;
				}
			}
		}
		return false;
	}

	/** @return array<int,array<string,array>> time ⇒ [sig ⇒ event] of this class's waiting pulls */
	private static function cron_events(): array {
		if ( isset( self::$readers['cron'] ) ) {
			return (array) call_user_func( self::$readers['cron'] );
		}
		$out  = array();
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $cron ) ? $cron : array() as $when => $hooks ) {
			if ( isset( $hooks[ self::HOOK_PULL ] ) && is_array( $hooks[ self::HOOK_PULL ] ) ) {
				$out[ (int) $when ] = $hooks[ self::HOOK_PULL ];
			}
		}
		return $out;
	}

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( self::HOOK_CREATED, array( __CLASS__, 'schedule' ), 10, 1 );
		add_action( self::HOOK_PULL, array( __CLASS__, 'pull' ), 10, 1 );
	}

	/**
	 * Self-heal (PHASE-0.106 S106-C5): the list of a user's outputs hands its rows here; every media row still `pending` (no copy yet, the cell still holds it) is queued for
	 * the pull again. So the copy does not depend on the turn-complete hook having fired (a partial deploy, a missed cron) - opening "Tệp đã tạo" is enough.
	 * Bounded: at most `$max` per call and 5 tries per output (a counter kept 6 h), so a file that can never be pulled (403, gone) is not retried forever.
	 *
	 * @param array<int,array<string,mixed>> $items rows of `list_cell_outputs()`
	 * @return int jobs queued
	 */
	public static function reconcile( array $items, int $user_id, int $max = 3 ): int {
		$queued = 0;
		foreach ( $items as $it ) {
			if ( $queued >= $max ) {
				break;
			}
			if ( 'pending' !== (string) ( $it['state'] ?? '' ) || '' === (string) ( $it['event_uuid'] ?? '' ) || ! self::should_pull( (string) ( $it['kind'] ?? '' ), (int) ( $it['bytes'] ?? 0 ) ) ) {
				continue;
			}
			$id = (string) ( $it['output_id'] ?? '' );
			if ( self::bump_tries( $id ) > 5 ) {
				continue;
			}
			self::schedule( array(
				'event_uuid' => (string) $it['event_uuid'],
				'user_id'    => $user_id,
				'account_id' => (string) ( $it['account_id'] ?? '' ),
				'session_id' => (string) ( $it['session_id'] ?? '' ),
				'trace_id'   => (string) ( $it['trace_id'] ?? '' ),
				'output'     => array( 'output_id' => $id, 'kind' => (string) $it['kind'], 'name' => (string) ( $it['name'] ?? '' ), 'mime' => (string) ( $it['mime'] ?? '' ), 'bytes' => (int) $it['bytes'], 'media_id' => (string) ( $it['media_id'] ?? '' ) ),
			) );
			++$queued;
		}
		return $queued;
	}

	/** Tries so far for one output (6 h counter), incremented. Test seam `tries(id): int`. */
	private static function bump_tries( string $output_id ): int {
		if ( isset( self::$readers['tries'] ) ) {
			return (int) call_user_func( self::$readers['tries'], $output_id );
		}
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return 1;
		}
		$key = 'bizcity_cop_' . substr( md5( $output_id ), 0, 20 );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 6 * 3600 );
		return $n;
	}

	/** Whether this kind is copied to the media library (pure). */
	public static function should_pull( string $kind, int $bytes ): bool {
		return in_array( $kind, self::PULL_KINDS, true ) && $bytes > 0 && $bytes <= self::MAX_BYTES;
	}

	/**
	 * Hook `bizcity_cell_output_created` — turn-complete tells which output was stored.
	 *
	 * @param array $job {event_uuid, user_id, account_id, session_id, trace_id, output: cell-output@1 row (cleaned)}
	 */
	public static function schedule( $job ): void {
		$job = is_array( $job ) ? $job : array();
		$o   = (array) ( $job['output'] ?? array() );
		if ( empty( $job['event_uuid'] ) || (int) ( $job['user_id'] ?? 0 ) <= 0 || ! self::should_pull( (string) ( $o['kind'] ?? '' ), (int) ( $o['bytes'] ?? 0 ) ) ) {
			self::note( 'schedule_skipped', 0, empty( $job['event_uuid'] ) ? 'no_event' : ( (int) ( $job['user_id'] ?? 0 ) <= 0 ? 'no_user' : 'kind_' . (string) ( $o['kind'] ?? '' ) ) );
			return;
		}
		self::note( 'scheduled', 0 );
		$args = array( array(
			'event_uuid' => (string) $job['event_uuid'],
			'user_id'    => (int) $job['user_id'],
			'account_id' => (string) ( $job['account_id'] ?? '' ),
			'session_id' => (string) ( $job['session_id'] ?? '' ),
			'trace_id'   => (string) ( $job['trace_id'] ?? '' ),
			'output'     => array_intersect_key( $o, array_flip( array( 'output_id', 'kind', 'name', 'mime', 'bytes', 'media_id' ) ) ),
			'tries'      => 0,
		) );
		if ( isset( self::$readers['schedule'] ) ) {
			call_user_func( self::$readers['schedule'], $args );
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::HOOK_PULL, $args ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK_PULL, $args );
		}
	}

	/**
	 * WP-Cron `bizcity_cell_output_pull`. Returns the attachment id (0 = nothing stored). A transient failure re-schedules itself up to MAX_TRIES
	 * (the Hub or cell may be restarting); a permanent one (file gone, wrong owner) just stops — the output stays listed as `pending` until it expires.
	 *
	 * @param array $job see schedule()
	 */
	public static function pull( $job ): int {
		$job = is_array( $job ) ? $job : array();
		$o   = (array) ( $job['output'] ?? array() );
		$id  = (string) ( $o['output_id'] ?? '' );
		if ( ! preg_match( '/^co_[a-f0-9]{24}$/', $id ) || ! preg_match( '/^cm_[a-f0-9]{24}$/', (string) ( $o['media_id'] ?? '' ) ) ) {
			return 0;
		}
		$user_id = (int) ( $job['user_id'] ?? 0 );
		if ( $user_id <= 0 || ! self::should_pull( (string) ( $o['kind'] ?? '' ), (int) ( $o['bytes'] ?? 0 ) ) ) {
			return 0;
		}
		$have = self::existing( $id );
		if ( $have > 0 ) {
			return $have;
		}
		self::note( 'start', 0 );
		$tmp = self::tmp_path();
		if ( '' === $tmp ) {
			return 0;
		}
		try {
			$r = self::fetch( $user_id, (string) ( $job['account_id'] ?? '' ), (string) $o['media_id'], $tmp );
			if ( 200 !== (int) $r['status'] || (int) $r['bytes'] <= 0 ) {
				self::note( 'fetch_failed', (int) $r['status'] );
				self::retry( $job, (int) $r['status'] );
				return 0;
			}
			$name = self::file_name( (string) ( $o['name'] ?? '' ), $id, (string) ( $o['mime'] ?? '' ) );
			$att  = self::sideload(
				array( 'name' => $name, 'type' => (string) ( $o['mime'] ?? '' ), 'tmp_name' => $tmp, 'size' => (int) $r['bytes'], 'error' => 0 ),
				array( self::META => $id, '_bizcity_cell_output_session' => (string) ( $job['session_id'] ?? '' ), '_bizcity_cell_output_user' => $user_id, '_bizcity_cell_output_bytes' => (int) $r['bytes'], self::META_MEDIA => (string) $o['media_id'] )
			);
			if ( ! is_int( $att ) || $att <= 0 ) {
				self::note( 'sideload_failed', 0, self::$last_error );
				return 0;
			}
			self::note( 'stored', 200 );
			self::record_stored( $job, $att );
			self::enforce_quota( $att );
			return $att;
		} finally {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Q106-8: keeps the total size of cell-output copies under the quota by deleting the oldest ones (never `$keep_id`, the one just stored).
	 * Test seams: copies(): list<array{id:int,bytes:int}> oldest first · delete(id): void.
	 *
	 * @return int attachments deleted
	 */
	public static function enforce_quota( int $keep_id = 0 ): int {
		$quota = defined( 'BIZCITY_CELL_OUTPUT_QUOTA_BYTES' ) ? (int) BIZCITY_CELL_OUTPUT_QUOTA_BYTES : self::QUOTA_BYTES;
		if ( function_exists( 'apply_filters' ) ) {
			$quota = (int) apply_filters( 'bizcity_cell_output_quota_bytes', $quota );
		}
		if ( $quota <= 0 ) {
			return 0; // 0 / negative = no limit
		}
		$copies = self::copies();
		$total  = 0;
		foreach ( $copies as $c ) {
			$total += (int) $c['bytes'];
		}
		$gone = 0;
		foreach ( $copies as $c ) {
			if ( $total <= $quota ) {
				break;
			}
			if ( (int) $c['id'] === $keep_id ) {
				continue;
			}
			self::delete_copy( (int) $c['id'] );
			$total -= (int) $c['bytes'];
			++$gone;
		}
		return $gone;
	}

	/** @return list<array{id:int,bytes:int}> every copy, oldest first */
	private static function copies(): array {
		if ( isset( self::$readers['copies'] ) ) {
			return (array) call_user_func( self::$readers['copies'] );
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC", self::META ) );
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$path  = function_exists( 'get_attached_file' ) ? (string) get_attached_file( (int) $id ) : '';
			$bytes = '' !== $path && is_file( $path ) ? (int) filesize( $path ) : (int) get_post_meta( (int) $id, '_bizcity_cell_output_bytes', true );
			$out[] = array( 'id' => (int) $id, 'bytes' => $bytes );
		}
		return $out;
	}

	private static function delete_copy( int $id ): void {
		if ( isset( self::$readers['delete'] ) ) {
			call_user_func( self::$readers['delete'], $id );
			return;
		}
		if ( function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( $id, true );
		}
	}

	/** `<random 16 hex>-<slug of the original name>.<ext>`: unguessable on the CDN, readable in the library. Pure given the inputs. */
	public static function file_name( string $name, string $output_id, string $mime ): string {
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$ext  = preg_match( '/^[a-z0-9]{2,5}$/', $ext ) ? $ext : self::ext_of_mime( $mime );
		$base = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) pathinfo( $name, PATHINFO_FILENAME ) ) );
		$base = trim( substr( $base, 0, 60 ), '-' );
		$rand = substr( hash( 'sha256', $output_id . '|' . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'bizcity' ) ), 0, 16 );
		return $rand . '-' . ( '' !== $base ? $base : 'cell-output' ) . '.' . $ext;
	}

	private static function ext_of_mime( string $mime ): string {
		$map = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'video/mp4' => 'mp4', 'video/webm' => 'webm' );
		return $map[ strtolower( $mime ) ] ?? 'bin';
	}

	private static function retry( array $job, int $status ): void {
		// 4xx = the file is gone / not ours: do not retry. 0 / 5xx = Hub or cell busy: a few more tries, spaced out.
		if ( $status >= 400 && $status < 500 ) {
			return;
		}
		$tries = (int) ( $job['tries'] ?? 0 ) + 1;
		if ( $tries >= self::MAX_TRIES ) {
			return;
		}
		$job['tries'] = $tries;
		if ( isset( self::$readers['schedule'] ) ) {
			call_user_func( self::$readers['schedule'], array( $job ) );
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 60 * $tries, self::HOOK_PULL, array( $job ) );
		}
	}

	private static function tmp_path(): string {
		if ( isset( self::$readers['tmp'] ) ) {
			return (string) call_user_func( self::$readers['tmp'] );
		}
		if ( function_exists( 'wp_tempnam' ) ) {
			if ( ! function_exists( 'download_url' ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			return (string) wp_tempnam( 'cell-output' );
		}
		return (string) tempnam( sys_get_temp_dir(), 'cell-output' );
	}

	/** @return array{status:int,bytes:int} */
	private static function fetch( int $user_id, string $account_id, string $media_id, string $dest ): array {
		if ( isset( self::$readers['fetch'] ) ) {
			return (array) call_user_func( self::$readers['fetch'], $user_id, $account_id, $media_id, $dest );
		}
		if ( ! class_exists( 'BizCity_Twin_Web_Turn' ) ) {
			return array( 'status' => 0, 'bytes' => 0 );
		}
		return BizCity_Twin_Web_Turn::cell_chat_media_to_file( $user_id, $account_id, $media_id, $dest, self::MAX_BYTES );
	}

	private static function existing( string $output_id ): int {
		if ( isset( self::$readers['existing'] ) ) {
			return (int) call_user_func( self::$readers['existing'], $output_id );
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META, $output_id ) );
	}

	/** @return int|WP_Error */
	private static function sideload( array $file, array $meta ) {
		if ( isset( self::$readers['sideload'] ) ) {
			return call_user_func( self::$readers['sideload'], $file, $meta );
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$id = media_handle_sideload( $file, 0, null, array( 'post_status' => 'inherit' ) );
		if ( is_wp_error( $id ) ) {
			self::$last_error = substr( (string) $id->get_error_code() . ': ' . (string) $id->get_error_message(), 0, 160 );
			return 0;
		}
		foreach ( $meta as $k => $v ) {
			update_post_meta( (int) $id, (string) $k, $v );
		}
		return (int) $id;
	}

	/** Attachment URL (the CDN's once R2 offloaded it; the site's otherwise) — '' when unknown. */
	public static function url_of( int $attachment_id ): string {
		if ( isset( self::$readers['url'] ) ) {
			return (string) call_user_func( self::$readers['url'], $attachment_id );
		}
		return function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $attachment_id ) : '';
	}

	/** The `cell_output_stored` child event of a created one. */
	private static function record_stored( array $job, int $attachment_id ): void {
		$o     = (array) $job['output'];
		$event = array(
			'event_uuid'        => self::uuid( (string) $job['event_uuid'] . ':stored' ),
			'event_type'        => 'cell_output_stored',
			'event_source'      => 'server',
			'trace_id'          => (string) ( $job['trace_id'] ?? '' ),
			'session_id'        => '' !== (string) ( $job['session_id'] ?? '' ) ? (string) $job['session_id'] : null,
			'user_id'           => (int) $job['user_id'],
			'blog_id'           => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null,
			'parent_event_uuid' => (string) $job['event_uuid'],
			'created_epoch_ms'  => (int) ( microtime( true ) * 1000 ),
			'payload'           => array( 'output_id' => (string) $o['output_id'], 'attachment_id' => $attachment_id, 'bytes' => (int) ( $o['bytes'] ?? 0 ), 'kind' => (string) ( $o['kind'] ?? '' ) ),
		);
		try {
			if ( isset( self::$readers['ingest'] ) ) {
				call_user_func( self::$readers['ingest'], $event );
			} elseif ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
				BizCity_Twin_Event_Bus::ingest_remote( $event );
			}
		} catch ( \Throwable $e ) {
			// the attachment exists (meta marks it, so a retry never pulls twice); only the child event is missing, so the list keeps showing the file as `pending` until the cell's copy expires
		}
	}

	private static function uuid( string $key ): string {
		$h = sha1( 'cell-output-stored|' . $key );
		return sprintf( '%s-%s-5%s-%s%s-%s', substr( $h, 0, 8 ), substr( $h, 8, 4 ), substr( $h, 13, 3 ), dechex( ( hexdec( $h[16] ) & 0x3 ) | 0x8 ), substr( $h, 17, 3 ), substr( $h, 20, 12 ) );
	}
}
