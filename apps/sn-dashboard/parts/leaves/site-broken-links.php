<?php
/**
 * S&N Dashboard — Site → Broken links, painted from the kit.
 *
 * Split out of `site-redirects.php` in v13.109.7. The classic leaf is
 * `sn_redirects_render_broken_links_tab()` behind
 * `sn_admin_render_broken_links_section()`.
 *
 * WHY THIS IS ITS OWN LEAF. It used to be the RAIL of Site → Redirects, and a
 * rail cannot hold this list: measured live on 2026-09-10 the log offered **192
 * broken paths** as actionable against 8 classified as probes. Rendered into a
 * ~1fr column beside a redirect table, it produced an unbounded stack next to a
 * much shorter column — the dead-half-window defect the layout parity pass
 * exists to remove, and the reason a real `/login` rule could not be found by
 * looking.
 *
 * Pattern B from the parity spec: full-width sections, no rail. It earns no rail
 * because nothing here could honestly fill one — and a half-empty rail is the
 * same defect wearing different chrome.
 *
 * The CONTENT is deliberately unchanged by the split — the same sections, from
 * the same `redirects_*_html()` helpers in `site-redirects-parts.php`. What
 * changed is the width they are given. Behaviour, handlers and nonces untouched.
 *
 * @package SignalNoiseTools
 * @since 13.109.8
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/site-redirects-parts.php';

/**
 * The intro this leaf opens with — the sentence Redirects used to carry about
 * its sidebar, now said where the list actually lives.
 *
 * @return string
 */
function broken_links_intro_html() {
	return \snt_kit_tag(
		'p',
		array( 'class' => 'snt-prose' ),
		\snt_kit_esc(
			__( 'Paths visitors hit that do not exist here. A path earns a row when it resembles something published on this site, or when something here links to it; scanner traffic is counted separately and needs no decision.', 'signal-and-noise-tools' )
		)
	);
}

/**
 * The broken-links sections: status, the probe bucket, one section per broken
 * path, and the whole-log clear.
 *
 * Was `redirects_rail_html()` in site-redirects.php until v13.109.7, when the
 * 404 log became its own leaf. Renamed with it: nothing here is a rail any more,
 * and a name that says otherwise is the next reader's wrong turn.
 *
 * @param array<string,mixed> $data From redirects_data().
 * @return string
 */
function broken_links_sections_html( array $data ) {
	$total = count( $data['broken'] );
	$out   = redirects_status_html( $total );
	if ( ! empty( $data['probes'] ) ) {
		$out .= redirects_probes_html( (array) $data['probes'], (int) $data['probe_hits'] );
	}
	if ( $total > 0 ) {
		// Busiest first, then capped. A 404 log grows without bound and the tail
		// is the part nobody acts on; painting all of it cost nine screens of
		// scrolling on the live site. The true total is stated by
		// redirects_status_html() above regardless of what is listed here, so
		// the cap can never make the log look smaller than it is.
		$listed = $data['broken'];
		uasort(
			$listed,
			static function ( $a, $b ) {
				return (int) ( $b['entry']['count'] ?? 0 ) <=> (int) ( $a['entry']['count'] ?? 0 );
			}
		);
		$shown = array_slice( $listed, 0, SN_404_LIST_CAP, true );
		foreach ( $shown as $path => $row ) {
			$out .= redirects_404_row_html( (string) $path, (array) $row['entry'], (string) $row['suggested'] );
		}
		if ( $total > SN_404_LIST_CAP ) {
			$out .= '<p class="snt-hint">' . \snt_kit_esc(
				sprintf(
					/* translators: 1: paths listed, 2: paths in the log in total. */
					__( 'Showing the %1$s busiest of %2$s broken paths. Clear the log or act on these to see the rest.', 'signal-and-noise-tools' ),
					number_format_i18n( SN_404_LIST_CAP ),
					number_format_i18n( $total )
				)
			) . '</p>';
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
function paint_site_broken_links( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return ''; // The classic renderer's own gate: a silent return, never a wp_die.
	}
	$data = redirects_data();

	// Full width, no wrapping grid. `.snt-cols` would re-create the two-column
	// shell this split exists to leave behind.
	return broken_links_intro_html()
		. \snt_kit_tag( 'os-stack', array( 'gap' => '12' ), broken_links_sections_html( $data ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/broken-links'] = __NAMESPACE__ . '\\paint_site_broken_links';
		return $painters;
	}
);
