<?php
/**
 * WP-CLI command family for the BizCity Twin framework.
 *
 * @package BizCity_Twin_AI
 * @since 1.3.7 PHASE-1.31
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Framework_CLI' ) ) {
	final class BizCity_Framework_CLI {

		const VERDICT_CONTRACT = 'diagnostics-verdict';
		const VERDICT_VERSION   = '1';

		public static function register(): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — register one framework command family.
			if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
				return;
			}

			// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-CLI — only the server ops commands ship (status, health — the production evidence surface named by R-DIAG-LOCAL —,
			// crm archive-pointers, cron, knowledge repair, brain). diagnostics / probe / sdk-check / memory / contracts / plugin / make:* need
			// core/diagnostics or node and live in the dev-only class-bizcity-framework-cli-dev.php; tools / tool-index left with Tool_Index (R126-TC).
			\WP_CLI::add_command( 'bizcity status', [ __CLASS__, 'status' ] );
			\WP_CLI::add_command( 'bizcity health', [ __CLASS__, 'health' ] );
			\WP_CLI::add_command( 'bizcity crm archive-pointers', [ __CLASS__, 'crm_archive_pointers' ] );
			\WP_CLI::add_command( 'bizcity cron', [ 'BizCity_Framework_CLI_Cron', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity knowledge', [ 'BizCity_Framework_CLI_Knowledge', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity brain', [ 'BizCity_Framework_CLI_Brain', 'dispatch' ] );
			if ( is_readable( __DIR__ . '/class-bizcity-framework-cli-dev.php' ) ) {
				require_once __DIR__ . '/class-bizcity-framework-cli-dev.php';
				if ( class_exists( 'BizCity_Framework_CLI_Dev', false ) ) {
					BizCity_Framework_CLI_Dev::register();
				}
			}
		}

		public static function status( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — expose read-only framework status.
			$origin = self::switch_blog( $assoc_args );
			try {
				$last = class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ? BizCity_Diagnostics_Smoke_Runner::get_last_results() : [];
				$last_smoke = get_option( 'bizcity_diag_last_smoke', array() );
				if ( is_array( $last_smoke ) && isset( $last_smoke['pass'], $last_smoke['fail'], $last_smoke['skipped'] ) ) {
					// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — report the latest run aggregate instead of summing capped/stale per-probe history.
					$counts = array(
						'pass' => (int) $last_smoke['pass'],
						'warn' => (int) ( $last_smoke['warn'] ?? 0 ),
						'fail' => (int) $last_smoke['fail'],
						'skip'  => (int) $last_smoke['skipped'],
					);
				} else {
					$counts = self::count_results( $last );
				}
				$modules = class_exists( 'BizCity_Module_Registry' ) ? BizCity_Module_Registry::instance()->inventory() : [];
				$payload = [
					'contract' => self::VERDICT_CONTRACT,
					'version' => self::VERDICT_VERSION,
					'blog_id' => (int) get_current_blog_id(),
					'env' => [ 'php' => PHP_VERSION, 'wordpress' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '', 'multisite' => is_multisite() ],
					'modules' => self::module_summary( $modules ),
					'last_diagnostics' => [ 'counts' => $counts, 'verdict' => self::verdict_from_counts( $counts ) ],
				];
			} finally {
				self::restore_blog( $origin );
			}
			self::emit( $payload, $assoc_args, 0 );
		}

		public static function health( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — run the bounded local health allowlist.
			// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-13 B-11 — Diagnostics ships only in local dev (D-35):
			// without the engine, answer an explicit skip (never pass, never a usage error).
			// [2026-10-24 12:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L2 (doc 20 §6) — journal_tools answers truthfully on a SERVER too (ready | missing + reason), in both branches; never a skip.
			$journal_tools = class_exists( 'BizCity_Journal_MCP_Tools' ) && method_exists( 'BizCity_Journal_MCP_Tools', 'door_state' )
				? BizCity_Journal_MCP_Tools::door_state()
				: [ 'journal' => 'unknown', 'reason' => 'journal_tools_class_absent' ];
			if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
				$payload = self::aggregate(
					[ [ 'id' => 'diagnostics.package', 'status' => 'skip', 'error' => 'diagnostics_package_absent', 'skip_reason' => 'diagnostics_package_absent', 'duration_ms' => 0 ] ],
					[ 'command' => 'health', 'health_degraded' => true, 'health_skip_reasons' => [ 'diagnostics.package:diagnostics_package_absent' ], 'fix' => 'Diagnostics runs in local development only (D-35). Run `wp bizcity health` there.', 'journal_tools' => $journal_tools ]
				);
				self::emit( $payload, $assoc_args, self::exit_code( $payload, $assoc_args ) );
				return;
			}
			$origin = self::switch_blog( $assoc_args );
			try {
			$ids = [
				'core.module-registry',
				'core.loader.registration_integrity',
				'core.loader.ownership',
				'core.helper.error_ux',
				'core.wp_hook.callback_integrity',
			];
			$catalog = BizCity_Diagnostics_Smoke_Runner::catalog();
			$results = [];
			foreach ( $ids as $id ) {
				if ( ! isset( $catalog[ $id ] ) ) {
					$results[] = [ 'id' => $id, 'status' => 'skip', 'error' => 'Health probe is not registered.', 'duration_ms' => 0 ];
					continue;
				}
				$results[] = BizCity_Diagnostics_Smoke_Runner::run_probe( $id, [ 'skip_network' => true ] );
			}
			$payload = self::aggregate( $results, [ 'command' => 'health', 'journal_tools' => $journal_tools ] ); // [2026-10-24 12:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.131 S131-L2
			// [2026-08-29 Johnny Chu] PHASE-1.32-CI-HEALTH — intentional health precondition skips degrade to WARN with explicit evidence; only an executed FAIL blocks the health smoke.
			if ( (string) ( $payload['verdict'] ?? '' ) === 'skip' ) {
				$skipped = array();
				foreach ( (array) ( $payload['results'] ?? array() ) as $health_result ) {
					if ( is_array( $health_result ) && (string) ( $health_result['status'] ?? '' ) === 'skip' ) {
						$skipped[] = (string) ( $health_result['id'] ?? 'unknown' ) . ':' . (string) ( $health_result['error'] ?? $health_result['skip_reason'] ?? 'precondition_skip' );
					}
				}
				$payload['verdict'] = 'warn';
				$payload['health_degraded'] = true;
				$payload['health_skip_reasons'] = $skipped;
			}
			} finally {
				self::restore_blog( $origin );
			}
			self::emit( $payload, $assoc_args, self::exit_code( $payload, $assoc_args ) );
		}

		/**
		 * Plan or apply bounded legacy CRM archive receipt pointer repairs.
		 *
		 * @param array<int,string> $args
		 * @param array<string,mixed> $assoc_args
		 * @return void
		 */
		public static function crm_archive_pointers( array $args, array $assoc_args ): void {
			// [2026-09-21 06:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.56-H-11 — canonical bounded receipt maintenance command; dry-run by default and explicit confirmation required for writes.
			self::require_class( 'BizCity_Channel_Conversation_Archive', 'Channel archive owner' );
			$origin = self::switch_blog( $assoc_args );
			try {
				$limit = isset( $assoc_args['limit'] ) ? max( 1, min( 100, (int) $assoc_args['limit'] ) ) : 100;
				$apply = ! empty( $assoc_args['apply'] );
				$confirm = (string) ( $assoc_args['confirm'] ?? '' );
				if ( $apply && 'REPAIR_LEGACY_POINTERS' !== $confirm ) {
					self::usage_error( 'Apply requires --confirm=REPAIR_LEGACY_POINTERS.' );
				}
				$payload = BizCity_Channel_Conversation_Archive::reconcile_legacy_receipt_pointers( $limit, ! $apply );
				$payload['command'] = 'crm archive-pointers';
				$payload['blog_id'] = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				$payload['mode'] = $apply ? 'apply' : 'dry_run';
				$payload['confirmation_required'] = 'REPAIR_LEGACY_POINTERS';
			} finally {
				self::restore_blog( $origin );
			}
			self::emit( array( 'contract' => self::VERDICT_CONTRACT, 'version' => self::VERDICT_VERSION, 'verdict' => ! empty( $payload['ok'] ) && empty( $payload['errors'] ) ? 'pass' : 'fail', 'results' => array( $payload ) ), $assoc_args, ! empty( $payload['ok'] ) && empty( $payload['errors'] ) ? 0 : 1 );
		}

		public static function usage_error( string $message ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — preserve WP-CLI usage exit code 2.
			\WP_CLI::error( $message, false );
			\WP_CLI::halt( 2 );
		}

		public static function aggregate( array $results, array $extra = [] ): array {
			$normalized = [];
			foreach ( $results as $result ) {
				$status = strtolower( (string) ( $result['status'] ?? 'fail' ) );
				if ( $status === 'precheck-fail' || $status === 'skipped' ) {
					$status = 'skip';
				}
				if ( ! in_array( $status, [ 'pass', 'warn', 'fail', 'skip' ], true ) ) {
					$status = 'fail';
				}
				$result['status'] = $status;
				$normalized[] = $result;
			}
			$counts = self::count_results( $normalized );
			return array_merge(
				[ 'contract' => self::VERDICT_CONTRACT, 'version' => self::VERDICT_VERSION, 'results' => $normalized, 'counts' => $counts, 'verdict' => self::verdict_from_counts( $counts ) ],
				$extra
			);
		}

		private static function count_results( array $results ): array {
			$counts = [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 ];
			foreach ( $results as $result ) {
				$status = strtolower( (string) ( $result['status'] ?? 'fail' ) );
				if ( $status === 'precheck-fail' || $status === 'skipped' ) {
					$status = 'skip';
				}
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ]++;
				} else {
					$counts['fail']++;
				}
			}
			return $counts;
		}

		private static function verdict_from_counts( array $counts ): string {
			if ( ! empty( $counts['fail'] ) ) {
				return 'fail';
			}
			if ( ! empty( $counts['warn'] ) ) {
				return 'warn';
			}
			// [2026-08-28 Johnny Chu] PHASE-1.31 — never collapse an unavailable probe set into PASS.
			return ! empty( $counts['skip'] ) ? 'skip' : 'pass';
		}

		public static function exit_code( array $payload, array $assoc_args ): int {
			$verdict = (string) ( $payload['verdict'] ?? 'fail' );
			if ( $verdict === 'fail' || ( $verdict === 'warn' && ! empty( $assoc_args['strict'] ) ) ) {
				return 1;
			}
			return 0;
		}

		public static function emit( array $payload, array $assoc_args, int $exit_code ): void {
			if ( self::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				if ( isset( $payload['results'] ) ) {
					foreach ( $payload['results'] as $result ) {
						\WP_CLI::log( sprintf( '[%s] %s', strtoupper( (string) $result['status'] ), (string) ( $result['label'] ?? $result['id'] ?? '' ) ) );
					}
				}
				\WP_CLI::log( sprintf( 'Verdict: %s', strtoupper( (string) ( $payload['verdict'] ?? 'pass' ) ) ) );
			}
			if ( $exit_code !== 0 ) {
				\WP_CLI::halt( $exit_code );
			}
		}

		public static function switch_blog( array $assoc_args ): int {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — honor explicit multisite blog scope.
			$target = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;
			$origin = (int) get_current_blog_id();
			if ( $target <= 0 || ! is_multisite() || $target === $origin ) {
				return 0;
			}
			if ( ! function_exists( 'get_site' ) || ! get_site( $target ) ) {
				self::usage_error( 'Requested blog does not exist.' );
			}
			switch_to_blog( $target );
			return $origin;
		}

		public static function restore_blog( int $origin ): void {
			if ( $origin > 0 && is_multisite() && get_current_blog_id() !== $origin ) {
				restore_current_blog();
			}
		}

		public static function wants_json( array $assoc_args ): bool {
			return ! empty( $assoc_args['json'] ) || ( isset( $assoc_args['format'] ) && strtolower( (string) $assoc_args['format'] ) === 'json' );
		}

		private static function require_class( string $class, string $label ): void {
			if ( ! class_exists( $class ) ) {
				self::usage_error( $label . ' is unavailable in this WordPress runtime.' );
			}
		}

		private static function module_summary( array $modules ): array {
			$booted = 0;
			foreach ( $modules as $module ) {
				if ( ! empty( $module['booted'] ) ) {
					$booted++;
				}
			}
			return [ 'total' => count( $modules ), 'booted' => $booted, 'failed' => count( $modules ) - $booted ];
		}

	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Cron' ) ) {
	final class BizCity_Framework_CLI_Cron {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — expose the canonical cron registry.
			if ( ( $args[0] ?? 'check' ) !== 'check' || ! class_exists( 'BizCity_Cron_Manager' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity cron check [--json].' );
			}
			$origin = BizCity_Framework_CLI::switch_blog( $assoc_args );
			try {
				$jobs = BizCity_Cron_Manager::instance()->all();
				$payload = [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'cron check', 'blog_id' => (int) get_current_blog_id(), 'count' => count( $jobs ), 'jobs' => $jobs, 'verdict' => 'pass' ];
			foreach ( $jobs as $job ) {
				if ( ! empty( $job['enabled'] ) && empty( $job['next_run_at'] ) ) {
					$payload['verdict'] = 'warn';
				}
				if ( in_array( strtolower( (string) ( $job['last_status'] ?? '' ) ), [ 'fail', 'failed', 'error' ], true ) ) {
					$payload['verdict'] = 'fail';
				}
			}
			} finally {
				BizCity_Framework_CLI::restore_blog( $origin );
			}
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				\WP_CLI\Utils\format_items( 'table', $jobs, [ 'job_id', 'hook', 'enabled', 'next_run_at', 'last_run_at', 'last_status' ] );
				\WP_CLI::log( 'Verdict: ' . strtoupper( $payload['verdict'] ) );
			}
			if ( $payload['verdict'] === 'fail' || ( $payload['verdict'] === 'warn' && ! empty( $assoc_args['strict'] ) ) ) {
				\WP_CLI::halt( 1 );
			}
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Knowledge' ) ) {
	final class BizCity_Framework_CLI_Knowledge {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — route knowledge repair to the KG skeleton owner.
			if ( ( $args[0] ?? 'repair' ) !== 'repair' || ! class_exists( 'BizCity_KG_Skeleton_Diagnostic' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity knowledge repair [--notebook=<id>] [--stuck] [--yes] [--json].' );
			}
			$blog_id = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;
			$notebook = isset( $assoc_args['notebook'] ) ? (int) $assoc_args['notebook'] : 0;
			$stuck = ! empty( $assoc_args['stuck'] );
			$diagnostic = BizCity_KG_Skeleton_Diagnostic::instance();
			if ( empty( $assoc_args['yes'] ) && empty( $assoc_args['force'] ) ) {
				$result = $diagnostic->audit_blog( $blog_id );
				$result['contract'] = BizCity_Framework_CLI::VERDICT_CONTRACT;
				$result['version'] = BizCity_Framework_CLI::VERDICT_VERSION;
				$result['command'] = 'knowledge repair';
				$result['status'] = 'skip';
				$result['verdict'] = 'skip';
				$result['reason'] = 'confirmation_required';
				\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			$result = $diagnostic->rebuild( $notebook, $stuck, $blog_id );
			$result['contract'] = BizCity_Framework_CLI::VERDICT_CONTRACT;
			$result['version'] = BizCity_Framework_CLI::VERDICT_VERSION;
			$result['command'] = 'knowledge repair';
			$result['status'] = ! empty( $result['ok'] ) ? 'pass' : 'fail';
			$result['verdict'] = $result['status'];
			if ( ! empty( $assoc_args['json'] ) ) {
				\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				if ( $result['status'] === 'fail' ) {
					\WP_CLI::halt( 1 );
				}
			} else {
				if ( $result['status'] === 'fail' ) {
					\WP_CLI::error( (string) ( $result['reason'] ?? 'Knowledge repair failed.' ), false );
					\WP_CLI::halt( 1 );
				}
				\WP_CLI::success( 'Knowledge repair completed.' );
			}
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Brain' ) ) {
	final class BizCity_Framework_CLI_Brain {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-28 Johnny Chu] PHASE-1.32 — expose the canonical Vertical Brain/MPR bridge contract.
			$verb = isset( $args[0] ) ? (string) $args[0] : 'verticals';
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C-C2 — `trace` reads the
			// read-only MPR trace calculator; it does not depend on the Vertical Bridge
			// Registry, so it is routed before that class-existence guard below.
			if ( 'trace' === $verb ) {
				self::dispatch_trace( $args, $assoc_args );
				return;
			}
			if ( ! in_array( $verb, [ 'verticals', 'check' ], true ) || ! class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity brain verticals|check|trace <trace_id> [--id=<vertical>] [--json] [--strict].' );
			}
			$verticals = BizCity_TwinBrain_Vertical_Bridge_Registry::all();
			$id = isset( $assoc_args['id'] ) ? sanitize_key( (string) $assoc_args['id'] ) : '';
			if ( $id !== '' ) {
				$verticals = array_values( array_filter( $verticals, static function ( $row ) use ( $id ) { return sanitize_key( (string) ( $row['id'] ?? '' ) ) === $id; } ) );
			}
			if ( $verb === 'verticals' ) {
				if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) { \WP_CLI::line( wp_json_encode( [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'brain verticals', 'source' => 'BizCity_TwinBrain_Vertical_Bridge_Registry', 'count' => count( $verticals ), 'verticals' => $verticals ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); return; }
				\WP_CLI\Utils\format_items( 'table', $verticals, [ 'id', 'label', 'owner_plugin', 'output_shape', 'min_plan', 'guest_allowed', 'contract_id' ] );
				return;
			}
			$checks = [];
			foreach ( $verticals as $vertical ) {
				$missing = [];
				foreach ( [ 'id', 'label', 'role', 'owner_plugin', 'output_shape', 'guest_allowed', 'min_plan', 'contract_id', 'mpr_layers', 'automation_mode', 'channel_entry', 'admin_surface' ] as $field ) { if ( ! array_key_exists( $field, $vertical ) || $vertical[ $field ] === '' ) $missing[] = $field; }
				$checks[] = [ 'id' => 'vertical.' . sanitize_key( (string) ( $vertical['id'] ?? 'unknown' ) ), 'label' => (string) ( $vertical['label'] ?? 'Vertical'), 'status' => empty( $missing ) ? 'pass' : 'fail', 'evidence' => empty( $missing ) ? 'Bridge metadata, MPR layers, automation/channel/admin spine are declared.' : 'Missing: ' . implode( ', ', $missing ), 'fix_hint' => empty( $missing ) ? 'Run the vertical runtime probe before promotion.' : 'Register the vertical with the complete bridge contract fields.', 'file' => 'bizcity-twin-brain-addon/twinbrain/includes/class-twinbrain-vertical-bridge-registry.php', 'severity' => empty( $missing ) ? 'info' : 'critical' ];
			}
			$payload = BizCity_Framework_CLI::aggregate( $checks, [ 'command' => 'brain check', 'source' => 'BizCity_TwinBrain_Vertical_Bridge_Registry' ] );
			BizCity_Framework_CLI::emit( $payload, $assoc_args, BizCity_Framework_CLI::exit_code( $payload, $assoc_args ) );
		}

		/**
		 * `wp bizcity brain trace <trace_id> [--json]`
		 *
		 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C-C2 — read-only wrapper
		 * around BizCity_TwinBrain_Trace_Calculator. Prints counts and durations
		 * only; never a payload body, query text or credential, matching the same
		 * bounded-field rule as every other diagnostics surface in this codebase.
		 */
		private static function dispatch_trace( array $args, array $assoc_args ): void {
			$trace_id = isset( $args[1] ) ? (string) $args[1] : (string) ( $assoc_args['trace_id'] ?? '' );
			if ( '' === trim( $trace_id ) || ! class_exists( 'BizCity_TwinBrain_Trace_Calculator' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity brain trace <trace_id> [--json]. (BizCity_TwinBrain_Trace_Calculator must be loaded.)' );
			}
			$result = BizCity_TwinBrain_Trace_Calculator::calculate( $trace_id );
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( array_merge(
					[ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'brain trace' ],
					$result
				), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			if ( empty( $result['ok'] ) ) {
				\WP_CLI::error( 'Trace calculator: ' . (string) ( $result['reason'] ?? 'unknown_failure' ) );
				return;
			}
			\WP_CLI::line( sprintf( 'trace_id            = %s', (string) $result['trace_id'] ) );
			\WP_CLI::line( sprintf( 'event_count         = %d', (int) $result['event_count'] ) );
			\WP_CLI::line( sprintf( 'wall_time_ms        = %d (basis: %s)', (int) $result['wall_time_ms'], (string) $result['wall_time_basis'] ) );
			\WP_CLI::line( sprintf( 'known_phase_ms_sum  = %d', (int) $result['known_phase_ms_sum'] ) );
			\WP_CLI::line( sprintf( 'unattributed_ms     = %d', (int) $result['unattributed_ms'] ) );
			\WP_CLI::line( 'parallel_worker_ms  = ' . ( null === $result['parallel_worker_ms'] ? 'n/a (no durable perspective event yet)' : (string) $result['parallel_worker_ms'] ) );
			\WP_CLI::line( 'network_gap_ms      = ' . ( null === $result['network_gap_ms'] ? 'n/a (needs client-reported timestamp)' : (string) $result['network_gap_ms'] ) );
			\WP_CLI::line( 'client_gap_ms       = ' . ( null === $result['client_gap_ms'] ? 'n/a (needs client-reported timestamp)' : (string) $result['client_gap_ms'] ) );
			\WP_CLI::line( '' );
			\WP_CLI::line( 'phases:' );
			foreach ( (array) $result['phases'] as $phase_name => $phase ) {
				if ( empty( $phase['durable'] ) ) {
					\WP_CLI::line( sprintf( '  %-22s not durable (%s)', $phase_name, (string) ( $phase['reason'] ?? 'unknown' ) ) );
					continue;
				}
				\WP_CLI::line( sprintf(
					'  %-22s %5dms  status=%-9s reason=%s',
					$phase_name,
					(int) $phase['duration_ms'],
					(string) $phase['status'],
					'' === (string) ( $phase['reason_bucket'] ?? '' ) ? '-' : (string) $phase['reason_bucket']
				) );
			}
			\WP_CLI::line( '' );
			\WP_CLI::line( 'coverage_note: ' . (string) $result['coverage_note'] );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	BizCity_Framework_CLI::register();
}
