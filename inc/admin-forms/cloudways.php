<?php
/**
 * Signal & Noise — Connections → Cloudways (status glance).
 *
 * DISPLAY-ONLY, AND THAT IS A SECURITY DECISION, NOT A SHORTCUT.
 *
 * inc/cloudways-purge.php reads its four credentials from wp-config constants
 * and has NO option fallback — it is the only integration here that works that
 * way. Its own docblock gives the reason: "The account-wide API key lives in
 * wp-config (never the database); a bearer minted from it grants the same
 * powers, so persisting one widens the blast radius of a DB dump."
 *
 * A Cloudflare token can be scoped to cache-purge alone, which is why that leaf
 * may offer a field. A Cloudways key cannot — it holds the whole hosting
 * account. So this leaf renders whether each constant is PRESENT and never what
 * it contains, and it offers no way to set one. Adding an input here would put
 * an account-wide credential in wp_options and undo the sentence above.
 *
 * WHY IT EXISTS AT ALL: the purge already writes a full result record to
 * SNT_CW_LAST_PURGE_OPT on every attempt — success, failure, and the error
 * envelope — and until now nothing read it. A fire-and-forget purge that cannot
 * report that it failed is the defect this closes.
 *
 * Pure/live split follows inc/admin-forms/mcp-connect-status.php: the builders
 * take injected state and the gatherer does the WP reads, so the tests drive the
 * real builders rather than a fixture.
 *
 * @package SignalNoiseTools
 * @since 12.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four constants inc/cloudways-purge.php requires, in the order its own
 * sn_cloudways_is_configured() checks them. NAMES only — this file never reads
 * a value, so nothing it renders can leak one.
 *
 * @return string[]
 */
function sn_admin_cloudways_constants() {
	return array(
		'SN_CLOUDWAYS_EMAIL',
		'SN_CLOUDWAYS_API_KEY',
		'SN_CLOUDWAYS_SERVER_ID',
		'SN_CLOUDWAYS_APP_ID',
	);
}

/**
 * PURE: classify the last purge record.
 *
 * The purge module goes out of its way to record `inconclusive`, `coalesced`
 * and `reauthed` as states distinct from a plain ok/fail, because each one sent
 * a previous reader down the wrong path. Collapsing them back into a red or
 * green pill here would throw away precisely what it took care to capture, so
 * each gets its own outcome.
 *
 * @param array|null $last Record from SNT_CW_LAST_PURGE_OPT, or null.
 * @return array{value:string,kind:string,text:string,meta:string}
 */
function sn_admin_cloudways_outcome( $last ) {
	if ( ! is_array( $last ) || ! isset( $last['ok'] ) ) {
		// NEVER-RUN is not FAILED. A site that has not purged since install has
		// nothing wrong with it, and saying "Failed" here would invent an
		// incident. Same distinction the sensor draws between null and zero.
		return array(
			'value' => 'Never run',
			'kind'  => 'warn',
			'text'  => 'No data',
			'meta'  => 'No purge has been attempted yet, so there is nothing to report. This is not a failure.',
		);
	}

	$ok    = ! empty( $last['ok'] );
	$stage = isset( $last['stage'] ) ? (string) $last['stage'] : '';
	$http  = isset( $last['http'] ) ? (int) $last['http'] : 0;
	$err   = isset( $last['error'] ) ? (string) $last['error'] : '';

	// Where it got to, appended to every meta line so a reader never has to
	// infer the step from which keys happen to be present.
	$where = array();
	if ( '' !== $stage ) {
		$where[] = 'stage <code>' . esc_html( $stage ) . '</code>';
	}
	if ( 0 !== $http ) {
		$where[] = 'HTTP <code>' . esc_html( (string) $http ) . '</code>';
	}
	$suffix = $where ? ' (' . implode( ', ', $where ) . ')' : '';

	if ( ! $ok && ! empty( $last['inconclusive'] ) ) {
		return array(
			'value' => 'Inconclusive',
			'kind'  => 'warn',
			'text'  => 'Unknown',
			'meta'  => 'The request timed out or the connection failed, so we never heard back. That is NOT evidence the purge did not happen — a previous one was found still running afterwards. '
				. ( '' !== $err ? '<br>' . esc_html( $err ) : '' ) . $suffix,
		);
	}

	if ( ! $ok ) {
		return array(
			'value' => 'Failed',
			'kind'  => 'err',
			'text'  => 'Failed',
			'meta'  => ( '' !== $err ? esc_html( $err ) : 'No detail was captured.' ) . $suffix,
		);
	}

	if ( ! empty( $last['reauthed'] ) ) {
		return array(
			'value' => 'OK after re-auth',
			'kind'  => 'warn',
			'text'  => 'Check credential',
			'meta'  => 'The purge succeeded, but only after a second token exchange. That is a signal about the credential rather than a clean success.' . $suffix,
		);
	}

	if ( ! empty( $last['coalesced'] ) ) {
		return array(
			'value' => 'Coalesced',
			'kind'  => 'ok',
			'text'  => 'OK',
			'meta'  => 'Joined a purge that was already running rather than starting a second one.' . $suffix,
		);
	}

	return array(
		'value' => 'OK',
		'kind'  => 'ok',
		'text'  => 'OK',
		'meta'  => 'Dispatched and acknowledged.' . $suffix,
	);
}

/**
 * PURE: the three glance cards.
 *
 * @param array $state {
 *     @type bool       $configured Whether all four constants are set.
 *     @type string[]   $missing    Constant NAMES that are absent.
 *     @type array|null $last       Last purge record.
 *     @type string     $ago        Pre-formatted "3 hours ago", or '' when never.
 * }
 * @return array<int,array<string,mixed>>
 */
function sn_admin_cloudways_cards( array $state ) {
	$configured = ! empty( $state['configured'] );
	$missing    = isset( $state['missing'] ) && is_array( $state['missing'] ) ? $state['missing'] : array();
	$last       = isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : null;
	$ago        = isset( $state['ago'] ) ? (string) $state['ago'] : '';

	$outcome = sn_admin_cloudways_outcome( $last );

	if ( $configured ) {
		$conn_meta = 'Set in <code>wp-config.php</code>. Display-only: this page cannot read or change these values.';
	} else {
		$names = array();
		foreach ( $missing as $m ) {
			$names[] = '<code>' . esc_html( (string) $m ) . '</code>';
		}
		$conn_meta = 'Missing: ' . ( $names ? implode( ', ', $names ) : '—' )
			. '. Add them to <code>wp-config.php</code>; the purge no-ops silently until all four are present.';
	}

	return array(
		array(
			'label'     => 'Connection',
			'value'     => $configured ? 'Configured' : 'Not configured',
			'pill'      => array(
				'kind' => $configured ? 'ok' : 'warn',
				'text' => $configured ? 'Ready' : 'Inactive',
			),
			'meta_html' => $conn_meta,
		),
		array(
			'label'     => 'Last purge',
			'value'     => '' !== $ago ? $ago : 'Never',
			'meta_html' => '' !== $ago
				? 'Cache purge on the Cloudways application, fired by post save and theme update.'
				: 'Nothing recorded yet.',
		),
		array(
			'label'     => 'Result',
			'value'     => $outcome['value'],
			'pill'      => array(
				'kind' => $outcome['kind'],
				'text' => $outcome['text'],
			),
			'meta_html' => $outcome['meta'],
		),
	);
}

/**
 * LIVE: gather the state the builders above take as input.
 *
 * @return array<string,mixed>
 */
function sn_admin_cloudways_state() {
	$missing = array();
	foreach ( sn_admin_cloudways_constants() as $name ) {
		if ( ! defined( $name ) || '' === (string) constant( $name ) ) {
			$missing[] = $name;
		}
	}

	$last = function_exists( 'get_option' ) ? get_option( SNT_CW_LAST_PURGE_OPT ) : null;
	$last = is_array( $last ) ? $last : null;

	$ago = '';
	if ( $last && ! empty( $last['time'] ) && function_exists( 'human_time_diff' ) ) {
		$ago = human_time_diff( (int) $last['time'], time() ) . ' ago';
	}

	return array(
		'configured' => empty( $missing ),
		'missing'    => $missing,
		'last'       => $last,
		'ago'        => $ago,
	);
}

/**
 * Render the leaf.
 *
 * @return void
 */
function sn_admin_cloudways_render() {
	$state = sn_admin_cloudways_state();
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		sn_admin_glance_grid( sn_admin_cloudways_cards( $state ) );
	}

	echo '<p class="sn-field-helper">';
	echo 'Cloudways holds the origin cache (Breeze / Varnish). This leaf reports it; it never edits it. ';
	echo 'Credentials live in <code>wp-config.php</code> only — an account-wide API key is deliberately kept out of the database.';
	echo '</p>';
}

if ( ! defined( 'SN_ADMIN_CLOUDWAYS_TEST' ) || ! SN_ADMIN_CLOUDWAYS_TEST ) {
	add_action( 'sn_admin_cloudways_tab', 'sn_admin_cloudways_render' );
}
