<?php
/**
 * Standalone test: the Signal & Noise app's control surface -- the four
 * server actions and their two helpers (#1065, phase two).
 *
 * The framework is stubbed in its own namespaces, the same shapes
 * tests/openstation-app.php uses, with two additions the handlers need:
 * `Os::toast()` and `Os::announce()` record what an action SAID and what it
 * told the desktop, and `Os::can()` reads a per-capability map so a test can
 * refuse `delete_post` while allowing `edit_post`. WordPress is stubbed flat;
 * the `sn_`-prefixed halves (Cloudflare, provenance) are stubbed as recorders,
 * because what matters here is WHICH call an action makes, in what order, and
 * -- for the probe log -- which one it never makes.
 * Run: php tests/openstation-app-actions.php
 *
 * @since 13.101.0
 */

namespace OpenStation {
	final class App {
		public $id; public $title; public $icon; public $placement; public $caps = array(); public $state = array();
		public $actions = array(); public $view; public $data; public $client; public $tabs = array(); public $buttons = array(); public $watch = array();
		public $config = array();
		public static function define( $id ) { $a = new self(); $a->id = $id; return $a; }
		public function title( $t ) { $this->title = $t; return $this; }
		public function icon( $i ) { $this->icon = $i; return $this; }
		public function size( $w, $h ) { return $this; }
		public function min_size( $w, $h ) { return $this; }
		public function placement( $p ) { $this->placement = $p; return $this; }
		public function capabilities( ...$c ) { $this->caps = $c; return $this; }
		public function watch( ...$t ) { $this->watch = $t; return $this; }
		public function state( array $d ) { $this->state = $d; return $this; }
		public function title_bar_button( $id, array $a ) { $this->buttons[ $id ] = $a; return $this; }
		public function action( $n, callable $cb ) { $this->actions[ $n ] = $cb; return $this; }
		public function view( callable $cb ) { $this->view = $cb; return $this; }
		public function data( callable $cb ) { $this->data = $cb; return $this; }
		public function client( $p ) { $this->client = $p; return $this; }
		public function tab( $v, array $a ) { $this->tabs[ $v ] = $a; return $this; }
		public function config( array $c ) { $this->config = $c; return $this; }
	}
}

namespace OpenStation\App {
	class State {
		private $d; private $defaults;
		public function __construct( array $defaults = array(), array $in = array() ) { $this->defaults = $defaults; $this->d = array_merge( $defaults, $in ); }
		public function get( $k, $f = null ) { return array_key_exists( $k, $this->d ) ? $this->d[ $k ] : $f; }
		public function set( $k, $v ) { $this->d[ $k ] = $v; return $this; }
		public function reset( $k ) { $this->d[ $k ] = array_key_exists( $k, $this->defaults ) ? $this->defaults[ $k ] : null; return $this; }
		public function all() { return $this->d; }
	}
	class Os {
		public $opened = array(); public $toasts = array(); public $announced = array();
		public function open_url( $u, $t = '', $i = '' ) { $this->opened[] = array( $u, $t, $i ); return $this; }
		public function toast( $m ) { $this->toasts[] = (string) $m; return $this; }
		public function announce( $type, $action, $ids ) { $this->announced[] = array( (string) $type, (string) $action, array_values( array_map( 'intval', (array) $ids ) ) ); return $this; }
		// Keyed on capability AND object first, then capability alone, so a
		// per-note refusal (delete_post on 12, not on 11) can be expressed.
		public function can( $c, ...$a ) { $GLOBALS['__can_calls'][] = array( $c, $a ); return (bool) ( $GLOBALS['__os_can'][ $c . ':' . (string) ( $a[0] ?? '' ) ] ?? $GLOBALS['__os_can'][ $c ] ?? true ); }
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
	define( 'ABSPATH', '/' );
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
	define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
	// The three the purge path reads. The log option exists here only so the
	// test can prove nothing ever writes it.
	// The probe's names come from the SOURCE, so a rename there cannot leave
	// these pins anchored to a name the plugin no longer has.
	$probe_src = (string) file_get_contents( __DIR__ . '/../inc/cloudflare-purge-verify.php' );
	foreach ( array( 'SN_CF_PROBE_HOOK' => "/const SN_CF_PROBE_HOOK\s*=\s*'([^']+)'/", 'SN_CF_PROBE_LOG_OPT' => "/const SN_CF_PROBE_LOG_OPT\s*=\s*'([^']+)'/", 'SN_CF_PROBE_DELAY' => '/const SN_CF_PROBE_DELAY\s*=\s*(\d+)/' ) as $name => $re ) {
		if ( ! preg_match( $re, $probe_src, $pm ) ) { echo "FAIL: $name not found in inc/cloudflare-purge-verify.php\n"; exit( 1 ); }
		define( $name, is_numeric( $pm[1] ) ? (int) $pm[1] : $pm[1] );
	}

	// ── WordPress, flat ──────────────────────────────────────────────
	$GLOBALS['__filters'] = array();
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
	function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
	function __( $s, $d = null ) { return $s; }
	function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
	function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
	function home_url( $p = '' ) { return 'https://example.test' . $p; }
	function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
	$GLOBALS['__caps'] = array( 'edit_posts' => true, 'manage_options' => true );
	function current_user_can( $cap, ...$a ) { return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
	function get_post( $id ) { foreach ( $GLOBALS['__posts'] as $p ) { if ( (int) $p->ID === (int) $id ) { return $p; } } return null; }
	function get_permalink( $post ) { return 'https://example.test/?p=' . ( is_object( $post ) ? $post->ID : (int) $post ); }

	$GLOBALS['__trashed'] = array(); $GLOBALS['__untrashable'] = array();
	function wp_trash_post( $post_id = 0 ) {
		$GLOBALS['__trashed'][] = (int) $post_id;
		return in_array( (int) $post_id, $GLOBALS['__untrashable'], true ) ? false : (object) array( 'ID' => (int) $post_id );
	}
	$GLOBALS['__updated'] = array(); $GLOBALS['__update_fails'] = false;
	function get_post_status( $id ) { $p = get_post( $id ); return $p ? (string) $p->post_status : false; }
	function wp_update_post( $postarr = array(), $wp_error = false, $fire_after_hooks = true ) {
		$GLOBALS['__updated'][] = (array) $postarr;
		// The row is written; the status lands unless the fixture says the
		// write left it where it was (what core does to a dated note).
		if ( empty( $GLOBALS['__update_fails'] ) && empty( $GLOBALS['__status_stays'] ) && isset( $postarr['post_status'] ) ) {
			$p = get_post( (int) $postarr['ID'] );
			if ( $p ) { $p->post_status = (string) $postarr['post_status']; }
		}
		return $GLOBALS['__update_fails'] ? 0 : (int) ( ( (array) $postarr )['ID'] ?? 0 );
	}
	$GLOBALS['__scheduled'] = array(); $GLOBALS['__unscheduled'] = array(); $GLOBALS['__next'] = array();
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['__next'][ (int) ( $args[0] ?? 0 ) ] ?? false; }
	function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) { $GLOBALS['__unscheduled'][] = array( (int) $timestamp, (string) $hook, (array) $args ); return true; }
	function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) { $GLOBALS['__scheduled'][] = array( (int) $timestamp, (string) $hook, (array) $args ); return true; }
	$GLOBALS['__options'] = array();
	function update_option( $option, $value, $autoload = null ) { $GLOBALS['__options'][] = (string) $option; return true; }
	function get_option( $option, $default_value = false ) { return $default_value; }

	// The plugin's own halves, as recorders. `sn_`-prefixed, so exempt from
	// the stub-parity sweep -- they are ours, not core's.
	$GLOBALS['__cf_configured'] = true; $GLOBALS['__purged'] = array();
	function sn_cf_is_configured() { return (bool) $GLOBALS['__cf_configured']; }
	function sn_cf_post_purge_urls( $post_id, $post ) { return $GLOBALS['__purge_urls'][ (int) $post_id ] ?? array(); }
	function sn_cf_purge_urls( $urls ) { if ( ! empty( $GLOBALS['__purge_refuses'] ) ) { return false; } $GLOBALS['__purged'][] = array_values( (array) $urls ); return true; }
	function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ (int) $id ] ?? array(); }
	$GLOBALS['__reconciled'] = array(); $GLOBALS['__reconcile_fails'] = false;
	function sn_prov_reconcile_post( $post_id ) { $GLOBALS['__reconciled'][] = (int) $post_id; return $GLOBALS['__reconcile_fails'] ? false : null; }
	$GLOBALS['__worker'] = 'https://example.com/anchor'; $GLOBALS['__secret'] = 's4ndb0x';
	function sn_prov_worker_url() { return (string) $GLOBALS['__worker']; }
	function sn_prov_hmac_secret() { return (string) $GLOBALS['__secret']; }
	function sn_prov_is_note( $id ) { return true; }
	function sn_discography_get() { return array( 'entries' => array() ); }

	function post( $id, $title, $status = 'publish' ) { return (object) array( 'ID' => $id, 'post_title' => $title, 'post_status' => $status, 'post_date' => '2026-08-14 10:00:00', 'post_type' => 'post', 'thumb' => '' ); }
	$GLOBALS['__posts'] = array(
		post( 21, 'The ban failed', 'future' ),
		post( 11, 'The signer keeps moving', 'publish' ),
		post( 12, 'A draft', 'draft' ),
		post( 22, 'A pending note', 'pending' ),
		(object) array( 'ID' => 44, 'post_title' => 'A page', 'post_status' => 'publish', 'post_date' => '2026-08-14 10:00:00', 'post_type' => 'page', 'thumb' => '' ),
	);
	$GLOBALS['__purge_urls'] = array(
		11 => array( 'https://example.test/notes/a/', 'https://example.test/', 'https://example.test/notes/' ),
		12 => array( 'https://example.test/notes/b/', 'https://example.test/', 'https://example.test/notes/' ),
	);
	$GLOBALS['__chains'] = array();

	require_once __DIR__ . '/../inc/openstation-app.php';
	$app = require __DIR__ . '/../apps/signal-noise/signal-noise.os.php';

	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
	/** A fresh state on the app's declared schema. */
	function st( $app, array $in = array() ) { return new \OpenStation\App\State( $app->state, $in ); }
	/** Every capability the run asked the runtime for, cleared. */
	function asked() { $GLOBALS['__can_calls'] = array(); }

	echo "openstation-app-actions -- the control surface (#1065)\n\nGroup 1: targets() re-derives the Explorer's selection rule from the SERVER's state\n";
	$one = st( $app, array( 'selected' => array( '11' ) ) );
	ok( array( 11 ) === \SignalNoise\OpenStationApp\targets( $one, array( 'item' => '11', 'selection' => true ) ), 'a one-member selection acts on the clicked note alone' );
	$stale = st( $app, array( 'selected' => array( '12', '21' ) ) );
	ok( array( 11 ) === \SignalNoise\OpenStationApp\targets( $stale, array( 'item' => '11', 'selection' => true ) ), 'a forged selection: true buys nothing: the server re-checks that the CLICKED note is in the selection it holds, and this one is not' );
	$many = st( $app, array( 'selected' => array( '11', '12', '21' ) ) );
	ok( array( 11, 12, 21 ) === \SignalNoise\OpenStationApp\targets( $many, array( 'item' => '11', 'selection' => true ) ), 'the clicked note inside a multi-selection widens to the whole selection' );
	ok( array( 11 ) === \SignalNoise\OpenStationApp\targets( $many, array( 'item' => '11' ) ), 'without the flag a pick is the clicked note, whatever is selected' );
	ok( array( 33 ) === \SignalNoise\OpenStationApp\targets( $many, array( 'item' => '33', 'selection' => true ) ), 'a clicked note OUTSIDE the selection never drags the selection along' );
	$dirty = st( $app, array( 'selected' => array( '11', '0', '-4', '12', '11' ) ) );
	ok( array( 11, 12 ) === \SignalNoise\OpenStationApp\targets( $dirty, array( 'item' => '11', 'selection' => true ) ), 'ids at or below zero are dropped and the list is unique -- state is client-written' );
	ok( array() === \SignalNoise\OpenStationApp\targets( $many, array() ), 'no clicked item and no flag: nothing to act on' );

	echo "\nGroup 2: trash\n";
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'item' => '12', 'selected' => array( '11', '12', '21' ) ) );
	asked();
	$app->actions['trash']( $state, $os, array( 'item' => '11', 'selection' => true ) );
	ok( array( 11, 12, 21 ) === $GLOBALS['__trashed'], 'every selected note is trashed, note by note' );
	ok( 3 === count( array_filter( $GLOBALS['__can_calls'], static function ( $c ) { return 'delete_post' === $c[0]; } ) ) && in_array( array( 'delete_post', array( 12 ) ), $GLOBALS['__can_calls'], true ), 'delete_post was asked once per note, with THAT note\'s id' );
	ok( array() === $state->get( 'selected' ), 'every trashed note left the selection: those rows are gone' );
	ok( '' === $state->get( 'item' ) && array() === $state->get( 'verdict' ), 'the open dossier closes when its own note was trashed, and its verdict goes with it' );
	ok( array( 'Moved 3 items to the Trash.' ) === $os->toasts, 'the plural toast counts what was actually trashed' );
	ok( array( array( 'post', 'trashed', array( 11, 12, 21 ) ) ) === $os->announced, 'the desktop is told which post ids changed, so every other window repaints' );

	$GLOBALS['__trashed'] = array();
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'item' => '11', 'verdict' => array( 'post_id' => 11 ) ) );
	$app->actions['trash']( $state, $os, array( 'item' => '12' ) );
	ok( array( 12 ) === $GLOBALS['__trashed'] && array( 'Moved to the Trash.' ) === $os->toasts, 'one note: the singular toast, and no selection needed' );
	ok( '11' === $state->get( 'item' ) && array( 'post_id' => 11 ) === $state->get( 'verdict' ), 'a dossier showing ANOTHER note stays open with its verdict' );

	$GLOBALS['__trashed'] = array();
	$GLOBALS['__os_can'] = array( 'delete_post' => false );
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes' ) );
	$app->actions['trash']( $state, $os, array( 'item' => '11' ) );
	ok( array() === $GLOBALS['__trashed'] && array( 'You cannot trash this item.' ) === $os->toasts && array() === $os->announced, 'without delete_post nothing is trashed, nothing is announced, and the toast says you may not (the Explorer\'s single-note refusal)' );
	$GLOBALS['__os_can'] = array();

	$GLOBALS['__trashed'] = array();
	$GLOBALS['__untrashable'] = array( 11 );
	$os = new \OpenStation\App\Os();
	$app->actions['trash']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array( 11 ) === $GLOBALS['__trashed'] && array( 'Trashing failed.' ) === $os->toasts, 'a trash WordPress refused is not counted as done, and the toast names the failure, not a refusal' );
	$GLOBALS['__untrashable'] = array();

	$GLOBALS['__trashed'] = array();
	$os = new \OpenStation\App\Os();
	$app->actions['trash']( st( $app ), $os, array( 'item' => '44' ) );
	ok( array() === $GLOBALS['__trashed'] && array( 'You cannot trash this item.' ) === $os->toasts, 'an id that is not a `post` is not a Note, whatever the client sent' );

	// A mixed selection: delete_post on 11 and 21, refused on 12. The refused
	// note stays selected; the trashed two leave; the toast counts two.
	$GLOBALS['__trashed'] = array();
	$GLOBALS['__os_can'] = array( 'delete_post:12' => false );
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'selected' => array( '11', '12', '21' ) ) );
	$app->actions['trash']( $state, $os, array( 'item' => '11', 'selection' => true ) );
	ok( array( 11, 21 ) === $GLOBALS['__trashed'] && array( '12' ) === $state->get( 'selected' ) && array( 'Moved 2 items to the Trash.' ) === $os->toasts, 'a per-note refusal inside a selection: the two allowed are trashed and leave the selection, the refused one stays selected, the toast counts two' );
	$GLOBALS['__os_can'] = array();
	$GLOBALS['__trashed'] = array();
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'selected' => array( '12', '21' ) ) );
	$app->actions['trash']( $state, $os, array( 'item' => '11' ) );
	ok( array( 11 ) === $GLOBALS['__trashed'] && array( '12', '21' ) === $state->get( 'selected' ), 'trashing a note OUTSIDE the selection leaves the selection alone -- never a reset of what the action did not touch' );

	echo "\nGroup 3: publish -- one note, never the selection\n";
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'item' => '12', 'verdict' => array( 'post_id' => 12 ), 'selected' => array( '12', '21' ) ) );
	asked();
	$app->actions['publish']( $state, $os, array( 'item' => '12', 'selection' => true ) );
	ok( array( array( 'ID' => 12, 'post_status' => 'publish' ) ) === $GLOBALS['__updated'], 'the draft is published through wp_update_post, so the plugin\'s own publish hooks fire (signing, purge, schedule sync)' );
	ok( array( 'Published.' ) === $os->toasts && array( array( 'post', 'updated', array( 12 ) ) ) === $os->announced, 'it says Published. and announces the one id -- the selection flag is ignored' );
	ok( array() === $state->get( 'verdict' ), 'the verdict is cleared: the chain just gained a version' );
	ok( in_array( array( 'publish_posts', array() ), $GLOBALS['__can_calls'], true ) && in_array( array( 'edit_post', array( 12 ) ), $GLOBALS['__can_calls'], true ), 'both gates were asked: publish_posts app-wide and edit_post on THAT note' );

	$GLOBALS['__updated'] = array();
	$os = new \OpenStation\App\Os();
	$app->actions['publish']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array() === $GLOBALS['__updated'] && array( 'Nothing could be published.' ) === $os->toasts, 'an already published note is refused' );

	$GLOBALS['__os_can'] = array( 'publish_posts' => false );
	$os = new \OpenStation\App\Os();
	$app->actions['publish']( st( $app ), $os, array( 'item' => '12' ) );
	ok( array() === $GLOBALS['__updated'] && array( 'Nothing could be published.' ) === $os->toasts, 'a draft without publish_posts is refused' );
	$GLOBALS['__os_can'] = array();
	$GLOBALS['__updated'] = array();
	$os = new \OpenStation\App\Os();
	$app->actions['publish']( st( $app ), $os, array( 'item' => '22' ) );
	ok( array( array( 'ID' => 22, 'post_status' => 'publish' ) ) === $GLOBALS['__updated'] && array( 'Published.' ) === $os->toasts, 'a pending note publishes like a draft' );
	$GLOBALS['__updated'] = array();
	$os = new \OpenStation\App\Os();
	$app->actions['publish']( st( $app ), $os, array( 'item' => '21' ) );
	ok( array() === $GLOBALS['__updated'] && array( 'Nothing could be published.' ) === $os->toasts, 'a SCHEDULED note is refused: core would re-assert future for its date, and "Published." would be false' );
	$GLOBALS['__updated'] = array(); $GLOBALS['__status_stays'] = true;
	foreach ( $GLOBALS['__posts'] as $p ) { if ( 12 === (int) $p->ID ) { $p->post_status = 'draft'; } }
	$os = new \OpenStation\App\Os();
	$app->actions['publish']( st( $app ), $os, array( 'item' => '12' ) );
	ok( 1 === count( $GLOBALS['__updated'] ) && array( 'The note did not publish; its status is still draft.' ) === $os->toasts && array() === $os->announced, 'a write that left the status where it was is read back and said, never inferred from the return code' );
	$GLOBALS['__status_stays'] = false;
	$GLOBALS['__os_can'] = array();

	echo "\nGroup 4: purge -- and the probe log it must never write\n";
	$GLOBALS['__cf_configured'] = false;
	$os = new \OpenStation\App\Os();
	$app->actions['purge']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array() === $GLOBALS['__purged'] && array( 'Cloudflare is not configured.' ) === $os->toasts, 'an unconfigured Cloudflare is said out loud, and nothing is dispatched' );
	$GLOBALS['__cf_configured'] = true;

	$GLOBALS['__os_can'] = array( 'manage_options' => false );
	$os = new \OpenStation\App\Os();
	$app->actions['purge']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array() === $GLOBALS['__purged'] && array( 'Nothing to purge.' ) === $os->toasts, 'without manage_options nothing is purged' );
	$GLOBALS['__os_can'] = array();

	$GLOBALS['__next'] = array( 11 => 1700000000 );
	$now = time();
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'selected' => array( '11', '12' ) ) );
	$app->actions['purge']( $state, $os, array( 'item' => '11', 'selection' => true ) );
	ok( 1 === count( $GLOBALS['__purged'] ), 'one purge call for the whole selection, not one per note' );
	ok( array( 'https://example.test/notes/a/', 'https://example.test/', 'https://example.test/notes/', 'https://example.test/notes/b/' ) === $GLOBALS['__purged'][0], '   ...the URL lists merged and de-duplicated: the front page is purged once, not twice' );
	ok( array( 'Purge dispatched for 4 URLs; the probe checks them in two minutes.' ) === $os->toasts, 'the toast counts URLs, and promises only what the probe will actually do' );
	ok( array( array( 1700000000, SN_CF_PROBE_HOOK, array( 11 ) ) ) === $GLOBALS['__unscheduled'], 'a probe already pending for a note is unscheduled first -- several purges in a minute probe once, after the last' );
	ok( 2 === count( $GLOBALS['__scheduled'] ) && array( 11 ) === $GLOBALS['__scheduled'][0][2] && array( 12 ) === $GLOBALS['__scheduled'][1][2] && SN_CF_PROBE_HOOK === $GLOBALS['__scheduled'][0][1], '   ...and one probe is scheduled per note, with that note\'s id as the single argument' );
	ok( abs( $GLOBALS['__scheduled'][0][0] - ( $now + SN_CF_PROBE_DELAY ) ) <= 1, '   ...at the same delay the save hook uses' );
	ok( ! in_array( SN_CF_PROBE_LOG_OPT, $GLOBALS['__options'], true ), 'the purge NEVER writes the probe log: the verdict is measured later, never a row the button wrote (v13.87.2)' );

	$GLOBALS['__purged'] = array();
	$GLOBALS['__purge_urls'][21] = array();
	$os = new \OpenStation\App\Os();
	$app->actions['purge']( st( $app ), $os, array( 'item' => '21' ) );
	ok( array() === $GLOBALS['__purged'] && array( 'Nothing to purge.' ) === $os->toasts, 'a note with no URLs to purge dispatches nothing' );
	$GLOBALS['__purged'] = array();
	$os = new \OpenStation\App\Os();
	$app->actions['purge']( st( $app ), $os, array( 'item' => '44' ) );
	ok( array() === $GLOBALS['__purged'] && array( 'Nothing to purge.' ) === $os->toasts, 'a page id is not a Note: manage_options alone does not make it purgeable' );
	$GLOBALS['__purged'] = array(); $GLOBALS['__purge_refuses'] = true;
	$os = new \OpenStation\App\Os();
	$app->actions['purge']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array( 'The purge was not dispatched.' ) === $os->toasts, 'a purge the callee refused is said as such, and no probe is promised' );
	$GLOBALS['__purge_refuses'] = false;

	echo "\nGroup 5: anchor retry\n";
	$GLOBALS['__chains'][11] = array(
		array( 'version' => 1, 'status' => 'genesis' ),
		array( 'version' => 2, 'status' => 'confirmed' ),
	);
	$os = new \OpenStation\App\Os();
	$app->actions['anchor']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array() === $GLOBALS['__reconciled'] && array( 'Nothing to dispatch: every version is anchored or pending.' ) === $os->toasts, 'a chain with nothing unanchored dispatches nothing and says why' );

	$GLOBALS['__chains'][11][] = array( 'version' => 3, 'status' => 'unanchored' );
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'verdict' => array( 'post_id' => 11 ) ) );
	$app->actions['anchor']( $state, $os, array( 'item' => '11', 'selection' => true ) );
	ok( array( 11 ) === $GLOBALS['__reconciled'], 'one unanchored commit: reconcile is called exactly once, for the one note (never the selection)' );
	ok( array( 'Re-dispatch requested for v3; the ledger answers when it lands.' ) === $os->toasts && array() === $state->get( 'verdict' ), 'the toast names the version and calls it a REQUEST -- the ledger, not the toast, says whether it landed; the verdict is cleared' );

	$GLOBALS['__reconciled'] = array(); $GLOBALS['__reconcile_fails'] = true;
	$os = new \OpenStation\App\Os();
	$app->actions['anchor']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array( 11 ) === $GLOBALS['__reconciled'] && array( 'The dispatch could not be retried.' ) === $os->toasts, 'a protected post makes reconcile return false, and the toast does not claim a retry' );
	$GLOBALS['__reconcile_fails'] = false;

	$GLOBALS['__reconciled'] = array();
	$GLOBALS['__os_can'] = array( 'manage_options' => false );
	$os = new \OpenStation\App\Os();
	$app->actions['anchor']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array() === $GLOBALS['__reconciled'] && array( 'The dispatch could not be retried.' ) === $os->toasts, 'without manage_options nothing is dispatched' );
	$GLOBALS['__os_can'] = array();

	$GLOBALS['__reconciled'] = array();
	$os = new \OpenStation\App\Os();
	$app->actions['anchor']( st( $app ), $os, array( 'item' => '44' ) );
	ok( array() === $GLOBALS['__reconciled'] && array( 'The dispatch could not be retried.' ) === $os->toasts, 'a page id is not a Note: manage_options alone does not make it dispatchable' );
	$GLOBALS['__reconciled'] = array(); $GLOBALS['__worker'] = '';
	$os = new \OpenStation\App\Os();
	$app->actions['anchor']( st( $app ), $os, array( 'item' => '11' ) );
	ok( array() === $GLOBALS['__reconciled'] && array( 'The anchor worker is not configured here.' ) === $os->toasts, 'without a worker URL nothing is requested and the toast says why -- sn_prov_dispatch() would have bailed silently' );
	$GLOBALS['__worker'] = 'https://example.com/anchor';

	echo "\nGroup 6: go clears the selection with the rest of the facets\n";
	$os = new \OpenStation\App\Os();
	$state = st( $app, array( 'section' => 'notes', 'selected' => array( '11', '12' ) ) );
	$app->actions['go']( $state, $os, array( 'section' => 'discography' ) );
	ok( array() === $state->get( 'selected' ), 'leaving a section drops its selection -- ids from one section must never scope an action in another' );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
