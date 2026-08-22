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

	$ids = get_posts( array(
		'post_type'   => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
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

/**
 * Tools → Provenance section body, rendered in the house settings-page idiom: a
 * first-glance stat-card hero over the shared two-column shell (sn_admin_shell_*).
 * The wide live Commits table fills the main column; the compact System and
 * Genesis readouts stack in the narrower rail — the shell's documented RULE (wide
 * data tables in the main column, status readouts in the rail; see
 * inc/admin-shell.php). The Commits <tbody> is the live region
 * assets/provenance-admin.js hydrates by polling /status; it carries the
 * data-endpoint/data-nonce/data-ledger the poller reads.
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

	// 2. Two-column shell: the wide live Commits table in the main column, the
	// compact System + Genesis readouts stacked in the rail. Between open() and
	// close() no early return may occur (would unbalance the wrapper divs).
	sn_admin_shell_open();
	sn_prov_admin_render_commits_fieldset( $note_base );
	// v10.3.0: the one-shot ledger backfill (renders only while candidates
	// exist — after a clean import the section disappears).
	if ( function_exists( 'sn_prov_backfill_render_fieldset' ) ) {
		sn_prov_backfill_render_fieldset();
	}
	sn_admin_shell_rail( __( 'Provenance status', 'signal-and-noise-tools' ) );
	sn_prov_admin_render_system_fieldset( $sys );
	sn_prov_admin_render_genesis_fieldset( $sys );
	sn_admin_shell_close();
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
 * Format a provenance timestamp for admin display in Eastern Time — the site's
 * operating timezone — instead of the raw UTC ISO-8601 the Worker/ledger store.
 * A full instant (e.g. "2026-07-09T19:31:58Z") becomes "Jul 9, 2026 3:31 PM EDT"
 * (DST-aware: EDT in summer, EST in winter). A bare calendar date (the genesis
 * "date", "YYYY-MM-DD") is reformatted date-only with NO timezone shift — moving
 * its implicit midnight across zones would roll it to the previous day. Empty in
 * → empty out; an unparseable value is returned verbatim rather than swallowed.
 *
 * @param string $iso UTC ISO-8601 instant or a YYYY-MM-DD date.
 * @return string Human ET string, or the input unchanged if it can't be parsed.
 */
function sn_prov_admin_format_ts( $iso ) {
	$iso = trim( (string) $iso );
	if ( '' === $iso ) {
		return '';
	}
	try {
		$dt = new DateTimeImmutable( $iso );
	} catch ( Exception $e ) {
		return $iso; // not a date we recognize — show it as-is, don't lie
	}
	// Date-only input carries no clock; reformat as written (no zone conversion).
	if ( false === strpos( $iso, 'T' ) && false === strpos( $iso, ':' ) ) {
		return $dt->format( 'M j, Y' );
	}
	return $dt->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'M j, Y g:i A T' );
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
		$worker_value .= ' · ' . sn_prov_admin_format_ts( $last_contact );
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
 * The ?sn_prov_swept=ok|fail flash for the manual sweep, rendered inside the
 * Commits fieldset beside the trigger button. Reads the per-user transient the
 * handler stashed (then clears it — one-shot). Count-driven copy on success; a
 * config-aware line on failure.
 *
 * @return void
 */
function sn_prov_admin_render_sweep_notice() {
	if ( ! isset( $_GET['sn_prov_swept'] ) ) {
		return;
	}
	$flag   = sanitize_text_field( wp_unslash( $_GET['sn_prov_swept'] ) );
	$key    = 'sn_prov_sweep_result_' . get_current_user_id();
	$result = get_transient( $key );
	delete_transient( $key );
	$result = is_array( $result ) ? $result : array();

	if ( 'ok' === $flag && ! empty( $result['ok'] ) ) {
		$up    = (int) ( $result['upgraded'] ?? 0 );
		$pend  = (int) ( $result['still_pending'] ?? 0 );
		$mod   = '';
		$title = __( 'Sweep complete', 'signal-and-noise-tools' );
		$body  = $up > 0
			? sprintf(
				/* translators: 1: newly-confirmed count, 2: still-pending count */
				_n( '%1$d proof newly confirmed on Bitcoin; %2$d still pending.', '%1$d proofs newly confirmed on Bitcoin; %2$d still pending.', $up, 'signal-and-noise-tools' ),
				$up,
				$pend
			)
			: sprintf(
				/* translators: %d: still-pending count */
				_n( 'Nothing new. %d proof is still awaiting Bitcoin confirmation.', 'Nothing new. %d proofs are still awaiting Bitcoin confirmation.', $pend, 'signal-and-noise-tools' ),
				$pend
			);
	} else {
		$mod   = ' sn-status-box--err';
		$title = __( 'Sweep failed', 'signal-and-noise-tools' );
		$err   = isset( $result['error'] ) ? (string) $result['error'] : '';
		$body  = 'unconfigured' === $err
			? __( 'Set the SN_PROV_* constants in wp-config first.', 'signal-and-noise-tools' )
			: __( 'Could not reach the Worker, or it rejected the request.', 'signal-and-noise-tools' );
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

	// Deployed Worker version, from its /_sn/version endpoint (cached). Shown only
	// when the Worker is configured; "unknown" when it can't be reached.
	if ( ! empty( $sys['config']['worker_url'] ) ) {
		$wver = function_exists( 'sn_prov_worker_version' ) ? sn_prov_worker_version() : '';
		echo '<p class="sn-prov-worker-ver">' . esc_html__( 'Worker version', 'signal-and-noise-tools' ) . ' '
			. ( '' !== $wver
				? '<code>' . esc_html( $wver ) . '</code>'
				: '<span class="sn-prov-muted">' . esc_html__( 'unknown', 'signal-and-noise-tools' ) . '</span>' )
			. '</p>';
	}

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

	// Re-anchoring is only meaningful when the root isn't already anchored. Once
	// it's 'pending' (in flight to Bitcoin) or 'confirmed', re-stamping would reset
	// the OTS clock / revert the anchor — so the button is disabled (and the
	// handler no-ops as a belt: sn_prov_genesis_reanchor()).
	$anchored = ( 'pending' === $status || 'confirmed' === $status );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'sn_prov_reanchor' );
	echo '<input type="hidden" name="action" value="sn_prov_reanchor" />';
	echo '<button type="submit" class="button"' . ( $anchored ? ' disabled' : '' ) . '>' . esc_html__( 'Re-anchor genesis', 'signal-and-noise-tools' ) . '</button>';
	if ( $anchored ) {
		echo ' <span class="sn-fieldset-intro">' . esc_html__( 'Already anchored: nothing to re-anchor.', 'signal-and-noise-tools' ) . '</span>';
	}
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

	// On-demand sweep: the last result flash + the trigger. Lets the owner flip
	// Bitcoin-confirmed proofs now instead of waiting for the Worker's hourly cron.
	sn_prov_admin_render_sweep_notice();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sn-prov-sweep">';
	wp_nonce_field( 'sn_prov_runsweep' );
	echo '<input type="hidden" name="action" value="sn_prov_runsweep" />';
	echo '<button type="submit" class="button">' . esc_html__( 'Check for confirmations', 'signal-and-noise-tools' ) . '</button> ';
	echo '<span class="sn-fieldset-intro">' . esc_html__( 'Ask the Worker to check pending proofs against Bitcoin now, rather than waiting for the hourly sweep.', 'signal-and-noise-tools' ) . '</span>';
	echo '</form>';

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
