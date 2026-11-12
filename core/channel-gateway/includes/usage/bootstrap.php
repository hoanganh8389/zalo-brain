<?php
/**
 * Zalo Brain — usage / billing module bootstrap (usage ledger copy, plans per Zalo number + Woo sales, revenue, accounts desk, admin users overview,
 * brain journey / digest / standing).
 *
 * [2026-10-25 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-BILL (owner "build khôi phục trọn vẹn lại page sử dụng, không cần bật bizcity-billing-addon nữa") — the module is core again: no add-on needed. It is the old
 * bizcity-billing-addon/bootstrap.php with the six token-usage files folded back in (same order and Safe_Loader labels as before R126-BILL).
 * Required once from core/channel-gateway/bootstrap.php; the folder is includes/usage/ next to this file.
 *
 * PHP 7.4 compatible.
 */
defined( 'ABSPATH' ) || exit;

// [2026-10-25 10:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-BILL (owner "build khôi phục trọn vẹn lại page sử dụng, không cần bật bizcity-billing-addon nữa") — never twice (an old add-on copy that still loads its own classes must not redeclare them).
if ( class_exists( 'BizCity_Usage_Wallet', false ) ) {
	return;
}

$_bzc_billing_dir = __DIR__ . '/';


// [2026-10-13 01:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L9/L10/L12/L2'/L13 — menu "Sử dụng": the site's copy of its own client
// ledger (usage-ledger@1 ingest) + usage-view@1 read routes. Partial deploy degrades this module only (R-SAFE-LOADER).
// [2026-10-25 09:04 PM Johnny Chu - Chu Hoàng Anh] R126-BILL — schema, prices, ingest, ops-day, activity-day, view-rest moved back to core/channel-gateway/includes/usage/ (token usage page).
$_bzc_usage_files = array(
	$_bzc_billing_dir . 'class-usage-schema.php' => 'channel.usage.schema',
	$_bzc_billing_dir . 'class-usage-prices.php' => 'channel.usage.prices',
	$_bzc_billing_dir . 'class-usage-ingest.php' => 'channel.usage.ingest',
	$_bzc_billing_dir . 'class-usage-ops-day.php' => 'channel.usage.ops_day',
	$_bzc_billing_dir . 'class-usage-guru-stats.php' => 'channel.usage.guru_stats', // [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CELL1 — guru-stats@1 from the ops-day `gurus` block (C-BM6)
	$_bzc_billing_dir . 'class-usage-activity-day.php' => 'channel.usage.activity_day',
	$_bzc_billing_dir . 'class-usage-view-rest.php' => 'channel.usage.view_rest',
	// [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 round 4 (L14–L18): wallet proxy, client plans, site-side enforcement (AI switch + owner notice), Woo Pro, REST.
	$_bzc_billing_dir . 'class-usage-wallet.php'     => 'channel.usage.wallet',
	$_bzc_billing_dir . 'class-usage-plans.php'      => 'channel.usage.plans',
	$_bzc_billing_dir . 'class-usage-plan-enforce.php' => 'channel.usage.plan_enforce',
	$_bzc_billing_dir . 'class-usage-plan-woo.php'   => 'channel.usage.plan_woo',
	$_bzc_billing_dir . 'class-usage-plans-rest.php' => 'channel.usage.plans_rest',
	$_bzc_billing_dir . 'class-usage-vault-summary.php' => 'channel.usage.vault_summary', // [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-UI3 — vault-summary@1 (Vault docs · Context Bank · tool families) for the Overview license card
	// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L18.2 — revenue ledger (records a sale AFTER
	// BizCity_Usage_Plan_Woo::apply() extends the plan; does not own the double-extend lock itself).
	$_bzc_billing_dir . 'class-usage-plan-sales.php' => 'channel.usage.plan_sales',
	// [2026-10-13 09:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 vòng 2 (20-REVENUE-PER-NUMBER, 24-TECH-DESIGN-REVENUE): sell plans per number — expected end (L20.1),
	// price floor from the Hub (L21), 1/3/6/12-month price list + hidden Woo products (L17), order for ONE number (L19.1), Zalo link/receipt (L22.1), their REST (L17.1/L19.2/L19.3)
	// and the revenue report REST (L20.3-5). New files only; the 0.107 plan classes above are not edited beyond one do_action in Plan_Woo::apply().
	$_bzc_billing_dir . 'class-usage-plan-expect.php'      => 'channel.usage.plan_expect',
	$_bzc_billing_dir . 'class-usage-plan-floor.php'       => 'channel.usage.plan_floor',
	$_bzc_billing_dir . 'class-usage-plan-packages.php'    => 'channel.usage.plan_packages',
	$_bzc_billing_dir . 'class-usage-plan-orders.php'      => 'channel.usage.plan_orders',
	$_bzc_billing_dir . 'class-usage-plan-notices.php'     => 'channel.usage.plan_notices',
	$_bzc_billing_dir . 'class-usage-plan-orders-rest.php' => 'channel.usage.plan_orders_rest',
	$_bzc_billing_dir . 'class-usage-revenue-rest.php'     => 'channel.usage.revenue_rest',
	$_bzc_billing_dir . 'class-usage-myplans-endpoint.php' => 'channel.usage.myplans_endpoint', // Woo My Account "Gói Zalo của tôi" (customer screen ⑥)
	$_bzc_billing_dir . 'class-usage-accounts-rest.php'    => 'channel.usage.accounts_rest',    // PHASE-0.109 — account desk + token ledger tabs
	// [2026-10-17 11:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M1 — admin "Người dùng" overview (read only, one row per WP user).
	$_bzc_billing_dir . 'class-admin-users-overview-rest.php' => 'channel.usage.admin_users_overview',
	// [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — "Thêm người dùng" on the same page (admin-user-create@1, R-ADMIN-12).
	$_bzc_billing_dir . 'class-admin-users-create-rest.php' => 'channel.usage.admin_users_create',
	$_bzc_billing_dir . 'class-usage-standing.php'         => 'channel.usage.standing',        // S108-L23 — the site's window on its domain title (Hub-issued from cell-measured numbers)
	$_bzc_billing_dir . 'class-brain-standing-rules.php'   => 'channel.brain.standing_rules',   // cap20_customer_only@1 reference (shared fixture with the cell, T108-33)
);
foreach ( $_bzc_usage_files as $_bzc_usage_file => $_bzc_usage_label ) {
	if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
		BizCity_Safe_Loader::require_file( $_bzc_usage_file, $_bzc_usage_label );
	} elseif ( is_file( $_bzc_usage_file ) && is_readable( $_bzc_usage_file ) ) {
		require_once $_bzc_usage_file;
	}
}
unset( $_bzc_usage_files, $_bzc_usage_file, $_bzc_usage_label );
if ( class_exists( 'BizCity_Usage_Schema' ) ) {
	add_action( 'admin_init', array( 'BizCity_Usage_Schema', 'maybe_install' ) );
}
if ( class_exists( 'BizCity_Usage_Ingest' ) && class_exists( 'BizCity_Usage_Prices' ) ) {
	BizCity_Usage_Ingest::init();
}
if ( class_exists( 'BizCity_Usage_Activity_Day' ) && class_exists( 'BizCity_Usage_Schema' ) ) {
	BizCity_Usage_Activity_Day::init(); // PHASE-0.112 S112-L1
}
if ( class_exists( 'BizCity_Usage_Ops_Day' ) ) {
	BizCity_Usage_Ops_Day::init(); // PHASE-0.127 S127-C2
	if ( class_exists( 'BizCity_Usage_Guru_Stats' ) ) {
		BizCity_Usage_Guru_Stats::init(); // [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-CELL1 — GET guru/stats?days=7|30 (reads the ops-day options)
	}
}
if ( class_exists( 'BizCity_Usage_View_REST' ) && class_exists( 'BizCity_Usage_Ingest' ) ) {
	BizCity_Usage_View_REST::init();
}
if ( class_exists( 'BizCity_Usage_Plans' ) && class_exists( 'BizCity_Usage_Schema' ) ) {
	BizCity_Usage_Plans::init(); // daily cron + trial start on first login
}
if ( class_exists( 'BizCity_Usage_Plan_Woo' ) && class_exists( 'BizCity_Usage_Plans' ) ) {
	add_action( 'init', array( 'BizCity_Usage_Plan_Woo', 'init' ), 5 ); // WooCommerce is loaded by then; init() is a no-op without it
}
if ( class_exists( 'BizCity_Usage_Plan_Sales' ) && class_exists( 'BizCity_Usage_Plan_Woo' ) ) {
	add_action( 'init', array( 'BizCity_Usage_Plan_Sales', 'init' ), 6 ); // after Plan_Woo's own init() (5); WooCommerce guard inside init() itself
}
if ( class_exists( 'BizCity_Usage_Plans_REST' ) && class_exists( 'BizCity_Usage_Wallet' ) && class_exists( 'BizCity_Usage_View_REST' ) ) {
	BizCity_Usage_Plans_REST::init();
	// [2026-10-28 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-UI3 — GET usage/vault-summary; its gate is Plans_REST::can_manage (same as usage/subscription), so it loads only with it.
	if ( class_exists( 'BizCity_Usage_Vault_Summary' ) ) {
		BizCity_Usage_Vault_Summary::init();
	}
}
// PHASE-0.108 vòng 2 — each module starts only when everything it calls is present (partial deploy degrades that module only, R-SAFE-LOADER).
if ( class_exists( 'BizCity_Usage_Plan_Notices' ) && class_exists( 'BizCity_Usage_Plan_Woo' ) ) {
	BizCity_Usage_Plan_Notices::init(); // receipt on bizcity_usage_plan_applied (priority 20, after the revenue ledger)
}
if ( class_exists( 'BizCity_Usage_Plan_Orders_REST' ) && class_exists( 'BizCity_Usage_Plan_Orders' ) && class_exists( 'BizCity_Usage_Plan_Packages' )
	&& class_exists( 'BizCity_Usage_Plan_Floor' ) && class_exists( 'BizCity_Usage_Plan_Woo' ) && class_exists( 'BizCity_Usage_Plans' ) && class_exists( 'BizCity_Usage_View_REST' ) ) {
	BizCity_Usage_Plan_Orders_REST::init();
}
if ( class_exists( 'BizCity_Usage_MyPlans_Endpoint' ) && class_exists( 'BizCity_Usage_Plan_Orders_REST' ) && class_exists( 'BizCity_Usage_Plan_Orders' ) && class_exists( 'BizCity_Usage_Plan_Woo' ) ) {
	add_action( 'init', array( 'BizCity_Usage_MyPlans_Endpoint', 'init' ), 7 ); // WooCommerce is loaded by then; init() is a no-op without it
}
if ( class_exists( 'BizCity_Usage_Standing' ) && class_exists( 'BizCity_Usage_View_REST' ) ) {
	BizCity_Usage_Standing::init();
}
if ( class_exists( 'BizCity_Usage_Revenue_REST' ) && class_exists( 'BizCity_Usage_Plan_Sales' ) && class_exists( 'BizCity_Usage_Plan_Expect' )
	&& class_exists( 'BizCity_Usage_Plans_REST' ) && class_exists( 'BizCity_Usage_Wallet' ) && class_exists( 'BizCity_Usage_View_REST' ) ) {
	BizCity_Usage_Revenue_REST::init();
}

// [2026-10-14 Johnny Chu - Chu Hoàng Anh] PHASE-0.109 — "Tài khoản" + "Sổ cái token" tabs (read-only, billed figures only).
if ( class_exists( 'BizCity_Usage_Accounts_REST' ) && class_exists( 'BizCity_Usage_Wallet' ) && class_exists( 'BizCity_Usage_View_REST' ) ) {
	BizCity_Usage_Accounts_REST::init();
}

// [2026-10-17 11:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-M1 — admin "Người dùng" overview; reads the same owners as the Accounts tab.
if ( class_exists( 'BizCity_Admin_Users_Overview_REST' ) && class_exists( 'BizCity_Usage_View_REST' ) ) {
	BizCity_Admin_Users_Overview_REST::init();
	// [2026-10-18 12:48 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-V2 — its gate is the overview's can_manage(), so it loads only with it.
	if ( class_exists( 'BizCity_Admin_Users_Create_REST' ) ) {
		BizCity_Admin_Users_Create_REST::init();
	}
}

// [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L1..L6 — menu "Sử dụng" → tab Thành tích: achievements/ROI
// built entirely from the usage-ledger copy above (D108-2, R-CELL-METER-ONLY). Partial deploy degrades this module
// only. Q108-1..9 not yet owner-locked — code follows the master plan's suggested defaults (00-MASTER-PLAN.md §6).
// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-BILL — the milestone catalog moved with it: contracts/brain-milestones.v1.json of this
// plugin (BizCity_Brain_Milestones_Catalog reads dirname( __DIR__, 2 ) . '/contracts/…', which is this plugin's root from includes/usage/).
$_bzc_brain_files = array(
	$_bzc_billing_dir . 'class-brain-journey-schema.php'     => 'channel.brain.schema',
	$_bzc_billing_dir . 'class-brain-milestones-catalog.php' => 'channel.brain.milestones_catalog',
	$_bzc_billing_dir . 'class-brain-roi.php'                => 'channel.brain.roi',
	$_bzc_billing_dir . 'class-brain-digest.php'              => 'channel.brain.digest',       // S108-L7 — daily Zalo bulletins (owner + site admin)
	$_bzc_billing_dir . 'class-brain-settings.php'            => 'channel.brain.settings',     // S108-L15 — REST for minutes/wage/action tools/bulletin switches
	$_bzc_billing_dir . 'class-brain-maturity.php'            => 'channel.brain.maturity', // [2026-10-16 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L5 — maturity 0–100 (five axes × efficiency multiplier)
	$_bzc_billing_dir . 'class-brain-owner-ladder.php'        => 'channel.brain.owner_ladder', // S108-L13 — 6-step ladder + what is missing (pure)
	$_bzc_billing_dir . 'class-brain-board.php'               => 'channel.brain.board',          // S108-L11/L12 — ranges, flags, ranking, next milestones, badges (pure)
	$_bzc_billing_dir . 'class-brain-period-metrics.php'      => 'channel.brain.period_metrics', // S108-L11a — 7d / 30d / month windows with the 20/customer/day cap
	$_bzc_billing_dir . 'class-brain-payback-history.php'     => 'channel.brain.payback_history', // S108-L14 — payback.silver/gold + savings + owner Master/Grandmaster counts
	$_bzc_billing_dir . 'class-brain-journey-evaluator.php'  => 'channel.brain.evaluator',
	$_bzc_billing_dir . 'class-brain-journey-rest.php'       => 'channel.brain.journey_rest',
);
foreach ( $_bzc_brain_files as $_bzc_brain_file => $_bzc_brain_label ) {
	if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
		BizCity_Safe_Loader::require_file( $_bzc_brain_file, $_bzc_brain_label );
	} elseif ( is_file( $_bzc_brain_file ) && is_readable( $_bzc_brain_file ) ) {
		require_once $_bzc_brain_file;
	}
}
unset( $_bzc_brain_files, $_bzc_brain_file, $_bzc_brain_label, $_bzc_billing_dir );
if ( class_exists( 'BizCity_Brain_Journey_Schema' ) ) {
	add_action( 'admin_init', array( 'BizCity_Brain_Journey_Schema', 'maybe_install' ) );
}
if ( class_exists( 'BizCity_Brain_Journey_Evaluator' ) && class_exists( 'BizCity_Brain_Journey_Schema' ) ) {
	BizCity_Brain_Journey_Evaluator::init();
}
if ( class_exists( 'BizCity_Brain_Journey_REST' ) && class_exists( 'BizCity_Brain_Journey_Schema' ) ) {
	BizCity_Brain_Journey_REST::init();
}
if ( class_exists( 'BizCity_Brain_Settings' ) && class_exists( 'BizCity_Brain_ROI' ) && class_exists( 'BizCity_Brain_Journey_Evaluator' ) ) {
	BizCity_Brain_Settings::init(); // S108-L15
}
if ( class_exists( 'BizCity_Brain_Digest' ) && class_exists( 'BizCity_Brain_Settings' ) && class_exists( 'BizCity_Brain_Journey_Schema' ) ) {
	BizCity_Brain_Digest::init(); // S108-L7 — hourly tick; sends at most once a day per person
}
