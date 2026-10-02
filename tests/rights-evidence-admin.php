<?php
/**
 * Rights evidence on Monitoring > Machine Readers, both twins (Unreleased):
 * inc/admin-forms/rights-evidence.php (classic),
 * apps/sn-dashboard/parts/leaves/monitoring-machine-readers-evidence.php
 * (native) and inc/admin-post-actions/rights-evidence.php (the two actions).
 *
 * Run: php tests/rights-evidence-admin.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The module's readers, from fixtures (the module itself is pinned in tests/rights-evidence.php).
const SN_RIGHTS_EVIDENCE_HOOK = 'sn_rights_evidence_daily';
$GLOBALS['__re'] = array( 'ready' => true, 'sensor' => array( 'version' => '1.29.0', 'reachable' => true ), 'next' => strtotime( '2026-10-01T21:43:00Z' ), 'dry' => 0 );
function sn_rights_evidence_held( $seed = true ) { return $GLOBALS['__options']['sn_rights_evidence_hold'] ?? array( '2026-09' ); }
function sn_rights_evidence_backlog() { return $GLOBALS['__options']['sn_rights_evidence_backlog'] ?? array(); }
function sn_rights_evidence_is_ready() { return $GLOBALS['__re']['ready']; }
function snt_mr_sensor_info() { return $GLOBALS['__re']['sensor']; }
function wp_next_scheduled( $h ) { return SN_RIGHTS_EVIDENCE_HOOK === $h ? $GLOBALS['__re']['next'] : false; }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
function sn_rights_evidence_dry_run( $ym ) { $GLOBALS['__re']['dry']++; return array( 'ok' => true, 'month' => $ym, 'error' => '', 'payloads' => array() ); }
// The retraction's seams: the stored records, the signed POST (the real one is pinned in tests/rights-evidence.php).
const SN_RIGHTS_EVIDENCE_OPTION = 'sn_rights_evidence';
$GLOBALS['__re']['posts'] = array(); $GLOBALS['__re']['reply'] = array( 'code' => 200, 'body' => array( 'ok' => true, 'path' => 'retractions/u7/v1.json', 'content_hash' => 'c' ) );
function sn_rights_evidence_data() { return $GLOBALS['__options'][ SN_RIGHTS_EVIDENCE_OPTION ] ?? array(); }
$GLOBALS['__re']['worker'] = 'https://prov.example'; $GLOBALS['__re']['secret'] = 's3';
function sn_prov_worker_url() { return $GLOBALS['__re']['worker']; }
function sn_prov_hmac_secret() { return $GLOBALS['__re']['secret']; }
function sn_rights_evidence_signed_post( $url, array $fields ) { $GLOBALS['__re']['posts'][] = array( $url, $fields ); return $GLOBALS['__re']['reply']; }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; } }

// The review window's seams: the backlog writer and one send (the real ones are pinned in tests/rights-evidence.php).
function sn_rights_evidence_backlog_set( array $m ) { $m = array_values( array_unique( $m ) ); sort( $m ); $GLOBALS['__options']['sn_rights_evidence_backlog'] = $m; }
$GLOBALS['__re']['sent'] = array();
function sn_rights_evidence_send( array $entry, $now ) { $GLOBALS['__re']['sent'][] = $entry['uuid']; unset( $entry['canonical'] ); return array( array( 'status' => 'pending', 'ledger_path' => 'rights-evidence/' . $entry['uuid'] . '/v1.json' ) + $entry, 'posted' ); }
require SNT_PATH . 'inc/rights-evidence-review.php';
require SNT_PATH . 'inc/rights-evidence-post-now.php';
require SNT_PATH . 'inc/admin-post-actions/rights-evidence-post-now.php';
require SNT_PATH . 'inc/rights-evidence-retractions.php';
require SNT_PATH . 'inc/rights-evidence-retract.php';
require SNT_PATH . 'inc/admin-post-actions/rights-evidence-retract.php';
require SNT_PATH . 'inc/admin-post-actions/rights-evidence.php';
require SNT_PATH . 'inc/admin-forms/rights-evidence.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-machine-readers-evidence.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
$native  = static fn() => html_entity_decode( \SignalNoise\OpenStationHost\Dashboard\Leaves\machine_readers_rights_evidence_html(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
$classic = static function () { ob_start(); sn_admin_render_rights_evidence(); return html_entity_decode( (string) ob_get_clean(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); };

// A: the actions are registered with the dispatcher (nonce + capability are its job).
$handler_src = (string) file_get_contents( SNT_PATH . 'inc/admin-post-handler.php' );
ok( str_contains( $handler_src, "'rights_evidence_view'       => 'sn_handle_rights_evidence_view'" ) && str_contains( $handler_src, "'rights_evidence_lift'       => 'sn_handle_rights_evidence_lift'" ), 'A1 both actions are in sn_admin_post_handlers()' );
ok( str_contains( (string) file_get_contents( SNT_PATH . 'inc/admin-post-actions.php' ), "admin-post-actions/rights-evidence.php" ), 'A2 the handler file is loaded' );

// B: both twins paint the same controls.
$GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ), 'sn_rights_evidence_backlog' => array( '2026-08' ) );
$c = $classic(); $n = $native();
foreach ( array( 'classic' => $c, 'native' => $n ) as $twin => $h ) {
	ok( str_contains( $h, 'Held: 2026-09. In review: none. Backlog: 2026-08. Next pass: 2026-10-01 21:43 UTC.' ), "B1 $twin: the status line names held months, backlog and the next pass" );
	ok( str_contains( $h, 'View September 2026 payloads' ) && str_contains( $h, 'action=sn_rights_evidence_view' ) && str_contains( $h, 'month=2026-09' ) && str_contains( $h, '_wpnonce=nonce-sn_rights_evidence_view' ) && str_contains( $h, 'page=sn-monitoring' ), "B2 $twin: a View door per held month, admin-post GET with its own nonce" );
	ok( str_contains( $h, 'Lift September 2026 hold' ) && str_contains( $h, 'sn_rights_evidence_lift' ) && str_contains( $h, 'append-only ledger' ), "B3 $twin: a Lift button behind a confirm that says what it publishes" );
}
ok( array( 'month', 'sub', 'tab' ) === array_values( array_diff( snt_leaf_names( $n ), array( 'action', '_wpnonce' ) ) ) && snt_leaf_names( $c ) === snt_leaf_names( $n ), 'B4 the native form posts the same names as the classic: ' . implode( ',', snt_leaf_names( $n ) ) );
ok( 0 === $GLOBALS['__re']['dry'], 'B5 painting never composes (no dry run, no sensor reads per family)' );
$GLOBALS['__re']['sensor'] = null;
ok( ! str_contains( $classic(), 'Lift September' ) && ! str_contains( $native(), 'Lift September' ) && str_contains( $native(), 'Lift appears once' ) && str_contains( $classic(), 'View September 2026 payloads' ), 'B6 sensor unreachable: no Lift on either twin, the hint says why, View stays' );
$GLOBALS['__re']['sensor'] = array( 'reachable' => true ); $GLOBALS['__re']['ready'] = false;
ok( ! str_contains( $native(), 'Lift September' ) && ! str_contains( $classic(), 'Lift September' ), 'B7 worker not set up: no Lift' );
$GLOBALS['__re']['ready'] = true;

// C: Lift removes one month from the hold and nothing else.
$GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array( '2026-09', '2026-10' ) );
$GLOBALS['__options']['sn_rights_evidence_hold_reasons'] = array( '2026-09' => array( 'sensor: network' ), '2026-10' => array( 'x' ) );
ok( 'rights_evidence_lifted' === sn_handle_rights_evidence_lift( array( 'month' => '2026-09' ) ) && array( '2026-10' ) === $GLOBALS['__options']['sn_rights_evidence_hold'] && 0 === $GLOBALS['__re']['dry'] && array( '2026-10' => array( 'x' ) ) === $GLOBALS['__options']['sn_rights_evidence_hold_reasons'], 'C1 Lift takes the month off the hold and clears the reasons a rule gave it, keeps the rest, and runs nothing' );
ok( 'rights_evidence_not_held' === sn_handle_rights_evidence_lift( array( 'month' => '2026-08' ) ) && 'rights_evidence_not_held' === sn_handle_rights_evidence_lift( array( 'month' => '2026-10\'"' ) ) && array( '2026-10' ) === $GLOBALS['__options']['sn_rights_evidence_hold'], 'C2 a month not on hold (or not a month) changes nothing' );
$GLOBALS['__re']['sensor'] = null;
ok( 'rights_evidence_lift_refused' === sn_handle_rights_evidence_lift( array( 'month' => '2026-10' ) ) && array( '2026-10' ) === $GLOBALS['__options']['sn_rights_evidence_hold'], 'C3 the handler re-checks the gate: sensor unreachable, the hold stands' );
$GLOBALS['__re']['sensor'] = array( 'reachable' => true );
unset( $GLOBALS['__options']['sn_rights_evidence_hold'] );
ok( 'rights_evidence_lifted' === sn_handle_rights_evidence_lift( array( 'month' => '2026-09' ) ) && array() === $GLOBALS['__options']['sn_rights_evidence_hold'], 'C4 the seeded default (2026-09, never stored) lifts too, and stays lifted' );
$flash = (string) file_get_contents( SNT_PATH . 'inc/admin-flash-messages.php' );
ok( str_contains( $flash, "'rights_evidence_lifted'" ) && str_contains( $flash, "'rights_evidence_lift_refused'" ) && str_contains( $flash, "'rights_evidence_not_held'" ), 'C5 every code the handlers return has a flash message' );

// D: View refuses a month that is not held before composing anything.
$GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ) );
$_GET = array( 'month' => '2026-07' );
ok( 'rights_evidence_not_held' === sn_handle_rights_evidence_view( array() ) && 0 === $GLOBALS['__re']['dry'], 'D1 View for a month not on hold is refused without a dry run' );
$view_src = (string) file_get_contents( SNT_PATH . 'inc/admin-post-actions/rights-evidence.php' );
ok( ! preg_match( '/wp_remote_post|sn_rights_evidence_post|sn_rights_evidence_run|sn_rights_evidence_send|sn_rights_evidence_retract/', $view_src ), 'D2 structurally: neither View, Lift nor Hold can post, retract or run the pass (Retract and Post now live in their own files)' );

// E: Retract, one signed retraction per click, both twins.
$conf = array( 'uuid' => 'u7', 'content_hash' => 'h7', 'status' => 'confirmed', 'ledger_path' => 'rights-evidence/u7/v1.json', 'at' => 1, 'error' => '' );
$recs = static function ( array $aug ) { $GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array(), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => $aug ) ); $GLOBALS['__re']['posts'] = array(); };
$t    = SN_RIGHTS_EVIDENCE_RETRACTIONS['2026-08']['openai'];
$recs( array( 'openai' => $conf, 'anthropic' => array_merge( $conf, array( 'status' => 'pending' ) ), 'google-ai' => array_merge( $conf, array( 'ledger_path' => '' ) ), 'commoncrawl' => array_merge( $conf, array( 'status' => 'retracted' ) ), 'mistral' => $conf ) );
$c = $classic(); $n = $native();
foreach ( array( 'classic' => $c, 'native' => $n ) as $twin => $h ) {
	ok( str_contains( $h, 'Retract August 2026, openai' ) && str_contains( $h, 'sn_rights_evidence_retract' ) && str_contains( $h, 'nonce-sn_rights_evidence_retract' ) && str_contains( $h, 'append-only ledger' ) && str_contains( $h, 'cannot be undone' ), "E1 $twin: a Retract button for the confirmed record, its own nonce, behind a confirm that says it publishes" );
	ok( str_contains( $h, 'Claimed:</strong> ' . $t['claimed'] ) && str_contains( $h, 'What was wrong:</strong> ' . $t['what_was_wrong'] ) && str_contains( $h, 'Root cause:</strong> ' . $t['root_cause'] ) && str_contains( $h, 'What changed:</strong> ' . $t['what_changed'] ) && str_contains( $h, 'rights-evidence/u7/v1.json' ), "E2 $twin: the exact text that will be published is shown above the button" );
	ok( str_contains( $h, "Post one, wait for the ledger's checks, then the next." ), "E3 $twin: the one-at-a-time note is visible" );
	ok( 1 === substr_count( $h, 'Retract August 2026' ) && ! str_contains( $h, 'Retract August 2026, anthropic' ) && ! str_contains( $h, 'Retract August 2026, google-ai' ) && ! str_contains( $h, 'Retract August 2026, commoncrawl' ) && ! str_contains( $h, 'Retract August 2026, mistral' ), "E4 $twin: hidden for pending, no ledger path, retracted and no approved text" );
}
ok( array( 'family', 'month', 'sub', 'tab' ) === snt_leaf_names( $n ) && snt_leaf_names( $c ) === snt_leaf_names( $n ), 'E5 the native form posts the same names as the classic: ' . implode( ',', snt_leaf_names( $n ) ) );
ok( array() === $GLOBALS['__re']['posts'], 'E6 painting posts nothing' );
$recs( array( 'openai' => array_merge( $conf, array( 'status' => 'retracted' ) ) ) );
ok( ! str_contains( $classic(), 'Retract' ) && ! str_contains( $native(), 'Retract' ), 'E7 nothing retractable: no Retractions block on either twin' );

foreach ( array( 'worker' => '', 'secret' => '' ) as $k => $v ) {
	$recs( array( 'openai' => $conf ) );
	$keep = $GLOBALS['__re'][ $k ]; $GLOBALS['__re'][ $k ] = $v;
	ok( ! str_contains( $classic(), 'Retract August' ) && ! str_contains( $native(), 'Retract August' ), "E7b $k unset: no Retract button on either twin" );
	ok( 'rights_evidence_retract_unconfigured' === sn_handle_rights_evidence_retract( array( 'month' => '2026-08', 'family' => 'openai' ) ) && array() === $GLOBALS['__re']['posts'] && 'confirmed' === $GLOBALS['__options'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-08']['openai']['status'], "E7c $k unset: the handler refuses with its own flash, nothing posted" );
	$GLOBALS['__re'][ $k ] = $keep;
}
ok( str_contains( $flash, "'rights_evidence_retract_unconfigured'" ), 'E7d the unconfigured refusal has a flash message' );

$recs( array( 'openai' => $conf, 'mistral' => $conf ) );
foreach ( array( array( '2026-08', 'mistral' ), array( '2026-08', 'nobody' ), array( '2026-07', 'openai' ), array( '2026-08\'"', 'openai' ), array( '', '' ) ) as $mf ) {
	ok( 'rights_evidence_not_retractable' === sn_handle_rights_evidence_retract( array( 'month' => $mf[0], 'family' => $mf[1] ) ) && array() === $GLOBALS['__re']['posts'], 'E8 the handler refuses an unknown or ineligible month/family without posting: ' . implode( ' ', $mf ) );
}
ok( 'rights_evidence_retracted' === sn_handle_rights_evidence_retract( array( 'month' => '2026-08', 'family' => 'openai' ) ) && 1 === count( $GLOBALS['__re']['posts'] ) && 'https://prov.example/retract' === $GLOBALS['__re']['posts'][0][0] && 'retracted' === $GLOBALS['__options'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-08']['openai']['status'], 'E9 one click, one POST to /retract, the record retracted' );
$recs( array( 'openai' => $conf ) ); $GLOBALS['__re']['reply'] = array( 'code' => 409, 'body' => array( 'ok' => false, 'error' => 'subject absent' ) );
ok( 'rights_evidence_retract_refused' === sn_handle_rights_evidence_retract( array( 'month' => '2026-08', 'family' => 'openai' ) ) && 'subject absent' === ( $GLOBALS['__transients']['sn_rights_evidence_retract_error'] ?? '' ) && 'confirmed' === $GLOBALS['__options'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-08']['openai']['status'], 'E10 409: its own flash code, the worker\'s error kept for it, the status untouched' );
$GLOBALS['__re']['reply'] = array( 'code' => 0, 'body' => array( 'error' => 'timed out' ) );
ok( 'rights_evidence_retract_failed' === sn_handle_rights_evidence_retract( array( 'month' => '2026-08', 'family' => 'openai' ) ) && '0 timed out' === ( $GLOBALS['__transients']['sn_rights_evidence_retract_error'] ?? '' ) && 'confirmed' === $GLOBALS['__options'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-08']['openai']['status'], 'E11 network failure: its own flash code, untouched' );
ok( str_contains( $handler_src, "'rights_evidence_retract'    => 'sn_handle_rights_evidence_retract'" ) && str_contains( (string) file_get_contents( SNT_PATH . 'inc/admin-post-actions.php' ), 'admin-post-actions/rights-evidence-retract.php' ), 'E12 Retract is registered with the dispatcher (nonce + manage_options) and loaded' );
foreach ( array( 'rights_evidence_retracted', 'rights_evidence_retract_busy', 'rights_evidence_not_retractable' ) as $code ) {
	ok( str_contains( $flash, "'$code'" ), "E13 static flash message for $code" );
}
ok( str_contains( $flash, "'rights_evidence_retract_refused' === \$flash" ) && str_contains( $flash, "'rights_evidence_retract_failed' === \$flash" ) && str_contains( $flash, "get_transient( 'sn_rights_evidence_retract_error' )" ), 'E14 the 409 and failure flashes print the worker\'s error' );

// F: the review window on both twins (Unreleased): View, Hold and Post now for a waiting month, the rule's reason for a held one.
$waiting = array( 'uuid' => 'w1', 'content_hash' => 'h', 'canonical' => '{"family":"openai","month":"2026-09"}', 'status' => 'composed', 'ledger_path' => '', 'at' => 1, 'review_until' => time() + 41 * 3600 + 60, 'error' => '' );
$board   = static function () use ( $waiting ) {
	$GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ), 'sn_rights_evidence_hold_reasons' => array( '2026-08' => array( 'the worker refused openai: reservation: v3 not in force' ) ), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-09' => array( 'openai' => $waiting ) ) );
	$GLOBALS['__re']['sent'] = array();
};
$board();
$c = $classic(); $n = $native();
foreach ( array( 'classic' => $c, 'native' => $n ) as $twin => $h ) {
	ok( str_contains( $h, 'Held: 2026-08. In review: 2026-09. Backlog: none.' ), "F1 $twin: the status line names the month in review" );
	ok( str_contains( $h, 'September 2026</strong>: Composed, not posted: posts after ' ) && str_contains( $h, '(41 h left), unless held.' ), "F2 $twin: (changed) a waiting month says when a pass may post it, the slack hour before review_until, and that a hold stops it" );
	ok( str_contains( $h, 'View September 2026 payloads' ) && str_contains( $h, 'Hold September 2026' ) && str_contains( $h, 'sn_rights_evidence_hold' ) && str_contains( $h, 'Nothing of it posts until you lift the hold' ) && str_contains( $h, 'Post September 2026 now' ) && str_contains( $h, 'nonce-sn_rights_evidence_post_now' ) && str_contains( $h, 'cannot be edited, only retracted' ), "F3 $twin: View, Hold and Post now for the waiting month, each POST with its own nonce behind a confirm that says what it does" );
	ok( str_contains( $h, 'August 2026</strong>: Held by a rule: the worker refused openai: reservation: v3 not in force.' ) && str_contains( $h, 'Lift August 2026 hold' ) && ! str_contains( $h, 'Hold August' ) && ! str_contains( $h, 'Post August' ), "F4 $twin: a month a rule held shows the reason and Lift, never Hold or Post now" );
}
ok( snt_leaf_names( $c ) === snt_leaf_names( $n ) && array( 'month', 'sub', 'tab' ) === array_values( array_unique( array_diff( snt_leaf_names( $n ), array( 'action', '_wpnonce' ) ) ) ), 'F5 the native forms post the same names as the classic: ' . implode( ',', snt_leaf_names( $n ) ) );
$GLOBALS['__re']['worker'] = '';
ok( ! str_contains( $classic(), 'Post September' ) && ! str_contains( $native(), 'Post September' ) && str_contains( $classic(), 'Hold September 2026' ) && str_contains( $native(), 'Hold September 2026' ), 'F6 worker not set up: no Post now on either twin; Hold stays' );
ok( 'rights_evidence_post_now_unconfigured' === sn_handle_rights_evidence_post_now( array( 'month' => '2026-09' ) ) && array() === $GLOBALS['__re']['sent'], 'F6b the Post now handler re-checks the worker: nothing sent' );
$GLOBALS['__re']['worker'] = 'https://prov.example';
ok( array( 'openai' => $waiting['canonical'] ) === sn_rights_evidence_stored_payloads( '2026-09' ) && '2026-09' === sn_rights_evidence_request_pending( array( 'month' => '2026-09' ) ) && '' === sn_rights_evidence_request_pending( array( 'month' => '2026-08' ) ), 'F7 View takes a waiting month too and streams its stored bytes (what will post); a held month is not pending' );
$_GET = array( 'month' => '2026-07' );
ok( 'rights_evidence_not_held' === sn_handle_rights_evidence_view( array() ) && 0 === $GLOBALS['__re']['dry'], 'F7b View for a month neither held nor waiting is refused without a dry run' );
ok( 'rights_evidence_not_pending' === sn_handle_rights_evidence_hold( array( 'month' => '2026-08' ) ) && 'rights_evidence_not_pending' === sn_handle_rights_evidence_hold( array( 'month' => '2026-07' ) ) && array( '2026-08' ) === $GLOBALS['__options']['sn_rights_evidence_hold'], 'F8 Hold refuses a month that is held already or has nothing waiting' );
ok( 'rights_evidence_held' === sn_handle_rights_evidence_hold( array( 'month' => '2026-09' ) ) && array( '2026-08', '2026-09' ) === $GLOBALS['__options']['sn_rights_evidence_hold'] && array( '2026-09' ) === $GLOBALS['__options']['sn_rights_evidence_backlog'] && ! isset( $GLOBALS['__options']['sn_rights_evidence_hold_reasons']['2026-09'] ) && array() === $GLOBALS['__re']['sent'], 'F8b Hold puts the waiting month on the hold and in the backlog, stores no reason (the owner\'s hold), posts nothing' );
ok( 'rights_evidence_not_pending' === sn_handle_rights_evidence_post_now( array( 'month' => '2026-09' ) ) && array() === $GLOBALS['__re']['sent'], 'F9 Post now refuses a held month' );
$board();
ok( 'rights_evidence_posted_now' === sn_handle_rights_evidence_post_now( array( 'month' => '2026-09' ) ) && array( 'w1' ) === $GLOBALS['__re']['sent'] && 'rights-evidence/w1/v1.json' === $GLOBALS['__options'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-09']['openai']['ledger_path'], 'F9b Post now sends the waiting month inside its window and stores the answer' );
ok( 'rights_evidence_not_pending' === sn_handle_rights_evidence_post_now( array( 'month' => '2026-09' ) ) && 1 === count( $GLOBALS['__re']['sent'] ) && 'rights_evidence_not_pending' === sn_handle_rights_evidence_post_now( array( 'month' => '2026-09\'"' ) ), 'F9c a second click, or a month that is not one, sends nothing' );
ok( str_contains( $handler_src, "'rights_evidence_hold'       => 'sn_handle_rights_evidence_hold'" ) && str_contains( $handler_src, "'rights_evidence_post_now'   => 'sn_handle_rights_evidence_post_now'" ) && str_contains( (string) file_get_contents( SNT_PATH . 'inc/admin-post-actions.php' ), 'admin-post-actions/rights-evidence-post-now.php' ), 'F10 Hold and Post now are registered with the dispatcher (nonce + manage_options) and loaded' );
foreach ( array( 'rights_evidence_held', 'rights_evidence_not_pending', 'rights_evidence_posted_now', 'rights_evidence_post_now_partial', 'rights_evidence_post_now_unconfigured' ) as $code ) {
	ok( str_contains( $flash, "'$code'" ), "F11 static flash message for $code" );
}

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
