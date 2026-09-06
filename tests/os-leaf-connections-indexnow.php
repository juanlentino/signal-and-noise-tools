<?php
/**
 * Native window leaf: Connections → IndexNow (apps/sn-dashboard/parts/leaves/connections-indexnow.php).
 *
 * The oracle is the classic leaf: the kit leaf must carry the same field
 * names and the same three sn_action values, paint every readout the classic
 * rail paints in each of its three states (disabled / failed / active, in the
 * classic order of precedence), escape what came from an option, and none of
 * wp-admin's markup.
 *
 * Run: php tests/os-leaf-connections-indexnow.php
 */
// Redeclared UNCONDITIONALLY so it is bound at compile time (before the
// harness's guarded one runs) and can be driven from a global — the harness's
// current_user_can() always answers true, and this leaf has a capability gate.
function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; }

require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers: sn_indexnow_is_enabled() reads sn_setting(); the
// key and the last result are options (the harness's get_option).
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) { return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default; }

require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/indexnow.php';
require SNT_PATH . 'inc/admin-forms/indexnow.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-indexnow.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** One fixture: the toggle, the stored key, the last-result option. */
function snt_indexnow_fixture( $enabled, $key, array $result ) {
	$GLOBALS['__settings']['indexnow.enabled'] = $enabled;
	$GLOBALS['__options'][ SN_INDEXNOW_KEY_OPT ]    = $key;
	$GLOBALS['__options'][ SN_INDEXNOW_RESULT_OPT ] = $result;
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/indexnow'] ), 'the painter is registered under connections/indexnow' );

// ── Active state, rich fixture: enabled, a key, a clean last submission.
$key = 'deadbeef00112233445566778899aabb';
snt_indexnow_fixture( true, $key, array( 'time' => time() - 3600, 'code' => 200, 'count' => 12 ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_indexnow_section' );
$kit     = snt_leaf_paint( 'connections', 'indexnow' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'indexnow_ping_now', 'indexnow_regenerate', 'indexnow_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the three actions are indexnow_ping_now, indexnow_regenerate, indexnow_save, as on the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the enable form is an os-form dispatching post through the shared table (no pipeline declared, as the classic posts to the current URL)' );
ok( false !== strpos( $kit, '<os-checkbox-label name="indexnow_enabled" value="1" checked label="Notify search engines when content changes">' ), 'the toggle is a kit checkbox carrying the enabled state' );
ok( false !== strpos( $kit, 'name="tab" value="connections"' ) && false !== strpos( $kit, 'name="sub" value="indexnow"' ), 'the classic hidden tab/sub fields survive on the enable form' );
ok( false !== strpos( $kit, 'submit-label="Save IndexNow settings"' ), 'the save button keeps its classic label' );
ok( false !== strpos( $kit, 'os-arg-action="indexnow_ping_now"' ) && false !== strpos( $kit, '>Submit recent content now</os-button>' ), 'Submit recent content now is a one-click write of indexnow_ping_now' );
ok( false !== strpos( $kit, 'os-arg-action="indexnow_regenerate"' ) && false !== strpos( $kit, '>Regenerate key</os-button>' ), 'Regenerate key is a one-click write of indexnow_regenerate' );
ok( 2 === substr_count( $kit, 'os-arg-nonce="nonce-sn_theme_options_nonce"' ), 'both one-click writes carry the classic page nonce' );
ok( false === strpos( $kit, 'os-confirm' ), 'no confirm is asked: the classic maintenance buttons ask none' );
ok( false !== strpos( $kit, 'backfills your existing published posts' ) && false !== strpos( $kit, 'rotates the key' ), 'the maintenance helper text survives as the section description' );
ok( false !== strpos( $kit, 'Pushes changed URLs to <strong>IndexNow</strong>' ) && false !== strpos( $kit, 'not Google' ), 'the intro prose survives, IndexNow in bold' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, '<b>Active</b>' ) && false !== strpos( $kit, '<os-badge tone="success">On</os-badge>' ) && false !== strpos( $kit, 'Changed URLs are submitted automatically.' ), 'the active state paints a success notice with the On pill and the classic body' );
ok( false !== strpos( $kit, 'href="https://example.test/' . $key . '.txt"' ) && false !== strpos( $kit, '<os-code>https://example.test/' . $key . '.txt</os-code>' ) && false !== strpos( $kit, 'Key file' ), 'the key-file URL is shown as an external link in inline code under Key file' );
ok( false !== strpos( $kit, 'Last submission' ) && false !== strpos( $kit, '1 hour ago — HTTP 200, 12 URL(s)' ), 'the last submission reads ago, HTTP code and URL count, as the classic row' );
ok( false !== strpos( $kit, 'col="4" aria-label="IndexNow status"' ) && false !== strpos( $kit, '<os-section heading="Status">' ), 'the rail keeps its landmark name and the Status heading' );
ok( false !== strpos( $kit, '<os-row gap="16"' ), 'the classic two-column shell survives as an os-row' );
ok( false !== strpos( $kit, '<os-stack col="8" gap="12">' ), 'the main column carries col=8 inside the os-row' );

// ── Per-form field census: the enable form alone carries all five classic
// field names (the whole-blob names oracle can't see which form a hidden
// field lives on, so this pins it directly).
preg_match( '/<os-form\b.*?<\/os-form>/s', $kit, $m );
ok( isset( $m[0] ) && array( '_wpnonce', 'indexnow_enabled', 'sn_action', 'sub', 'tab' ) === snt_leaf_names( $m[0] ), 'the enable form alone carries all five classic field names: ' . implode( ',', snt_leaf_names( $m[0] ?? '' ) ) );

// ── The availability guard (an addition over the classic, defensive against
// a leaf loaded before its readers exist) is present in the source.
ok( false !== strpos( file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-indexnow.php' ), 'IndexNow is not available.' ), 'the availability guard is present in the source' );

// ── Enabled, nothing submitted yet: active, no last-submission row.
snt_indexnow_fixture( true, $key, array() );
$kit = snt_leaf_paint( 'connections', 'indexnow' );
ok( false !== strpos( $kit, '<b>Active</b>' ) && false === strpos( $kit, 'Last submission' ), 'enabled with no result yet: active, and no Last submission row' );

// ── Failed state: enabled, the last submission recorded an error.
snt_indexnow_fixture( true, $key, array( 'time' => time() - 3600, 'code' => 429, 'count' => 3, 'error' => 'HTTP 429 slow down' ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_indexnow_section' );
$kit     = snt_leaf_paint( 'connections', 'indexnow' );
ok( false !== strpos( $kit, 'tone="danger"' ) && false !== strpos( $kit, '<b>Last submission failed</b>' ) && false !== strpos( $kit, '<os-badge tone="danger">Error</os-badge>' ), 'the failed state paints a danger notice with the Error pill' );
ok( false !== strpos( $kit, '<os-code>HTTP 429 slow down</os-code>' ), 'the recorded error is shown as inline code' );
ok( false !== strpos( $kit, '1 hour ago — HTTP 429, 3 URL(s)' ), 'the failed submission still paints its Last submission row, as the classic table does' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'failed state: field names still match the classic forms' );

// ── Escaping: a hostile error and a hostile key never reach the markup raw.
snt_indexnow_fixture( true, '"><script>x</script>', array( 'time' => time() - 60, 'code' => 500, 'count' => 1, 'error' => '<script>alert(1)</script>' ) );
$kit = snt_leaf_paint( 'connections', 'indexnow' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'a hostile error string is escaped' );
ok( false === strpos( $kit, '"><script>' ) && false !== strpos( $kit, '&quot;&gt;&lt;script&gt;x&lt;/script&gt;.txt' ), 'a hostile key is escaped in the key-file link' );
ok( array() === snt_leaf_classic_markers( $kit ), 'hostile fixture: no <script> survives the markers check' );

// ── Disabled state: no key yet, toggle off, no save-time readouts.
snt_indexnow_fixture( false, '', array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_indexnow_section' );
$kit     = snt_leaf_paint( 'connections', 'indexnow' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'disabled: names and actions still match the classic leaf' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<b>Disabled</b>' ) && false !== strpos( $kit, '<os-badge tone="warning">Off</os-badge>' ) && false !== strpos( $kit, 'Enable it in the main column' ), 'the disabled state paints a warning notice with the Off pill' );
ok( false !== strpos( $kit, '<os-checkbox-label name="indexnow_enabled" value="1" label="Notify search engines when content changes">' ), 'disabled: the toggle is unchecked' );
ok( false !== strpos( $kit, '<em>not generated yet</em>' ) && false === strpos( $kit, '.txt' ), 'disabled with no key: Key file reads not generated yet, no link' );
ok( false === strpos( $kit, 'Last submission' ), 'disabled with no result: no Last submission row' );

// ── Precedence: disabled wins over a recorded error, as on the classic leaf.
snt_indexnow_fixture( false, $key, array( 'time' => time() - 3600, 'code' => 500, 'count' => 1, 'error' => 'boom' ) );
$kit = snt_leaf_paint( 'connections', 'indexnow' );
ok( false !== strpos( $kit, '<b>Disabled</b>' ) && false === strpos( $kit, 'Last submission failed' ), 'disabled takes precedence over a recorded error' );
ok( false !== strpos( $kit, 'href="https://example.test/' . $key . '.txt"' ) && false !== strpos( $kit, '1 hour ago — HTTP 500, 1 URL(s)' ), 'disabled with a key and a result: the key link and Last submission row still paint, as the classic table does' );

// ── Capability gate: without manage_options the leaf paints an empty state
// and never a write action, matching the classic leaf's early return.
$GLOBALS['__can'] = false;
$kit = snt_leaf_paint( 'connections', 'indexnow' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'cannot manage options' ) && false === strpos( $kit, 'os-arg-action=' ), 'without manage_options the leaf paints an empty state and no write' );
$GLOBALS['__can'] = true;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
