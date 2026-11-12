<?php
/**
 * BizCity_BYOK — the ONE adapter for group C tools on community (self-host) sites (R-CELL-SERVICE-BOUNDARY CSB-3).
 *
 * Group C = creative "hands" (image, speech, video, music, document drafting). On the BizCity multisite network
 * they keep running through the Hub + wallet (Q-CSB-2 / D138-7) — this adapter is never used there. On every
 * other site the owner may store ONE OpenRouter key of their own; the call then runs server-side from this file
 * straight to the provider: not through the Hub, not through the cell, not in the BizCity money ledger.
 *
 * Boundary (R-GW-1 / R-GW-2 exception, 2026-10-25):
 *   - this directory is the ONLY place allowed to contain the provider domain and to read self::OPTION
 *     (grep-guard bin/validate-provider-gateway-isolation.mjs rule R-GW7, exemption = core/byok/ only);
 *   - the key never reaches wp_localize_script, REST output, logs, the Journal, Hub/cell payloads or error
 *     messages (every message from the provider is passed through self::scrub());
 *   - every HTTP call names its method explicitly (R-GW-HTTP), keeps sslverify at the WordPress default.
 *
 * Capabilities verified against the provider docs (2026-10-25):
 *   text   POST /api/v1/chat/completions   choices[0].message.content
 *   image  POST /api/v1/images             data[].b64_json + media_type
 *   tts    POST /api/v1/audio/speech       raw audio bytes (mp3 → audio/mpeg, pcm → audio/pcm)
 *   video  POST /api/v1/videos → GET /api/v1/videos/{id} → GET /api/v1/videos/{id}/content?index=0
 *   key    GET  /api/v1/key                cheap authenticated check
 *   music  NOT documented as an API → 'byok_unsupported' unless an extension supplies a provider through the
 *          filter `bizcity_byok_provider` (CSB-7: the framework stays open, it does not invent endpoints).
 *
 * Extension point: apply_filters( 'bizcity_byok_provider', null, $capability, $payload ) runs BEFORE the
 * built-in call for every capability; return an array (result, same shape as the built-in one) or a WP_Error
 * to take over, null to let the built-in call run.
 *
 * // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 S138-CL5 — new.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BYOK
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_BYOK', false ) ) {
	return;
}

final class BizCity_BYOK {

	/** Per-site option holding the owner's key (autoload false). */
	const OPTION = 'bizcity_byok_openrouter_key';

	/** Nonce action for the three AJAX actions. */
	const NONCE = 'bizcity_byok';

	/** Provider API root — the only place this domain may appear (R-GW-7 exemption). */
	const API_BASE = 'https://openrouter.ai/api/v1';

	/** Where the owner creates a key (shown in hints only, never fetched). */
	const KEYS_URL = 'https://openrouter.ai/keys';

	/** Option prefix for per-capability model ids: bizcity_byok_model_{text|image|tts|video}. */
	const MODEL_OPTION_PREFIX = 'bizcity_byok_model_';

	/** Defaults — model ids taken from the provider docs examples (2026-10-25); override by option or filter. */
	const DEFAULT_MODELS = array(
		'text'  => 'openai/gpt-4o-mini',
		'image' => 'google/gemini-2.5-flash-image',
		'tts'   => 'openai/gpt-4o-mini-tts-2025-12-15',
		'video' => 'google/veo-3.1',
	);

	/** Default TTS voice for the default model (OpenAI voices: alloy, echo, fable, onyx, nova, shimmer). */
	const DEFAULT_TTS_VOICE = 'alloy';

	/** @var bool */
	private static $booted = false;

	/* ─────────────────────────────────────────────────────────────
	 *  Boot
	 * ───────────────────────────────────────────────────────────── */

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'wp_ajax_bizcity_byok_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_bizcity_byok_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_bizcity_byok_delete', array( __CLASS__, 'ajax_delete' ) );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Key storage (the only reader of self::OPTION)
	 * ───────────────────────────────────────────────────────────── */

	private static function key(): string {
		return trim( (string) get_option( self::OPTION, '' ) );
	}

	public static function has_key(): bool {
		return '' !== self::key();
	}

	/** Masked form for the settings UI, e.g. 'sk-or-…ab12'; '' when no key is stored. */
	public static function masked(): string {
		return self::mask( self::key() );
	}

	/** Pure: mask any key string (never returns more than the last 4 characters). */
	public static function mask( string $key ): string {
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}
		$tail = strlen( $key ) >= 12 ? substr( $key, -4 ) : '';
		return 'sk-or-…' . $tail;
	}

	/** Pure: basic format check — provider keys start with 'sk-or-' (e.g. 'sk-or-v1-…'). */
	public static function is_valid_format( string $key ): bool {
		return 1 === preg_match( '/^sk-or-[A-Za-z0-9_-]{8,256}$/', trim( $key ) );
	}

	private static function can_manage(): bool {
		if ( class_exists( 'BizCity_Network_Admin_Capability' ) && method_exists( 'BizCity_Network_Admin_Capability', 'can_manage' ) ) {
			return BizCity_Network_Admin_Capability::can_manage();
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Store the owner's key (trimmed, format-checked, autoload false).
	 *
	 * @return true|WP_Error
	 */
	public static function save_key( string $key ) {
		if ( ! self::can_manage() ) {
			return new WP_Error( 'byok_forbidden', 'Bạn không có quyền quản trị site này.', array( 'status' => 403 ) );
		}
		if ( self::is_hosted() ) {
			return new WP_Error(
				'byok_hosted',
				'Site trên mạng BizCity dùng ví BizCity cho vẽ ảnh, giọng nói, video — không cần khoá OpenRouter.',
				array( 'status' => 409, 'hint' => 'Nạp ví tại bizcity.vn nếu hết hạn mức.' )
			);
		}
		$key = trim( $key );
		if ( '' === $key ) {
			return new WP_Error( 'byok_empty', 'Chưa nhập khoá.', array( 'status' => 400 ) );
		}
		if ( ! self::is_valid_format( $key ) ) {
			return new WP_Error(
				'byok_bad_format',
				'Khoá không đúng định dạng (phải bắt đầu bằng sk-or-).',
				array( 'status' => 400, 'hint' => 'Tạo khoá tại ' . self::KEYS_URL . ' rồi dán nguyên chuỗi.' )
			);
		}
		update_option( self::OPTION, $key, false );
		return true;
	}

	public static function delete_key(): void {
		delete_option( self::OPTION );
	}

	/** Group C may use the adapter on this site: not on the BizCity network, and a key is stored. */
	public static function available(): bool {
		return ! self::is_hosted() && self::has_key();
	}

	private static function is_hosted(): bool {
		return function_exists( 'bizcity_is_hosted_site' ) && bizcity_is_hosted_site();
	}

	/**
	 * Cheap authenticated check (GET /key). Never echoes the key or its label.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function test(): array {
		if ( ! self::has_key() ) {
			return array( 'ok' => false, 'message' => 'Chưa cài khoá OpenRouter.' );
		}
		$res = self::http( 'GET', '/key', null, 15 );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => $res->get_error_message() );
		}
		if ( 200 !== $res['code'] ) {
			if ( 401 === $res['code'] || 403 === $res['code'] ) {
				return array( 'ok' => false, 'message' => 'Khoá không hợp lệ hoặc đã bị thu hồi (HTTP ' . $res['code'] . ').' );
			}
			return array( 'ok' => false, 'message' => self::error_from( $res, 'key' )->get_error_message() );
		}
		$data = is_array( $res['json']['data'] ?? null ) ? $res['json']['data'] : array();
		$msg  = 'Khoá hợp lệ.';
		if ( isset( $data['limit_remaining'] ) && is_numeric( $data['limit_remaining'] ) ) {
			$msg .= ' Hạn mức còn lại của khoá: ' . round( (float) $data['limit_remaining'], 2 ) . ' credit.';
		}
		if ( ! empty( $data['is_free_tier'] ) ) {
			$msg .= ' Tài khoản chưa nạp credit — các model trả phí (ảnh, giọng nói, video) sẽ báo lỗi 402.';
		}
		return array( 'ok' => true, 'message' => $msg );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Errors (one shape, R-ERROR-UX: code + message + status + hint)
	 * ───────────────────────────────────────────────────────────── */

	public static function missing_error(): WP_Error {
		return new WP_Error(
			'byok_missing',
			'Chưa cài khoá OpenRouter — vào ⚡ LLM Settings để thêm khoá của bạn.',
			array(
				'status' => 402,
				'hint'   => 'Tạo khoá tại ' . self::KEYS_URL . ', dán vào ⚡ LLM Settings → Khoá OpenRouter (BYOK), bấm Test.',
			)
		);
	}

	public static function unsupported_error( string $capability ): WP_Error {
		$labels = array(
			'music'      => 'tạo nhạc',
			'image_task' => 'tác vụ ảnh nâng cao (ghép mặt, thử đồ)',
		);
		$label = $labels[ $capability ] ?? $capability;
		return new WP_Error(
			'byok_unsupported',
			'OpenRouter chưa có API ' . $label . ' được tài liệu hoá — tính năng này chưa chạy được bằng khoá của bạn.',
			array(
				'status'     => 501,
				'capability' => $capability,
				'hint'       => 'Cài một plugin mở rộng có cung cấp nhà cung cấp riêng (filter bizcity_byok_provider), hoặc dùng site tạo qua trang đăng ký BizCity.',
			)
		);
	}

	/** Remove anything that looks like a provider key from a message before it leaves this file. */
	public static function scrub( string $text ): string {
		return (string) preg_replace( '/sk-or-[A-Za-z0-9_-]+/', 'sk-or-…', $text );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Models + provider extension point
	 * ───────────────────────────────────────────────────────────── */

	/** Model id for a capability: option bizcity_byok_model_{cap} → default; filter 'bizcity_byok_model'. */
	public static function model( string $capability ): string {
		$default = self::DEFAULT_MODELS[ $capability ] ?? '';
		$opt     = trim( (string) get_option( self::MODEL_OPTION_PREFIX . sanitize_key( $capability ), '' ) );
		$model   = '' !== $opt ? $opt : $default;
		return (string) apply_filters( 'bizcity_byok_model', $model, $capability );
	}

	/**
	 * Pure-ish: a caller-supplied model wins only when it looks like a provider slug — exactly one "vendor/model"
	 * (optional ":variant"). Hub aliases ('gpt-image-1') and PiAPI paths ('kling/v1-5/i2v-pro') fall back to ours.
	 */
	public static function pick_model( string $capability, $requested ): string {
		return self::is_provider_slug( (string) $requested ) ? trim( (string) $requested ) : self::model( $capability );
	}

	/** Pure: 'vendor/model' or 'vendor/model:variant'. */
	public static function is_provider_slug( string $model ): bool {
		return 1 === preg_match( '#^[a-z0-9][a-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*(?::[A-Za-z0-9._-]+)?$#', trim( $model ) );
	}

	/**
	 * Extension providers first (CSB-7). Returns null when no extension handled the capability.
	 *
	 * @return array|WP_Error|null
	 */
	private static function provider( string $capability, array $payload ) {
		$out = apply_filters( 'bizcity_byok_provider', null, $capability, $payload );
		return ( is_array( $out ) || is_wp_error( $out ) ) ? $out : null;
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Capabilities
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Text chat completion (document drafting and other group C text work — NOT the assistant, which thinks at the cell).
	 *
	 * @param array $messages OpenAI-format messages.
	 * @param array $opts     { model?, temperature?, max_tokens?, timeout? }
	 * @return array|WP_Error { text, model, usage, provider }
	 */
	public static function chat( array $messages, array $opts = array() ) {
		$payload = array( 'messages' => $messages, 'opts' => $opts );
		$ext     = self::provider( 'text', $payload );
		if ( null !== $ext ) {
			return $ext;
		}
		$body = array(
			'model'    => self::pick_model( 'text', $opts['model'] ?? '' ),
			'messages' => array_values( $messages ),
		);
		if ( isset( $opts['temperature'] ) ) {
			$body['temperature'] = (float) $opts['temperature'];
		}
		if ( ! empty( $opts['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $opts['max_tokens'];
		}
		$res = self::http( 'POST', '/chat/completions', $body, max( 10, (int) ( $opts['timeout'] ?? 90 ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ! is_array( $res['json'] ) ) {
			return self::error_from( $res, 'text' );
		}
		$text = $res['json']['choices'][0]['message']['content'] ?? '';
		if ( is_array( $text ) ) { // content parts
			$text = implode( '', array_map( static function ( $p ) {
				return is_array( $p ) ? (string) ( $p['text'] ?? '' ) : (string) $p;
			}, $text ) );
		}
		return array(
			'text'     => (string) $text,
			'model'    => (string) ( $res['json']['model'] ?? $body['model'] ),
			'usage'    => is_array( $res['json']['usage'] ?? null ) ? $res['json']['usage'] : array(),
			'provider' => 'openrouter_byok',
		);
	}

	/**
	 * Image generation (POST /images). Returns raw base64 like the Hub path's `b64_json`.
	 *
	 * @param array $opts { model?, size?, aspect_ratio?, n?, input_images?: string[], timeout? }
	 * @return array|WP_Error { b64_json, media_type, images: [{b64_json, media_type}], model, cost, provider }
	 */
	public static function generate_image( string $prompt, array $opts = array() ) {
		$ext = self::provider( 'image', array( 'prompt' => $prompt, 'opts' => $opts ) );
		if ( null !== $ext ) {
			return $ext;
		}
		$body = array(
			'model'  => self::pick_model( 'image', $opts['model'] ?? '' ),
			'prompt' => $prompt,
			'n'      => max( 1, min( 10, (int) ( $opts['n'] ?? 1 ) ) ),
		);
		if ( ! empty( $opts['aspect_ratio'] ) ) {
			$body['aspect_ratio'] = (string) $opts['aspect_ratio'];
		} elseif ( ! empty( $opts['size'] ) && preg_match( '/^\d{2,5}x\d{2,5}$/', (string) $opts['size'] ) ) {
			$body['size'] = (string) $opts['size'];
		}
		if ( ! empty( $opts['input_images'] ) && is_array( $opts['input_images'] ) ) {
			$refs = array();
			foreach ( array_slice( array_values( array_filter( $opts['input_images'], 'is_string' ) ), 0, 16 ) as $url ) {
				$refs[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => $url ) );
			}
			if ( $refs ) {
				$body['input_references'] = $refs;
			}
		}
		$res = self::http( 'POST', '/images', $body, max( 30, (int) ( $opts['timeout'] ?? 120 ) ) + 10 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ! is_array( $res['json'] ) ) {
			return self::error_from( $res, 'image' );
		}
		$images = array();
		foreach ( (array) ( $res['json']['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['b64_json'] ) ) {
				$images[] = array(
					'b64_json'   => (string) $row['b64_json'],
					'media_type' => (string) ( $row['media_type'] ?? 'image/png' ),
				);
			}
		}
		if ( ! $images ) {
			return new WP_Error( 'byok_empty_result', 'OpenRouter không trả về ảnh nào.', array( 'status' => 502, 'hint' => 'Thử lại hoặc đổi model ảnh trong ⚡ LLM Settings.' ) );
		}
		return array(
			'b64_json'   => $images[0]['b64_json'],
			'media_type' => $images[0]['media_type'],
			'images'     => $images,
			'model'      => $body['model'],
			'cost'       => isset( $res['json']['usage']['cost'] ) ? (float) $res['json']['usage']['cost'] : null,
			'provider'   => 'openrouter_byok',
		);
	}

	/**
	 * Text → speech (POST /audio/speech). Same result shape as Hub /tools/tts.
	 *
	 * @param array $opts { model?, voice?, format?: 'mp3'|'pcm', instructions?, timeout? }
	 * @return array|WP_Error { success, audio_b64, mime_type, ext, bytes, provider, model, chars, latency_ms }
	 */
	public static function text_to_speech( string $text, array $opts = array() ) {
		$ext = self::provider( 'tts', array( 'text' => $text, 'opts' => $opts ) );
		if ( null !== $ext ) {
			return $ext;
		}
		$model  = self::pick_model( 'tts', $opts['model'] ?? '' );
		$format = ( 'pcm' === strtolower( (string) ( $opts['format'] ?? '' ) ) ) ? 'pcm' : 'mp3';
		$voice  = trim( (string) ( $opts['voice'] ?? '' ) );
		if ( '' === $voice && 0 === strpos( $model, 'openai/' ) ) {
			$voice = self::DEFAULT_TTS_VOICE;
		}
		$body = array(
			'model'           => $model,
			'input'           => $text,
			'response_format' => $format,
		);
		if ( '' !== $voice ) {
			$body['voice'] = $voice;
		}
		if ( ! empty( $opts['instructions'] ) ) {
			$body['instructions'] = (string) $opts['instructions'];
		}
		$t0  = microtime( true );
		$res = self::http( 'POST', '/audio/speech', $body, max( 10, (int) ( $opts['timeout'] ?? 100 ) ), array( 'Accept' => 'audio/*' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$ctype = strtolower( (string) $res['content_type'] );
		if ( 200 !== $res['code'] || 0 !== strpos( $ctype, 'audio/' ) || '' === $res['body'] ) {
			return self::error_from( $res, 'tts' );
		}
		$mime = 'pcm' === $format ? 'audio/pcm' : 'audio/mpeg';
		return array(
			'success'    => true,
			'audio_b64'  => base64_encode( $res['body'] ),
			'mime_type'  => $mime,
			'ext'        => 'pcm' === $format ? 'pcm' : 'mp3',
			'bytes'      => strlen( $res['body'] ),
			'provider'   => 'openrouter_byok',
			'model'      => $model,
			'chars'      => function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ),
			'latency_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
		);
	}

	/**
	 * Music — no documented provider API: extension provider or 'byok_unsupported'.
	 *
	 * @return array|WP_Error Hub /tools/music shape when an extension handles it.
	 */
	public static function generate_music( string $prompt, array $opts = array() ) {
		$ext = self::provider( 'music', array( 'prompt' => $prompt, 'opts' => $opts ) );
		return null !== $ext ? $ext : self::unsupported_error( 'music' );
	}

	/**
	 * PiAPI-style image tasks (face swap, virtual try-on) — no documented provider API.
	 *
	 * @param array $payload { action: 'submit'|'poll', operation?, input?, options?, task_id? }
	 * @return array|WP_Error
	 */
	public static function image_task( array $payload ) {
		$ext = self::provider( 'image_task', $payload );
		return null !== $ext ? $ext : self::unsupported_error( 'image_task' );
	}

	/**
	 * Submit a video job (POST /videos, async — 202 + id).
	 *
	 * @param array $opts { model?, duration?, aspect_ratio?, resolution?, image_url?, with_audio? }
	 * @return array|WP_Error { task_id, status, eta_sec, provider, model }
	 */
	public static function video_submit( string $prompt, array $opts = array() ) {
		$ext = self::provider( 'video', array( 'prompt' => $prompt, 'opts' => $opts ) );
		if ( null !== $ext ) {
			return $ext;
		}
		$body = array(
			'model'  => self::pick_model( 'video', $opts['model'] ?? '' ),
			'prompt' => $prompt,
		);
		if ( ! empty( $opts['duration'] ) ) {
			$body['duration'] = (int) $opts['duration'];
		}
		if ( ! empty( $opts['aspect_ratio'] ) ) {
			$body['aspect_ratio'] = (string) $opts['aspect_ratio'];
		}
		if ( ! empty( $opts['resolution'] ) ) {
			$body['resolution'] = (string) $opts['resolution'];
		}
		if ( array_key_exists( 'with_audio', $opts ) ) {
			$body['generate_audio'] = ! empty( $opts['with_audio'] );
		}
		$image_url = trim( (string) ( $opts['image_url'] ?? '' ) );
		if ( '' !== $image_url ) {
			$body['frame_images'] = array(
				array(
					'type'       => 'image_url',
					'image_url'  => array( 'url' => $image_url ),
					'frame_type' => 'first_frame',
				),
			);
		}
		$res = self::http( 'POST', '/videos', $body, 30 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = is_array( $res['json'] ) ? (string) ( $res['json']['id'] ?? '' ) : '';
		if ( ( 200 !== $res['code'] && 202 !== $res['code'] ) || '' === $id ) {
			return self::error_from( $res, 'video' );
		}
		return array(
			'task_id'  => $id,
			'status'   => self::map_video_status( (string) ( $res['json']['status'] ?? 'pending' ) ),
			'eta_sec'  => 120,
			'provider' => 'openrouter_byok',
			'model'    => $body['model'],
		);
	}

	/**
	 * Poll a video job; on completion download the file into the Media Library once and return its URL
	 * (the provider's content URL needs the key, its signed URLs are secrets — neither leaves this file).
	 *
	 * @return array|WP_Error { status, progress, result_url, video_url, thumbnail_url, error, provider }
	 */
	public static function video_status( string $task_id ) {
		$ext = self::provider( 'video_status', array( 'task_id' => $task_id ) );
		if ( null !== $ext ) {
			return $ext;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $task_id ) ) {
			return new WP_Error( 'invalid_param', 'Mã tác vụ video không hợp lệ.', array( 'status' => 400 ) );
		}
		$cache_key = 'bizcity_byok_vid_' . md5( $task_id );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return self::video_row( 'completed', 100, $cached, '' );
		}
		$res = self::http( 'GET', '/videos/' . rawurlencode( $task_id ), null, 20 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || ! is_array( $res['json'] ) ) {
			return self::error_from( $res, 'video' );
		}
		$status = self::map_video_status( (string) ( $res['json']['status'] ?? '' ) );
		if ( 'failed' === $status ) {
			return self::video_row( 'failed', 0, '', self::scrub( (string) ( $res['json']['error'] ?? 'Tạo video thất bại.' ) ) );
		}
		if ( 'completed' !== $status ) {
			return self::video_row( $status, 'processing' === $status ? 50 : 5, '', '' );
		}
		$url = self::download_video( $task_id );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		set_transient( $cache_key, $url, 7 * DAY_IN_SECONDS );
		return self::video_row( 'completed', 100, $url, '' );
	}

	/** Pure: provider status → the client vocabulary (pending | processing | completed | failed). */
	public static function map_video_status( string $status ): string {
		switch ( strtolower( $status ) ) {
			case 'completed':
				return 'completed';
			case 'in_progress':
				return 'processing';
			case 'failed':
			case 'cancelled':
			case 'expired':
				return 'failed';
			default:
				return 'pending';
		}
	}

	private static function video_row( string $status, int $progress, string $url, string $error ): array {
		return array(
			'status'        => $status,
			'progress'      => $progress,
			'result_url'    => $url,
			'video_url'     => $url,
			'thumbnail_url' => '',
			'error'         => $error,
			'provider'      => 'openrouter_byok',
		);
	}

	/** @return string|WP_Error local Media URL */
	private static function download_video( string $task_id ) {
		$lock = 'bizcity_byok_vid_lock_' . md5( $task_id );
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'byok_video_downloading', 'Video đang được tải về site, thử lại sau ít giây.', array( 'status' => 409, 'hint' => 'Hỏi lại trạng thái sau 10–20 giây.' ) );
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			delete_transient( $lock );
			return new WP_Error( 'byok_upload_dir', 'Không ghi được thư mục uploads của site.', array( 'status' => 500, 'hint' => 'Kiểm tra quyền ghi wp-content/uploads.' ) );
		}
		$file = wp_unique_filename( $uploads['path'], 'video-' . sanitize_file_name( $task_id ) . '.mp4' );
		$path = trailingslashit( $uploads['path'] ) . $file;

		$res = self::http( 'GET', '/videos/' . rawurlencode( $task_id ) . '/content?index=0', null, 300, array(), array( 'stream' => true, 'filename' => $path ) );
		if ( ! is_wp_error( $res ) && $res['code'] >= 300 && $res['code'] < 400 && '' !== $res['location'] ) {
			// Redirect to a pre-signed storage URL: follow WITHOUT the Authorization header (the key never leaves for another host).
			$redir = wp_remote_get( $res['location'], array( 'method' => 'GET', 'timeout' => 300, 'stream' => true, 'filename' => $path ) );
			$res   = is_wp_error( $redir )
				? new WP_Error( 'byok_unreachable', 'Không tải được video: ' . self::scrub( $redir->get_error_message() ), array( 'status' => 502 ) )
				: array( 'code' => (int) wp_remote_retrieve_response_code( $redir ), 'content_type' => (string) wp_remote_retrieve_header( $redir, 'content-type' ) );
		}
		delete_transient( $lock );
		if ( is_wp_error( $res ) || 200 !== (int) $res['code'] || ! is_file( $path ) || filesize( $path ) < 1024 ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			return is_wp_error( $res ) ? $res : new WP_Error( 'byok_video_download', 'Không tải được video từ OpenRouter (HTTP ' . (int) $res['code'] . ').', array( 'status' => 502, 'hint' => 'Hỏi lại trạng thái sau ít phút.' ) );
		}
		$url = trailingslashit( $uploads['url'] ) . $file;
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Video ' . $task_id,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => $url,
			),
			$path
		);
		if ( $att && ! is_wp_error( $att ) ) {
			$att_url = wp_get_attachment_url( (int) $att );
			if ( is_string( $att_url ) && '' !== $att_url ) {
				$url = $att_url;
			}
		}
		return $url;
	}

	/* ─────────────────────────────────────────────────────────────
	 *  HTTP (explicit method, R-GW-HTTP) + error mapping
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * @param array|null $body JSON body (POST) or null.
	 * @param array      $headers Extra headers.
	 * @param array      $args    Extra wp_remote_* args (e.g. stream/filename).
	 * @return array{code:int,body:string,json:mixed,content_type:string,location:string}|WP_Error
	 */
	private static function http( string $method, string $path, $body, int $timeout, array $headers = array(), array $args = array() ) {
		$key = self::key();
		if ( '' === $key ) {
			return self::missing_error();
		}
		$req = array_merge(
			array(
				'method'      => $method,
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array_merge(
					array(
						'Authorization' => 'Bearer ' . $key,
						'Accept'        => 'application/json',
					),
					$headers
				),
			),
			$args
		);
		if ( null !== $body ) {
			$req['headers']['Content-Type'] = 'application/json';
			$req['body']                    = wp_json_encode( $body );
		}
		$url = self::API_BASE . $path;
		$res = ( 'POST' === $method ) ? wp_remote_post( $url, $req ) : wp_remote_get( $url, $req );
		if ( is_wp_error( $res ) ) {
			return new WP_Error(
				'byok_unreachable',
				'Không kết nối được OpenRouter: ' . self::scrub( $res->get_error_message() ),
				array( 'status' => 502, 'hint' => 'Kiểm tra máy chủ ra được internet (https) rồi thử lại.' )
			);
		}
		$raw = empty( $args['stream'] ) ? (string) wp_remote_retrieve_body( $res ) : '';
		$ct  = (string) wp_remote_retrieve_header( $res, 'content-type' );
		return array(
			'code'         => (int) wp_remote_retrieve_response_code( $res ),
			'body'         => $raw,
			'json'         => ( '' !== $raw && ( false !== stripos( $ct, 'json' ) || '{' === substr( ltrim( $raw ), 0, 1 ) ) ) ? json_decode( $raw, true ) : null,
			'content_type' => $ct,
			'location'     => (string) wp_remote_retrieve_header( $res, 'location' ),
		);
	}

	/** Provider error body { error: { code, message } } → WP_Error (message scrubbed, never the key). */
	private static function error_from( array $res, string $capability ): WP_Error {
		$code = (int) $res['code'];
		$json = is_array( $res['json'] ) ? $res['json'] : json_decode( (string) $res['body'], true );
		$msg  = '';
		if ( is_array( $json ) ) {
			$err = $json['error'] ?? null;
			$msg = is_array( $err ) ? (string) ( $err['message'] ?? '' ) : (string) $err;
		}
		$msg = self::scrub( substr( trim( wp_strip_all_tags( $msg ) ), 0, 300 ) );
		if ( 401 === $code || 403 === $code ) {
			$hint = 'Khoá OpenRouter không hợp lệ hoặc đã bị thu hồi — kiểm tra lại trong ⚡ LLM Settings.';
		} elseif ( 402 === $code ) {
			$hint = 'Tài khoản OpenRouter hết credit — nạp thêm credit trong tài khoản OpenRouter của bạn.';
		} elseif ( 429 === $code ) {
			$hint = 'Đang bị giới hạn tần suất — đợi một lúc rồi thử lại.';
		} elseif ( 400 === $code || 404 === $code ) {
			$hint = 'Model hoặc tham số không được hỗ trợ — đổi model trong ⚡ LLM Settings.';
		} else {
			$hint = 'Thử lại sau vài phút.';
		}
		return new WP_Error(
			'byok_http_' . $code,
			'OpenRouter trả lỗi HTTP ' . $code . ( '' !== $msg ? ': ' . $msg : '.' ),
			array( 'status' => $code >= 400 && $code < 600 ? $code : 502, 'capability' => $capability, 'hint' => $hint )
		);
	}

	/* ─────────────────────────────────────────────────────────────
	 *  AJAX (wp_ajax_ only — logged-in admins; never returns the key)
	 * ───────────────────────────────────────────────────────────── */

	private static function ajax_guard(): void {
		$nonce = '';
		foreach ( array( 'nonce', '_ajax_nonce', '_wpnonce' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just below.
				$nonce = sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				break;
			}
		}
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_send_json_error( array( 'message' => 'Phiên làm việc hết hạn — tải lại trang rồi thử lại.', 'masked' => self::masked() ), 403 );
		}
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => 'Bạn không có quyền quản trị site này.', 'masked' => self::masked() ), 403 );
		}
	}

	public static function ajax_save(): void {
		self::ajax_guard();
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ajax_guard().
		$ok  = self::save_key( $key );
		unset( $key );
		if ( is_wp_error( $ok ) ) {
			$data = $ok->get_error_data();
			wp_send_json_error(
				array(
					'message' => $ok->get_error_message(),
					'code'    => $ok->get_error_code(),
					'hint'    => is_array( $data ) ? (string) ( $data['hint'] ?? '' ) : '',
					'masked'  => self::masked(),
				),
				is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400
			);
		}
		wp_send_json_success( array( 'message' => 'Đã lưu khoá OpenRouter.', 'masked' => self::masked() ) );
	}

	public static function ajax_test(): void {
		self::ajax_guard();
		$r = self::test();
		if ( $r['ok'] ) {
			wp_send_json_success( array( 'message' => $r['message'], 'masked' => self::masked(), 'ok' => true ) );
		}
		wp_send_json_error( array( 'message' => $r['message'], 'masked' => self::masked(), 'ok' => false ), 200 );
	}

	public static function ajax_delete(): void {
		self::ajax_guard();
		self::delete_key();
		wp_send_json_success( array( 'message' => 'Đã xoá khoá OpenRouter.', 'masked' => '' ) );
	}
}
