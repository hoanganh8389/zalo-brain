<?php
/**
 * BizCity_Brain_Resource_Link — `brain-resource-link@1` (PHASE-0.133 doc 20 §1, R-SOURCE-FIRST-VAULT SF-2/SF-3): the stable identity of ONE source on its
 * journal row, kept in `metadata.resource` next to `original` / `version` / `job` (journal-entry@1.2, no new column, no new table).
 *
 *   source_ref       "TL-47@v2"  (the code a person reads + the version n; never derived from a file name)
 *   source_uuid      UUID v7 minted ONCE for the logical source; every version of the same root and every replay of the same bytes keep it
 *   original         {ref: asset://TL-47/v2, sha256, mime, bytes, origin, captured_at} — a logical locator; the site resolves it, the cell never sees a path
 *   representations [{kind: markdown, ref: vault://TL-47/v2/r1, content_sha256, extract{kind, engine, status, reviewed}, rev}]
 *   knowledge        {kg_source_id, indexed_rev, status: ready|stale|none}
 *   links            {notebook_ref, job_refs[], sheet_refs[], derived_from[]}
 *
 * The site fills it (BizCity_Journal_Index::add / file); a caller cannot set `source_uuid` or `original` here (site-owned). Everything except
 * build() / attach_representation() is pure (unit-tested without WordPress).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-10-24 05:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.133 S133-V1 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\KG_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Resource_Link', false ) ) {
	return;
}

final class BizCity_Brain_Resource_Link {

	const CONTRACT        = 'brain-resource-link@1.0.0';
	const KNOWLEDGE_STATES = array( 'ready', 'stale', 'none' );
	const EXTRACT_KINDS   = array( 'pdf_text', 'pdf_ocr', 'pdf_text_or_ocr', 'image_vision', 'image_ocr', 'image_vision_ocr', 'sheet_parser', 'docx_text', 'audio_transcript', 'note_text', 'other' );
	const EXTRACT_ENGINES = array( 'parser', 'ocr', 'vision', 'transcript', 'model', 'person' );
	const EXTRACT_STATES  = array( 'pending', 'completed', 'failed', 'skipped' );
	const MAX_REPRESENTATIONS = 12;

	/**
	 * UUID v7 (RFC 9562): 48-bit unix ms + version 7 + 74 random bits. Pure apart from time and randomness.
	 * The fallback (no random_bytes) is a v4 from mt_rand — never reached on PHP ≥ 7.
	 */
	public static function uuid_v7( ?int $ms = null ): string {
		$ms   = null === $ms ? (int) floor( microtime( true ) * 1000 ) : $ms;
		$rand = function_exists( 'random_bytes' ) ? random_bytes( 10 ) : substr( md5( (string) mt_rand(), true ), 0, 10 );
		$hex  = str_pad( dechex( $ms ), 12, '0', STR_PAD_LEFT ) . bin2hex( $rand );
		// time_hi(48) | ver(4)=7 + rand_a(12) | var(2)=10 + rand_b(62)
		$a = substr( $hex, 0, 8 );
		$b = substr( $hex, 8, 4 );
		$c = '7' . substr( $hex, 13, 3 );
		$d = dechex( ( hexdec( substr( $hex, 16, 2 ) ) & 0x3f ) | 0x80 ) . substr( $hex, 18, 2 );
		$e = substr( $hex, 20, 12 );
		return strtolower( $a . '-' . $b . '-' . $c . '-' . str_pad( $d, 4, '0', STR_PAD_LEFT ) . '-' . $e );
	}

	/** A UUID string (any version, lower case, 8-4-4-4-12). Pure. */
	public static function is_uuid( string $s ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower( $s ) );
	}

	/** "TL-47" + n ⇒ "TL-47@v2"; '' when the code is not a row code. Pure. */
	public static function source_ref( string $ref_code, int $n ): string {
		$ref_code = strtoupper( trim( $ref_code ) );
		return 1 === preg_match( '/^[A-Z]{2}-\d{1,12}$/', $ref_code ) ? $ref_code . '@v' . max( 1, $n ) : '';
	}

	/** "TL-47@v2" ⇒ {ref: TL-47, n: 2}; null otherwise. Pure. */
	public static function parse_source_ref( string $s ): ?array {
		return 1 === preg_match( '/^\s*([A-Za-z]{2}-\d{1,12})@v(\d{1,6})\s*$/', $s, $m ) ? array( 'ref' => strtoupper( $m[1] ), 'n' => (int) $m[2] ) : null;
	}

	/** The logical locator of a stored original ("asset://TL-47/v2"). Pure. */
	public static function asset_ref( string $ref_code, int $n ): string {
		$sr = self::source_ref( $ref_code, $n );
		return '' === $sr ? '' : 'asset://' . strtoupper( trim( $ref_code ) ) . '/v' . max( 1, $n );
	}

	/** "vault://TL-47/v2/r1" for one Markdown representation revision. Pure. */
	public static function vault_ref( string $ref_code, int $n, int $rev ): string {
		$sr = self::source_ref( $ref_code, $n );
		return '' === $sr ? '' : 'vault://' . strtoupper( trim( $ref_code ) ) . '/v' . max( 1, $n ) . '/r' . max( 1, $rev );
	}

	/** "vault://TL-47/v2/r3" ⇒ {ref, n, rev}; null otherwise. Pure. */
	public static function parse_vault_ref( string $s ): ?array {
		return 1 === preg_match( '#^vault://([A-Za-z]{2}-\d{1,12})/v(\d{1,6})/r(\d{1,6})$#', trim( $s ), $m ) ? array( 'ref' => strtoupper( $m[1] ), 'n' => (int) $m[2], 'rev' => (int) $m[3] ) : null;
	}

	/**
	 * Normalize a stored / incoming `resource` block (site-owned fields win: pass the site's view in $site). Pure.
	 *
	 * @param array $in   what is stored (or sent)
	 * @param array $site {source_ref, source_uuid, original{…}} computed by the site — always overrides $in
	 */
	public static function normalize( array $in, array $site = array() ): array {
		$uuid = strtolower( (string) ( $site['source_uuid'] ?? ( $in['source_uuid'] ?? '' ) ) );
		$out  = array(
			'contract'        => self::CONTRACT,
			'source_ref'      => (string) ( $site['source_ref'] ?? ( self::parse_source_ref( (string) ( $in['source_ref'] ?? '' ) ) ? strtoupper( (string) $in['source_ref'] ) : '' ) ),
			'source_uuid'     => self::is_uuid( $uuid ) ? $uuid : '',
			'original'        => self::normalize_original( is_array( $site['original'] ?? null ) ? $site['original'] : ( is_array( $in['original'] ?? null ) ? $in['original'] : array() ) ),
			'representations' => array(),
			'knowledge'       => self::normalize_knowledge( is_array( $in['knowledge'] ?? null ) ? $in['knowledge'] : array() ),
			'links'           => self::normalize_links( is_array( $in['links'] ?? null ) ? $in['links'] : array() ),
		);
		foreach ( array_slice( (array) ( $in['representations'] ?? array() ), 0, self::MAX_REPRESENTATIONS ) as $r ) {
			$n = is_array( $r ) ? self::normalize_representation( $r ) : null;
			if ( $n ) {
				$out['representations'][] = $n;
			}
		}
		return $out;
	}

	/** `original{ref, sha256, mime, bytes, origin, captured_at}` — locator only, never a path or URL. Pure. */
	public static function normalize_original( array $o ): array {
		$sha = strtolower( (string) ( $o['sha256'] ?? '' ) );
		$ref = (string) ( $o['ref'] ?? '' );
		return array(
			'ref'         => 1 === preg_match( '#^asset://[A-Z]{2}-\d{1,12}/v\d{1,6}$#', $ref ) ? $ref : '',
			'sha256'      => 1 === preg_match( '/^[0-9a-f]{64}$/', $sha ) ? $sha : '',
			'mime'        => substr( preg_replace( '/[^a-z0-9.+\/-]/i', '', (string) ( $o['mime'] ?? '' ) ), 0, 100 ),
			'bytes'       => max( 0, (int) ( $o['bytes'] ?? $o['size'] ?? 0 ) ),
			'origin'      => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $o['origin'] ?? '' ) ) ), 0, 32 ),
			'captured_at' => self::iso( (string) ( $o['captured_at'] ?? '' ) ),
		);
	}

	/** One Markdown (or other) representation; null when its ref is not a vault ref. Pure. */
	public static function normalize_representation( array $r ): ?array {
		$ref = (string) ( $r['ref'] ?? '' );
		$p   = self::parse_vault_ref( $ref );
		if ( null === $p ) {
			return null;
		}
		$x   = is_array( $r['extract'] ?? null ) ? $r['extract'] : array();
		$sha = strtolower( (string) ( $r['content_sha256'] ?? '' ) );
		return array(
			'kind'           => 'markdown',
			'ref'            => $ref,
			'rev'            => $p['rev'],
			'content_sha256' => 1 === preg_match( '/^[0-9a-f]{64}$/', $sha ) ? $sha : '',
			'extract'        => array(
				'kind'     => in_array( (string) ( $x['kind'] ?? '' ), self::EXTRACT_KINDS, true ) ? (string) $x['kind'] : 'other',
				'engine'   => in_array( (string) ( $x['engine'] ?? '' ), self::EXTRACT_ENGINES, true ) ? (string) $x['engine'] : 'model',
				'status'   => in_array( (string) ( $x['status'] ?? '' ), self::EXTRACT_STATES, true ) ? (string) $x['status'] : 'completed',
				'reviewed' => ! empty( $x['reviewed'] ),
				'run_id'   => substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) ( $x['run_id'] ?? '' ) ), 0, 64 ),
			),
			'at'             => self::iso( (string) ( $r['at'] ?? '' ) ),
		);
	}

	/** `knowledge{kg_source_id, indexed_rev, status}`. Pure. */
	public static function normalize_knowledge( array $k ): array {
		$id = max( 0, (int) ( $k['kg_source_id'] ?? 0 ) );
		$st = (string) ( $k['status'] ?? '' );
		return array(
			'kg_source_id' => $id,
			'indexed_rev'  => max( 0, (int) ( $k['indexed_rev'] ?? 0 ) ),
			'status'       => in_array( $st, self::KNOWLEDGE_STATES, true ) ? $st : ( $id > 0 ? 'ready' : 'none' ),
		);
	}

	/** `links{notebook_ref, job_refs[], sheet_refs[], derived_from[]}` — human codes only. Pure. */
	public static function normalize_links( array $l ): array {
		$codes = static function ( $list, int $max ): array {
			$out = array();
			foreach ( (array) $list as $c ) {
				$c = strtoupper( trim( (string) $c ) );
				if ( 1 === preg_match( '/^[A-Z]{2}-\d{1,12}(@v\d{1,6})?$/', $c ) && ! in_array( $c, $out, true ) ) {
					$out[] = $c;
				}
				if ( count( $out ) >= $max ) {
					break;
				}
			}
			return $out;
		};
		$nb = strtoupper( trim( (string) ( $l['notebook_ref'] ?? '' ) ) );
		return array(
			'notebook_ref' => 1 === preg_match( '/^NB-\d{1,12}$/', $nb ) ? $nb : '',
			'job_refs'     => $codes( $l['job_refs'] ?? array(), 50 ),
			'sheet_refs'   => $codes( $l['sheet_refs'] ?? array(), 50 ),
			'derived_from' => $codes( $l['derived_from'] ?? array(), 30 ),
		);
	}

	/**
	 * A representation of `$rev` was written ⇒ the block with it added (same rev replaced), and the knowledge index marked stale when the content
	 * changed against what was indexed. Pure.
	 */
	public static function with_representation( array $resource, array $rep ): array {
		$n = self::normalize_representation( $rep );
		if ( null === $n ) {
			return $resource;
		}
		$list = array();
		foreach ( (array) ( $resource['representations'] ?? array() ) as $r ) {
			if ( is_array( $r ) && (int) ( $r['rev'] ?? 0 ) !== $n['rev'] ) {
				$list[] = $r;
			}
		}
		$list[] = $n;
		usort( $list, static function ( $a, $b ) { return (int) $a['rev'] <=> (int) $b['rev']; } );
		$resource['representations'] = array_slice( $list, -self::MAX_REPRESENTATIONS );
		$k = self::normalize_knowledge( is_array( $resource['knowledge'] ?? null ) ? $resource['knowledge'] : array() );
		if ( $k['kg_source_id'] > 0 && $k['indexed_rev'] !== $n['rev'] ) {
			$k['status'] = 'stale'; // VA-5: the Markdown changed ⇒ the index of THIS source is stale (rebuilt alone)
		}
		$resource['knowledge'] = $k;
		return $resource;
	}

	/** The newest representation (highest rev), or null. Pure. */
	public static function latest_representation( array $resource ): ?array {
		$best = null;
		foreach ( (array) ( $resource['representations'] ?? array() ) as $r ) {
			if ( is_array( $r ) && ( null === $best || (int) $r['rev'] > (int) $best['rev'] ) ) {
				$best = $r;
			}
		}
		return $best;
	}

	/**
	 * The site's view of a row's identity (BizCity_Journal_Index::add / file). Reuses the uuid of: the root of a version chain, else a row with the
	 * same original bytes for the same owner, else the row itself (replay); otherwise mints a v7.
	 *
	 * @param array    $metadata the row's metadata being written (original, version, resource?)
	 * @param string   $ref_code the row code (TL-…/JE-…)
	 * @param callable $lookup   fn(string $kind, string $value): ?string — returns an existing source_uuid ('root' ⇒ by root ref code, 'sha' ⇒ by sha256)
	 */
	public static function site_view( array $metadata, string $ref_code, callable $lookup ): array {
		$orig    = is_array( $metadata['original'] ?? null ) ? $metadata['original'] : array();
		$version = is_array( $metadata['version'] ?? null ) ? $metadata['version'] : array();
		$n       = max( 1, (int) ( $version['n'] ?? 1 ) );
		$current = strtolower( (string) ( $metadata['resource']['source_uuid'] ?? '' ) );
		$uuid    = self::is_uuid( $current ) ? $current : '';
		$root    = (string) ( $version['root_ref'] ?? '' );
		if ( '' === $uuid && '' !== $root ) {
			$uuid = (string) ( $lookup( 'root', $root ) ?? '' );
		}
		$sha = (string) ( $orig['sha256'] ?? '' );
		if ( '' === $uuid && '' !== $sha && $n <= 1 ) {
			$uuid = (string) ( $lookup( 'sha', $sha ) ?? '' );
		}
		if ( ! self::is_uuid( $uuid ) ) {
			$uuid = self::uuid_v7();
		}
		return array(
			'source_ref'  => self::source_ref( $ref_code, $n ),
			'source_uuid' => $uuid,
			'original'    => array(
				'ref'         => self::asset_ref( $ref_code, $n ),
				'sha256'      => $sha,
				'mime'        => (string) ( $orig['mime'] ?? '' ),
				'bytes'       => (int) ( $orig['size'] ?? 0 ),
				'origin'      => (string) ( $metadata['origin']['surface'] ?? '' ),
				'captured_at' => (string) ( $metadata['resource']['original']['captured_at'] ?? '' ),
			),
		);
	}

	/** A row carries a resource block when it is a document / output with an original file, or any row of the document layer. Pure. */
	public static function applies( string $layer, array $original ): bool {
		return (int) ( $original['attachment_id'] ?? 0 ) > 0 || '' !== (string) ( $original['sha256'] ?? '' ) || in_array( $layer, array( 'document', 'output' ), true );
	}

	/** ISO-8601 or ''. Pure. */
	private static function iso( string $s ): string {
		$s = trim( $s );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $s ) ? $s : '';
	}
}
