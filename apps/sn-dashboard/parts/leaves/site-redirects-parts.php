<?php
/**
 * S&N Dashboard — Site → Redirects: the pieces the leaf paints.
 *
 * Required by site-redirects.php. Every function is prefixed `redirects_`.
 *
 * 2026-10-11 (no folds): what both leaves share stays here (the readings,
 * the confirm-carrying form, the 301/302 options, the `sn_edit` param). The
 * Redirects ledger and its forms live in site-redirects-rules.php; the Broken
 * links ledger in site-broken-links-ledger.php and its probes in
 * site-broken-links-probes.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * v13.109.7: moved here from site-redirects.php. BOTH leaves read it now —
 * Redirects paints the rules, Broken links paints the 404s — so it belongs in
 * the shared parts file rather than inside one of its two consumers.
 */
/**
 * The leaf's readings, the way the classic renderer reads them: the redirect
 * map newest first; the actionable 404 log split into broken paths (busiest
 * first, each with its slug suggestion) and automated probes (busiest first).
 *
 * @return array{redirects:array,broken:array,probes:array,probe_hits:int}
 */
function redirects_data() {
	$redirects  = \sn_redirects_all();
	$log        = \sn_404_log_actionable();
	$candidates = function_exists( 'sn_404_published_paths' ) ? \sn_404_published_paths() : array();
	$host       = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$part       = function_exists( 'sn_404_log_partition' )
		? \sn_404_log_partition( $log, $candidates, $host )
		: array( 'actionable' => $log, 'probes' => array() );
	$busiest    = static function ( $a, $b ) {
		return (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
	};
	$broken = (array) $part['actionable'];
	$probes = (array) $part['probes'];
	uasort( $broken, $busiest );
	uasort( $probes, $busiest );
	$rows = array();
	foreach ( $broken as $path => $entry ) {
		$rows[ $path ] = array(
			'entry'     => (array) $entry,
			'suggested' => function_exists( 'sn_404_suggest_target' ) ? (string) \sn_404_suggest_target( (string) $path, $candidates ) : '',
		);
	}
	$hits = 0;
	foreach ( $probes as $entry ) {
		$hits += (int) ( $entry['count'] ?? 0 );
	}
	return array(
		'redirects'  => array_reverse( $redirects, true ),
		'broken'     => $rows,
		'probes'     => $probes,
		'probe_hits' => $hits,
	);
}

/**
 * `snt_kit_form()` plus the two confirm attributes the helper does not take —
 * `os-confirm-title` and `os-confirm-label`, both in the framework's trigger
 * vocabulary — which the classic Delete button carries.
 *
 * @param string              $sn_action Handler action.
 * @param string              $inner     Painted fields.
 * @param array<string,mixed> $opts      As snt_kit_form(), plus confirm_title, confirm_label.
 * @return string
 */
function redirects_form( $sn_action, $inner, array $opts = array() ) {
	$html  = \snt_kit_form( $sn_action, $inner, $opts );
	$extra = \snt_kit_attr(
		array(
			'os-confirm-title' => isset( $opts['confirm_title'] ) ? (string) $opts['confirm_title'] : null,
			'os-confirm-label' => isset( $opts['confirm_label'] ) ? (string) $opts['confirm_label'] : null,
		)
	);
	if ( '' === $extra || 0 !== strpos( $html, '<os-form' ) ) {
		return $html; // Nothing to splice in, or snt_kit_form()'s shape changed underneath us — never inject blind.
	}
	return substr_replace( $html, $extra, strlen( '<os-form' ), 0 );
}

/**
 * The 301/302 choice, as the classic select offers it.
 *
 * @return array<string,string>
 */
function redirects_status_options() {
	return array(
		'301' => __( '301. Permanent', 'signal-and-noise-tools' ),
		'302' => __( '302. Temporary', 'signal-and-noise-tools' ),
	);
}

/**
 * The `sn_edit` value a row's Edit (or Fix) carries: the key itself, or past
 * the host's 200-character param cap (snt_os_host_params()) a short hash of
 * it, so a long path still opens its region.
 *
 * @param string $key A rule's source or a broken path.
 * @return string
 */
function redirects_edit_value( $key ) {
	$key = (string) $key;
	return strlen( $key ) <= 200 ? $key : 'h:' . substr( md5( $key ), 0, 16 );
}

/**
 * The item a row action opened: the key among `$keys` whose
 * redirects_edit_value() is the `sn_edit` param, '' when none, so an unknown
 * or stale value opens nothing.
 *
 * @param array<string,mixed> $ctx  Leaf context (tab, sub, state, os).
 * @param array<int,string>   $keys The rules' sources or the broken paths.
 * @return string
 */
function redirects_editing( array $ctx, array $keys ) {
	$state  = $ctx['state'] ?? null;
	$params = ( is_object( $state ) && method_exists( $state, 'get' ) ) ? (array) $state->get( 'params' ) : array();
	$value  = $params['sn_edit'] ?? '';
	if ( ! is_scalar( $value ) || '' === (string) $value ) {
		return '';
	}
	foreach ( $keys as $key ) {
		if ( redirects_edit_value( $key ) === (string) $value ) {
			return (string) $key;
		}
	}
	return '';
}
