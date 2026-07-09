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
 * Tools → Provenance section body, rendered in the house settings-page idiom: a
 * first-glance stat-card hero over stacked full-width .sn-fieldset blocks
 * (System, Genesis anchor, Commits). The Commits table's <tbody> is the live
 * region assets/provenance-admin.js hydrates by polling the /status endpoint; it
 * carries the data-endpoint/data-nonce/data-ledger the poller reads.
 *
 * The dispatcher wraps this callback in <div class="sn-section"> (the leaf is
 * registered 'wide'), so this renderer adds NO outer wrapper of its own.
 */
function sn_admin_render_provenance_section() {
	$sys        = sn_prov_admin_system_status();
	$ledger_url = (string) $sys['ledger_url'];
	$note_base  = '' !== $ledger_url ? $ledger_url . '/tree/main/notes/' : '';

	// 1. First-glance hero: Worker / Genesis / Pending / Confirmed.
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		echo '<section aria-label="Provenance at a glance">';
		sn_admin_glance_grid( sn_prov_admin_glance_cards( $sys ) );
		echo '</section>';
	}

	// 2. Stacked full-width fieldset blocks (System, Genesis, Commits).
	sn_prov_admin_render_system_fieldset( $sys );
	sn_prov_admin_render_genesis_fieldset( $sys );
	sn_prov_admin_render_commits_fieldset( $note_base );
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
 * First-glance hero cards for the Provenance panel: Worker reachability, the
 * genesis anchor status, and the pending/confirmed commit tallies. Pure — takes
 * the sn_prov_admin_system_status() view-model, returns the card array for
 * sn_admin_glance_grid(). Mirrors snt_tags_glance_cards().
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 * @return array<int,array<string,mixed>>
 */
function sn_prov_admin_glance_cards( array $sys ) {
	$reachable    = ! empty( $sys['worker']['reachable'] );
	$last_contact = (string) ( $sys['worker']['last_contact'] ?? '' );
	$worker_value = $reachable
		? __( 'Reachable', 'signal-and-noise-tools' )
		: __( 'No contact yet', 'signal-and-noise-tools' );
	if ( $reachable && '' !== $last_contact ) {
		$worker_value .= ' · ' . $last_contact;
	}

	$genesis  = is_array( $sys['genesis'] ) ? $sys['genesis'] : array();
	$g_status = (string) ( $genesis['status'] ?? '' );
	$g_pills  = array(
		'confirmed' => array( 'kind' => 'ok', 'text' => 'anchored' ),
		'pending'   => array( 'kind' => 'warn', 'text' => 'awaiting' ),
		'unsent'    => array( 'kind' => 'err', 'text' => 'unsent' ),
	);
	$genesis_card = array(
		'label' => 'Genesis',
		'value' => '' !== $g_status ? sn_prov_admin_status_label( $g_status ) : __( 'Not anchored', 'signal-and-noise-tools' ),
	);
	if ( isset( $g_pills[ $g_status ] ) ) {
		$genesis_card['pill'] = $g_pills[ $g_status ];
	}

	$pending   = (int) ( $sys['counts']['pending'] ?? 0 );
	$confirmed = (int) ( $sys['counts']['confirmed'] ?? 0 );

	return array(
		array(
			'label' => 'Worker',
			'value' => $worker_value,
			'pill'  => $reachable
				? array( 'kind' => 'ok', 'text' => 'online' )
				: array( 'kind' => 'warn', 'text' => 'idle' ),
		),
		$genesis_card,
		array(
			'label' => 'Pending',
			'value' => number_format_i18n( $pending ),
			'pill'  => $pending > 0
				? array( 'kind' => 'warn', 'text' => 'in flight' )
				: array( 'kind' => 'ok', 'text' => 'clear' ),
		),
		array(
			'label' => 'Confirmed',
			'value' => number_format_i18n( $confirmed ),
			'pill'  => array( 'kind' => 'ok', 'text' => 'anchored' ),
		),
	);
}

/**
 * The ?sn_prov_reanchor=ok|fail flash notice as a status box, rendered inside the
 * Genesis fieldset (beside the re-anchor form). Read-only display of a
 * whitelisted flag — no state change, so no nonce is required here.
 *
 * The failure copy is config-aware (honest reporting): when all three SN_PROV_*
 * constants are present, the dispatch reached a deployed Worker that rejected it,
 * so it blames the Worker; otherwise the constants are simply unset.
 *
 * @param array $sys sn_prov_admin_system_status() view-model (for config booleans).
 */
function sn_prov_admin_render_reanchor_notice( array $sys ) {
	if ( ! isset( $_GET['sn_prov_reanchor'] ) ) {
		return;
	}
	$result = sanitize_text_field( wp_unslash( $_GET['sn_prov_reanchor'] ) );
	if ( 'ok' === $result ) {
		$mod   = '';
		$title = __( 'Re-anchor dispatched', 'signal-and-noise-tools' );
		$body  = __( 'The genesis root was re-submitted to the Worker for anchoring.', 'signal-and-noise-tools' );
	} elseif ( 'fail' === $result ) {
		$mod    = ' sn-status-box--err';
		$title  = __( 'Re-anchor failed', 'signal-and-noise-tools' );
		$config = isset( $sys['config'] ) && is_array( $sys['config'] ) ? $sys['config'] : array();
		if ( ! empty( $config['worker_url'] ) && ! empty( $config['hmac'] ) && ! empty( $config['pubkey'] ) ) {
			$body = __( 'The Worker rejected the dispatch. Check the Worker is deployed and reachable.', 'signal-and-noise-tools' );
		} else {
			$body = __( 'Set the SN_PROV_* constants in wp-config first.', 'signal-and-noise-tools' );
		}
	} else {
		return;
	}
	echo '<div class="sn-status-box' . esc_attr( $mod ) . '"><div>'
		. '<p class="sn-status-box-title">' . esc_html( $title ) . '</p>'
		. '<p class="sn-status-box-body">' . esc_html( $body ) . '</p>'
		. '</div></div>';
}

/**
 * System fieldset: the config-presence readout (Worker URL / HMAC secret /
 * Public key — presence booleans ONLY, never the secret value), the Ed25519
 * public key, and the public-ledger link.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 */
function sn_prov_admin_render_system_fieldset( array $sys ) {
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'System', 'signal-and-noise-tools' ) . '</h2>';

	echo '<table class="widefat striped sn-prov-config"><tbody>';
	sn_prov_admin_config_row( __( 'Worker URL', 'signal-and-noise-tools' ), ! empty( $sys['config']['worker_url'] ) );
	sn_prov_admin_config_row( __( 'HMAC secret', 'signal-and-noise-tools' ), ! empty( $sys['config']['hmac'] ) );
	sn_prov_admin_config_row( __( 'Public key', 'signal-and-noise-tools' ), ! empty( $sys['config']['pubkey'] ) );
	echo '</tbody></table>';

	$pubkey = (string) $sys['pubkey'];
	if ( '' !== $pubkey ) {
		echo '<p class="sn-prov-key"><code>' . esc_html( $pubkey ) . '</code></p>';
	}

	$ledger_url = (string) $sys['ledger_url'];
	if ( '' !== $ledger_url ) {
		echo '<p><a href="' . esc_url( $ledger_url ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Public ledger', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
	}
	echo '</div>';
}

/**
 * One config-presence row (a striped table row): the setting label with a ✓/✗
 * presence pill. Presence ONLY — the value (e.g. the HMAC secret) is NEVER
 * rendered.
 *
 * @param string $label   Human setting label.
 * @param bool   $present Whether the constant/option is configured.
 */
function sn_prov_admin_config_row( $label, $present ) {
	$class = $present ? 'sn-pill sn-pill--ok' : 'sn-pill sn-pill--warn';
	$mark  = $present ? '✓' : '✗';
	echo '<tr><td>' . esc_html( $label ) . '</td>'
		. '<td><span class="' . esc_attr( $class ) . '">' . esc_html( $mark ) . '</span></td></tr>';
}

/**
 * Genesis anchor fieldset: the flash notice for the last re-anchor action, the
 * anchor-status pill, the truncated root, and the re-anchor button (POSTs to
 * admin-post.php, nonce-protected).
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 */
function sn_prov_admin_render_genesis_fieldset( array $sys ) {
	$genesis = is_array( $sys['genesis'] ) ? $sys['genesis'] : array();
	$status  = (string) ( $genesis['status'] ?? '' );
	$root    = (string) ( $genesis['root'] ?? '' );

	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Genesis anchor', 'signal-and-noise-tools' ) . '</h2>';

	// The re-anchor form lives in this fieldset, so its result notice does too.
	sn_prov_admin_render_reanchor_notice( $sys );

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
	echo '</div>';
}

/**
 * Commits fieldset: a wp-list-table whose <tbody class="sn-prov-live"> is the
 * aria-live region assets/provenance-admin.js hydrates into commit rows. The
 * tbody carries the data-endpoint/data-nonce/data-ledger the poller reads.
 *
 * @param string $note_base Per-Note ledger URL base (empty when unconfigured).
 */
function sn_prov_admin_render_commits_fieldset( $note_base ) {
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">' . esc_html__( 'Commits', 'signal-and-noise-tools' ) . '</h2>';
	echo '<table class="wp-list-table widefat striped sn-prov-table"><thead><tr>'
		. '<th>' . esc_html__( 'UID', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Version', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Status', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Ledger', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead>';
	echo '<tbody class="sn-prov-live" aria-live="polite"'
		. ' data-endpoint="' . esc_attr( esc_url_raw( rest_url( 'sn-prov/v1/status' ) ) ) . '"'
		. ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-ledger="' . esc_attr( esc_url_raw( (string) $note_base ) ) . '">';
	echo '<tr><td colspan="4">' . esc_html__( 'Loading anchor status…', 'signal-and-noise-tools' ) . '</td></tr>';
	echo '</tbody></table>';
	echo '</div>';
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
