<?php
/**
 * BizCity_SelfWP_Form_Service — `cf7.form.update` (R1) and `cf7.form.set_recipient` (R2): the cell edits a Contact Form 7 form it built, and is the
 * ONLY thing that may change where the form's mail goes — through a previewed, confirmed step (PHASE-0.118 S118-L6/L8, doc 20, Q118-4).
 *
 * Rules:
 *  - only forms the assistant built (`cf7.form.create` leaves the normalised form-spec in the form's own meta); a hand-made form answers
 *    "edit it in WordPress" with the link, never a write;
 *  - `cf7.form.update` can change the fields, the labels, the button and the success message, and NEVER the recipient: the current recipient is
 *    read back and written back untouched, and any `recipient_email` is refused with a pointer to `cf7.form.set_recipient`;
 *  - `cf7.form.set_recipient` is R2 (preview with both addresses masked → confirm; the token is bound to the arguments); the previous address is kept
 *    (full, in the form's own meta, last 5) so `revert` can put it back; the audit and the history show masked addresses only;
 *  - every update keeps the previous form-spec as a snapshot (≤ 10, 90 days) — `restore` puts one back; one site-change-log@1 row per write;
 *  - runs as the WordPress user of the delegated principal; both `wpcf7_edit_contact_forms` and the per-form `wpcf7_edit_contact_form` are re-checked in
 *    every handler.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-17 (PHASE-0.118 S118-L6/L8)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-17 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L6/L8 — new file: cf7.form.update + cf7.form.set_recipient.
final class BizCity_SelfWP_Form_Service {

	const META              = BizCity_SelfWP_Action_MCP_Service::META_FORM;
	const SNAP_MAX          = 10;
	const SNAP_TTL_S        = 7776000; // 90 ngày (doc 50 §4)
	const RECIPIENTS_KEEP   = 5;
	const MAIL_FOOTER       = "\n-- \nGửi từ [_url]";

	/**
	 * Test seams, name → callable. Defaults call WordPress / Contact Form 7.
	 *   user_can(string $cap, ...$args): bool
	 *   get_form(int $id): array|null {id,title,meta:array|null,mail:array,messages:array}
	 *   save_form(array $args): int|WP_Error · update_meta(int $id, string $key, array $value) · edit_url(int $id): string
	 *
	 * @var array<string,callable>
	 */
	public static $seams = array();

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	private static function available(): bool {
		return function_exists( 'wpcf7_save_contact_form' ) || isset( self::$seams['save_form'] );
	}

	public static function register_tools() {
		if ( ! self::available() ) {
			return;
		}
		$S = 'BizCity_MCP_Tool_Registry';

		// @mcp bizcity-mcp-standard@1 tool cf7.form.update
		BizCity_MCP_Tool_Registry::register( 'cf7.form.update', array(
			'executor'       => 'cf7.form.update',
			'title'          => 'Sửa form liên hệ đã dựng',
			'description'    => 'Sửa MỘT form liên hệ do trợ lý dựng: spec = phần cần đổi của form-spec (title, fields — thay TOÀN BỘ danh sách ô —, submit_label, success_message) HOẶC restore = snapshot_id để quay về bản cũ. KHÔNG đổi được người nhận thư (dùng cf7.form.set_recipient, có xem trước). Bản cũ được lưu để quay lại. Form viết tay thì báo needs_human kèm đường dẫn sửa trong WordPress.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'form_id' ), 'properties' => array(
				'form_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'spec'    => array( 'type' => 'object', 'properties' => array(
					'title'           => array( 'type' => 'string', 'maxLength' => 120 ),
					'fields'          => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => BizCity_SelfWP_Page_Compiler::MAX_FIELDS ),
					'submit_label'    => array( 'type' => 'string', 'maxLength' => 30 ),
					'success_message' => array( 'type' => 'string', 'maxLength' => 200 ),
				) ),
				'restore' => array( 'type' => 'string', 'minLength' => 6, 'maxLength' => 16 ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'form_id' => array( 'type' => 'integer' ), 'shortcode' => array( 'type' => 'string' ), 'changes' => array( 'type' => 'array' ), 'snapshot_id' => array( 'type' => 'string' ), 'unchanged' => array( 'type' => 'boolean' ), 'recipient_masked' => array( 'type' => 'string' ) ), array( 'form_id' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'content.write',
			'required_cap'   => 'wpcf7_edit_contact_forms',
			'handler'        => array( __CLASS__, 'form_update' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'cf7_form_update',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );

		// [2026-10-17 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.118 S118-L8 — R2: đổi nơi thư của khách được gửi tới ⇒ luôn xem trước (địa chỉ đã che) → xác nhận
		// @mcp bizcity-mcp-standard@1 tool cf7.form.set_recipient
		BizCity_MCP_Tool_Registry::register( 'cf7.form.set_recipient', array(
			'executor'       => 'cf7.form.set_recipient',
			'title'          => 'Đổi người nhận thư của form',
			'description'    => 'Đồng bộ hệ thống (chỉ zalo-hub gọi): đổi email nhận thư của MỘT form do trợ lý dựng (recipient_email, MỘT địa chỉ) HOẶC quay lại địa chỉ trước (revert=true). Lần đầu chỉ trả bản xem trước (địa chỉ cũ → mới, đã che bớt) + confirm_token, CHƯA đổi; gọi lại ĐÚNG tham số kèm confirm_token sau khi người yêu cầu đồng ý. Đây là cửa DUY NHẤT đổi nơi thông tin khách điền được gửi tới.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'form_id' ), 'properties' => array(
				'form_id'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'recipient_email' => array( 'type' => 'string', 'maxLength' => 100 ),
				'revert'          => array( 'type' => 'boolean' ),
				'confirm_token'   => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'status' => array( 'type' => 'string' ), 'preview' => array( 'type' => 'object' ), 'confirm_token' => array( 'type' => 'string' ), 'expires_at' => array( 'type' => 'string' ), 'form_id' => array( 'type' => 'integer' ), 'recipient_masked' => array( 'type' => 'string' ), 'previous_masked' => array( 'type' => 'string' ), 'unchanged' => array( 'type' => 'boolean' ) ), array( 'status' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'content.write',
			'required_cap'   => 'wpcf7_edit_contact_forms',
			'handler'        => array( __CLASS__, 'set_recipient' ),
			'preview'        => array( __CLASS__, 'set_recipient_preview' ),
			'mode'           => 'content',
			'scopes'         => array( 'content.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'cf7_form_set_recipient',
			'fallback_pack'  => null,
			'since'          => '0.118.0',
		) );
	}

	/* ───────────────────────────── shared checks ──────────────────── */

	/** @return array|WP_Error the form row, only when the assistant built it and this person may edit it */
	private static function form_for( int $id ) {
		$f = self::seam( 'get_form', array( $id ), array( __CLASS__, 'default_get_form' ) );
		if ( ! is_array( $f ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Không có form #{$id} trên website.", 404 );
		}
		if ( ! self::can( 'wpcf7_edit_contact_forms' ) ) {
			return self::denied( 'wpcf7_edit_contact_forms', 'sửa form liên hệ' );
		}
		if ( ! self::can( 'wpcf7_edit_contact_form', $id ) ) {
			return self::denied( 'wpcf7_edit_contact_form', "sửa form #{$id}" );
		}
		if ( ! is_array( $f['meta']['spec'] ?? null ) ) {
			$edit = (string) self::seam( 'edit_url', array( $id ), array( __CLASS__, 'default_edit_url' ) );
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Form #{$id} không do trợ lý dựng nên trợ lý không sửa giúp (nhất là nơi nhận thư). Hãy sửa trong WordPress.", 422, array( 'help_code' => 'selfwp_form_not_generated', 'edit_url' => $edit ) );
		}
		if ( ! is_array( $f['mail'] ?? null ) || '' === trim( (string) ( $f['mail']['recipient'] ?? '' ) ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, "Không đọc được cấu hình thư của form #{$id} nên không sửa để tránh làm mất người nhận. Mở form trong WordPress (tab Thư) kiểm tra rồi thử lại.", 422, array( 'help_code' => 'selfwp_form_mail_unreadable' ) );
		}
		return $f;
	}

	/** @return array<int,array{id:string,at:string,by:int,title:string,spec:array}> newest first, expired / malformed dropped */
	private static function snapshot_list( array $meta ): array {
		$out = array();
		foreach ( (array) ( $meta['snapshots'] ?? array() ) as $n ) {
			$at = is_array( $n ) ? strtotime( (string) ( $n['at'] ?? '' ) ) : false;
			if ( ! is_array( $n ) || ! is_string( $n['id'] ?? null ) || ! is_array( $n['spec'] ?? null ) || false === $at || ( time() - $at ) > self::SNAP_TTL_S ) {
				continue;
			}
			$out[] = array( 'id' => $n['id'], 'at' => (string) $n['at'], 'by' => (int) ( $n['by'] ?? 0 ), 'title' => (string) ( $n['title'] ?? '' ), 'spec' => $n['spec'] );
		}
		return $out;
	}

	private static function spec_id( array $spec ): string {
		return substr( BizCity_SelfWP_Page_Compiler::spec_hash( $spec ), 0, 12 );
	}

	/** compact line-by-line difference of two normalised form-specs (≤ 8 lines, text cut to 60) */
	public static function diff( array $a, array $b ): array {
		$cut = static function ( $v ): string {
			$s = is_scalar( $v ) ? (string) $v : (string) json_encode( $v, JSON_UNESCAPED_UNICODE );
			return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 60, 'UTF-8' ) : substr( $s, 0, 60 );
		};
		$lines = array();
		foreach ( array( 'title' => 'Tên form', 'submit_label' => 'Nút gửi', 'success_message' => 'Lời cảm ơn' ) as $k => $label ) {
			if ( (string) ( $a[ $k ] ?? '' ) !== (string) ( $b[ $k ] ?? '' ) ) {
				$lines[] = $label . ': "' . $cut( $a[ $k ] ?? '' ) . '" → "' . $cut( $b[ $k ] ?? '' ) . '"';
			}
		}
		$fa = array();
		foreach ( (array) ( $a['fields'] ?? array() ) as $f ) {
			$fa[ (string) ( $f['name'] ?? '' ) ] = $f;
		}
		$fb = array();
		foreach ( (array) ( $b['fields'] ?? array() ) as $f ) {
			$fb[ (string) ( $f['name'] ?? '' ) ] = $f;
		}
		foreach ( $fa as $name => $f ) {
			if ( ! isset( $fb[ $name ] ) ) {
				$lines[] = 'Bỏ ô ' . $cut( $name ) . ' (' . $cut( $f['type'] ?? '' ) . ')';
			} elseif ( $f !== $fb[ $name ] ) {
				$lines[] = 'Ô ' . $cut( $name ) . ': "' . $cut( $f['label'] ?? '' ) . '" ' . $cut( $f['type'] ?? '' ) . ( ! empty( $f['required'] ) ? '*' : '' ) . ' → "' . $cut( $fb[ $name ]['label'] ?? '' ) . '" ' . $cut( $fb[ $name ]['type'] ?? '' ) . ( ! empty( $fb[ $name ]['required'] ) ? '*' : '' );
			}
		}
		foreach ( $fb as $name => $f ) {
			if ( ! isset( $fa[ $name ] ) ) {
				$lines[] = 'Thêm ô ' . $cut( $name ) . ' (' . $cut( $f['type'] ?? '' ) . ')';
			}
		}
		if ( array_keys( $fa ) !== array_keys( $fb ) && array_keys( array_intersect_key( $fa, $fb ) ) !== array_keys( array_intersect_key( $fb, $fa ) ) ) {
			$lines[] = 'Đổi thứ tự các ô';
		}
		return array_slice( $lines, 0, 8 );
	}

	/* ───────────────────────────── cf7.form.update ──────────────────── */

	/** @return array|WP_Error */
	public static function form_update( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$id = (int) ( $args['form_id'] ?? 0 );
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã form (form_id).', 422 );
			}
			if ( isset( $args['recipient_email'] ) || isset( $args['spec']['recipient_email'] ) || isset( $args['spec']['recipient'] ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'cf7.form.update không đổi được người nhận thư. Muốn đổi nơi nhận thư dùng cf7.form.set_recipient (có xem trước và xác nhận).', 422, array( 'help_code' => 'selfwp_use_set_recipient' ) );
			}
			$has_spec    = isset( $args['spec'] );
			$has_restore = isset( $args['restore'] ) && '' !== trim( (string) $args['restore'] );
			if ( $has_spec === $has_restore ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần đúng MỘT trong hai: spec (phần cần đổi) hoặc restore (mã bản lưu).', 422, array( 'help_code' => 'selfwp_form_args' ) );
			}
			$form = self::form_for( $id );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$meta = (array) $form['meta'];
			$old  = (array) $meta['spec'];
			if ( $has_spec ) {
				$allowed = array( 'title', 'fields', 'submit_label', 'success_message' );
				$given   = is_array( $args['spec'] ) ? $args['spec'] : array();
				$bad     = array_diff( array_map( 'strval', array_keys( $given ) ), $allowed );
				if ( array() === $given || array() !== $bad ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'spec cần ít nhất một trong: ' . implode( ', ', $allowed ) . ( $bad ? '. Không nhận: ' . implode( ', ', array_map( static function ( $k ) {
						return substr( (string) preg_replace( '/[^A-Za-z0-9_]/', '?', $k ), 0, 30 );
					}, $bad ) ) : '' ) . '.', 422, array( 'help_code' => 'selfwp_form_args' ) );
				}
				$want = array_merge( $old, array_intersect_key( $given, array_flip( $allowed ) ) );
			} else {
				$want = null;
				foreach ( self::snapshot_list( $meta ) as $n ) {
					if ( 0 === strcasecmp( $n['id'], trim( (string) $args['restore'] ) ) ) {
						$want = $n['spec'];
						break;
					}
				}
				if ( null === $want ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không có bản lưu «' . substr( (string) preg_replace( '/[^A-Za-z0-9_]/', '?', (string) $args['restore'] ), 0, 16 ) . "» của form #{$id} (hết hạn 90 ngày hoặc sai mã).", 404, array( 'help_code' => 'selfwp_snapshot_not_found' ) );
				}
			}
			$t = BizCity_SelfWP_Page_Compiler::form_template( $want ); // a saved snapshot is data too: same gate as a fresh spec
			if ( ! $t['ok'] ) {
				return self::invalid( $t['errors'] );
			}
			$new = $t['form'];
			$changes = self::diff( $old, $new );
			$mail    = (array) $form['mail'];
			if ( array() === $changes ) {
				return array( 'form_id' => $id, 'shortcode' => '[contact-form-7 id="' . $id . '"]', 'changes' => array(), 'unchanged' => true, 'recipient_masked' => BizCity_SelfWP_Action_MCP_Service::mask_email( (string) $mail['recipient'] ) );
			}
			$reply = '';
			foreach ( $new['fields'] as $fl ) {
				if ( 'email' === $fl['type'] ) {
					$reply = "Reply-To: [{$fl['name']}]";
					break;
				}
			}
			$save = array(
				'id'       => $id,
				'title'    => $new['title'],
				'form'     => $t['template'],
				'mail'     => array_merge( $mail, array( 'body' => $t['mail_body'] . self::MAIL_FOOTER, 'additional_headers' => $reply ) ), // the recipient is read back and written back untouched
				'messages' => array_merge( (array) ( $form['messages'] ?? array() ), array( 'mail_sent_ok' => $new['success_message'] ) ),
			);
			$r = self::seam( 'save_form', array( $save ), static function ( $save ) {
				if ( ! function_exists( 'wpcf7_save_contact_form' ) ) {
					return new WP_Error( 'cf7_missing', 'Contact Form 7 chưa được cài.' );
				}
				$f = wpcf7_save_contact_form( $save );
				return ( $f && method_exists( $f, 'id' ) ) ? (int) $f->id() : 0;
			} );
			if ( is_wp_error( $r ) ) {
				return BizCity_MCP_Action_Support::from_business( $r );
			}
			if ( (int) $r <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được thay đổi của form.', 500 );
			}
			$old_id = self::spec_id( $old );
			$snaps  = array_values( array_filter( self::snapshot_list( $meta ), static function ( $n ) use ( $old_id ) {
				return $n['id'] !== $old_id;
			} ) );
			array_unshift( $snaps, array( 'id' => $old_id, 'at' => gmdate( 'c' ), 'by' => $uid, 'title' => (string) ( $old['title'] ?? '' ), 'spec' => $old ) );
			$entry                 = BizCity_SelfWP_Action_MCP_Service::change_entry( 'cf7.form.update', 'R1', $uid, array(), $id, $old_id, self::spec_id( $new ), $changes, 'form' );
			$entry['command_line'] = "wp post update {$id} --post_content=<form>  # cf7 form";
			$meta['spec']          = $new;
			$meta['snapshots']     = array_slice( $snaps, 0, self::SNAP_MAX );
			self::save_meta( $id, BizCity_SelfWP_Action_MCP_Service::with_history( $meta, $entry ) );
			BizCity_SelfWP_Action_MCP_Service::audit_change( $entry );
			return array( 'form_id' => $id, 'shortcode' => '[contact-form-7 id="' . $id . '"]', 'changes' => $changes, 'unchanged' => false, 'snapshot_id' => $old_id, 'recipient_masked' => BizCity_SelfWP_Action_MCP_Service::mask_email( (string) $mail['recipient'] ) );
		} );
	}

	/* ───────────────────────────── cf7.form.set_recipient ──────────────────── */

	/** @return array|WP_Error {id, form, from, to} — nothing is written */
	private static function plan_recipient( array $args ) {
		$id = (int) ( $args['form_id'] ?? 0 );
		if ( $id <= 0 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu mã form (form_id).', 422 );
		}
		$revert = true === ( $args['revert'] ?? false );
		$typed  = trim( (string) ( $args['recipient_email'] ?? '' ) );
		if ( $revert === ( '' !== $typed ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần đúng MỘT trong hai: recipient_email (địa chỉ mới) hoặc revert=true (quay lại địa chỉ trước).', 422, array( 'help_code' => 'selfwp_recipient_args' ) );
		}
		$form = self::form_for( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$from = trim( (string) $form['mail']['recipient'] );
		if ( $revert ) {
			$prev = array_values( array_filter( (array) ( $form['meta']['recipients'] ?? array() ), 'is_array' ) );
			$last = $prev ? end( $prev ) : null;
			$to   = is_array( $last ) ? trim( (string) ( $last['email'] ?? '' ) ) : '';
			if ( '' === $to ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, "Form #{$id} chưa từng đổi người nhận nên không có địa chỉ cũ để quay lại.", 404, array( 'help_code' => 'selfwp_no_previous_recipient' ) );
			}
		} else {
			$to = $typed;
		}
		if ( ! BizCity_SelfWP_Action_MCP_Service::single_email( $to ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Email nhận thư không hợp lệ: chỉ nhận MỘT địa chỉ, dạng ten@ten-mien.vn (không dấu phẩy, không khoảng trắng, không nhiều người nhận).', 422, array( 'help_code' => 'selfwp_recipient_invalid' ) );
		}
		return array( 'id' => $id, 'form' => $form, 'from' => $from, 'to' => $to, 'revert' => $revert );
	}

	/** @return array|WP_Error */
	public static function set_recipient_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan_recipient( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( 0 === strcasecmp( $plan['from'], $plan['to'] ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Form đang gửi thư về đúng địa chỉ này rồi, không có gì để đổi.', 422, array( 'help_code' => 'selfwp_no_change' ) );
			}
			$m = BizCity_SelfWP_Action_MCP_Service::mask_email( $plan['from'] ) . ' → ' . BizCity_SelfWP_Action_MCP_Service::mask_email( $plan['to'] );
			return array(
				'form_id' => $plan['id'],
				'title'   => (string) ( $plan['form']['title'] ?? '' ),
				'changes' => array( 'Người nhận thư: ' . $m ),
				'summary' => 'Đổi nơi nhận thư của form «' . (string) ( $plan['form']['title'] ?? '' ) . '»: ' . $m . ' — từ lúc đó mọi thông tin khách điền vào form sẽ gửi về địa chỉ mới.',
			);
		} );
	}

	/** @return array|WP_Error */
	public static function set_recipient( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $ctx ) {
			$plan = self::plan_recipient( $args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$mask = static function ( $e ) {
				return BizCity_SelfWP_Action_MCP_Service::mask_email( (string) $e );
			};
			if ( 0 === strcasecmp( $plan['from'], $plan['to'] ) ) { // a retry / second tap: already there
				return array( 'status' => 'done', 'form_id' => $plan['id'], 'unchanged' => true, 'recipient_masked' => $mask( $plan['to'] ) );
			}
			$id   = $plan['id'];
			$meta = (array) $plan['form']['meta'];
			// keep the old address FIRST (full, in the form's own meta): if the write below stops half way, the way back exists
			$prev           = array_values( array_filter( (array) ( $meta['recipients'] ?? array() ), 'is_array' ) );
			$prev[]         = array( 'at' => gmdate( 'c' ), 'by' => $uid, 'email' => $plan['from'] );
			$meta['recipients'] = array_slice( $prev, -self::RECIPIENTS_KEEP );
			$line           = 'Người nhận thư: ' . $mask( $plan['from'] ) . ' → ' . $mask( $plan['to'] );
			$entry          = BizCity_SelfWP_Action_MCP_Service::change_entry( 'cf7.form.set_recipient', 'R2', $uid, $ctx, $id, 'recipient', 'recipient', array( $line ), 'form' );
			$entry['command_line'] = "wp post meta update {$id} _mail  # recipient (masked in the log)";
			self::save_meta( $id, BizCity_SelfWP_Action_MCP_Service::with_history( $meta, $entry ) );
			$r = self::seam( 'save_form', array( array( 'id' => $id, 'mail' => array_merge( (array) $plan['form']['mail'], array( 'recipient' => $plan['to'] ) ) ) ), static function ( $save ) {
				if ( ! function_exists( 'wpcf7_save_contact_form' ) ) {
					return new WP_Error( 'cf7_missing', 'Contact Form 7 chưa được cài.' );
				}
				$f = wpcf7_save_contact_form( $save );
				return ( $f && method_exists( $f, 'id' ) ) ? (int) $f->id() : 0;
			} );
			if ( is_wp_error( $r ) ) {
				return BizCity_MCP_Action_Support::from_business( $r );
			}
			if ( (int) $r <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được người nhận mới của form.', 500 );
			}
			BizCity_SelfWP_Action_MCP_Service::audit_change( $entry );
			return array( 'status' => 'done', 'form_id' => $id, 'unchanged' => false, 'recipient_masked' => $mask( $plan['to'] ), 'previous_masked' => $mask( $plan['from'] ) );
		} );
	}

	/* ───────────────────────────── helpers ──────────────────── */

	private static function save_meta( int $id, array $meta ): void {
		self::seam( 'update_meta', array( $id, self::META, $meta ), static function ( $id, $key, $value ) {
			return update_post_meta( $id, $key, $value );
		} );
	}

	/** @return array|null */
	public static function default_get_form( $id ) {
		$p = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		if ( ! $p || 'wpcf7_contact_form' !== $p->post_type ) {
			return null;
		}
		$cf   = class_exists( 'WPCF7_ContactForm' ) ? WPCF7_ContactForm::get_instance( (int) $id ) : null;
		$meta = function_exists( 'get_post_meta' ) ? get_post_meta( (int) $id, self::META, true ) : array();
		return array(
			'id'       => (int) $id,
			'title'    => (string) $p->post_title,
			'meta'     => ( is_array( $meta ) && $meta ) ? $meta : null,
			'mail'     => $cf ? (array) $cf->prop( 'mail' ) : array(),
			'messages' => $cf ? (array) $cf->prop( 'messages' ) : array(),
		);
	}

	public static function default_edit_url( $id ): string {
		return function_exists( 'admin_url' ) ? (string) admin_url( 'admin.php?page=wpcf7&post=' . (int) $id . '&action=edit' ) : '';
	}

	/** @param mixed ...$args */
	private static function can( string $cap, ...$args ): bool {
		if ( isset( self::$seams['user_can'] ) ) {
			return (bool) call_user_func( self::$seams['user_can'], $cap, ...$args );
		}
		return function_exists( 'current_user_can' ) && current_user_can( $cap, ...$args );
	}

	private static function seam( string $name, array $args, $default ) {
		$fn = self::$seams[ $name ] ?? $default;
		return call_user_func_array( $fn, $args );
	}

	private static function denied( string $cap, string $what ) {
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::PERMISSION_DENIED, "Tài khoản của bạn chưa có quyền {$what} (cần quyền WordPress \"{$cap}\").", 403, array( 'hint' => 'Nhờ quản trị viên cấp quyền hoặc dùng tài khoản có quyền này.', 'required_cap' => $cap ) );
	}

	/** @param array<int,array{path:string,code:string,message:string}> $errors */
	private static function invalid( array $errors ) {
		$msgs = array();
		foreach ( array_slice( $errors, 0, 4 ) as $e ) {
			$msgs[] = $e['path'] . ': ' . $e['message'];
		}
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Nội dung form chưa hợp lệ — ' . implode( ' · ', $msgs ), 422, array( 'errors' => array_slice( $errors, 0, 10 ), 'help_code' => 'selfwp_spec_invalid' ) );
	}
}

BizCity_SelfWP_Form_Service::init();
