<?php
/**
 * S&N Dashboard — Site → Redirects, painted from the kit.
 *
 * The classic leaf (inc/redirects-admin.php, `sn_redirects_render_admin_tab()`
 * behind `sn_admin_render_redirects_section()`) paints the redirect manager in
 * the main column and the 404 log in the rail: one edit form per redirect
 * (`redirect_update` / `redirect_delete`), the add form (`redirect_add`), the
 * broken-links status, the probe bucket (`redirect_404_clear_probes`), one
 * create / dismiss form per broken path (`redirect_add` / `redirect_404_delete`)
 * and the whole-log clear (`redirect_404_clear`). Same readers, same fields,
 * same handlers; the kit's parts instead of wp-admin's.
 *
 * A classic row form carried TWO submit buttons; an `<os-form>` has one, and a
 * one-click button replays only `action` + `nonce` (posted_values() in the app
 * definition), so every per-row action that reads `source` is its own form.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/site-redirects-parts.php';

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
 * The intro the classic leaf opens with.
 *
 * @return string
 */
function redirects_intro_html() {
	return '<p class="snt-prose">'
		. \snt_kit_esc( __( 'Send old or broken URLs to a new destination with a 301 (permanent) or 302 (temporary) redirect. Targets can be an on-site path (', 'signal-and-noise-tools' ) )
		. \snt_kit_code( '/new-page', false )
		. \snt_kit_esc( __( ') or a full external URL (', 'signal-and-noise-tools' ) )
		. \snt_kit_code( 'https://…', false )
		. \snt_kit_esc( __( '). The ', 'signal-and-noise-tools' ) )
		. '<strong>' . \snt_kit_esc( __( '404 log', 'signal-and-noise-tools' ) ) . '</strong>'
		. \snt_kit_esc( __( ' in the sidebar surfaces paths visitors actually hit that don’t exist: one click turns any of them into a redirect.', 'signal-and-noise-tools' ) )
		. '</p>';
}

/**
 * The rail: the broken-links status, the probe bucket, one section per broken
 * path, and the whole-log clear.
 *
 * @param array<string,mixed> $data From redirects_data().
 * @return string
 */
function redirects_rail_html( array $data ) {
	$total = count( $data['broken'] );
	$out   = redirects_status_html( $total );
	if ( ! empty( $data['probes'] ) ) {
		$out .= redirects_probes_html( (array) $data['probes'], (int) $data['probe_hits'] );
	}
	if ( $total > 0 ) {
		foreach ( $data['broken'] as $path => $row ) {
			$out .= redirects_404_row_html( (string) $path, (array) $row['entry'], (string) $row['suggested'] );
		}
		$out .= \snt_kit_action_button(
			__( 'Clear 404 log', 'signal-and-noise-tools' ),
			'redirect_404_clear',
			array(
				'confirm'       => __( 'Clear the entire 404 log?', 'signal-and-noise-tools' ),
				'confirm_label' => __( 'Clear', 'signal-and-noise-tools' ),
			)
		);
	}
	return $out;
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_site_redirects( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return ''; // The classic renderer's own gate: a silent return, never a wp_die.
	}
	$data = redirects_data();
	$main = '';
	foreach ( $data['redirects'] as $source => $r ) {
		$main .= redirects_row_html( (string) $source, (array) $r );
	}
	$main .= redirects_add_html();
	return redirects_intro_html()
		. '<div class="snt-cols">'
		. '<section class="snt-col">' . \snt_kit_tag( 'os-stack', array( 'gap' => '12' ), $main ) . '</section>'
		. \snt_kit_tag( 'aside', array( 'class' => 'snt-col', 'aria-label' => __( 'Broken links (404s)', 'signal-and-noise-tools' ) ), \snt_kit_tag( 'os-stack', array( 'gap' => '12' ), redirects_rail_html( $data ) ) )
		. '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/redirects'] = __NAMESPACE__ . '\\paint_site_redirects';
		return $painters;
	}
);
