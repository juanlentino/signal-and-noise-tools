<?php
/**
 * Native window leaf: Tools → Links (apps/sn-dashboard/parts/leaves/tools-links.php).
 *
 * The oracle is the classic leaf (inc/admin-forms/links.php): no forms, no
 * `sn_action` values, no dynamic reads — just three groups of two static
 * links each. The suite pins the painter's registration, the (empty) field
 * and action parity with the classic leaf, every group/title/host/href the
 * classic loop prints (including the classic per-card group label, derived
 * from the classic markup rather than hardcoded), that a hostile link value
 * (built directly with the leaf's own `links_card_html()` — the leaf has no
 * dynamic reader and no filter to hook) is escaped, and that no classic
 * wp-admin markup survives.
 *
 * Run: php tests/os-leaf-tools-links.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

require SNT_PATH . 'inc/admin-forms/links.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-links.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/links'] ), 'the painter is registered under tools/links' );

$classic = snt_leaf_classic_html( 'sn_admin_render_links_section' );
$kit     = snt_leaf_paint( 'tools', 'links' );
ok( '' !== $kit, 'the kit leaf paints' );

// ── Field/action parity: the classic leaf carries neither, and neither does ours.
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form (both empty): ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array() === snt_leaf_names( $kit ), 'no form fields: the classic leaf offers none' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $kit ), 'no sn_action values: the classic leaf offers none' );

// ── No classic wp-admin markup survives.
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Every readout the classic loop prints: three group labels, six titles, their hosts, their hrefs.
// 2026-10-12: one section, one grid of six cards; the group names ride each
// card's eyebrow (as the classic card's label), never a section heading too.
ok( 1 === substr_count( $kit, '<os-section' ) && false !== strpos( $kit, 'heading="External shortcuts"' ), 'one section holds all six cards' );
foreach ( array( 'Source code', 'Releases', 'Infrastructure' ) as $label ) {
	ok( false === strpos( $kit, 'heading="' . $label . '"' ), "group label '$label' is no longer a second heading over cards that already carry it" );
}

// ── The classic loop repeats the group label on EVERY card
// (sn-link-card__label), not just once per group. Assert against that
// classic markup directly rather than a hardcoded literal, so a dropped
// per-card label fails automatically.
preg_match_all( '/<span class="sn-link-card__label">([^<]*)<\/span>/', $classic, $classic_labels );
ok( 6 === count( $classic_labels[1] ), 'classic markup carries 6 per-card group labels (sanity on the oracle itself)' );
foreach ( array_unique( $classic_labels[1] ) as $classic_label ) {
	ok(
		2 === substr_count( $kit, '<span class="snt-hint">' . $classic_label . '</span>' ),
		"per-card group label '$classic_label' (classic sn-link-card__label) appears on both its cards as a snt-hint span"
	);
}

$expected_links = array(
	array( 'Theme repo', 'https://github.com/juanlentino/signal-and-noise', 'github.com' ),
	array( 'Plugin repo', 'https://github.com/juanlentino/signal-and-noise-tools', 'github.com' ),
	array( 'Theme releases', 'https://github.com/juanlentino/signal-and-noise/tags', 'github.com' ),
	array( 'Plugin releases', 'https://github.com/juanlentino/signal-and-noise-tools/tags', 'github.com' ),
	array( 'Cloudflare dashboard', 'https://dash.cloudflare.com', 'dash.cloudflare.com' ),
	array( 'Cloudways platform', 'https://platform.cloudways.com', 'platform.cloudways.com' ),
);
$titles_ok = true;
$hrefs_ok  = true;
$hosts_ok  = true;
foreach ( $expected_links as $l ) {
	list( $title, $href, $host ) = $l;
	// The title IS the link, said once (it was a heading, then the same words as the link).
	if ( 1 !== preg_match( '#<h3><a [^>]*href="' . preg_quote( $href, '#' ) . '"[^>]*>' . preg_quote( $title, '#' ) . '</a></h3>#', $kit ) || 1 !== substr_count( $kit, '>' . $title . '<' ) ) {
		$titles_ok = false;
	}
	if ( false === strpos( $kit, 'href="' . $href . '"' ) ) {
		$hrefs_ok = false;
	}
	if ( false === strpos( $kit, $host . ' ' ) ) {
		$hosts_ok = false;
	}
}
ok( $titles_ok, 'all six link titles are painted once each, as the link itself' );
preg_match_all( '#<a [^>]*class="sn-link-card__title"[^>]*>([^<]*)</a>#', $classic, $cl_titles );
ok( 6 === count( $cl_titles[1] ) && false === strpos( $classic, 'sn-link-card__link' ) && false === strpos( $classic, '<span class="sn-link-card__title">' ), 'classic: the title is the link too, with no hidden second link of the same words' );
ok( $hrefs_ok, 'all six hrefs are painted, unmodified from the classic array' );
ok( $hosts_ok, 'all six hosts (wp_parse_url( $href, PHP_URL_HOST )) are painted, matching the classic loop' );
ok( substr_count( $kit, '<os-card' ) === 6, 'six link cards are painted, one per classic link' );
ok( 1 === substr_count( $kit, '<os-grid' ) && false !== strpos( $kit, 'min-item-width="360"' ), 'the six cards share one kit grid at a 360px minimum: three columns of about 400px at a 1,300px window, the width the URLs were measured to need' );
ok( substr_count( $kit, 'target="_blank"' ) === 6 && substr_count( $kit, 'rel="noopener noreferrer"' ) === 6, 'every link opens in a new tab, as the classic anchor does' );

// ── Escaping: a hostile title and href, built directly with the leaf's own
// card helper (there is no dynamic reader or filter here to hook), never
// reach the markup raw.
$hostile = \SignalNoise\OpenStationHost\Dashboard\Leaves\links_card_html(
	'"><script>g</script>',
	array(
		'title' => '"><script>x</script>',
		'href'  => 'https://example.test/?q="><script>y</script>',
	)
);
ok(
	false === strpos( $hostile, '<script>' )
	&& false !== strpos( $hostile, '&lt;script&gt;x&lt;/script&gt;' )
	&& false !== strpos( $hostile, 'os-arg-url="https://example.test/?q=&quot;&gt;&lt;script&gt;y&lt;/script&gt;"' ),
	'a hostile title and href are escaped (the href now as the door arg, 14.7.5)'
);
ok( array() === snt_leaf_classic_markers( $hostile ), 'hostile fixture: no wp-admin markup survives either' );


// ── 2026-10-12: the old "NOT paired" reason (three stacked groups, each
// already two columns wide) no longer applies: one grid replaces the three
// sections, so there is no row of sections to divide an already-divided width.
$kitP = snt_leaf_paint( 'tools', 'links' );
ok( false === strpos( $kitP, 'snt-systems' ) && 0 === substr_count( $kitP, '<os-grid columns="2"' ), 'no nested two-column grids inside a row of sections' );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
