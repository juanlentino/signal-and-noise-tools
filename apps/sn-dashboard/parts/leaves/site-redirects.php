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
	// v13.109.8: full width, no rail. The 404 log moved to Site → Broken links —
	// it was 192 rows in a ~1fr column beside this table, which is the
	// dead-half-window defect the parity pass removes. Pattern B: a redirect map
	// is tabular, so give it the width rather than half of it.
	return redirects_intro_html()
		. \snt_kit_tag( 'os-stack', array( 'gap' => '12' ), $main );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/redirects'] = __NAMESPACE__ . '\\paint_site_redirects';
		return $painters;
	}
);
