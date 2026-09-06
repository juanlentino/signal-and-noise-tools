<?php
/**
 * Native window leaf: Site → Performance (apps/sn-dashboard/parts/leaves/site-performance.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same field
 * name and the same sn_action in both the on and the off state, every rail
 * readout (status box, pill, profile reference) must survive, and none of
 * wp-admin's markup may.
 *
 * Run: php tests/os-leaf-site-performance.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's one reader: the setting by dot-path, default when unset.
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) { return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default; }

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
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the form is an os-form dispatching post through the shared handler table (the classic form posts to the current admin URL)' );
ok( false !== strpos( $kit, 'submit-label="Save"' ), 'the submit is labelled Save, as the classic button is' );
ok( false !== strpos( $kit, '<os-checkbox-label name="speculative_loading" value="1" checked label="Enabled: prerender the pages a visitor is likely to open next"' ), 'the toggle is a kit checkbox carrying name, value 1, checked and the classic label' );
ok( false !== strpos( $kit, '<os-field-row label="Status" hint="Turning this off disables speculative loading entirely (core emits no speculation rules)."' ), 'the Status row carries the classic helper as its hint' );
ok( false !== strpos( $kit, 'heading="Speculative loading"' ) && false !== strpos( $kit, 'href="https://developer.chrome.com/docs/web-platform/prerender-pages"' ) && false !== strpos( $kit, '>Speculation Rules</a>' ) && false !== strpos( $kit, '<os-code>auto</os-code>/<os-code>auto</os-code>' ), 'the intro keeps the Speculation Rules link and the auto/auto default as inline code' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, '<b>Speculative loading</b>' ) && false !== strpos( $kit, '<os-badge tone="success">On</os-badge>' ) && false !== strpos( $kit, '<br>Enabled</os-notice>' ), 'the on state paints a success notice: Speculative loading / Enabled / pill On' );
ok( false !== strpos( $kit, 'aria-label="Speculative loading status"' ), 'the rail keeps its landmark name' );
ok( false !== strpos( $kit, 'heading="Profile"' ) && false !== strpos( $kit, 'Mode <os-code>prerender</os-code>, eagerness <os-code>moderate</os-code>' ) && false !== strpos( $kit, '<strong>Excluded automatically:</strong> the custom login URL and <os-code>/contact/*</os-code>.' ) && false !== strpos( $kit, '<strong>Support:</strong> only modern Chromium browsers act on speculation rules; others safely ignore them.' ), 'the Profile reference survives: mode, eagerness, exclusions, support' );
ok( 2 === substr_count( $kit, 'class="snt-col"' ) && false !== strpos( $kit, '<div class="snt-cols">' ), 'the two-column shell becomes the app column grid: form column, then the rail' );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
