<?php
/**
 * Standalone fixture tests for inc/rest-hardening.php +
 * inc/rest-hardening-policy.php (v9.83.0 anonymous REST hardening).
 *
 * The real modules are require'd, not reimplemented — the decision layer is
 * pure by design so the asserts drive production code rather than a stub that
 * could drift from it. Only WordPress itself is stubbed.
 *
 * Covers:
 *   - route removal for anonymous callers (users, users/{id}, comments, batch)
 *   - posts/pages routes SURVIVE removal (they are stripped, not killed)
 *   - sn-prov/v1 + signal-noise/v1 survive, including against a filter that
 *     explicitly tries to remove them (the protected veto)
 *   - logged-in callers see an untouched route table (editor/media flows)
 *   - content.rendered / excerpt.rendered emptied for anon, preserved for
 *     logged-in, keys retained either way
 *   - TDM headers emitted with replace=true and the exact pinned values
 *   - REGISTRATION GUARD: the module is actually require'd from the plugin
 *     bootstrap, and the three hooks are actually bound. A module that is
 *     written but never loaded passes every behavioural test above.
 *
 * Run: php tests/rest-hardening.php
 *
 * @since plugin v9.83.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$pass = 0;
$fail = 0;
/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Label.
 * @return void
 */
function rh_check( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $msg\n";
	} else {
		$fail++;
		echo "FAIL: $msg\n";
	}
}

// ---- WordPress stubs (recording) ------------------------------------------
$GLOBALS['__test_filters']     = array();
$GLOBALS['__test_logged_in']   = false;
$GLOBALS['__test_added_filter'] = array();
$GLOBALS['__test_added_action'] = array();

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return array_key_exists( $hook, $GLOBALS['__test_filters'] )
			? $GLOBALS['__test_filters'][ $hook ]
			: $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook = '', $cb = null, $priority = 10 ) {
		$GLOBALS['__test_added_filter'][] = array( $hook, $cb, $priority );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook = '', $cb = null, $priority = 10 ) {
		$GLOBALS['__test_added_action'][] = array( $hook, $cb, $priority );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return $GLOBALS['__test_logged_in'];
	}
}

/** Minimal WP_REST_Response stand-in: public data + a recording header(). */
class RH_Response {
	public $data    = array();
	public $headers = array();
	/**
	 * Record a header call.
	 *
	 * @param string $n Name.
	 * @param string $v Value.
	 * @param bool   $r Replace.
	 * @return void
	 */
	public function header( $n, $v, $r = false ) {
		$this->headers[] = array( $n, $v, $r );
	}
}

require_once __DIR__ . '/../inc/rest-hardening.php';

// The live route keys, copied from https://juanlentino.com/wp-json/ on
// 2026-07-28 so the fixture matches what WordPress actually registers.
$routes = array(
	'/wp/v2/posts'                        => 1,
	'/wp/v2/posts/(?P<id>[\d]+)'          => 1,
	'/wp/v2/pages'                        => 1,
	'/wp/v2/media'                        => 1,
	'/wp/v2/users'                        => 1,
	'/wp/v2/users/(?P<id>[\d]+)'          => 1,
	'/wp/v2/users/me'                     => 1,
	'/wp/v2/comments'                     => 1,
	'/wp/v2/comments/(?P<id>[\d]+)'       => 1,
	'/batch/v1'                           => 1,
	'/oembed/1.0/embed'                   => 1,
	'/sn-prov/v1'                         => 1,
	'/sn-prov/v1/status'                  => 1,
	'/signal-noise/v1'                    => 1,
	'/signal-noise/v1/mcp'                => 1,
	'/desktop-mode/v1/users'              => 1,
);

// ---- 1. Anonymous: the four named surfaces are removed ---------------------
$GLOBALS['__test_logged_in'] = false;
$anon = snt_rest_hardening_endpoints( $routes );

foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)', '/wp/v2/comments', '/batch/v1' ) as $gone ) {
	rh_check( ! isset( $anon[ $gone ] ), "anon: $gone removed" );
}
rh_check( ! isset( $anon['/wp/v2/users/me'] ), 'anon: /wp/v2/users/me removed by prefix match' );
rh_check( ! isset( $anon['/wp/v2/comments/(?P<id>[\d]+)'] ), 'anon: single-comment route removed by prefix match' );

// ---- 2. Anonymous: posts/pages and neighbours SURVIVE ----------------------
foreach ( array( '/wp/v2/posts', '/wp/v2/posts/(?P<id>[\d]+)', '/wp/v2/pages', '/wp/v2/media', '/oembed/1.0/embed' ) as $kept ) {
	rh_check( isset( $anon[ $kept ] ), "anon: $kept survives (stripped, not killed)" );
}
rh_check( isset( $anon['/desktop-mode/v1/users'] ), 'anon: another plugin\'s /users route is not collateral damage' );

// ---- 3. Anonymous: protected namespaces survive ---------------------------
foreach ( array( '/sn-prov/v1', '/sn-prov/v1/status', '/signal-noise/v1', '/signal-noise/v1/mcp' ) as $kept ) {
	rh_check( isset( $anon[ $kept ] ), "anon: $kept survives (verifier / MCP)" );
}

// ---- 4. Logged-in: route table untouched ----------------------------------
$GLOBALS['__test_logged_in'] = true;
$auth = snt_rest_hardening_endpoints( $routes );
rh_check( count( $auth ) === count( $routes ), 'logged-in: route table untouched (editor/site-editor/media)' );
rh_check( isset( $auth['/batch/v1'] ) && isset( $auth['/wp/v2/users'] ), 'logged-in: batch + users still routable' );

// ---- 5. Protected veto beats a hostile filter ------------------------------
$GLOBALS['__test_logged_in'] = false;
$GLOBALS['__test_filters']['snt_rest_hardening_policy'] = array(
	'remove'    => array( '/sn-prov/v1', '/signal-noise/v1', '/wp/v2/posts' ),
	'strip'     => array( 'post' ),
	'protected' => array( 'sn-prov/v1', 'signal-noise/v1' ),
	'headers'   => array(),
);
$vetoed = snt_rest_hardening_endpoints( $routes );
rh_check( isset( $vetoed['/sn-prov/v1/status'] ), 'veto: filter cannot remove sn-prov/v1' );
rh_check( isset( $vetoed['/signal-noise/v1/mcp'] ), 'veto: filter cannot remove signal-noise/v1' );
rh_check( ! isset( $vetoed['/wp/v2/posts'] ), 'veto: a non-protected route added by filter IS removed (filter is live)' );
unset( $GLOBALS['__test_filters']['snt_rest_hardening_policy'] );

// ---- 6. Rendered-field stripping ------------------------------------------
/**
 * Build a post-shaped response fixture.
 *
 * @return RH_Response
 */
function rh_post_response() {
	$r       = new RH_Response();
	$r->data = array(
		'id'      => 42,
		'slug'    => 'a-note',
		'title'   => array( 'rendered' => 'A Note' ),
		'content' => array( 'rendered' => '<p>Full body text.</p>', 'protected' => false ),
		'excerpt' => array( 'rendered' => '<p>Teaser.</p>', 'protected' => false ),
	);
	return $r;
}

$GLOBALS['__test_logged_in'] = false;
$stripped = snt_rest_hardening_strip_rendered( rh_post_response() );
rh_check( '' === $stripped->data['content']['rendered'], 'anon: content.rendered emptied' );
rh_check( '' === $stripped->data['excerpt']['rendered'], 'anon: excerpt.rendered emptied' );
rh_check( array_key_exists( 'rendered', $stripped->data['content'] ), 'anon: content.rendered KEY retained (well-formed response)' );
rh_check( false === $stripped->data['content']['protected'], 'anon: content.protected preserved' );
rh_check( 42 === $stripped->data['id'] && 'a-note' === $stripped->data['slug'], 'anon: metadata (id, slug) intact' );
rh_check( 'A Note' === $stripped->data['title']['rendered'], 'anon: title.rendered NOT stripped (metadata, not payload)' );

$GLOBALS['__test_logged_in'] = true;
$kept = snt_rest_hardening_strip_rendered( rh_post_response() );
rh_check( '<p>Full body text.</p>' === $kept->data['content']['rendered'], 'logged-in: content.rendered preserved' );
rh_check( '<p>Teaser.</p>' === $kept->data['excerpt']['rendered'], 'logged-in: excerpt.rendered preserved' );

// Sparse response (?_fields=id) must not warn or fatal.
$GLOBALS['__test_logged_in'] = false;
$sparse       = new RH_Response();
$sparse->data = array( 'id' => 7 );
$sparse       = snt_rest_hardening_strip_rendered( $sparse );
rh_check( array( 'id' => 7 ) === $sparse->data, 'anon: sparse response (?_fields=id) passes through untouched' );
rh_check( 'scalar' === snt_rest_hardening_strip_rendered( 'scalar' ), 'non-object response passes through' );

// ---- 7. TDM headers --------------------------------------------------------
$resp = snt_rest_hardening_tdm_headers( new RH_Response() );
$map  = array();
foreach ( $resp->headers as $h ) {
	$map[ $h[0] ] = $h;
}
rh_check( count( $resp->headers ) === 3, 'tdm: exactly three headers emitted' );
rh_check( isset( $map['TDM-Reservation'] ) && '1' === $map['TDM-Reservation'][1], 'tdm: TDM-Reservation === "1"' );
rh_check(
	isset( $map['TDM-Policy'] ) && 'https://juanlentino.com/tdm-policy/' === $map['TDM-Policy'][1],
	'tdm: TDM-Policy === https://juanlentino.com/tdm-policy/'
);
rh_check(
	isset( $map['Content-Signal'] ) && 'search=yes, ai-train=no, ai-input=yes' === $map['Content-Signal'][1],
	'tdm: Content-Signal === search=yes, ai-train=no, ai-input=yes'
);
rh_check( true === $map['TDM-Reservation'][2], 'tdm: replace=true (no duplicate header on re-emit)' );

$GLOBALS['__test_logged_in'] = true;
$authResp = snt_rest_hardening_tdm_headers( new RH_Response() );
rh_check( count( $authResp->headers ) === 3, 'tdm: headers emitted for logged-in callers too (every REST response)' );
rh_check( 'scalar' === snt_rest_hardening_tdm_headers( 'scalar' ), 'tdm: non-object result passes through' );

// ---- 8. Registration guards ------------------------------------------------
$hooked = array();
foreach ( $GLOBALS['__test_added_filter'] as $f ) {
	$hooked[ $f[0] ] = $f[1];
}
foreach ( $GLOBALS['__test_added_action'] as $a ) {
	$hooked[ $a[0] ] = $a[1];
}
rh_check( isset( $hooked['rest_endpoints'] ) && 'snt_rest_hardening_endpoints' === $hooked['rest_endpoints'], 'registered: rest_endpoints' );
rh_check( isset( $hooked['rest_post_dispatch'] ) && 'snt_rest_hardening_tdm_headers' === $hooked['rest_post_dispatch'], 'registered: rest_post_dispatch' );
rh_check( isset( $hooked['rest_api_init'] ) && 'snt_rest_hardening_bind_strip' === $hooked['rest_api_init'], 'registered: rest_api_init binds the strip filters' );

// The strip filters must NOT be bound at require time — binding early would
// read a policy no other plugin has had a chance to filter.
$early = array();
foreach ( $GLOBALS['__test_added_filter'] as $f ) {
	$early[] = $f[0];
}
rh_check( ! in_array( 'rest_prepare_post', $early, true ), 'deferred: rest_prepare_post NOT bound at require time' );

// Running the binder must then bind one filter per policy post type.
$GLOBALS['__test_added_filter'] = array();
snt_rest_hardening_bind_strip();
$bound = array();
foreach ( $GLOBALS['__test_added_filter'] as $f ) {
	$bound[ $f[0] ] = $f[1];
}
rh_check( isset( $bound['rest_prepare_post'] ) && 'snt_rest_hardening_strip_rendered' === $bound['rest_prepare_post'], 'bind: rest_prepare_post' );
rh_check( isset( $bound['rest_prepare_page'] ) && 'snt_rest_hardening_strip_rendered' === $bound['rest_prepare_page'], 'bind: rest_prepare_page' );

// DEAD-CODE GUARD: every assert above passes even if the plugin never loads
// the module. This is the only test that catches that.
$boot = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
rh_check( false !== strpos( $boot, "inc/rest-hardening.php" ), 'bootstrap: signal-and-noise-tools.php require_once\'s inc/rest-hardening.php' );

// The bootstrap must stay logic-free: the require line is all it may contribute.
rh_check( false === strpos( $boot, 'snt_rest_hardening_' ), 'bootstrap: no hardening logic in the bootstrap itself' );

// Namespaces the plugin owns must never appear in the default remove list.
$default = snt_rest_hardening_policy();
rh_check(
	! in_array( '/sn-prov/v1', $default['remove'], true ) && ! in_array( '/signal-noise/v1', $default['remove'], true ),
	'policy: owned namespaces absent from the default remove list'
);
rh_check( array( 'post', 'page' ) === $default['strip'], 'policy: strip covers exactly post + page' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
