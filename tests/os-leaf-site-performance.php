<?php
/**
 * Native window leaf: Site → Performance (apps/sn-dashboard/parts/leaves/site-performance.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same field
 * name and the same sn_action in both the on and the off state, every rail
 * readout (status box, pill, profile reference) must survive, and none of
 * wp-admin's markup may. Second oracle: the Site Health panel
 * (sn_httpdiag_debug_information) for the slow admin requests ledger the
 * leaf paints at full width under the row.
 *
 * Run: php tests/os-leaf-site-performance.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's one reader: the setting by dot-path, default when unset.
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) { return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default; }

// The ledger's module: its load-time hook wiring asks is_admin(), which the harness lacks.
function is_admin() { return false; }
require SNT_PATH . 'inc/http-diagnostics.php';

require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-forms/performance.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-performance.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/performance'] ), 'the painter is registered under site/performance' );

// ── Enabled state: the same field, the same action.
$GLOBALS['__settings'] = array( 'perf.speculative_loading' => true );
$classic = snt_leaf_classic_html( 'sn_admin_render_performance_section' );
$kit     = snt_leaf_paint( 'site', 'performance' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'perf_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is perf_save, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the form is an os-form dispatching post through the admin-post pipeline (no pipeline declared: an action field is admin-post)' );
ok( false !== strpos( $kit, 'submit-label="Save"' ), 'the submit is labelled Save, as the classic button is' );
ok( false !== strpos( $kit, '<os-checkbox-label name="speculative_loading" value="1" checked label="Enabled: prerender the pages a visitor is likely to open next"' ), 'the toggle is a kit checkbox carrying name, value 1, checked and the classic label' );
ok( false !== strpos( $kit, '<os-field-row label="Status" hint="Turning this off disables speculative loading entirely (core emits no speculation rules)."' ), 'the Status row carries the classic helper as its hint' );
ok( false !== strpos( $kit, 'heading="Speculative loading"' ) && false !== strpos( $kit, 'href="https://developer.chrome.com/docs/web-platform/prerender-pages"' ) && false !== strpos( $kit, '>Speculation Rules</a>' ) && false !== strpos( $kit, '<os-code>auto</os-code>/<os-code>auto</os-code>' ), 'the intro keeps the Speculation Rules link and the auto/auto default as inline code' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, '<b>Speculative loading</b>' ) && false !== strpos( $kit, '<os-badge tone="success">On</os-badge>' ) && false !== strpos( $kit, '<br>Enabled</os-notice>' ), 'the on state paints a success notice: Speculative loading / Enabled / pill On' );
ok( false !== strpos( $kit, 'aria-label="Speculative loading status"' ), 'the rail keeps its landmark name' );
ok( false !== strpos( $kit, 'heading="Profile"' ) && false !== strpos( $kit, 'Mode <os-code>prerender</os-code>, eagerness <os-code>moderate</os-code>' ) && false !== strpos( $kit, '<strong>Excluded automatically:</strong> the custom login URL and <os-code>/contact/*</os-code>.' ) && false !== strpos( $kit, '<strong>Support:</strong> only modern Chromium browsers act on speculation rules; others safely ignore them.' ), 'the Profile reference survives: mode, eagerness, exclusions, support' );
ok( 2 === substr_count( $kit, '<os-card' ) && false !== strpos( $kit, snt_leaf_row() . '<os-card><os-section' ) && false !== strpos( $kit, '<os-card role="complementary" aria-label="Speculative loading status">' ), 'the two-column shell becomes the app column grid: form column, then the rail, each an os-card, the rail keeping its landmark' );

// ── Disabled state: unchecked, warning box, plain Off pill; parity holds.
$GLOBALS['__settings'] = array( 'perf.speculative_loading' => false );
$classic = snt_leaf_classic_html( 'sn_admin_render_performance_section' );
$kit     = snt_leaf_paint( 'site', 'performance' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( 'perf_save' ) === snt_leaf_actions( $kit ), 'off: field names and the action still match the classic form' );
ok( false !== strpos( $kit, '<os-checkbox-label name="speculative_loading" value="1" label="Enabled:' ) && false === strpos( $kit, ' checked ' ), 'off: the checkbox is unchecked' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<os-badge tone="neutral">Off</os-badge>' ) && false !== strpos( $kit, '<br>Disabled</os-notice>' ) && false === strpos( $kit, 'tone="success"' ), 'off: the status box is a warning notice: Disabled / plain pill Off' );
ok( false !== strpos( $classic, 'sn-status-box--warn' ) && false !== strpos( $classic, '<span class="sn-pill">Off</span>' ), 'off: the classic oracle really paints the warn box and the plain pill (the control is not vacuous)' );

// ── Default state: no stored value reads as on, as sn_setting's default does.
$GLOBALS['__settings'] = array();
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, ' checked ' ) && false !== strpos( $kit, 'tone="success"' ), 'unset: the setting defaults to on, as the classic reader does' );

// ── Escaping: a hostile stored value never reaches the markup raw — the leaf
// reduces the option to a boolean before painting, exactly as the classic does.
$GLOBALS['__settings'] = array( 'perf.speculative_loading' => '"><script>x</script>' );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit, 'script&gt;' ) && false !== strpos( $kit, ' checked ' ), 'a hostile stored value is reduced to the boolean it truthy-casts to; nothing of it reaches the markup' );
$GLOBALS['__settings'] = array();

// ── Slow admin requests: the ledger under the row, read from the module's log.
// The empty state first: no option, no table, the sentence.
unset( $GLOBALS['__options']['snt_httpdiag_log'] );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, 'heading="Slow admin requests"' ) && false !== strpos( $kit, '<os-empty-state heading="No slow call was captured."' ) && false === strpos( $kit, '<os-table' ), 'empty: the section says no slow call was captured, and paints no table' );

$now     = time();
$fixture = array(
	array( 't' => $now - 120, 'screen' => 'admin.php?page=sn-theme-options&tab=health', 'wall_s' => 10.1, 'http' => array(
		array( 'url' => 'https://api.example.com/v1/things', 'ms' => 300, 'code' => 200, 'error' => false ),
		array( 'url' => 'https://slow.example.com/b', 'ms' => 9800, 'code' => 500, 'error' => false ),
	) ),
	array( 't' => $now - 7200, 'screen' => 'index.php', 'wall_s' => 3.5, 'http' => array(
		array( 'url' => 'https://dead.example.com/hook', 'ms' => 4000, 'code' => 0, 'error' => true ),
	) ),
	array( 't' => $now - 3 * 86400, 'screen' => 'edit.php', 'wall_s' => 4.2, 'http' => array() ),
	array( 't' => $now - 40 * 86400, 'screen' => 'stale-page', 'wall_s' => 99.0, 'http' => array(
		array( 'url' => 'https://stale.example.com/z', 'ms' => 99999, 'code' => 200, 'error' => false ),
	) ),
	array( 'screen' => '"><script>x</script>', 'wall_s' => 1.0, 'http' => array(
		array( 'url' => 'https://not.example.com/p?token=abc#frag', 'ms' => 50, 'code' => 200, 'error' => false ),
	) ),
);
$GLOBALS['__options']['snt_httpdiag_log'] = $fixture;
$classic = snt_leaf_classic_html( 'sn_admin_render_performance_section' );
$kit     = snt_leaf_paint( 'site', 'performance' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( 'perf_save' ) === snt_leaf_actions( $kit ) && array() === snt_leaf_classic_markers( $kit ) && 2 === substr_count( $kit, '<os-card' ), 'with a log: names, the one action, no classic markers and the two-column row are unchanged' );
ok( strrpos( $kit, '</os-grid>' ) < strpos( $kit, 'heading="Slow admin requests"' ), 'the ledger section sits after the paired row, at full width' );
preg_match( '/<os-table[^>]* os-prop-columns="([^"]*)"[^>]* os-prop-data="([^"]*)"/', $kit, $m );
$cols = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
$rows = json_decode( html_entity_decode( $m[2] ?? '', ENT_QUOTES, 'UTF-8' ), true );
ok( array( 'screen', 'host', 'ms', 'when' ) === array_column( (array) $cols, 'key' ), 'the columns are Screen, Host, Duration, When' );
ok( array( 'slow.example.com', 'dead.example.com', 'api.example.com', 'not.example.com' ) === array_column( (array) $rows, 'host' ) && array( 9800, 4000, 300, 50 ) === array_column( (array) $rows, 'ms' ), 'rows are every call across the visible pages, slowest first; the 40-day-old 99999ms call is hidden by retention' );
ok( 'admin.php?page=sn-theme-options&tab=health' === ( $rows[0]['screen'] ?? '' ) && '2m ago' === ( $rows[0]['when'] ?? '' ) && '' === ( $rows[3]['when'] ?? 'x' ), 'a row carries the screen that fired the call and its age; an entry with no t has an empty When, not a fatal' );
ok( false === strpos( $kit, 'example.com/' ) && false === strpos( $kit, 'token=' ) && false === strpos( $kit, '#frag' ), 'the host alone is painted: no path, query string or fragment reaches the window' );
ok( false !== strpos( $kit, '<os-notice tone="warning" not-dismissible>2 failed calls; the slowest was slow.example.com.</os-notice>' ), 'failed calls (a 500 and a WP_Error) are a warning on top of the box naming the slowest failed host' );
ok( false !== strpos( $kit, '<os-notice tone="info" not-dismissible>1 page load over 2.0s captured no outbound call; the slow part was not an HTTP request this module can see.</os-notice>' ), 'a page load over the slow line with nothing captured is an info notice' );
ok( false !== strpos( $kit, '1 older entry hidden (older than 30 days).' ), 'the retention caption counts the hidden entry, singular' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&quot;&gt;&lt;script' ), 'a hostile stored screen reaches the markup escaped, never raw' );

// Parity with the Site Health panel, the classic reader of the same log:
// every host it paints in a slow_* field is in the ledger.
$panel = sn_httpdiag_debug_information( array(), $fixture, $now );
$panel_hosts = array();
foreach ( $panel['snt_httpdiag']['fields'] as $key => $field ) {
	if ( 0 !== strpos( $key, 'slow_' ) || '(no HTTP calls captured)' === $field['value'] ) { continue; }
	foreach ( explode( ' | ', $field['value'] ) as $call ) { $panel_hosts[] = (string) wp_parse_url( substr( $call, 0, (int) strpos( $call, '. ' ) ), PHP_URL_HOST ); }
}
ok( 4 === count( $panel_hosts ) && array() === array_diff( $panel_hosts, array_column( (array) $rows, 'host' ) ), 'parity: every host the Site Health panel paints is in the ledger (' . implode( ',', $panel_hosts ) . ')' );
unset( $GLOBALS['__options']['snt_httpdiag_log'] );

// A stored url of '' (the sanitizer's answer to a URL with no parseable host)
// is an absent host, named as such in the row and in the warning.
$GLOBALS['__options']['snt_httpdiag_log'] = array( array( 't' => $now - 10, 'screen' => 'index.php', 'wall_s' => 1.0, 'http' => array( array( 'url' => '', 'ms' => 700, 'code' => 0, 'error' => true ) ) ) );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, '1 failed call; the slowest was (no host).</os-notice>' ) && false !== strpos( $kit, '&quot;host&quot;:&quot;(no host)&quot;' ), 'a call with no host is painted as (no host) in the row and the warning, never a blank' );
unset( $GLOBALS['__options']['snt_httpdiag_log'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
