<?php
/**
 * Signal & Noise Tools: provenance-admin.php, the status reads, the URLs,
 * the two admin-post handlers and the REST route.
 *
 * Split from inc/provenance-admin.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the admin live-status payload: every commit still pending/unanchored,
 * plus genesis anchor status.
 *
 * @return array
 */
function sn_prov_admin_status() {
	// Surfaces the newest 100 UID-tracked Notes (default date-desc order biases
	// toward in-flight commits); older pending commits beyond that window aren't
	// listed. No pagination — the live stepper only needs the recent tail.
	$ids = get_posts( array(
		'post_type'   => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
		'post_status' => 'publish',
		'numberposts' => 100,
		'fields'      => 'ids',
		'meta_key'    => SN_PROV_UID_META,
	) );
	$pending = array();
	foreach ( $ids as $id ) {
		foreach ( sn_prov_get_chain( (int) $id ) as $c ) {
			$status = (string) ( $c['status'] ?? '' );
			if ( 'pending' === $status || 'unanchored' === $status ) {
				// v12.8.0: the row carries its OWN ledger URL. The table used to
				// emit ONE base for every row and let the browser append the uid,
				// which is only correct while every subject is a Note. Now that a
				// signed page can appear here, a shared base would hand every page
				// row a notes/ link that 404s — on the panel whose whole job is
				// checkability. Resolved server-side so the kind→directory map
				// stays in one place instead of gaining a copy in JavaScript.
				$row_uid  = (string) get_post_meta( (int) $id, SN_PROV_UID_META, true );
				$row_kind = function_exists( 'sn_prov_subject_kind' ) ? (string) sn_prov_subject_kind( get_post( (int) $id ) ) : '';
				$pending[] = array(
					'post_id'      => (int) $id,
					'note_uid'     => $row_uid,
					'kind'         => $row_kind,
					'ledger_url'   => ( '' !== $row_uid && '' !== $row_kind && function_exists( 'sn_prov_ledger_note_url' ) )
						? sn_prov_ledger_note_url( $row_uid, $row_kind )
						: '',
					'version'      => (int) ( $c['version'] ?? 0 ),
					'status'       => $status,
					'committed_at' => (string) ( $c['committed_at'] ?? '' ),
				);
			}
		}
	}
	$genesis = get_option( SN_PROV_GENESIS_OPT, array() );
	return array(
		'pending' => $pending,
		'genesis' => is_array( $genesis ) ? $genesis : array(),
	);
}

/**
 * System view-model for the Provenance admin panel. Reports configuration
 * PRESENCE only (never a secret value), an inferred Worker-reachability signal,
 * status totals across every Note chain, the genesis option, and the public
 * ledger surface.
 *
 * Worker reachability is inferred, not pinged (the Worker is POST-only): any
 * commit — chain or genesis — that reached 'pending' or 'confirmed' proves a
 * past successful dispatch. `last_contact` is the newest such timestamp.
 *
 * @return array
 */
function sn_prov_admin_system_status() {
	$config = array(
		'worker_url' => '' !== sn_prov_worker_url(),
		'hmac'       => '' !== sn_prov_hmac_secret(),
		'pubkey'     => '' !== sn_prov_pubkey_b64(),
	);

	$counts       = array(
		'pending'    => 0,
		'confirmed'  => 0,
		'unanchored' => 0,
	);
	$reached      = false;
	$last_contact = '';

	// #1228: numberposts => 100 silently undercounted the status TOTALS once
	// the site passed 100 provenance-tracked posts — unlike the live stepper
	// above (which only needs the recent tail), this is a total, and a
	// partial total presented as a total is wrong.
	$ids = get_posts( array(
		'post_type'   => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_key'    => SN_PROV_UID_META,
	) );
	foreach ( $ids as $id ) {
		foreach ( sn_prov_get_chain( (int) $id ) as $c ) {
			$status = (string) ( $c['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
			if ( 'pending' === $status || 'confirmed' === $status ) {
				$reached = true;
				$when    = (string) ( $c['confirmed_at'] ?? ( $c['committed_at'] ?? '' ) );
				if ( $when > $last_contact ) {
					$last_contact = $when;
				}
			}
		}
	}

	$genesis  = get_option( SN_PROV_GENESIS_OPT, array() );
	$genesis  = is_array( $genesis ) ? $genesis : array();
	$g_status = (string) ( $genesis['status'] ?? '' );
	if ( 'pending' === $g_status || 'confirmed' === $g_status ) {
		$reached = true;
		$when    = (string) ( $genesis['date'] ?? '' );
		if ( $when > $last_contact ) {
			$last_contact = $when;
		}
	}

	return array(
		'config'     => $config,
		'worker'     => array(
			'reachable'    => $reached,
			'last_contact' => $last_contact,
		),
		'counts'     => $counts,
		'genesis'    => $genesis,
		'pubkey'     => sn_prov_pubkey_b64(),
		// WHICH key, and WHERE the answer came from. Both values are public —
		// the id is in did.json and named by every record's pubkey_id, and the
		// date is in the keys mirror — so this discloses nothing new. The
		// SOURCE is the part that could not be answered before: sn_prov_config()
		// returns a DEFINED constant even when blank and never reads the option,
		// so "constant", "option" and "default" all serve values that look
		// identical on the page. During a rotation that ambiguity is the whole
		// problem. Read-only by design: see docs/ops/key-rotation-runbook.md for
		// why rotation is a ceremony with a mandatory order and not a form field.
		'signing_key' => array(
			'id'                   => sn_prov_key_id(),
			'introduced_at'        => sn_prov_key_introduced_at(),
			'id_source'            => sn_prov_key_config_source( 'SN_PROV_PUBKEY_ID', 'sn_prov_pubkey_id' ),
			'introduced_at_source' => sn_prov_key_config_source( 'SN_PROV_KEY_INTRODUCED_AT', 'sn_prov_key_introduced_at' ),
		),
		'ledger_url' => sn_prov_admin_ledger_url(),
	);
}

/**
 * Public ledger repository root URL (filterable owner/repo — the same filters
 * the per-Note ledger links use). Empty string when either is filtered away.
 *
 * @return string
 */
function sn_prov_admin_ledger_url() {
	$owner = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo  = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	if ( '' === $owner || '' === $repo ) {
		return '';
	}
	return "https://github.com/{$owner}/{$repo}";
}

/**
 * The admin-post redirect target back to Tools → Provenance, carrying the
 * re-anchor result flag.
 *
 * @param string $result 'ok' or 'fail'.
 * @return string
 */
function sn_prov_admin_reanchor_url( $result ) {
	return add_query_arg(
		array(
			'page'             => 'sn-theme-options',
			'tab'              => 'tools',
			'sub'              => 'provenance',
			'sn_prov_reanchor' => $result,
		),
		admin_url( 'admin.php' )
	);
}

/**
 * admin_post_sn_prov_reanchor handler: nonce + manage_options gated. Re-POSTs
 * the persisted genesis root via sn_prov_genesis_reanchor(), then redirects
 * back to the Provenance sub-tab with an ok|fail flag.
 */
function sn_prov_admin_reanchor_handler() {
	check_admin_referer( 'sn_prov_reanchor' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	$ok = sn_prov_genesis_reanchor();
	wp_safe_redirect( sn_prov_admin_reanchor_url( $ok ? 'ok' : 'fail' ) );
	exit;
}
add_action( 'admin_post_sn_prov_reanchor', 'sn_prov_admin_reanchor_handler' );

/**
 * admin_post_sn_prov_runsweep handler: nonce + manage_options gated. Triggers the
 * Worker's on-demand sweep via sn_prov_run_sweep(), stashes the result in a short
 * per-user transient, and redirects back to the Provenance sub-tab with an
 * ok|fail flag the notice renders.
 */
function sn_prov_admin_runsweep_handler() {
	check_admin_referer( 'sn_prov_runsweep' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	$result = function_exists( 'sn_prov_run_sweep' )
		? sn_prov_run_sweep()
		: array( 'ok' => false, 'error' => 'unavailable' );
	set_transient( 'sn_prov_sweep_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg(
		array(
			'page'          => 'sn-theme-options',
			'tab'           => 'tools',
			'sub'           => 'provenance',
			'sn_prov_swept' => ! empty( $result['ok'] ) ? 'ok' : 'fail',
		),
		admin_url( 'admin.php' )
	) );
	exit;
}
add_action( 'admin_post_sn_prov_runsweep', 'sn_prov_admin_runsweep_handler' );

/**
 * Register the nonce-gated live-status REST route (manage_options only).
 */
function sn_prov_admin_register_status_route() {
	register_rest_route( 'sn-prov/v1', '/status', array(
		'methods'             => 'GET',
		'callback'            => 'sn_prov_admin_status_handler',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
}
add_action( 'rest_api_init', 'sn_prov_admin_register_status_route' );

/**
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sn_prov_admin_status_handler( $request ) {
	return new WP_REST_Response( sn_prov_admin_status(), 200 );
}

