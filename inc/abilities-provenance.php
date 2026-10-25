<?php
/**
 * Signal & Noise Tools — provenance anchor abilities.
 *
 * Two thin wraps over seams that already exist, so the Desktop Mode
 * mirror (the SN Anchors widget + the ⌘K sweep command) rides the
 * canonical abilities run-path like every other JS caller:
 *
 *   signal-noise/anchor-status — READ. Aggregates every anchored Note's
 *     latest chain entry (SN_PROV_CHAIN_META via sn_prov_get_chain())
 *     into { pending: rows, confirmed, total }. Pending rows carry the
 *     live in-flight tx data the worker's pending callbacks record
 *     (bitcoin_txid + confirmations, N/6).
 *
 *   signal-noise/anchor-sweep — WRITE (idempotent). Dispatches the
 *     Worker's on-demand upgrade sweep via the existing
 *     sn_prov_run_sweep() (inc/provenance-webhook.php) — the same seam
 *     the admin panel button uses.
 *
 * @since 9.78.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregate anchor state across every Note that carries a provenance
 * uid. Pure read: one bounded post query + per-post chain meta reads —
 * fetch-on-render economics (never localized on page load).
 *
 * A Note with no chain yet is counted in NEITHER `total` NOR listed
 * anywhere (#1228 — this docblock previously claimed it was counted in
 * `total`; tests/abilities-provenance.php pins the real rule): "no chain"
 * is not a pending anchor, and inventing a row — or a count — for it
 * would fabricate state the ledger never recorded.
 *
 * @since 9.78.0
 * @return array{pending:array<int,array>,recording:array<int,array>,confirmed:int,total:int}
 */
function snt_prov_anchor_overview() {
	$out = array(
		'pending'   => array(),
		// v14.6.2: the THIRD state. A commit is `unanchored` from the moment
		// it is persisted until the settle-window dispatch reaches the Worker
		// and the Worker answers `pending`; the theme presents that as
		// "Recording". The overview counted only confirmed and pending, so a
		// note that had just minted v2 read as "40 of 41 anchored, no anchors
		// pending" while its page said RECORDING · V2. Absent from both lists
		// is not the same as anchored.
		'recording' => array(),
		'confirmed' => 0,
		'total'     => 0,
		// 19.2.0: signed Pages (the /provenance/ hub, the essays) are their own
		// count. `confirmed`/`total` stay Notes-only, so "N of N notes
		// anchored" keeps its meaning; a page's in-flight version still lists
		// under pending/recording, tagged type=page, because it is real work.
		'pages'     => array( 'confirmed' => 0, 'total' => 0 ),
		// 2026-10-17: every minted version (v0 genesis excluded) by the author's
		// change class. Absent or unknown counts as unclassified.
		'change_classes' => defined( 'SN_PROV_CHANGE_CLASSES' ) ? array_fill_keys( SN_PROV_CHANGE_CLASSES, 0 ) : array(),
	);
	if ( ! function_exists( 'get_posts' ) || ! function_exists( 'sn_prov_get_chain' ) || ! defined( 'SN_PROV_UID_META' ) ) {
		return $out;
	}

	$ids = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => SN_PROV_UID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded corpus (~dozens of Notes), mirrors inc/provenance-admin.php.
		'no_found_rows'  => true,
	) );
	if ( ! is_array( $ids ) ) {
		return $out;
	}

	foreach ( $ids as $post_id ) {
		$chain = sn_prov_get_chain( (int) $post_id );
		if ( ! is_array( $chain ) || array() === $chain ) {
			continue;
		}
		$type = function_exists( 'get_post_type' ) && 'page' === get_post_type( (int) $post_id ) ? 'page' : 'post';
		if ( 'page' === $type ) {
			$out['pages']['total']++;
		} else {
			$out['total']++;
		}
		foreach ( $chain as $entry ) {
			if ( (int) ( $entry['version'] ?? 0 ) >= 1 && $out['change_classes'] ) {
				$out['change_classes'][ snt_prov_change_class_of( $entry ) ?: 'unclassified' ]++;
			}
		}
		$latest = end( $chain );
		$status = (string) ( $latest['status'] ?? 'unanchored' );
		if ( 'confirmed' === $status ) {
			if ( 'page' === $type ) {
				$out['pages']['confirmed']++;
			} else {
				$out['confirmed']++;
			}
			continue;
		}
		if ( 'unanchored' === $status && (int) ( $latest['version'] ?? 0 ) >= 1 ) {
			$out['recording'][] = array(
				'post_id' => (int) $post_id,
				'type'    => $type,
				'title'   => function_exists( 'get_the_title' ) ? (string) get_the_title( (int) $post_id ) : '',
				'version' => (int) ( $latest['version'] ?? 0 ),
			) + snt_prov_beside_payload_fields( $latest );
			continue;
		}
		if ( 'pending' === $status ) {
			$out['pending'][] = array(
				'post_id'       => (int) $post_id,
				'type'          => $type,
				'title'         => function_exists( 'get_the_title' ) ? (string) get_the_title( (int) $post_id ) : '',
				'version'       => (int) ( $latest['version'] ?? 0 ),
				'bitcoin_txid'  => (string) ( $latest['bitcoin_txid'] ?? '' ),
				// null, never 0: "no confirmation count recorded" is not
				// "zero confirmations" — the widget renders the honest gap.
				'confirmations' => isset( $latest['confirmations'] ) ? (int) $latest['confirmations'] : null,
			) + snt_prov_beside_payload_fields( $latest );
		}
	}
	return $out;
}

/**
 * A chain entry's stored change class, or '' when unclassified or unknown.
 *
 * @param array $entry
 * @return string
 */
function snt_prov_change_class_of( $entry ) {
	$class = $entry['change_class'] ?? '';
	return function_exists( 'sn_prov_change_class_storable' ) && sn_prov_change_class_storable( $class ) ? (string) $class : '';
}

/**
 * The fields an in-flight row carries from beside the signed payload: the
 * change_class when classified, and (2026-10-24) core_revision_id when the
 * commit stored one (inc/provenance-core-revision.php; it may name a revision
 * core has since pruned).
 *
 * @param array $entry
 * @return array
 */
function snt_prov_beside_payload_fields( $entry ) {
	$class = snt_prov_change_class_of( $entry );
	$field = '' === $class ? array() : array( 'change_class' => $class );
	return $field + ( function_exists( 'sn_prov_core_revision_field' ) ? sn_prov_core_revision_field( $entry ) : array() );
}

/**
 * Register both abilities on the canonical registrar hook.
 *
 * @since 9.78.0
 */
function snt_abilities_provenance_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/anchor-status', array(
		'label'               => 'Provenance anchor overview',
		'description'         => 'Aggregates every Note\'s latest anchor state: pending anchors with their in-flight Bitcoin transaction and confirmation count, plus confirmed/total counts.',
		// 'diagnostics', from the registrar's REGISTERED set (inc/
		// abilities-categories.php) — beside get-deploy-status. v9.78.0
		// shipped 'monitoring', a category no registrar registers: WP 6.9's
		// _doing_it_wrong fired on every shell request and the registry
		// silently refused the ability (owner-caught live via Query Monitor).
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_anchor_status',
		'input_schema'        => array(
			// The [object,null] union, per the house rule for READ abilities
			// (see get-deploy-status): readonly ⇒ GET run-path ⇒ a caller that
			// omits ?input= delivers NULL, and a plain 'object' rejects every
			// such call ("input is not of type object" — v9.78.1, caught live
			// on the SN Anchors widget; third bite of this exact trap).
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'pending'   => array( 'type' => 'array' ),
				'recording' => array( 'type' => 'array', 'description' => 'v14.6.2: commits persisted and not yet accepted by the Worker (the theme\'s "Recording" badge): {post_id, title, version}. Neither confirmed nor pending; a note here is NOT anchored yet.' ),
				'confirmed' => array( 'type' => 'integer' ),
				'total'     => array( 'type' => 'integer' ),
				'pages'     => array( 'type' => 'object', 'description' => '19.2.0: signed Pages, counted apart from Notes: {confirmed, total}. confirmed/total above are Notes only.' ),
				'change_classes' => array( 'type' => 'object', 'description' => '2026-10-17: every minted version (notes and pages, v0 excluded) counted by the author\'s change class: {reading_aid, correction, copy, claim, unclassified}. The class is the author\'s label, stored beside the signed payload and not covered by the signature. pending/recording rows carry change_class when one was given, and core_revision_id (2026-10-24) when the commit stored the id of the WordPress revision saved with it; that id is a pointer outside the signature and may name a revision core has since pruned.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/anchor-sweep', array(
		'label'               => 'Run the anchor upgrade sweep',
		'description'         => 'Asks the provenance Worker to upgrade pending OpenTimestamps proofs now instead of waiting for the hourly cron. Idempotent: only genuinely Bitcoin-confirmed proofs flip.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_anchor_sweep',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ), // 15.7.1: an input-less call arrives as null.
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'checked'       => array( 'type' => 'integer' ),
				'upgraded'      => array( 'type' => 'integer' ),
				'still_pending' => array( 'type' => 'integer' ),
				'error'         => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
}
add_action( 'wp_abilities_api_init', 'snt_abilities_provenance_register' );

/**
 * Execute callbacks — thin, testable.
 *
 * @since 9.78.0
 */
function snt_ability_anchor_status( $input = array() ) {
	return snt_prov_anchor_overview();
}

function snt_ability_anchor_sweep( $input = array() ) {
	if ( ! function_exists( 'sn_prov_run_sweep' ) ) {
		return array( 'ok' => false, 'error' => 'unavailable' );
	}
	return sn_prov_run_sweep();
}
