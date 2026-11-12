<?php
/**
 * Bizcity Twin AI — TwinBrain Graph Ask (notebook-graph-ask@1).
 *
 * [2026-10-27 06:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 S143-L1 (D143-1) — LAYER 1 of the notebook answer: pulls the evidence out of the
 * notebook's knowledge graph (seed entities/relations → 1-hop expand → rerank → passages from the .bin/jsonl filestore) and returns it as DATA.
 * NO LLM runs here (R-ONE-VERTICAL-CELL / R-VERTICAL-AXIS): the cell composes the answer from `source_map` with `[nb:<id>/p<pid>]` tokens,
 * emits `kg_highlight` from `entity_ids` of the tokens it used, and offers `question_seeds` as follow-up chips.
 * Contract: core/kg-hub/contracts/NOTEBOOK-GRAPH-ASK-v1.json.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Graph_Ask {

	const CONTRACT       = 'notebook-graph-ask@1';
	const TOP_K_MAX      = 12;
	// [2026-10-27 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 (live T143-1) — the cell hands a site result to the model capped at 12 000 chars: a 24 KB pack was cut mid-JSON. 700 × 8 + triples/entities stays under ~9 KB.
	const PASSAGE_CHARS  = 700;
	const PACK_BYTES_MAX = 9000;
	const SEEDS_MAX      = 6;

	/**
	 * @param array $args { notebook_id, query, source_ids?, guru_id?, top_k?, expand_hops?, lang? }
	 * @param array $ctx  MCP auth context (allowed_notebook_ids / user_id / auth_method).
	 * @return array|WP_Error envelope { summary, data, receipt }
	 */
	public static function run( array $args, array $ctx ) {
		$t0    = microtime( true );
		$nb_id = (int) ( $args['notebook_id'] ?? 0 );
		$query = trim( (string) ( $args['query'] ?? '' ) );
		if ( $nb_id <= 0 || '' === $query ) {
			return new WP_Error( 'query_invalid', 'Thiếu notebook_id hoặc query.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_KG_Retriever' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'retrieval_failed', 'KG Retriever không sẵn sàng.', array( 'status' => 503 ) );
		}

		// Step 0 — scope: the caller's own readable notebooks only; a Guru turn is narrowed to the Guru's folders (fails closed).
		$allowed = class_exists( 'BizCity_MCP_Client_Scope_Resolver' ) ? BizCity_MCP_Client_Scope_Resolver::allowed_notebook_ids( $ctx ) : array();
		if ( ! in_array( $nb_id, array_map( 'intval', (array) $allowed ), true ) ) {
			return new WP_Error( 'notebook_access_denied', 'Notebook không nằm trong phạm vi được đọc.', array( 'status' => 403 ) );
		}
		$guru_id = (int) ( $args['guru_id'] ?? 0 );
		if ( $guru_id > 0 ) {
			$guru_nbs = apply_filters( 'bizcity_twinbrain_graph_ask_guru_scope', null, $guru_id, $ctx );
			if ( ! is_array( $guru_nbs ) || ! in_array( $nb_id, array_map( 'intval', $guru_nbs ), true ) ) {
				return self::envelope( $nb_id, self::empty_data( 'guru_scope_empty' ), 'Guru không đọc notebook này (R-SCOPE-FOLDER).', $t0 );
			}
		}

		$top_k      = max( 1, min( self::TOP_K_MAX, (int) ( $args['top_k'] ?? 8 ) ) );
		$source_ids = array_values( array_filter( array_map( 'intval', (array) ( $args['source_ids'] ?? array() ) ), static function ( $v ) { return $v > 0; } ) );

		$retriever = BizCity_KG_Retriever::instance();
		// [2026-10-28 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 HOTFIX-T143-1b (live: cell_timeout twice on notebook #31) — PASSAGE-FIRST GraphRAG.
		// The seed step of Retriever::ask (search_entities / search_relations) loads EVERY embedding of the notebook (+ attached Gurus) from SQL and decodes it in PHP,
		// then waits on the Hub /rerank: seconds, past the cell's 8 s read. Here: passages by vector from the .bin (fast mode, S99-P19), then the graph AROUND those
		// passages — their entities (one query) and the relations between those entities (one query). Same output contract, no rerank call, no LLM.
		$res      = $retriever->ask( $nb_id, $query, array( 'answer' => false, 'fast' => true, 'rerank_top_k' => $top_k * 3 ) );
		$mode     = 'degraded_keyword' === (string) ( $res['retrieval_mode'] ?? '' ) ? 'keyword_fallback' : ( 'embed_failed' === (string) ( $res['retrieval_mode'] ?? '' ) ? 'empty' : 'vector_fallback' );
		$passages = isset( $res['passages'] ) && is_array( $res['passages'] ) ? $res['passages'] : array();

		// Step 7 — degrade: nothing from vectors ⇒ the keyword path (same hygiene), and say so.
		if ( empty( $passages ) && method_exists( $retriever, 'keyword_passages' ) ) {
			$kw = $retriever->keyword_passages( $nb_id, $query, $top_k * 2 );
			if ( $kw ) {
				$passages = array_map( static function ( $p ) {
					return array( 'id' => (int) $p['id'], 'content' => (string) $p['content'], 'source_id' => (int) $p['source_id'], 'metadata' => isset( $p['metadata'] ) ? (string) $p['metadata'] : '', 'score' => (float) ( $p['score'] ?? 0 ), 'score_source' => 'keyword' );
				}, $kw );
				$mode = 'keyword_fallback';
			}
		}

		// Chat-promoted rows (source_id <= 0) never count as evidence; a source scope narrows.
		$passages = array_values( array_filter( $passages, static function ( $p ) use ( $source_ids ) {
			$sid = (int) ( $p['source_id'] ?? 0 );
			return $sid > 0 && ( empty( $source_ids ) || in_array( $sid, $source_ids, true ) );
		} ) );

		$passages = self::local_rerank( $query, $passages );
		$passages = array_slice( $passages, 0, $top_k );

		if ( empty( $passages ) ) {
			$data          = self::empty_data( 'no_passage' );
			$data['stats'] = self::stats( $res, 0, $t0 );
			return self::envelope( $nb_id, $data, 'Không tìm thấy đoạn nào trong notebook cho câu hỏi này.', $t0 );
		}

		$entity_map = self::entity_ids_for_passages( array_map( static function ( $p ) { return (int) $p['id']; }, $passages ) );
		$titles     = self::source_titles( array_unique( array_map( static function ( $p ) { return (int) $p['source_id']; }, $passages ) ) );

		$source_map = array();
		$bytes      = 0;
		foreach ( $passages as $p ) {
			$pid     = (int) $p['id'];
			$content = self::clip( (string) $p['content'], self::PASSAGE_CHARS );
			$row     = array(
				'token'        => sprintf( '[nb:%d/p%d]', $nb_id, $pid ),
				'notebook_id'  => $nb_id,
				'passage_id'   => $pid,
				'source_id'    => (int) $p['source_id'],
				'source_title' => $titles[ (int) $p['source_id'] ] ?? '',
				'heading_path' => self::heading_path( $p['metadata'] ?? '' ),
				'content'      => $content,
				'score'        => round( (float) ( $p['score'] ?? 0 ), 4 ),
				'score_source' => (string) ( $p['score_source'] ?? 'vector' ),
				'entity_ids'   => $entity_map[ $pid ] ?? array(),
			);
			$bytes += strlen( (string) wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) ); // measured as the cell will see it (no \u escapes)
			if ( $bytes > self::PACK_BYTES_MAX && ! empty( $source_map ) ) {
				break; // the pack stays under the C-scale ceiling of the cell
			}
			$source_map[] = $row;
		}

		list( $entities, $triples ) = self::graph_around( $source_map );
		if ( 'vector_fallback' === $mode && ! empty( $triples ) ) {
			$mode = 'graph';
		}

		$data = array(
			'mode'           => $mode,
			'source_map'     => $source_map,
			'triples'        => $triples,
			'entities'       => array_values( $entities ),
			'question_seeds' => self::question_seeds( $nb_id, $query, $triples, array_values( $entities ), $source_map ),
			'stats'          => array_merge( self::stats( $res, count( $source_map ), $t0 ), array( 'seed_entities' => count( $entities ), 'seed_relations' => count( $triples ) ) ),
		);
		if ( (int) $data['stats']['took_ms'] > 4000 ) {
			error_log( '[twinbrain graph_ask] slow notebook ' . $nb_id . ': ' . (int) $data['stats']['took_ms'] . ' ms (passages ' . count( $source_map ) . ')' );
		}

		$sources = count( array_unique( array_column( $source_map, 'source_id' ) ) );
		$summary = sprintf( '%d đoạn từ %d nguồn, %d quan hệ, %d thực thể%s.', count( $source_map ), $sources, count( $triples ), count( $entities ),
			'graph' === $mode ? '; đồ thị đủ để trả lời' : '; ' . $mode );

		return self::envelope( $nb_id, $data, $summary, $t0 );
	}

	/* ───────────────────────── helpers ───────────────────────── */

	private static function envelope( int $nb_id, array $data, string $summary, float $t0 ): array {
		return array(
			'summary' => $summary,
			'data'    => $data,
			'receipt' => array( 'tool' => 'brain.graph_ask', 'contract' => self::CONTRACT, 'notebook_id' => $nb_id, 'took_ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ) ),
		);
	}

	public static function empty_data( string $reason = 'no_passage' ): array {
		return array(
			'mode' => 'empty', 'reason' => $reason, 'source_map' => array(), 'triples' => array(), 'entities' => array(), 'question_seeds' => array(),
			'stats' => array( 'seed_entities' => 0, 'seed_relations' => 0, 'expanded' => 0, 'reranked' => 0, 'took_ms' => 0, 'bin' => 'ok' ),
		);
	}

	private static function stats( array $res, int $reranked, float $t0 ): array {
		$d   = isset( $res['retrieval_detail'] ) && is_array( $res['retrieval_detail'] ) ? $res['retrieval_detail'] : array();
		$sub = isset( $res['subgraph'] ) && is_array( $res['subgraph'] ) ? $res['subgraph'] : array();
		$rm  = (string) ( $res['retrieval_mode'] ?? '' );
		return array(
			'seed_entities'  => count( (array) ( $d['entity_ids'] ?? array() ) ),
			'seed_relations' => count( (array) ( $d['relation_ids'] ?? array() ) ),
			'expanded'       => count( (array) ( $sub['links'] ?? array() ) ),
			'reranked'       => $reranked,
			'took_ms'        => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
			'bin'            => in_array( $rm, array( 'degraded_keyword', 'embed_failed' ), true ) ? 'missing' : 'ok',
		);
	}

	/** Token overlap + stored score (port of w020_rerank_candidates, no LLM); stable for equal ranks. */
	public static function local_rerank( string $query, array $passages ): array {
		if ( empty( $passages ) ) { return array(); }
		$q = self::tokens( $query );
		foreach ( $passages as $i => $p ) {
			$hay = self::tokens( (string) ( $p['content'] ?? '' ) );
			$hit = $q ? count( array_intersect( $q, $hay ) ) / max( 1, count( $q ) ) : 0.0;
			$passages[ $i ]['_rk'] = ( (float) ( $p['score'] ?? 0 ) ) * 0.6 + $hit * 0.4;
			$passages[ $i ]['_ix'] = $i;
		}
		usort( $passages, static function ( $a, $b ) {
			$c = $b['_rk'] <=> $a['_rk'];
			return 0 !== $c ? $c : $a['_ix'] <=> $b['_ix'];
		} );
		foreach ( $passages as $i => $p ) { unset( $passages[ $i ]['_rk'], $passages[ $i ]['_ix'] ); }
		return $passages;
	}

	private static function tokens( string $s ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $s, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $parts as $t ) {
			if ( mb_strlen( $t, 'UTF-8' ) >= 2 ) { $out[ $t ] = true; }
		}
		return array_keys( $out );
	}

	private static function clip( string $s, int $max ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return mb_strlen( $s, 'UTF-8' ) > $max ? mb_substr( $s, 0, $max - 1, 'UTF-8' ) . '…' : $s;
	}

	/** @return string[] */
	private static function heading_path( $metadata ): array {
		$m = is_array( $metadata ) ? $metadata : json_decode( (string) $metadata, true );
		if ( ! is_array( $m ) ) { return array(); }
		$h = $m['heading_path'] ?? $m['headings'] ?? array();
		if ( is_string( $h ) ) { $h = array_map( 'trim', explode( '>', $h ) ); }
		return array_values( array_filter( array_map( 'strval', (array) $h ) ) );
	}

	/** passage_id ⇒ entity_id[] in ONE query (port of resolve_cited_entity_ids, computed up front). */
	private static function entity_ids_for_passages( array $pids ): array {
		global $wpdb;
		$pids = array_values( array_unique( array_filter( array_map( 'intval', $pids ) ) ) );
		if ( empty( $pids ) || ! isset( $wpdb ) ) { return array(); }
		$tbl  = BizCity_KG_Database::instance()->tbl_passage_entities();
		$in   = implode( ',', $pids );
		$rows = $wpdb->get_results( "SELECT passage_id, entity_id FROM {$tbl} WHERE passage_id IN ({$in})", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['passage_id'] ][] = (int) $r['entity_id'];
		}
		foreach ( $out as $k => $v ) { $out[ $k ] = array_values( array_unique( $v ) ); }
		return $out;
	}

	private static function source_titles( array $sids ): array {
		global $wpdb;
		$sids = array_values( array_filter( array_map( 'intval', $sids ) ) );
		if ( empty( $sids ) || ! isset( $wpdb ) ) { return array(); }
		$tbl  = BizCity_KG_Database::instance()->tbl_sources();
		$in   = implode( ',', $sids );
		$rows = $wpdb->get_results( "SELECT id, title FROM {$tbl} WHERE id IN ({$in})", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) { $out[ (int) $r['id'] ] = (string) $r['title']; }
		return $out;
	}

	/**
	 * The graph AROUND the answer passages: the entities they mention (one query, those cited first by weight) and the approved relations between those
	 * entities (one query, heaviest first). @return array{0: array<int,array>, 1: array} [entities keyed by id, triples]
	 */
	private static function graph_around( array $source_map ): array {
		global $wpdb;
		$ids = array();
		foreach ( $source_map as $row ) {
			foreach ( $row['entity_ids'] as $e ) { $ids[ (int) $e ] = true; }
		}
		$ids = array_slice( array_keys( $ids ), 0, 60 );
		if ( empty( $ids ) || ! isset( $wpdb ) ) {
			return array( array(), array() );
		}
		$db   = BizCity_KG_Database::instance();
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$ents = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, name, type, weight FROM {$db->tbl_entities()} WHERE id IN ({$in}) AND deleted_at IS NULL ORDER BY weight DESC LIMIT 24", ARRAY_A ) as $e ) {
			$ents[ (int) $e['id'] ] = array( 'id' => (int) $e['id'], 'name' => (string) $e['name'], 'type' => (string) $e['type'], 'weight' => (int) $e['weight'] );
		}
		$triples = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, head_entity_id, tail_entity_id, predicate FROM {$db->tbl_relations()} WHERE head_entity_id IN ({$in}) AND tail_entity_id IN ({$in}) AND status = 'approved' AND deleted_at IS NULL ORDER BY weight DESC LIMIT 12", ARRAY_A ) as $r ) {
			$h = (int) $r['head_entity_id'];
			$t = (int) $r['tail_entity_id'];
			if ( ! isset( $ents[ $h ], $ents[ $t ] ) ) {
				continue;
			}
			$triples[] = array( 'relation_id' => (int) $r['id'], 'head' => $ents[ $h ]['name'], 'predicate' => (string) $r['predicate'], 'tail' => $ents[ $t ]['name'], 'head_id' => $h, 'tail_id' => $t, 'text' => '' );
		}
		return array( $ents, $triples );
	}

	/** 3–6 template questions, no LLM: skeleton key points not already asked + relations not already in the answer. */
	public static function question_seeds( int $nb_id, string $query, array $triples, array $entities = array(), array $source_map = array() ): array {
		$seeds = array();
		$asked = mb_strtolower( $query, 'UTF-8' );
		if ( class_exists( 'BizCity_KG_Skeleton_Adapter' ) && method_exists( 'BizCity_KG_Skeleton_Adapter', 'get_skeleton' ) ) {
			$sk = BizCity_KG_Skeleton_Adapter::get_skeleton( $nb_id );
			foreach ( (array) ( is_array( $sk ) ? ( $sk['key_points'] ?? array() ) : array() ) as $kp ) {
				$kp = trim( is_array( $kp ) ? (string) ( $kp['text'] ?? '' ) : (string) $kp );
				if ( '' !== $kp && false === mb_strpos( $asked, mb_strtolower( $kp, 'UTF-8' ) ) ) {
					$seeds[] = mb_strlen( $kp, 'UTF-8' ) > 90 ? mb_substr( $kp, 0, 89, 'UTF-8' ) . '…' : $kp . ( '?' === substr( $kp, -1 ) ? '' : '?' );
				}
				if ( count( $seeds ) >= 3 ) { break; }
			}
		}
		foreach ( $triples as $t ) {
			if ( '' === $t['head'] || '' === $t['tail'] ) { continue; }
			$seeds[] = sprintf( '%s %s %s như thế nào?', $t['head'], $t['predicate'], $t['tail'] );
			if ( count( $seeds ) >= self::SEEDS_MAX ) { break; }
		}
		// [2026-10-27 11:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 (live T143-1: no chips) — a notebook without skeleton key points and without selected relations still gets
		// chips: the entities the answer passages mention (not already in the question), then the source titles. Templates only, no LLM.
		foreach ( $entities as $e ) {
			if ( count( $seeds ) >= 4 ) { break; }
			$name = trim( (string) ( $e['name'] ?? '' ) );
			if ( mb_strlen( $name, 'UTF-8' ) >= 3 && false === mb_strpos( $asked, mb_strtolower( $name, 'UTF-8' ) ) ) {
				$seeds[] = sprintf( '%s là gì và liên quan thế nào tới câu hỏi này?', $name );
			}
		}
		foreach ( $source_map as $p ) {
			if ( count( $seeds ) >= 4 ) { break; }
			$t = trim( (string) ( $p['source_title'] ?? '' ) );
			if ( '' !== $t ) {
				$seeds[] = sprintf( 'Tài liệu "%s" còn nói gì khác?', mb_substr( $t, 0, 80, 'UTF-8' ) );
			}
		}
		return array_slice( array_values( array_unique( $seeds ) ), 0, self::SEEDS_MAX );
	}
}
