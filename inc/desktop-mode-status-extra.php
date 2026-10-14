<?php
/**
 * Signal & Noise Tools: the extra rows SN Systems and SN Provenance carry
 * (owner rule 2026-10-05: "lacking some information compared with the rest").
 *
 * Every figure is a local read the site already stores (a rollup table, the
 * cron history table, an option); none calls a remote service. The whole
 * payload is held for five minutes, because the localize that carries it runs
 * on every wp-admin screen. A source that cannot answer is null, and the card
 * leaves its row out: unknown is never painted as zero.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v2 (2026-10-10): analytics2, watches, credentials, zenodo.failed and `at`
// joined the shape; a v1 payload still cached is never served for it.
const SNT_DESKTOP_STATUS_EXTRA_KEY = 'snt_desktop_status_extra_v2';

/**
 * The extra rows for both cards, cached five minutes.
 *
 * @return array{systems:array,provenance:array,at:int}
 */
function snt_desktop_status_extra() {
	$cached = get_transient( SNT_DESKTOP_STATUS_EXTRA_KEY );
	if ( is_array( $cached ) && isset( $cached['systems'], $cached['provenance'] ) ) {
		return $cached;
	}
	$out = array(
		'systems'    => array(
			'edge'  => snt_desktop_edge_yesterday(),
			'cron'  => snt_desktop_cron_24h(),
			'cache' => snt_desktop_cache_state(),
			// 2026-10-10: the analytics 2.0 verdict as stored (never the live
			// check), the watches due now, and a credential that is failing.
			'analytics2'  => snt_desktop_analytics2_shape( get_option( defined( 'SN_ANALYTICS_V2_VERIFIED_OPT' ) ? SN_ANALYTICS_V2_VERIFIED_OPT : 'sn_analytics_v2_verified', null ) ),
			'watches'     => function_exists( 'snt_watches_ripe' ) ? snt_desktop_watches_shape( snt_watches_ripe() ) : null,
			'credentials' => snt_desktop_credentials_shape( function_exists( 'snt_ability_keyring_status' ) ? snt_ability_keyring_status() : null, function_exists( 'sn_cf_monitor_read' ) ? sn_cf_monitor_read() : null, time() ),
		),
		'provenance' => array(
			'integrity' => snt_desktop_integrity(),
			'rights'    => snt_desktop_rights_state( function_exists( 'sn_rights_evidence_data' ) ? sn_rights_evidence_data() : null, function_exists( 'sn_rights_evidence_held' ) ? (array) sn_rights_evidence_held( false ) : array() ),
			'zenodo'    => snt_desktop_zenodo(),
		),
		// When these rows were read: SN Provenance's "Read at" foot.
		'at'         => time(),
	);
	set_transient( SNT_DESKTOP_STATUS_EXTRA_KEY, $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * Edge 5xx for the newest complete UTC day the rollup has covered (yesterday,
 * or the day before while the daily rollup has not run yet) and the day
 * before it. There is no rolling 24 hours: today is still filling. Null when
 * none of the last three days could be read.
 *
 * @return array{day:string,total?:int,visitor?:int|null,dashboard?:int|null,prior?:int|null,installs?:int|null,explained?:bool,failed?:bool}|null `failed` when the newest non-pending day's read failed. `installs` the plugin and theme installs recorded that UTC day (null when the history is not loaded); `explained` whether those installs account for every 5xx (snt_desktop_edge_install_explained()). `day` is the label ("Oct 4"); `visitor` the 5xx a visitor received (null when the day predates who-asked); `dashboard` how many hit the owner's own admin and its polls (null when the day carries no paths).
 */
function snt_desktop_edge_yesterday() {
	if ( ! function_exists( 'sn_edge_errors_range' ) ) {
		return null;
	}
	// Only the requested day's own state counts: 'read' (not failed, pending
	// or untracked). query.error is global to the last query, not this day.
	$state = static function ( $r, $day ) {
		foreach ( (array) ( is_array( $r ) ? ( $r['days'] ?? array() ) : array() ) as $d ) {
			if ( $day === (string) ( $d['day'] ?? '' ) ) {
				return (string) ( $d['read'] ?? '' );
			}
		}
		return '';
	};
	$ok = static fn( $r, $day ) => 'read' === $state( $r, $day );
	// Yesterday reads pending until the daily edge rollup runs: walk back to
	// the newest day it has covered, and label that day.
	for ( $back = 1; $back <= 3; $back++ ) {
		$t = time() - $back * DAY_IN_SECONDS;
		$d = gmdate( 'Y-m-d', $t );
		$a = sn_edge_errors_range( $d, $d );
		if ( ! $ok( $a, $d ) ) {
			// A pending (or unrecorded) day walks back; a FAILED read of the
			// newest day is said, never replaced by an older day's count.
			if ( 'failed' === $state( $a, $d ) ) {
				return array( 'day' => gmdate( 'M j', $t ), 'failed' => true );
			}
			continue;
		}
		$pd = gmdate( 'Y-m-d', $t - DAY_IN_SECONDS );
		$b  = sn_edge_errors_range( $pd, $pd );
		// Who asked (18.2.0): only a visitor's 5xx is an outage someone saw;
		// a Worker subrequest's is the Worker's own business.
		// Null when the day was stored before who-asked was: unknown, never 0.
		$visitor = null;
		foreach ( (array) ( $a['days'] ?? array() ) as $row ) {
			if ( $d === (string) ( $row['day'] ?? '' ) && isset( $row['visitor'] ) ) {
				$visitor = (int) $visitor + (int) $row['visitor'];
			}
		}
		$total = (int) ( $a['total'] ?? 0 );
		// 2026-10-14: each plugin or theme install puts WordPress in maintenance
		// for a few seconds, and the owner's open tabs get a 503 or two each.
		$installs = function_exists( 'snt_deploy_history_get' ) ? snt_desktop_edge_installs_on( $d, (array) snt_deploy_history_get() ) : null;
		$rows     = $total > 0 && function_exists( 'sn_edge_top_dim' ) ? sn_edge_top_dim( 'err_path_asker', $d, $d, 50 ) : null;
		return array(
			'day'       => gmdate( 'M j', $t ),
			'total'     => $total,
			'visitor'   => $visitor,
			'dashboard' => snt_desktop_edge_dashboard_count( $a['paths'] ?? null ),
			'prior'     => $ok( $b, $pd ) ? (int) ( $b['total'] ?? 0 ) : null,
			'installs'  => $installs,
			'explained' => snt_desktop_edge_install_explained( $total, $rows ? $rows : null, $installs ),
		);
	}
	return null;
}

// The rest of the module lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran; every constant is defined before any read runs.
require_once __DIR__ . '/desktop-mode-status-extra-edge-parts.php';
require_once __DIR__ . '/desktop-mode-status-extra-reads-parts.php';
require_once __DIR__ . '/desktop-mode-status-extra-rows-parts.php';
