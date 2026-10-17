<?php
/**
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-17 11:50 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.120 S120-L1 — danh bạ Zalo của số (cell) → CRM contacts, và trang trace
 * "gửi thay chủ" cho admin client (D120-17/18). Mọi số đi qua Hub (`zalo-personal-bridge/brain/{directory,outreach}`), không gọi cell trực tiếp.
 *
 * Quyền riêng tư (chủ chốt D120-15/19):
 *   - Máy-với-máy nhận UID đầy đủ (cần để gắn CRM contact theo source_id); MỌI response REST ra trình duyệt từ lớp này đã CHE:
 *     UID chỉ còn 4 ký tự cuối, số điện thoại (nếu có trong CRM) còn 3 số cuối.
 *   - Không có đường xuất danh bạ (không CSV, không trả UID đầy đủ ra giao diện).
 *   - Danh bạ cell không có số điện thoại / ảnh / ngày sinh; lớp này không thêm gì ngoài tên + loại.
 *
 * CRM source_id theo đúng quy ước có sẵn: người = UID, nhóm = 'group:' . group_id (class-adapter-zalo-personal.php).
 *
 * @package bizcity-twin-ai/core/crm
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Zalo_Directory {

	const PAGE_SLUG = 'bizcity-crm-zalo-outreach';
	const ACQ_SOURCE = 'zalo_directory';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
	}

	/* ---------------------------------------------------------------- che dữ liệu */

	/** "1234567890123" → "•••0123" (4 ký tự cuối) */
	public static function mask_uid( string $uid ): string {
		$uid = preg_replace( '/[^0-9A-Za-z]/', '', $uid );
		return strlen( (string) $uid ) > 4 ? '•••' . substr( (string) $uid, -4 ) : (string) $uid;
	}

	/** số điện thoại → "…123" (3 số cuối) */
	public static function mask_phone( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		return strlen( (string) $d ) >= 4 ? '…' . substr( (string) $d, -3 ) : '';
	}

	/* ---------------------------------------------------------------- nguồn */

	/** Inbox Zalo cá nhân của site: channel_ref_id = bridge account id ở Hub */
	public static function zalo_inboxes(): array {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		$out = array();
		foreach ( BizCity_CRM_Repository::list_inboxes() as $inbox ) {
			if ( 'zalo_personal' === (string) ( $inbox['channel_type'] ?? '' ) && preg_match( '/^\d+$/', (string) ( $inbox['channel_ref_id'] ?? '' ) ) ) {
				$out[] = $inbox;
			}
		}
		return $out;
	}

	private static function inbox_or_null( int $inbox_id ): ?array {
		foreach ( self::zalo_inboxes() as $inbox ) {
			if ( (int) $inbox['id'] === $inbox_id ) {
				return $inbox;
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	private static function hub( string $method, string $path, array $inbox, array $query = array(), array $body = array() ): array {
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return array( 'ok' => false, '_degraded' => true, 'code' => 'hub_client_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->outreach_call( $method, $path, (string) $inbox['channel_ref_id'], $query, $body );
	}

	/* ---------------------------------------------------------------- nhập CRM */

	/**
	 * Gắn từng mục danh bạ vào CRM contacts của inbox (upsert theo source_id). Liên hệ đã có: chỉ làm mới tên khi tên cũ là tên tạm
	 * (luật sẵn có của upsert_contact) - không ghi đè tên chủ đã sửa tay.
	 *
	 * @param list<array{uid:string,kind:string,display_name:string,member_count?:int,has_messaged?:bool}> $entries
	 * @return array{created:int,updated:int,skipped:int}
	 */
	public static function import_entries( array $inbox, array $entries ): array {
		$stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0 );
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return $stats;
		}
		$inbox_id = (int) $inbox['id'];
		$account  = (string) $inbox['channel_ref_id'];
		foreach ( $entries as $e ) {
			$uid  = preg_replace( '/[^0-9]/', '', (string) ( $e['uid'] ?? '' ) );
			$name = trim( wp_strip_all_tags( (string) ( $e['display_name'] ?? '' ) ) );
			if ( '' === $uid || '' === $name ) {
				$stats['skipped']++;
				continue;
			}
			$is_group  = 'group' === (string) ( $e['kind'] ?? '' );
			$source_id = $is_group ? 'group:' . $uid : $uid;
			$existed   = BizCity_CRM_Repository::find_contact_id_by_source( $inbox_id, $source_id ) > 0;
			$attrs     = array( 'zalo_kind' => $is_group ? 'group' : 'friend', 'directory_source' => self::ACQ_SOURCE );
			if ( $is_group ) {
				$attrs['group_name'] = $name;
				$attrs['member_count'] = (int) ( $e['member_count'] ?? 0 );
			}
			$r = BizCity_CRM_Repository::upsert_contact( $inbox_id, $source_id, array(
				'name'                  => $name,
				'acquisition_source'    => self::ACQ_SOURCE,
				'additional_attributes' => $attrs,
			) );
			$contact_id = (int) ( $r['contact_id'] ?? 0 );
			if ( $contact_id <= 0 ) {
				$stats['skipped']++;
				continue;
			}
			if ( ! $is_group && class_exists( 'BizCity_CRM_Contact_Identity' ) && method_exists( 'BizCity_CRM_Contact_Identity', 'link' ) ) {
				BizCity_CRM_Contact_Identity::link( $contact_id, 'zalo', $uid, $account );
			}
			$stats[ $existed ? 'updated' : 'created' ]++;
		}
		return $stats;
	}

	/* ---------------------------------------------------------------- REST */

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		$ns  = defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		$cap = array( __CLASS__, 'can_manage' );
		register_rest_route( $ns, '/zalo-outreach/inboxes', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_inboxes' ), 'permission_callback' => $cap ) );
		register_rest_route( $ns, '/zalo-outreach/(?P<inbox>\d+)/directory', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_directory' ), 'permission_callback' => $cap ) );
		register_rest_route( $ns, '/zalo-outreach/(?P<inbox>\d+)/directory/sync', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_sync' ), 'permission_callback' => $cap ) );
		register_rest_route( $ns, '/zalo-outreach/(?P<inbox>\d+)/log', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_log' ), 'permission_callback' => $cap ) );
		register_rest_route( $ns, '/zalo-outreach/(?P<inbox>\d+)/settings', array( 'methods' => 'GET, POST', 'callback' => array( __CLASS__, 'rest_settings' ), 'permission_callback' => $cap ) );
	}

	private static function fail( string $code, string $message, string $hint, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint ), $status );
	}

	private static function degraded_response( array $r ): WP_REST_Response {
		return self::fail(
			'zalo_outreach_unavailable',
			'Chưa đọc được dữ liệu từ số Zalo (cell / Hub chưa trả lời).',
			'Kiểm số đang kết nối, khoá 1API của site còn hiệu lực, rồi thử lại. Mã: ' . sanitize_key( (string) ( $r['code'] ?? $r['error'] ?? 'unknown' ) ),
			502
		);
	}

	public static function rest_inboxes(): WP_REST_Response {
		$out = array();
		foreach ( self::zalo_inboxes() as $inbox ) {
			$out[] = array( 'id' => (int) $inbox['id'], 'name' => (string) ( $inbox['name'] ?? '' ), 'number' => self::mask_uid( (string) $inbox['channel_ref_id'] ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'inboxes' => $out ), 200 );
	}

	/** Danh bạ ĐÃ CHE cho giao diện: tên · loại · đã từng nhắn / chưa · UID 4 ký tự cuối */
	public static function shape_directory_for_ui( array $entries ): array {
		$out = array();
		foreach ( $entries as $e ) {
			$out[] = array(
				'name'         => (string) ( $e['display_name'] ?? '' ),
				'kind'         => 'group' === (string) ( $e['kind'] ?? '' ) ? 'group' : 'friend',
				'has_messaged' => ! empty( $e['has_messaged'] ),
				'uid'          => self::mask_uid( (string) ( $e['uid'] ?? '' ) ),
				'members'      => (int) ( $e['member_count'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function rest_directory( WP_REST_Request $req ): WP_REST_Response {
		$inbox = self::inbox_or_null( (int) $req['inbox'] );
		if ( ! $inbox ) {
			return self::fail( 'inbox_not_found', 'Không có số Zalo này trên site.', 'Chọn lại số trong danh sách.', 404 );
		}
		$r = self::hub( 'GET', 'directory', $inbox );
		if ( empty( $r['ok'] ) ) {
			return self::degraded_response( $r );
		}
		return new WP_REST_Response( array( 'ok' => true, 'enabled' => ! empty( $r['enabled'] ), 'synced_at' => (string) ( $r['synced_at'] ?? '' ), 'entries' => self::shape_directory_for_ui( (array) ( $r['entries'] ?? array() ) ) ), 200 );
	}

	/** Đồng bộ ở cell (Zalo) rồi nhập vào CRM contacts. Trả số đếm, không trả danh sách UID. */
	public static function rest_sync( WP_REST_Request $req ): WP_REST_Response {
		$inbox = self::inbox_or_null( (int) $req['inbox'] );
		if ( ! $inbox ) {
			return self::fail( 'inbox_not_found', 'Không có số Zalo này trên site.', 'Chọn lại số trong danh sách.', 404 );
		}
		$s = self::hub( 'POST', 'directory/sync', $inbox );
		$d = self::hub( 'GET', 'directory', $inbox );
		if ( empty( $d['ok'] ) ) {
			return self::degraded_response( $d );
		}
		if ( empty( $d['enabled'] ) ) {
			return self::fail( 'directory_disabled', 'Số này đang tắt "Giữ danh bạ Zalo để gửi thay".', 'Bật lại trong Cấu hình của cell (nhóm Lịch hẹn) rồi đồng bộ.', 409 );
		}
		$stats = self::import_entries( $inbox, (array) ( $d['entries'] ?? array() ) );
		return new WP_REST_Response( array(
			'ok'          => true,
			'synced_now'  => ! empty( $s['ok'] ),
			'sync_note'   => empty( $s['ok'] ) ? 'Không đồng bộ lại được từ Zalo (dùng bản đã lưu ở cell).' : '',
			'synced_at'   => (string) ( $d['synced_at'] ?? '' ),
			'total'       => count( (array) ( $d['entries'] ?? array() ) ),
			'crm'         => $stats,
		), 200 );
	}

	/** Sổ gửi thay ĐÃ CHE: admin kiểm "gửi có đúng không" (ai nhờ, gửi ai, nội dung, kết quả) - UID còn 4 ký tự cuối */
	public static function shape_log_for_ui( array $items ): array {
		$out = array();
		foreach ( $items as $it ) {
			$recipients = array();
			foreach ( (array) ( $it['recipients'] ?? array() ) as $r ) {
				$recipients[] = array(
					'name' => (string) ( $r['display_name'] ?? '' ),
					'kind' => (string) ( $r['kind'] ?? '' ),
					'uid'  => self::mask_uid( (string) ( $r['uid'] ?? '' ) ),
					'body' => (string) ( $r['body'] ?? '' ),
				);
			}
			$results = array();
			foreach ( (array) ( $it['results'] ?? array() ) as $x ) {
				$results[] = array(
					'name'   => (string) ( $x['display_name'] ?? '' ),
					'uid'    => self::mask_uid( (string) ( $x['uid'] ?? '' ) ),
					'status' => (string) ( $x['status'] ?? '' ),
					'reason' => (string) ( $x['reason'] ?? '' ),
					'at'     => (string) ( $x['at'] ?? '' ),
				);
			}
			$out[] = array(
				'code'         => (string) ( $it['code'] ?? '' ),
				'status'       => (string) ( $it['status'] ?? '' ),
				'requested_by' => self::mask_uid( (string) ( $it['requested_by'] ?? '' ) ),
				'recipients'   => $recipients,
				'body'         => (string) ( $it['body'] ?? '' ),
				'signature'    => (string) ( $it['signature'] ?? '' ),
				'attachment'   => (string) ( $it['attachment'] ?? '' ),
				'send_at'      => (string) ( $it['send_at'] ?? '' ),
				'results'      => $results,
				'error_code'   => (string) ( $it['error_code'] ?? '' ),
				'created_at'   => (string) ( $it['created_at'] ?? '' ),
				'decided_at'   => (string) ( $it['decided_at'] ?? '' ),
			);
		}
		return $out;
	}

	public static function rest_log( WP_REST_Request $req ): WP_REST_Response {
		$inbox = self::inbox_or_null( (int) $req['inbox'] );
		if ( ! $inbox ) {
			return self::fail( 'inbox_not_found', 'Không có số Zalo này trên site.', 'Chọn lại số trong danh sách.', 404 );
		}
		$limit = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
		$r = self::hub( 'GET', 'outreach/log', $inbox, array( 'limit' => $limit ) );
		if ( empty( $r['ok'] ) ) {
			return self::degraded_response( $r );
		}
		return new WP_REST_Response( array( 'ok' => true, 'items' => self::shape_log_for_ui( (array) ( $r['items'] ?? array() ) ) ), 200 );
	}

	public static function rest_settings( WP_REST_Request $req ): WP_REST_Response {
		$inbox = self::inbox_or_null( (int) $req['inbox'] );
		if ( ! $inbox ) {
			return self::fail( 'inbox_not_found', 'Không có số Zalo này trên site.', 'Chọn lại số trong danh sách.', 404 );
		}
		if ( 'POST' === strtoupper( $req->get_method() ) ) {
			$body = array();
			if ( null !== $req->get_param( 'signature_name' ) ) {
				$body['signature_name'] = mb_substr( sanitize_text_field( (string) $req->get_param( 'signature_name' ) ), 0, 80 );
			}
			if ( null !== $req->get_param( 'signature_enabled' ) ) {
				$body['signature_enabled'] = (bool) rest_sanitize_boolean( $req->get_param( 'signature_enabled' ) );
			}
			$w = self::hub( 'POST', 'outreach/settings', $inbox, array(), $body );
			if ( empty( $w['ok'] ) ) {
				return self::degraded_response( $w );
			}
		}
		$r = self::hub( 'GET', 'outreach/settings', $inbox );
		if ( empty( $r['ok'] ) ) {
			return self::degraded_response( $r );
		}
		return new WP_REST_Response( array( 'ok' => true, 'signature_name' => (string) ( $r['signature_name'] ?? '' ), 'signature_enabled' => ! empty( $r['signature_enabled'] ) ), 200 );
	}

	/* ---------------------------------------------------------------- trang quản trị */

	public static function register_page(): void {
		$parent = class_exists( 'BizCity_CRM_Admin_Menu' ) ? BizCity_CRM_Admin_Menu::SLUG : 'tools.php';
		add_submenu_page( $parent, 'Danh bạ & gửi thay Zalo', 'Danh bạ & gửi thay Zalo', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function render_page(): void {
		$rest  = esc_url_raw( rest_url( ( defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1' ) . '/zalo-outreach/' ) );
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1>Danh bạ &amp; gửi thay Zalo</h1>
			<p class="description">Danh bạ của số (chỉ tên, loại, đã từng nhắn hay chưa) và sổ các tin trợ lý đã gửi thay chủ. UID chỉ hiện 4 ký tự cuối; không có chức năng xuất danh bạ.</p>
			<div id="bzc-outreach" data-rest="<?php echo esc_attr( $rest ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><p>Đang tải…</p></div>
		</div>
		<style>
			#bzc-outreach .bo-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0}
			#bzc-outreach .bo-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;margin:14px 0}
			#bzc-outreach .bo-muted{color:#646970}
			#bzc-outreach .bo-err{border-left:4px solid #d63638;background:#fcf0f1;padding:10px}
			#bzc-outreach .bo-ok{color:#008a20}
			#bzc-outreach .bo-no{color:#996800}
			#bzc-outreach pre{white-space:pre-wrap;margin:0;font-family:inherit}
		</style>
		<script>
		(function(){
			var root=document.getElementById('bzc-outreach'); if(!root)return;
			var rest=root.dataset.rest, nonce=root.dataset.nonce, inbox=0;
			function api(p,m,b){return fetch(rest+p,{method:m||'GET',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json();});}
			function esc(v){var s=document.createElement('span');s.textContent=v==null?'':String(v);return s.innerHTML;}
			function err(r){return '<div class="bo-err">'+esc(r.message||'Lỗi')+'<br><span class="bo-muted">'+esc(r.hint||'')+'</span></div>';}
			var ST={sent:'đã gửi',scheduled:'đã hẹn',blocked:'bị chặn',failed:'hỏng',pending:'chờ duyệt',waiting_first_contact:'chờ người nhận nhắn trước',sending:'đang gửi',partial:'gửi một phần',rejected:'chủ huỷ',expired:'hết hạn'};
			function boot(){api('inboxes').then(function(r){
				if(!r.ok){root.innerHTML=err(r);return;}
				if(!r.inboxes.length){root.innerHTML='<p class="bo-muted">Site chưa có số Zalo cá nhân nào.</p>';return;}
				var h='<div class="bo-bar"><label>Số: <select id="bo-inbox">'+r.inboxes.map(function(i){return '<option value="'+i.id+'">'+esc(i.name||'Zalo')+' ('+esc(i.number)+')</option>';}).join('')+'</select></label></div><div id="bo-body"></div>';
				root.innerHTML=h; var sel=root.querySelector('#bo-inbox'); inbox=sel.value; sel.onchange=function(){inbox=sel.value;load();}; load();
			});}
			function load(){
				var body=root.querySelector('#bo-body'); body.innerHTML='<p class="bo-muted">Đang tải…</p>';
				Promise.all([api(inbox+'/directory'),api(inbox+'/log?limit=100'),api(inbox+'/settings')]).then(function(res){
					var d=res[0],l=res[1],s=res[2],h='';
					h+='<div class="bo-card"><h2>Chữ ký tin gửi thay</h2>'+(s.ok?'<div class="bo-bar"><input id="bo-sig" type="text" maxlength="80" placeholder="vd: anh Hoàng Anh" value="'+esc(s.signature_name)+'"><label><input id="bo-sig-on" type="checkbox" '+(s.signature_enabled?'checked':'')+'> Bật chữ ký</label><button class="button" id="bo-sig-save">Lưu</button><span class="bo-muted">Tin sẽ kết thúc bằng: — Trợ lý của <b id="bo-sig-prev">'+esc(s.signature_name||'(tên Zalo của chủ)')+'</b></span></div>':err(s))+'</div>';
					h+='<div class="bo-card"><h2>Danh bạ</h2>';
					if(!d.ok){h+=err(d);}else{
						var n=d.entries.filter(function(e){return e.has_messaged;}).length;
						h+='<div class="bo-bar"><button class="button button-primary" id="bo-sync">Đồng bộ &amp; nhập vào CRM</button><span class="bo-muted">'+(d.enabled?('Lần đồng bộ: '+esc(d.synced_at||'chưa có')+' · '+d.entries.length+' mục · '+n+' đã từng nhắn'):'Số này đang tắt giữ danh bạ')+'</span></div><div id="bo-sync-res"></div>';
						if(d.entries.length){h+='<table class="widefat striped"><thead><tr><th>Tên</th><th>Loại</th><th>Đã từng nhắn</th><th>UID</th></tr></thead><tbody>'+d.entries.map(function(e){return '<tr><td>'+esc(e.name)+'</td><td>'+(e.kind==='group'?'Nhóm'+(e.members?' ('+e.members+')':''):'Bạn bè')+'</td><td>'+(e.has_messaged?'<span class="bo-ok">✓ đã nhắn</span>':'<span class="bo-no">chưa</span>')+'</td><td><code>'+esc(e.uid)+'</code></td></tr>';}).join('')+'</tbody></table>';}
					}
					h+='</div><div class="bo-card"><h2>Sổ gửi thay</h2>';
					if(!l.ok){h+=err(l);}else if(!l.items.length){h+='<p class="bo-muted">Chưa có tin gửi thay nào.</p>';}else{
						h+='<table class="widefat striped"><thead><tr><th>Lúc tạo</th><th>Mã</th><th>Trạng thái</th><th>Gửi cho</th><th>Nội dung</th><th>Kết quả từng người</th></tr></thead><tbody>'+l.items.map(function(it){
							var to=it.recipients.map(function(r){return esc(r.name)+' <code>'+esc(r.uid)+'</code>'+(r.body?'<br><span class="bo-muted">riêng: '+esc(r.body)+'</span>':'');}).join('<br>');
							var rs=it.results.map(function(x){return esc(x.name)+': '+esc(ST[x.status]||x.status)+(x.reason?' <span class="bo-muted">('+esc(x.reason)+')</span>':'');}).join('<br>')||'<span class="bo-muted">—</span>';
							var extra=(it.attachment?'<br>📎 '+esc(it.attachment):'')+(it.send_at?'<br>⏰ '+esc(it.send_at):'');
							return '<tr><td>'+esc(it.created_at)+'</td><td><code>'+esc(it.code)+'</code></td><td>'+esc(ST[it.status]||it.status)+'</td><td>'+to+'</td><td><pre>'+esc(it.body)+'\n'+esc(it.signature)+'</pre>'+extra+'</td><td>'+rs+'</td></tr>';
						}).join('')+'</tbody></table>';
					}
					h+='</div>'; body.innerHTML=h;
					var b=body.querySelector('#bo-sync'); if(b)b.onclick=function(){b.disabled=true;var out=body.querySelector('#bo-sync-res');out.innerHTML='<p class="bo-muted">Đang đồng bộ…</p>';api(inbox+'/directory/sync','POST',{}).then(function(r){out.innerHTML=r.ok?'<p class="bo-ok">'+esc(r.total)+' mục · CRM: thêm '+r.crm.created+', cập nhật '+r.crm.updated+', bỏ qua '+r.crm.skipped+(r.sync_note?' · '+esc(r.sync_note):'')+'</p>':err(r);b.disabled=false;});};
					var sv=body.querySelector('#bo-sig-save'); if(sv)sv.onclick=function(){sv.disabled=true;api(inbox+'/settings','POST',{signature_name:body.querySelector('#bo-sig').value,signature_enabled:body.querySelector('#bo-sig-on').checked}).then(function(r){sv.disabled=false;if(r.ok){body.querySelector('#bo-sig-prev').textContent=r.signature_name||'(tên Zalo của chủ)';}else{alert(r.message||'Lỗi');}});};
				});
			}
			boot();
		})();
		</script>
		<?php
	}
}
