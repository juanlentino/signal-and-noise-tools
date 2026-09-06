<?php
/**
 * S&N Dashboard — Connections → Cloudflare: the readouts under the form.
 *
 * The folded Post-purge probes table, the cache status box, the manual purge
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
 * The Post-purge probes fold: `<os-disclosure heading hint open>` (kit-help
 * "Disclosure") — open only when the NEWEST probe is stale, as the classic
 * `<details>` is — around the intro and the When / Result / Page table.
 * Painted only when configured and the log has entries, the classic gate.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_probes_html( array $d ) {
	if ( empty( $d['is_configured'] ) || empty( $d['probe_log'] ) ) {
		return '';
	}
	$stale  = 0;
	$newest = '';
	$rows   = array();
	foreach ( $d['probe_log'] as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		if ( '' === $newest ) {
			$newest = (string) ( $row['result'] ?? '' );
		}
		if ( 'stale' === (string) ( $row['result'] ?? '' ) ) {
			++$stale;
		}
	}
	foreach ( array_slice( $d['probe_log'], 0, 20 ) as $row ) {
		if ( is_array( $row ) ) {
			$rows[] = cloudflare_probe_row( $row );
		}
	}
	$delay = defined( 'SN_CF_PROBE_DELAY' ) ? (int) SN_CF_PROBE_DELAY : 0;
	$intro = sprintf(
		/* translators: %d: seconds between a purge and its probe */
		__( 'Each row is one check of the page a reader would actually get, %d seconds after its purge. A stale row escalated to a full zone purge at the time, so it records a purge that needed a second attempt — not a page still stale now.', 'signal-and-noise-tools' ),
		$delay
	);
	$hint = sprintf(
		/* translators: 1: probes retained, 2: how many of them were stale */
		_n( '%1$d retained, %2$d stale', '%1$d retained, %2$d stale', count( $d['probe_log'] ), 'signal-and-noise-tools' ),
		count( $d['probe_log'] ),
		$stale
	);
	return \snt_kit_tag(
		'os-disclosure',
		array( 'heading' => __( 'Post-purge probes', 'signal-and-noise-tools' ), 'hint' => $hint, 'open' => 'stale' === $newest ),
		'<p class="snt-prose">' . \snt_kit_esc( $intro ) . '</p>'
		. \snt_kit_table(
			array(
				array( 'key' => 'when', 'label' => __( 'When', 'signal-and-noise-tools' ) ),
				array( 'key' => 'result', 'label' => __( 'Result', 'signal-and-noise-tools' ) ),
				array( 'key' => 'page', 'label' => __( 'Page', 'signal-and-noise-tools' ) ),
			),
			$rows,
			array( 'empty' => __( 'No probes recorded.', 'signal-and-noise-tools' ) )
		)
	);
}

/**
 * The cache status box: configured (with the last purge) or not.
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
	$line = '';
	if ( ! empty( $last['time'] ) ) {
		$kind = ( 'all' === (string) ( $last['kind'] ?? '' ) )
			? __( 'full zone', 'signal-and-noise-tools' )
			/* translators: %d: URLs purged */
			: sprintf( __( '%d URL(s)', 'signal-and-noise-tools' ), (int) ( $last['count'] ?? 0 ) );
		/* translators: 1: how long ago, 2: what was purged */
		$line = ' ' . sprintf( __( 'Last purge: %1$s ago (%2$s).', 'signal-and-noise-tools' ), human_time_diff( (int) $last['time'], time() ), $kind );
	}
	return \snt_kit_notice(
		'ok',
		'<b>' . \snt_kit_esc( __( 'Configured: auto-purge active', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_badge( 'ok', __( 'Active', 'signal-and-noise-tools' ) ) . '<br>'
		. \snt_kit_esc( __( 'Cache purges fire automatically on post save, theme update, and via the REST endpoint.', 'signal-and-noise-tools' ) . $line )
	);
}

/**
 * The manual purge card: `<os-card compact>` with header / body / footer
 * (kit-help "Card"), the button posting `cf_purge_now` through the shared
 * handler table and disabled until configured, as the classic button is.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_purge_html( array $d ) {
	return \snt_kit_tag(
		'os-card',
		array( 'compact' => true ),
		'<header><h3>' . \snt_kit_esc( __( 'Purge Everything Now', 'signal-and-noise-tools' ) ) . '</h3></header>'
		. '<p>' . \snt_kit_esc( __( 'Clears the entire Cloudflare zone cache. Use after manual edits to global elements.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<footer>' . \snt_kit_action_button( __( 'Purge Cloudflare', 'signal-and-noise-tools' ), 'cf_purge_now', array( 'disabled' => empty( $d['is_configured'] ) ) ) . '</footer>'
	);
}

/**
 * The Cloudways purge status, when that module is configured: it rides the
 * same purge chain, so a failed leg is visible next to the rest of it.
 *
 * @param array<string,mixed>|null $cw SNT_CW_LAST_PURGE_OPT, or null when Cloudways is not configured.
 * @return string
 */
function cloudflare_cloudways_html( $cw ) {
	if ( ! is_array( $cw ) ) {
		return '';
	}
	$attempted = ! empty( $cw['time'] );
	$warn      = $attempted && empty( $cw['ok'] );
	$line      = '';
	if ( $attempted ) {
		/* translators: %s: how long ago */
		$line = ' ' . sprintf( __( 'Last attempt: %s ago.', 'signal-and-noise-tools' ), human_time_diff( (int) $cw['time'], time() ) );
		if ( $warn ) {
			$line .= ' HTTP ' . (string) ( $cw['http'] ?? 0 );
			if ( '' !== trim( (string) ( $cw['error'] ?? '' ) ) ) {
				$line .= ': ' . (string) $cw['error'];
			}
		}
	}
	$pill = $warn ? __( 'Error', 'signal-and-noise-tools' ) : ( $attempted ? __( 'OK', 'signal-and-noise-tools' ) : __( 'Active', 'signal-and-noise-tools' ) );
	return \snt_kit_notice(
		$warn ? 'warn' : 'ok',
		'<b>' . \snt_kit_esc( __( 'Cloudways purge', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_badge( $warn ? 'warn' : 'ok', $pill ) . '<br>'
		. \snt_kit_esc( __( 'Rides the same purge chain (Varnish leg).', 'signal-and-noise-tools' ) . $line )
	);
}
