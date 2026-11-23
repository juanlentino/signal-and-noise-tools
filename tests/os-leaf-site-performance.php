<?php
/**
 * Native window leaf: Site › Performance (apps/sn-dashboard/parts/leaves/site-performance.php).
 *
 * The leaf is readings: which speculative loading profile the site serves,
 * the Profile reference, and the outbound calls from admin pages. Its switch
 * left for Settings › Front end in 2026-11 (tests/os-leaf-settings-front-end-pane.php
 * pins the switch, the pointer this leaf keeps, and that no form is left
 * here). The oracle for the profile is the classic leaf: both twins say the
 * same state, in the same words, for on and for off, and neither state reads
 * as a warning, because off is WordPress's own default and not nothing.
 * Second oracle: the Site Health panel (sn_httpdiag_debug_information) for
 * the outbound-call ledger the leaf paints at full width under the row.
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
require SNT_PATH . 'inc/admin-forms/settings-front-end.php'; // the line saying where the switch went.
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-performance.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/performance'] ), 'the painter is registered under site/performance' );

// ── Switch on: the plugin's profile, said the same way on both twins.
$GLOBALS['__settings'] = array( 'perf.speculative_loading' => true );
$classic = snt_leaf_classic_html( 'sn_admin_render_performance_section' );
$kit     = snt_leaf_paint( 'site', 'performance' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === snt_leaf_names( $classic ) && array() === snt_leaf_names( $kit ) && array() === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $classic ) && false === strpos( $kit, '<os-form' ) && false === strpos( $classic, '<form' ), 'no field, no action and no form on either twin: the switch is on Settings › Front end' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
// 2026.10.4 audit (batch 4): "summarized alongside" pointed at a position.
ok( false !== strpos( $kit, 'The Profile box lists what is in effect and what it leaves out.' ) && false !== strpos( $classic, 'The Profile box lists what is in effect and what it leaves out.' ) && false === strpos( $kit . $classic, 'alongside' ), 'the intro names the Profile box, on both twins' );
ok( false !== strpos( $kit, 'href="https://developer.chrome.com/docs/web-platform/prerender-pages"' ) && false !== strpos( $kit, '>Speculation Rules</a>' ) && false !== strpos( $kit, '<os-code>auto</os-code>/<os-code>auto</os-code>, which WordPress resolves to prefetch). With its switch on,' ) && false !== strpos( $classic, '<code>auto</code>/<code>auto</code>, which WordPress resolves to prefetch). With its switch on,' ), 'the intro keeps the Speculation Rules link and the auto/auto default as inline code, and says what that default is and what the switch adds' );
ok( false !== strpos( $kit, 'With it off, WordPress’s default stays.' ) && false !== strpos( html_entity_decode( $classic, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'With it off, WordPress’s default stays.' ), 'and both twins say what off leaves in place' );
// 2026-10-16, the leaf anatomy (docs/kit-leaf-anatomy.md): the state is the
// status line's first chip, the intro a How this works fold, the Profile the
// one fold of a Setup card beside it.
$line = strpos( $kit, '<div class="snt-status-line"><os-cluster gap="8"><os-badge tone="success">Prerender is on</os-badge>' );
ok( false !== $line && false === strpos( $kit, '<os-notice tone="success"' ) && false === strpos( $kit, '<b>' ) && false !== strpos( $classic, '<p class="sn-status-box-title">Prerender is on.</p></div><span class="sn-pill sn-pill--ok">On</span>' ), 'the on state is one chip on the status line that leads the leaf, no notice box, no bold lead-in; the classic box says the same' );
$how = strpos( $kit, '<os-disclosure heading="How this works" hint="Speculation Rules load the pages a visitor is likely to open next before the click."' );
ok( false !== $how && $line < $how && $how < strpos( $kit, 'Since 6.8, WordPress ships native' ) && false === strpos( $kit, 'aria-label="Speculative loading' ), 'the intro is a How this works fold under the status line; the classic rail is gone' );
ok( false !== strpos( $kit, '<os-disclosure heading="Profile" hint="prerender, moderate eagerness"' ) && 1 === substr_count( $kit, 'heading="Setup"' ) && false === strpos( $kit, 'heading="Speculative loading"' ) && 2 === substr_count( $kit, '<os-disclosure' ), 'one Setup card holds the Profile (summary the profile in effect), a closed fold; the setting\'s fold left with the switch' );
ok( false !== strpos( $kit, '<os-fact label="Mode"><span class="snt-kv__v"><os-code>prerender</os-code>, eagerness <os-code>moderate</os-code>' ) && false !== strpos( $kit, '<os-fact label="Excluded automatically"><span class="snt-kv__v"><os-code>/contact/*</os-code>.</span>' ) && false !== strpos( $kit, '<os-fact label="Browser support"><span class="snt-kv__v">only modern Chromium browsers act on speculation rules; others safely ignore them.</span>' ) && false !== strpos( $classic, 'Mode <code>prerender</code>, eagerness <code>moderate</code>' ), 'on: the Profile names prerender and moderate, the exclusion and browser support, each under its own label' );
ok( 0 === substr_count( $kit, '<os-card' ), 'no classic rail card left' );
// 2026-10-16: the status and its reference belong together. One grid row holds
// [status card | Setup card], in that order, and closes before the ledger.
$grid = strpos( $kit, snt_leaf_row() );
ok( 0 === $grid && 1 === substr_count( $kit, '<os-grid' ) && $grid < strpos( $kit, 'heading="Performance"' ) && strpos( $kit, 'heading="Performance"' ) < strpos( $kit, 'heading="Setup"' ) && strpos( $kit, 'heading="Setup"' ) < strpos( $kit, '</os-grid>' ), 'the leaf opens on one row of two: the Performance status card, then the Setup card' );

// ── Switch off: WordPress's own default is in effect. A plain chip and a plain
// box, never a warning: until 2026-11 off meant no speculation rules at all.
$GLOBALS['__settings'] = array( 'perf.speculative_loading' => false );
$classic = snt_leaf_classic_html( 'sn_admin_render_performance_section' );
$kit     = snt_leaf_paint( 'site', 'performance' );
$decoded = html_entity_decode( $classic, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
ok( false !== strpos( $kit, '<div class="snt-status-line"><os-cluster gap="8"><os-chip>Prerender is off; WordPress’s own default applies</os-chip>' ) && false === strpos( $kit, 'tone="success"' ) && false === strpos( $kit, 'tone="warning"' ), 'off: a neutral chip says prerender is off and WordPress\'s default applies; no amber, no green' );
ok( false !== strpos( $decoded, '<div class="sn-status-box"><div><p class="sn-status-box-title">Prerender is off; WordPress’s own default applies.</p></div><span class="sn-pill">Off</span></div>' ) && false === strpos( $classic, 'sn-status-box--warn' ), 'off: the classic box says the same, with the plain pill and no warn box' );
ok( false !== strpos( $kit, '<os-disclosure heading="Profile" hint="WordPress’s default"' ) && false !== strpos( $kit, '<os-fact label="Mode"><span class="snt-kv__v">WordPress’s default (<os-code>auto</os-code>/<os-code>auto</os-code>): <os-code>prefetch</os-code>, eagerness <os-code>conservative</os-code>, unless the host set another.</span>' ) && false === strpos( $kit, '<os-code>prerender</os-code>' ), 'off, native: the Profile names what is served, prefetch and conservative, and no longer prerender' );
ok( false !== strpos( $decoded, 'WordPress’s default (<code>auto</code>/<code>auto</code>): mode <code>prefetch</code>, eagerness <code>conservative</code>, unless the host set another.' ) && false === strpos( $classic, '<code>prerender</code>' ) && false !== strpos( $classic, '<code>/contact/*</code>' ), 'off, classic: the same, and the /contact/ exclusion still stands (the filter appends it in both states)' );

// ── Default state: no stored value reads as on, as sn_setting's default does.
$GLOBALS['__settings'] = array();
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, '<os-badge tone="success">Prerender is on</os-badge>' ), 'unset: the setting defaults to on, as the classic reader does' );

// ── Escaping: a hostile stored value never reaches the markup raw. The leaf
// reduces the option to a boolean before painting, exactly as the classic does.
$GLOBALS['__settings'] = array( 'perf.speculative_loading' => '"><script>x</script>' );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit, 'script&gt;' ) && false !== strpos( $kit, 'Prerender is on' ), 'a hostile stored value is reduced to the boolean it truthy-casts to; nothing of it reaches the markup' );
$GLOBALS['__settings'] = array();

// ── Slow admin requests: the ledger under the row, read from the module's log.
// The empty state first: no option, no table, the sentence.
unset( $GLOBALS['__options']['snt_httpdiag_log'] );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, 'heading="Outbound calls from admin pages"' ) && false !== strpos( $kit, '<os-empty-state heading="No outbound call was captured."' ) && false === strpos( $kit, '<os-table' ) && false === strpos( $kit, 'Slow admin requests' ), 'empty: the section, named for what it logs (every call, not only slow ones), says none was captured and paints no table' );

$now     = time();
$fixture = array(
	array( 't' => $now - 120, 'screen' => 'admin.php?page=sn-theme-options&tab=monitoring&sub=health', 'wall_s' => 10.1, 'http' => array(
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
snt_leaf_preview( 'site-performance', $kit );
ok( array() === snt_leaf_names( $kit ) && array() === snt_leaf_actions( $kit ) && array() === snt_leaf_classic_markers( $kit ), 'with a log: still no field, no action and no classic markers' );
$ledger = strpos( $kit, 'heading="Outbound calls from admin pages"' );
ok( false !== $ledger && strpos( $kit, 'heading="Setup"' ) < strpos( $kit, '</os-grid>' ) && strpos( $kit, '</os-grid>' ) < $ledger, 'the ledger is the working content at full width: under the [status | Setup] row, outside its grid' );
ok( false !== strpos( $kit, '<os-badge tone="warning">2 failed outbound calls</os-badge>' ) && false !== strpos( $kit, '2 failed calls; the slowest was slow.example.com.' ), 'the status line counts the failed calls in amber, and the ledger keeps its open warning naming the host' );
preg_match( '/<os-table[^>]* os-prop-columns="([^"]*)"[^>]* os-prop-data="([^"]*)"/', $kit, $m );
$cols = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
$rows = json_decode( html_entity_decode( $m[2] ?? '', ENT_QUOTES, 'UTF-8' ), true );
ok( array( 'screen', 'host', 'ms', 'status', 'when' ) === array_column( (array) $cols, 'key' ), 'the columns are Screen, Host, Duration, Status, When' );
// 2026.10.4 audit (batch 4): the failed calls the notice counts are marked in their rows.
ok( 2 === substr_count( $kit, '<os-badge tone="danger">' ) && false !== strpos( $kit, '<os-badge tone="danger">500</os-badge>' ) && false !== strpos( $kit, '<os-badge tone="danger">failed</os-badge>' ), 'the two failed calls carry a Status badge (500, failed); the rest their code' );
ok( array( 'slow.example.com', 'dead.example.com', 'api.example.com', 'not.example.com' ) === array_column( (array) $rows, 'host' ) && array( 9800, 4000, 300, 50 ) === array_column( (array) $rows, 'ms' ), 'rows are every call across the visible pages, slowest first; the 40-day-old 99999ms call is hidden by retention' );
ok( 'Signal & Noise › Measurement › Health' === ( $rows[0]['screen'] ?? '' ) && 'Dashboard' === ( $rows[1]['screen'] ?? '' ) && '2m ago' === ( $rows[0]['when'] ?? '' ) && '' === ( $rows[3]['when'] ?? 'x' ), 'a row names the screen that fired the call as the admin names it (never "admin.php?page=..."), and its age; an entry with no t has an empty When, not a fatal' );
ok( false === strpos( $kit, 'snt-ledger-more' ), 'four calls under the cap of twenty: no "+N more" line' );
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

// 2026-10-16, live: entries written before the pagenow kept its dot were
// stored as "adminphp" and "updatephp". They read back with the dot restored;
// a core screen is named again; a label that already has its dot is untouched.
$GLOBALS['__options']['snt_httpdiag_log'] = array(
	array( 't' => $now - 10, 'screen' => 'adminphp?page=mailpoet-settings', 'wall_s' => 1.0, 'http' => array( array( 'url' => 'https://a.example.com/', 'ms' => 30, 'code' => 200, 'error' => false ) ) ),
	array( 't' => $now - 20, 'screen' => 'updatephp', 'wall_s' => 1.0, 'http' => array( array( 'url' => 'https://b.example.com/', 'ms' => 20, 'code' => 200, 'error' => false ) ) ),
	array( 't' => $now - 30, 'screen' => 'indexphp', 'wall_s' => 1.0, 'http' => array( array( 'url' => 'https://c.example.com/', 'ms' => 10, 'code' => 200, 'error' => false ) ) ),
);
$kit = snt_leaf_paint( 'site', 'performance' );
preg_match( '/<os-table[^>]* os-prop-data="([^"]*)"/', $kit, $m );
$rows = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
ok( array( 'admin.php?page=mailpoet-settings', 'update.php', 'Dashboard' ) === array_column( (array) $rows, 'screen' ) && false === strpos( $kit, 'adminphp' ) && false === strpos( $kit, 'updatephp' ), 'old rows read back with the dot restored: admin.php?page=mailpoet-settings, update.php, and indexphp named Dashboard again' );
unset( $GLOBALS['__options']['snt_httpdiag_log'] );

// A stored url of '' (the sanitizer's answer to a URL with no parseable host)
// is an absent host, named as such in the row and in the warning.
$GLOBALS['__options']['snt_httpdiag_log'] = array( array( 't' => $now - 10, 'screen' => 'index.php', 'wall_s' => 1.0, 'http' => array( array( 'url' => '', 'ms' => 700, 'code' => 0, 'error' => true ) ) ) );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, '1 failed call; the slowest was (no host).</os-notice>' ) && false !== strpos( $kit, '&quot;host&quot;:&quot;(no host)&quot;' ), 'a call with no host is painted as (no host) in the row and the warning, never a blank' );
unset( $GLOBALS['__options']['snt_httpdiag_log'] );

// Over the cap: the twenty slowest, and the rest counted out loud.
$many = array();
for ( $i = 1; $i <= 23; $i++ ) { $many[] = array( 'url' => 'https://h' . $i . '.example.com/x', 'ms' => $i * 10, 'code' => 200, 'error' => false ); }
$GLOBALS['__options']['snt_httpdiag_log'] = array( array( 't' => $now - 10, 'screen' => 'index.php', 'wall_s' => 1.0, 'http' => array_slice( $many, 0, 20 ) ), array( 't' => $now - 20, 'screen' => 'edit.php', 'wall_s' => 1.0, 'http' => array_slice( $many, 20 ) ) );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+3 quicker calls are not listed; the list is capped, not complete.</p>' ), 'twenty-three calls: the twenty slowest, then "+3 quicker calls are not listed", never a silent cut' );
// Review on #2020 (P2-2): a fast failed call the cap would cut stays listed, so the warning's count has its row.
$calls   = array_slice( $many, 0, 19 );
$calls[] = array( 'url' => 'https://fast404.example.com/x', 'ms' => 5, 'code' => 404, 'error' => false );
$calls[] = array( 'url' => 'https://slowok.example.com/x', 'ms' => 3000, 'code' => 200, 'error' => false );
$GLOBALS['__options']['snt_httpdiag_log'] = array( array( 't' => $now - 10, 'screen' => 'index.php', 'wall_s' => 1.0, 'http' => $calls ) );
$kit = snt_leaf_paint( 'site', 'performance' );
ok( false !== strpos( $kit, '1 failed call; the slowest was fast404.example.com.' ) && false !== strpos( $kit, '<os-badge tone="danger">404</os-badge>' ) && false !== strpos( $kit, 'fast404.example.com' ), 'a 5 ms 404 among twenty-one calls: the warning counts it AND its row is listed, badge and all' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+1 quicker call is not listed; the list is capped, not complete.</p>' ), 'one call cut: "+1 quicker call is not listed", singular' );
unset( $GLOBALS['__options']['snt_httpdiag_log'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
