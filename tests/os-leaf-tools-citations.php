<?php
/**
 * Native window leaf: Tools → Citations (apps/sn-dashboard/parts/leaves/tools-citations.php).
 *
 * A pure readout leaf — no forms, no sn_action. The oracle is the classic leaf
 * (inc/citations-admin.php, `sn_admin_render_citations_section()`): the same
 * glance hero (one card per tier, every tier printed even at zero), the same
 * three-way summary sentence, the same inbox address, the same folded legend,
 * and the same up-to-100-row claims table, painted from the kit instead of
 * wp-admin markup.
 *
 * Run: php tests/os-leaf-tools-citations.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── A minimal $wpdb stand-in: get_results() answers either the counts query
// or the claims query by sniffing the SQL, exactly as the classic renderer
// issues them; get_var() answers the never-checked count.
class SNT_Citations_Test_WPDB {
	public $prefix = 'wp_';
	public $counts_rows = array();
	public $claim_rows  = array();
	public $never       = 0;

	public function get_results( $sql ) {
		if ( false !== strpos( $sql, 'GROUP BY tier' ) ) {
			return $this->counts_rows;
		}
		if ( false !== strpos( $sql, 'ORDER BY first_seen_gmt' ) ) {
			return $this->claim_rows;
		}
		return array();
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'IS NULL' ) ) {
			return $this->never;
		}
		return 0;
	}
}

/** @param string $tier @param int $n @return object */
function snt_cit_test_count_row( $tier, $n ) {
	return (object) array( 'tier' => $tier, 'n' => $n );
}

/**
 * @param array<string,mixed> $fields
 * @return object
 */
function snt_cit_test_claim_row( array $fields ) {
	$defaults = array(
		'tier'             => 'verified',
		'source_url'       => 'https://example.org/post',
		'source_title'     => '',
		'target_url'       => 'https://example.test/notes/hello',
		'target_post_id'   => 0,
		'first_seen_gmt'   => '2026-09-01 00:00:00',
		'last_checked_gmt' => '2026-09-02 00:00:00',
		'last_status'      => 200,
	);
	return (object) array_merge( $defaults, $fields );
}

$GLOBALS['__titles'] = array();
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post_id = 0 ) { return $GLOBALS['__titles'][ (int) $post_id ] ?? ''; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
}

global $wpdb;
$wpdb = new SNT_Citations_Test_WPDB();

require SNT_PATH . 'inc/citations-core.php';
require SNT_PATH . 'inc/citations-store.php';
require SNT_PATH . 'inc/citations-endpoint.php';
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/citations-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-citations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['tools/citations'] ), 'the painter is registered under tools/citations' );

// ── State 1: empty — no counts, no rows.
$wpdb->counts_rows = array();
$wpdb->claim_rows  = array();
$wpdb->never       = 0;
$classic = snt_leaf_classic_html( 'sn_admin_render_citations_section' );
$kit     = snt_leaf_paint( 'tools', 'citations' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array() === snt_leaf_names( $kit ), 'no field names in either (a readout leaf): ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $kit ), 'no sn_action in either' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $classic, 'No citations recorded' ) && false !== strpos( $kit, 'No citations recorded' ), 'empty state: both say "No citations recorded"' );
ok( false !== strpos( $kit, 'Nothing to list yet' ), 'empty state: the no-rows sentence is printed' );
foreach ( SN_CIT_TIERS as $tier ) {
	ok( false !== strpos( $kit, '<os-stat value="0" label="' . $tier . '"' ), "empty state: the $tier glance card reads 0" );
}

// ── State 2: a rich fixture — mixed tiers, a never-checked row, a cited post,
// a bare URL with no title, an HTTP failure (0), and exactly 100 rows (the cap notice).
$wpdb->counts_rows = array(
	snt_cit_test_count_row( 'verified', 3 ),
	snt_cit_test_count_row( 'unattributed', 1 ),
	snt_cit_test_count_row( 'asserted', 2 ),
	snt_cit_test_count_row( 'unverified', 4 ),
);
$wpdb->never = 2;
$GLOBALS['__titles'][ 77 ] = 'Two kinds of provenance';
$rows = array(
	snt_cit_test_claim_row( array(
		'tier'             => 'verified',
		'source_url'       => 'https://blog.example/2026/post',
		'source_title'     => 'A Blog Post',
		'target_url'       => 'https://example.test/notes/prov',
		'target_post_id'   => 77,
		'first_seen_gmt'   => '2026-08-01 00:00:00',
		'last_checked_gmt' => '2026-09-01 00:00:00',
		'last_status'      => 200,
	) ),
	snt_cit_test_claim_row( array(
		'tier'             => 'asserted',
		'source_url'       => 'https://gone.example/x',
		'source_title'     => '',
		'target_url'       => 'https://example.test/notes/prov',
		'target_post_id'   => 77,
		'first_seen_gmt'   => '2026-07-01 00:00:00',
		'last_checked_gmt' => '2026-09-02 00:00:00',
		'last_status'      => 404,
	) ),
	snt_cit_test_claim_row( array(
		'tier'             => 'unverified',
		'source_url'       => 'https://unreachable.example/y',
		'source_title'     => '',
		'target_url'       => 'https://example.test/notes/other',
		'target_post_id'   => 0,
		'first_seen_gmt'   => '2026-09-03 00:00:00',
		'last_checked_gmt' => null,
		'last_status'      => 0,
	) ),
);
for ( $i = count( $rows ); $i < 100; $i++ ) {
	$rows[] = snt_cit_test_claim_row( array( 'source_url' => 'https://filler.example/' . $i ) );
}
$wpdb->claim_rows = $rows;

$classic = snt_leaf_classic_html( 'sn_admin_render_citations_section' );
$kit     = snt_leaf_paint( 'tools', 'citations' );

ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array() === snt_leaf_names( $kit ), 'rich fixture: still no field names in either' );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $kit ), 'rich fixture: still no sn_action in either' );
ok( array() === snt_leaf_classic_markers( $kit ), 'rich fixture: no wp-admin markup: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

ok( false !== strpos( $kit, '<os-stat value="3" label="verified"' ), 'rich fixture: verified glance card reads 3' );
ok( false !== strpos( $kit, '<os-stat value="1" label="unattributed"' ), 'rich fixture: unattributed glance card reads 1' );
ok( false !== strpos( $kit, '<os-stat value="2" label="asserted"' ) && false !== strpos( $kit, 'evidence gone' ), 'rich fixture: asserted glance card reads 2 and carries the "evidence gone" note' );
ok( false !== strpos( $kit, '<os-stat value="4" label="unverified"' ) && false !== strpos( $kit, '2 never checked' ), 'rich fixture: unverified glance card reads 4 and carries the "2 never checked" note' );

ok( false !== strpos( $kit, '10 claims: 3 verified' ), 'rich fixture: the summary sentence states the total and per-tier breakdown' );
ok( false !== strpos( $kit, '2 have never been checked' ), 'rich fixture: the summary sentence states the never-checked count' );
ok( false !== strpos( $kit, 'https://example.test/wp-json/signal-noise/v1/webmention' ), 'rich fixture: the public inbox URL is printed' );
ok( false !== strpos( $kit, '<os-disclosure' ) && false !== strpos( $kit, 'What the four tiers mean' ), 'rich fixture: the folded legend is a kit disclosure' );
foreach ( SN_CIT_TIERS as $tier ) {
	ok( false !== strpos( $kit, sn_cit_tier_sentence( $tier ) ), "rich fixture: the legend states $tier's sentence" );
}

ok( false !== strpos( $kit, 'A Blog Post (blog.example)' ) || ( false !== strpos( $kit, 'A Blog Post' ) && false !== strpos( $kit, 'blog.example' ) ), 'rich fixture: a titled source carries its host' );
ok( false !== strpos( $kit, 'Two kinds of provenance' ) && false !== strpos( $kit, '/notes/prov' ), 'rich fixture: a cited post carries its title and path' );
ok( false !== strpos( $kit, 'gone.example' ), 'rich fixture: a source with no title falls back to its host' );
ok( false !== strpos( $kit, '/notes/other' ), 'rich fixture: an uncited target falls back to its bare path' );
ok( false !== strpos( $kit, '>404<' ), 'rich fixture: the 404 status is printed in the HTTP field' );
ok( false !== strpos( $kit, '>—<' ), 'rich fixture: the zero-status row prints the em dash, not "0"' );
ok( false !== strpos( $kit, 'The newest 100 claims are listed' ), 'rich fixture: exactly 100 rows triggers the cap notice' );

// ── Links: every href the classic table offers survives in the kit output
// (os-card + os-link, not a table cell, but the same URLs must be reachable).
preg_match_all( '/href="([^"]+)"/', $classic, $classic_hrefs );
$missing_hrefs = array();
foreach ( array_unique( $classic_hrefs[1] ) as $href ) {
	if ( false === strpos( $kit, $href ) ) {
		$missing_hrefs[] = $href;
	}
}
ok( array() === $missing_hrefs, 'rich fixture: every link the classic table offers survives: missing ' . implode( ',', $missing_hrefs ) );
ok(
	false !== strpos( $kit, 'https://blog.example/2026/post' ) && false !== strpos( $kit, 'https://example.test/notes/prov' ),
	'rich fixture: the source and cited-page URLs are reachable (clickable), not just their host/path text'
);

// ── Escaping: a hostile source title never reaches the markup raw.
$wpdb->claim_rows = array(
	snt_cit_test_claim_row( array( 'source_title' => '"><script>x</script>' ) ),
);
$kit = snt_leaf_paint( 'tools', 'citations' );
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, 'script' ), 'a hostile source title is escaped (raw <script> never appears)' );
ok( false === strpos( $kit, '<script>' ), 'no literal <script> tag reaches the markup' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
