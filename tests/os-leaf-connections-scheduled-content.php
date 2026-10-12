<?php
/**
 * Native window leaf: Connections → Scheduled
 * (apps/sn-dashboard/parts/leaves/connections-scheduled-content.php).
 *
 * The oracle is the classic leaf (inc/schedule-admin.php,
 * `sn_admin_render_scheduled_content_section()`): the kit must fold the same
 * two producers (fragment/queue rows + native future posts) into the same
 * capped, soonest-first status wall, offer the same three ops
 * (schedule_run_now, schedule_repurge, schedule_swap_run_now) with the same
 * hidden fields, and carry none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-connections-scheduled-content.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── The leaf's own readers: date/time + post lookups the classic renderer
// (and its pure helpers) call, none of which the shared harness stubs.
$GLOBALS['__now'] = 1700000000; // fixed instant; every "future" fixture sits after this.
function current_time( $type, $gmt = 0 ) { return $GLOBALS['__now']; }
function get_date_from_gmt( $string, $format = 'Y-m-d H:i:s' ) {
	$string = (string) $string;
	if ( '' === $string ) { return ''; }
	$ts = strtotime( $string . ' UTC' );
	return false === $ts ? '' : gmdate( $format, $ts );
}
$GLOBALS['__titles'] = array();
function get_the_title( $id = 0 ) { return $GLOBALS['__titles'][ (int) $id ] ?? ''; }
$GLOBALS['__edit_links'] = array();
function get_edit_post_link( $id = 0 ) { return $GLOBALS['__edit_links'][ (int) $id ] ?? ''; }

// ── The two producers + the derived swap pairs, all fixture-fed.
$GLOBALS['__sched_fragments'] = array();
function sn_schedule_all() { return $GLOBALS['__sched_fragments']; }
$GLOBALS['__sched_posts'] = array();
function sn_schedule_future_posts() { return $GLOBALS['__sched_posts']; }
$GLOBALS['__sched_pairs'] = array();
function sn_schedule_swap_pairs( $fragments ) { return $GLOBALS['__sched_pairs']; }

require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/schedule-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-scheduled-content.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$NOW = $GLOBALS['__now'];
function iso( $offset ) { global $NOW; return gmdate( 'Y-m-d H:i:s', $NOW + $offset ); }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/scheduled-content'] ), 'the painter is registered under connections/scheduled-content' );

// ── Rich fixture: one reveal fragment (linked), one unlinked hide fragment,
// one past/no-pending fragment, one future post, one derived swap pair.
$GLOBALS['__titles']     = array( 55 => 'Home page' );
$GLOBALS['__edit_links'] = array( 55 => 'https://example.test/wp-admin/post.php?post=55&action=edit' );
$GLOBALS['__sched_fragments'] = array(
	array( 'id' => 201, 'target_ref' => 55, 'action' => 'reveal', 'starts_at' => iso( 3600 ), 'ends_at' => null, 'status' => 'queued' ),
	array( 'id' => 202, 'target_ref' => 0, 'action' => 'hide', 'starts_at' => null, 'ends_at' => iso( 7200 ), 'status' => 'error' ),
	array( 'id' => 203, 'target_ref' => 55, 'action' => 'hide', 'starts_at' => iso( -7200 ), 'ends_at' => iso( -3600 ), 'status' => 'done' ),
);
$GLOBALS['__sched_posts'] = array(
	array( 'id' => 77, 'title' => 'Launch announcement', 'post_type' => 'page', 'edit_link' => 'https://example.test/wp-admin/post.php?post=77&action=edit', 'scheduled_gmt' => iso( 10800 ) ),
);
$GLOBALS['__sched_pairs'] = array(
	array(
		'target_ref' => 55,
		'swap_at'    => iso( 1800 ),
		'hide'       => array( 'id' => 301, 'status' => 'active' ),
		'show'       => array( 'id' => 302, 'status' => 'queued' ),
	),
);

$classic = snt_leaf_classic_html( 'sn_admin_render_scheduled_content_section' );
$kit     = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok(
	array( 'schedule_repurge', 'schedule_run_now', 'schedule_swap_run_now' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ),
	'the three ops (schedule_run_now, schedule_repurge, schedule_swap_run_now) match the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) )
);
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// 2026-10-12 audit: the third tile was the sum of the other two ("Scheduled
// 25" beside "Future posts 25"); it is gone, on both surfaces.
ok( false === strpos( $kit, 'label="Scheduled"' ) && false === strpos( $classic, 'sn-glance-card__label">Scheduled<' ) && false !== strpos( $classic, 'sn-glance-card__label">Fragments<' ) && 2 === count( snt_schedule_glance_cards( array( 1 ), array( 1, 2 ) ) ), 'no total tile: the two producers are the glance' );
ok( false !== strpos( $kit, 'value="3"' ) && false !== strpos( $kit, 'label="Fragments"' ) && false !== strpos( $kit, 'value="1"' ) && false !== strpos( $kit, 'label="Future posts"' ), 'the fragment/future-post glance split is shown' );

ok( false !== strpos( $kit, 'Home page' ) && false !== strpos( $kit, 'os-arg-url="https://example.test/wp-admin/post.php?post=55&amp;action=edit"' ), 'the linked fragment target is a kit door to its editor, not a raw admin <a>' );
ok( false !== strpos( $kit, '(unlinked fragment)' ), 'the unlinked fragment falls back to its classic label' );
ok( false !== strpos( $kit, 'Launch announcement' ) && false !== strpos( $kit, 'Page' ), 'the native future post shows its title and type (Page)' );
// 15.10.1: native posts take the house table; fragments keep the list their forms need.
ok( 1 === preg_match( '/<os-table[^>]*os-prop-columns=/', $kit ) && false !== strpos( $kit, '&quot;key&quot;:&quot;publishes&quot;' ), 'the native posts paint as an <os-table> with a Publishes column, the Cron leaf\'s shape' );
// 2026-10-11 (owner rule, no folds): the fragments list is an open section,
// not an always-open disclosure; both headings are short noun phrases and
// the counts they carried are each section's finding sentence.
ok( array() === snt_leaf_folds( $kit ), 'no fold on the rich paint: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( false !== strpos( $kit, '<os-section heading="Scheduled posts"' ) && false !== strpos( $kit, '<os-section heading="Scheduled fragments" stack>' ), 'the two producers get their own short headings' );
ok( false !== strpos( $kit, '<p class="snt-prose">1 post or page waits to auto-publish; it publishes in 1 hour.</p>' ), 'the posts finding counts them and says when the soonest publishes' );
ok( false !== strpos( $kit, '<p class="snt-prose">3 scheduled fragments: 1 queued, 1 error, 1 done.</p>' ), 'the fragments finding counts them by status, from the rows' );
ok( false === strpos( $kit, 'snt-ledger-more' ), 'four items are under the cap: no "+N more" line' );
ok( false === strpos( $kit, '>native<' ), 'no row is labelled "native" any more: the table IS the native section' );
ok( false !== strpos( $kit, 'Run now' ) && false !== strpos( $kit, 'snt-list__row' ), 'fragment rows still paint as list rows and still carry Run now' );

ok( false !== strpos( $kit, 'active → queued' ), 'the version-swap pair shows its hide→show status' );
ok( false !== strpos( $kit, 'Run swap now' ) && false !== strpos( $kit, 'name="hide_id" value="301"' ) && false !== strpos( $kit, 'name="show_id" value="302"' ), 'the swap row carries the same hide_id/show_id the classic op posts' );

ok( false !== strpos( $kit, 'in 1 hour' ), 'a pending boundary reads as a relative "in …" time (fixture-fed human_time_diff)' );
ok( false !== strpos( $kit, '>nothing pending</span>' ) && false === strpos( $kit, '&mdash;' ) && false === strpos( $kit, '—' ) && false !== strpos( $classic, '>nothing pending</td>' ), 'a row with no pending boundary (both past) says "nothing pending" on both surfaces, never an em dash' );
// 2026-10-12 audit: the fragment and swap lists were plain flex rows, so the
// header words sat over the wrong cells (#1604); both are ledger grids now.
ok( 1 === preg_match( '#<os-section heading="Scheduled fragments" stack><p class="snt-prose">[^<]*</p><div class="snt-ledger snt-ledger--wrap"><ul class="snt-list snt-list--ledger"><li class="snt-list__row"><span class="snt-list__label">Target</span>#', $kit ), 'the fragments list is a ledger grid whose first row is its header' );
ok( 1 === preg_match( '/\.snt-list--ledger os-form\s*\{\s*container-type:\s*normal;\s*width:\s*max-content;/', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) ), 'a ledger form sizes to its button: an os-form is an inline-size container with no intrinsic width, and its buttons piled up and clipped (seen in the preview)' );
ok( false !== strpos( $kit, '<div class="snt-ledger snt-ledger--wrap"><ul class="snt-list snt-list--ledger snt-list--ledger-5"><li class="snt-list__row"><span class="snt-list__label">Target</span><span class="snt-list__value">Swap at</span>' ), 'the swaps list is a five-track ledger grid' );
$css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
// Live check (2026-10-12): "always" / "never" in a <small> computed to 10px,
// under the 11px floor; and a ledger form's empty fields margin and footer
// rule made the actions cell 83px tall, its buttons 20px under their row.
ok( false === strpos( $kit, '<small>' ) && false !== strpos( $kit, 'always &rarr; ' ), 'the open-ended window words are the row\'s own size, not a 10px <small>' );
ok( 1 === preg_match( '/\.snt-list--ledger os-form::part\( header \),\s*\.snt-list--ledger os-form::part\( fields \)\s*\{[^}]*margin:\s*0;/', $css ) && 1 === preg_match( '/\.snt-list--ledger os-form::part\( footer \)\s*\{[^}]*border-top:\s*0;[^}]*padding-top:\s*0;/', $css ), 'a ledger form is its button: no header or fields margin, no footer rule or padding' );
ok( 1 === preg_match( '/\.snt-list--ledger\.snt-list--ledger-5\s*\{[^}]*grid-template-columns:\s*minmax\( 8em, 2fr \) repeat\( 2, minmax\( 4em, 1fr \) \) max-content max-content/', $css ), 'the five-track variant is defined' );

ok( false !== strpos( $kit, 'name="row_id" value="201"' ) && false !== strpos( $kit, 'value="sn_schedule_run_now"' ) && false !== strpos( $kit, 'value="sn_schedule_repurge"' ), 'the fragment row carries its own row_id into both ops' );
// 2026-10-12 audit: Run now and Run swap now change the public site at once
// and asked nothing; they ask now, in the same words on both surfaces.
// Re-purge only refreshes the cache and stays one click.
foreach ( array( 'Run this scheduled change now', 'Run this swap now' ) as $q ) {
	ok( 1 === preg_match( '/os-confirm="(' . preg_quote( $q, '/' ) . '[^"]*)"/', $kit, $m ) && false !== strpos( $classic, 'data-snt-confirm="' . $m[1] . '"' ), "asks first on both surfaces: $q" );
}
preg_match_all( '/<os-form\b[^>]*>(?:(?!<\/os-form>).)*value="sn_schedule_repurge"/s', $kit, $rp );
ok( array() !== $rp[0] && array() === array_filter( $rp[0], static fn( $f ) => false !== strpos( $f, 'os-confirm' ) ), 'Re-purge still runs in one click' );

// ── Escaping: a hostile action string never reaches the markup raw.
$GLOBALS['__sched_fragments'][0]['action'] = '"><script>x</script>';
$kit = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ) && array() === snt_leaf_folds( $kit ), 'a hostile action value is escaped; no fold' );
$GLOBALS['__sched_fragments'][0]['action'] = 'reveal';

// ── Empty state: no fragments, no posts.
$GLOBALS['__sched_fragments'] = array();
$GLOBALS['__sched_posts']     = array();
$GLOBALS['__sched_pairs']     = array();
$kit = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'No scheduled content.' ), 'the empty state paints when nothing is scheduled' );
ok( false === strpos( $kit, 'snt-schedule-row' ), 'the empty state paints no rows' );
ok( array() === snt_leaf_folds( $kit ) && false === strpos( $kit, 'snt-ledger-more' ), 'the empty state paints no fold and no "+N more"' );

// ── Capped + remainder: more rows than the display cap.
$many = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$many[] = array( 'id' => 1000 + $i, 'target_ref' => 0, 'action' => 'reveal', 'starts_at' => iso( $i * 60 ), 'ends_at' => null, 'status' => 'queued' );
}
$GLOBALS['__sched_fragments'] = $many;
$kit = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( false !== strpos( $kit, '<p class="snt-prose">30 scheduled fragments: 30 queued.</p>' ) && array() === snt_leaf_folds( $kit ), 'the fragments finding carries the TRUE total, not the capped count; no fold' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+5 more scheduled items, sorted soonest-first; the tail is the furthest out.</p>' ), 'the remainder line reports the hidden tail (30 - 25 cap = 5) on the kit more line, no em dash' );
ok( 25 === substr_count( $kit, 'name="row_id"' ) / 2, 'the capped list paints 25 fragment rows, each with its two ops' );
$GLOBALS['__sched_fragments'] = array_slice( $many, 0, 25 );
$kit = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( 25 === substr_count( $kit, 'name="row_id"' ) / 2 && false === strpos( $kit, 'snt-ledger-more' ), 'exactly 25 items: every row painted, no "+N more" line' );

// ── Live-shaped preview: a few fragments (one swap pair), three future posts.
$GLOBALS['__titles']     = array( 55 => 'Now page', 61 => 'Speaking', 70 => 'About' );
$GLOBALS['__edit_links'] = array( 55 => 'https://example.test/wp-admin/post.php?post=55&action=edit', 61 => 'https://example.test/wp-admin/post.php?post=61&action=edit', 70 => 'https://example.test/wp-admin/post.php?post=70&action=edit' );
$GLOBALS['__sched_fragments'] = array(
	array( 'id' => 401, 'target_ref' => 55, 'action' => 'hide', 'starts_at' => null, 'ends_at' => iso( 2 * 86400 ), 'status' => 'active' ),
	array( 'id' => 402, 'target_ref' => 55, 'action' => 'reveal', 'starts_at' => iso( 2 * 86400 ), 'ends_at' => null, 'status' => 'queued' ),
	array( 'id' => 403, 'target_ref' => 61, 'action' => 'reveal', 'starts_at' => iso( 9 * 86400 ), 'ends_at' => iso( 10 * 86400 ), 'status' => 'queued' ),
	array( 'id' => 404, 'target_ref' => 70, 'action' => 'hide', 'starts_at' => null, 'ends_at' => iso( 30 * 86400 ), 'status' => 'active' ),
	array( 'id' => 405, 'target_ref' => 61, 'action' => 'reveal', 'starts_at' => iso( -3 * 86400 ), 'ends_at' => null, 'status' => 'done' ),
);
$GLOBALS['__sched_posts'] = array(
	array( 'id' => 811, 'title' => 'Two kinds of provenance', 'post_type' => 'post', 'scheduled_gmt' => iso( 86400 ) ),
	array( 'id' => 812, 'title' => 'Provenance without institutions', 'post_type' => 'post', 'scheduled_gmt' => iso( 8 * 86400 ) ),
	array( 'id' => 813, 'title' => 'Press kit', 'post_type' => 'page', 'scheduled_gmt' => iso( 15 * 86400 ) ),
);
$GLOBALS['__sched_pairs'] = array( array( 'target_ref' => 55, 'swap_at' => iso( 2 * 86400 ), 'hide' => array( 'id' => 401, 'status' => 'active' ), 'show' => array( 'id' => 402, 'status' => 'queued' ) ) );
$kitL = snt_leaf_paint( 'connections', 'scheduled-content' );
ok( array() === snt_leaf_folds( $kitL ) && false !== strpos( $kitL, '3 posts and pages wait to auto-publish' ) && false !== strpos( $kitL, '5 scheduled fragments: 2 active, 2 queued, 1 done.' ) && false === strpos( $kitL, 'snt-ledger-more' ), 'live-shaped: both findings, no fold, nothing capped' );
snt_leaf_preview( 'connections-scheduled-content', $kitL );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
