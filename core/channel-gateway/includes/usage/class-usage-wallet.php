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
