<?php
/**
 * Signal & Noise Tools — Abilities API: sn_posts (MCP consolidation, session 3).
 *
 * Consolidated tool absorbing `signal-noise/list-posts` +
 * `signal-noise/get-post-content` (~/.claude/session-data/SN-MCP-new/
 * sn-remaining-specs.md, sn_posts section). NEW ALONGSIDE OLD: neither
 * absorbed ability is touched, unregistered, or deleted — both stay live on
 * the read door. Registered as its OWN slug, `signal-noise/sn-posts`
 * (deviation from the spec's bare `sn_posts` — see the file docblock in
 * inc/abilities-sn-site-facts.php for the shared naming rationale).
 *
 * Deliberately THIN: every query/row primitive is reused verbatim from
 * inc/corpus-inspect.php (snt_corpus_fetch_posts, snt_corpus_post_row,
 * snt_corpus_post_type_allowed, snt_corpus_post_type_error,
 * SNT_CORPUS_STATUSES, SNT_CORPUS_MAX_CONTENT_IDS) — this file adds only the
 * scope-union dispatch, cursor pagination, and the reject-never-truncate
 * content cap on top of them. Same visibility as the tools it absorbs: all
 * five non-trash statuses (pre-publish collision checking is the point),
 * double-gated by manage_options + the MCP door's own auth.
 *
 * Cursor: the spec (sn-mcp-consolidation.md's "shared conventions") never
 * defines a concrete cursor ENCODING, only that one exists — an opaque
 * base64(offset) is used here (see snt_sn_posts_encode_cursor /
 * _decode_cursor). Noted as a deviation-by-necessity in FINDINGS.md.
 *
 * @package SignalNoiseTools
 * @since 10.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_SN_POSTS_DEFAULT_MAX = 100;
const SNT_SN_POSTS_MAX_CAP     = 100; // `max` clamps silently to this ceiling — not a reject (only include_content's cap rejects).

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-posts', array(
		'label'               => 'List or fetch corpus posts (consolidated)',
		'description'         => 'Consolidated post query, absorbing list-posts (metadata) and get-post-content (bodies) into one record: content is an opt-in FIELD, not a different shape. scope selects the target set: {kind:"all"} (default) walks every non-trash post of scope.post_type (default "post"); {kind:"post_ids", post_ids:[...]} fetches a bounded ID set, unknown/trashed IDs reported in `missing` rather than silently dropped; {kind:"modified_since", modified_since:"<date>"} walks posts modified at/after that date, newest-modified first; {kind:"post_type", post_type:"<type>"} walks one specific registered+public type. include_content:true attaches full post_content per row but is REJECTED (422, never silently truncated) when the resolved scope exceeds 20 posts — narrow the scope instead. Paginated via an opaque cursor (max default/cap 100 per page); the post_ids scope always returns its whole bounded set in one page. Same visibility as list-posts/get-post-content: all five non-trash statuses (publish/future/draft/pending/private) across registered public types, gated by manage_options + the MCP door\'s own auth.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_sn_posts',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ), // bodyless GET delivers null; every field has a safe default.
			'properties'           => array(
				'scope'           => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind'           => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'post_ids', 'modified_since', 'post_type' ),
							'default' => 'all',
						),
						'post_ids'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
						'modified_since' => array( 'type' => 'string' ),
						'post_type'      => array( 'type' => 'string', 'default' => 'post' ),
					),
					'additionalProperties' => false,
				),
				'include_content' => array( 'type' => 'boolean', 'default' => false ),
				'cursor'          => array( 'type' => array( 'string', 'null' ), 'default' => null ),
				'max'             => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => SNT_SN_POSTS_MAX_CAP, 'default' => SNT_SN_POSTS_DEFAULT_MAX ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'posts'    => array( 'type' => 'array' ),
				'count'    => array( 'type' => 'integer' ),
				'missing'  => array( 'type' => 'array' ),
				'cursor'   => array( 'type' => array( 'string', 'null' ) ),
				'has_more' => array( 'type' => 'boolean' ),
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
 * Cursor codec — opaque base64(offset). See file docblock for why this
 * encoding (the spec names no concrete scheme).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int $offset
 * @return string
 */
function snt_sn_posts_encode_cursor( $offset ) {
	return base64_encode( (string) max( 0, (int) $offset ) );
}

/**
 * Decode a cursor into an offset. Absent/null/empty cursor means "start from
 * the top" (offset 0). A malformed cursor is distinguished (null return) so
 * the caller can 422 rather than silently restart the walk.
 *
 * @param mixed $cursor
 * @return int|null Offset, or null when the cursor is present but malformed.
 */
function snt_sn_posts_decode_cursor( $cursor ) {
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
 * Execute
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Shared 422 for a malformed/invalid scope.
 *
 * @param string $message
 * @return WP_Error
 */
function snt_sn_posts_scope_error( $message ) {
	return new WP_Error( 'snt_posts_bad_scope', $message, array( 'status' => 422 ) );
}

/**
 * The scope='post_ids' path: a bounded, unpaginated fetch (mirrors
 * get-post-content's semantics exactly — "give me exactly these IDs").
 *
 * @param array $scope
 * @param bool  $include_content
 * @return array|WP_Error
 */
function snt_sn_posts_resolve_post_ids( $scope, $include_content ) {
	$ids = isset( $scope['post_ids'] ) ? array_values( array_unique( array_map( 'intval', (array) $scope['post_ids'] ) ) ) : array();
	if ( empty( $ids ) ) {
		return snt_sn_posts_scope_error( __( 'scope.kind "post_ids" requires a non-empty scope.post_ids array.', 'signal-and-noise-tools' ) );
	}
	if ( $include_content && count( $ids ) > SNT_CORPUS_MAX_CONTENT_IDS ) {
		return new WP_Error(
			'snt_posts_content_cap_exceeded',
			sprintf(
				/* translators: %d: maximum posts per include_content:true call. */
				__( 'include_content:true is capped at %d posts; this scope resolves to more. Narrow scope.post_ids instead of relying on pagination.', 'signal-and-noise-tools' ),
				SNT_CORPUS_MAX_CONTENT_IDS
			),
			array( 'status' => 422 )
		);
	}

	$rows    = array();
	$missing = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post
			|| ! in_array( (string) ( $post->post_status ?? '' ), SNT_CORPUS_STATUSES, true )
			|| ! snt_corpus_post_type_allowed( (string) ( $post->post_type ?? '' ) ) ) {
			$missing[] = $id;
			continue;
		}
		$row = snt_corpus_post_row( $post );
		if ( $include_content ) {
			$row['content'] = (string) ( $post->post_content ?? '' );
		}
		$rows[] = $row;
	}

	return array(
		'ok'       => true,
		'posts'    => $rows,
		'count'    => count( $rows ),
		'missing'  => $missing,
		'cursor'   => null,
		'has_more' => false,
	);
}

/**
 * The scope='all'|'post_type'|'modified_since' path: walk the corpus via
 * snt_corpus_fetch_posts (REUSED verbatim), optionally filter by
 * modified_since, then paginate deterministically.
 *
 * @param string $kind
 * @param array  $scope
 * @param bool   $include_content
 * @param int    $offset
 * @param int    $max
 * @return array|WP_Error
 */
function snt_sn_posts_resolve_walk( $kind, $scope, $include_content, $offset, $max ) {
	$post_type = isset( $scope['post_type'] ) && '' !== trim( (string) $scope['post_type'] ) ? (string) $scope['post_type'] : 'post';
	if ( 'post_type' === $kind && ( ! isset( $scope['post_type'] ) || '' === trim( (string) $scope['post_type'] ) ) ) {
		return snt_sn_posts_scope_error( __( 'scope.kind "post_type" requires a non-empty scope.post_type.', 'signal-and-noise-tools' ) );
	}
	if ( ! snt_corpus_post_type_allowed( $post_type ) ) {
		return snt_corpus_post_type_error(); // Reused verbatim from inc/corpus-inspect.php.
	}

	$posts = snt_corpus_fetch_posts( 'any', $post_type ); // Reused verbatim.

	if ( 'modified_since' === $kind ) {
		$since_raw = isset( $scope['modified_since'] ) ? (string) $scope['modified_since'] : '';
		$since_ts  = '' !== $since_raw ? strtotime( $since_raw ) : false;
		if ( false === $since_ts ) {
			return snt_sn_posts_scope_error( __( 'scope.kind "modified_since" requires a parseable scope.modified_since date/time string.', 'signal-and-noise-tools' ) );
		}
		$posts = array_values( array_filter( $posts, function( $p ) use ( $since_ts ) {
			$mts = strtotime( (string) ( $p->post_modified ?? '' ) );
			return false !== $mts && $mts >= $since_ts;
		} ) );
		// Deterministic ordering (house rule: never database order): newest
		// modified first, ID DESC as a tie-break so byte-identical output
		// survives two runs against unchanged content.
		usort( $posts, function( $a, $b ) {
			$am = strtotime( (string) ( $a->post_modified ?? '' ) );
			$bm = strtotime( (string) ( $b->post_modified ?? '' ) );
			return $am === $bm ? ( (int) $b->ID <=> (int) $a->ID ) : ( $bm <=> $am );
		} );
	} else {
		// snt_corpus_fetch_posts already orders by date DESC; add the same ID
		// DESC tie-break for two posts sharing a post_date.
		usort( $posts, function( $a, $b ) {
			$ad = strtotime( (string) ( $a->post_date ?? '' ) );
			$bd = strtotime( (string) ( $b->post_date ?? '' ) );
			return $ad === $bd ? ( (int) $b->ID <=> (int) $a->ID ) : ( $bd <=> $ad );
		} );
	}

	$total = count( $posts );
	if ( $include_content && $total > SNT_CORPUS_MAX_CONTENT_IDS ) {
		return new WP_Error(
			'snt_posts_content_cap_exceeded',
			sprintf(
				/* translators: 1: maximum posts per include_content:true call. 2: how many the scope actually resolved to. */
				__( 'include_content:true is capped at %1$d posts; this scope resolves to more (%2$d). Narrow the scope (e.g. scope.kind "post_ids") instead of relying on pagination.', 'signal-and-noise-tools' ),
				SNT_CORPUS_MAX_CONTENT_IDS,
				$total
			),
			array( 'status' => 422 )
		);
	}

	$page = array_slice( $posts, max( 0, $offset ), $max );
	$rows = array();
	foreach ( $page as $p ) {
		$row = snt_corpus_post_row( $p );
		if ( $include_content ) {
			$row['content'] = (string) ( $p->post_content ?? '' );
		}
		$rows[] = $row;
	}

	$next_offset = $offset + count( $page );
	$has_more    = $next_offset < $total;

	return array(
		'ok'       => true,
		'posts'    => $rows,
		'count'    => count( $rows ),
		'missing'  => array(),
		'cursor'   => $has_more ? snt_sn_posts_encode_cursor( $next_offset ) : null,
		'has_more' => $has_more,
	);
}

/**
 * Ability execute callback: signal-noise/sn-posts.
 *
 * @param array|null $input Validated (defensively, not schema-enforced — see
 *                          inc/corpus-inspect.php's siblings for the same
 *                          convention) against input_schema above.
 * @return array|WP_Error
 */
function snt_ability_sn_posts( $input ) {
	if ( ! function_exists( 'snt_corpus_fetch_posts' ) || ! function_exists( 'snt_corpus_post_row' ) || ! function_exists( 'snt_corpus_post_type_allowed' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}

	$input = is_array( $input ) ? $input : array();
	$scope = is_array( $input['scope'] ?? null ) ? $input['scope'] : array();
	$kind  = isset( $scope['kind'] ) ? (string) $scope['kind'] : 'all';
	if ( ! in_array( $kind, array( 'all', 'post_ids', 'modified_since', 'post_type' ), true ) ) {
		return snt_sn_posts_scope_error( __( 'scope.kind must be one of: all, post_ids, modified_since, post_type.', 'signal-and-noise-tools' ) );
	}

	$include_content = ! empty( $input['include_content'] );

	$offset = snt_sn_posts_decode_cursor( $input['cursor'] ?? null );
	if ( null === $offset ) {
		return new WP_Error( 'snt_posts_bad_cursor', __( 'cursor is malformed.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$max = isset( $input['max'] ) && is_numeric( $input['max'] ) ? (int) $input['max'] : SNT_SN_POSTS_DEFAULT_MAX;
	$max = max( 1, min( $max, SNT_SN_POSTS_MAX_CAP ) ); // Clamp, never reject — only include_content's cap rejects.

	if ( 'post_ids' === $kind ) {
		return snt_sn_posts_resolve_post_ids( $scope, $include_content );
	}
	return snt_sn_posts_resolve_walk( $kind, $scope, $include_content, $offset, $max );
}
