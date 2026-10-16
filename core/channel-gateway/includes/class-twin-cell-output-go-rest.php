<?php
/**
 * Public link of a cell output — `GET bizcity-twin/v1/outputs/<cm_…>/go` (PHASE-0.110 S110-L3, D110 photo-video).
 *
 * The cell sends the owner this link in Zalo as soon as the video is made. By the time they tap it the site has (or has not yet) copied the file
 * to the media library (BizCity_Twin_Cell_Output_Store, meta `_bizcity_cell_output_media`):
 *   - copied  ⇒ 302 to `wp_get_attachment_url()` (already the R2 CDN's once bizcity-r2.php offloaded it)
 *   - not yet ⇒ 202 + `Retry-After: 60` + `{ok:false,status:'saving'}`; a waiting WP-Cron pull is nudged (non-blocking), never run inline
 * The 24-hex media id is unguessable, so the route is public (a CDN link has no login either). Light per-IP limit: 60 requests / minute.
 *
 * // @axis twin-agent-axis@1 seam SEAM-3
 *
 * // [2026-10-14 08:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.110 S110-L3 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Cell_Output_Go_REST', false ) ) {
	return;
}

final class BizCity_Twin_Cell_Output_Go_REST {

	const NS        = 'bizcity-twin/v1';
	const RATE_MAX  = 60;
	const RATE_SECS = 60;

	/**
	 * Test seams: ip(): string · attachment(media_id): int · url(attachment_id): string · nudge(media_id): bool
	 * (the store's own `$readers` still apply underneath when a seam is not set).
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/outputs/(?P<cm>cm_[0-9a-f]{24})/go', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // public: the id is the capability
		) );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$cm = (string) $request->get_param( 'cm' );
		if ( ! preg_match( '/^cm_[0-9a-f]{24}$/', $cm ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found' ), 404 );
		}
		if ( ! self::allow( self::ip() ) ) {
			$r = new WP_REST_Response( array( 'ok' => false, 'code' => 'rate_limited' ), 429 );
			$r->header( 'Retry-After', (string) self::RATE_SECS );
			return $r;
		}
		$att = self::attachment( $cm );
		$url = $att > 0 ? self::url( $att ) : '';
		if ( '' !== $url ) {
			$r = new WP_REST_Response( null, 302 );
			$r->header( 'Location', $url );
			$r->header( 'Cache-Control', 'no-store' );
			return $r;
		}
		self::nudge( $cm );
		$r = new WP_REST_Response( array( 'ok' => false, 'status' => 'saving' ), 202 );
		$r->header( 'Retry-After', '60' );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/** Fixed-window counter per IP in a transient; false = over the limit. */
	private static function allow( string $ip ): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}
		$key = 'bizcity_cog_' . substr( md5( $ip ), 0, 20 );
		$n   = (int) get_transient( $key ) + 1;
		if ( $n > self::RATE_MAX ) {
			return false;
		}
		set_transient( $key, $n, self::RATE_SECS );
		return true;
	}

	private static function ip(): string {
		if ( isset( self::$readers['ip'] ) ) {
			return (string) call_user_func( self::$readers['ip'] );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ), 0, 64 ) : '';
	}

	private static function attachment( string $cm ): int {
		if ( isset( self::$readers['attachment'] ) ) {
			return (int) call_user_func( self::$readers['attachment'], $cm );
		}
		return class_exists( 'BizCity_Twin_Cell_Output_Store' ) ? BizCity_Twin_Cell_Output_Store::attachment_of_media( $cm ) : 0;
	}

	private static function url( int $att ): string {
		if ( isset( self::$readers['url'] ) ) {
			return (string) call_user_func( self::$readers['url'], $att );
		}
		return class_exists( 'BizCity_Twin_Cell_Output_Store' ) ? BizCity_Twin_Cell_Output_Store::url_of( $att ) : '';
	}

	private static function nudge( string $cm ): void {
		if ( isset( self::$readers['nudge'] ) ) {
			call_user_func( self::$readers['nudge'], $cm );
			return;
		}
		if ( class_exists( 'BizCity_Twin_Cell_Output_Store' ) ) {
			BizCity_Twin_Cell_Output_Store::nudge_media( $cm );
		}
	}
}
