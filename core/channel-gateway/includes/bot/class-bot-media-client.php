<?php
/**
 * Bot Studio — real outbound calls for the TTS/STT/tạo nhạc "Test" buttons and the live-turn TTS / music jobs
 * (PHASE-0.60E D-E1, EB-x.4 "Nút Test gọi đúng đường bot dùng, không mock").
 *
 * [2026-10-14 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-X2 R-ONE-VERTICAL-CELL — every provider call here now goes
 * core → Hub gateway → cell → provider through BizCity_LLM_Client (text_to_speech / generate_music / transcribe_audio) and
 * BizCity_Search_Client. This class holds NO provider URL and NO per-character provider key. The character's voice / model /
 * format are passed as parameters; the stored per-character key fields (tts_api_keys, stt_api_key, music_api_key, tavily_api_key)
 * are no longer read (data is kept, never deleted). There is no mock/fake-success path: a Hub error is returned as a WP_Error.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60E (2026-09-23)
 */

// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 (user-approved).
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Media_Client {

	/**
	 * @param int    $character_id
	 * @param string $kind   'tts' | 'stt' | 'music' | 'apify' | 'video' | 'image' | 'tavily'
	 * @param array  $body   REST body — { text? } for tts, { } for stt (file comes from $_FILES via
	 *                       the caller's WP_REST_Request, passed through as 'file_path'), { prompt?, confirm_cost? } for
	 *                       music, video and image, { platform, url } for apify, { query? } for tavily.
	 * @return array|WP_Error
	 */
	public static function test( int $character_id, string $kind, array $body ) {
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bot Studio is not ready.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$media = BizCity_Bot_Config_Repo::get( $character_id )['media'];
		switch ( $kind ) {
			case 'tts':
				return self::test_tts( $media['tts'], $body );
			case 'stt':
				return self::test_stt( $media['stt'], $body );
			case 'music':
				return self::test_music( $media['music'], $body );
			case 'apify':
				return self::test_apify( $character_id, $body );
			case 'video':
				return self::test_video( $media['video'], $body );
			case 'image':
				return self::test_image( $media['image'], $body );
			case 'tavily':
				return self::test_tavily( $body );
			default:
				return new WP_Error( 'invalid_param', 'Invalid test type.', array( 'status' => 422, 'help_code' => 'bot_media_test_kind' ) );
		}
	}

	/**
	 * The one Hub-backed client, or a WP_Error when bizcity-llm is not loaded / has no key.
	 *
	 * @return BizCity_LLM_Client|WP_Error
	 */
	private static function hub() {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_Error( 'module_not_loaded', 'LLM client (bizcity-llm) is not loaded.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return new WP_Error( 'bot_provider_key_missing', 'No BizCity 1API key. Set it in BizCity LLM settings (site level, not per Guru).', array( 'status' => 422, 'help_code' => 'bot_media_hub_key_missing' ) );
		}
		return $client;
	}

	/** Keep the Hub error code/message, make sure the REST envelope has a help_code. */
	private static function hub_error( WP_Error $e, string $help_code ): WP_Error {
		$data = $e->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$data['status']    = $data['status'] ?? 502;
		$data['help_code'] = $data['help_code'] ?? $help_code;
		unset( $data['hub'] );
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), $data );
	}

	/* ── TTS — Hub POST /tools/tts (the cell picks the provider; voice / model / format are parameters) ── */

	private static function test_tts( array $cfg, array $body ) {
		$text = trim( (string) ( $body['text'] ?? '' ) );
		$text = '' !== $text ? mb_substr( $text, 0, 400 ) : 'Xin chào, đây là một tin nhắn thử giọng nói.';
		return self::run_tts( $cfg, $text );
	}

	/**
	 * The Hub call shared by the manual Test button and the turn-time synthesize().
	 *
	 * @return array{ok:true,audio_base64:string,mime_type:string,bytes:int}|WP_Error
	 */
	private static function run_tts( array $cfg, string $text ) {
		$client = self::hub();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$result = $client->text_to_speech( $text, array(
			'voice'  => trim( (string) ( $cfg['voice'] ?? '' ) ),
			'format' => trim( (string) ( $cfg['format'] ?? '' ) ),
			'model'  => trim( (string) ( $cfg['model'] ?? '' ) ),
			'caller' => 'bot_tts',
		) );
		if ( is_wp_error( $result ) ) {
			return self::hub_error( $result, 'bot_media_tts_failed' );
		}
		$b64 = (string) ( $result['audio_b64'] ?? '' );
		if ( '' === $b64 ) {
			return new WP_Error( 'provider_error', 'The TTS provider returned empty audio.', array( 'status' => 502, 'help_code' => 'bot_media_tts_empty_audio' ) );
		}
		return array(
			'ok'           => true,
			'audio_base64' => $b64,
			'mime_type'    => (string) ( $result['mime_type'] ?? 'audio/mpeg' ),
			'bytes'        => (int) ( $result['bytes'] ?? (int) ( strlen( $b64 ) * 3 / 4 ) ),
		);
	}

	/* ── TTS for a live bot turn (PHASE-0.60H D-H3, option A) ─────────────────────────────────────────────────── */

	/** Longest text a turn may speak. Zalo voice/audio should stay a short companion to the text reply. */
	const TTS_TURN_MAX_CHARS = 400;

	/**
	 * Pure: why this TTS configuration cannot be SENT to a Zalo customer ('' = it can).
	 *
	 * The CRM outbound path can only carry a real, playable container, so v1 accepts only MP3 (empty format = mp3).
	 * The provider field no longer matters: the cell decides it (R-ONE-VERTICAL-CELL).
	 */
	public static function tts_turn_support( array $cfg ): string {
		$format = strtolower( trim( (string) ( $cfg['format'] ?? '' ) ) );
		return ( '' === $format || 0 === strpos( $format, 'mp3' ) ) ? '' : 'Định dạng phải là mp3 để gửi qua Zalo.';
	}

	/**
	 * Speak `$text` with the character's TTS config for a live turn.
	 *
	 * @return array{ok:true,binary:string,mime_type:string,bytes:int}|WP_Error
	 */
	public static function synthesize( int $character_id, string $text ) {
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bot Studio is not ready.', array( 'status' => 503 ) );
		}
		$text = trim( $text );
		if ( '' === $text ) {
			return new WP_Error( 'invalid_param', 'There is no text to read aloud.', array( 'status' => 422 ) );
		}
		$cfg    = BizCity_Bot_Config_Repo::get( $character_id )['media']['tts'];
		$reason = self::tts_turn_support( $cfg );
		if ( '' !== $reason ) {
			return new WP_Error( 'tts_format_unsupported', $reason, array( 'status' => 422 ) );
		}
		$cfg['format'] = 'mp3';
		$result = self::run_tts( $cfg, mb_substr( $text, 0, self::TTS_TURN_MAX_CHARS ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$binary = base64_decode( (string) ( $result['audio_base64'] ?? '' ), true );
		if ( ! is_string( $binary ) || '' === $binary ) {
			return new WP_Error( 'provider_error', 'The TTS provider returned empty audio.', array( 'status' => 502 ) );
		}
		return array( 'ok' => true, 'binary' => $binary, 'mime_type' => 'audio/mpeg', 'bytes' => strlen( $binary ) );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H3 — let the CRM outbound owner carry the MP3 a bot turn produced.
	 * `bizcity_crm_outbound_allowed_mimes` is the dispatcher's own extension point (class-outbound-dispatcher.php
	 * allowed_mimes()). Only `audio/mpeg` is added; the ownership rule still decides who may send it.
	 */
	public static function init(): void {
		add_filter( 'bizcity_crm_outbound_allowed_mimes', array( __CLASS__, 'allow_audio_mime' ) );
		add_filter( 'bizcity_crm_outbound_allowed_mimes', array( __CLASS__, 'allow_bot_file_mimes' ) );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K0-4 — MIME types of the files a bot turn can now produce: music (wav/m4a),
	 * video (mp4) and generated documents (docx/xlsx/pptx/markdown). One place, so the media/document features do not
	 * each patch the dispatcher. The filter is site-wide, so staff can also attach these from the CRM composer — the
	 * ownership rule (`post_author === owner`) and the size ceiling still apply to every one of them.
	 * `text/html` is deliberately NOT here: a generated HTML file can carry script, and it is added together with the
	 * sanitiser that makes it safe (PHASE-0.60K K4).
	 */
	const BOT_FILE_MIMES = array(
		'audio/wav',
		'audio/mp4',
		'video/mp4',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'text/markdown',
	);

	public static function allow_bot_file_mimes( $mimes ) {
		$mimes = is_array( $mimes ) ? $mimes : array();
		foreach ( self::BOT_FILE_MIMES as $mime ) {
			if ( ! in_array( $mime, $mimes, true ) ) {
				$mimes[] = $mime;
			}
		}
		return $mimes;
	}

	public static function allow_audio_mime( $mimes ) {
		$mimes = is_array( $mimes ) ? $mimes : array();
		if ( ! in_array( 'audio/mpeg', $mimes, true ) ) {
			$mimes[] = 'audio/mpeg';
		}
		return $mimes;
	}

	/* ── STT — Hub POST /tools/transcribe (the Hub fetches a public URL, so the test upload is staged in uploads/ for the call) ── */

	private static function test_stt( array $cfg, array $body ) {
		if ( empty( $cfg['enabled'] ) ) {
			return new WP_Error( 'invalid_param', 'STT is turned off for this assistant.', array( 'status' => 422, 'help_code' => 'bot_media_stt_disabled' ) );
		}
		$file_path = (string) ( $body['file_path'] ?? '' );
		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return new WP_Error( 'invalid_param', 'The test recording is missing.', array( 'status' => 422, 'help_code' => 'bot_media_stt_file_missing' ) );
		}
		$client = self::hub();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return new WP_Error( 'module_not_loaded', 'WordPress uploads are not ready.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'provider_error', 'The uploads folder is not writable.', array( 'status' => 500, 'help_code' => 'bot_media_stt_stage_failed' ) );
		}
		$name = (string) ( $body['file_name'] ?? '' );
		$ext  = strtolower( pathinfo( '' !== $name ? $name : $file_path, PATHINFO_EXTENSION ) );
		$ext  = preg_match( '/^[a-z0-9]{2,5}$/', $ext ) ? $ext : 'mp3';
		$mime = (string) ( wp_check_filetype( 'x.' . $ext )['type'] ?? '' );
		$dest = trailingslashit( $uploads['path'] ) . 'bot-stt-test-' . wp_generate_password( 12, false ) . '.' . $ext;
		if ( ! @copy( $file_path, $dest ) ) {
			return new WP_Error( 'provider_error', 'The test recording could not be staged.', array( 'status' => 500, 'help_code' => 'bot_media_stt_stage_failed' ) );
		}
		try {
			$result = $client->transcribe_audio( trailingslashit( $uploads['url'] ) . basename( $dest ), array(
				'mime'   => $mime,
				'lang'   => trim( (string) ( $cfg['language'] ?? '' ) ),
				'caller' => 'bot_stt',
			) );
		} finally {
			@unlink( $dest );
		}
		if ( is_wp_error( $result ) ) {
			return self::hub_error( $result, 'bot_media_stt_failed' );
		}
		return array( 'ok' => true, 'text' => (string) ( $result['text'] ?? '' ) );
	}

	/* ── Tạo nhạc — Hub POST /tools/music (synchronous, up to ~330 s) ── */

	/**
	 * Hub music call → { audio_b64, mime_type, ext }.
	 *
	 * @return array|WP_Error
	 */
	private static function run_music( array $cfg, string $prompt ) {
		$client = self::hub();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$result = $client->generate_music( $prompt, array(
			'model'  => trim( (string) ( $cfg['model'] ?? '' ) ),
			'format' => trim( (string) ( $cfg['format'] ?? '' ) ),
			'caller' => 'bot_music',
		) );
		if ( is_wp_error( $result ) ) {
			return self::hub_error( $result, 'bot_media_music_failed' );
		}
		if ( '' === (string) ( $result['audio_b64'] ?? '' ) ) {
			return new WP_Error( 'provider_error', 'The provider returned no audio.', array( 'status' => 502, 'help_code' => 'bot_media_music_empty_audio' ) );
		}
		return $result;
	}

	private static function test_music( array $cfg, array $body ) {
		if ( empty( $body['confirm_cost'] ) ) {
			// EB-3.1: this call costs real money — the FE must have shown the warning and the
			// caller must explicitly confirm, or this refuses before spending anything.
			return new WP_Error( 'confirm_required', 'Confirmation is required before generating real music (it costs money).', array( 'status' => 422, 'help_code' => 'bot_media_music_confirm_required' ) );
		}
		$prompt = trim( (string) ( $body['prompt'] ?? '' ) ) ?: 'Nhạc nền nhẹ nhàng, vui tươi cho một cửa hàng thời trang.';
		$result = self::run_music( $cfg, $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$ext = (string) ( $result['ext'] ?? ( $cfg['format'] ?? 'mp3' ) );
		return array( 'ok' => true, 'audio_base64' => (string) $result['audio_b64'], 'mime_type' => (string) ( $result['mime_type'] ?? 'audio/' . $ext ) );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K3 — the REAL music generation the async job worker calls (test_music() only proves
	 * the route works). No confirm_cost gate (the job queue already gated cost: opt-in tool, hourly ceiling, owner check). It runs in
	 * its own cron event, never inside a customer turn. The Hub call is synchronous (client timeout 330 s).
	 *
	 * @return array{ok:true,binary:string,mime:string,ext:string}|WP_Error  mp3/wav only
	 */
	public static function generate_music( int $character_id, string $prompt ) {
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bot Studio is not ready.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$cfg    = (array) ( BizCity_Bot_Config_Repo::get( $character_id )['media']['music'] ?? array() );
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			return new WP_Error( 'invalid_param', 'The music description is missing.', array( 'status' => 422, 'help_code' => 'bot_media_music_prompt_required' ) );
		}
		$want = (string) ( $cfg['format'] ?? 'mp3' );
		if ( ! in_array( $want, array( 'mp3', 'wav' ), true ) ) {
			// flac is a valid provider format but not one the outbound MIME list (K0-4) accepts: refuse it here rather than pay for a file that cannot be sent.
			return new WP_Error( 'invalid_param', 'Music format must be mp3 or wav so it can be sent through Zalo.', array( 'status' => 422, 'help_code' => 'bot_media_music_format_unsupported' ) );
		}
		$result = self::run_music( $cfg, $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$binary = base64_decode( (string) $result['audio_b64'], true );
		if ( false === $binary || '' === $binary ) {
			return new WP_Error( 'provider_error', 'The audio returned by the provider could not be decoded.', array( 'status' => 502, 'help_code' => 'bot_media_music_bad_audio' ) );
		}
		$ext = strtolower( (string) ( $result['ext'] ?? $want ) );
		if ( ! in_array( $ext, array( 'mp3', 'wav' ), true ) ) {
			return new WP_Error( 'invalid_param', 'Music format must be mp3 or wav so it can be sent through Zalo.', array( 'status' => 422, 'help_code' => 'bot_media_music_format_unsupported' ) );
		}
		return array( 'ok' => true, 'binary' => $binary, 'mime' => 'wav' === $ext ? 'audio/wav' : 'audio/mpeg', 'ext' => $ext );
	}

	/* ── Cào dữ liệu (Apify) — reuses the same real executor scrape_social_data() calls (OW-4A) ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-12/§5.2A.4) — the config UI's own
	 * "Test" button must call the SAME executor the turn tool would use, not a separate stub. This
	 * delegates straight to BizCity_Bot_Apify_Client::scrape() — no duplicate HTTP logic here.
	 */
	private static function test_apify( int $character_id, array $body ) {
		if ( ! class_exists( 'BizCity_Bot_Apify_Client' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bot Studio is not ready.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$platform = sanitize_key( (string) ( $body['platform'] ?? '' ) );
		$url      = trim( (string) ( $body['url'] ?? '' ) );
		if ( '' === $platform || ! isset( BizCity_Bot_Apify_Client::PLATFORM_ACTOR_FIELD[ $platform ] ) ) {
			return new WP_Error( 'invalid_param', 'Choose a platform (facebook/tiktok/youtube/shopee) to test.', array( 'status' => 422, 'help_code' => 'bot_media_apify_platform_required' ) );
		}
		if ( '' === $url ) {
			return new WP_Error( 'invalid_param', 'Enter a public URL to test the Actor.', array( 'status' => 422, 'help_code' => 'bot_media_apify_url_required' ) );
		}
		return BizCity_Bot_Apify_Client::scrape( $character_id, $platform, $url );
	}

	/* ── Tạo video — BizCity_Video_Client (core/bizcity-llm), SITE-level 1API key only (R-1API) ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-05/§5.2A.4 "Test video có cảnh báo
	 * thời gian/phí") — a REAL submit() call, never a mock. Unlike tts/stt/music this does NOT wait
	 * for the result: video generation is asynchronous and can take minutes (BizCity_Video_Client's
	 * own POLL_MAX_ATTEMPTS), so "Test" here proves the job was accepted (real task_id/eta from the
	 * gateway) — it intentionally does not download or attach a finished video (that outbound path is
	 * OW-4 runtime scope, still `unconfigured` per check_video() in class-bot-tool-registry.php).
	 */
	private static function test_video( array $cfg, array $body ) {
		if ( empty( $body['confirm_cost'] ) ) {
			return new WP_Error( 'confirm_required', 'Confirmation is required before generating a real video (it costs money and can take several minutes).', array( 'status' => 422, 'help_code' => 'bot_media_video_confirm_required' ) );
		}
		if ( ! class_exists( 'BizCity_Video_Client' ) ) {
			return new WP_Error( 'module_not_loaded', 'Video_Client (bizcity-llm) is not loaded.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$client = BizCity_Video_Client::instance();
		if ( ! $client->is_ready() ) {
			return new WP_Error( 'bot_provider_key_missing', 'No BizCity 1API key for video. Set it in BizCity LLM settings (site level, not per Guru).', array( 'status' => 422, 'help_code' => 'bot_media_video_key_missing' ) );
		}
		$prompt  = trim( (string) ( $body['prompt'] ?? '' ) ) ?: 'Một đoạn clip ngắn giới thiệu sản phẩm, ánh sáng tự nhiên.';
		$options = array(
			'duration'     => (int) ( $cfg['duration'] ?? 5 ),
			'aspect_ratio' => (string) ( $cfg['aspect_ratio'] ?? '16:9' ),
			'with_audio'   => ! empty( $cfg['with_audio'] ),
		);
		$model = trim( (string) ( $cfg['model'] ?? '' ) );
		if ( '' !== $model ) {
			$options['model'] = $model;
		}
		$result = $client->submit( $prompt, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'provider_error', (string) ( $result['error'] ?? 'The video job could not be submitted.' ), array( 'status' => 502, 'help_code' => 'bot_media_video_failed' ) );
		}
		return array(
			'ok'      => true,
			'task_id' => (string) ( $result['task_id'] ?? '' ),
			'status'  => (string) ( $result['status'] ?? '' ),
			'eta_sec' => (int) ( $result['eta_sec'] ?? 0 ),
		);
	}

	/* ── Vẽ ảnh — BizCity_LLM_Client::generate_image(), SITE-level 1API key only (R-1API) ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-04/§5.2A.4 "Test image phải chứng
	 * minh attachment result") — the SAME real call the bot would use (BizCity_LLM_Client::generate_image(),
	 * already goes through the Hub gateway); no per-character key (see media_defaults() docblock
	 * for the R-1API boundary this shares with video). Returns the real image (url or base64) so the FE
	 * can render an actual preview — the strongest available proof short of a live outbound Zalo send,
	 * which stays out of scope until the dispatcher attach path (OW-4 runtime) exists.
	 */
	private static function test_image( array $cfg, array $body ) {
		if ( empty( $body['confirm_cost'] ) ) {
			return new WP_Error( 'confirm_required', 'Confirmation is required before generating a real image (it costs money).', array( 'status' => 422, 'help_code' => 'bot_media_image_confirm_required' ) );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! method_exists( 'BizCity_LLM_Client', 'generate_image' ) ) {
			return new WP_Error( 'module_not_loaded', 'LLM client (bizcity-llm) is not loaded.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$client = BizCity_LLM_Client::instance();
		if ( method_exists( $client, 'is_ready' ) && ! $client->is_ready() ) {
			return new WP_Error( 'bot_provider_key_missing', 'No BizCity 1API key. Set it in BizCity LLM settings (site level, not per Guru).', array( 'status' => 422, 'help_code' => 'bot_media_image_key_missing' ) );
		}
		$prompt  = trim( (string) ( $body['prompt'] ?? '' ) ) ?: 'Một chiếc cốc cà phê gốm màu trắng trên bàn gỗ, ánh sáng tự nhiên.';
		$options = array( 'size' => (string) ( $cfg['size'] ?? '1024x1024' ) );
		$model   = trim( (string) ( $cfg['model'] ?? '' ) );
		if ( '' !== $model ) {
			$options['model'] = $model;
		}
		$result = $client->generate_image( $prompt, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'provider_error', (string) ( $result['error'] ?? 'The image could not be generated.' ), array( 'status' => 502, 'help_code' => 'bot_media_image_failed' ) );
		}
		return array(
			'ok'        => true,
			'image_url' => (string) ( $result['image_url'] ?? '' ),
			'b64_json'  => (string) ( $result['b64_json'] ?? '' ),
			'model'     => (string) ( $result['model'] ?? '' ),
		);
	}

	/* ── Tra cứu web — BizCity_Search_Client (Hub), cùng đường với công cụ web_search ── */

	/**
	 * [2026-10-14 12:28 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-X2 — no per-Guru search key and no direct search-provider call:
	 * the Test button runs the SAME Hub search route the live `web_search` turn tool uses.
	 */
	private static function test_tavily( array $body ) {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return new WP_Error( 'module_not_loaded', 'Search client (bizcity-llm) is not loaded.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$search = BizCity_Search_Client::instance();
		if ( ! $search->is_ready() ) {
			return new WP_Error( 'bot_provider_key_missing', 'No BizCity 1API key. Set it in BizCity LLM settings (site level, not per Guru).', array( 'status' => 422, 'help_code' => 'bot_media_hub_key_missing' ) );
		}
		$query   = trim( (string) ( $body['query'] ?? '' ) ) ?: 'thời tiết Hà Nội hôm nay';
		$results = $search->search( $query, 5, array( 'include_raw_content' => false ) );
		if ( is_wp_error( $results ) ) {
			return self::hub_error( $results, 'bot_media_search_failed' );
		}
		$results = is_array( $results ) ? $results : array();
		return array(
			'ok'      => true,
			'results' => array_map( static function ( $r ) {
				return array( 'title' => (string) ( $r['title'] ?? '' ), 'url' => (string) ( $r['url'] ?? '' ) );
			}, array_slice( $results, 0, 5 ) ),
		);
	}
}
