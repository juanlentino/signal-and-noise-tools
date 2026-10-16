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
	$label  = '' !== $result ? $result : __( 'unknown', 'signal-and-noise-tools' );
	$esc = function_exists( 'snt_cf_probe_escalation_label' ) ? \snt_cf_probe_escalation_label( $row ) : '';
	if ( '' !== $esc ) {
		$label .= ' ' . $esc;
	}
	if ( defined( 'SN_CF_PROBE_ALGO' ) && (int) ( $row['algo'] ?? 1 ) < SN_CF_PROBE_ALGO ) {
		$label .= ' · ' . __( 'retired detector', 'signal-and-noise-tools' );
	}
	// The URL minus the host: twenty identical origins is none of the information.
	$url  = (string) ( $row['url'] ?? '' );
	$path = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
	if ( '' === $path ) {
		// A manual zone purge names no single page; say which purge it was.
		// Unknown is never an em dash (2026-10-12 audit).
		$path = '' !== (string) ( $row['source'] ?? '' ) ? (string) $row['source'] : __( 'not recorded', 'signal-and-noise-tools' );
	}
	return array(
		/* translators: %s: how long ago */
		'when'   => $time ? sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( $time, time() ) ) : __( 'not recorded', 'signal-and-noise-tools' ),
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
	$intro = function_exists( 'snt_cf_probe_intro' ) ? \snt_cf_probe_intro( $delay ) : '';
	$retired = function_exists( 'snt_cf_probe_retired_note' ) ? snt_cf_probe_retired_note( $d['probe_log'] ) : '';
	if ( '' !== $retired ) {
		$intro = $retired . ' ' . $intro;
	}
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
		$kind = function_exists( 'sn_cf_last_purge_kind' ) ? \sn_cf_last_purge_kind( $last ) : '';
		/* translators: 1: relative time element, 2: what was purged */
		$when = sprintf( \snt_kit_esc( __( '%1$s (%2$s)', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( (int) $last['time'] ), \snt_kit_esc( $kind ) );
	}
	$rows = array(
		array( 'label' => __( 'Auto-purge', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_badge( 'ok', __( 'Active', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( function_exists( 'sn_cf_auto_purge_when' ) ? \sn_cf_auto_purge_when() : '' ) ),
		array( 'label' => __( 'Last purge', 'signal-and-noise-tools' ), 'html' => true, 'value' => $when ),
	);
	if ( function_exists( 'sn_cf_breeze_page_cache_words' ) ) {
		$breeze = \sn_cf_breeze_page_cache_words( get_option( 'sn_last_purge_report', array() ) );
		$rows[] = array( 'label' => __( 'Breeze page cache', 'signal-and-noise-tools' ), 'value' => $breeze, 'tone' => __( 'on', 'signal-and-noise-tools' ) === $breeze ? 'warn' : '' );
	}
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
 * The Cloudways purge as one facts row, when that module is configured: it
 * fires with the same post saves and theme updates, so a failed purge is
 * visible next to the rest. The verdict is the Cloudways leaf's own
 * (sn_admin_cloudways_leg(), 2026-10-12 audit: the two leaves called one
 * record by different names).
 *
 * @param array<string,mixed>|null $cw SNT_CW_LAST_PURGE_OPT, or null when Cloudways is not configured.
 * @return array<string,mixed>|null
 */
function cloudflare_cloudways_row( $cw ) {
	if ( ! is_array( $cw ) || ! function_exists( 'sn_admin_cloudways_leg' ) ) {
		return null;
	}
	$leg  = \sn_admin_cloudways_leg( $cw, ! function_exists( 'sn_admin_cloudways_on' ) || \sn_admin_cloudways_on() );
	$line = '' !== $leg['value'] ? \snt_kit_esc( $leg['value'] ) . '.' : '';
	if ( $leg['time'] > 0 ) {
		/* translators: %s: relative time element */
		$line .= ' ' . sprintf( \snt_kit_esc( __( 'Last attempt: %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( $leg['time'] ) );
	}
	$line .= ' ' . str_replace( array( '<code>', '</code>' ), array( '<os-code>', '</os-code>' ), \wp_kses_post( $leg['meta'] ) );
	return array( 'label' => __( 'Cloudways purge', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_badge( $leg['kind'], $leg['text'] ) . ' ' . trim( $line ), 'tone' => in_array( $leg['kind'], array( 'warn', 'err' ), true ) ? 'warn' : '' );
}

/**
 * The Status card (2026-10-16): one status line answering "is the cache
 * wired?" (auto-purge, the last purge, the token as the monitor read it, the
 * probes' stale count), and the analytics-token warning, which needs action
 * and so is never folded with the keys.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_status_card_html( array $d ) {
	$on    = ! empty( $d['is_configured'] );
	$last  = (array) $d['last_purge'];
	$chips = array(
		$on ? array( 'tone' => 'ok', 'label' => __( 'Auto-purge active', 'signal-and-noise-tools' ) ) : array( 'tone' => 'warn', 'label' => __( 'Not configured', 'signal-and-noise-tools' ) ),
		array(
			'tone'  => 'neutral',
			'label' => ! empty( $last['time'] )
				/* translators: %s: how long ago, e.g. "1 hour". */
				? sprintf( __( 'Last purge: %s ago', 'signal-and-noise-tools' ), human_time_diff( (int) $last['time'], time() ) )
				: __( 'Last purge: never', 'signal-and-noise-tools' ),
		),
		cloudflare_token_chip( function_exists( __NAMESPACE__ . '\\cloudflare_monitor_record' ) ? cloudflare_monitor_record() : null ),
	);
	$log = (array) ( $d['probe_log'] ?? array() );
	if ( $on && array() !== $log ) {
		$stale   = count( array_filter( $log, static function ( $r ) { return 'stale' === (string) ( $r['result'] ?? '' ); } ) );
		/* translators: 1: probes retained, 2: stale probes. */
		$chips[] = array( 'tone' => $stale > 0 ? 'warn' : 'ok', 'label' => sprintf( __( 'Probes: %1$d retained, %2$d stale', 'signal-and-noise-tools' ), count( $log ), $stale ) );
	}
	$warn = '';
	if ( '' !== (string) ( $d['analytics_override'] ?? '' ) ) {
		$warn = \snt_kit_notice( 'warning', \snt_kit_esc( 'constant' === $d['analytics_override']
			? __( 'Analytics still reads with the SN_CF_ANALYTICS_TOKEN constant, not this token. Remove the constant from wp-config.php to use one token.', 'signal-and-noise-tools' )
			: __( 'Analytics still reads with a separate token saved under Measurement › Analytics › Credentials. Drop it there to use this one.', 'signal-and-noise-tools' ) ) );
	}
	return \snt_kit_section( __( 'Status', 'signal-and-noise-tools' ), \snt_kit_status_line( $chips ) . $warn );
}

/**
 * The token as the monitor last read it, as one status-line chip.
 *
 * @param array<string,mixed>|null $record cloudflare_monitor_record().
 * @return array<string,string> A status-line chip ('' label paints none).
 */
function cloudflare_token_chip( $record ) {
	if ( ! is_array( $record ) ) {
		return array( 'tone' => 'neutral', 'label' => __( 'Token not read yet', 'signal-and-noise-tools' ) );
	}
	if ( empty( $record['configured'] ) ) {
		return array( 'label' => '' );
	}
	$t = is_array( $record['token'] ?? null ) ? $record['token'] : array();
	if ( 'changed' === (string) ( $t['status'] ?? '' ) ) {
		return array( 'tone' => 'warn', 'label' => __( 'Token changed, not read yet', 'signal-and-noise-tools' ) );
	}
	if ( empty( $t['verified'] ) ) {
		return array( 'tone' => 'bad', 'label' => __( 'Token not verified', 'signal-and-noise-tools' ) );
	}
	if ( 'active' !== (string) ( $t['status'] ?? '' ) ) {
		/* translators: %s: the token's status as Cloudflare reports it. */
		return array( 'tone' => 'bad', 'label' => sprintf( __( 'Token %s', 'signal-and-noise-tools' ), (string) $t['status'] ) );
	}
	$exp = '' !== (string) ( $t['expires_on'] ?? '' ) ? (int) strtotime( (string) $t['expires_on'] ) : 0;
	if ( $exp && $exp < time() + 14 * DAY_IN_SECONDS ) {
		/* translators: %s: time until the token expires. */
		return array( 'tone' => 'warn', 'label' => sprintf( __( 'Token expires in %s', 'signal-and-noise-tools' ), human_time_diff( time(), $exp ) ) );
	}
	return array( 'tone' => 'ok', 'label' => __( 'Token active', 'signal-and-noise-tools' ) );
}
