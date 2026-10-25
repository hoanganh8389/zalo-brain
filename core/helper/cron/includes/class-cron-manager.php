<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_Manager — Phase 1 (Registry + Observability).
 *
 *   - register()       : declare a job (idempotent; UNIQUE on job_id).
 *   - all()            : list registered jobs + computed health (next/last run).
 *   - record_runs(*)   : internal hooks that wrap real handlers to log start/end.
 *   - gc_runs()        : retention sweep (runs nightly via own hook).
 *
 * IMPORTANT: this class does NOT call `wp_schedule_event` for you when
 * `enabled=false`, but for normal jobs it DOES — replacing the per-module
 * boilerplate. It also keeps the original hook name verbatim so existing
 * action listeners keep working without modification.
 *
 * Anti-patterns enforced: see core/cron/PHASE-CRON.md §6.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_Manager {

	const TABLE_REGISTRY = 'bizcity_cron_registry';
	// ⛔ R-TABLE-FREEZE (docs/rules/PHASE-0-RULE-TABLE-FREEZE.md) — bizcity_cron_runs / bizcity_cron_retries is FROZEN: never install, write or read it in new code; run log = JSONL cron file logger, fallback option bizcity_cron_last_runs; no retry queue.
	const TABLE_RUNS     = 'bizcity_cron_runs';
	const TABLE_RETRIES  = 'bizcity_cron_retries';
	// [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — new lock table (see TRACE-CRON-OVERLOAD-2026-07-26.md).
	// [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c — table quarantined + writer-stopped (rows stay);
	// name kept only so census/legacy tooling can resolve it. Locks now live in wp_options under LOCK_OPTION_PREFIX.
	const TABLE_LOCKS    = 'bizcity_cron_locks';
	const LOCK_OPTION_PREFIX = 'bizcity_cron_lock_';

	const DB_VERSION        = '1.3.0';
	const DB_VERSION_OPTION = 'bizcity_cron_db_version';

	/**
	 * [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — safety-net lock TTL. Not the
	 * expected job runtime — it's the max time before an orphaned lock
	 * (crashed/killed PHP process that never reached wrap_end) is auto-released
	 * so the job isn't stuck forever. Real completion always unlocks immediately.
	 */
	const LOCK_TTL_SECONDS = 15 * MINUTE_IN_SECONDS;

	/** Internal nightly GC hook. */
	const GC_HOOK = 'bizcity_cron_runs_gc';

	/** Retry dispatch hook (every 5 min). [2026-10-25] PHASE-0.126 R126-TB — retired; kept only so retire_retry_schedule() can clear it. */
	const RETRY_HOOK = 'bizcity_cron_retry_dispatch';

	/** [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — fallback run record (last run per job) when the JSONL logger is not writable. */
	const LAST_RUNS_OPTION = 'bizcity_cron_last_runs';

	/** wp_options key for registry fingerprint cache (CRON-PERF-1). */
	// [2026-06-09 Johnny Chu] CRON-PERF-1 — fingerprint option để skip batch write khi spec không đổi.
	const REGISTRY_FP_OPTION = 'bizcity_cron_registry_fp';
	// [2026-07-27 Johnny Chu] CRON-PERF-ADOPT — per-job fingerprints for adopt-only registry rows.
	const ADOPT_FP_OPTION_PREFIX = 'bizcity_cron_adopt_fp_';
	// [2026-07-27 Johnny Chu] CRON-PERF-ADOPT — invalidate adopt fingerprints after registry provisioning.
	const REGISTRY_GENERATION_OPTION = 'bizcity_cron_registry_generation';

	private static ?self $instance = null;

	/** @var array<string, array> in-memory copy of registered jobs (job_id => row). */
	private array $jobs = array();

	/** @var array<string, int> active run ids keyed by job_id (for the wrap_end callback). */
	private array $active_runs = array();

	/** @var array<string, float> */
	private array $active_started_at = array();

	/** @var array<string, array> */
	private array $active_meta = array();

	/** @var int */
	private int $last_virtual_run_id = 0;

	/**
	 * [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A
	 * @var array<string, bool> whether THIS request acquired the runtime lock for job_id (so wrap_end knows it owns the release).
	 */
	private array $lock_owned = array();

	/**
	 * [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A
	 * @var array<string, bool> whether job_id was locked-out (duplicate concurrent fire) THIS request — handlers may call is_locked_out() to bail early.
	 */
	private array $locked_out = array();

	/**
	 * [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c
	 * @var array<string, string> owner token of each lock THIS request acquired via try_lock() (job_id => token); unlock() needs it.
	 */
	private array $lock_tokens = array();

	/**
	 * @var array<string, array> static jobs pending deferred DB flush (job_id => row).
	 * [2026-06-09 Johnny Chu] CRON-PERF-1 — deferred để batch-write thay vì ghi ngay trong register().
	 */
	private array $pending_rows = array();

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Self-GC: keep runs table small.
		add_action( self::GC_HOOK, [ $this, 'gc_runs' ] );
		add_action( 'init', [ $this, 'ensure_gc_scheduled' ] );

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB (owner: "cron_retries bỏ hoàn toàn") — the retry queue is gone; unschedule its 5-min tick once.
		add_action( 'init', [ $this, 'retire_retry_schedule' ] );

		// [2026-06-09 Johnny Chu] CRON-PERF-1 — flush pending registry rows sau khi tất cả module đã register().
		// [2026-06-09 Johnny Chu] CRON-PERF-2 — CHỈ flush tại init:99, KHÔNG flush tại plugins_loaded.
		// Lý do: plugins_loaded:99 chỉ thấy subset jobs (các module register sớm), tính fp_A và lưu vào DB.
		// init:99 thấy full set (tất cả module), tính fp_B ≠ fp_A → INSERT lần 2. Request sau:
		// plugins_loaded:99 lại tính fp_A ≠ stored fp_B → INSERT lần 3 → loop mỗi request.
		// Với chỉ init:99: tất cả register() call (dù ở plugins_loaded hay init) đều đã vào pending_rows
		// trước khi init:99 fires → 1 fingerprint duy nhất → INSERT đúng 1 lần, mọi request sau skip.
		add_action( 'init', [ $this, 'flush_pending_registry' ], 99 );
	}

	public function ensure_gc_scheduled(): void {
		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::GC_HOOK );
		}
	}

	/** [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — clear the retired retry tick (no handler left); wp_next_scheduled() reads the autoloaded cron option. */
	public function retire_retry_schedule(): void {
		if ( wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_clear_scheduled_hook( self::RETRY_HOOK );
		}
	}

	/**
	 * Provisioner-friendly installer entry. Creates / heals both cron tables
	 * via the JSON changelog auto-create pipeline, then bumps the version
	 * option so the diagnostics page shows green.
	 *
	 * Registered via `bizcity_register_installers` filter (installer id `cron`).
	 */
	public static function maybe_install(): void {
		if ( ! class_exists( 'BizCity_Diagnostics_Auto_Create' ) ) {
			return; // diagnostics not loaded — soft-skip; will heal next pageload.
		}
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — TABLE_RUNS / TABLE_RETRIES are quarantined (run log = JSONL file logger,
		// fallback the bizcity_cron_last_runs option; retry queue retired): never created again, existing rows stay.
		$results = array(
			BizCity_Diagnostics_Auto_Create::run( self::TABLE_REGISTRY ),
		);
		// [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — provision lock table.
		// [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c — no longer: locks live in wp_options (try_lock()).

		$schema_changed = false;
		foreach ( $results as $result ) {
			$action = is_array( $result ) ? (string) ( $result['action'] ?? '' ) : '';
			if ( in_array( $action, array( 'created', 'altered', 'partial' ), true ) ) {
				$schema_changed = true;
				break;
			}
		}

		// [2026-07-30 Johnny Chu] R-PERF — do not rewrite options or invalidate
		// registry fingerprints after a steady-state noop provisioning pass.
		$current_version = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( $current_version !== self::DB_VERSION ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
		if ( ! $schema_changed ) {
			return;
		}

		// [2026-06-09 Johnny Chu] CRON-PERF-1 — invalidate fp khi bảng vừa được tạo lại để force re-seed.
		delete_option( self::REGISTRY_FP_OPTION );
		update_option( self::REGISTRY_GENERATION_OPTION, microtime( true ), false );
	}

	/**
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] R-PERF/self-heal — `maybe_install()` (bên trên) chỉ chạy
	 * được khi `BizCity_Diagnostics_Auto_Create` đã nạp, nhưng module đó (`core/diagnostics/bootstrap.php`,
	 * ~957KB/101 file) chỉ nạp trong `$_bizcity_diagnostics_ctx` (is_admin()/WP_CLI/trang diagnostics) —
	 * KHÔNG bao gồm `DOING_CRON`. `flush_pending_registry()` chạy ở `init:99`, tải qua cổng
	 * `$_bizcity_admin_ctx` (bao gồm DOING_CRON) rộng hơn nhiều. Kết quả: tenant nào INSERT lỗi đúng lúc
	 * WP-Cron tự chạy (đa số trường hợp thật — wp-cron.php do khách ghé site kích hoạt, rải đều theo
	 * blog_id) không bao giờ tự chữa được — `maybe_install()` return ngay vì thiếu class, mãi mãi.
	 * Log production 2026-09-18 xác nhận: mọi dòng `registry upsert failed` đều có `heal=auto_create_not_loaded`.
	 *
	 * Cố tình KHÔNG mở rộng `$_bizcity_diagnostics_ctx` sang DOING_CRON — sẽ nạp toàn bộ 101 file diagnostics
	 * trên MỌI tick WP-Cron của MỌI tenant, đúng chi phí mà cổng đó sinh ra để tránh (xem comment R-PERF
	 * tại bizcity-twin-ai.php). Thay vào đó: tự tạo đúng 1 bảng này bằng CREATE TABLE IF NOT EXISTS tối
	 * thiểu, khớp `core/diagnostics/changelog/core.cron.json`, không phụ thuộc Auto_Create. Không tạo
	 * TABLE_RUNS/RETRIES/LOCKS ở đây — các bảng đó không nằm trên đường ghi registry, và request admin/CLI
	 * kế tiếp vẫn sẽ tự chữa chúng qua `maybe_install()` như cũ.
	 *
	 * @return bool true nếu bảng tồn tại sau khi gọi (đã có sẵn hoặc vừa tạo xong).
	 */
	private static function ensure_registry_table_minimal(): bool {
		global $wpdb;
		if ( ! $wpdb ) { return false; }
		$t = $wpdb->prefix . self::TABLE_REGISTRY;
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
		$wpdb->suppress_errors( true );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$t} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id          VARCHAR(128) NOT NULL DEFAULT '',
			hook            VARCHAR(191) NOT NULL DEFAULT '',
			interval_key    VARCHAR(64)  NOT NULL DEFAULT '',
			owner           VARCHAR(128) NOT NULL DEFAULT '',
			description     TEXT NULL,
			singleton       TINYINT(1) NOT NULL DEFAULT 1,
			enabled         TINYINT(1) NOT NULL DEFAULT 1,
			retention_days  INT NOT NULL DEFAULT 7,
			registered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_job_id (job_id),
			KEY idx_owner (owner),
			KEY idx_hook (hook)
		) {$charset}" );
		$wpdb->suppress_errors( false );
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $t );
		}
		return $wpdb->last_error === '';
	}

	/**
	 * Register a cron job. Idempotent — calling twice with same job_id updates the row.
	 *
	 * @param array{
	 *   id:string, hook:string, interval:string,
	 *   owner?:string, description?:string,
	 *   singleton?:bool, enabled?:bool, retention?:int
	 * } $spec
	 * @return bool true on success / refresh, false on hard error.
	 */
	public function register( array $spec ): bool {
		$job_id   = (string) ( $spec['id']       ?? '' );
		$hook     = (string) ( $spec['hook']     ?? '' );
		$interval = (string) ( $spec['interval'] ?? '' );
		if ( $job_id === '' || $hook === '' || $interval === '' ) {
			return false;
		}

		$row = array(
			'job_id'         => $job_id,
			'hook'           => $hook,
			'interval_key'   => $interval,
			'owner'          => (string) ( $spec['owner']       ?? '' ),
			'description'    => (string) ( $spec['description'] ?? '' ),
			'singleton'      => ! empty( $spec['singleton'] ?? true )  ? 1 : 0,
			'enabled'        => ! empty( $spec['enabled']   ?? true )  ? 1 : 0,
			'retention_days' => (int)    ( $spec['retention']   ?? 7 ),
		);

		$this->jobs[ $job_id ] = $row + array( 'registered_at' => time() );

		$adopt_only = ! empty( $spec['adopt_only'] );
		// [2026-07-27 Johnny Chu] CRON-PERF-ADOPT — keep adopt-only jobs out of recurring scheduling,
		// but skip the registry upsert when this job specification is unchanged.
		if ( $adopt_only ) {
			$fp_option = self::ADOPT_FP_OPTION_PREFIX . md5( $job_id );
			$generation = (string) get_option( self::REGISTRY_GENERATION_OPTION, '' );
			$fp_new    = md5( serialize( $row ) . '|' . $generation );
			$fp_old    = (string) get_option( $fp_option, '' );
			if ( $fp_new !== $fp_old ) {
				$upserted = $this->upsert_registry_one( $row );
				if ( $upserted ) {
					update_option( $fp_option, $fp_new, true );
				}
			}
		} else {
			$this->pending_rows[ $job_id ] = $row;
		}

		// Backward-compat: only auto-schedule if enabled + singleton AND not in adopt mode.
		if ( ! $adopt_only && $row['enabled'] && $row['singleton'] && ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 5, $interval, $hook );
		}

		// Wire trace hooks (priority 1 = before, PHP_INT_MAX = after).
		// Guard so multiple register() calls don't stack listeners.
		$marker_pre  = '_bizcity_cron_pre_'  . md5( $job_id );
		$marker_post = '_bizcity_cron_post_' . md5( $job_id );
		if ( ! has_action( $hook, [ $this, $marker_pre ] ) ) {
			// Closures preserve $job_id so we can find the run record back.
			add_action( $hook, function () use ( $job_id ) {
				$this->wrap_start( $job_id );
			}, 1 );
			add_action( $hook, function () use ( $job_id ) {
				$this->wrap_end( $job_id, null );
			}, PHP_INT_MAX );
		}

		return true;
	}

	/**
	 * List jobs + computed health for the diagnostics page / MCP tool.
	 *
	 * @return array<int, array>
	 */
	public function all(): array {
		// Lazy discovery: any bizcity_* hook scheduled by other code paths gets
		// adopted into the registry so the diagnostics page shows the full picture.
		$this->discover_legacy_crons();

		$out = array();
		foreach ( $this->jobs as $job_id => $row ) {
			$next = wp_next_scheduled( $row['hook'] );
			$last = $this->last_run( $job_id );
			$out[] = array(
				'job_id'        => $job_id,
				'hook'          => $row['hook'],
				'interval_key'  => $row['interval_key'],
				'owner'         => $row['owner'],
				'description'   => $row['description'],
				'enabled'       => (bool) $row['enabled'],
				'next_run_at'   => $next ? (int) $next : 0,
				'last_run_at'   => $last['started_at_ts'] ?? 0,
				'last_status'   => $last['status']        ?? '',
				'last_duration' => $last['duration_ms']   ?? null,
				'last_error'    => $last['error']         ?? '',
			);
		}
		return $out;
	}

	/**
	 * Adopt a hook already scheduled by external code (does NOT call
	 * wp_schedule_event). Use when migrating legacy crons that still own their
	 * own scheduling lifecycle but need meta/trace + admin visibility.
	 */
	public function adopt( string $job_id, string $hook, string $interval, string $owner = '', string $description = '' ): bool {
		return $this->register( array(
			'id'          => $job_id,
			'hook'        => $hook,
			'interval'    => $interval,
			'owner'       => $owner ?: 'legacy',
			'description' => $description,
			'adopt_only'  => true,
		) );
	}

	/**
	 * Scan WP cron array, auto-adopt any bizcity-namespaced hook that isn't
	 * already in the registry. Runs at most once per request.
	 */
	private function discover_legacy_crons(): void {
		static $done = false;
		if ( $done ) { return; }
		$done = true;

		if ( ! function_exists( '_get_cron_array' ) ) { return; }
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) { return; }

		// Build hook → interval map + already-adopted hook set.
		$known_hooks = array();
		foreach ( $this->jobs as $j ) {
			$known_hooks[ $j['hook'] ] = true;
		}

		$prefixes = array( 'bizcity_', 'biz_', 'twf_', 'twinchat_', 'waic_', 'wai_', 'bzgoogle_' );

		foreach ( $cron as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) { continue; }
			foreach ( $hooks as $hook => $events ) {
				if ( isset( $known_hooks[ $hook ] ) ) { continue; }
				$match = false;
				foreach ( $prefixes as $p ) {
					// [2026-06-09 Johnny Chu] PHP74-COMPAT — str_starts_with là PHP 8.0+.
					if ( strpos( $hook, $p ) === 0 ) { $match = true; break; }
				}
				if ( ! $match ) { continue; }
				if ( ! is_array( $events ) ) { continue; }
				// Take the first event to read schedule.
				$ev = reset( $events );
				$interval = is_array( $ev ) && ! empty( $ev['schedule'] ) ? (string) $ev['schedule'] : 'hourly';
				$this->adopt(
					'auto.' . $hook,
					$hook,
					$interval,
					'discovered',
					'Auto-discovered legacy cron (not yet migrated to BizCity_Cron_Manager).'
				);
				$known_hooks[ $hook ] = true;
			}
		}
	}

	/* ───────────── internal ───────────── */

	/**
	 * Deferred flush: batch-write all pending static registry rows.
	 *
	 * Chỉ ghi DB khi fingerprint (md5 của toàn bộ spec) khác với cached option.
	 * Trường hợp bình thường (không đổi spec): 0 DB queries, chỉ đọc 1 autoloaded option.
	 *
	 * [2026-06-09 Johnny Chu] CRON-PERF-1 — Phase 2 fingerprint+batch optimization.
	 */
	public function flush_pending_registry(): void {
		if ( empty( $this->pending_rows ) ) { return; }
		// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE-FOLLOWUP — a REST API or
		// admin-ajax request has nothing to gain from syncing this registry synchronously (the
		// jobs it describes are static, hardcoded at plugin-boot time; no REST caller reads this
		// table within its own request). `REST_REQUEST` is not yet defined at `init` (WordPress
		// only sets it later, in `rest_api_loaded()` on `parse_request`), so detect the same thing
		// from the request URI instead. Rows simply stay in `$this->pending_rows` for this request
		// and flush on the next regular page/cron load — the fingerprint gate below still applies
		// there, so this changes nothing about *whether* the registry ends up in sync, only *when*.
		if ( self::is_latency_sensitive_request() ) { return; }

		// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE-FOLLOWUP v4 — fingerprint THEO TỪNG JOB,
		// không phải 1 md5 cho cả bộ. Query Monitor (blog 1511) cho thấy các loại request khác nhau đăng ký
		// SỐ job khác nhau (module intent/automation/kg.learning_sweep chỉ nạp ở một số request: 14 vs 18 job)
		// → md5 cả bộ dao động c0ea…↔44002… giữa 2 loại request → INSERT + UPDATE option ở GẦN NHƯ MỌI page
		// load, vô hạn. Giờ lưu map job_id => md5(row) và chỉ ghi các job mới / đổi spec thật sự; job vắng mặt
		// ở request này KHÔNG bị coi là thay đổi (không bao giờ xoá khỏi map/bảng ở đây).
		// v3 (giữ nguyên): không log mỗi lần lệch; chỉ log khi INSERT thất bại, có back-off 1h.
		$stored = get_option( self::REGISTRY_FP_OPTION, array() );
		if ( ! is_array( $stored ) ) { $stored = array(); } // định dạng cũ (1 chuỗi md5) → seed lại 1 lần.

		$changed = array();
		foreach ( $this->pending_rows as $job_id => $row ) {
			$row['owner']       = self::ascii_text( (string) $row['owner'] );
			$row['description'] = self::ascii_text( (string) $row['description'] );
			$fp = md5( serialize( $row ) );
			if ( ! isset( $stored[ $job_id ] ) || $stored[ $job_id ] !== $fp ) {
				$changed[ $job_id ] = array( 'row' => $row, 'fp' => $fp );
			}
		}
		if ( empty( $changed ) ) { return; } // không job nào đổi → 0 queries.
		ksort( $changed );

		$fp_changed = md5( serialize( array_map( static function ( $c ) { return $c['fp']; }, $changed ) ) );
		$fail_key   = 'bizcity_cron_registry_fail_fp';
		if ( get_transient( $fail_key ) === $fp_changed ) { return; } // đã thất bại gần đây với đúng bộ thay đổi này → chờ back-off.

		$rows = array_values( array_map( static function ( $c ) { return $c['row']; }, $changed ) );
		$ok   = $this->upsert_registry_batch( $rows );
		$heal = 'not_needed';
		if ( ! $ok ) {
			// Tự chữa 1 lần: tạo/heal bảng qua pipeline installer rồi thử lại. Xoá cache "bảng tồn tại" trước —
			// giá trị cũ (có thể sai do probe information_schema chạy nhầm shard, xem BizCity_Table_Metadata::route_hint)
			// sống tới 1h và khiến Auto_Create bỏ qua bước CREATE.
			global $wpdb;
			if ( $wpdb && function_exists( 'bizcity_tbl_invalidate' ) ) {
				bizcity_tbl_invalidate( $wpdb->prefix . self::TABLE_REGISTRY );
			}
			if ( class_exists( 'BizCity_Diagnostics_Auto_Create' ) ) {
				$heal = 'auto_create';
				self::maybe_install();
			} else {
				// [2026-09-18] Auto_Create không nạp trong ngữ cảnh này (điển hình: DOING_CRON — xem
				// comment ở ensure_registry_table_minimal() phía trên). Tự tạo đúng bảng registry, không
				// đợi request admin/CLI kế tiếp.
				$heal = self::ensure_registry_table_minimal() ? 'self_create' : 'self_create_failed';
			}
			$stored = array(); // bảng vừa được tạo/heal — không merge fingerprint cũ vào bản này.
			$ok     = $this->upsert_registry_batch( $rows );
		}
		if ( $ok ) {
			foreach ( $changed as $job_id => $c ) {
				$stored[ $job_id ] = $c['fp'];
			}
			ksort( $stored );
			// autoload=true vì được đọc mỗi request để so sánh.
			update_option( self::REGISTRY_FP_OPTION, $stored, true );
			return;
		}

		global $wpdb;
		set_transient( $fail_key, $fp_changed, HOUR_IN_SECONDS );
		error_log( sprintf(
			'[bizcity-cron] registry upsert failed (retry in 1h) — blog_id=%d table=%s heal=%s changed_jobs=%s error=%s',
			get_current_blog_id(),
			$wpdb ? $wpdb->prefix . self::TABLE_REGISTRY : '(no wpdb)',
			$heal,
			implode( ',', array_keys( $changed ) ),
			$wpdb && $wpdb->last_error !== '' ? $wpdb->last_error : '(none)'
		) );
	}

	/**
	 * Registry text is internal English; some tenant tables are latin1, where a single UTF-8 char
	 * (e.g. "→") makes wpdb reject the whole batch ("contains invalid data" — seen on blog 396).
	 */
	private static function ascii_text( string $text ): string {
		$text = strtr( $text, array( '→' => '->', '←' => '<-', '—' => '-', '–' => '-', '…' => '...', '“' => '"', '”' => '"', '‘' => "'", '’' => "'" ) );
		return (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text );
	}

	/**
	 * True for a REST API or admin-ajax request, checked at `init` (before WordPress itself
	 * defines `REST_REQUEST`/`DOING_AJAX` for every case — `DOING_AJAX` is set early enough to
	 * trust, but a REST call is only confirmed later in `rest_api_loaded()`, so this also checks
	 * the URL shape both pretty permalinks (`/wp-json/`) and the `?rest_route=` fallback use).
	 *
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE-FOLLOWUP.
	 */
	private static function is_latency_sensitive_request(): bool {
		if ( wp_doing_ajax() ) { return true; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return true; }
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( $uri === '' ) { return false; }
		return false !== strpos( $uri, '/wp-json/' ) || false !== strpos( $uri, 'rest_route=' );
	}

	/**
	 * Batch INSERT … ON DUPLICATE KEY UPDATE cho nhiều rows.
	 * Dùng cho flush_pending_registry() (Phase 1+2 CRON-PERF-1).
	 *
	 * [2026-06-09 Johnny Chu] CRON-PERF-1 — Strategy D: N rows → 1 query.
	 *
	 * @return bool true nếu query thành công (kể cả 0 rows affected), false nếu SQL error.
	 */
	private function upsert_registry_batch( array $rows ): bool {
		global $wpdb;
		if ( ! $wpdb || empty( $rows ) ) { return false; }
		$t = $wpdb->prefix . self::TABLE_REGISTRY;

		$placeholders = array();
		$values       = array();
		foreach ( $rows as $row ) {
			$placeholders[] = '(%s,%s,%s,%s,%s,%d,%d,%d)';
			$values[] = $row['job_id'];
			$values[] = $row['hook'];
			$values[] = $row['interval_key'];
			$values[] = $row['owner'];
			$values[] = $row['description'];
			$values[] = (int) $row['singleton'];
			$values[] = (int) $row['enabled'];
			$values[] = (int) $row['retention_days'];
		}

		$sql = 'INSERT INTO ' . $t
			 . ' (job_id,hook,interval_key,owner,description,singleton,enabled,retention_days) VALUES '
			 . implode( ',', $placeholders )
			 . ' ON DUPLICATE KEY UPDATE'
			 . ' hook=VALUES(hook),interval_key=VALUES(interval_key),owner=VALUES(owner),'
			 . 'description=VALUES(description),singleton=VALUES(singleton),'
			 . 'enabled=VALUES(enabled),retention_days=VALUES(retention_days)';

		$wpdb->suppress_errors( true );
		// PHP 7.4 compat: spread array vào prepare() variadic (PHP 5.6+, WP 3.5+).
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$values ) );
		$wpdb->suppress_errors( false );
		return false !== $result;
	}

	/**
	 * Single-row INSERT ON DUPLICATE KEY UPDATE (Strategy A).
	 * Dùng cho adopt_only / legacy-discovered jobs khi spec fingerprint thay đổi.
	 *
	 * [2026-06-09 Johnny Chu] CRON-PERF-1 — thay 2 queries (SELECT+UPDATE) bằng 1 IODKU.
	 */
	private function upsert_registry_one( array $row ): bool {
		global $wpdb;
		if ( ! $wpdb ) { return false; }
		$t = $wpdb->prefix . self::TABLE_REGISTRY;
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $t
			. ' (job_id,hook,interval_key,owner,description,singleton,enabled,retention_days)'
			. ' VALUES (%s,%s,%s,%s,%s,%d,%d,%d)'
			. ' ON DUPLICATE KEY UPDATE'
			. ' hook=VALUES(hook),interval_key=VALUES(interval_key),owner=VALUES(owner),'
			. 'description=VALUES(description),singleton=VALUES(singleton),'
			. 'enabled=VALUES(enabled),retention_days=VALUES(retention_days)',
			$row['job_id'],
			$row['hook'],
			$row['interval_key'],
			self::ascii_text( (string) $row['owner'] ),
			self::ascii_text( (string) $row['description'] ),
			(int) $row['singleton'],
			(int) $row['enabled'],
			(int) $row['retention_days']
		) );
		$wpdb->suppress_errors( false );
		return false !== $result;
	}

	private function wrap_start( string $job_id ): void {
		global $wpdb;
		if ( ! $wpdb ) { return; }

		// [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — acquire atomic per-job lock BEFORE
		// allowing the run to start. Prevents duplicate concurrent execution when WP
		// pseudo-cron double-fires the same due event (see TRACE-CRON-OVERLOAD-2026-07-26.md
		// root cause #2). `singleton` in register() only guards duplicate wp_schedule_event()
		// registration — it does NOT guard runtime concurrency, hence this separate lock.
		$acquired                    = $this->try_lock( $job_id );
		$this->lock_owned[ $job_id ] = $acquired;
		$this->locked_out[ $job_id ] = ! $acquired;

		if ( ! $acquired ) {
			if ( $this->is_filelog_primary_mode() && class_exists( 'BizCity_Cron_File_Logger' ) ) {
				// [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY — emit skipped
				// run directly to filelog when SQL run-log is disabled.
				BizCity_Cron_File_Logger::append( array(
					'type'        => 'end',
					'ts'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'run_id'      => $this->next_virtual_run_id(),
					'job_id'      => $job_id,
					'status'      => 'skipped',
					'duration_ms' => 0,
					'error'       => 'duplicate run skipped — lock already held by an in-flight run',
				) );
			} else {
				// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — no SQL run log any more: option fallback (last run per job).
				$this->remember_run( $job_id, 'skipped', 0, 'duplicate run skipped — lock already held by an in-flight run', array() );
			}
			// Intentionally do NOT set active_runs[$job_id] — note()/note_event() become
			// silent no-ops for this tick (current_run_id() returns 0), and the real
			// handler (priority 10, fires after this priority-1 hook) can call
			// is_locked_out($job_id) to bail out immediately instead of doing real work.
			return;
		}

		// [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY — virtual run ids, meta kept in memory until wrap_end.
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — always (bizcity_cron_runs is quarantined); when the JSONL
		// logger is not writable, wrap_end() keeps the last run per job in the bizcity_cron_last_runs option instead of SQL.
		$run_id = $this->next_virtual_run_id();
		$this->active_runs[ $job_id ]       = $run_id;
		$this->active_started_at[ $job_id ] = microtime( true );
		$this->active_meta[ $job_id ]       = array();

		if ( isset( $this->jobs[ $job_id ] ) ) {
			$job_row = $this->jobs[ $job_id ];
			do_action( 'bizcity_cron_run_started', $run_id, $job_id, array(
				'hook'  => (string) ( $job_row['hook']  ?? '' ),
				'owner' => (string) ( $job_row['owner'] ?? '' ),
			) );
		}
	}

	private function wrap_end( string $job_id, ?\Throwable $err ): void {
		global $wpdb;

		// [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — release the lock only if THIS request
		// acquired it (a locked-out duplicate run must never release a lock it doesn't own).
		if ( ! empty( $this->lock_owned[ $job_id ] ) ) {
			$this->unlock( $job_id );
		}
		unset( $this->lock_owned[ $job_id ], $this->locked_out[ $job_id ] );

		if ( ! $wpdb || empty( $this->active_runs[ $job_id ] ) ) { return; }
		$run_id = (int) $this->active_runs[ $job_id ];
		unset( $this->active_runs[ $job_id ] );

		$started_at_ts = isset( $this->active_started_at[ $job_id ] ) ? (float) $this->active_started_at[ $job_id ] : 0.0;
		unset( $this->active_started_at[ $job_id ] );
		$duration_ms = $started_at_ts > 0 ? max( 0, (int) round( ( microtime( true ) - $started_at_ts ) * 1000 ) ) : null;
		$status = $err ? 'error' : 'ok';
		$error_msg = $err ? $err->getMessage() : null;
		$meta = isset( $this->active_meta[ $job_id ] ) && is_array( $this->active_meta[ $job_id ] ) ? $this->active_meta[ $job_id ] : array();
		unset( $this->active_meta[ $job_id ] );

		if ( $this->is_filelog_primary_mode() ) {
			if ( ! empty( $meta ) ) {
				BizCity_Cron_File_Logger::append_meta( $run_id, $job_id, $meta );
			}
		} else {
			// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — log dir not writable: keep the last run (+ meta) in an option, never SQL.
			$this->remember_run( $job_id, $status, $duration_ms, (string) ( $error_msg ?? '' ), $meta, $started_at_ts );
		}

		// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — emit hook so file logger can write end line
		do_action( 'bizcity_cron_run_ended', $run_id, $job_id, $status, $duration_ms, (string) ( $error_msg ?? '' ) );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — fallback run record when the JSONL logger is not writable:
	 * one entry per job in the non-autoloaded option bizcity_cron_last_runs (bounded: meta ≤ 16 KB, ≤ 300 jobs).
	 */
	private function remember_run( string $job_id, string $status, ?int $duration_ms, string $error, array $meta, float $started_at_ts = 0.0 ): void {
		$all = get_option( self::LAST_RUNS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$encoded = wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 16384 ) {
			$meta = array( '_truncated' => true, 'size' => is_string( $encoded ) ? strlen( $encoded ) : 0 );
		}
		$all[ $job_id ] = array(
			'started_at_ts' => $started_at_ts > 0 ? (int) $started_at_ts : time(),
			'ended_at_ts'   => time(),
			'status'        => $status,
			'duration_ms'   => $duration_ms,
			'error'         => mb_substr( $error, 0, 2000 ),
			'meta'          => $meta,
		);
		if ( count( $all ) > 300 ) {
			$all = array_slice( $all, -300, null, true );
		}
		update_option( self::LAST_RUNS_OPTION, $all, false );
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — add $by to meta.counters[$key] of the running job (in memory;
	 * written by wrap_end). Replaces the publishers' SELECT on bizcity_cron_runs, which never matched a virtual run id.
	 */
	public function bump_counter( string $key, int $by = 1, ?string $job_id = null ): void {
		$target_job = $job_id ?: $this->current_job_id();
		if ( $target_job === '' || empty( $this->active_runs[ $target_job ] ) ) { return; }
		$prev = (int) ( $this->active_meta[ $target_job ]['counters'][ $key ] ?? 0 );
		$this->note( array( 'counters' => array( $key => $prev + $by ) ), $target_job );
	}

	/**
	 * Run $fn inside a synthetic cron run so handlers can use note() /
	 * note_event() even outside a real cron tick (admin QA harness / Lab).
	 *
	 * Writes a row to bizcity_cron_runs with the given $job_id (recommended
	 * prefix: 'lab.*') and returns the row id. Captures thrown exceptions but
	 * RE-THROWS them after marking the run as error (caller can decide).
	 *
	 * @param string   $job_id Synthetic job identifier (e.g. 'lab.automation.fire-now').
	 * @param callable $fn     Closure executed inside the run window.
	 * @return int Run row id (0 on DB failure).
	 */
	public function with_synthetic_run( string $job_id, callable $fn ): int {
		$this->wrap_start( $job_id );
		$run_id = (int) ( $this->active_runs[ $job_id ] ?? 0 );
		$err    = null;
		try {
			$fn();
		} catch ( \Throwable $e ) {
			$err = $e;
		}
		$this->wrap_end( $job_id, $err );
		if ( $err ) { throw $err; }
		return $run_id;
	}

	/**
	 * [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A
	 * [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c — the lock no longer needs its own table
	 * (bizcity_cron_locks is quarantined + writer-stopped; rows stay). One wp_options row per job instead:
	 *   option_name  = LOCK_OPTION_PREFIX . md5( job_id )  (UNIQUE key ⇒ the insert is the atomic check-and-set)
	 *   option_value = {"job":…,"token":…,"until":<unix>}  autoload = no
	 *
	 * Atomically try to acquire the runtime lock for $job_id. Same three outcomes as before:
	 *   - no row                   → INSERT IGNORE inserts            → acquired
	 *   - row whose `until` passed → guarded UPDATE … WHERE option_value = <the expired value>
	 *                                (only one racer can match it)    → acquired (took over)
	 *   - row still valid          → nothing changes                  → NOT acquired
	 * add_option() is deliberately NOT used: WordPress implements it as INSERT … ON DUPLICATE KEY UPDATE, which
	 * would overwrite a live lock under a race. A second try_lock() for a job this request already holds is refused,
	 * exactly like the table version.
	 *
	 * Fails OPEN (returns true) on DB error so a broken DB never silently disables cron.
	 *
	 * @param string $job_id
	 * @param int    $ttl_seconds Safety-net expiry; defaults to LOCK_TTL_SECONDS.
	 */
	public function try_lock( string $job_id, int $ttl_seconds = 0 ): bool {
		global $wpdb;
		if ( ! $wpdb || $job_id === '' ) { return true; }
		if ( $ttl_seconds <= 0 ) { $ttl_seconds = self::LOCK_TTL_SECONDS; }

		$table = $wpdb->options;
		$name  = self::lock_option_name( $job_id );
		$token = self::new_lock_token();
		$value = (string) wp_json_encode( array( 'job' => $job_id, 'token' => $token, 'until' => time() + $ttl_seconds ) );

		$acquired = null;
		$wpdb->suppress_errors( true );
		for ( $attempt = 0; $attempt < 2 && null === $acquired; $attempt++ ) {
			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$value
			) );
			if ( false === $inserted ) {
				break; // SQL error → fail-open below.
			}
			if ( 1 === (int) $wpdb->rows_affected ) {
				$acquired = true;
				break;
			}
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $name ) );
			if ( null === $held ) {
				continue; // released between our INSERT and SELECT — try the insert once more.
			}
			$held_row = json_decode( (string) $held, true );
			$until    = is_array( $held_row ) ? (int) ( $held_row['until'] ?? 0 ) : 0;
			if ( $until >= time() ) {
				$acquired = false; // valid lock held by another run.
				break;
			}
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				(string) $held
			) );
			$acquired = ( false !== $updated && 1 === (int) $wpdb->rows_affected );
		}
		$wpdb->suppress_errors( false );

		if ( null === $acquired ) {
			return true; // DB error / unresolved race → fail-open, don't block cron entirely.
		}
		if ( $acquired ) {
			$this->lock_tokens[ $job_id ] = $token;
		}
		return $acquired;
	}

	/**
	 * [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — release the lock for $job_id.
	 * Safe to call even if no lock exists (no-op).
	 *
	 * [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c — owner-checked: only the token this
	 * request received from try_lock() (or an explicit $token) can release it, so a run whose lock expired and was taken
	 * over can no longer delete the new owner's lock. Returns true when a lock row was removed.
	 */
	public function unlock( string $job_id, string $token = '' ): bool {
		global $wpdb;
		if ( ! $wpdb || $job_id === '' ) { return false; }
		if ( $token === '' ) {
			$token = (string) ( $this->lock_tokens[ $job_id ] ?? '' );
		}
		unset( $this->lock_tokens[ $job_id ] );
		if ( $token === '' ) {
			return false; // this request never acquired it.
		}
		$table = $wpdb->options;
		$name  = self::lock_option_name( $job_id );
		$wpdb->suppress_errors( true );
		$held     = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $name ) );
		$held_row = null === $held ? null : json_decode( (string) $held, true );
		$released = false;
		if ( is_array( $held_row ) && hash_equals( (string) ( $held_row['token'] ?? '' ), $token ) ) {
			$deleted  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s AND option_value = %s", $name, (string) $held ) );
			$released = ( false !== $deleted && 1 === (int) $wpdb->rows_affected );
		}
		$wpdb->suppress_errors( false );
		return $released;
	}

	/** [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c — fixed-length option key per job. */
	private static function lock_option_name( string $job_id ): string {
		return self::LOCK_OPTION_PREFIX . md5( $job_id );
	}

	/** [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c — unguessable per-acquire owner token. */
	private static function new_lock_token(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A
	 *
	 * Handlers of long-running / historically double-fired jobs should call this
	 * at the very top of their real callback (registered at normal priority, which
	 * fires AFTER the manager's priority-1 wrap_start()) and return early when true.
	 * Only meaningful for the CURRENT request/tick — not a general job status query.
	 */
	public function is_locked_out( string $job_id ): bool {
		return ! empty( $this->locked_out[ $job_id ] );
	}

	/**
	 * Return the active run id for the currently-executing job (if any). Useful
	 * for handlers / hook subscribers wanting to attach meta to the parent cron
	 * run row (R-CRON-META). Returns 0 outside a wrap_start/wrap_end window.
	 */
	public function current_run_id( ?string $job_id = null ): int {
		if ( $job_id !== null ) {
			return (int) ( $this->active_runs[ $job_id ] ?? 0 );
		}
		if ( empty( $this->active_runs ) ) { return 0; }
		// Return the most recently started (end of array).
		$ids = array_values( $this->active_runs );
		return (int) end( $ids );
	}

	/**
	 * Merge a structured JSON patch into the current run's meta column.
	 *
	 * R-CRON-META: every cron job MUST persist enough JSON evidence per run so
	 * that post-mortem analysis is possible (which FB page failed, which event
	 * id, Graph error code, token expired vs timeout vs missing perm, partial
	 * batch counts, etc.).
	 *
	 * Silent no-op when called outside an active run (safe to call from hooks
	 * that may fire both inside and outside cron context).
	 *
	 * @param array       $patch Top-level fields to deep-merge.
	 * @param string|null $job_id Optional explicit job id when multiple runs are nested.
	 */
	public function note( array $patch, ?string $job_id = null ): void {
		$run_id = $this->current_run_id( $job_id );
		if ( ! $run_id ) { return; }
		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — meta always lives in memory until wrap_end (no SQL merge_meta any more).
		$target_job = $job_id ?: $this->current_job_id();
		if ( $target_job === '' ) { return; }
		$current = isset( $this->active_meta[ $target_job ] ) && is_array( $this->active_meta[ $target_job ] ) ? $this->active_meta[ $target_job ] : array();
		if ( isset( $patch['__append_event'] ) ) {
			$evt = $patch['__append_event'];
			unset( $patch['__append_event'] );
			if ( ! isset( $current['events'] ) || ! is_array( $current['events'] ) ) {
				$current['events'] = array();
			}
			$current['events'][] = $evt;
			if ( count( $current['events'] ) > 200 ) {
				$current['events'] = array_slice( $current['events'], -200 );
			}
		}
		if ( ! empty( $patch ) ) {
			$current = $this->deep_merge( $current, $patch );
		}
		$this->active_meta[ $target_job ] = $current;
	}

	/**
	 * Append a timeline entry into meta.events[]. Same scope rules as note().
	 *
	 * @param string $name  Event name (e.g. 'graph_call', 'token_missing', 'item_done').
	 * @param array  $data  Arbitrary JSON-serialisable payload.
	 * @param string|null $job_id Optional explicit job id.
	 */
	public function note_event( string $name, array $data = array(), ?string $job_id = null ): void {
		$entry = array(
			'ts'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'name' => $name,
		);
		if ( $data ) { $entry['data'] = $data; }
		$this->note( array( '__append_event' => $entry ), $job_id );
	}

	private function deep_merge( array $base, array $patch ): array {
		foreach ( $patch as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] )
				&& ! $this->is_list( $v ) && ! $this->is_list( $base[ $k ] ) ) {
				$base[ $k ] = $this->deep_merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	private function is_list( array $a ): bool {
		if ( $a === array() ) { return true; }
		return array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * Fetch latest run row for a job (used by all() + probe).
	 *
	 * @return array{started_at_ts:int,status:string,duration_ms:?int,error:string}|array{}
	 */
	public function last_run( string $job_id ): array {
		if ( $this->is_filelog_primary_mode() && class_exists( 'BizCity_Cron_File_Logger' ) ) {
			static $file_last_run_index = null;
			if ( ! is_array( $file_last_run_index ) ) {
				$file_last_run_index = BizCity_Cron_File_Logger::last_runs_index( 2500 );
			}
			return isset( $file_last_run_index[ $job_id ] ) && is_array( $file_last_run_index[ $job_id ] )
				? $file_last_run_index[ $job_id ]
				: array();
		}

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — fallback: the bizcity_cron_last_runs option (no SQL run log any more).
		$all = get_option( self::LAST_RUNS_OPTION, array() );
		$row = is_array( $all ) && isset( $all[ $job_id ] ) && is_array( $all[ $job_id ] ) ? $all[ $job_id ] : array();
		if ( ! $row ) { return array(); }
		return array(
			'started_at_ts' => (int) ( $row['started_at_ts'] ?? 0 ),
			'status'        => (string) ( $row['status'] ?? '' ),
			'duration_ms'   => isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : null,
			'error'         => (string) ( $row['error'] ?? '' ),
		);
	}

	/**
	 * Nightly via GC_HOOK. [2026-10-25] PHASE-0.126 R126-TB — the run log is JSONL (its own GC) or the bounded option; only the lock sweep is left.
	 */
	public function gc_runs(): void {
		global $wpdb;
		if ( ! $wpdb ) { return; }
		$wpdb->suppress_errors( true );
		// [2026-07-26 Johnny Chu] CRON-LOCK-PHASE-A — sweep orphaned lock rows (crashed
		// process that never reached wrap_end/unlock). Harmless if it never accumulates —
		// this is just hygiene since try_lock() already self-heals expired rows on read.
		// [2026-10-10 11:14 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7c — sweep lock OPTIONS expired > 1 day
		// (guarded delete on the exact value, so a lock re-acquired meanwhile survives); the quarantined table is not touched.
		$stale = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
			$wpdb->esc_like( self::LOCK_OPTION_PREFIX ) . '%'
		), ARRAY_A );
		foreach ( $stale as $lock ) {
			$held = json_decode( (string) ( $lock['option_value'] ?? '' ), true );
			if ( is_array( $held ) && (int) ( $held['until'] ?? 0 ) >= time() - DAY_IN_SECONDS ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				(string) $lock['option_name'],
				(string) $lock['option_value']
			) );
		}
		$wpdb->suppress_errors( false );
	}

	/* ───────────── Phase 2: run-now (the retry queue left in PHASE-0.126 R126-TB) ───────────── */

	/**
	 * Immediately invoke a registered job's hook (synchronous). Used by admin
	 * "Run Now" button and the cron.run_one MCP tool. Returns ok/error info.
	 *
	 * @return array{ok:bool,job_id:string,duration_ms:int,error:string}
	 */
	public function run_now( string $job_id ): array {
		$t0 = microtime( true );
		if ( ! isset( $this->jobs[ $job_id ] ) ) {
			return [ 'ok' => false, 'job_id' => $job_id, 'duration_ms' => 0, 'error' => 'unknown_job' ];
		}
		$hook = (string) $this->jobs[ $job_id ]['hook'];
		try {
			do_action( $hook );
			return [
				'ok'          => true,
				'job_id'      => $job_id,
				'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'error'       => '',
			];
		} catch ( \Throwable $e ) {
			// [2026-10-25] PHASE-0.126 R126-TB — no retry queue: the caller (admin / MCP cron.run_one) sees the error and decides.
			return [
				'ok'          => false,
				'job_id'      => $job_id,
				'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'error'       => $e->getMessage(),
			];
		}
	}

	/**
	 * List recent runs for a job (admin UI + MCP).
	 *
	 * @return array<int,array>
	 */
	public function recent_runs( string $job_id = '', int $limit = 20 ): array {
		if ( $this->is_filelog_primary_mode() && class_exists( 'BizCity_Cron_File_Logger' ) ) {
			return BizCity_Cron_File_Logger::recent_runs( $job_id, $limit );
		}

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — fallback: the last run per job from the option, newest first (same row shape as the old SQL).
		$all  = get_option( self::LAST_RUNS_OPTION, array() );
		$rows = array();
		foreach ( is_array( $all ) ? $all : array() as $jid => $r ) {
			if ( ( $job_id !== '' && $jid !== $job_id ) || ! is_array( $r ) ) { continue; }
			$rows[] = array(
				'id'          => 0,
				'job_id'      => (string) $jid,
				'started_at'  => gmdate( 'Y-m-d H:i:s', (int) ( $r['started_at_ts'] ?? 0 ) ),
				'ended_at'    => gmdate( 'Y-m-d H:i:s', (int) ( $r['ended_at_ts'] ?? 0 ) ),
				'duration_ms' => $r['duration_ms'] ?? null,
				'status'      => (string) ( $r['status'] ?? '' ),
				'error'       => (string) ( $r['error'] ?? '' ),
				'meta'        => wp_json_encode( $r['meta'] ?? array() ),
			);
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $b['started_at'], $a['started_at'] ); } );
		return array_slice( $rows, 0, max( 1, min( 500, $limit ) ) );
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY — gate SQL
	 * run-log writes behind logger readiness.
	 * [2026-10-25] PHASE-0.126 R126-TB — no SQL path any more; now picks JSONL vs the option fallback. Cached per request:
	 * is_ready() probes the directory with tempnam() + unlink().
	 */
	private function is_filelog_primary_mode(): bool {
		static $ready = null;
		if ( null === $ready ) {
			$ready = class_exists( 'BizCity_Cron_File_Logger' ) && BizCity_Cron_File_Logger::is_ready();
		}
		return $ready;
	}

	private function next_virtual_run_id(): int {
		$next = (int) round( microtime( true ) * 1000000 );
		if ( $next <= $this->last_virtual_run_id ) {
			$next = $this->last_virtual_run_id + 1;
		}
		$this->last_virtual_run_id = $next;
		return $next;
	}

	private function current_job_id(): string {
		if ( empty( $this->active_runs ) ) {
			return '';
		}
		$job_id = '';
		foreach ( $this->active_runs as $jid => $rid ) {
			$job_id = (string) $jid;
		}
		return $job_id;
	}
}
