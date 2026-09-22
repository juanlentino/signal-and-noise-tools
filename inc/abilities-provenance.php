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
	);
	if ( ! function_exists( 'get_posts' ) || ! function_exists( 'sn_prov_get_chain' ) || ! defined( 'SN_PROV_UID_META' ) ) {
		return $out;
	}

	$ids = get_posts( array(
		'post_type'      => 'post',
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
		$out['total']++;
		$latest = end( $chain );
		$status = (string) ( $latest['status'] ?? 'unanchored' );
		if ( 'confirmed' === $status ) {
			$out['confirmed']++;
			continue;
		}
		if ( 'unanchored' === $status && (int) ( $latest['version'] ?? 0 ) >= 1 ) {
			$out['recording'][] = array(
				'post_id' => (int) $post_id,
				'title'   => function_exists( 'get_the_title' ) ? (string) get_the_title( (int) $post_id ) : '',
				'version' => (int) ( $latest['version'] ?? 0 ),
			);
			continue;
		}
		if ( 'pending' === $status ) {
			$out['pending'][] = array(
				'post_id'       => (int) $post_id,
				'title'         => function_exists( 'get_the_title' ) ? (string) get_the_title( (int) $post_id ) : '',
				'version'       => (int) ( $latest['version'] ?? 0 ),
				'bitcoin_txid'  => (string) ( $latest['bitcoin_txid'] ?? '' ),
				// null, never 0: "no confirmation count recorded" is not
				// "zero confirmations" — the widget renders the honest gap.
				'confirmations' => isset( $latest['confirmations'] ) ? (int) $latest['confirmations'] : null,
			);
		}
	}
	return $out;
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
