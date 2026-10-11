<?php
/**
 * Legacy Facebook Poster Functions
 * Migrated from fb-connect-poster.php
 * 
 * @package BizCity_Facebook_Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [2026-10-10 11:25 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-8a — five legacy admin page renderers (bizcity_fb_app_settings_admin_page,
// bizcity_fb_connect_page, bizcity_fb_business_management_page, bizcity_fb_comments_manager_page, bizcity_fb_fanpage_posts_page)
// had no menu registration and no caller (BizCity_Facebook_Bot_Admin_Menu::render_* replaced them); the full original file is at
// core/_archived/z8a-20261010/plugins/bizcity-facebook-bot/lib/legacy-poster.php. Only the OAuth callback stays live.

if ( ! function_exists( 'bizcity_fb_handle_oauth_callback' ) ) {
/**
 * Handle Facebook OAuth callback
 */
function bizcity_fb_handle_oauth_callback() {
	if ( isset( $_GET['fb_callback'] ) && isset( $_GET['code'] ) ) {
		$code       = sanitize_text_field( $_GET['code'] );
		$token      = 'blog_';
		$app_id     = get_option( 'fb_app_id', defined( 'FB_APP_ID' ) ? FB_APP_ID : '' );
		$app_secret = get_option( 'fb_app_secret', defined( 'FB_APP_SECRET' ) ? FB_APP_SECRET : '' );

		$blog_details = get_blog_details( get_current_blog_id() );
		$domain       = 'https://' . rtrim( $blog_details->domain, '/' );
		$redirect_uri = $domain . '/?fb_callback=1';

		$token_url = "https://graph.facebook.com/v18.0/oauth/access_token?" . http_build_query( array(
			'client_id'     => $app_id,
			'redirect_uri'  => $redirect_uri,
			'client_secret' => $app_secret,
			'code'          => $code,
		) );

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'get token_url: ' . print_r( $token_url, true ) );
		// }

		$response = wp_remote_get( $token_url, array(
			'timeout'     => 30,
			'redirection' => 3,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'BizGPT-FBConnect/1.0; ' . home_url( '/' ),
			),
		) );

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'wp_remote_get response received' );
		// }

		if ( is_wp_error( $response ) ) {
			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'ERROR', 'FB token wp_remote_get WP_Error: ' . $response->get_error_message() );
			// }
			wp_die( 'Lỗi kết nối tới Facebook (WP_Error): ' . esc_html( $response->get_error_message() ) );
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$body_raw  = (string) wp_remote_retrieve_body( $response );
		$body      = json_decode( $body_raw, true );

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'FB token HTTP code: ' . $code_http );
		// 	back_trace( 'NOTICE', 'FB token body_raw: ' . $body_raw );
		// }

		if ( $code_http !== 200 ) {
			$msg = '';
			if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
				$msg = $body['error']['message'];
			}
			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'ERROR', 'FB token HTTP error: ' . $code_http . ' - ' . $msg );
			// }
			wp_die( 'Facebook token HTTP ' . $code_http . ( $msg ? ( ' - ' . esc_html( $msg ) ) : '' ) );
		}

		$access_token = $body['access_token'] ?? '';

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'FB access_token received: ' . ( $access_token ? 'YES' : 'NO' ) );
		// }

		// Lấy blog_id từ transient
		switch_to_blog( 1 );
		$blog_id = get_transient( 'fb_login_' . $token );
		delete_transient( 'fb_login_' . $token );
		restore_current_blog();

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'FB blog_id from transient: ' . ( $blog_id ? $blog_id : 'NOT FOUND' ) );
		// }

		if ( ! $blog_id ) {
			wp_die( '❌ Không tìm thấy blog_id tương ứng với token xác thực.' );
		}

		switch_to_blog( $blog_id );
		if ( $access_token ) {
			// Lưu user token trước
			update_option( 'fb_user_token', $access_token );
			
			// Debug: Kiểm tra thông tin user và quyền đã cấp
			$me_url = "https://graph.facebook.com/v18.0/me?fields=id,name&access_token=$access_token";
			$me_response = wp_remote_get( $me_url );
			$me_data = json_decode( wp_remote_retrieve_body( $me_response ), true );
			
			// Debug: Kiểm tra permissions đã được cấp
			$permissions_url = "https://graph.facebook.com/v18.0/me/permissions?access_token=$access_token";
			$permissions_response = wp_remote_get( $permissions_url );
			$permissions_data = json_decode( wp_remote_retrieve_body( $permissions_response ), true );
			
			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'NOTICE', 'FB /me data: ' . print_r( $me_data, true ) );
			// 	back_trace( 'NOTICE', 'FB /me/permissions data: ' . print_r( $permissions_data, true ) );
			// }
			
			$pages_url = "https://graph.facebook.com/v18.0/me/accounts?fields=id,name,access_token,category&access_token=$access_token";
			$pages_response = wp_remote_get( $pages_url );
			
			if ( is_wp_error( $pages_response ) ) {
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'ERROR', 'FB /me/accounts WP_Error: ' . $pages_response->get_error_message() );
				// }
				restore_current_blog();
				wp_die( 'Lỗi kết nối tới Facebook khi lấy danh sách Fanpage: ' . esc_html( $pages_response->get_error_message() ) );
			}
			
			$pages_http_code = (int) wp_remote_retrieve_response_code( $pages_response );
			$pages_body_raw = wp_remote_retrieve_body( $pages_response );
			$data = json_decode( $pages_body_raw, true );

			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'NOTICE', 'FB /me/accounts HTTP: ' . $pages_http_code );
			// 	back_trace( 'NOTICE', 'FB pages data: ' . print_r( $data, true ) );
			// }
			
			// Kiểm tra lỗi từ Facebook API
			if ( isset( $data['error'] ) ) {
				$error_msg = $data['error']['message'] ?? 'Unknown error';
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'ERROR', 'FB /me/accounts error: ' . $error_msg );
				// }
				restore_current_blog();
				wp_die( 'Facebook API Error: ' . esc_html( $error_msg ) );
			}

			if ( ! empty( $data['data'] ) ) {
				$pages_clean = array();
				foreach ( $data['data'] as $page ) {
					$pages_clean[] = array(
						'id'           => $page['id'],
						'name'         => $page['name'],
						'access_token' => $page['access_token'],
						'category'     => $page['category'] ?? '',
					);
				}

				update_option( 'fb_pages_connected', $pages_clean );
				
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'NOTICE', 'FB OAuth success! ' . count( $pages_clean ) . ' pages connected. Redirecting...' );
				// }
				
				restore_current_blog();
				wp_redirect( admin_url( 'admin.php?page=bizcity-facebook-bot-connect&status=success' ) );
				exit;
			} else {
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'ERROR', 'FB pages data is empty - user did not grant access to any Fanpage' );
				// }
				restore_current_blog();
				
				// Get user name for display
				$user_name = isset( $me_data['name'] ) ? esc_html( $me_data['name'] ) : 'User';
				$user_id = isset( $me_data['id'] ) ? esc_html( $me_data['id'] ) : '';
				
				wp_die( '
					<div style="font-family: Arial, sans-serif; max-width: 700px; margin: 50px auto; padding: 20px;">
						<h2 style="color: #c00;">❌ Không tìm thấy Fanpage nào!</h2>
						<p>Đã xác thực thành công với tài khoản: <b>' . $user_name . '</b> (ID: ' . $user_id . ')</p>
						<p>Tất cả permissions đã được cấp, nhưng API <code>/me/accounts</code> trả về danh sách rỗng.</p>
						
						<h3 style="color: #333; margin-top: 20px;">🔍 Nguyên nhân có thể:</h3>
						<ol style="line-height: 1.8;">
							<li><b>Facebook App đang ở chế độ Development:</b><br>
								Chỉ Admin/Developer/Tester của App mới có thể truy cập Fanpages.<br>
								→ <a href="https://developers.facebook.com/apps/' . esc_attr( $app_id ) . '/roles/roles/" target="_blank" style="color: #1877f2;">Thêm user vào App Roles</a> hoặc <a href="https://developers.facebook.com/apps/' . esc_attr( $app_id ) . '/app-review/requests/" target="_blank" style="color: #1877f2;">Chuyển App sang Live mode</a>
							</li>
							<li><b>Tài khoản không quản lý Fanpage nào:</b><br>
								Chỉ Admin hoặc Editor của Fanpage mới xuất hiện trong danh sách.<br>
								→ <a href="https://www.facebook.com/pages/?category=your_pages" target="_blank" style="color: #1877f2;">Kiểm tra Fanpages của bạn</a>
							</li>
							<li><b>Bạn đã bỏ chọn tất cả Fanpages trong dialog đăng nhập:</b><br>
								Khi đăng nhập, Facebook hỏi "Chọn Fanpages để chia sẻ với ứng dụng".<br>
								→ Thử đăng nhập lại và <b>tick chọn các Fanpage</b> bạn muốn kết nối.
							</li>
							<li><b>Fanpage bị giới hạn hoặc chưa published:</b><br>
								Fanpages chưa published hoặc bị restrict sẽ không xuất hiện.
							</li>
						</ol>
						
						<div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 15px; margin-top: 20px;">
							<b>💡 Giải pháp nhanh nhất:</b><br>
							Vào <a href="https://developers.facebook.com/apps/' . esc_attr( $app_id ) . '/roles/roles/" target="_blank" style="color: #1877f2;">App Roles</a> → Thêm tài khoản <b>' . $user_name . '</b> làm <b>Tester</b> hoặc <b>Developer</b> → Sau đó quay lại đăng nhập.
						</div>
						
						<p style="margin-top: 20px;">
							<a href="' . esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ) . '" style="display: inline-block; background: #1877f2; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">← Quay lại thử kết nối lại</a>
						</p>
					</div>
				' );
			}
		}
		restore_current_blog();

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'ERROR', 'FB OAuth failed at end - access_token: ' . ( $access_token ? 'YES' : 'NO' ) );
		// }
		wp_die( 'Lỗi xác thực Facebook - Không nhận được access token.' );
	}
}
} // end if function_exists bizcity_fb_handle_oauth_callback
