<?php
/**
 * Native window leaf: Site -> Broken links
 * (apps/sn-dashboard/parts/leaves/site-broken-links.php).
 *
 * The oracle is the classic leaf (inc/admin-render-sections.php through
 * sn_admin_render_broken_links_section()): across the default paint and the
 * editing paint (`sn_edit` names a broken path) the kit must carry the same
 * field names and sn_action values, and every reading (the broken-path count,
 * each path's hits, last date, referrer and slug suggestion, the probe count,
 * the busiest probes, the whole-log clear) must be painted, a hostile path must
 * be escaped, and none of wp-admin's markup may survive, in the rich, the
 * editing, the hostile, the empty, the probes-only and the capped state.
 *
 * 2026-10-11 (owner rule: no collapsible sections): tiles, one finding, one kit
 * table of broken paths with visible row actions, ONE create-redirect region
 * below it for the path `sn_edit` names, then the probe ledger, open.
 *
 * It also pins the SPLIT from this side: this leaf paints no redirect
 * edit/delete action and no rail. The redirects half is pinned by
 * tests/os-leaf-site-redirects.php.
 *
 * Run: php tests/os-leaf-site-broken-links.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers: the published slug set the 404 suggester ranks.
$GLOBALS['__published'] = array( 1 => 'https://example.test/notes/design-tokens', 2 => 'https://example.test/contact' );
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $args = array() ) { return array_keys( $GLOBALS['__published'] ); } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return $GLOBALS['__published'][ (int) $id ] ?? ''; } }

require SNT_PATH . 'inc/redirects-store.php';
require SNT_PATH . 'inc/redirects-404-log.php';
require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/redirects-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-broken-links.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fixture( array $redirects, array $log ) { $GLOBALS['__options']['sn_redirects'] = $redirects; $GLOBALS['__options']['sn_404_log'] = $log; }
function entry( $count, $ts, $referer = '' ) { return array( 'count' => $count, 'first_seen' => $ts, 'last_seen' => $ts, 'referer' => $referer ); }
function before( $html, $a, $b ) { return false !== strpos( $html, $a ) && false !== strpos( $html, $b ) && strpos( $html, $a ) < strpos( $html, $b ); }
function form_block( $html, $submit ) { return preg_match( '/<os-form[^>]*submit-label="' . preg_quote( $submit, '/' ) . '"[^>]*>.*?<\/os-form>/s', $html, $m ) ? $m[0] : ''; }
function editing( $path ) { return snt_leaf_paint( 'site', 'broken-links', array( 'params' => array( 'sn_edit' => $path ) ) ); }
function union( array $a, array $b ) { $u = array_values( array_unique( array_merge( $a, $b ) ) ); sort( $u ); return $u; }
/** The table whose first column is labelled $first ('Path') and that has $n columns. */
function table_of( $html, $n ) { foreach ( snt_leaf_tables( $html ) as $t ) { if ( $n === count( $t['columns'] ) ) { return $t; } } return array( 'columns' => array(), 'data' => array(), 'slots' => array() ); }
function styled( $html ) { return false !== strpos( (string) preg_replace( '/<os-grid [^>]*>/', '', $html ), ' style="' ); }
$t0 = 1725000000; $t1 = $t0 + DAY_IN_SECONDS; $d0 = gmdate( 'Y-m-d', $t0 ); $d1 = gmdate( 'Y-m-d', $t1 );
// The broken-links leaf owns the three 404 actions, plus redirect_add (a
// suggestion accepted IS a redirect created).
$all_actions = array( 'redirect_404_clear', 'redirect_404_clear_probes', 'redirect_404_delete', 'redirect_add' );

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/broken-links'] ), 'the painter is registered under site/broken-links' );

// ── Rich state: two broken paths (both with a slug suggestion, since v10.47.0
// a path is broken BECAUSE the suggester matched it), thirty probes.
$log = array(
	'/notes/desing-tokens' => entry( 7, $t0, 'https://ref.example/page' ),
	'/contact-us'          => entry( 2, $t1 ),
);
for ( $i = 1; $i <= 30; $i++ ) { $log[ '/probe-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) ] = entry( $i, $t0 ); }
fixture( array( '/first' => array( 'to' => '/notes/design-tokens', 'status' => 301, 'created_at' => $t0 ) ), $log );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed  = editing( '/notes/desing-tokens' );
ok( '' !== $kit && '' !== $kit_ed, 'the kit leaf paints, default and editing' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ), 'no fold in the default or the editing paint: ' . implode( ',', array_merge( snt_leaf_folds( $kit ), snt_leaf_folds( $kit_ed ) ) ) );
ok( snt_leaf_names( $classic ) === union( snt_leaf_names( $kit ), snt_leaf_names( $kit_ed ) ), 'field names match the classic forms across both paints: ' . implode( ',', union( snt_leaf_names( $kit ), snt_leaf_names( $kit_ed ) ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( $all_actions === union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) && snt_leaf_actions( $classic ) === $all_actions, 'the four actions match the classic leaf across both paints: ' . implode( ',', union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) ) );
ok( array( 'redirect_404_clear', 'redirect_404_clear_probes', 'redirect_404_delete' ) === snt_leaf_actions( $kit ) && in_array( 'redirect_add', snt_leaf_actions( $kit_ed ), true ), '...the default paint offers no create form; the editing paint does: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && ! styled( $kit ) && ! styled( $kit_ed ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// The reading: two tiles (broken, with attention; probes, without), then the finding.
ok( false !== strpos( $kit, '<os-stat value="2" label="Broken paths" caption="9 hits" swatch data-tone="warning">' ), 'the broken tile reads 2 paths, 9 hits, with the attention swatch' );
ok( false !== strpos( $kit, '<os-stat value="30" label="Automated probes" caption="465 hits">' ), 'the probe tile reads 30 probes, 465 hits, without one' );
ok( false !== strpos( $kit, '<p class="snt-prose">2 broken paths took 9 hits; the busiest, /notes/desing-tokens, took 7.</p>' ), 'the finding names the busiest path' );
ok( before( $kit, '<os-stat', 'heading="Broken paths"' ), 'reading before ledger' );

// The ledger: one table, busiest first, every figure the per-path fold held.
$broken = table_of( $kit, 6 );
ok( array( 'Path', 'Hits', 'Last seen', 'Referrer', 'Suggested target', 'Actions' ) === array_column( $broken['columns'], 'label' ), 'the broken table has labelled columns: ' . implode( ',', array_column( $broken['columns'], 'label' ) ) );
$r0 = $broken['data'][0] ?? array(); $r1 = $broken['data'][1] ?? array();
ok( '/notes/desing-tokens' === ( $r0['path'] ?? '' ) && '/contact-us' === ( $r1['path'] ?? '' ), 'broken paths paint busiest first' );
ok( 7 === ( $r0['hits'] ?? null ) && $d0 === ( $r0['last'] ?? '' ) && 'ref.example' === ( $r0['referrer'] ?? '' ) && '/notes/design-tokens' === ( $r0['suggested'] ?? '' ), 'a row carries hits, last date, referring host and the slug suggestion: ' . wp_json_encode( $r0 ) );
ok( 2 === ( $r1['hits'] ?? null ) && $d1 === ( $r1['last'] ?? '' ) && 'none recorded' === ( $r1['referrer'] ?? '' ) && '/contact' === ( $r1['suggested'] ?? '' ), '...and a missing referrer reads "none recorded", never blank' );
$cell = snt_leaf_cell_html( $broken, $r0['actions'] ?? null );
ok( false !== strpos( $cell, '<os-button variant="ghost" os-action="go" os-arg-sub="broken-links" os-arg-sn_edit="/notes/desing-tokens">Redirect</os-button>' ), 'the row action Redirect is a visible go button naming its path' );
$dismiss = form_block( $cell, 'Dismiss' );
ok( '' !== $dismiss && false !== strpos( $dismiss, '<input type="hidden" name="source" value="/notes/desing-tokens"><input type="hidden" name="action" value="sn_redirect_404_delete">' ) && false === strpos( $dismiss, 'os-confirm' ), 'the row Dismiss is a tiny unconfirmed form (redirect_404_delete) carrying its path' );
ok( false === strpos( $kit, 'submit-label="Create redirect"' ) && false === strpos( $kit, 'quieter paths' ), 'the default paint stacks no create form, and no "+N more" under the cap' );

// The editing paint: ONE create region below the table, prefilled with the suggestion.
ok( 1 === substr_count( $kit_ed, 'submit-label="Create redirect"' ) && false !== strpos( $kit_ed, 'heading="New redirect for /notes/desing-tokens"' ) && before( $kit_ed, '<os-table', 'heading="New redirect for' ) && before( $kit_ed, 'heading="New redirect for', 'heading="Automated probes"' ), 'editing paints ONE create region, right below the broken table' );
ok( false !== strpos( $kit_ed, 'description="7 hits, last ' . $d0 . ', from ref.example."' ), '...its description carries the hits, the last date and the referring host' );
$create = form_block( $kit_ed, 'Create redirect' );
ok( false !== strpos( $create, '<os-field-row label="Redirect to" hint="Suggested from your published slugs (closest match); review before creating."><os-text-field name="target" type="text" value="/notes/design-tokens"' ), 'the create form is prefilled with the slug suggestion and says so' );
ok( false !== strpos( $create, '<input type="hidden" name="source" value="/notes/desing-tokens">' ) && false !== strpos( $create, 'name="action" value="sn_redirect_add"' ), '...and posts redirect_add with the path hidden' );
ok( false !== strpos( $kit_ed, '<os-button variant="ghost" os-action="go" os-arg-sub="broken-links">Cancel</os-button>' ), 'Cancel is a go that clears the param' );
ok( false !== strpos( editing( '/contact-us' ), 'description="2 hits, last ' . $d1 . ', no referrer recorded."' ), 'a path with no referrer says so in its create region' );
$unknown = editing( '/nope' );
ok( false === strpos( $unknown, 'Create redirect' ) && false === strpos( $unknown, 'New redirect for' ) && array() === snt_leaf_folds( $unknown ), 'an unknown sn_edit paints no create region' );

// The probes: one finding, a capped ledger, the bulk dismiss. Open.
ok( false !== strpos( $kit, '<p class="snt-prose">30 automated probes made 465 hits on paths that resemble nothing published here: scanner traffic, not broken links, so no action is needed.</p>' ) && false !== strpos( $kit, 'heading="Automated probes"' ), 'the probe section counts the probes and their hits in one sentence' );
$probes = table_of( $kit, 2 );
ok( array( 'Path', 'Hits' ) === array_column( $probes['columns'], 'label' ) && 25 === count( $probes['data'] ), 'the probe ledger lists 25 of 30 -- listed ' . count( $probes['data'] ) );
$listed = array_column( $probes['data'], 'path' );
ok( '/probe-0030' === ( $listed[0] ?? '' ) && 30 === ( $probes['data'][0]['hits'] ?? null ) && in_array( '/probe-0006', $listed, true ) && ! in_array( '/probe-0005', $listed, true ), '...the 25 busiest, with their hits' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-more">+5 more probed paths, the quietest; the list is capped, not complete.</p>' ), '...and counts the rest out loud' );
preg_match( '/<os-button[^>]*os-arg-action="sn_redirect_404_clear_probes"[^>]*>/', $kit, $m );
ok( isset( $m[0] ) && false !== strpos( $m[0], 'os-confirm="Dismiss every automated probe from the log? Genuinely broken paths are kept."' ) && false !== strpos( $m[0], 'os-confirm-label="Dismiss probes"' ) && false === strpos( $m[0], 'os-confirm-danger' ), 'Dismiss all probes confirms with its label and is not marked danger' );
preg_match( '/<os-button[^>]*os-arg-action="sn_redirect_404_clear"[^>]*>/', $kit, $m );
ok( isset( $m[0] ) && false !== strpos( $m[0], 'os-confirm="Clear the entire 404 log?"' ) && false !== strpos( $m[0], 'os-confirm-label="Clear"' ) && before( $kit, 'heading="Automated probes"', 'os-arg-action="sn_redirect_404_clear"' ), 'Clear 404 log confirms with its label, last' );

// ── Escaping: hostile probe paths, and a hostile BROKEN path (one the suggester matches).
fixture( array(), array( '/y"><script>alert1</script>' => entry( 3, $t0 ), '/p"><script>alert2</script>' => entry( 1, $t0 ), '/notes/design-tokens"><b>' => entry( 2, $t0 ) ) );
$kit    = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed = editing( '/notes/design-tokens"><b>' );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit . $kit_ed, '"><b>' ), 'hostile 404 paths never reach the markup raw' );
ok( substr_count( $kit, '&lt;script&gt;' ) >= 2, '...they are escaped, not dropped' );
// The fixture must REACH the markup for the escaping pins to mean anything:
// v13.109.9 rejects any path containing a parenthesis, and `alert(1)` has two.
ok( in_array( '/y"><script>alert1</script>', array_column( table_of( $kit, 2 )['data'], 'path' ), true ) && '/notes/design-tokens"><b>' === ( table_of( $kit, 6 )['data'][0]['path'] ?? '' ), '...and they REACHED the probe and broken tables, so the pins are not vacuous' );
ok( false !== strpos( $kit_ed, 'heading="New redirect for /notes/design-tokens&quot;&gt;&lt;b&gt;"' ), '...the create heading escapes it' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ) && array() === snt_leaf_classic_markers( $kit ) && ! styled( $kit ), 'no fold and no wp-admin markup (hostile)' );

// ── Empty state: nothing broken, nothing probed: two zero tiles, measured.
fixture( array(), array() );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
ok( false !== strpos( $kit, '<os-stat value="0" label="Broken paths" caption="clean">' ) && false !== strpos( $kit, '<p class="snt-prose">No broken links: a path lands here when it resembles a published URL.</p>' ), 'empty: the clean reading and its sentence paint' );
ok( false === strpos( $kit, 'heading="Automated probes"' ) && false === strpos( $kit, '<os-table' ), '...with no probe section and no empty table' );
ok( array() === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_folds( $kit ), 'empty: no action is offered, the classic leaf agrees, no fold' );

// ── Probes only: clean broken reading beside the probe ledger; dismiss offered, clear-log not.
fixture( array(), array( '/probe-a' => entry( 4, $t0 ), '/probe-b' => entry( 1, $t0 ), '/probe-c' => entry( 9, $t0 ) ) );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
ok( false !== strpos( $kit, '<os-stat value="0" label="Broken paths" caption="clean">' ) && false !== strpos( $kit, '<os-stat value="3" label="Automated probes" caption="14 hits">' ), 'probes only: clean broken tile beside the probe tile' );
ok( 3 === count( table_of( $kit, 2 )['data'] ) && false === strpos( $kit, 'snt-more' ), '...the probe ledger lists all 3, no "+N more" under the cap' );
ok( false !== strpos( $kit, 'redirect_404_clear_probes' ) && false === strpos( $kit, 'os-arg-action="sn_redirect_404_clear"' ), 'dismiss-probes is offered, clear-log is not' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_folds( $kit ), '...the classic leaf agrees on the action set, no fold' );
ok( false === strpos( $kit, 'os-arg-action="sn_redirect_update"' ) && false === strpos( $kit, 'os-arg-action="sn_redirect_delete"' ) && false === strpos( $kit, '<aside' ), 'the split, from this side: no redirect edit/delete action, no rail' );

// ── The broken list is CAPPED busiest first, and says so out loud.
$GLOBALS['__published'] = array();
$many = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$slug = '/notes/alpha-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT );
	$GLOBALS['__published'][ $i ] = 'https://example.test' . $slug;
	$many[ $slug . 'x' ] = entry( 100 - $i, $t0 ); // One extra character: similar enough to suggest, different enough to 404.
}
fixture( array(), $many );
$kit     = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed  = editing( '/notes/alpha-0001x' );
$classic = snt_leaf_classic_html( 'sn_admin_render_broken_links_section' );
$broken  = table_of( $kit, 6 );
$paths   = array_column( $broken['data'], 'path' );
ok( 30 === count( $many ) && false !== strpos( $kit, '<os-stat value="0" label="Automated probes"' ), 'sanity: 30 paths, and the suggester classified every one as BROKEN, not as a probe' );
ok( SN_404_LIST_CAP === count( $paths ) && 1 === count( snt_leaf_tables( $kit ) ), 'exactly SN_404_LIST_CAP (' . SN_404_LIST_CAP . ') paths are listed, not all 30 -- listed ' . count( $paths ) );
ok( '/notes/alpha-0001x' === ( $paths[0] ?? '' ) && ! in_array( '/notes/alpha-0030x', $paths, true ), '...the BUSIEST are the ones kept, and the quietest are the ones dropped' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-more">+5 quieter paths are not listed. Showing the 25 busiest of 30 broken paths. Clear the log or act on these to see the rest.</p>' ), '...and the "+N more" line says how many were not shown' );
ok( false !== strpos( $kit, '<os-stat value="30" label="Broken paths"' ), 'the tile still states the TRUE total, not the listed count' );
ok( false !== strpos( $classic, 'Showing the 25 busiest of 30 broken paths' ), '...and the classic leaf caps identically -- one constant, read by both' );
ok( snt_leaf_actions( $classic ) === union( snt_leaf_actions( $kit ), snt_leaf_actions( $kit_ed ) ) && array() === snt_leaf_folds( $kit ), '...with the same action set on both sides under the cap, no fold' );

// ── Live-shaped preview: 31 broken paths (typos of published slugs, mixed
// referrers) and 40 scanner probes that pass the capture filter.
$slugs = array(
	'design-tokens', 'provenance-over-detection', 'provenance-as-substrate', 'two-kinds-of-provenance', 'weighted-identity-frameworks',
	'attribution-is-a-data-problem', 'music-information-retrieval-ethics', 'contributor-identity', 'the-credits-problem', 'liner-notes-are-metadata',
	'session-musicians-and-credit', 'detection-is-an-arms-race', 'watermarks-are-not-provenance', 'c2pa-for-audio', 'a-ledger-of-hands',
	'why-isrc-is-not-enough', 'the-producer-question', 'sampling-and-lineage', 'stems-as-evidence', 'consent-at-the-session', 'who-played-on-this',
	'metadata-debt', 'royalty-routing', 'the-orphaned-recording', 'archives-and-attribution', 'notes-on-mir-evaluation', 'beat-tracking-in-practice',
	'onset-detection-notes', 'chord-recognition-limits', 'source-separation-ethics', 'datasets-and-permission',
);
$refs = array( 'https://www.google.com/', '', 'https://news.ycombinator.com/item?id=1', 'https://example.test/notes/design-tokens', '', 'https://bsky.app/profile/example' );
$GLOBALS['__published'] = array();
$live = array();
foreach ( $slugs as $i => $slug ) {
	$GLOBALS['__published'][ $i + 1 ] = 'https://example.test/notes/' . $slug;
	$typo = substr( $slug, 0, 3 ) . $slug[4] . $slug[3] . substr( $slug, 5 ); // Two letters transposed: a mistyped link.
	$live[ '/notes/' . $typo ] = entry( 140 - 4 * $i, $t0 + $i * HOUR_IN_SECONDS, $refs[ $i % count( $refs ) ] );
}
foreach ( array(
	'/remote/login', '/owa/auth/logon', '/sslvpn_logon', '/global-protect/login', '/cf_scripts/scripts/ajax', '/autodiscover/autodiscover',
	'/ecp/default', '/mifs/user', '/vpn/index', '/hudson', '/manager/html', '/console/login', '/geoserver/web', '/portal/redlion', '/sdk',
	'/nmaplowercheck1700000000', '/evox/about', '/ab2g', '/ab2h', '/systembc/password', '/cgi/login', '/developmentserver/metadatauploader',
	'/webui', '/jasperserver/login', '/static/admin', '/assets/admin', '/simple', '/remote/fgt_lang', '/aaa9', '/aab8', '/t4', '/teorema505',
	'/version', '/info', '/owncloud/status', '/nextcloud/login', '/zabbix/index', '/grafana/login', '/kibana/app', '/portainer/api',
) as $i => $probe ) {
	$live[ $probe ] = entry( 1 + ( $i * 7 ) % 23, $t0 );
}
fixture( array(), $live );
$data   = \SignalNoise\OpenStationHost\Dashboard\Leaves\redirects_data();
$kit    = snt_leaf_paint( 'site', 'broken-links' );
$kit_ed = editing( (string) array_key_first( $data['broken'] ) );
ok( 31 === count( $data['broken'] ) && 40 === count( $data['probes'] ), 'live-shaped sanity: 31 broken and 40 probes survive the capture filter and the partition -- ' . count( $data['broken'] ) . ' / ' . count( $data['probes'] ) );
ok( false !== strpos( $kit, '<os-stat value="31" label="Broken paths"' ) && false !== strpos( $kit, '<os-stat value="40" label="Automated probes"' ), 'live-shaped: the tiles read 31 broken and 40 probes' );
ok( 25 === count( table_of( $kit, 6 )['data'] ) && false !== strpos( $kit, '+6 quieter paths are not listed.' ) && 25 === count( table_of( $kit, 2 )['data'] ) && false !== strpos( $kit, '+15 more probed paths' ), 'live-shaped: both ledgers capped at 25, each with its "+N more"' );
ok( array() === snt_leaf_folds( $kit ) && array() === snt_leaf_folds( $kit_ed ) && 1 === substr_count( $kit_ed, 'submit-label="Create redirect"' ), 'live-shaped: fold-free, and editing paints ONE create form' );
snt_leaf_preview( 'site-broken-links', $kit );
snt_leaf_preview( 'site-broken-links-editing', $kit_ed );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
