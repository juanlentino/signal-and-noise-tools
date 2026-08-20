<?php
/**
 * Tests for inc/ai-maturity-page.php — the [sn_ai_maturity] static explainer
 * (third sibling of analytics/provenance maturity). Mirrors the sibling
 * fixture, PLUS the SECURITY CONTRACT block: every rendered format is
 * asserted free of the sensitive tokens the page must never leak (option
 * names, constants, endpoint paths, tool slugs, meta keys, throttle numbers).
 * Run: php tests/ai-maturity-page.php
 * @since plugin v10.10.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
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

require __DIR__ . '/../inc/ai-maturity-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + contract\n";
ok( isset( $GLOBALS['__shortcodes']['sn_ai_maturity'] ) && 'sn_ai_maturity_shortcode' === $GLOBALS['__shortcodes']['sn_ai_maturity'], 'shortcode registered on load' );
ok( array() === $GLOBALS['__enq'], 'loading the file enqueues nothing — the stylesheet rides the render, not the pageload' );
ok( array( 'spec', 'generate', 'check', 'review', 'mark', 'bound' ) === array_keys( sn_ai_maturity_layers() ), 'layer slugs in walk order: spec, generate, check, review, mark, bound' );
// 2026-08-14: nine, one MORE than the siblings. The ninth arrived by
// graduation off the hub roadmap board, not by authoring, so the count
// deliberately breaks the sibling symmetry the eighth was pinned to.
ok( 9 === count( sn_ai_maturity_principles() ), 'nine honesty principles — the ninth graduated in off the roadmap board, breaking the sibling symmetry on purpose' );
ok( in_array( 'never', SN_AI_MATURITY_STATUSES, true ), "the scope whitelist carries 'never' — the one deliberate divergence from the siblings" );

echo "\nGroup: formats\n";
$full = sn_ai_maturity_shortcode( array() );
ok( false !== strpos( $full, 'sn-ai-maturity--full' ), 'bare shortcode renders full' );
ok( false !== strpos( $full, 'sn-ai-maturity-table' ) && false !== strpos( $full, 'sn-ai-maturity-principles' ) && false !== strpos( $full, 'sn-ai-maturity-scope' ), 'full carries table + principles + scope' );
$table = sn_ai_maturity_shortcode( array( 'format' => 'table' ) );
ok( false !== strpos( $table, 'sn-ai-maturity-table' ) && false === strpos( $table, 'sn-ai-maturity-principles' ), 'table format is table only' );
$compact = sn_ai_maturity_shortcode( array( 'format' => 'compact' ) );
ok( 6 === substr_count( $compact, 'sn-ai-maturity-badge ' ), 'compact strip carries one badge per layer (6)' );
$bogus = sn_ai_maturity_shortcode( array( 'format' => '"><script>alert(1)</script>' ) );
ok( false !== strpos( $bogus, 'sn-ai-maturity--full' ) && false === strpos( $bogus, '<script' ), 'unknown format falls back to full; raw attribute never reaches the class' );
ok( array() !== $GLOBALS['__enq'] && 'sn-ai-maturity-front' === $GLOBALS['__enq'][0][0], 'rendering enqueues the front stylesheet by handle' );

echo "\nGroup: scope statuses + filter seam\n";
$scope_html = sn_ai_maturity_shortcode( array( 'format' => 'scope' ) );
ok( false !== strpos( $scope_html, 'sn-ai-maturity-scope-badge--never' ), "Note bodies render with the 'never' badge" );
ok( false !== strpos( $scope_html, 'Note bodies' ), 'the bodies commitment is present by name' );
ok( false !== strpos( $scope_html, 'sn-ai-maturity-scope-badge--never"><strong>Relevance ranking' ), "v10.18.0: 'Relevance ranking &amp; similarity' renders as a never — the deterministic tier below AI is named, so 'where AI stops' has a floor" );
ok( 2 === substr_count( $scope_html, 'sn-ai-maturity-scope-badge--never' ), 'exactly two never badges on the AI page: bodies + ranking' );

// v10.54.x sentence_replace scope: the proposal path is LIVE while the
// drafting commitment stays a never — adjacent rows, no contradiction.
ok( false !== strpos( $scope_html, 'Sentence-level edit proposals' ), 'the sentence_replace proposal row renders' );
ok( false !== strpos( $scope_html, 'sn-ai-maturity-scope-badge--live"><strong>Sentence-level edit proposals' ), 'and it renders as LIVE' );
$principles_html = sn_ai_maturity_principles_html();
ok( false !== strpos( $principles_html, 'staged revision a person accepts' ), 'principle 1 states the staged-revision acceptance path, not the pre-sentence_replace absolute' );
// THE GRADUATION PIN. The threat-model claim left the hub roadmap board on
// 2026-08-14 to make room under the done ceiling; this is the assertion that
// the move was a MOVE and not a deletion. Pinned on the RENDERED html, not on
// sn_ai_maturity_principles(), because a claim sitting in an array that no
// format emits is exactly the mechanism-without-surface shape this project
// keeps re-learning — the array is where it lives, the page is where it counts.
// Substance, not wording: the three parts of the claim are pinned separately so
// a rewrite may reword it freely but cannot quietly drop half of it.
ok( false !== strpos( $principles_html, 'threat model' ), 'GRADUATION: the threat-model claim renders on the AI page — it retired off the board, it did not vanish' );
ok( false !== strpos( $principles_html, 'gate by gate' ), 'GRADUATION: and it keeps the gate-by-gate argument, the part that makes it a method rather than a reassurance' );
ok( false !== strpos( $principles_html, 'rather than closed by assertion' ), 'GRADUATION: and it keeps the residuals-carried-by-name half — a threat model whose leftovers are waved away is the failure this row was written against' );
add_filter( 'sn_ai_maturity_scope', function ( $scope ) {
	$scope['voice'] = array( 'Voice cloning', 'evil-raw-status' );
	return $scope;
} );
$filtered = sn_ai_maturity_shortcode( array( 'format' => 'scope' ) );
ok( false !== strpos( $filtered, 'Voice cloning' ), 'filter seam adds a surface without markup changes' );
ok( false === strpos( $filtered, 'evil-raw-status' ), 'a status outside the whitelist NEVER reaches the class attribute raw' );
ok( false !== strpos( $filtered, 'sn-ai-maturity-scope-badge--planned"><strong>Voice cloning' ), 'unknown status renders as planned' );
remove_all_filters( 'sn_ai_maturity_scope' );

echo "\nGroup: the bound layer publishes the closed-door claim\n";
// THE CLOSED-DOOR PIN. Until now the `bound` engine string was asserted by
// NOTHING — the walk-order test pins layer SLUGS, so every word of the claim
// could be rewritten or dropped and the suite stayed green. The page named the
// doors, the allowlists, the kill switches and the audit trail, but never said
// the doors are shut to the public web, which is the one thing a reader who
// distrusts an agent surface actually wants to know. Pinned on the RENDERED
// table, not on sn_ai_maturity_layers(), for the same reason the graduation
// pin above is: the array is where it lives, the page is where it counts.
// Substance in two halves so a rewrite may reword freely but cannot quietly
// drop either one — refusing a caller and not advertising the door are
// DIFFERENT properties, and publishing only the first would overclaim.
ok( false !== strpos( $table, 'unauthenticated' ), 'CLOSED DOOR: the bound row says an unauthenticated caller is refused - the property a reader cannot verify from outside' );
ok( false !== strpos( $table, 'listed' ), 'CLOSED DOOR: and it states the doors ARE listed publicly - both routes really do appear in the public interface index, so claiming they are hidden would be falsifiable by any reader in five seconds' );
ok( false !== strpos( $table, 'unlisted' ), 'CLOSED DOOR: and it says why that is fine - an unlisted door is not a closed one, which is the difference between obscurity and refusal' );

echo "\nGroup: SECURITY CONTRACT — no lever leaks in any rendered format\n";
$all_output = '';
foreach ( array( 'full', 'table', 'principles', 'scope', 'compact' ) as $f ) {
	$all_output .= sn_ai_maturity_shortcode( array( 'format' => $f ) );
}
$forbidden = array(
	// options + constants (kill switches, credential binding)
	'sn_mcp_read_enabled', 'sn_mcp_rw_enabled', 'SN_MCP_READ_DISABLED', 'SN_MCP_RW_DISABLED',
	'app_password', 'application password',
	// endpoint paths + namespaces
	'wp-json', 'signal-noise/v1', '/mcp', 'mcp-rw',
	// ability/tool slugs
	'update-post-surfaces', 'duplicate-body-scan', 'get-post-content', 'list-posts',
	// meta keys + sentinels by name
	'_sn_focus_keyword', '_sn_autogen', '_sn_meta_description', '_sn_og_card_title',
	// operational numbers (throttle, allowlist sizes)
	'5 writes', '10 minutes', '10-minute', '28 ', ' 35 ',
	// internal file/function names
	'mcp-read-guard', 'mcp-rw-guard', 'snt_', 'sn_mcp_',
);
$leaks = array();
$low   = mb_strtolower( $all_output );
foreach ( $forbidden as $token ) {
	if ( false !== mb_strpos( $low, mb_strtolower( $token ) ) ) {
		$leaks[] = $token;
	}
}
ok( array() === $leaks, 'no sensitive token appears in ANY rendered format' . ( $leaks ? ' — LEAKED: ' . implode( ', ', $leaks ) : '' ) );
ok( false !== strpos( $all_output, 'Model Context Protocol' ), 'sanity: the page still says something real (protocol named at the design level is deliberate)' );
ok( false !== strpos( $all_output, 'kill switch' ), 'sanity: kill-switch EXISTENCE is published (deterrent); its levers are not' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
