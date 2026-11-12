<?php
/**
 * BizCity_Journal_Evidence — `external-publication-evidence@1` + `delivery-evidence@1` (PHASE-0.133 doc 70, R-SOURCE-FIRST-VAULT EV-1…8).
 *
 *   four ids, never mixed (EV-1): publication_uuid (ours, one intent across retries) · provider_native_id (THEIRS, kept byte for byte, never forced
 *   into a UUID, never invented) · external_event_id (their receipt / webhook) · evidence_uuid (ours, one evidence record)
 *   object key (EV-3): (tenant, provider, provider_account_ref, object_type, provider_native_id) — the URL is an attribute, never a key
 *   one journal row per milestone (EV-6): kind `job.evidence` (EV-), envelope in `metadata.evidence`, idempotency key from the object key + event type;
 *   a mistake is fixed by a NEW row `evidence.correction` with `supersedes_ref`, never by an overwrite
 *   statuses (EV-8): verified · pending · failed · unknown — no native id ⇒ never `verified`
 *
 * Everything is pure except record() / stamp_product().
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-24 05:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-E1 / S133-E4 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Journal_Evidence', false ) ) {
	return;
}

final class BizCity_Journal_Evidence {

	const PUBLICATION_CONTRACT = 'external-publication-evidence@1.0.0';
	const DELIVERY_CONTRACT    = 'delivery-evidence@1.0.0';
	const PUBLICATION_EVENTS   = array( 'publication.requested', 'publication.published', 'publication.failed', 'publication.unknown', 'publication.updated', 'publication.unpublished', 'publication.deleted' );
	const DOCUMENT_EVENTS      = array( 'document.created', 'document.revised', 'document.sent', 'document.delivery_failed', 'document.received', 'document.signed' );
	const CORRECTION_EVENT     = 'evidence.correction';
	const STATUSES             = array( 'verified', 'pending', 'failed', 'unknown' );
	const METHODS              = array( 'provider_response', 'readback', 'provider_response_plus_readback', 'webhook', 'manual', 'none' );
	const PROVIDERS            = array( 'facebook', 'instagram', 'wordpress', 'website', 'zalo', 'zalo_oa', 'messenger', 'email', 'telegram', 'google', 'tiktok', 'youtube', 'other' );
	const OBJECT_TYPES         = array( 'post', 'photo', 'video', 'page', 'comment', 'message', 'file', 'email', 'reel', 'story', 'other' );
	const VISIBILITY           = array( 'public', 'private', 'draft', 'scheduled', 'unknown' );
	const MAX_NATIVE_ID        = 255;

	// ── normalize (pure) ────────────────────────────────────────────────────

	/**
	 * One evidence envelope (publication or delivery). Returns {envelope, errors}. `$now` = ISO time used for recorded_at when absent.
	 * Rules enforced here, not trusted from the caller: no native id ⇒ status cannot be verified; a published event without native id ⇒ unknown.
	 */
	public static function normalize( array $in, string $now = '' ): array {
		$errors = array();
		$event  = (string) ( $in['event_type'] ?? '' );
		$is_pub = in_array( $event, self::PUBLICATION_EVENTS, true );
		$is_doc = in_array( $event, self::DOCUMENT_EVENTS, true );
		if ( ! $is_pub && ! $is_doc && self::CORRECTION_EVENT !== $event ) {
			$errors[] = 'event_type';
		}
		$uuid = static function ( $v ): string {
			$v = strtolower( trim( (string) $v ) );
			return class_exists( 'BizCity_Brain_Resource_Link' ) && BizCity_Brain_Resource_Link::is_uuid( $v ) ? $v : '';
		};
		$ev_uuid = $uuid( $in['evidence_uuid'] ?? '' );
		if ( '' === $ev_uuid && class_exists( 'BizCity_Brain_Resource_Link' ) ) {
			$ev_uuid = BizCity_Brain_Resource_Link::uuid_v7();
		}
		$dest   = is_array( $in['destination'] ?? null ) ? $in['destination'] : array();
		$native = self::native_id( $dest['provider_native_id'] ?? '' );
		$ver    = is_array( $in['verification'] ?? null ) ? $in['verification'] : array();
		$status = in_array( (string) ( $ver['status'] ?? '' ), self::STATUSES, true ) ? (string) $ver['status'] : 'pending';
		$method = in_array( (string) ( $ver['method'] ?? '' ), self::METHODS, true ) ? (string) $ver['method'] : 'none';
		if ( 'verified' === $status && '' === $native && ! $is_doc ) {
			$status = 'unknown'; // EV-2/EV-8: no id from the platform ⇒ never verified
		}
		if ( 'publication.published' === $event && '' === $native ) {
			$status = 'unknown';
		}
		if ( 'verified' === $status && 'none' === $method ) {
			$status = 'pending'; // a verified claim names how it was verified
		}
		$out = array(
			'schema'        => $is_doc ? self::DELIVERY_CONTRACT : self::PUBLICATION_CONTRACT,
			'evidence_uuid' => $ev_uuid,
			'event_type'    => $event,
			'job_ref'       => self::code( $in['job_ref'] ?? '', 'JB' ),
			'source_refs'   => self::source_refs( (array) ( $in['source_refs'] ?? array() ) ),
			'output_ref'    => self::code( $in['output_ref'] ?? '' ),
			'actor'         => array(
				'kind' => in_array( (string) ( $in['actor']['kind'] ?? '' ), array( 'executor', 'cell', 'owner', 'staff', 'automation', 'webhook' ), true ) ? (string) $in['actor']['kind'] : 'executor',
				'ref'  => substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) ( $in['actor']['ref'] ?? '' ) ), 0, 64 ),
			),
			'verification'  => array(
				'status'              => $status,
				'method'              => $method,
				'checked_at'          => self::iso( $ver['checked_at'] ?? '' ),
				'provider_created_at' => self::iso( $ver['provider_created_at'] ?? '' ),
				'receipt_ref'         => 1 === preg_match( '#^evidence://[A-Za-z0-9_./:-]{1,180}$#', (string) ( $ver['receipt_ref'] ?? '' ) ) ? (string) $ver['receipt_ref'] : '',
				'receipt_sha256'      => 1 === preg_match( '/^[0-9a-f]{64}$/', strtolower( (string) ( $ver['receipt_sha256'] ?? '' ) ) ) ? strtolower( (string) $ver['receipt_sha256'] ) : '',
				'external_event_id'   => self::native_id( $ver['external_event_id'] ?? ( $in['external_event_id'] ?? '' ) ),
			),
			'occurred_at'   => self::iso( $in['occurred_at'] ?? '' ),
			'recorded_at'   => self::iso( $in['recorded_at'] ?? '' ) ?: self::iso( $now ),
		);
		if ( $is_pub || ( self::CORRECTION_EVENT === $event && ! empty( $in['publication_uuid'] ) ) ) {
			$pub_uuid = $uuid( $in['publication_uuid'] ?? '' );
			if ( '' === $pub_uuid ) {
				$errors[] = 'publication_uuid';
			}
			$out['publication_uuid']    = $pub_uuid;
			$out['content_version_ref'] = self::version_ref( $in['content_version_ref'] ?? '' );
			$provider                   = in_array( (string) ( $dest['provider'] ?? '' ), self::PROVIDERS, true ) ? (string) $dest['provider'] : 'other';
			$out['destination']         = array(
				'provider'             => $provider,
				'provider_account_ref' => substr( preg_replace( '/[^A-Za-z0-9_.:@\/-]/', '', (string) ( $dest['provider_account_ref'] ?? '' ) ), 0, 120 ),
				'object_type'          => in_array( (string) ( $dest['object_type'] ?? '' ), self::OBJECT_TYPES, true ) ? (string) $dest['object_type'] : 'other',
				'provider_native_id'   => $native,
				'canonical_url'        => self::url( $dest['canonical_url'] ?? '' ),
				'visibility'           => in_array( (string) ( $dest['visibility'] ?? '' ), self::VISIBILITY, true ) ? (string) $dest['visibility'] : 'unknown',
				'site_ref'             => 'wordpress' === $provider ? substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) ( $dest['site_ref'] ?? '' ) ), 0, 80 ) : '',
				'post_type'            => 'wordpress' === $provider ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $dest['post_type'] ?? '' ) ) ), 0, 32 ) : '',
			);
			if ( '' === $out['destination']['provider_account_ref'] && 'publication.requested' !== $event ) {
				$errors[] = 'provider_account_ref';
			}
		}
		if ( $is_doc ) {
			$d = is_array( $in['delivery'] ?? null ) ? $in['delivery'] : array();
			$out['delivery_uuid'] = $uuid( $in['delivery_uuid'] ?? '' ) ?: ( class_exists( 'BizCity_Brain_Resource_Link' ) ? BizCity_Brain_Resource_Link::uuid_v7() : '' );
			$out['document_version_ref'] = self::version_ref( $in['document_version_ref'] ?? '' );
			$out['delivery'] = array(
				'channel'                 => in_array( (string) ( $d['channel'] ?? '' ), self::PROVIDERS, true ) ? (string) $d['channel'] : 'other',
				'sender_account_ref'      => substr( preg_replace( '/[^A-Za-z0-9_.:@-]/', '', (string) ( $d['sender_account_ref'] ?? '' ) ), 0, 80 ),
				'recipient_principal_ref' => 1 === preg_match( '/^[hp]:[0-9a-f]{16}$/', strtolower( (string) ( $d['recipient_principal_ref'] ?? '' ) ) ) ? strtolower( (string) $d['recipient_principal_ref'] ) : '',
				'provider_message_id'     => self::native_id( $d['provider_message_id'] ?? '' ),
				'sha256'                  => 1 === preg_match( '/^[0-9a-f]{64}$/', strtolower( (string) ( $d['sha256'] ?? '' ) ) ) ? strtolower( (string) $d['sha256'] ) : '',
				'sent_at'                 => self::iso( $d['sent_at'] ?? '' ),
			);
			if ( '' === $out['document_version_ref'] ) {
				$errors[] = 'document_version_ref';
			}
			if ( 'document.sent' === $event && '' === $out['delivery']['recipient_principal_ref'] ) {
				$errors[] = 'recipient_principal_ref'; // R-CONTACT-ID: a resolved principal, never a contact name
			}
			if ( 'document.signed' === $event && 'verified' !== $status ) {
				$errors[] = 'signed_needs_verified_receipt'; // EV-4: signed only with real proof
			}
		}
		if ( self::CORRECTION_EVENT === $event ) {
			$out['supersedes_ref'] = self::code( $in['supersedes_ref'] ?? '', 'EV' );
			$out['reason']         = mb_substr( (string) ( $in['reason'] ?? '' ), 0, 200 );
			if ( '' === $out['supersedes_ref'] ) {
				$errors[] = 'supersedes_ref';
			}
		}
		return array( 'envelope' => $out, 'errors' => $errors );
	}

	/**
	 * EV-3 object key: tenant | provider | account | object type | native id. '' when the object is not known yet (no native id). Pure.
	 */
	public static function object_key( int $tenant, array $env ): string {
		$d = is_array( $env['destination'] ?? null ) ? $env['destination'] : array();
		if ( '' === (string) ( $d['provider_native_id'] ?? '' ) ) {
			return '';
		}
		return implode( '|', array( $tenant, (string) $d['provider'], (string) $d['provider_account_ref'], (string) $d['object_type'], (string) $d['provider_native_id'] ) );
	}

	/**
	 * The journal idempotency key of one milestone (EV-6). A publication milestone keys on its object (or on the publication uuid while no object
	 * exists); a delivery keys on the delivery uuid; a correction on its own evidence uuid. Sanitized + hashed so long / exotic native ids fit
	 * `idempotency_key VARCHAR(191)`; the native id itself stays verbatim in `metadata.evidence`. Pure.
	 */
	public static function idempotency_key( int $tenant, array $env ): string {
		$event = (string) ( $env['event_type'] ?? '' );
		if ( self::CORRECTION_EVENT === $event ) {
			$basis = 'corr|' . (string) ( $env['evidence_uuid'] ?? '' );
		} elseif ( in_array( $event, self::DOCUMENT_EVENTS, true ) ) {
			$basis = 'doc|' . (string) ( $env['delivery_uuid'] ?? '' ) . '|' . $event;
		} else {
			$obj   = self::object_key( $tenant, $env );
			$basis = ( '' !== $obj ? 'obj|' . $obj : 'pub|' . (string) ( $env['publication_uuid'] ?? '' ) ) . '|' . $event;
			if ( 'publication.updated' === $event ) {
				$basis .= '|' . (string) ( $env['content_version_ref'] ?? '' ); // each update of the same post is its own milestone
			}
		}
		return 'ev:' . $tenant . ':' . substr( hash( 'sha256', $basis ), 0, 40 );
	}

	/** Card fields shown for the EV- row (journal-kinds job.evidence `card_view`). Pure. */
	public static function card( array $env ): array {
		$d = is_array( $env['destination'] ?? null ) ? $env['destination'] : array();
		$v = is_array( $env['verification'] ?? null ) ? $env['verification'] : array();
		$card = array(
			'evidence_id'  => (string) ( $env['evidence_uuid'] ?? '' ),
			'event'        => (string) ( $env['event_type'] ?? '' ),
			'method'       => (string) ( $v['method'] ?? '' ),
			'ref_kind'     => in_array( (string) ( $env['event_type'] ?? '' ), self::DOCUMENT_EVENTS, true ) ? 'delivery' : ( self::CORRECTION_EVENT === (string) ( $env['event_type'] ?? '' ) ? 'correction' : 'publication' ),
			'verification' => (string) ( $v['status'] ?? 'pending' ),
		);
		if ( $d ) {
			$card['provider']             = (string) ( $d['provider'] ?? '' );
			$card['provider_account_ref'] = (string) ( $d['provider_account_ref'] ?? '' );
			$card['provider_native_id']   = (string) ( $d['provider_native_id'] ?? '' );
			$card['canonical_url']        = (string) ( $d['canonical_url'] ?? '' );
			$card['visibility']           = (string) ( $d['visibility'] ?? '' );
		}
		if ( is_array( $env['delivery'] ?? null ) ) {
			$card['channel']   = (string) ( $env['delivery']['channel'] ?? '' );
			$card['recipient'] = (string) ( $env['delivery']['recipient_principal_ref'] ?? '' );
			$card['sent_at']   = (string) ( $env['delivery']['sent_at'] ?? '' );
			$card['version']   = (string) ( $env['document_version_ref'] ?? '' );
		}
		return $card;
	}

	/** A short Vietnamese title for the EV- row. Pure. */
	public static function title( array $env ): string {
		$d   = is_array( $env['destination'] ?? null ) ? $env['destination'] : array();
		$st  = array( 'verified' => 'đã xác minh', 'pending' => 'chờ xác minh', 'failed' => 'thất bại', 'unknown' => 'chưa rõ' )[ (string) ( $env['verification']['status'] ?? 'pending' ) ] ?? '';
		$ev  = array(
			'publication.requested' => 'Yêu cầu đăng', 'publication.published' => 'Đã đăng', 'publication.failed' => 'Đăng lỗi', 'publication.unknown' => 'Đăng chưa rõ',
			'publication.updated' => 'Sửa bài', 'publication.unpublished' => 'Gỡ bài', 'publication.deleted' => 'Xoá bài',
			'document.created' => 'Tạo tài liệu', 'document.revised' => 'Sửa tài liệu', 'document.sent' => 'Đã gửi', 'document.delivery_failed' => 'Gửi lỗi',
			'document.received' => 'Đã nhận', 'document.signed' => 'Đã ký', self::CORRECTION_EVENT => 'Đính chính bằng chứng',
		)[ (string) ( $env['event_type'] ?? '' ) ] ?? 'Bằng chứng';
		$where = '' !== (string) ( $d['provider'] ?? '' ) ? ' ' . ucfirst( (string) $d['provider'] ) : '';
		$what  = (string) ( $env['document_version_ref'] ?? $env['content_version_ref'] ?? '' );
		return trim( $ev . $where . ( '' !== $what ? ' · ' . $what : '' ) . ( '' !== $st ? ' (' . $st . ')' : '' ) );
	}

	// ── write (site) ────────────────────────────────────────────────────────

	/**
	 * Record one milestone as a `job.evidence` row (idempotent), linked to its job and the source / product rows; on a verified publication the
	 * product row's card gets the native id / account / URL / status (stamp_product). Returns the journal-entry or WP_Error.
	 */
	public static function record( int $owner, array $env_in, array $job = array() ) {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$n   = self::normalize( $env_in, $now );
		if ( $n['errors'] ) {
			return new WP_Error( 'evidence_invalid', 'Bằng chứng chưa đúng hình dạng: ' . implode( ', ', $n['errors'] ) . '.', array( 'status' => 400, 'fields' => $n['errors'], 'hint' => 'Xem PHASE-0.133 doc 70 §4 (phong bì) — native id lấy từ nền tảng, không tự tạo.', 'help_code' => 'evidence_invalid' ) );
		}
		if ( ! class_exists( 'BizCity_Journal_Index' ) ) {
			return new WP_Error( 'journal_unavailable', 'Sổ chưa sẵn sàng.' );
		}
		$env    = $n['envelope'];
		$tenant = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$derived = array();
		foreach ( array_merge( (array) $env['source_refs'], array( (string) $env['output_ref'] ) ) as $code ) {
			$p   = class_exists( 'BizCity_Brain_Resource_Link' ) ? BizCity_Brain_Resource_Link::parse_source_ref( (string) $code ) : null;
			$row = BizCity_Journal_Index::find_by_ref( $p ? $p['ref'] : (string) $code, $owner );
			if ( $row ) {
				$derived[] = (int) $row['entry_id'];
				// [2026-10-24 06:21 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 (UI gap 4) — the evidence row carries its source's uuid, so journal.find {source_uuid} returns "where this source went"
				if ( ! isset( $env['source_uuid'] ) && '' !== (string) ( $row['resource']['source_uuid'] ?? '' ) && in_array( (string) $code, (array) $env['source_refs'], true ) ) {
					$env['source_uuid'] = (string) $row['resource']['source_uuid'];
				}
			}
		}
		$entry = array(
			'owner_user_id'   => $owner,
			'kind'            => 'job.evidence',
			'title'           => self::title( $env ),
			// [2026-10-24 06:22 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 (cell gap 2) — the source codes ride in the summary, so find_text answers "ảnh TL-47 đăng ở đâu" by code
			'summary'         => trim( self::title( $env ) . ( $env['source_refs'] ? ' · nguồn ' . implode( ' ', (array) $env['source_refs'] ) : '' ) . ( '' !== (string) ( $env['document_version_ref'] ?? '' ) ? ' · bản ' . $env['document_version_ref'] : '' ) ),
			'card'            => self::card( $env ),
			'state'           => 'captured',
			'source_type'     => 'job',
			'source_ref'      => (string) $env['output_ref'],
			'idempotency_key' => self::idempotency_key( $tenant, $env ),
			'derived_from'    => array_values( array_unique( $derived ) ),
			'origin'          => array( 'surface' => 'job', 'ref' => (string) $env['job_ref'] ),
			'evidence'        => $env,
		);
		$row = BizCity_Journal_Index::add( $entry );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( $job && class_exists( 'BizCity_Journal_Job_Link' ) && BizCity_Journal_Job_Link::is_job_id( (string) ( $job['job_id'] ?? '' ) ) ) {
			$linked = BizCity_Journal_Job_Link::attach( $row, $owner, array_merge( $job, array( 'role' => 'evidence' ) ) );
			if ( ! is_wp_error( $linked ) ) {
				$row = $linked;
			}
		}
		if ( 'verified' === (string) $env['verification']['status'] && '' !== (string) $env['output_ref'] ) {
			self::stamp_product( $owner, (string) $env['output_ref'], $env, (string) ( $row['ref_code'] ?? '' ) );
		}
		return $row;
	}

	/** The product row (mkt.post.output · mkt.page.output · document output) shows where it went: native id, account, URL, status, evidence code. */
	public static function stamp_product( int $owner, string $output_ref, array $env, string $ev_ref ): void {
		$row = BizCity_Journal_Index::find_by_ref( $output_ref, $owner );
		if ( ! $row ) {
			return;
		}
		$d    = is_array( $env['destination'] ?? null ) ? $env['destination'] : array();
		$card = array( 'verification' => (string) $env['verification']['status'], 'evidence_ref' => $ev_ref );
		if ( $d ) {
			$card += array( 'provider' => (string) $d['provider'], 'provider_native_id' => (string) $d['provider_native_id'], 'provider_account_ref' => (string) $d['provider_account_ref'], 'canonical_url' => (string) $d['canonical_url'], 'published_at' => (string) ( $env['verification']['provider_created_at'] ?: $env['occurred_at'] ) );
		}
		BizCity_Journal_Index::file( (int) $row['entry_id'], $owner, array( 'card' => $card ) );
	}

	// ── helpers (pure) ──────────────────────────────────────────────────────

	/** A provider id kept verbatim (EV-2): only control chars stripped and the length capped; '' when empty. Pure. */
	public static function native_id( $v ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$s = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $v );
		return strlen( $s ) > self::MAX_NATIVE_ID ? '' : $s; // too long ⇒ refused, never silently cut (a cut id is a wrong id)
	}

	private static function code( $v, string $prefix = '' ): string {
		$s = strtoupper( trim( (string) $v ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}-\d{1,12}$/', $s ) ) {
			return '';
		}
		return '' === $prefix || 0 === strpos( $s, $prefix . '-' ) ? $s : '';
	}

	private static function version_ref( $v ): string {
		$s = trim( (string) $v );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z-]{1,20}-\d{1,12}@[vs]\d{1,6}$/', $s ) ? preg_replace_callback( '/^([A-Za-z-]+)/', static function ( $m ) { return strtoupper( $m[1] ); }, $s ) : '';
	}

	private static function source_refs( array $list ): array {
		$out = array();
		foreach ( array_slice( $list, 0, 20 ) as $r ) {
			$r = trim( (string) $r );
			if ( 1 === preg_match( '/^[A-Za-z]{2}-\d{1,12}(@v\d{1,6})?$/', $r ) ) {
				$out[] = strtoupper( substr( $r, 0, 2 ) ) . substr( $r, 2 );
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function url( $v ): string {
		$s = trim( (string) $v );
		return 1 === preg_match( '#^https://[^\s<>"]{3,500}$#', $s ) ? $s : '';
	}

	private static function iso( $v ): string {
		$s = trim( (string) $v );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $s ) ? $s : '';
	}
}
