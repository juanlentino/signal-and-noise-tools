<?php
/**
 * MIO in this plugin's windows (14.8.0): the PHP half and the two JS halves'
 * contracts. Run: php tests/openstation-mio.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SNT_VERSION', '14.8.0-test' );

$GLOBALS['__prefs'] = array( 'dashboard' => true, 'analytics' => true, 'mio_tips' => true, 'mio_help' => true, 'mio_look' => false );
function snt_os_native_window_preferences( $u = 0 ) { return $GLOBALS['__prefs']; }
function __( $s, $d = null ) { return $s; }
$GLOBALS['__filters'] = array(); $GLOBALS['__actions'] = array(); $GLOBALS['__localized'] = array();
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ] = $cb; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ] = array( $cb, $p ); }
function wp_script_is( $h, $s = 'enqueued' ) { return 'snt-os-settings-tab' === $h; }
function wp_localize_script( $h, $name, $data ) { $GLOBALS['__localized'][ $name ] = $data; return true; }

require SNT_PATH . 'inc/openstation-mio.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "openstation-mio -- the companion in this plugin's windows (14.8.0)\n";

// ── Documents
$docs = snt_os_mio_documents();
$ids  = array_map( static function ( $d ) { return $d['id']; }, $docs );
ok( array( 'index.md', 'attention.md', 'readers.md', 'stamps.md', 'windows.md' ) === $ids, 'five help documents, index first, ids are the relative paths the links use' );
foreach ( $docs as $d ) {
	ok( '' !== $d['title'] && $d['title'] !== $d['id'] && strlen( $d['markdown'] ) > 200 && SNT_VERSION === $d['version'], "{$d['id']}: an H1 title, a body, the plugin version as the revision" );
	ok( false === strpos( $d['markdown'], "\xE2\x80\x94" ), "{$d['id']}: no em dash" );
	preg_match_all( '/\]\(([^)]+)\)/', $d['markdown'], $links );
	foreach ( $links[1] as $target ) {
		ok( in_array( $target, $ids, true ), "{$d['id']}: link {$target} resolves inside the collection (MIO excludes external and missing links)" );
	}
}
$all = implode( "\n", array_map( static function ( $d ) { return $d['markdown']; }, $docs ) );
foreach ( array( 'Integrity', 'Anchors', 'Edge', 'Citations', 'Scheduled', 'Pending review', 'Health', 'Watches', 'Machine readers', 'Search' ) as $kind ) {
	ok( false !== strpos( $all, $kind ), "the help names the $kind reader (ten readers, the queue's vocabulary)" );
}
ok( false !== strpos( $all, 'Acknowledge hides an item until its stamp changes' ), 'the help says what Acknowledge does and does not do' );

// ── Prompt
$p = snt_os_mio_prompt_base( 'app' );
ok( false !== strpos( $p, 'Signal & Noise app' ) && false !== strpos( $p, 'this window only' ), 'the app prompt names the window and scopes to it' );
ok( false !== strpos( $p, 'cannot run a sweep' ) && false !== strpos( $p, 'cannot acknowledge' ), 'the prompt says what MIO cannot do here: no sweeps, no writes' );
ok( false !== strpos( $p, 'say when a reading is from' ), 'the prompt carries the house rule: readings have times, never "true now"' );
ok( false !== strpos( snt_os_mio_prompt_base( 'analytics' ), 'S&N Analytics' ) && false !== strpos( snt_os_mio_prompt_base( 'nope' ), 'Signal & Noise app' ), 'each window has its own name; an unknown window falls back to the app' );
ok( strlen( $p ) < 2000, 'the prompt base is well under the shell\'s 16,000-byte prompt budget' );

// ── The bag
$bag = snt_os_mio_client_bag();
ok( true === $bag['tips'] && true === $bag['help'] && 5 === count( $bag['documents'] ) && array( 'app', 'dashboard', 'analytics' ) === array_keys( $bag['prompts'] ), 'with help on, the bag carries tips, help, the documents and the three prompt bases' );
$GLOBALS['__prefs']['mio_help'] = false;
$bag = snt_os_mio_client_bag();
ok( false === $bag['help'] && array() === $bag['documents'] && array() === $bag['prompts'] && true === $bag['tips'], 'with help off, no documents and no prompts leave the server; tips stay on their own switch' );
$GLOBALS['__prefs']['mio_help'] = true;
ok( isset( $GLOBALS['__actions']['admin_enqueue_scripts'] ) && 6 === $GLOBALS['__actions']['admin_enqueue_scripts'][1], 'the bag is localized on admin_enqueue_scripts at priority 6, after the settings script registers at 5' );
snt_os_mio_localize();
ok( isset( $GLOBALS['__localized']['sntMio'] ) && true === $GLOBALS['__localized']['sntMio']['tips'], 'localized as window.sntMio on the settings-tab handle' );

// ── The look
$cfg = array( 'appearance' => array( 'radius' => 56, 'hueStart' => 296.5 ), 'physics' => array( 'magnetStrength' => 2400 ) );
ok( $cfg === snt_os_mio_config( $cfg ), 'mio_look off: the shell\'s config passes through untouched' );
$GLOBALS['__prefs']['mio_look'] = true;
$out = snt_os_mio_config( $cfg );
ok( '#000000' === $out['appearance']['bodyColor'] && 0 === $out['appearance']['hueStart'] && 12 === $out['appearance']['hueSpan'] && 0.96 === $out['appearance']['saturation'], 'mio_look on: bone body, the ring from blood (hue 0) toward signal (hue 12), blood\'s saturation' );
ok( 56 === $out['appearance']['radius'] && 2400 === $out['physics']['magnetStrength'], 'only colour keys change: radius and physics stay the shell\'s' );
ok( isset( $GLOBALS['__filters']['openstation_mio_config'] ) && 'snt_os_mio_config' === $GLOBALS['__filters']['openstation_mio_config'], 'hooked on openstation_mio_config' );
$GLOBALS['__prefs']['mio_look'] = false;

// ── The JS halves, pinned on source with comments stripped
$strip = static function ( $src ) { $src = preg_replace( '~/\*.*?\*/~s', '', $src ); return preg_replace( '~^\s*//.*$~m', '', (string) $src ); };
$client = $strip( (string) file_get_contents( SNT_PATH . 'apps/signal-noise/signal-noise-client.js' ) );
ok( false !== strpos( $client, 'registerWindow( ctx.windowId, {' ) && false !== strpos( $client, 'host: ctx.root' ), 'the app registers its INSTANCE id (ctx.windowId) with its root as host' );
ok( false !== strpos( $client, 'teardowns.push( mioArm( ctx ) )' ) && false !== strpos( $client, 'lease.dispose()' ), 'the lease is armed in mounted and disposed in the teardown' );
ok( preg_match_all( "/^\t\t\teffect: 'read',$/m", $client ) === 2 && false === strpos( $client, "effect: 'write'" ) && 2 === substr_count( $client, "return { effect: 'read'," ), 'exactly two abilities declared read-effect, both returning read results; nothing writes' );
ok( false !== strpos( $client, "name: 'list_items'" ) && false !== strpos( $client, "name: 'read_item'" ), 'the tools are list_items and read_item' );
ok( false !== strpos( $client, 'bag.help ? mioAbilities( ctx ) : []' ) && false !== strpos( $client, 'bag.help ? ( bag.documents || [] ) : []' ), 'documents and tools are offered only when mio_help is on' );
ok( false !== strpos( $client, "if ( ! lease || ! mioBag().tips )" ), 'callouts only when mio_tips is on' );
ok( false !== strpos( $client, "case 'integrity':" ) && false !== strpos( $client, "case 'watches':" ) && false !== strpos( $client, "case 'scheduled':" ) && false !== strpos( $client, "case 'search':" ), 'a tip per kind that had a state a reader could not tell apart today' );
ok( false !== strpos( $client, "id: 'item:' + String( item.id )" ), 'callouts are keyed per item, so a dismissal holds for that item only' );
ok( false !== strpos( $client, 'mioSync( ctx );' ) && substr_count( $client, 'mioSync( ctx );' ) >= 2, 'the tip is re-synced on every updated() paint and once at arm time' );
$host = $strip( (string) file_get_contents( SNT_PATH . 'assets/os-host.js' ) );
ok( false !== strpos( $host, "id.indexOf( 'wp-window-' ) === 0" ), 'the host derives the window INSTANCE id from the shell\'s wp-window-<id> element' );
ok( false !== strpos( $host, 'mioSync( root );' ) && false !== strpos( $host, 'os-table[empty*="failing"]' ), 'the host syncs on every paint and targets the empty Commits table that quotes failures' );
ok( false !== strpos( $host, 'abilities: function () { return []; }' ), 'the host windows offer no tools: their writes are forms, not model calls' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
