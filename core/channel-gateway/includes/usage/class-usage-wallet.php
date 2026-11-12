<?php
/**
 * Hub wallet for the site: proxy of `wallet-view@1` (PHASE-0.107 S107-L18, doc 66 §6.3, fixtures wallet-view.v1.json / token-packs.json).
 *
 * The site READS the Hub wallet and never holds a balance. `GET {gateway}/wp-json/bizcity/v1/account/wallet` with the site's OWN key
 * (main-site fallback OFF), cached 60 s (`?refresh=1` skips the cache). The answer is REBUILT field by field from a whitelist (the
 * second filtering layer; the Hub is the first): never provider cost, real tokens, markup, a full email, any 64-hex string.
 * The packs behind "Mua thêm token" come from wallet-view `buy.packs`; when they carry no add-to-cart URL the Hub list
 * `GET /account/token-packs` is read instead. A null rate stays null (every *_vnd is null; the UI shows USD only).
 *
 * // [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L18 — wallet-view@1 proxy with a whitelist filter.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Wallet {

	const CONTRACT  = 'wallet-view@1';
	const TRANSIENT = 'bizcity_usage_wallet_view';
	const TTL       = 60;

	/** Test seams: fetch(path): array (decoded Hub body or []) · has_key(): bool · cache_get(): mixed · cache_set(value): void */
	public static $readers = array();

	/**
	 * @param bool $refresh skip the 60 s cache
	 * @return array<string,mixed> {state:"no_key"} | {state:"degraded"} | the filtered wallet view
	 */
	public static function view( bool $refresh = false ): array {
		if ( ! self::has_key() ) {
			return array( 'contract' => self::CONTRACT, 'state' => 'no_key' );
		}
		if ( ! $refresh ) {
			$c = self::cache_get();
			if ( is_array( $c ) && isset( $c['contract'] ) ) {
				return $c;
			}
		}
		// "Làm mới" must skip the Hub's own 60 s cache too (the buyer just came back from the pay page), not only this site's.
		$raw = self::fetch( '/account/wallet', $refresh ? array( 'refresh' => 1 ) : array() );
		if ( ! is_array( $raw ) || empty( $raw['contract'] ) || 0 !== strpos( (string) $raw['contract'], 'wallet-view@' ) ) {
			return array( 'contract' => self::CONTRACT, 'state' => 'degraded' );
		}
		$out = self::filter( $raw );
		if ( empty( $out['buy']['packs'] ) || ! self::packs_have_urls( $out['buy']['packs'] ) ) {
			$tp = self::fetch( '/account/token-packs' );
			if ( is_array( $tp ) && ! empty( $tp['packs'] ) && is_array( $tp['packs'] ) ) {
				$packs = self::filter_packs( $tp['packs'] );
				if ( $packs ) {
					$out['buy']['packs'] = $packs;
				}
				if ( null === $out['usd_vnd_rate'] && isset( $tp['usd_vnd_rate'] ) && is_numeric( $tp['usd_vnd_rate'] ) && (float) $tp['usd_vnd_rate'] > 0 ) {
					$out['usd_vnd_rate'] = (float) $tp['usd_vnd_rate'];
				}
			}
		}
		$out['state'] = 'ok';
		self::cache_set( $out );
		return $out;
	}

	/** The Hub's single USD→VND rate from the wallet view, or null. */
	public static function rate(): ?float {
		$v = self::view();
		return ( isset( $v['usd_vnd_rate'] ) && is_numeric( $v['usd_vnd_rate'] ) && (float) $v['usd_vnd_rate'] > 0 ) ? (float) $v['usd_vnd_rate'] : null;
	}

	/**
	 * Whitelist rebuild of a wallet-view body (pure; unit-tested). Unknown keys vanish, strings are cleaned.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function filter( array $raw ): array {
		$acc    = (array) ( $raw['account'] ?? array() );
		$topups = (array) ( $raw['topups'] ?? array() );
		$spend  = (array) ( $raw['spend'] ?? array() );
		$dom    = (array) ( $spend['this_domain'] ?? array() );
		$buy    = (array) ( $raw['buy'] ?? array() );
		$last   = array();
		foreach ( array_slice( (array) ( $topups['last'] ?? array() ), 0, 10 ) as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$last[] = array(
				'at'     => self::str( $t['at'] ?? '', 40 ),
				'vnd'    => self::num( $t['vnd'] ?? null ),
				'usd'    => self::num( $t['usd'] ?? null ),
				'source' => sanitize_key( (string) ( $t['source'] ?? '' ) ),
				'ref'    => self::str( $t['ref'] ?? '', 40 ),
			);
		}
		$rate = self::num( $raw['usd_vnd_rate'] ?? null );
		$rate = ( null !== $rate && $rate > 0 ) ? $rate : null;
		$url  = self::url( $buy['url'] ?? '' );
		return array(
			'contract'        => self::CONTRACT,
			// R-WALLET-KEY (0.107/70): key = this domain's own wallet · shared = the operator's wallet (no balance, no top-up history). An older Hub sends none ⇒ key.
			'wallet_scope'    => in_array( (string) ( $raw['wallet_scope'] ?? '' ), array( 'key', 'shared' ), true ) ? (string) $raw['wallet_scope'] : 'key',
			'account'         => array( 'email_masked' => self::mask_email( (string) ( $acc['email_masked'] ?? '' ) ), 'domains' => (int) ( $acc['domains'] ?? 0 ) ),
			'balance_usd'     => self::num( $raw['balance_usd'] ?? null ),
			'balance_vnd'     => null === $rate ? null : self::num( $raw['balance_vnd'] ?? null ),
			'usd_vnd_rate'    => $rate,
			'rate_entered_at' => null === $rate ? null : self::str( $raw['rate_entered_at'] ?? '', 20 ),
			'topups'          => array(
				'count'     => (int) ( $topups['count'] ?? 0 ),
				'total_usd' => self::num( $topups['total_usd'] ?? null ),
				'total_vnd' => null === $rate ? null : self::num( $topups['total_vnd'] ?? null ),
				'last'      => $last,
			),
			'spend'           => array(
				'this_domain'       => array( 'usd' => self::num( $dom['usd'] ?? null ), 'billed_tokens' => (int) ( $dom['billed_tokens'] ?? 0 ) ),
				'account_total_usd' => self::num( $spend['account_total_usd'] ?? null ),
				'month'             => self::str( $spend['month'] ?? '', 7 ),
			),
			'runway_days'     => isset( $raw['runway_days'] ) && is_numeric( $raw['runway_days'] ) ? (int) $raw['runway_days'] : null,
			'low_balance'     => ! empty( $raw['low_balance'] ),
			'buy'             => array( 'url' => $url, 'packs' => self::filter_packs( (array) ( $buy['packs'] ?? array() ) ) ),
		);
	}

	/**
	 * "Mua thêm token" for THIS domain (PHASE-0.107 S107-H30, doc 70 §8): ask the Hub for a pay link with the site's OWN key. The domain is
	 * never sent — the Hub reads it from the key — so a site can only ever top up itself. The buyer needs no bizcity.vn account.
	 *
	 * @return array{ok:bool,pay_url?:string,domain?:string,pack?:array<string,mixed>,code?:string,message?:string}
	 */
	public static function create_order( int $product_id, string $email = '' ): array {
		if ( $product_id <= 0 ) {
			return array( 'ok' => false, 'code' => 'bad_product', 'message' => 'Chưa chọn gói.' );
		}
		if ( ! self::has_key() ) {
			return array( 'ok' => false, 'code' => 'no_key', 'message' => 'Chưa kết nối khoá BizCity cho domain này.' );
		}
		$email = trim( $email );
		if ( '' === $email && function_exists( 'wp_get_current_user' ) ) {
			$email = (string) wp_get_current_user()->user_email; // the admin pressing the button; editable on the screen
		}
		// [2026-10-14 04:10 AM Johnny Chu - Chu Hoàng Anh] a phone-login admin has no usable WP email (the Hub answered bad_email, 2026-10-14): fall back to the site admin email
		// before asking; the screen still lets the person type one (usePayPack → PayEmailField).
		if ( function_exists( 'is_email' ) && ! is_email( $email ) && function_exists( 'get_option' ) ) {
			$site = (string) get_option( 'admin_email', '' );
			$email = is_email( $site ) ? $site : $email;
		}
		$raw = self::post( '/account/token-packs/order', array( 'product_id' => $product_id, 'email' => $email ) );
		if ( ! empty( $raw['ok'] ) && 'token-order@1' === ( $raw['contract'] ?? '' ) && '' !== self::url( $raw['pay_url'] ?? '' ) ) {
			$pack = (array) ( $raw['pack'] ?? array() );
			return array(
				'ok'       => true,
				'order_id' => (int) ( $raw['order_id'] ?? 0 ),
				'pay_url'  => self::url( $raw['pay_url'] ),
				'domain'  => self::str( $raw['domain'] ?? '', 80 ),
				'pack'    => array( 'label' => self::str( $pack['label'] ?? '', 60 ), 'vnd' => self::num( $pack['vnd'] ?? null ), 'usd' => self::num( $pack['usd'] ?? null ) ),
			);
		}
		$code = sanitize_key( (string) ( $raw['code'] ?? '' ) );
		return array(
			'ok'      => false,
			'code'    => '' !== $code ? $code : 'degraded',
			'message' => '' !== (string) ( $raw['message'] ?? '' ) ? self::str( $raw['message'], 160 ) : 'Chưa tạo được đơn từ BizCity. Vui lòng thử lại sau ít phút.',
		);
	}

	/**
	 * "Did my payment land?" for an order this site's key made (polled by the "Mua thêm token" tab while the QR is open).
	 * The Hub answers only the key that made the order; the site never forwards a domain or a key id.
	 *
	 * @return array{ok:bool,state:string,usd:?float,vnd:?float}
	 */
	public static function order_status( int $order_id ): array {
		$none = array( 'ok' => false, 'state' => 'unknown', 'usd' => null, 'vnd' => null );
		if ( $order_id <= 0 || ! self::has_key() ) {
			return $none;
		}
		$raw = self::fetch( '/account/token-packs/order/status', array( 'order_id' => $order_id ) );
		$st  = sanitize_key( (string) ( $raw['state'] ?? '' ) );
		if ( empty( $raw['ok'] ) || ! in_array( $st, array( 'pending', 'paid', 'credited', 'refunded', 'cancelled' ), true ) ) {
			return $none;
		}
		return array( 'ok' => true, 'state' => $st, 'usd' => self::num( $raw['usd'] ?? null ), 'vnd' => self::num( $raw['vnd'] ?? null ) );
	}

	/* ── PHASE-0.139 subscription (thuê bao theo gói) ──────────────── */

	const PLAN_ORDER_MONTHS = array( 1, 3, 6, 12 );

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.139 S139-UI4 — the site's subscription@1 block (doc 20 §4) as the client stores it
	 * (BizCity_LLM_Client::subscription(): days_left counts down between syncs; state 'none' on an older Hub). `$refresh` forces one
	 * master/config sync first (button "Làm mới", or back from the pay page) — at most once per 30 s per site.
	 * Test seams: $readers['subscription'](): array · $readers['sync'](): void
	 *
	 * @return array<string,mixed>
	 */
	public static function subscription( bool $refresh = false ): array {
		if ( $refresh && self::has_key() ) {
			$guard = 'bizcity_usage_subscription_sync';
			$fresh = isset( self::$readers['sync'] ) || ! function_exists( 'get_transient' ) || ! get_transient( $guard );
			if ( $fresh ) {
				if ( function_exists( 'set_transient' ) && ! isset( self::$readers['sync'] ) ) {
					set_transient( $guard, 1, 30 );
				}
				// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.139 S139-UI6 — cheap, uncached Hub route first; an older Hub (rest_no_route / no block) ⇒ the master/config sync as before.
				$hub = self::fetch( '/account/subscription' );
				$blk = isset( $hub['subscription'] ) && is_array( $hub['subscription'] ) ? $hub['subscription'] : $hub;
				if ( 'subscription@1' === (string) ( $blk['contract'] ?? '' ) ) {
					if ( isset( self::$readers['store'] ) ) {
						call_user_func( self::$readers['store'], $blk );
					} elseif ( class_exists( 'BizCity_LLM_Client' ) ) {
						BizCity_LLM_Client::sync_subscription( array( 'subscription' => $blk ) ); // one source: same whitelist + option
					}
				} elseif ( isset( self::$readers['sync'] ) ) {
					call_user_func( self::$readers['sync'] );
				} elseif ( class_exists( 'BizCity_LLM_Client' ) ) {
					BizCity_LLM_Client::instance()->maybe_sync_master_plan( array( 'force_refresh' => true, 'timeout' => 6 ) );
				}
			}
		}
		if ( isset( self::$readers['subscription'] ) ) {
			return (array) call_user_func( self::$readers['subscription'] );
		}
		if ( class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'subscription' ) ) {
			return BizCity_LLM_Client::subscription();
		}
		return array( 'contract' => 'subscription@1', 'state' => 'none', 'renew_packs' => array() );
	}

	const PLAN_PRODUCTS_TRANSIENT = 'bizcity_usage_plan_products';
	const PLAN_PRODUCTS_TTL       = 300;
	const PLAN_PRODUCTS_GUARD     = 'bizcity_usage_plan_products_guard';
	const PLAN_PRODUCTS_GUARD_TTL = 15;

	/**
	 * [2026-10-26 Johnny Chu - Chu Hoàng Anh] PHASE-0.139 S139-UI7 — the plan products of the Hub shop for THIS site's key
	 * (GET /account/plan-products, contract plan-products@1). Answer cached 5 min; `$refresh` bypasses the cache but at most one forced
	 * fetch per 15 s. Whitelisted field by field. Never throws.
	 * Test seams: $readers['plan_fetch'](path): array (raw Hub body incl. code/http_code) · ['pp_cache_get'](): mixed · ['pp_cache_set'](array) ·
	 * ['pp_guard_get'](): bool · ['pp_guard_set']() · ['has_key']()
	 *
	 * @return array{ok:bool,state:string,products:array<int,array<string,mixed>>,shop_url:string,cart_url:string,message?:string}
	 */
	public static function plan_products( bool $refresh = false ): array {
		$empty = array( 'ok' => false, 'state' => 'error', 'products' => array(), 'shop_url' => '', 'cart_url' => '' );
		try {
			if ( ! self::has_key() ) {
				return array_merge( $empty, array( 'state' => 'no_key', 'message' => 'Chưa kết nối khoá BizCity.' ) );
			}
			if ( $refresh ) {
				if ( self::pp_guard_get() ) {
					$refresh = false; // one forced fetch per 15 s
				} else {
					self::pp_guard_set();
				}
			}
			if ( ! $refresh ) {
				$hit = self::pp_cache_get();
				if ( is_array( $hit ) && ! empty( $hit['ok'] ) ) {
					return $hit;
				}
			}
			if ( isset( self::$readers['plan_fetch'] ) ) {
				$raw = (array) call_user_func( self::$readers['plan_fetch'], '/account/plan-products' );
			} else {
				$raw = (array) BizCity_LLM_Client::instance()->gateway_get( '/account/plan-products', array(), 'GET', 8, false ); // the site's OWN key only
			}
			$code = (string) ( $raw['code'] ?? '' );
			$http = (int) ( $raw['http_code'] ?? 0 );
			if ( 'rest_no_route' === $code || 404 === $http ) {
				return array_merge( $empty, array( 'state' => 'hub_too_old', 'message' => 'Hub chưa hỗ trợ danh sách gói.' ) );
			}
			if ( 'no_key' === $code || 'no_api_key' === (string) ( $raw['error'] ?? '' ) ) {
				return array_merge( $empty, array( 'state' => 'no_key', 'message' => self::str( $raw['message'] ?? 'Chưa kết nối khoá BizCity.', 160 ) ) );
			}
			if ( 'key_inactive' === $code ) {
				return array_merge( $empty, array( 'state' => 'inactive', 'message' => self::str( $raw['message'] ?? 'Khoá BizCity không hoạt động.', 160 ) ) );
			}
			if ( 'plan-products@1' !== (string) ( $raw['contract'] ?? '' ) || empty( $raw['ok'] ) || ! empty( $raw['_degraded'] ) ) {
				return $empty;
			}
			$products = array();
			foreach ( array_slice( (array) ( $raw['products'] ?? array() ), 0, 40 ) as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$pid  = isset( $p['product_id'] ) && is_numeric( $p['product_id'] ) ? (int) $p['product_id'] : 0;
				$plan = strtolower( self::str( $p['plan_code'] ?? '', 60 ) );
				if ( $pid <= 0 || '' === $plan ) {
					continue;
				}
				$months = array();
				foreach ( (array) ( $p['months'] ?? array() ) as $m ) {
					if ( is_numeric( $m ) && in_array( (int) $m, self::PLAN_ORDER_MONTHS, true ) ) {
						$months[ (int) $m ] = (int) $m;
					}
				}
				sort( $months );
				$price   = isset( $p['price_vnd'] ) && is_numeric( $p['price_vnd'] ) ? max( 0, (int) $p['price_vnd'] ) : 0;
				$regular = isset( $p['regular_vnd'] ) && is_numeric( $p['regular_vnd'] ) ? max( 0, (int) $p['regular_vnd'] ) : null;
				// [2026-10-27 Johnny Chu - Chu Hoàng Anh] PHASE-0.144 S144-UI1 — days of one unit + the day options of a variable product (30 / 60 / 90 / 365 …).
				$options = array();
				foreach ( array_slice( (array) ( $p['options'] ?? array() ), 0, 12 ) as $o ) {
					$vid  = is_array( $o ) && isset( $o['variation_id'] ) && is_numeric( $o['variation_id'] ) ? (int) $o['variation_id'] : 0;
					$days = is_array( $o ) && isset( $o['days'] ) && is_numeric( $o['days'] ) ? (int) $o['days'] : 0;
					if ( $vid <= 0 || $days <= 0 ) {
						continue;
					}
					$options[] = array(
						'variation_id' => $vid,
						'days'         => min( 1830, $days ),
						'label'        => self::str( $o['label'] ?? '', 60 ),
						'price_vnd'    => isset( $o['price_vnd'] ) && is_numeric( $o['price_vnd'] ) ? max( 0, (int) $o['price_vnd'] ) : 0,
						'regular_vnd'  => isset( $o['regular_vnd'] ) && is_numeric( $o['regular_vnd'] ) ? max( 0, (int) $o['regular_vnd'] ) : null,
						'buy_url'      => self::pp_url( $o['buy_url'] ?? '' ),
						'default'      => ! empty( $o['default'] ),
					);
				}
				$products[] = array(
					'product_id'  => $pid,
					'key'         => self::str( $p['key'] ?? ( $pid . ':' . $plan ), 80 ),
					'days'        => isset( $p['days'] ) && is_numeric( $p['days'] ) && (int) $p['days'] > 0 ? min( 1830, (int) $p['days'] ) : 30,
					'options'     => $options,
					'name'        => self::str( $p['name'] ?? '', 120 ),
					'plan_code'   => $plan,
					'label'       => self::str( $p['label'] ?? '', 60 ),
					'price_vnd'   => $price,
					'regular_vnd' => $regular,
					'image_url'   => self::pp_url( $p['image_url'] ?? '' ),
					'permalink'   => self::pp_url( $p['permalink'] ?? '' ),
					'summary'     => self::str( $p['summary'] ?? '', 200 ),
					'months'      => array_values( $months ),
					'buy_url'     => self::pp_url( $p['buy_url'] ?? '' ),
					// [2026-10-29 01:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.147 — số Zalo bật AI của gói (-1 = không giới hạn); null = Hub cũ chưa gửi
					'zalo_numbers' => isset( $p['zalo_numbers'] ) && is_numeric( $p['zalo_numbers'] ) ? max( -1, min( 1000, (int) $p['zalo_numbers'] ) ) : null,
				);
			}
			$out = array(
				'ok'       => true,
				'state'    => 'ok',
				'products' => $products,
				'shop_url' => self::pp_url( $raw['shop_url'] ?? '' ),
				'cart_url' => self::pp_url( $raw['cart_url'] ?? '' ),
			);
			self::pp_cache_set( $out );
			return $out;
		} catch ( \Throwable $e ) {
			return $empty;
		}
	}

	private static function pp_url( $v ): string {
		$u = trim( (string) $v );
		if ( 1 !== preg_match( '#^https?://[^\s<>"\']+$#i', $u ) ) {
			return '';
		}
		return function_exists( 'esc_url_raw' ) ? (string) esc_url_raw( $u, array( 'http', 'https' ) ) : $u;
	}

	private static function pp_cache_get() {
		if ( isset( self::$readers['pp_cache_get'] ) ) {
			return call_user_func( self::$readers['pp_cache_get'] );
		}
		return function_exists( 'get_transient' ) ? get_transient( self::PLAN_PRODUCTS_TRANSIENT ) : false;
	}

	private static function pp_cache_set( array $v ): void {
		if ( isset( self::$readers['pp_cache_set'] ) ) {
			call_user_func( self::$readers['pp_cache_set'], $v );
		} elseif ( function_exists( 'set_transient' ) ) {
			set_transient( self::PLAN_PRODUCTS_TRANSIENT, $v, self::PLAN_PRODUCTS_TTL );
		}
	}

	private static function pp_guard_get(): bool {
		if ( isset( self::$readers['pp_guard_get'] ) ) {
			return (bool) call_user_func( self::$readers['pp_guard_get'] );
		}
		return function_exists( 'get_transient' ) && (bool) get_transient( self::PLAN_PRODUCTS_GUARD );
	}

	private static function pp_guard_set(): void {
		if ( isset( self::$readers['pp_guard_set'] ) ) {
			call_user_func( self::$readers['pp_guard_set'] );
		} elseif ( function_exists( 'set_transient' ) ) {
			set_transient( self::PLAN_PRODUCTS_GUARD, 1, self::PLAN_PRODUCTS_GUARD_TTL );
		}
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.139 S139-UI5 — "Gia hạn / nâng gói" for THIS domain: Hub POST /account/plan-order with the
	 * site's OWN key (the Hub reads the key / domain from it; no domain, price or key id is ever sent). Same shapes as create_order().
	 *
	 * @return array{ok:bool,order_id?:int,pay_url?:string,code?:string,message?:string,hint?:string}
	 */
	public static function create_plan_order( string $plan_code, int $months, string $email = '' ): array {
		$plan_code = sanitize_key( $plan_code );
		if ( '' === $plan_code ) {
			return array( 'ok' => false, 'code' => 'bad_plan', 'message' => 'Chưa chọn gói.' );
		}
		if ( ! in_array( $months, self::PLAN_ORDER_MONTHS, true ) ) {
			return array( 'ok' => false, 'code' => 'bad_months', 'message' => 'Số tháng phải là 1, 3, 6 hoặc 12.' );
		}
		if ( ! self::has_key() ) {
			return array( 'ok' => false, 'code' => 'no_key', 'message' => 'Chưa kết nối khoá BizCity cho domain này.' );
		}
		$raw = self::post( '/account/plan-order', array( 'plan_code' => $plan_code, 'months' => $months, 'email' => self::buyer_email( $email ) ) );
		if ( ! empty( $raw['ok'] ) && '' !== self::url( $raw['pay_url'] ?? '' ) ) {
			return array(
				'ok'       => true,
				'order_id' => (int) ( $raw['order_id'] ?? 0 ),
				'pay_url'  => self::url( $raw['pay_url'] ),
			);
		}
		$code = sanitize_key( (string) ( $raw['code'] ?? '' ) );
		if ( 'rest_no_route' === $code ) { // an older Hub (before PHASE-0.139 W2) has no /account/plan-order
			return array( 'ok' => false, 'code' => 'hub_too_old', 'message' => 'Máy chủ BizCity chưa mở bán gói theo tháng cho site này. Vui lòng thử lại sau hoặc liên hệ nhà cung cấp.' );
		}
		$out  = array(
			'ok'      => false,
			'code'    => '' !== $code ? $code : 'degraded',
			'message' => '' !== (string) ( $raw['message'] ?? '' ) ? self::str( $raw['message'], 160 ) : 'Chưa tạo được đơn gia hạn từ BizCity. Vui lòng thử lại sau ít phút.',
		);
		if ( '' !== (string) ( $raw['hint'] ?? '' ) ) {
			$out['hint'] = self::str( $raw['hint'], 200 );
		}
		return $out;
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.139 S139-UI5 — "Đã kích hoạt chưa?" for a plan order this site's key made (polled while the
	 * QR is open). Hub GET /account/plan-order/status. `active` ⇒ the next master/config read is forced (the card shows the new end date).
	 *
	 * @return array{ok:bool,state:string,period_end_at:?string}
	 */
	public static function plan_order_status( int $order_id ): array {
		$none = array( 'ok' => false, 'state' => 'unknown', 'period_end_at' => null );
		if ( $order_id <= 0 || ! self::has_key() ) {
			return $none;
		}
		$raw = self::fetch( '/account/plan-order/status', array( 'order_id' => $order_id ) );
		$st  = sanitize_key( (string) ( $raw['state'] ?? '' ) );
		if ( ! in_array( $st, array( 'pending', 'paid', 'active', 'refunded', 'cancelled' ), true ) ) {
			return $none;
		}
		$end = self::str( $raw['period_end_at'] ?? '', 40 );
		return array( 'ok' => true, 'state' => $st, 'period_end_at' => '' !== $end ? $end : null );
	}

	/** The buyer email for a Hub order: typed ⇒ WP user ⇒ site admin email (phone-login admins have none). */
	private static function buyer_email( string $email ): string {
		$email = trim( $email );
		if ( '' === $email && function_exists( 'wp_get_current_user' ) ) {
			$email = (string) wp_get_current_user()->user_email;
		}
		if ( function_exists( 'is_email' ) && ! is_email( $email ) && function_exists( 'get_option' ) ) {
			$site  = (string) get_option( 'admin_email', '' );
			$email = is_email( $site ) ? $site : $email;
		}
		return $email;
	}

	/** @param array<int,mixed> $packs @return array<int,array<string,mixed>> */
	public static function filter_packs( array $packs ): array {
		$out = array();
		foreach ( array_slice( $packs, 0, 12 ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$basis = (array) ( $p['estimate_basis'] ?? array() );
			$out[] = array(
				'product_id'     => isset( $p['product_id'] ) && is_numeric( $p['product_id'] ) ? (int) $p['product_id'] : 0, // S107-H30: what the order is created for
				'label'          => self::str( $p['label'] ?? '', 60 ),
				'vnd'            => self::num( $p['vnd'] ?? null ),
				'usd'            => self::num( $p['usd'] ?? null ),
				'token_estimate' => isset( $p['token_estimate'] ) && is_numeric( $p['token_estimate'] ) ? (int) $p['token_estimate'] : null,
				'estimate_basis' => array( 'model' => self::str( $basis['model'] ?? '', 80 ), 'priced_at' => self::str( $basis['priced_at'] ?? '', 20 ) ),
				'buy_url'        => self::url( $p['buy_url'] ?? '' ),
			);
		}
		return $out;
	}

	/** h•••@gmail.com stays; a full address (no bullet) is masked here too. */
	public static function mask_email( string $e ): string {
		$e = trim( $e );
		if ( '' === $e || false !== strpos( $e, '•' ) || false !== strpos( $e, '*' ) ) {
			return self::str( $e, 80 );
		}
		if ( 1 === preg_match( '/^([^@\s])[^@\s]*@(.+)$/u', $e, $m ) ) {
			return $m[1] . '•••@' . $m[2];
		}
		return self::str( $e, 80 );
	}

	private static function packs_have_urls( array $packs ): bool {
		foreach ( $packs as $p ) {
			if ( '' === (string) ( $p['buy_url'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	private static function num( $v ): ?float {
		return ( null !== $v && is_numeric( $v ) ) ? round( (float) $v, 8 ) : null;
	}

	private static function str( $v, int $max ): string {
		$s = trim( (string) preg_replace( '/[0-9a-f]{64}/i', '', (string) $v ) );
		$s = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $s ) : trim( strip_tags( $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	private static function url( $v ): string {
		$u = trim( (string) $v );
		return 1 === preg_match( '#^https?://[^\s<>"\']+$#i', $u ) ? $u : '';
	}

	private static function has_key(): bool {
		if ( isset( self::$readers['has_key'] ) ) {
			return (bool) call_user_func( self::$readers['has_key'] );
		}
		return class_exists( 'BizCity_LLM_Client' ) && '' !== BizCity_LLM_Client::instance()->get_api_key( false );
	}

	private static function fetch( string $path, array $query = array() ): array {
		if ( isset( self::$readers['fetch'] ) ) {
			return (array) call_user_func( self::$readers['fetch'], $path, $query );
		}
		$r = BizCity_LLM_Client::instance()->gateway_get( $path, $query, 'GET', 8, false ); // the site's OWN key only
		return is_array( $r ) && empty( $r['_degraded'] ) ? $r : array();
	}

	/** POST to the Hub with the site's OWN key (main-site fallback OFF). Test seam: $readers['post'](path, body): array */
	private static function post( string $path, array $body ): array {
		if ( isset( self::$readers['post'] ) ) {
			return (array) call_user_func( self::$readers['post'], $path, $body );
		}
		$r = BizCity_LLM_Client::instance()->gateway_post( $path, $body, 15, false );
		return is_array( $r ) ? $r : array();
	}

	private static function cache_get() {
		if ( isset( self::$readers['cache_get'] ) ) {
			return call_user_func( self::$readers['cache_get'] );
		}
		return function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT ) : false;
	}

	private static function cache_set( array $v ): void {
		if ( isset( self::$readers['cache_set'] ) ) {
			call_user_func( self::$readers['cache_set'], $v );
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::TRANSIENT, $v, self::TTL );
		}
	}
}
