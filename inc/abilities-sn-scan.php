<?php
/**
 * Signal & Noise Tools — Abilities API: sn_scan (MCP consolidation, session 4).
 *
 * Consolidated read-only scan tool absorbing five existing scan abilities
 * PLUS a sixth source (orphan media, previously reachable only through the
 * AI-gated ai-orphan-suggest path — see the orphan_media adapter for why a
 * pure-SQL detector qualified):
 *
 *   block-migrations-scan   → snt_block_migrations_run_scan()      (structural)
 *   pattern-adoption-scan   → snt_pattern_adoption_run_scan()      (structural)
 *   duplicate-body-scan     → snt_corpus_duplicate_scan()          (structural)
 *   near-duplicate-scan     → snt_ml_cousin_pairs()                (ML kernel)
 *   link-candidates         → snt_ml_link_candidates() (fanned out)(ML kernel)
 *   orphan_media (NEW)      → sn_health_check_orphaned_media()     (structural)
 *
 * NEW ALONGSIDE OLD: every absorbed ability stays registered and untouched.
 * ~/.claude/session-data/SN-MCP-new/sn-scan-spec.md is the contract this
 * file implements (deterministic candidate IDs, targets[]/evidence/
 * apply_hint shape, confidence-DESC/candidate_id-ASC ordering, opaque
 * cursor pagination). docs/mcp-consolidation/FINDINGS.md's session-4
 * section records every deviation from the spec and why.
 *
 * THIN WRAPPER, not an ability-to-ability proxy: every adapter in
 * inc/sn-scan-adapters.php calls the SAME internal PHP functions the five
 * absorbed abilities call (snt_block_migrations_run_scan(),
 * snt_pattern_adoption_run_scan(), snt_corpus_duplicate_scan(),
 * snt_ml_cousin_pairs(), snt_ml_link_candidates(),
 * sn_health_check_orphaned_media()) — never wp_get_ability() — so reshaping
 * output into the sn_scan envelope costs no double-marshalling. Two of the
 * six sources (near_duplicate, link_candidates) share inc/ml-kernel.php's
 * TF-IDF/cosine primitives (the ML-kernel family); the other four are pure
 * structural detectors that never touch the kernel — the "two scan
 * families" finding from FINDINGS.md #5.
 *
 * Zero AI anywhere in this file or its adapters. sn_scan itself never
 * calls a model — the orphan_media adapter deliberately wraps the SQL
 * detector (sn_health_check_orphaned_media), never ai-orphan-suggest.
 *
 * @package SignalNoiseTools
 * @since 10.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_SN_SCAN_TYPES = array(
	'block_migrations',
	'pattern_adoption',
	'duplicate_body',
	'near_duplicate',
	'link_candidates',
	'orphan_media',
	// v10.52.1: emdash shipped in v10.51.0 with an adapter but WITHOUT this entry,
	// so the ability rejected scan_type:"emdash" before dispatch and the feature was
	// unreachable. tests/abilities-sn-scan.php now pins this list against
	// snt_sn_scan_adapters() in both directions.
	'emdash',
	// v10.58.0: two binary anchor rules (anchor text == its full sentence;
	// any <a> inside an h1–h6) — see inc/sn-scan-anchor-violations.php.
	'anchor_violations',
);

const SNT_SN_SCAN_DEFAULT_MAX = 50;
const SNT_SN_SCAN_MAX_CAP     = 200; // max_candidates clamps silently to this ceiling.

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-scan', array(
		'label'               => 'Scan the corpus for actionable candidates (consolidated)',
		'description'         => 'Consolidated read-only scan, absorbing block-migrations-scan, pattern-adoption-scan, duplicate-body-scan, near-duplicate-scan, and link-candidates, plus a sixth source (orphan_media, a pure-SQL detector previously reachable only via the AI-gated ai-orphan-suggest path). scan_type takes exactly ONE value per call — cost profiles differ by an order of magnitude (near_duplicate is O(n^2) pair comparison; block_migrations/pattern_adoption/duplicate_body/orphan_media are O(n) walks; link_candidates is O(n) artifact reads) and a caller should feel that rather than have it hidden behind a convenience flag. scan_type "anchor_violations" applies two BINARY link rules over the corpus (scheduled posts included): anchor_equals_sentence (an <a>\'s anchor text is identical to the full sentence containing it, terminal punctuation ignored) and heading_contains_link (any <a> inside an h1–h6; a heading wrapped entirely in a link reports under this rule only, never both). No thresholds and deliberately NO anchor/sentence length ratio — a ratio punishes short sentences, which are intentional in the house voice. No apply path yet: apply_hint is null, violations are fixed in the editor. candidate_id = sha256(scan_type + target identity + content fingerprint), hex, stable across runs on unchanged content — dismissal and diffing both depend on this. Ordering is always confidence DESC, candidate_id ASC; two runs against unchanged content return byte-identical candidate lists. freshness reports what actually happened: block_migrations/pattern_adoption/duplicate_body/near_duplicate/orphan_media always recompute live (no cache exists to read), so freshness is always "fresh" regardless of what was requested; link_candidates reads a prebuilt artifact and can never honor freshness:"fresh" without writing (this tool writes nothing, ever), so it always reports "cached". dismissed is always false: block_migrations/pattern_adoption\'s underlying detectors already exclude dismissed candidates before this tool ever sees them (the existing dismiss-candidate store), and the other four sources have no dismissal store at all yet (sn_dismiss is a later phase) — include_dismissed is accepted but has no effect on any scan_type today. apply_hint names the next-step apply tool with its required_args where a direct, fingerprint-safe apply path exists (block-migrations-apply, pattern-adoption-apply, ai-orphan-apply); null for duplicate_body and near_duplicate (no apply path) and for link_candidates (its only apply path, ai-link-apply, validates a positional fingerprint that only an AI-mediated suggest call can produce — wiring it here would fail the fingerprint-parity guarantee, so it is deliberately null; see FINDINGS.md).',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_sn_scan',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'scan_type' ),
			'properties'           => array(
				'scan_type'         => array(
					'type' => 'string',
					'enum' => SNT_SN_SCAN_TYPES,
				),
				'scope'             => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind'           => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'post_ids', 'modified_since' ),
							'default' => 'all',
						),
						'post_ids'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
						'modified_since' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'freshness'         => array(
					'type'    => 'string',
					'enum'    => array( 'cached', 'fresh' ),
					'default' => 'cached',
				),
				'include_dismissed' => array( 'type' => 'boolean', 'default' => false ),
				'max_candidates'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => SNT_SN_SCAN_MAX_CAP, 'default' => SNT_SN_SCAN_DEFAULT_MAX ),
				'cursor'            => array( 'type' => array( 'string', 'null' ), 'default' => null ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'scan_type'    => array( 'type' => 'string' ),
				'scan_run_id'  => array( 'type' => 'string' ),
				'generated_at' => array( 'type' => 'string' ),
				'freshness'    => array( 'type' => 'string' ),
				'corpus_state' => array(
					'type'       => 'object',
					'properties' => array(
						'posts_examined'     => array( 'type' => 'integer' ),
						'posts_skipped'      => array( 'type' => 'integer' ),
						'corpus_fingerprint' => array( 'type' => 'string' ),
					),
				),
				'candidates'   => array( 'type' => 'array' ),
				'total_candidates' => array( 'type' => 'integer' ),
				'nextCursor'   => array( 'type' => array( 'string', 'null' ) ),
				'truncated'    => array( 'type' => 'boolean' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/* ════════════════════════════════════════════════════════════════════════
 * Cursor codec — opaque base64(offset), same idiom as inc/abilities-sn-posts.php
 * (the spec names no concrete encoding, only that a cursor exists).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int $offset
 * @return string
 */
function snt_sn_scan_encode_cursor( $offset ) {
	return base64_encode( (string) max( 0, (int) $offset ) );
}

/**
 * @param mixed $cursor
 * @return int|null Offset, or null when the cursor is present but malformed.
 */
function snt_sn_scan_decode_cursor( $cursor ) {
	if ( null === $cursor || '' === $cursor ) {
		return 0;
	}
	if ( ! is_string( $cursor ) ) {
		return null;
	}
	$decoded = base64_decode( $cursor, true );
	if ( false === $decoded || '' === $decoded || 1 !== preg_match( '/^\d+$/', $decoded ) ) {
		return null;
	}
	return (int) $decoded;
}

/* ════════════════════════════════════════════════════════════════════════
 * Scope resolution — shared across all six scan_types. Returns null for
 * scope.kind "all" (no restriction), an int[] of allowed target identities
 * (post IDs for the five post-backed types, attachment IDs for
 * orphan_media) for "post_ids"/"modified_since", or a WP_Error.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $message
 * @return WP_Error
 */
function snt_sn_scan_scope_error( $message ) {
	return new WP_Error( 'snt_scan_bad_scope', $message, array( 'status' => 422 ) );
}

/**
 * @param array  $scope
 * @param string $scan_type
 * @return array|null|WP_Error
 */
function snt_sn_scan_resolve_scope( $scope, $scan_type ) {
	$kind = isset( $scope['kind'] ) ? (string) $scope['kind'] : 'all';
	if ( ! in_array( $kind, array( 'all', 'post_ids', 'modified_since' ), true ) ) {
		return snt_sn_scan_scope_error( __( 'scope.kind must be one of: all, post_ids, modified_since.', 'signal-and-noise-tools' ) );
	}

	if ( 'all' === $kind ) {
		return null;
	}

	if ( 'post_ids' === $kind ) {
		$ids = isset( $scope['post_ids'] ) ? array_values( array_unique( array_map( 'intval', (array) $scope['post_ids'] ) ) ) : array();
		if ( empty( $ids ) ) {
			return snt_sn_scan_scope_error( __( 'scope.kind "post_ids" requires a non-empty scope.post_ids array.', 'signal-and-noise-tools' ) );
		}
		return $ids;
	}

	// 'modified_since': walk the relevant corpus (posts for the five
	// post-backed types, image attachments for orphan_media) and resolve to
	// the set of IDs whose modified/date timestamp is >= the parsed date.
	$since_raw = isset( $scope['modified_since'] ) ? (string) $scope['modified_since'] : '';
	$since_ts  = '' !== $since_raw ? strtotime( $since_raw ) : false;
	if ( false === $since_ts ) {
		return snt_sn_scan_scope_error( __( 'scope.kind "modified_since" requires a parseable scope.modified_since date/time string.', 'signal-and-noise-tools' ) );
	}

	if ( 'orphan_media' === $scan_type ) {
		if ( ! function_exists( 'snt_corpus_post_type_allowed' ) ) {
			return snt_sn_scan_scope_error( __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ) );
		}
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND post_date_gmt >= %s",
			gmdate( 'Y-m-d H:i:s', $since_ts )
		) );
		return array_map( 'intval', (array) $rows );
	}

	if ( ! function_exists( 'snt_corpus_fetch_posts' ) ) {
		return snt_sn_scan_scope_error( __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ) );
	}
	$posts = snt_corpus_fetch_posts( 'any', 'post' );
	$ids   = array();
	foreach ( $posts as $p ) {
		$mts = strtotime( (string) ( $p->post_modified ?? '' ) );
		if ( false !== $mts && $mts >= $since_ts ) {
			$ids[] = (int) $p->ID;
		}
	}
	return $ids;
}

/* ════════════════════════════════════════════════════════════════════════
 * Freshness — per scan_type, what "cached" vs "fresh" actually means. See
 * the ability description for the full rationale.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $scan_type
 * @return string 'fresh' (always-live sources) or 'cached' (artifact-backed).
 */
function snt_sn_scan_actual_freshness( $scan_type ) {
	return 'link_candidates' === $scan_type ? 'cached' : 'fresh';
}

/* ════════════════════════════════════════════════════════════════════════
 * Candidate ID + ordering.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * candidate_id = sha256(scan_type + target identity + content fingerprint),
 * hex. Content-derived, never a per-run random value — this is the spec's
 * non-negotiable core design decision.
 *
 * @param string $scan_type
 * @param string $target_identity
 * @param string $content_fingerprint
 * @return string 64-char hex.
 */
function snt_sn_scan_candidate_id( $scan_type, $target_identity, $content_fingerprint ) {
	return hash( 'sha256', $scan_type . '|' . $target_identity . '|' . $content_fingerprint );
}

/**
 * @param array $candidates List of assembled candidate rows (each has candidate_id + confidence).
 * @return array Sorted confidence DESC, candidate_id ASC.
 */
function snt_sn_scan_sort_candidates( $candidates ) {
	usort( $candidates, static function ( $a, $b ) {
		$by_conf = $b['confidence'] <=> $a['confidence'];
		return 0 !== $by_conf ? $by_conf : strcmp( $a['candidate_id'], $b['candidate_id'] );
	} );
	return $candidates;
}

/* ════════════════════════════════════════════════════════════════════════
 * Execute
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Ability execute callback: signal-noise/sn-scan. Thin observability wrapper
 * (v10.60.0): runs the real impl, then fires `sn_scan_completed` with a
 * per-run metrics array covering BOTH outcomes — success and error alike
 * (the telemetry-agents seam-2 lesson: a success-only observer silently
 * under-reports the failure rate to ~0%). Firing an action is NOT a write:
 * the ability itself stays pure (the zero-writes structural guard in
 * tests/abilities-sn-scan.php still holds — no listener is registered
 * there); the production listener that persists rows lives in
 * inc/sn-scan-telemetry.php, the same observer split the provenance
 * commit hook (`sn_prov_committed`) and the agent-telemetry bridge use.
 *
 * @param array|null $input
 * @return array|WP_Error
 */
function snt_ability_sn_scan( $input ) {
	$t0     = microtime( true );
	$result = snt_ability_sn_scan_impl( $input );

	if ( function_exists( 'do_action' ) && function_exists( 'snt_sn_scan_run_metrics' ) ) {
		/**
		 * Fires after every sn_scan execution, success or failure, with the
		 * full per-run measurement row. See snt_sn_scan_run_metrics() for
		 * the exact fields.
		 *
		 * @param array $metrics
		 */
		do_action( 'sn_scan_completed', snt_sn_scan_run_metrics( is_array( $input ) ? $input : array(), $result, $t0 ) );
	}

	return $result;
}

/**
 * The real sn_scan implementation — everything above the observability
 * wrapper is unchanged from pre-v10.60.0 behavior except the additive
 * `total_candidates` envelope field (the full sorted count BEFORE
 * pagination; previously a caller could never know the total without
 * walking every page, and the metrics row needs it).
 *
 * @param array|null $input Validated (defensively, not schema-enforced — house
 *                          convention, see inc/abilities-sn-posts.php) against
 *                          input_schema above.
 * @return array|WP_Error
 */
function snt_ability_sn_scan_impl( $input ) {
	$input = is_array( $input ) ? $input : array();

	$scan_type = isset( $input['scan_type'] ) ? (string) $input['scan_type'] : '';
	if ( ! in_array( $scan_type, SNT_SN_SCAN_TYPES, true ) ) {
		return new WP_Error(
			'snt_scan_bad_type',
			sprintf(
				/* translators: %s: comma-separated list of valid scan_type values. */
				__( 'scan_type must be one of: %s.', 'signal-and-noise-tools' ),
				implode( ', ', SNT_SN_SCAN_TYPES )
			),
			array( 'status' => 422 )
		);
	}

	$freshness = isset( $input['freshness'] ) ? (string) $input['freshness'] : 'cached';
	if ( ! in_array( $freshness, array( 'cached', 'fresh' ), true ) ) {
		return new WP_Error( 'snt_scan_bad_freshness', __( 'freshness must be "cached" or "fresh".', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$scope = is_array( $input['scope'] ?? null ) ? $input['scope'] : array();
	$allowed_ids = snt_sn_scan_resolve_scope( $scope, $scan_type );
	if ( is_wp_error( $allowed_ids ) ) {
		return $allowed_ids;
	}

	$offset = snt_sn_scan_decode_cursor( $input['cursor'] ?? null );
	if ( null === $offset ) {
		return new WP_Error( 'snt_scan_bad_cursor', __( 'cursor is malformed.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$max = isset( $input['max_candidates'] ) && is_numeric( $input['max_candidates'] ) ? (int) $input['max_candidates'] : SNT_SN_SCAN_DEFAULT_MAX;
	$max = max( 1, min( $max, SNT_SN_SCAN_MAX_CAP ) ); // Clamp, never reject.

	$include_dismissed = ! empty( $input['include_dismissed'] ); // Accepted; currently inert everywhere — see description.

	if ( ! function_exists( 'snt_sn_scan_adapters' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Scan adapters module not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$adapters = snt_sn_scan_adapters();
	if ( ! isset( $adapters[ $scan_type ] ) || ! is_callable( $adapters[ $scan_type ] ) ) {
		return new WP_Error( 'snt_scan_unknown_type', __( 'No adapter registered for this scan_type.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}

	$raw = call_user_func( $adapters[ $scan_type ], $allowed_ids );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$posts_examined = (int) ( $raw['posts_examined'] ?? 0 );
	$posts_skipped  = (int) ( $raw['posts_skipped'] ?? 0 );
	$truncated_scan = ! empty( $raw['truncated'] );
	$raw_candidates = is_array( $raw['candidates'] ?? null ) ? $raw['candidates'] : array();

	$candidates = array();
	foreach ( $raw_candidates as $c ) {
		$cid = snt_sn_scan_candidate_id( $scan_type, (string) $c['target_identity'], (string) $c['content_fingerprint'] );
		$candidates[] = array(
			'candidate_id' => $cid,
			'targets'      => (array) ( $c['targets'] ?? array() ),
			'confidence'   => round( (float) ( $c['confidence'] ?? 0.0 ), 4 ),
			'evidence'     => (array) ( $c['evidence'] ?? array() ),
			'apply_hint'   => $c['apply_hint'] ?? null,
			// Always false: block_migrations/pattern_adoption's underlying
			// detectors already exclude dismissed candidates before this
			// tool ever sees them; the other four sources have no dismiss
			// store yet. include_dismissed is accepted but inert.
			'dismissed'    => false,
		);
	}

	if ( ! $include_dismissed ) {
		$candidates = array_values( array_filter( $candidates, static function ( $c ) {
			return false === $c['dismissed'];
		} ) );
	}

	$candidates = snt_sn_scan_sort_candidates( $candidates );

	// corpus_fingerprint/scan_run_id are derived from the FULL sorted set
	// (before pagination) so every page of the same scan reports the same
	// value, and a rerun against unchanged content reproduces both exactly
	// — never from time()/random. This is a spec REFINEMENT (session-4):
	// the spec places generated_at/scan_run_id at the envelope level but
	// doesn't say scan_run_id must itself be content-derived; making it so
	// is what lets two runs be byte-identical on the fields that matter.
	$all_ids            = array_column( $candidates, 'candidate_id' );
	$corpus_fingerprint = hash( 'sha256', $scan_type . '|' . $posts_examined . '|' . $posts_skipped . '|' . implode( ',', $all_ids ) );
	$scan_run_id        = hash( 'sha256', $scan_type . '|' . $corpus_fingerprint );

	$total = count( $candidates );
	$page  = array_slice( $candidates, max( 0, $offset ), $max );

	$next_offset = $offset + count( $page );
	$has_more    = $next_offset < $total;

	return array(
		'scan_type'        => $scan_type,
		'scan_run_id'      => $scan_run_id,
		'generated_at'     => gmdate( 'c' ),
		'freshness'        => snt_sn_scan_actual_freshness( $scan_type ),
		'corpus_state'     => array(
			'posts_examined'     => $posts_examined,
			'posts_skipped'      => $posts_skipped,
			'corpus_fingerprint' => $corpus_fingerprint,
		),
		'candidates'       => $page,
		// v10.60.0, additive: the full sorted count BEFORE pagination — a
		// caller previously could not know this without walking every page.
		'total_candidates' => $total,
		'nextCursor'       => $has_more ? snt_sn_scan_encode_cursor( $next_offset ) : null,
		'truncated'        => $truncated_scan,
	);
}
