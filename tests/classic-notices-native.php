<?php
/**
 * Tests: every classic notice is wp_admin_notice() (#1618).
 *
 * At 17.4.3 the plugin echoed 37 `<div class="notice notice-...">` literals
 * across 22 files, and neither wp_admin_notice() nor wp_get_admin_notice()
 * appeared in inc. Core has shipped both since 6.4; the plugin floor is 7.0.
 * The rendered markup is the same either way; what the port buys is one seam:
 * every notice fires the `wp_admin_notice` action and passes the
 * `wp_admin_notice_markup` filter, so a future pin or shell filter sees all of
 * them from one hook instead of 22 files.
 *
 * Three parts. A source walk over inc/ and the bootstrap: zero hand-echoed
 * `<div ... notice notice-` literals, at least 35 calls (the vacuity guard,
 * so a walk that matched nothing cannot pass), and every quoted word from a
 * call's `'type' =>` to the end of that line is one word with no space, since
 * core _doing_it_wrong()s a `type` with a space (functions.php:9196) and the
 * stub throws on the same input. Then one representative per file group,
 * rendered through core's markup (tests/lib/wp-admin-notice-stub.php is core
 * 7.1's getter with two named departures: array_merge for wp_parse_args, a
 * throw for _doing_it_wrong): the bytes are the classic literal's, and the
 * seam saw the notice, which the unfixed code never did. Then the kses half:
 * the echo is core's own wp_kses_post() (the stub loads wp-includes/kses.php
 * and the HTML API from the SNT_WP_HTML_API seam, which CI fetches), and a
 * negative control proves it strips `<script>`, `onclick`, `<input>` and
 * `<form>`, so the representative bytes above were measured through the
 * runtime's filter, not a pass-through. Without a checkout the kses part is
 * a SKIP locally and, as in tests/openstation-host.php, a FAIL under CI.
 *
 * The one `<p class="sn-mr-truncated notice notice-warning inline">` in
 * inc/machine-readers-render.php stays as it is: it is a paragraph styled as
 * a notice, not a notice div, and the getter only emits a div.
 *
 * @since 17.4.4
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }

$pass = 0; $fail = 0; $skip = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// ── Hooks that RECORD: the seam is the thing under test.
$GLOBALS['__actions_fired'] = array();
$GLOBALS['__markup_seen']   = array();
$GLOBALS['__actions']       = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
function has_action( $hook, $cb = false ) { return ! empty( $GLOBALS['__actions'][ $hook ] ); }
function do_action( $hook ) { $GLOBALS['__actions_fired'][] = array_slice( func_get_args(), 0 ); }
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { return true; }
function apply_filters( $hook, $value ) {
	if ( 'wp_admin_notice_markup' === $hook ) { $GLOBALS['__markup_seen'][] = $value; }
	return $value;
}

// ── Escaping, i18n, formatting, the few WP reads the representatives touch.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function human_time_diff( $from, $to = 0 ) { return '5 mins'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $cap ) { return true; }
function wp_die( $m = '' ) { throw new RuntimeException( 'wp_die: ' . $m ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); }
function get_option( $k, $d = false ) { return $d; }
function sn_prepop_fields() { return array( '_sn_autogen_excerpt' => 'excerpt', '_sn_autogen_og_card_title' => 'OG card title' ); }
function sn_admin_flash_to_notice( $key ) { return 'purged' === $key ? array( 'success', 'Purged. <a href="https://example.test/log">See the log</a>.' ) : null; }

require_once __DIR__ . '/lib/wp-admin-notice-stub.php';

require_once __DIR__ . '/../inc/rss-feed-tracker.php';        // post-redirect results
require_once __DIR__ . '/../inc/admin-render-sections.php';   // inline warning inside a tab
require_once __DIR__ . '/../inc/machine-readers-render.php';  // notice-alt inline readouts that RETURN a string
require_once __DIR__ . '/../inc/batch-schedule.php';          // admin_notices hook
require_once __DIR__ . '/../inc/ai-prepopulate-notice.php';   // attributes + a Dismiss button
require_once __DIR__ . '/../inc/analytics-dashboard-page.php'; // the flash renderer

function capture( callable $fn ) {
	ob_start();
	$fn();
	return (string) ob_get_clean();
}
function last_notice_args() {
	$fired = array_values( array_filter( $GLOBALS['__actions_fired'], function ( $a ) { return 'wp_admin_notice' === $a[0]; } ) );
	$last  = end( $fired );
	return is_array( $last ) && isset( $last[2] ) && is_array( $last[2] ) ? $last[2] : array();
}

// ═════════════════════════════════════════════════════════════════════════
echo "Group: source walk, inc/ and the bootstrap\n";
$literal_lines = array();
$call_count    = 0;
$type_lines    = 0;
$type_spaced   = array();
$code_lines    = array();
$files         = array( SNT_PATH . 'signal-and-noise-tools.php' );
$walk          = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SNT_PATH . 'inc', FilesystemIterator::SKIP_DOTS ) );
foreach ( $walk as $file ) {
	if ( $file->isFile() && 'php' === strtolower( (string) $file->getExtension() ) ) {
		$files[] = (string) $file->getPathname();
	}
}
sort( $files );
foreach ( $files as $path ) {
	foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $i => $line ) {
		$t = ltrim( $line );
		if ( '' === $t || '*' === $t[0] || '#' === $t[0] || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '/*' ) ) {
			continue;
		}
		$code = (string) preg_replace( '~\s//.*$~', '', $line );
		if ( preg_match( '/<div\b[^>]*class="[^"]*\bnotice notice-/', $code ) ) {
			$literal_lines[] = str_replace( SNT_PATH, '', $path ) . ':' . ( $i + 1 );
		}
		$call_count += preg_match_all( '/\bwp_(?:get_)?admin_notice\(/', $code );
		$code_lines[] = $code;
	}
	// The `type` walk: from each call, the first `'type' =>` and every quoted word to the end of that line.
	foreach ( array_slice( preg_split( '/\bwp_(?:get_)?admin_notice\(/', implode( "\n", $code_lines ) ), 1 ) as $chunk ) {
		if ( preg_match( '/\'type\'\s*=>[^\n]*/', $chunk, $m ) ) {
			$type_lines++;
			preg_match_all( '/\'([^\']*)\'/', $m[0], $words );
			foreach ( $words[1] as $w ) {
				if ( preg_match( '/\s/', $w ) ) { $type_spaced[] = str_replace( SNT_PATH, '', $path ) . ": '" . $w . "'"; }
			}
		}
	}
	$code_lines = array();
}
ok( count( $files ) > 100, 'the walk saw the tree (' . count( $files ) . ' files)' );
ok( array() === $literal_lines, 'no hand-echoed <div class="notice notice-..."> literal remains' . ( $literal_lines ? ': ' . implode( ', ', $literal_lines ) : '' ) );
ok( $call_count >= 35, "at least 35 wp_admin_notice()/wp_get_admin_notice() calls (vacuity guard): $call_count" );
ok( $type_lines >= 35, "at least 35 calls name a type (vacuity guard for the type walk): $type_lines" );
ok( array() === $type_spaced, 'type is the one word at every call, and so is every class beside it on the line (core _doing_it_wrong()s a space)' . ( $type_spaced ? ': ' . implode( ', ', $type_spaced ) : '' ) );
$mr_src = (string) file_get_contents( SNT_PATH . 'inc/machine-readers-render.php' );
ok( false !== strpos( $mr_src, '<p class="sn-mr-truncated notice notice-warning inline">' ), 'the truncation readout stays a <p>: a paragraph styled as a notice, not a notice div' );

// ═════════════════════════════════════════════════════════════════════════
echo "\nGroup: the fixture refuses what the runtime refuses\n";
$threw = false;
try { wp_get_admin_notice( 'x', array( 'type' => 'warning inline' ) ); } catch ( RuntimeException $e ) { $threw = true; }
ok( $threw, 'the stub throws on a type with a space, where core _doing_it_wrong()s (so no representative can paint what core refuses)' );
ok( '<div class="notice notice-warning inline"><p>x</p></div>' === wp_get_admin_notice( 'x', array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) ), 'and the one-word type with inline as a class is the classic literal' );
if ( SNT_KSES_IS_CORE ) {
	$dirty = '<div class="notice notice-info"><p>x <script>alert(1)</script> <a href="https://e.test/?a=1&b=2" onclick="x()">l</a> <input type="text"> <form></form></p></div>';
	ok( '<div class="notice notice-info"><p>x alert(1) <a href="https://e.test/?a=1&amp;b=2">l</a>  </p></div>' === wp_kses_post( $dirty ), 'negative control: the echo is core kses, which strips <script>, onclick, <input> and <form> (a pass-through would keep them)' );
	ok( function_exists( 'wp_kses_allowed_html' ) && isset( wp_kses_allowed_html( 'post' )['button']['data-*'] ), 'and the post context of the loaded kses allows <button data-*>, which the prepop notice needs' );
} else {
	$skip += 2;
	echo "  SKIP - core kses: no WordPress checkout on this machine (SNT_WP_HTML_API=<wp>/wp-includes/html-api with kses.php beside it); the echo ran a pass-through\n";
}

// ═════════════════════════════════════════════════════════════════════════
echo "\nGroup: post-redirect result (inc/rss-feed-tracker.php)\n";
$GLOBALS['__actions_fired'] = array();
$html = capture( function () { sn_rss_tracker_render_flash( 'saved' ); } );
ok( '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>' === $html, 'the bytes are the classic literal: ' . $html );
$args = last_notice_args();
ok( 'success' === ( $args['type'] ?? '' ) && true === ( $args['dismissible'] ?? false ), 'the wp_admin_notice action saw type=success, dismissible' );
$html = capture( function () { sn_rss_tracker_render_flash( 'purged-1234' ); } );
ok( '<div class="notice notice-success is-dismissible"><p>Purged 1,234 log entries.</p></div>' === $html, 'the count still formats through number_format_i18n: ' . $html );

// ═════════════════════════════════════════════════════════════════════════
echo "\nGroup: inline warning inside a tab (inc/admin-render-sections.php)\n";
$GLOBALS['__actions_fired'] = array();
unset( $GLOBALS['__actions']['sn_admin_rss_tab'] ); // the tracker hooked it at load; the fallback paints only when nothing did.
$html = capture( 'sn_admin_render_rss_section' );
ok( '<div class="notice notice-warning inline sn-rss-not-installed"><p><strong>RSS feed-request tracker not loaded.</strong></p></div>' === $html, 'the bytes are the classic literal, inline first then the house class: ' . $html );
$args = last_notice_args();
ok( 'warning' === ( $args['type'] ?? '' ) && array( 'inline', 'sn-rss-not-installed' ) === ( $args['additional_classes'] ?? array() ), 'inline is an additional class, so common.js leaves the readout where the tab put it' );

// ═════════════════════════════════════════════════════════════════════════
echo "\nGroup: notice-alt inline readouts that return a string (inc/machine-readers-render.php)\n";
$GLOBALS['__markup_seen'] = array();
$html = snt_mr_render_edge_readout( array( 'version' => '1.21.0', 'deployed_at' => '2026-09-01T10:00:00Z', 'fetched_at' => time() - 300 ) );
ok( 0 === strpos( $html, '<div class="notice notice-info notice-alt inline"><p><strong>Worker</strong> <code>sn-rights-signals</code> <code>v1.21.0</code></p>' ), 'the edge readout opens as the classic literal did: ' . substr( $html, 0, 120 ) );
ok( '</p></div>' === substr( $html, -10 ) && false === strpos( $html, '<p><p>' ), 'paragraph_wrap is off: the readout keeps its own <p> tags, no double wrap' );
ok( 1 === count( $GLOBALS['__markup_seen'] ) && $GLOBALS['__markup_seen'][0] === $html, 'the getter passed wp_admin_notice_markup once, and returned what the filter saw' );
$GLOBALS['__markup_seen'] = array();
$html = snt_mr_render_sensor_card( array( 'version' => '1.3.0', 'deployed_at' => '2026-07-23T23:01:27Z' ) );
ok( false !== strpos( $html, '<div class="notice notice-warning notice-alt inline"><p><strong>Sensor outdated:</strong> the deployed worker is v1.3.0;' ), 'the outdated-sensor warning is the classic literal: ' . $html );
ok( 1 === count( $GLOBALS['__markup_seen'] ), 'and it passed the markup filter' );

// ═════════════════════════════════════════════════════════════════════════
echo "\nGroup: admin_notices hook (inc/batch-schedule.php)\n";
$GLOBALS['__actions_fired'] = array();
$_REQUEST                   = array( 'snt_batch_nodate' => '1' );
$html                       = capture( 'snt_batch_schedule_notice' );
ok( '<div class="notice notice-warning"><p>No date was entered, so nothing was rescheduled.</p></div>' === $html, 'the bytes are the classic literal: ' . $html );
ok( 'warning' === ( last_notice_args()['type'] ?? '' ), 'the action saw type=warning' );
$_REQUEST = array( 'snt_batch_moved' => '3', 'snt_batch_refused' => '1' );
$html     = capture( 'snt_batch_schedule_notice' );
ok( 0 === strpos( $html, '<div class="notice notice-warning"><p>' ) && false !== strpos( $html, '3' ), 'the counted result picks its type from the refusal count, as the printf did: ' . $html );
$_REQUEST = array();

// ═════════════════════════════════════════════════════════════════════════
echo "\nGroup: attributes and a Dismiss button (inc/ai-prepopulate-notice.php)\n";
$GLOBALS['__actions_fired'] = array();
$GLOBALS['__meta']          = array( 7 => array( '_sn_autogen_excerpt' => '1' ) );
$post                       = (object) array( 'ID' => 7 );
$html                       = capture( function () use ( $post ) { sn_prepop_render_notice( $post ); } );
ok( '<div class="notice notice-info sn-prepop-notice" data-post="7"><p>Auto-generated when you published: excerpt. <button type="button" class="button-link sn-prepop-dismiss">Dismiss</button></p></div>' === $html, 'data-post rides attributes, the house class rides additional_classes, the button survives: ' . $html );
$args = last_notice_args();
ok( array( 'data-post' => '7' ) === ( $args['attributes'] ?? array() ) && array( 'sn-prepop-notice' ) === ( $args['additional_classes'] ?? array() ), 'the action saw the attribute and the class' );
$js = (string) file_get_contents( SNT_PATH . 'assets/prepop-notice.js' );
ok( false !== strpos( $js, "closest( '.sn-prepop-notice' )" ) && false !== strpos( $js, "getAttribute( 'data-post' )" ), 'assets/prepop-notice.js still reads .sn-prepop-notice and data-post (class order moved; the selector does not care)' );
$GLOBALS['__meta'] = array();
ok( '' === capture( function () use ( $post ) { sn_prepop_render_notice( $post ); } ), 'no sentinel, no notice' );

// ═════════════════════════════════════════════════════════════════════════
echo "\nGroup: the flash renderer (inc/analytics-dashboard-page.php)\n";
$GLOBALS['__actions_fired'] = array();
$_GET                       = array( 'sn_flash' => 'purged' );
$html                       = capture( 'snt_analytics_dashboard_page' );
ok( false !== strpos( $html, '<div class="notice notice-success is-dismissible"><p>Purged. <a href="https://example.test/log">See the log</a>.</p></div>' ), 'the flash keeps its inline <a>, type from the fixed table: ' . $html );
ok( 'success' === ( last_notice_args()['type'] ?? '' ) && true === ( last_notice_args()['dismissible'] ?? false ), 'the action saw the mapped type, dismissible' );
$_GET = array( 'sn_flash' => 'not-a-key' );
$html = capture( 'snt_analytics_dashboard_page' );
ok( false === strpos( $html, 'class="notice' ), 'an unknown flash key paints no notice' );
$_GET = array();

$snt_ci = (string) getenv( 'CI' );
if ( $skip > 0 && '' !== $snt_ci && '0' !== $snt_ci && 'false' !== strtolower( $snt_ci ) ) {
	echo "\nFAILED: $skip pins were SKIPPED because wp-includes/kses.php is not on this machine, and the kses pass is the claim\n";
	echo "this suite exists to measure. Fix: fetch kses.php beside the HTML API in the workflow (SNT_WP_HTML_API).\n";
	$fail += $skip; // tests/run.sh reads the summary line, not the exit status.
}
echo "\nResult: $pass passed, $fail failed" . ( $skip > 0 ? ", $skip skipped" : '' ) . ".\n";
exit( $fail > 0 ? 1 : 0 );
