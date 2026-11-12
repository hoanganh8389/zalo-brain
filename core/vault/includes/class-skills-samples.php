<?php
/**
 * BizCity_Skills_Samples — ready-made daily-assistant skills, seeded and kept up to date by version (PHASE-0.117 S117-W2).
 *
 * The samples are plain SKILL.md files shipped in `core/skills/samples/` plus an `index.json` that gives each one a `version`
 * (bumped by `node bin/skill-samples-sync.mjs` whenever the file changes) and a `file_hash` (drift guard).
 * A site admin loading wp-admin runs `maybe_sync()` once per index revision (a stat of index.json, nothing else on later requests):
 *
 *   - not there, never seeded          ⇒ installed as an APPROVED skill (`source: template`) — the cell reads it within a minute
 *   - there, unchanged since we wrote  ⇒ updated in place when the sample version went up (status kept: a skill the owner turned off stays off)
 *   - there, edited by the owner       ⇒ left alone ("customized"); the owner's text is theirs
 *   - there, identical to the sample   ⇒ adopted (so later versions can update it)
 *   - deleted by the owner             ⇒ NOT brought back ("removed"); the owner can install it again by hand
 *   - a skill of the same name the owner wrote themselves is never touched
 *
 * One switch turns the whole thing off: option `bizcity_skill_samples_auto` (and the filter `bizcity_skill_samples_auto_seed`).
 * A sample carries no rights: it only tells the model HOW to use tools the cell already has; every send / write still goes through
 * the tool's own draft → approve gate.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 */
// [2026-10-17 10:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W2 — new file; auto seed + version bump (owner asked: no install button).

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Skills_Samples', false ) ) {
	return;
}

final class BizCity_Skills_Samples {

	const MAX_INSTALL = 20;
	const OPT_AUTO    = 'bizcity_skill_samples_auto';
	const OPT_STATE   = 'bizcity_skill_samples_state'; // {name: {v: version we last wrote, h: fingerprint of what we wrote}}
	const OPT_REV     = 'bizcity_skill_samples_rev';   // index.json "mtime-size" the last sync finished for
	const OPT_LAST    = 'bizcity_skill_samples_last';  // report of the last sync (counts + error codes only)

	/** @var string|null Tests: absolute path of an alternative samples directory */
	public static $dir = null;

	/** @var bool one automatic sync per request (tests reset it to play several requests) */
	public static $ran = false;

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_sync' ), 30 );
	}

	public static function dir(): string {
		return null !== self::$dir ? rtrim( self::$dir, '/\\' ) : rtrim( dirname( __DIR__ ), '/\\' ) . '/samples';
	}

	/* ── index / parse ─────────────────────────────────────────────────── */

	/** @return array{categories: array<string,string>, samples: array<string,array>} */
	private static function index(): array {
		$f = self::dir() . '/index.json';
		$j = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		return array(
			'categories' => is_array( $j['categories'] ?? null ) ? $j['categories'] : array(),
			'samples'    => is_array( $j['samples'] ?? null ) ? $j['samples'] : array(),
		);
	}

	/** The parsed SKILL.md of one sample, or null (unknown name, unreadable, not a valid skill). */
	public static function parse( string $name ) {
		$name = BizCity_Skills_Service::clean_name( $name );
		$f    = '' === $name ? '' : self::dir() . '/' . $name . '.md';
		if ( '' === $name || ! is_file( $f ) ) {
			return null;
		}
		$s = BizCity_Skills_Service::from_raw( (string) file_get_contents( $f ), '/skills/' . $name . '.md' );
		return ( $s && $s['name'] === $name ) ? $s : null;
	}

	/** What makes two skills "the same text": body, description, tool list. Library bookkeeping (version, status, source) is not part of it. */
	public static function fingerprint( array $skill ): string {
		return sha1( (string) $skill['body_md'] . "\n" . (string) $skill['description'] . "\n" . implode( ',', (array) $skill['tools'] ) );
	}

	/** version of a sample in the shipped index (never below 1) */
	private static function version_of( array $meta ): int {
		return max( 1, (int) ( $meta['version'] ?? 1 ) );
	}

	/* ── switch ────────────────────────────────────────────────────────── */

	public static function auto_enabled(): bool {
		return (bool) apply_filters( 'bizcity_skill_samples_auto_seed', '0' !== (string) get_option( self::OPT_AUTO, '1' ) );
	}

	public static function set_auto( bool $on ): void {
		update_option( self::OPT_AUTO, $on ? '1' : '0', false );
		if ( $on ) {
			update_option( self::OPT_REV, '', false ); // the next admin load syncs at once
		}
	}

	/* ── state ─────────────────────────────────────────────────────────── */

	private static function state(): array {
		$s = get_option( self::OPT_STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function remember( array &$state, string $name, int $version, array $skill ): void {
		$state[ $name ] = array( 'v' => $version, 'h' => self::fingerprint( $skill ) );
	}

	/**
	 * Where one sample stands on THIS site.
	 * not_installed · removed · current · update_available · customized
	 */
	private static function state_of( array $sample, array $meta, $have, $st ): string {
		if ( ! $have ) {
			return $st ? 'removed' : 'not_installed';
		}
		$fp = self::fingerprint( $have );
		if ( ! $st ) {
			return $fp === self::fingerprint( $sample ) ? 'current' : 'customized';
		}
		if ( $fp !== (string) ( $st['h'] ?? '' ) ) {
			return 'customized';
		}
		return (int) ( $st['v'] ?? 0 ) < self::version_of( $meta ) ? 'update_available' : 'current';
	}

	/* ── read ──────────────────────────────────────────────────────────── */

	/**
	 * Every sample with where it stands on this site.
	 *
	 * @return array [{name, title, icon, category, category_label, description, tools[], needs[], installed, status, state, version, installed_version}]
	 */
	public static function all(): array {
		$idx   = self::index();
		$state = self::state();
		$out   = array();
		foreach ( $idx['samples'] as $name => $meta ) {
			$s = self::parse( (string) $name );
			if ( ! $s ) {
				continue;
			}
			$have = BizCity_Skills_Service::get( (string) $name );
			$st   = $state[ $name ] ?? null;
			$cat  = (string) ( $meta['category'] ?? '' );
			$out[] = array(
				'name'              => (string) $name,
				'title'             => (string) ( $meta['title'] ?? $name ),
				'icon'              => (string) ( $meta['icon'] ?? '' ),
				'category'          => $cat,
				'category_label'    => (string) ( $idx['categories'][ $cat ] ?? $cat ),
				'description'       => (string) $s['description'],
				'tools'             => (array) $s['tools'],
				'needs'             => array_values( array_map( 'strval', (array) ( $meta['needs'] ?? array() ) ) ),
				'installed'         => (bool) $have,
				// [2026-10-17 10:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.117 S117-W2 — where the skill lives on this site, so a card can open it (the owner may have moved the file)
				'path'              => $have ? (string) $have['path'] : '',
				'status'            => $have ? (string) $have['status'] : '',
				'state'             => self::state_of( $s, (array) $meta, $have, $st ),
				'version'           => self::version_of( (array) $meta ),
				'installed_version' => $st ? (int) ( $st['v'] ?? 0 ) : 0,
			);
		}
		return $out;
	}

	/** One sample for the read-only preview behind a card: description, tools, the text (never more than the shipped file). */
	public static function preview( string $name ) {
		$s = self::parse( $name );
		if ( ! $s ) {
			return null;
		}
		$meta = self::index()['samples'][ $s['name'] ] ?? array();
		return array( 'name' => $s['name'], 'title' => (string) ( $meta['title'] ?? $s['name'] ), 'icon' => (string) ( $meta['icon'] ?? '' ), 'description' => (string) $s['description'], 'tools' => (array) $s['tools'], 'needs' => array_values( array_map( 'strval', (array) ( $meta['needs'] ?? array() ) ) ), 'version' => self::version_of( (array) $meta ), 'body_md' => (string) $s['body_md'] );
	}

	/** counts + error codes of the last automatic sync, or null */
	public static function last_report() {
		$r = get_option( self::OPT_LAST, null );
		return is_array( $r ) ? $r : null;
	}

	/* ── write ─────────────────────────────────────────────────────────── */

	/**
	 * Admin request hook: run `sync()` once per index revision. Cheap otherwise: one option read + one stat.
	 *
	 * @return array|null the sync report, or null when nothing ran
	 */
	public static function maybe_sync() {
		if ( self::$ran || ! self::auto_enabled() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_posts' ) ) {
			return null;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return null;
		}
		$f   = self::dir() . '/index.json';
		$rev = is_file( $f ) ? (string) filemtime( $f ) . '-' . (string) filesize( $f ) : '';
		if ( '' === $rev || (string) get_option( self::OPT_REV, '' ) === $rev ) {
			return null;
		}
		self::$ran = true;
		$report    = self::sync();
		update_option( self::OPT_REV, $rev, false );
		update_option( self::OPT_LAST, array( 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'installed' => count( $report['installed'] ), 'updated' => count( $report['updated'] ), 'adopted' => count( $report['adopted'] ), 'customized' => count( $report['customized'] ), 'removed' => count( $report['removed'] ), 'errors' => $report['errors'] ), false );
		return $report;
	}

	/**
	 * Bring this blog's library in line with the shipped samples (rules in the class header). Idempotent.
	 *
	 * @return array {installed: string[], updated: string[], adopted: string[], customized: string[], removed: string[], errors: array<string,string>}
	 */
	public static function sync(): array {
		$res   = array( 'installed' => array(), 'updated' => array(), 'adopted' => array(), 'customized' => array(), 'removed' => array(), 'errors' => array() );
		$state = self::state();
		foreach ( self::index()['samples'] as $name => $meta ) {
			$name = (string) $name;
			$s    = self::parse( $name );
			if ( ! $s ) {
				$res['errors'][ $name ] = 'sample_unknown';
				continue;
			}
			$ver  = self::version_of( (array) $meta );
			$have = BizCity_Skills_Service::get( $name );
			$st   = $state[ $name ] ?? null;
			switch ( self::state_of( $s, (array) $meta, $have, $st ) ) {
				case 'not_installed':
					$e = self::write_new( $name, $s, true );
					if ( '' === $e ) {
						self::remember( $state, $name, $ver, $s );
						$res['installed'][] = $name;
					} else {
						$res['errors'][ $name ] = $e;
					}
					break;
				case 'removed':
					$res['removed'][] = $name; // the owner deleted it: respect that
					break;
				case 'customized':
					$res['customized'][] = $name;
					break;
				case 'update_available':
					$saved = BizCity_Skills_Service::save( array( 'name' => $name, 'description' => $s['description'], 'body_md' => $s['body_md'], 'tools' => $s['tools'], 'scope' => $s['scope'] ) );
					if ( is_wp_error( $saved ) ) {
						$res['errors'][ $name ] = (string) $saved->get_error_code();
						break;
					}
					self::remember( $state, $name, $ver, BizCity_Skills_Service::get( $name ) ?: $s );
					$res['updated'][] = $name;
					break;
				default: // current
					if ( ! $st ) { // same text as the sample but we never wrote it: adopt it so later versions can update it
						self::remember( $state, $name, $ver, $have );
						$res['adopted'][] = $name;
					}
			}
		}
		update_option( self::OPT_STATE, $state, false );
		return $res;
	}

	/**
	 * Manual install of named samples (the owner's click): only what is not there; never overwrites; `*` = all. Also brings back a removed one.
	 *
	 * @param string[] $names
	 * @return array {installed: string[], skipped: array<string,string>, errors: array<string,string>}
	 */
	public static function install( array $names, bool $approve = true ): array {
		$res = array( 'installed' => array(), 'skipped' => array(), 'errors' => array() );
		$idx = self::index()['samples'];
		if ( in_array( '*', $names, true ) ) {
			$names = array_keys( $idx );
		}
		$names = array_slice( array_values( array_unique( array_map( 'strval', $names ) ) ), 0, self::MAX_INSTALL );
		$state = self::state();
		foreach ( $names as $name ) {
			$s = self::parse( $name );
			if ( ! $s ) {
				$res['errors'][ $name ] = 'sample_unknown';
				continue;
			}
			if ( BizCity_Skills_Service::get( $name ) ) {
				$res['skipped'][ $name ] = 'exists'; // never overwrite the owner's own skill
				continue;
			}
			$e = self::write_new( $name, $s, $approve );
			if ( '' !== $e ) {
				$res['errors'][ $name ] = $e;
				continue;
			}
			self::remember( $state, $name, self::version_of( (array) ( $idx[ $name ] ?? array() ) ), $s );
			$res['installed'][] = $name;
		}
		update_option( self::OPT_STATE, $state, false );
		return $res;
	}

	/** create one sample skill; '' on success, else the error code */
	private static function write_new( string $name, array $s, bool $approve ): string {
		$saved = BizCity_Skills_Service::save( array(
			'name'        => $name,
			'description' => $s['description'],
			'body_md'     => $s['body_md'],
			'tools'       => $s['tools'],
			'scope'       => $s['scope'],
			'source'      => 'template',
		) );
		if ( is_wp_error( $saved ) ) {
			return (string) $saved->get_error_code();
		}
		if ( $approve ) {
			$st = BizCity_Skills_Service::set_status( array( 'name' => $name, 'status' => 'approved' ) );
			if ( is_wp_error( $st ) ) {
				return (string) $st->get_error_code();
			}
		}
		return '';
	}
}
