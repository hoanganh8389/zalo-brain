<?php
/**
 * BizCity_Commerce_Consult_Score — the D95-16 match score of one product for one customer need (PHASE-0.95 S95-F6).
 *
 * Pure function: no WordPress call, no DB, no LLM. The site (commerce.search_products) and the zalo-hub cell score with the
 * same rules and are both tested against ONE shared fixture: zalo-hub/contracts/fixtures/consult/search.score.json
 * (its `notes` are the algorithm: sum order, round half up, reason / gap code order).
 *
 *   score = 35·aud + 25·goal + 15·facet + 10·budget + 5·stock + 5·pop + 5·tier   (each part 0..1), round half up
 *   excluded_by (need.constraints ∩ advice.avoid_for, either direction) ⇒ score 0
 *
 * Text compares through norm(): lowercase → strip Vietnamese / Latin diacritics → đ→d → collapse spaces → trim. This is
 * written out by hand (not remove_accents(), not intl Normalizer) so PHP gives exactly the cell's NFD result on every
 * fixture string whatever extensions the host has.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      PHASE-0.95 (2026-10-09)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-09 03:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.95-S95-F6 — new file, consult score D95-16 (fixture parity with the cell).
final class BizCity_Commerce_Consult_Score {

	const W_AUD    = 35;
	const W_GOAL   = 25;
	const W_FACET  = 15;
	const W_BUDGET = 10;
	const W_STOCK  = 5;
	const W_POP    = 5;
	const W_TIER   = 5;

	const TIER_VALUE = array( 'strategic' => 1.0, 'core' => 0.6, 'clearance' => 0.4 );

	/** Lowercase letters with a diacritic ⇒ base letter (Vietnamese first, then common Latin-1 / Latin Extended-A). */
	const FOLD = array(
		'a' => 'àáảãạăằắẳẵặâầấẩẫậäåāą',
		'e' => 'èéẻẽẹêềếểễệëēęě',
		'i' => 'ìíỉĩịïîī',
		'o' => 'òóỏõọôồốổỗộơờớởỡợöøō',
		'u' => 'ùúủũụưừứửữựüûūů',
		'y' => 'ỳýỷỹỵÿ',
		'd' => 'đď',
		'c' => 'çćč',
		'n' => 'ñńň',
		's' => 'śš',
		'z' => 'źżž',
		'r' => 'ř',
		't' => 'ť',
		'l' => 'ł',
	);

	/** @var array<string,string>|null */
	private static $fold_map = null;

	/**
	 * @param array      $product {ref, name, short, categories[], attrs{}, price|null, stock instock|low|out, pop 0..1, age_range_months?[min,max]}
	 * @param array|null $advice  product-advice@1 (sanitized) or null when the product has no advice meta
	 * @param array      $need    {for_whom, age, age_unit, goals[], constraints[], budget_max, facets{}}
	 * @param array      $group_ctx {synonyms[]} of the product's group ("a=b" pairs)
	 * @return array{score:int,basis:string,reasons:string[],gaps:string[],excluded_by:string[]}
	 */
	public static function score( array $product, ?array $advice, array $need, array $group_ctx = array() ): array {
		$syn     = array_values( array_filter( array_map( 'strval', (array) ( $group_ctx['synonyms'] ?? array() ) ), 'strlen' ) );
		$advice  = is_array( $advice ) && $advice ? $advice : null;
		$reasons = array();
		$gaps    = array();
		$text    = self::product_text( $product, true );

		// ── aud = 0.5·ageV + 0.5·forV ─────────────────────────────────────
		$age_v = 0.5;
		$age   = $need['age'] ?? null;
		if ( null === $age || '' === $age || ! is_numeric( $age ) ) {
			$gaps[] = 'age';
		} else {
			$months = (float) $age * ( 'month' === (string) ( $need['age_unit'] ?? 'year' ) ? 1 : 12 );
			$range  = self::age_range( $product, $advice );
			if ( null !== $range ) {
				$age_v = ( $months >= $range[0] && $months <= $range[1] ) ? 1.0 : 0.0;
				if ( 1.0 === $age_v ) {
					$reasons[] = 'age_fit';
				}
			}
		}
		$for_v = 0.5;
		$for   = trim( (string) ( $need['for_whom'] ?? '' ) );
		if ( '' === $for ) {
			$gaps[] = 'for_whom';
		} else {
			$aud_list = $advice ? array_values( array_filter( array_map( 'strval', (array) ( $advice['audience'] ?? array() ) ), 'strlen' ) ) : array();
			$hay      = $aud_list ? implode( ' | ', $aud_list ) : self::product_text( $product, false );
			$for_v    = self::has_term( $hay, $for, $syn ) ? 1.0 : 0.0;
			if ( 1.0 === $for_v ) {
				$reasons[] = 'audience_fit';
			}
		}
		$aud = 0.5 * $age_v + 0.5 * $for_v;

		// ── goal = hits / |need.goals| ────────────────────────────────────
		$goals = array_values( array_filter( array_map( 'strval', (array) ( $need['goals'] ?? array() ) ), static function ( $g ) { return '' !== trim( $g ); } ) );
		$goal  = 0.5;
		if ( ! $goals ) {
			$gaps[] = 'goals';
		} else {
			$adv_goals = $advice ? array_values( array_filter( array_map( 'strval', (array) ( $advice['goals'] ?? array() ) ), 'strlen' ) ) : array();
			$hay       = $adv_goals ? implode( ' | ', $adv_goals ) : $text;
			$hits      = 0;
			foreach ( $goals as $g ) {
				if ( self::has_term( $hay, $g, $syn ) ) {
					$hits++;
					$reasons[] = 'goal:' . self::norm( $g );
				}
			}
			$goal = $hits / count( $goals );
		}

		// ── facet = hits / |need.facets| (none asked ⇒ 0.5, no gap) ───────
		$facets = is_array( $need['facets'] ?? null ) ? $need['facets'] : array();
		$facet  = 0.5;
		if ( $facets ) {
			$attrs = array();
			foreach ( (array) ( $product['attrs'] ?? array() ) as $k => $v ) {
				$attrs[ self::norm( (string) $k ) ] = self::norm( is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v );
			}
			$hits = 0;
			foreach ( $facets as $k => $v ) {
				$nk = self::norm( (string) $k );
				$nv = self::norm( is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v );
				if ( '' !== $nv && isset( $attrs[ $nk ] ) && false !== strpos( $attrs[ $nk ], $nv ) ) {
					$hits++;
					$reasons[] = 'facet:' . $nk;
				}
			}
			$facet = $hits / count( $facets );
		}

		// ── budget ────────────────────────────────────────────────────────
		$max    = $need['budget_max'] ?? null;
		$budget = 0.5;
		if ( null === $max || '' === $max || ! is_numeric( $max ) || (float) $max <= 0 ) {
			$gaps[] = 'budget_max';
		} else {
			$price = $product['price'] ?? null;
			if ( null !== $price && '' !== $price && is_numeric( $price ) ) {
				$price = (float) $price;
				if ( $price <= (float) $max ) {
					$budget    = 1.0;
					$reasons[] = 'budget_fit';
				} elseif ( $price <= 1.15 * (float) $max ) {
					$budget = 0.5;
				} else {
					$budget = 0.0;
				}
			}
		}

		// ── stock · pop · tier ────────────────────────────────────────────
		$stock_label = (string) ( $product['stock'] ?? '' );
		$stock       = 'instock' === $stock_label ? 1.0 : ( 'low' === $stock_label ? 0.5 : 0.0 );
		if ( 'instock' === $stock_label ) {
			$reasons[] = 'in_stock';
		}
		$pop  = max( 0.0, min( 1.0, (float) ( $product['pop'] ?? 0 ) ) );
		$tier_name = $advice ? (string) ( $advice['tier'] ?? '' ) : '';
		$tier = self::TIER_VALUE[ $tier_name ] ?? 0.0;
		if ( 'strategic' === $tier_name ) {
			$reasons[] = 'strategic';
		}

		// ── excluded_by: need.constraints ∩ advice.avoid_for (either direction) ──
		$excluded = array();
		$avoid    = $advice ? array_values( array_filter( array_map( 'strval', (array) ( $advice['avoid_for'] ?? array() ) ), 'strlen' ) ) : array();
		foreach ( (array) ( $need['constraints'] ?? array() ) as $c ) {
			$c  = trim( (string) $c );
			$nc = self::norm( $c );
			if ( '' === $nc ) {
				continue;
			}
			foreach ( $avoid as $a ) {
				$na = self::norm( $a );
				if ( '' !== $na && ( false !== strpos( $nc, $na ) || false !== strpos( $na, $nc ) ) ) {
					$excluded[] = $c;
					break;
				}
			}
		}

		// SUM IN THIS ORDER (fixture note), then round half up.
		$sum   = self::W_AUD * $aud + self::W_GOAL * $goal + self::W_FACET * $facet + self::W_BUDGET * $budget + self::W_STOCK * $stock + self::W_POP * $pop + self::W_TIER * $tier;
		$score = $excluded ? 0 : (int) floor( $sum + 0.5 );

		return array(
			'score'       => max( 0, min( 100, $score ) ),
			'basis'       => $advice ? 'advice' : 'attrs',
			'reasons'     => self::ordered_reasons( $reasons ),
			'gaps'        => $gaps,
			'excluded_by' => $excluded,
		);
	}

	/** norm(s) = lowercase → strip diacritics → đ→d → collapse spaces → trim (fixture note). */
	public static function norm( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = strtr( $s, self::fold_map() );
		// Combining marks (U+0300–U+036F) left by decomposed input.
		$s = (string) preg_replace( '/[\x{0300}-\x{036F}]/u', '', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** norm(text) contains norm(t); or a synonym pair "a=b" where norm(t) is one side and the text contains the other. */
	public static function has_term( string $text, string $term, array $synonyms = array() ): bool {
		$nt = self::norm( $term );
		if ( '' === $nt ) {
			return false;
		}
		$hay = self::norm( $text );
		if ( false !== strpos( $hay, $nt ) ) {
			return true;
		}
		foreach ( $synonyms as $pair ) {
			$sides = explode( '=', (string) $pair, 2 );
			if ( 2 !== count( $sides ) ) {
				continue;
			}
			$a = self::norm( $sides[0] );
			$b = self::norm( $sides[1] );
			if ( '' === $a || '' === $b ) {
				continue;
			}
			if ( ( $nt === $a && false !== strpos( $hay, $b ) ) || ( $nt === $b && false !== strpos( $hay, $a ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Age range in months: advice range wins over product.age_range_months; null = unknown. */
	private static function age_range( array $product, ?array $advice ): ?array {
		if ( $advice && ( ( isset( $advice['age_min'] ) && is_numeric( $advice['age_min'] ) ) || ( isset( $advice['age_max'] ) && is_numeric( $advice['age_max'] ) ) ) ) {
			$mult = 'month' === (string) ( $advice['age_unit'] ?? 'year' ) ? 1 : 12;
			$min  = isset( $advice['age_min'] ) && is_numeric( $advice['age_min'] ) ? (float) $advice['age_min'] * $mult : 0.0;
			$max  = isset( $advice['age_max'] ) && is_numeric( $advice['age_max'] ) ? (float) $advice['age_max'] * $mult : INF;
			return array( $min, $max );
		}
		$r = $product['age_range_months'] ?? null;
		if ( is_array( $r ) && 2 === count( $r ) && is_numeric( $r[0] ?? null ) && is_numeric( $r[1] ?? null ) ) {
			return array( (float) $r[0], (float) $r[1] );
		}
		return null;
	}

	/** name + short + categories (+ attrs keys/values when $with_attrs). */
	private static function product_text( array $product, bool $with_attrs ): string {
		$parts = array( (string) ( $product['name'] ?? '' ), (string) ( $product['short'] ?? '' ) );
		foreach ( (array) ( $product['categories'] ?? array() ) as $c ) {
			$parts[] = (string) $c;
		}
		if ( $with_attrs ) {
			foreach ( (array) ( $product['attrs'] ?? array() ) as $k => $v ) {
				$parts[] = (string) $k . ' ' . ( is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v );
			}
		}
		return implode( ' | ', $parts );
	}

	/** age_fit, audience_fit, goal:…, facet:…, budget_fit, in_stock, strategic (fixture note). */
	private static function ordered_reasons( array $reasons ): array {
		$rank = static function ( string $r ): int {
			if ( 'age_fit' === $r ) { return 0; }
			if ( 'audience_fit' === $r ) { return 1; }
			if ( 0 === strpos( $r, 'goal:' ) ) { return 2; }
			if ( 0 === strpos( $r, 'facet:' ) ) { return 3; }
			if ( 'budget_fit' === $r ) { return 4; }
			if ( 'in_stock' === $r ) { return 5; }
			return 6;
		};
		$indexed = array();
		foreach ( array_values( $reasons ) as $i => $r ) {
			$indexed[] = array( $rank( $r ), $i, $r );
		}
		usort( $indexed, static function ( $a, $b ) { return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ); } );
		return array_values( array_unique( array_column( $indexed, 2 ) ) );
	}

	private static function fold_map(): array {
		if ( null !== self::$fold_map ) {
			return self::$fold_map;
		}
		$map = array();
		foreach ( self::FOLD as $base => $chars ) {
			foreach ( preg_split( '//u', $chars, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
				$map[ $ch ] = $base;
			}
		}
		return self::$fold_map = $map;
	}
}
