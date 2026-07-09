<?php
/**
 * Signal & Noise Tools — Notes provenance: admin (Tools tab + live status).
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
		'post_type'   => 'post',
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
				$pending[] = array(
					'post_id'      => (int) $id,
					'note_uid'     => (string) get_post_meta( (int) $id, SN_PROV_UID_META, true ),
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

	$ids = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 100,
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

/**
 * Tools → Provenance section body: three cards (System, Genesis, Commits) built
 * on the shared admin design system. The Commits card's live region is hydrated
 * by assets/provenance-admin.js polling the /status endpoint; the container
 * keeps the data-endpoint/data-nonce (and a data-ledger base) the JS needs.
 */
function sn_admin_render_provenance_section() {
	$sys        = sn_prov_admin_system_status();
	$ledger_url = (string) $sys['ledger_url'];
	$note_base  = '' !== $ledger_url ? $ledger_url . '/tree/main/notes/' : '';

	echo '<div class="sn-prov-admin"'
		. ' data-endpoint="' . esc_attr( esc_url_raw( rest_url( 'sn-prov/v1/status' ) ) ) . '"'
		. ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-ledger="' . esc_attr( esc_url_raw( $note_base ) ) . '">';

	sn_prov_admin_render_reanchor_notice();

	// Top row: System + Genesis side by side. Commits spans full width BELOW the
	// grid (a table never fits a ~360px card column).
	echo '<div class="sn-card-grid">';
	sn_prov_admin_render_system_card( $sys );
	sn_prov_admin_render_genesis_card( $sys );
	echo '</div>';
	sn_prov_admin_render_commits_card();

	echo '</div>';
}

/**
 * Canonical human label for a commit/anchor status. Shared by the server-
 * rendered Genesis pill and mirrored in the JS commits table so casing matches.
 *
 * @param string $status
 * @return string
 */
function sn_prov_admin_status_label( $status ) {
	$labels = array(
		'pending'    => __( 'Pending', 'signal-and-noise-tools' ),
		'confirmed'  => __( 'Confirmed', 'signal-and-noise-tools' ),
		'unanchored' => __( 'Unanchored', 'signal-and-noise-tools' ),
		'genesis'    => __( 'Genesis', 'signal-and-noise-tools' ),
		'unsent'     => __( 'Unsent', 'signal-and-noise-tools' ),
	);
	$status = (string) $status;
	return $labels[ $status ] ?? ucfirst( $status );
}

/**
 * The ?sn_prov_reanchor=ok|fail flash notice as a status box. Read-only display
 * of a whitelisted flag — no state change, so no nonce is required here.
 */
function sn_prov_admin_render_reanchor_notice() {
	if ( ! isset( $_GET['sn_prov_reanchor'] ) ) {
		return;
	}
	$result = sanitize_text_field( wp_unslash( $_GET['sn_prov_reanchor'] ) );
	if ( 'ok' === $result ) {
		$mod   = '';
		$title = esc_html__( 'Re-anchor dispatched', 'signal-and-noise-tools' );
		$body  = esc_html__( 'The genesis root was re-submitted to the Worker for anchoring.', 'signal-and-noise-tools' );
	} elseif ( 'fail' === $result ) {
		$mod   = ' sn-status-box--err';
		$title = esc_html__( 'Re-anchor failed', 'signal-and-noise-tools' );
		$body  = esc_html__( 'Nothing was dispatched — check the Worker URL and HMAC secret are configured.', 'signal-and-noise-tools' );
	} else {
		return;
	}
	echo '<div class="sn-status-box' . esc_attr( $mod ) . '"><div>'
		. '<p class="sn-status-box-title">' . esc_html( $title ) . '</p>'
		. '<p class="sn-status-box-body">' . esc_html( $body ) . '</p>'
		. '</div></div>';
}

/**
 * System card: inferred Worker status pill, config presence readout (never a
 * secret), the public key, and the ledger link.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 */
function sn_prov_admin_render_system_card( array $sys ) {
	echo '<section class="sn-card sn-prov-card">';
	echo '<strong>' . esc_html__( 'System', 'signal-and-noise-tools' ) . '</strong>';

	if ( ! empty( $sys['worker']['reachable'] ) ) {
		echo '<p><span class="sn-pill sn-pill--ok">' . esc_html__( 'Worker: reachable', 'signal-and-noise-tools' );
		if ( '' !== (string) $sys['worker']['last_contact'] ) {
			echo ' &middot; ' . esc_html( (string) $sys['worker']['last_contact'] );
		}
		echo '</span></p>';
	} else {
		echo '<p><span class="sn-pill sn-pill--warn">' . esc_html__( 'Worker: no contact yet', 'signal-and-noise-tools' ) . '</span></p>';
	}

	echo '<ul class="sn-prov-config">';
	sn_prov_admin_render_config_row( __( 'Worker URL', 'signal-and-noise-tools' ), ! empty( $sys['config']['worker_url'] ) );
	sn_prov_admin_render_config_row( __( 'HMAC secret', 'signal-and-noise-tools' ), ! empty( $sys['config']['hmac'] ) );
	sn_prov_admin_render_config_row( __( 'Public key', 'signal-and-noise-tools' ), ! empty( $sys['config']['pubkey'] ) );
	echo '</ul>';

	$pubkey = (string) $sys['pubkey'];
	if ( '' !== $pubkey ) {
		echo '<p class="sn-prov-key"><code>' . esc_html( $pubkey ) . '</code></p>';
	}

	$ledger_url = (string) $sys['ledger_url'];
	if ( '' !== $ledger_url ) {
		echo '<p><a href="' . esc_url( $ledger_url ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Public ledger', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
	}
	echo '</section>';
}

/**
 * One config presence row: a label with a ✓/✗ pill. Presence only — the value
 * (e.g. the HMAC secret) is NEVER rendered.
 *
 * @param string $label
 * @param bool   $present
 */
function sn_prov_admin_render_config_row( $label, $present ) {
	$class = $present ? 'sn-pill sn-pill--ok' : 'sn-pill sn-pill--warn';
	$mark  = $present ? '✓' : '✗';
	echo '<li><span>' . esc_html( $label ) . '</span>'
		. '<span class="' . esc_attr( $class ) . '">' . esc_html( $mark ) . '</span></li>';
}

/**
 * Genesis card: anchor-status pill, the truncated root, and the re-anchor
 * button (POSTs to admin-post.php, nonce-protected).
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 */
function sn_prov_admin_render_genesis_card( array $sys ) {
	$genesis = is_array( $sys['genesis'] ) ? $sys['genesis'] : array();
	$status  = (string) ( $genesis['status'] ?? '' );
	$root    = (string) ( $genesis['root'] ?? '' );

	echo '<section class="sn-card sn-prov-card">';
	echo '<strong>' . esc_html__( 'Genesis anchor', 'signal-and-noise-tools' ) . '</strong>';

	// Color class per genesis-anchor status; the label text comes from the shared
	// label map so its casing matches the commits table.
	$colors = array(
		'confirmed' => 'sn-pill sn-pill--ok',
		'pending'   => 'sn-pill sn-pill--warn',
		'unsent'    => 'sn-pill sn-pill--err',
	);
	if ( isset( $colors[ $status ] ) ) {
		echo '<p><span class="' . esc_attr( $colors[ $status ] ) . '">' . esc_html( sn_prov_admin_status_label( $status ) ) . '</span></p>';
	} else {
		echo '<p><span class="sn-pill">' . esc_html__( 'Not anchored', 'signal-and-noise-tools' ) . '</span></p>';
	}

	if ( '' !== $root ) {
		echo '<p class="sn-prov-root"><code>' . esc_html( sn_prov_admin_truncate( $root ) ) . '</code></p>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'sn_prov_reanchor' );
	echo '<input type="hidden" name="action" value="sn_prov_reanchor" />';
	echo '<button type="submit" class="button">' . esc_html__( 'Re-anchor genesis', 'signal-and-noise-tools' ) . '</button>';
	echo '</form>';
	echo '</section>';
}

/**
 * Commits card: the aria-live region the JS hydrates into a commits table.
 * Full-width (its own row in the grid).
 */
function sn_prov_admin_render_commits_card() {
	echo '<section class="sn-card sn-prov-card sn-prov-card--wide">';
	echo '<strong>' . esc_html__( 'Commits', 'signal-and-noise-tools' ) . '</strong>';
	echo '<div class="sn-prov-live" aria-live="polite"><p>' . esc_html__( 'Loading anchor status…', 'signal-and-noise-tools' ) . '</p></div>';
	echo '</section>';
}

/**
 * Truncate a long hex string for display: head…tail.
 *
 * @param string $hex
 * @return string
 */
function sn_prov_admin_truncate( $hex ) {
	$hex = (string) $hex;
	if ( strlen( $hex ) <= 24 ) {
		return $hex;
	}
	return substr( $hex, 0, 16 ) . '…' . substr( $hex, -8 );
}

/**
 * Enqueue the live-stepper CSS/JS ONLY on the plugin's admin screens
 * (external files — never inline; screen-gated per house rule).
 *
 * @param string $hook_suffix
 */
function sn_prov_admin_enqueue( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_hooks' ) || ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	$base = plugins_url( 'assets/', SNT_PATH . 'signal-and-noise-tools.php' );
	wp_enqueue_style( 'sn-provenance-admin', $base . 'provenance-admin.css', array(), SNT_VERSION );
	wp_enqueue_script( 'sn-provenance-admin', $base . 'provenance-admin.js', array(), SNT_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'sn_prov_admin_enqueue' );
