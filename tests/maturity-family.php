<?php
/**
 * Tests for the v10.11.0 maturity-family expansion: [sn_machine_maturity],
 * [sn_ops_maturity], [sn_a11y_maturity], and the [sn_maturity_index] hub.
 * One consolidated fixture: registration, format contracts, filter seams,
 * and the SECURITY CONTRACT sweep (model, never levers) across every
 * rendered format of every new page.
 * Run: php tests/maturity-family.php
 * @since plugin v10.11.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return filter_var( $s, FILTER_VALIDATE_URL ) ? $s : ''; }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }
function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $defaults as $k => $v ) {
		$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
	}
	return $out;
}
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ] = $cb; }
function remove_all_filters( $tag ) { unset( $GLOBALS['__filters'][ $tag ] ); }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['__filters'][ $tag ] ) ? call_user_func( $GLOBALS['__filters'][ $tag ], $value ) : $value;
}
$GLOBALS['__enq'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['__enq'][] = array( $handle, (string) $src );
	return true;
}
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' );
}
// Page store models the LIVE hierarchy: every family page is a CHILD of
// /maturity/ (the drift that motivated slug resolution in v10.11.2).
$GLOBALS['__pages'] = array( 'analytics', 'proof-of-origin', 'ai-maturity', 'machine-readability', 'ops-maturity', 'a11y-maturity', 'ml-maturity', 'roadmap' );
function get_posts( $args ) {
	$name = isset( $args['name'] ) ? (string) $args['name'] : '';
	return in_array( $name, $GLOBALS['__pages'], true ) ? array( (object) array( 'post_name' => $name ) ) : array();
}
function get_permalink( $post ) {
	return 'https://example.com/maturity/' . $post->post_name . '/';
}

// R3 3B: the machine-readability page grew ONE non-static element — the
// rights-read count. Its modules are loaded here on purpose. The security
// sweep below is the standing "model, never levers" contract for this family,
// and if the snapshot module were absent the new section would render empty
// and the sweep would pass over a sentence it never actually saw. A stored
// measurement is planted so the count renders in its fullest form.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? (string) $single : (string) $plural; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return true; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { return true; }
$GLOBALS['__options'] = array(
	'sn_mr_snapshot' => array(
		'captured_at' => time() - ( 3 * 86400 ),
		'days'        => 30,
		'total'       => 912,
		'by_family'   => array( 'openai' => 900, 'anthropic' => 12 ),
		'by_surface'  => array( 'html' => 900, 'robots' => 7, 'llms' => 5 ),
		// Both statuses that carry a NUMBER, so the lever sweep sees real prose.
		'referrals'   => array( 'Claude' => 3 ),
	),
);
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
require __DIR__ . '/../inc/machine-readers-snapshot.php';
require __DIR__ . '/../inc/machine-readers-operators.php';
require __DIR__ . '/../inc/machine-readers-giveback.php';
require __DIR__ . '/../inc/machine-readers-rights-reads.php';
require __DIR__ . '/../inc/machine-maturity-page.php';
require __DIR__ . '/../inc/ops-maturity-page.php';
require __DIR__ . '/../inc/a11y-maturity-page.php';
require __DIR__ . '/../inc/maturity-index-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration\n";
foreach ( array( 'sn_machine_maturity', 'sn_ops_maturity', 'sn_a11y_maturity', 'sn_maturity_index' ) as $tag ) {
	ok( isset( $GLOBALS['__shortcodes'][ $tag ] ), "$tag registered on load" );
}
ok( array() === $GLOBALS['__enq'], 'loading the files enqueues nothing — stylesheets ride the render' );
ok( 6 === count( sn_machine_maturity_layers() ) && 5 === count( sn_ops_maturity_layers() ) && 5 === count( sn_a11y_maturity_layers() ), 'ops and a11y walk five layers; machine readability walks six (v10.71.0 added the rights layer)' );
ok( 8 === count( sn_machine_maturity_principles() ) && 8 === count( sn_ops_maturity_principles() ), 'eight principles per page, matching the family' );
// a11y is the SECOND page to break the family's eight, and for the same reason
// the AI page broke it at nine: the extra principle arrived by GRADUATION off
// the hub roadmap board, not by authoring. The done column's ceiling forces a
// shipped row off the board once the column fills, and the family page is where
// it lands -- so an asymmetric count here is the mechanism working, not drift.
ok( 10 === count( sn_a11y_maturity_principles() ), 'a11y carries TEN - the ninth graduated off the roadmap board when the done column first filled (v12.6.3), the tenth when it filled again (v13.8.2)' );
// THE GRADUATION PIN, mirroring the AI page's. Pinned on the RENDERED html, not
// on the array: a claim sitting in an array no format emits is the
// mechanism-without-surface shape this project keeps re-learning. Substance in
// two halves so a rewrite may reword freely but cannot quietly drop either --
// repairing heading order and refusing to land on a moved block are different
// promises, and the second is the one that makes the first safe to run.
$a11y_principles = sn_a11y_maturity_principles_html();
ok( false !== strpos( $a11y_principles, 'heading order' ), 'GRADUATION: the structural-scan claim renders on the a11y page - it retired off the board, it did not vanish' );
ok( false !== strpos( $a11y_principles, 'fingerprint' ), 'GRADUATION: and it keeps the fingerprint-bound half - a repair that cannot prove where it is landing is the failure this row was written against' );
ok( 8 === count( sn_maturity_index_items() ), 'the index lists all eight cards (v10.55.1: + the hub-wide roadmap)' );

echo "\nGroup: format contract (spot: each page's format whitelist behaves)\n";
foreach ( array( 'machine' => 'sn_machine_maturity_shortcode', 'ops' => 'sn_ops_maturity_shortcode', 'a11y' => 'sn_a11y_maturity_shortcode' ) as $n => $fn ) {
	$full = $fn( array() );
	ok( false !== strpos( $full, "sn-$n-maturity--full" ) && false !== strpos( $full, "sn-$n-maturity-table" ), "$n: bare renders full with the table" );
	$bogus = $fn( array( 'format' => '"><script>x</script>' ) );
	ok( false !== strpos( $bogus, "sn-$n-maturity--full" ) && false === strpos( $bogus, '<script' ), "$n: unknown format falls back; raw attr never reaches the class" );
}

echo "\nGroup: the rights layer (v10.71.0) — position, count prose, coverage\n";
// POSITION is the argument, so it is pinned, not merely presence: a machine
// meets the terms at discovery time (a response header, and the content
// signal on the crawler manifest), before it has parsed anything. Appending
// 'reserved' last would seat it beside 'agents' and imply the terms bind
// agents only — the opposite of a reservation that rides every response.
$m_slugs = array_keys( sn_machine_maturity_layers() );
ok( array( 'indexed', 'reserved', 'structured', 'summarized', 'stamped', 'agents' ) === $m_slugs, 'the walk runs indexed → reserved → structured → summarized → stamped → agents; terms sit at position 2, where a machine actually meets them' );
$m_full = sn_machine_maturity_shortcode( array() );
// The hardcoded-count trap: the intro states the layer count in PROSE, so it
// cannot drift with the array. A stale "Five" is the tell.
ok( false !== strpos( $m_full, 'Six layers' ) && false === strpos( $m_full, 'Five layers' ), 'the intro states SIX layers — the prose count tracks the array, no stale "Five"' );
ok( false !== strpos( $m_full, 'Can machines know the terms?' ), 'the rights layer asks its own question in the layer table' );
// Publishing the badge without the measured result would break the page's
// own eighth principle ("a format nobody can verify is decoration").
ok( false !== strpos( $m_full, 'no declared AI-training crawler has' ), 'the rights layer publishes its measured result, not just its existence' );
$m_scope = sn_machine_maturity_scope();
foreach ( array( 'reservation', 'signal', 'licence', 'policy', 'vocabulary', 'identity' ) as $row ) {
	ok( isset( $m_scope[ $row ] ) && 'live' === $m_scope[ $row ][1], "coverage carries the $row row, live" );
}
ok( 11 === count( $m_scope ), 'eleven coverage rows (five original + the four rights surfaces + the terms vocabulary + identity discovery, v11.27.0)' );
// The layer list is ALSO stated as prose in two other places; both drift
// silently, so both are pinned to mention the terms.
ok( false !== strpos( sn_machine_maturity_shortcode( array( 'format' => 'compact' ) ), 'the terms on every response' ), 'the compact blurb names the terms too' );
$machine_card = sn_maturity_index_items()['machine'][2];
ok( false !== strpos( $machine_card, 'machine-readable terms' ), 'the /maturity/ index card for this family names the terms too' );

echo "\nGroup: index cards + filter seam\n";
$idx = sn_maturity_index_shortcode();
ok( 8 === substr_count( $idx, '<a class="sn-maturity-index-card' ), 'all eight default cards are linked (every default slug resolves)' );
ok( false !== strpos( $idx, 'https://example.com/maturity/ai-maturity/' ) && false !== strpos( $idx, 'https://example.com/maturity/machine-readability/' ) && false !== strpos( $idx, 'https://example.com/maturity/analytics/' ), 'v10.11.2: links resolve from the PAGES (get_permalink) — hierarchy-proof, child-of-/maturity/ paths come out right' );
ok( 'https://example.com/legacy/' === sn_maturity_index_resolve_url( '/legacy/' ), 'explicit path targets stay supported (filter escape hatch)' );
ok( 'https://ext.example/x' === sn_maturity_index_resolve_url( 'https://ext.example/x' ), 'absolute URL targets pass through' );
ok( '' === sn_maturity_index_resolve_url( 'no-such-page' ), 'unresolvable slug returns empty — the card renders unlinked, never dead' );
add_filter( 'sn_maturity_index_items', function ( $items ) {
	$items['future'] = array( 'Future moat', 'What next?', 'A page that does not exist yet.', '' );
	return $items;
} );
$idx2 = sn_maturity_index_shortcode();
ok( false !== strpos( $idx2, 'sn-maturity-index-card--unlinked' ) && false !== strpos( $idx2, 'Future moat' ), 'an empty path renders an UNLINKED card — never a dead link' );
remove_all_filters( 'sn_maturity_index_items' );

echo "\nGroup: SECURITY CONTRACT — no lever leaks across every new rendered surface\n";
$all = sn_maturity_index_shortcode();
foreach ( array( 'sn_machine_maturity_shortcode', 'sn_ops_maturity_shortcode', 'sn_a11y_maturity_shortcode' ) as $fn ) {
	foreach ( array( 'full', 'table', 'principles', 'scope', 'compact' ) as $f ) {
		$all .= $fn( array( 'format' => $f ) );
	}
}
// Coverage proof for the sweep below. Without these four, the security sweep
// would happily pass over a section that rendered as an empty string, and the
// rights-read sentence would be unswept while looking guarded.
ok( false !== strpos( $all, 'Machines read' ), 'the rights-read count IS present in the swept output (the sweep is not vacuous)' );
ok( false !== strpos( $all, 'never sent a reader back' ), 'the give-back section IS present in the swept output too' );
ok( false !== strpos( $all, 'OpenAI read this site 900 times and has never sent a reader back' ), 'the loudest row renders as a SENTENCE, not a bare 0' );
ok( false !== strpos( $all, 'Anthropic read this site 12 times and sent 3 readers back' ), 'a repaying operator states both sides' );
ok( false !== strpos( $all, 'cannot be told apart from ordinary search' ), 'not_measurable explains WHY it has no answer, rather than showing a dash' );
// THE SHAPE THAT SHIPPED BROKEN: a snapshot with crawl counts but NO referral
// map — exactly what every install has until the first cron run after v10.91.0.
// The fixtures above always supplied referrals, so the section rendered sixteen
// rows live (three permanent non-answers, thirteen identical "not measured
// yet") and no test noticed.
$saved_snap = $GLOBALS['__options']['sn_mr_snapshot'];
unset( $GLOBALS['__options']['sn_mr_snapshot']['referrals'] );
$norefs = sn_machine_maturity_shortcode( array( 'format' => 'full' ) );
// Count the MODIFIER class: the base class appears on the same element, so
// counting the base counts every row twice.
$rows_norefs = substr_count( $norefs, 'giveback__row--' );
ok( $rows_norefs <= 2, "with no referral data the section is at most 2 rows, not one per operator (got $rows_norefs)" );
ok( false !== strpos( $norefs, 'Not measured yet:' ), 'the unmeasured operators collapse into ONE named sentence' );
ok( false !== strpos( $norefs, 'OpenAI' ) && false !== strpos( $norefs, 'Common Crawl' ), 'and that sentence still NAMES them — collapsed, not hidden' );
ok( 1 === substr_count( $norefs, 'cannot be told apart from ordinary search' ), 'the permanent non-answers collapse to ONE sentence too' );
ok( false !== strpos( $norefs, 'Microsoft, DeepSeek and xAI send readers' ), 'and read as a list with proper grammar' );
$GLOBALS['__options']['sn_mr_snapshot'] = $saved_snap;

// The caveats must TRAIL the answers — a section that opens by explaining what
// it cannot measure buries what it can. This is what the live page did.
$ans = strpos( $all, 'never sent a reader back' );
$cav = strpos( $all, 'cannot be told apart from ordinary search' );
ok( false !== $ans && false !== $cav && $ans < $cav, 'answers come before caveats' );

$gb_pos = strpos( $all, 'never sent a reader back' );
$ok_pos = strpos( $all, 'sent 3 readers back' );
ok( $gb_pos < $ok_pos, 'the never-repaid row sorts ABOVE the repaying one — the finding leads' );
ok( false !== strpos( $all, '12 times' ), 'the published count sums only the rights surfaces (7+5), never the 900 article reads' );
// Scoped to the rights-read SENTENCE, not the whole page. The blunt global
// check was a fine proxy while nothing else printed that number, and became a
// false positive the moment the give-back section legitimately said "read this
// site 900 times" about CRAWLS. An assertion's blast radius should match the
// claim it is defending.
$rr_start = strpos( $all, 'Machines read this site' );
$rr_sentence = false === $rr_start ? '' : substr( $all, $rr_start, 200 );
ok( '' !== $rr_sentence && false === strpos( $rr_sentence, '900' ), 'article reads never leak into the RIGHTS-READ sentence specifically' );
ok( false !== strpos( $all, 'Last measured 3 days ago' ), 'a stale count states its age on the public page, not just internally' );

$forbidden = array(
	// options, constants, credentials
	'sn_mcp_read_enabled', 'sn_mcp_rw_enabled', 'SN_MCP_READ_DISABLED', 'SN_MCP_RW_DISABLED', 'application password',
	// endpoint paths + protocol internals
	'wp-json', 'signal-noise/v1', '/mcp', 'mcp-rw',
	// ability/tool slugs + meta keys
	'update-post-surfaces', 'duplicate-body-scan', '_sn_focus_keyword', '_sn_autogen',
	// hook/file/vendor internals
	'snt_', 'sn_cf_', 'deploy.yml', 'github', 'cloudways', 'cloudflare', 'workers.dev', 'opentimestamps',
	// operational numbers that identify levers
	'5 writes', '10 minutes',
);
$leaks = array();
$low   = mb_strtolower( $all );
foreach ( $forbidden as $token ) {
	if ( false !== mb_strpos( $low, mb_strtolower( $token ) ) ) {
		$leaks[] = $token;
	}
}
ok( array() === $leaks, 'no sensitive token in any rendered format of any new page' . ( $leaks ? ' — LEAKED: ' . implode( ', ', $leaks ) : '' ) );
ok( false !== mb_strpos( $low, 'wcag 2.1 aa' ), 'sanity: a11y still names its standard (public claim, kept)' );
ok( false !== mb_strpos( $low, 'never confuses' ) || false !== mb_strpos( $low, 'never confused' ), 'sanity: ops carries the zero-vs-unknown principle' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
