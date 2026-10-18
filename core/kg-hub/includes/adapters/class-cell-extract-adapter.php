<?php
/**
 * BizCity KG-Hub — Cell Extract Source Adapter (PHASE-0.99 S99-P38, doc 80 §4)
 *
 * Replaces the "ask an LLM to fetch a URL and transcribe it" fallback
 * (`class-twinchat-sources-service.php::extract_via_media_url()`, PHP has no real PDF/Office engine on
 * production — doc 80 G1) with a DETERMINISTIC read: the site sideloads the file to a temporary WP media
 * attachment (post_id=0, same trick `extract_via_media_url()` already uses for its public URL) and asks the
 * cell to parse it with the same library that already reads files the owner sends on Zalo
 * (`extractDocumentText` — pdfjs-dist/mammoth/exceljs/node-pptx-parser, zalo-hub S99-P36/P37).
 *
 * Registered FIRST in the registry (ahead of LiteParse/PDF/Office) so it wins `resolve()` whenever a zalo_hub
 * number of this site's key is reachable; `supports()` returns false otherwise (no number, option set to
 * `php`, sideload/relay class missing) so the OLDER adapters still run unchanged — this adapter never removes
 * a working path, only skips in front of it when a faster one exists.
 *
 * `extract()` never returns a cell-reachability error outward: a failed cell call falls through to the
 * legacy PDF/Office adapter INSTANCES directly (never back through the registry — that would re-resolve to
 * this same adapter and loop). Only a genuine extraction failure from that legacy fallback reaches the
 * caller as WP_Error, same contract as before this adapter existed.
 *
 * Option `bizcity_kg_extract_engine` ("auto" default | "cell" | "php") — the one explicit way back
 * (`prefer-auto-defaults-over-config`): "php" skips this adapter entirely, "cell" is the same as "auto" today
 * (no second cell-only mode yet — kept distinct for a future "fail instead of falling back" strictness).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub\Adapters
 * @since      2026-10-13
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Cell_Extract_Adapter implements BizCity_KG_Source_Adapter {

	const SUPPORTED_EXTS = [ 'pdf', 'docx', 'xlsx', 'pptx' ];
	const ACCOUNT_TRANSIENT = 'bizcity_zh_any_account';
	const ACCOUNT_TTL = 300; // 5 min — a batch of several files in one ingest hits the Hub once, not per file
	const MAX_SIDELOAD_BYTES = 20 * 1024 * 1024;

	public static function id() {
		return 'cell_extract';
	}

	public static function supports( $ext, $mime ) {
		if ( ! in_array( strtolower( (string) $ext ), self::SUPPORTED_EXTS, true ) ) {
			return false;
		}
		if ( 'php' === self::engine_option() ) {
			return false;
		}
		return '' !== self::active_zalo_hub_account_id();
	}

	public function extract( $file_path, array $opts ) {
		$ext = strtolower( (string) ( $opts['ext'] ?? pathinfo( (string) $file_path, PATHINFO_EXTENSION ) ) );
		$account_id = self::active_zalo_hub_account_id();
		// [2026-10-17 04:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.121 S121-L1 — a private original (owner-capture document) is
		// never re-uploaded as a public temp copy (that copy went to the CDN and was never removed there): the cell gets a
		// 15-minute signed link to the owner-only route instead.
		$private_url = '' !== $account_id ? self::private_signed_url( $opts ) : '';
		if ( '' !== $private_url ) {
			$sideloaded = array( 'attach_id' => 0, 'url' => $private_url );
		} else {
			$sideloaded = '' !== $account_id ? self::sideload_public_url( $file_path, $ext, $opts ) : new WP_Error( 'no_account', 'no zalo_hub account' );
		}

		if ( ! is_wp_error( $sideloaded ) ) {
			$result = BizCity_Zalo_Personal_Hub_Client::instance()->doc_extract( $account_id, $sideloaded['url'], $ext, (string) ( $opts['job_id'] ?? '' ) );
			if ( $sideloaded['attach_id'] > 0 ) {
				wp_delete_attachment( $sideloaded['attach_id'], true );
			}
			$text = is_array( $result ) ? trim( (string) ( $result['text'] ?? '' ) ) : '';
			if ( is_array( $result ) && ! empty( $result['ok'] ) && '' !== $text ) {
				do_action( 'bizcity_kg_adapter_chosen', self::id(), $ext, (string) ( $opts['mime'] ?? '' ) );
				return [
					'text'     => $text,
					'segments' => [],
					'assets'   => [],
					'modality' => 'cell_extract',
					'meta'     => [ 'engine' => 'cell', 'ext' => $ext ],
				];
			}
			do_action( 'bizcity_kg_cell_extract_fallback', is_array( $result ) ? (string) ( $result['code'] ?? 'cell_extract_empty' ) : 'cell_extract_invalid', $ext );
		} else {
			do_action( 'bizcity_kg_cell_extract_fallback', $sideloaded->get_error_code(), $ext );
		}

		return self::fallback_to_legacy_adapter( $ext, $file_path, $opts );
	}

	/** `auto` (default) | `cell` | `php` — the explicit way back to pure-PHP extraction. */
	private static function engine_option() {
		return sanitize_key( (string) apply_filters( 'bizcity_kg_extract_engine', get_option( 'bizcity_kg_extract_engine', 'auto' ) ) );
	}

	/**
	 * Any ACTIVE zalo_hub number of this site's key — the text extracted has nothing to do with WHICH number's
	 * cell reads it, only that some cell is reachable. Cached so an ingest batch of several files costs one
	 * Hub round trip, not one per file; '' (no number) also caches, so a site with no Zalo number doesn't
	 * retry the Hub on every upload either.
	 */
	private static function active_zalo_hub_account_id() {
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ) {
			return '';
		}
		$cached = get_transient( self::ACCOUNT_TRANSIENT );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$id = '';
		$list = BizCity_Zalo_Personal_Hub_Client::instance()->list_accounts();
		if ( ! empty( $list['success'] ) && is_array( $list['accounts'] ?? null ) ) {
			foreach ( $list['accounts'] as $acc ) {
				if ( is_array( $acc ) && 'zalo_hub' === (string) ( $acc['provider'] ?? '' ) && 'active' === (string) ( $acc['status'] ?? '' ) ) {
					$id = (string) ( $acc['id'] ?? '' );
					if ( '' !== $id ) {
						break;
					}
				}
			}
		}
		set_transient( self::ACCOUNT_TRANSIENT, $id, self::ACCOUNT_TTL );
		return $id;
	}

	/**
	 * Sideload the local file to a temporary, unattached (post_id=0) WP media item so the cell has a URL to
	 * fetch — same trick `extract_via_media_url()` already uses. Caller deletes the attachment right after
	 * the cell call; it is never meant to persist.
	 *
	 * @return array{attach_id:int,url:string}|WP_Error
	 */
	private static function sideload_public_url( $file_path, $ext, array $opts ) {
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error( 'cell_extract_file_missing', 'File not found at expected path' );
		}
		if ( (int) filesize( $file_path ) > self::MAX_SIDELOAD_BYTES ) {
			return new WP_Error( 'cell_extract_too_large', 'File exceeds ' . self::MAX_SIDELOAD_BYTES . ' bytes for cell extraction' );
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$mime = isset( $opts['mime'] ) && '' !== $opts['mime'] ? (string) $opts['mime'] : ( function_exists( 'mime_content_type' ) ? @mime_content_type( $file_path ) : '' );
		$fname = sanitize_file_name( basename( $file_path ) . '.' . $ext );
		$file_array = [ 'name' => $fname, 'tmp_name' => $file_path, 'type' => $mime, 'error' => 0, 'size' => filesize( $file_path ) ];

		$allow = [ $ext => $mime ];
		$mime_filter = static function ( $mimes ) use ( $allow ) {
			return array_merge( (array) $mimes, $allow );
		};
		add_filter( 'upload_mimes', $mime_filter, 99 );
		$attach_id = media_handle_sideload( $file_array, 0 );
		remove_filter( 'upload_mimes', $mime_filter, 99 );

		if ( is_wp_error( $attach_id ) ) {
			return new WP_Error( 'cell_extract_sideload_failed', 'Could not sideload file: ' . $attach_id->get_error_message() );
		}
		$url = wp_get_attachment_url( $attach_id );
		if ( ! $url ) {
			wp_delete_attachment( $attach_id, true );
			return new WP_Error( 'cell_extract_no_url', 'Could not get attachment URL after sideload' );
		}
		return [ 'attach_id' => (int) $attach_id, 'url' => (string) $url ];
	}

	/** PHASE-0.121 S121-L1 — signed link of a private original named by `attachment_id`, '' for any other file. */
	private static function private_signed_url( array $opts ) {
		$id = (int) ( $opts['attachment_id'] ?? 0 );
		if ( $id <= 0 || ! class_exists( 'BizCity_KG_Private_File_Store' ) ) {
			return '';
		}
		return BizCity_KG_Private_File_Store::signed_url( $id );
	}

	/** Direct instance call (never through the registry, which would re-resolve to THIS adapter and loop). */
	private static function fallback_to_legacy_adapter( $ext, $file_path, array $opts ) {
		if ( in_array( $ext, [ 'pdf' ], true ) && class_exists( 'BizCity_KG_Pdf_Adapter' ) ) {
			return ( new BizCity_KG_Pdf_Adapter() )->extract( $file_path, $opts );
		}
		if ( in_array( $ext, [ 'docx', 'xlsx', 'pptx' ], true ) && class_exists( 'BizCity_KG_Office_Adapter' ) ) {
			return ( new BizCity_KG_Office_Adapter() )->extract( $file_path, $opts );
		}
		return new WP_Error( 'cell_extract_no_fallback', 'Cell không đọc được file và không có adapter PHP dự phòng cho .' . $ext );
	}
}
