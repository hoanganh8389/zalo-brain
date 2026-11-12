<?php
/**
 * R-CELL-SERVICE-BOUNDARY (R-CSB) — where is this site running?  ONE owner for two facts every lane needs:
 *
 *   is_dev_site()     the site has no public https address the Hub / cell can call back (CSB-5): localhost, an IP,
 *                     a dev TLD (.local .test .localhost .lan .internal .example .invalid), a single-label host,
 *                     or WP_ENVIRONMENT_TYPE = local. Same host list as the Hub's
 *                     BizCity_Router_Auth::is_non_production_domain_value() (PHASE-0.138 S138-HB1) — keep the two in step.
 *   is_hosted_site()  the site lives on the BizCity multisite network (created through the BizCity signup page):
 *                     group C tools keep the Hub + wallet (D138-7), the 1 TB gift applies (D138-4). The BizCity
 *                     servers say so with the constant BIZCITY_HOSTED_NETWORK (mu-plugin, never shipped).
 *
 * dev_limits() is the ONE table "what works on a dev site" — the settings card prints it and
 * docs/developer/CELL-SERVICES.md §5 copies it (CL1: one source).
 *
 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 S138-CL1
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Site_Environment {

	/** Host suffixes that never resolve publicly (RFC 6761 / 6762 + common local-dev tools). */
	const DEV_SUFFIXES = array( '.local', '.test', '.localhost', '.lan', '.internal', '.example', '.invalid', '.home.arpa' );

	/** @var array<string,bool> */
	private static $memo = array();

	/** Lower-case host of home_url(), without "www." and port. */
	public static function host(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( trim( $host, '[]' ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** Pure: is this host unreachable from the internet? */
	public static function is_dev_host( string $host ): bool {
		$host = strtolower( trim( $host, "[] \t" ) );
		if ( '' === $host || 'localhost' === $host ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true; // IPv4 and IPv6 literals (127.0.0.1, 192.168.x.x, ::1): no domain to call back over https
		}
		if ( false === strpos( $host, '.' ) ) {
			return true; // single-label host ("mysite")
		}
		foreach ( self::DEV_SUFFIXES as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	public static function is_dev_site(): bool {
		if ( ! isset( self::$memo['dev'] ) ) {
			$dev = self::is_dev_host( self::host() )
				|| ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() );
			self::$memo['dev'] = (bool) apply_filters( 'bizcity_is_dev_site', $dev );
		}
		return self::$memo['dev'];
	}

	public static function is_hosted_site(): bool {
		if ( ! isset( self::$memo['hosted'] ) ) {
			$hosted = defined( 'BIZCITY_HOSTED_NETWORK' ) && BIZCITY_HOSTED_NETWORK && is_multisite();
			self::$memo['hosted'] = (bool) apply_filters( 'bizcity_is_hosted_site', $hosted );
		}
		return self::$memo['hosted'];
	}

	/**
	 * What works on a dev site (R-CSB CSB-5). `ok`: true = works · false = does not · 'partial'.
	 *
	 * @return array<int,array{id:string,label:string,ok:bool|string,note:string}>
	 */
	public static function dev_limits(): array {
		return array(
			array( 'id' => 'brain', 'label' => 'Notebook, sổ ghi, kho tri thức', 'ok' => true, 'note' => 'Chạy hoàn toàn trên site.' ),
			array( 'id' => 'key', 'label' => 'Dán khoá biz- và Test Connection', 'ok' => true, 'note' => 'Gói Free; tối đa 2 khoá thử nghiệm mỗi tài khoản.' ),
			array( 'id' => 'ocr', 'label' => 'Tải PDF / tài liệu ⇒ OCR ⇒ vào kho', 'ok' => true, 'note' => 'Nội dung tệp được gửi kèm lời gọi.' ),
			array( 'id' => 'rag', 'label' => 'Embedding / RAG, hỏi có trích dẫn', 'ok' => true, 'note' => 'Máy cần ra được internet tới bizcity.vn.' ),
			array( 'id' => 'assistant', 'label' => 'Trợ lý trả lời trên web', 'ok' => true, 'note' => 'Câu trả lời được lưu ngay trên site.' ),
			array( 'id' => 'connect', 'label' => 'Kết nối một chạm', 'ok' => false, 'note' => 'Dán khoá vào ô API key.' ),
			array( 'id' => 'paid', 'label' => 'Mua gói trả phí', 'ok' => false, 'note' => 'Gói trả phí cần tên miền thật.' ),
			array( 'id' => 'media_url', 'label' => 'Phân tích ảnh / ghi âm theo đường link', 'ok' => false, 'note' => 'BizCity không tải được tệp từ máy của bạn.' ),
			array( 'id' => 'callback', 'label' => 'Tệp cell tạo ra, việc chạy nền báo về site', 'ok' => false, 'note' => 'BizCity phải gọi ngược về site qua https.' ),
			array( 'id' => 'channels', 'label' => 'Kênh Zalo, Facebook', 'ok' => false, 'note' => 'Cần tên miền thật có https.' ),
		);
	}

	/**
	 * The guided error a feature returns on a dev site instead of failing silently (CSB-5, R-ERROR-UX shape).
	 *
	 * @param string $feature one of dev_limits() ids
	 */
	public static function dev_site_error( string $feature ): WP_Error {
		$note = '';
		foreach ( self::dev_limits() as $row ) {
			if ( $row['id'] === $feature ) {
				$note = $row['note'];
				break;
			}
		}
		return new WP_Error(
			'dev_site_unreachable',
			'Site đang chạy thử trên máy (' . self::host() . '): tính năng này cần BizCity gọi ngược về site qua https. ' . $note,
			array(
				'status'  => 409,
				'feature' => $feature,
				'hint'    => 'Dùng một tên miền thật có https — nhanh nhất là tạo site qua trang đăng ký BizCity.',
			)
		);
	}

	/** Tests only. */
	public static function reset(): void {
		self::$memo = array();
	}
}

if ( ! function_exists( 'bizcity_is_dev_site' ) ) {
	function bizcity_is_dev_site(): bool {
		return BizCity_Site_Environment::is_dev_site();
	}
}

if ( ! function_exists( 'bizcity_is_hosted_site' ) ) {
	function bizcity_is_hosted_site(): bool {
		return BizCity_Site_Environment::is_hosted_site();
	}
}
