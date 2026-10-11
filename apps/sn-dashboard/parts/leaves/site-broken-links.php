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
 * 2026-10-11 (owner rule: no collapsible sections): the per-path folds and the
 * probe fold became two tiles, one finding, the broken-path ledger with one
 * visible row action each (site-broken-links-ledger.php), ONE region holding
 * the create and dismiss forms for the path `sn_edit` names (below), and the
 * open probe ledger (site-broken-links-probes.php). Handlers, field names and
 * nonces untouched.
 *
 * @package SignalNoiseTools
 * @since 13.109.8
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/site-redirects-parts.php';
require_once __DIR__ . '/site-broken-links-ledger.php';
require_once __DIR__ . '/site-broken-links-probes.php';

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
 * The ONE region for the path `sn_edit` names, holding every write for it:
 * the redirect_add form prefilled with the slug suggestion, the
 * redirect_404_delete Dismiss with one line saying what it does, and a Cancel.
 *
 * @param string              $path      The 404'd path (a key the log holds).
 * @param array<string,mixed> $e         count, last_seen, referer.
 * @param string              $suggested Suggested target ('' when none).
 * @return string
 */
function broken_links_create_html( $path, array $e, $suggested ) {
	$opts = array( 'placeholder' => '/new-page  or  https://…' );
	if ( '' !== $suggested ) {
		$opts['hint'] = __( 'Suggested from your published slugs (closest match); review before creating.', 'signal-and-noise-tools' );
	}
	$fields = \snt_kit_field( 'hidden', 'source', '', $path ) . \snt_kit_field( 'text', 'target', __( 'Redirect to', 'signal-and-noise-tools' ), $suggested, $opts );
	$host   = broken_links_referrer( $e, '' );
	$last   = (int) ( $e['last_seen'] ?? 0 );
	return \snt_kit_section(
		/* translators: %s: the broken path. */
		sprintf( __( 'Fixing %s', 'signal-and-noise-tools' ), $path ),
		\snt_kit_form( 'redirect_add', $fields, array( 'submit' => __( 'Create redirect', 'signal-and-noise-tools' ) ) )
			. '<p class="snt-hint">' . \snt_kit_esc( __( 'Dismiss removes the path from the log without a redirect.', 'signal-and-noise-tools' ) ) . '</p>'
			. \snt_kit_form( 'redirect_404_delete', \snt_kit_field( 'hidden', 'source', '', $path ), array( 'submit' => __( 'Dismiss', 'signal-and-noise-tools' ) ) )
			. \snt_kit_button( __( 'Cancel', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => 'broken-links', 'anchor' => 'snt-edit-list' ) ) ),
		sprintf(
			/* translators: 1: "7 hits", 2: date of the latest hit (Y-m-d), 3: "from <host>" or "no referrer recorded". */
			__( '%1$s, last %2$s, %3$s.', 'signal-and-noise-tools' ),
			broken_links_hits( (int) ( $e['count'] ?? 0 ) ),
			$last > 0 ? wp_date( 'Y-m-d', $last ) : __( 'unknown', 'signal-and-noise-tools' ),
			/* translators: %s: the referring host. */
			'' !== $host ? sprintf( __( 'from %s', 'signal-and-noise-tools' ), $host ) : __( 'no referrer recorded', 'signal-and-noise-tools' )
		),
		array( 'id' => 'snt-edit-region', 'tabindex' => '-1', 'data-snt-focus' => true )
	);
}

/**
 * The leaf, top-down: the reading, the broken-path ledger, the ONE region
 * (when `sn_edit` names a broken path), the probe ledger, and the
 * whole-log clear last.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_site_broken_links( array $ctx ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return ''; // The classic renderer's own gate: a silent return, never a wp_die.
	}
	$data   = redirects_data();
	$broken = (array) $data['broken'];
	$path   = redirects_editing( $ctx, array_keys( $broken ) );
	$out    = broken_links_reading_html( $data ) . broken_links_paths_html( $broken );
	if ( '' !== $path && isset( $broken[ $path ] ) ) {
		$out .= broken_links_create_html( $path, (array) $broken[ $path ]['entry'], (string) $broken[ $path ]['suggested'] );
	}
	if ( ! empty( $data['probes'] ) ) {
		$out .= broken_links_probes_html( (array) $data['probes'], (int) $data['probe_hits'] );
	}
	if ( array() !== $broken ) {
		$out .= \snt_kit_action_button(
			__( 'Clear 404 log', 'signal-and-noise-tools' ),
			'redirect_404_clear',
			array(
				'confirm'       => __( 'Clear the entire 404 log?', 'signal-and-noise-tools' ),
				'confirm_label' => __( 'Clear', 'signal-and-noise-tools' ),
			)
		);
	}
	// Full width, no wrapping grid: a pair grid would re-create the two-column
	// shell this split exists to leave behind.
	return broken_links_intro_html() . \snt_kit_tag( 'os-stack', array( 'gap' => '12' ), $out );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/broken-links'] = __NAMESPACE__ . '\\paint_site_broken_links';
		return $painters;
	}
);
