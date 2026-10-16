<?php
/**
 * S&N Dashboard, Monitoring > Analytics: the Pipeline status card.
 *
 * 2026-10-16 (owner, desktop PWA: "a mess. Design, design, design."): one
 * card answers "is the pipeline wired?" on one line of chips: the five
 * presence checks as "N of 5 set" (each one missing named in amber), the
 * Worker's version, when the identity salt rotates, and how many devices are
 * yours, with the View dashboard door on the right. A warning stays on this
 * card as a notice (status is never folded); the detail behind each chip
 * lives in the Setup card's folds (monitoring-analytics-setup.php).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Pipeline card.
 *
 * @param array{0:array<int,array<string,mixed>>,1:string,2:string} $worker analytics_worker_rows().
 * @param array<string,mixed>|null                                  $salt     sn_salt_window_get(), or null when not loaded.
 * @return string
 */
function analytics_status_html( array $worker, $salt ) {
	list( $presence, $notes ) = analytics_presence_chips();
	$chips = $presence . analytics_worker_chip( $worker ) . analytics_salt_chip( $salt ) . analytics_devices_chip();
	$url   = function_exists( '\snt_analytics_page_url' ) ? (string) \snt_analytics_page_url() : '';
	$row   = '<div class="snt-an-status"><os-cluster gap="8">' . $chips . '</os-cluster>'
		. ( '' !== $url ? \snt_kit_door( __( 'View dashboard →', 'signal-and-noise-tools' ), $url ) : '' )
		. '</div>';
	return \snt_kit_section(
		__( 'Pipeline', 'signal-and-noise-tools' ),
		$row . $notes . (string) ( $worker[1] ?? '' ) . analytics_reads_notice(),
		__( 'First-party analytics settings; the read-only dashboard has its own menu, S&N Analytics. Beacon → worker → Analytics Engine → cron → edge, presence checks only: secret values are never shown.', 'signal-and-noise-tools' )
	);
}

/**
 * The five presence checks as one chip ("5 of 5 set", every check named in
 * its title), an amber chip per check that is not ok, and the notes of the
 * ones that carry one.
 *
 * @return array{0:string,1:string} Chips, notices.
 */
function analytics_presence_chips() {
	if ( ! function_exists( '\snt_analytics_pipeline_pills' ) ) {
		return array( '', '' );
	}
	$pills   = \snt_analytics_pipeline_pills();
	$ok      = 0;
	$labels  = array();
	$missing = '';
	$notes   = '';
	foreach ( $pills as $p ) {
		$kind     = (string) ( $p[0] ?? '' );
		$labels[] = (string) ( $p[1] ?? '' );
		if ( 'ok' === $kind ) {
			++$ok;
			continue;
		}
		$missing .= \snt_kit_badge( 'warn', (string) ( $p[1] ?? '' ) );
		if ( '' !== (string) ( $p[2] ?? '' ) ) {
			$notes .= \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( (string) $p[1] ) . '</b> ' . \snt_kit_esc( (string) $p[2] ) );
		}
	}
	$all     = $ok === count( $pills );
	/* translators: 1: checks that pass; 2: all checks. */
	$summary = \snt_kit_tag( 'os-badge', array( 'tone' => \snt_kit_tone( $all ? 'ok' : 'warn' ), 'title' => implode( ' · ', $labels ) ), \snt_kit_esc( sprintf( __( '%1$d of %2$d set', 'signal-and-noise-tools' ), $ok, count( $pills ) ) ) );
	return array( $summary . $missing, $notes );
}

/**
 * The Worker chip: its name and version, amber when the version was not
 * read, is the last value reached, or is not reported.
 *
 * @param array{0:array<int,array<string,mixed>>,1:string,2:string} $worker analytics_worker_rows().
 * @return string
 */
function analytics_worker_chip( array $worker ) {
	$state = (string) ( $worker[2] ?? '' );
	if ( 'unread' === $state ) {
		return \snt_kit_badge( 'warn', __( 'Worker version not read', 'signal-and-noise-tools' ) );
	}
	if ( 'unreported' === $state ) {
		return \snt_kit_badge( 'warn', __( 'Worker version not reported', 'signal-and-noise-tools' ) );
	}
	return \snt_kit_badge( 'stale' === $state ? 'warn' : 'ok', (string) ( $worker[0][0]['value'] ?? '' ) );
}

/**
 * When today's identity salt rotates, or that its window was not read.
 *
 * @param array<string,mixed>|null $salt sn_salt_window_get().
 * @return string
 */
function analytics_salt_chip( $salt ) {
	if ( ! is_array( $salt ) ) {
		return '';
	}
	$w = 'ok' === (string) ( $salt['state'] ?? '' ) && is_array( $salt['window'] ?? null ) ? (array) $salt['window'] : null;
	if ( null === $w ) {
		return \snt_kit_badge( 'warn', __( 'Salt window not read', 'signal-and-noise-tools' ) );
	}
	$tz = '' !== (string) ( $w['rotate_tz'] ?? '' ) ? (string) $w['rotate_tz'] : 'UTC';
	/* translators: %s: the rotation timezone. */
	return \snt_kit_chip( sprintf( __( 'Salt rotates at midnight (%s)', 'signal-and-noise-tools' ), $tz ) );
}

/**
 * How many enrolled devices are yours.
 *
 * @return string
 */
function analytics_devices_chip() {
	if ( ! function_exists( '\sn_owner_devices' ) ) {
		return '';
	}
	$n = count( (array) \sn_owner_devices() );
	/* translators: %s: number of enrolled owner devices. */
	return \snt_kit_chip( sprintf( _n( '%s device yours', '%s devices yours', $n, 'signal-and-noise-tools' ), \number_format_i18n( $n ) ) );
}
