<?php
/**
 * S&N Dashboard — Connections → Cloudflare: the readouts under the form.
 *
 * The Post-purge probes ledger, the cache status box, the manual purge
 * card and the Cloudways purge status, each painted from the kit for the same
 * readings the classic closure in inc/cloudflare-purge.php prints. Required
 * by connections-cloudflare.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** 17.4.1: the probes ledger lists this many newest rows, then "more". */
const CF_PROBE_ROWS = 8;

/**
 * One probe-log entry as a table row: when, the verdict (with its escalation
 * and the retired-detector mark), the page path (or the purge's source).
 *
 * @param array<string,mixed> $row A log entry.
 * @return array{when:string,result:string,page:string}
 */
function cloudflare_probe_row( array $row ) {
	$time   = (int) ( $row['time'] ?? 0 );
	$result = (string) ( $row['result'] ?? '' );
	$stale  = 'stale' === $result;
	$label  = '' !== $result ? $result : __( 'unknown', 'signal-and-noise-tools' );
	if ( $stale && ! empty( $row['escalated'] ) ) {
		$label .= ' ' . __( '→ zone purge', 'signal-and-noise-tools' );
	}
	if ( defined( 'SN_CF_PROBE_ALGO' ) && (int) ( $row['algo'] ?? 1 ) < SN_CF_PROBE_ALGO ) {
		$label .= ' · ' . __( 'retired detector', 'signal-and-noise-tools' );
	}
	// The URL minus the host: twenty identical origins is none of the information.
	$url  = (string) ( $row['url'] ?? '' );
	$path = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
	if ( '' === $path ) {
		// A manual zone purge names no single page; say which purge it was.
		$path = '' !== (string) ( $row['source'] ?? '' ) ? (string) $row['source'] : '—';
	}
	return array(
		/* translators: %s: how long ago */
		'when'   => $time ? sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( $time, time() ) ) : '—',
		'result' => $label,
		'page'   => $path,
	);
}

/**
 * The Post-purge probes ledger: its own box since 17.4.1 (#1573), the intro
 * as the description, the When / Result / Page table capped at
 * CF_PROBE_ROWS newest rows with a "more" line for the rest, no fold. Until
 * then an `<os-disclosure>` inside Cache, open only when the newest probe
 * was stale. Painted only when configured and the log has entries, the
 * classic gate; the tally (retained, stale) is a Cache facts row.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_probes_html( array $d ) {
	if ( empty( $d['is_configured'] ) || empty( $d['probe_log'] ) ) {
		return '';
	}
	$rows = array();
	foreach ( array_slice( $d['probe_log'], 0, CF_PROBE_ROWS ) as $row ) {
		if ( is_array( $row ) ) {
			$rows[] = cloudflare_probe_row( $row );
		}
	}
	$delay = defined( 'SN_CF_PROBE_DELAY' ) ? (int) SN_CF_PROBE_DELAY : 0;
	$intro = sprintf(
		/* translators: %d: seconds between a purge and its probe */
		__( 'Each row is one check of the page a reader would actually get, %d seconds after its purge. A stale row escalated to a full zone purge at the time, so it records a purge that needed a second attempt, not a page still stale now.', 'signal-and-noise-tools' ),
		$delay
	);
	$inner = \snt_kit_table(
		array(
			array( 'key' => 'when', 'label' => __( 'When', 'signal-and-noise-tools' ) ),
			array( 'key' => 'result', 'label' => __( 'Result', 'signal-and-noise-tools' ) ),
			array( 'key' => 'page', 'label' => __( 'Page', 'signal-and-noise-tools' ) ),
		),
		$rows,
		array( 'empty' => __( 'No probes recorded.', 'signal-and-noise-tools' ) )
	);
	$n = count( $d['probe_log'] );
	if ( $n > CF_PROBE_ROWS ) {
		/* translators: %d: probes retained but not listed */
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( '…and %d more', 'signal-and-noise-tools' ), $n - CF_PROBE_ROWS ) ) . '</p>';
	}
	return \snt_kit_section( __( 'Post-purge probes', 'signal-and-noise-tools' ), $inner, $intro );
}

/**
 * The cache status: one facts list. Auto-purge, the last purge, the
 * Cloudways leg when that module is configured, and the probe tally. 15.1.0
 * folded three stacked notices into this; the not-configured state keeps its
 * warning because it is a state, not a fact.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_status_html( array $d ) {
	if ( empty( $d['is_configured'] ) ) {
		return \snt_kit_notice(
			'warn',
			'<b>' . \snt_kit_esc( __( 'Not configured', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_badge( 'warn', __( 'Inactive', 'signal-and-noise-tools' ) ) . '<br>'
			. \snt_kit_esc( __( 'Auto-purge disabled. Set both the API token and zone ID under Credentials to activate.', 'signal-and-noise-tools' ) )
		);
	}
	$last = (array) $d['last_purge'];
	$when = \snt_kit_esc( __( 'never', 'signal-and-noise-tools' ) );
	if ( ! empty( $last['time'] ) ) {
		$kind = ( 'all' === (string) ( $last['kind'] ?? '' ) )
			? __( 'full zone', 'signal-and-noise-tools' )
			/* translators: %d: URLs purged */
			: sprintf( __( '%d URL(s)', 'signal-and-noise-tools' ), (int) ( $last['count'] ?? 0 ) );
		/* translators: 1: relative time element, 2: what was purged */
		$when = sprintf( \snt_kit_esc( __( '%1$s (%2$s)', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( (int) $last['time'] ), \snt_kit_esc( $kind ) );
	}
	$rows = array(
		array( 'label' => __( 'Auto-purge', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_badge( 'ok', __( 'Active', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( __( 'on post save, theme update and the REST endpoint', 'signal-and-noise-tools' ) ) ),
		array( 'label' => __( 'Last purge', 'signal-and-noise-tools' ), 'html' => true, 'value' => $when ),
	);
	$cw = cloudflare_cloudways_row( $d['cloudways'] );
	if ( null !== $cw ) {
		$rows[] = $cw;
	}
	$log = (array) ( $d['probe_log'] ?? array() );
	if ( array() !== $log ) {
		$stale = count( array_filter( $log, static function ( $r ) { return 'stale' === (string) ( $r['result'] ?? '' ); } ) );
		$rows[] = array( 'label' => __( 'Post-purge probes', 'signal-and-noise-tools' ), 'value' => sprintf( /* translators: 1: retained, 2: stale */ __( '%1$d retained, %2$d stale', 'signal-and-noise-tools' ), count( $log ), $stale ), 'tone' => $stale > 0 ? 'warn' : '' );
	}
	return \snt_kit_kv( $rows );
}

/**
 * The manual purge card. 15.1.0: the button runs the SAME chain as
 * Dashboard › Maintenance (object cache, Breeze, Varnish, then Cloudflare,
 * verified). Until then it purged Cloudflare alone, and the edge refilled
 * from the stale copy Varnish still held.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_purge_html( array $d ) {
	return \snt_kit_tag(
		'os-card',
		array( 'compact' => true ),
		'<header><h3>' . \snt_kit_esc( __( 'Purge all caches', 'signal-and-noise-tools' ) ) . '</h3></header>'
		. '<p>' . \snt_kit_esc( __( 'Object cache, Breeze, Varnish, then Cloudflare, in that order, verified. The same action as Dashboard › Maintenance; it lives here too because this is where the token changes.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<footer>' . \snt_kit_action_button( __( 'Purge all caches', 'signal-and-noise-tools' ), 'cf_purge_now', array( 'disabled' => empty( $d['is_configured'] ) ) ) . '</footer>'
	);
}

/**
 * The Cloudways leg as one facts row, when that module is configured: it
 * rides the same purge chain, so a failed leg is visible next to the rest.
 *
 * @param array<string,mixed>|null $cw SNT_CW_LAST_PURGE_OPT, or null when Cloudways is not configured.
 * @return array<string,mixed>|null
 */
function cloudflare_cloudways_row( $cw ) {
	if ( ! is_array( $cw ) ) {
		return null;
	}
	$attempted = ! empty( $cw['time'] );
	$warn      = $attempted && empty( $cw['ok'] );
	$line      = \snt_kit_esc( __( 'Varnish leg of the same chain.', 'signal-and-noise-tools' ) );
	if ( $attempted ) {
		/* translators: %s: relative time element */
		$line .= ' ' . sprintf( \snt_kit_esc( __( 'Last attempt: %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( (int) $cw['time'] ) );
		if ( $warn ) {
			$line .= ' HTTP ' . (int) ( $cw['http'] ?? 0 );
			if ( '' !== trim( (string) ( $cw['error'] ?? '' ) ) ) {
				$line .= ': ' . \snt_kit_esc( (string) $cw['error'] );
			}
		}
	}
	$pill = $warn ? __( 'Error', 'signal-and-noise-tools' ) : ( $attempted ? __( 'OK', 'signal-and-noise-tools' ) : __( 'Active', 'signal-and-noise-tools' ) );
	return array( 'label' => __( 'Cloudways purge', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_badge( $warn ? 'warn' : 'ok', $pill ) . ' ' . $line, 'tone' => $warn ? 'warn' : '' );
}
