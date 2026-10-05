<?php
/**
 * The extra SN Systems and SN Provenance rows (inc/desktop-mode-status-extra.php):
 * the pure shapers, and that every source is a local read.
 * Run: php tests/desktop-status-extra.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
function sn_prov_integrity_is_outage( $c ) { return in_array( (string) $c, array( 'twin_unreachable', 'ledger_unreachable', 'keys_unreachable' ), true ); }
$GLOBALS['__edge'] = array();
function sn_edge_errors_range( $from, $to ) { return $GLOBALS['__edge'][ $from ] ?? array( 'query' => array( 'error' => 'no fixture' ) ); }
require __DIR__ . '/../inc/desktop-mode-status-extra.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "\nCron over 24 hours\n";
$c = snt_desktop_cron_24h_shape( array( array( 'hook' => 'a', 'fires' => '10', 'failed' => '0' ), array( 'hook' => 'b', 'fires' => 5, 'failed' => 1 ), array( 'hook' => 'c', 'fires' => 3, 'failed' => 3 ) ) );
ok( 18 === $c['fires'] && 4 === $c['failed'], 'runs and failures add across hooks (numeric strings from wpdb included)' );
ok( array( 'c', 'b' ) === $c['failing'], 'failing hooks, most failures first; a clean hook is not named' );
ok( array( 'fires' => 0, 'failed' => 0, 'failing' => array() ) === snt_desktop_cron_24h_shape( array() ), 'no runs: zero runs and nothing failing (a read that answered, not a failed read)' );

echo "\nRights evidence\n";
$d = array(
	'2026-08' => array( 'gptbot' => array( 'status' => 'confirmed', 'at' => 100 ) ),
	'2026-09' => array( 'gptbot' => array( 'status' => 'pending', 'at' => 300 ), 'ccbot' => array( 'status' => 'pending', 'at' => 200 ), 'x' => array( 'status' => 'composed', 'at' => 400 ) ),
);
$r = snt_desktop_rights_state( $d, array() );
ok( 'September' === $r['month'], 'the newest month, by name' );
ok( '2 waiting for a Bitcoin block · 1 in review' === $r['text'] && false === $r['attention'], 'what each record of that month is waiting for' );
ok( 300 === $r['last_posted'], 'last posted: the newest posted record, not a composed one' );
ok( true === snt_desktop_rights_state( $d, array( '2026-09' ) )['attention'] && 0 === strpos( snt_desktop_rights_state( $d, array( '2026-09' ) )['text'], 'held' ), 'a held month says held and needs the owner' );
$d['2026-09']['y'] = array( 'status' => 'refused', 'at' => 0 );
ok( true === snt_desktop_rights_state( $d, array() )['attention'], 'a refused record needs the owner' );
ok( null === snt_desktop_rights_state( array(), array() ) && null === snt_desktop_rights_state( null, array() ), 'no ledger: null, the row is left out' );
$h = snt_desktop_rights_state( array(), array( '2026-10' ) );
ok( 'October' === $h['month'] && 'held' === $h['text'] && true === $h['attention'], 'Codex on 1aa80a1: a month held before anything was composed still shows, and needs the owner' );
ok( 'October' === snt_desktop_rights_state( $d, array( '2026-10' ) )['month'], 'a newer held month wins over an older populated one' );
$rt = snt_desktop_rights_state( array( '2026-09' => array( 'a' => array( 'status' => 'retracted', 'at' => 900 ), 'b' => array( 'status' => 'confirmed', 'at' => 100 ) ) ), array() );
ok( 900 === $rt['last_posted'], 'Codex on 1aa80a1: a retracted record was still posted: last posted counts it' );

echo "\nEdge 5xx\n";
$d1 = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ); $d2 = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ); $d3 = gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );
$GLOBALS['__edge'] = array(
	$d1 => array( 'total' => 0, 'days' => array( array( 'day' => $d1, 'read' => 'pending' ) ) ),
	$d2 => array( 'total' => 9, 'days' => array( array( 'day' => $d2, 'read' => 'read', 'visitor' => 3 ) ) ),
	$d3 => array( 'total' => 4, 'days' => array( array( 'day' => $d3, 'read' => 'read' ) ) ),
);
$e = snt_desktop_edge_yesterday();
ok( 3 === $e['visitor'], 'Codex on 90eb194: the 5xx a visitor received are carried apart from Worker subrequests' );
ok( 9 === $e['total'] && 4 === $e['prior'] && gmdate( 'M j', time() - 2 * DAY_IN_SECONDS ) === $e['day'], 'yesterday still pending: the newest covered day answers, labeled, against the day before it' );
$GLOBALS['__edge'][ $d3 ] = array( 'total' => 0, 'query' => array( 'error' => '' ), 'days' => array( array( 'day' => $d3, 'read' => 'failed' ) ) );
ok( null === snt_desktop_edge_yesterday()['prior'], 'Codex on 2bee69f: a failed prior day is no prior, never a 0 that makes a false delta' );
$GLOBALS['__edge'][ $d1 ] = array( 'total' => 12, 'days' => array( array( 'day' => $d1, 'read' => 'read' ) ) );
ok( 12 === snt_desktop_edge_yesterday()['total'] && 9 === snt_desktop_edge_yesterday()['prior'], 'once covered, yesterday answers' );
$GLOBALS['__edge'] = array();
ok( null === snt_desktop_edge_yesterday(), 'no day readable: null, the card says so instead of a 0' );

echo "\nIntegrity\n";
$ig = snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 50 ), 'notes' => array( 1 => array( 'last_checked' => 5, 'failures' => array() ), 2 => array( 'last_checked' => 5, 'failures' => array( 'twin' ) ), 3 => array( 'last_checked' => 0 ) ) ) );
ok( 50 === $ig['fleet'] && 2 === $ig['checked'] && 1 === $ig['clean'] && 1 === $ig['failing'], 'Codex on 2bee69f: counted from stored per-subject results; a subject not reached is not passing' );
ok( null === snt_desktop_integrity_shape( array() ), 'no sweep yet: null' );
$ur = snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 3 ), 'notes' => array( 1 => array( 'last_checked' => 5, 'failures' => array( 'twin_unreachable' ) ), 2 => array( 'last_checked' => 5, 'failures' => array( 'hash_mismatch' ) ), 3 => array( 'last_checked' => 5, 'failures' => array() ) ) ) );
ok( 1 === $ur['unreachable'] && 1 === $ur['failing'] && 1 === $ur['clean'], 'Codex on 90eb194: an unreachable twin is not a failure and not a pass' );
$kg = snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 2, 'keys' => 'keys_missing' ), 'notes' => array( 1 => array( 'last_checked' => 5, 'failures' => array() ), 2 => array( 'last_checked' => 5, 'failures' => array() ) ) ) );
ok( 'keys_missing' === $kg['keys'] && 2 === $kg['clean'], 'Codex on 1aa80a1: a fleet-level key finding is carried, however clean the subjects' );
ok( '' === snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 2, 'keys' => 'ok' ), 'notes' => array() ) )['keys'], 'a good key verdict carries nothing' );

echo "\nLocal reads only\n";
$src = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-status-extra.php' );
ok( false === strpos( $src, 'wp_remote_' ) && false === strpos( $src, 'snt_mr_fetch' ) && false === strpos( $src, 'sn_uptime_status' ), 'no remote call: the payload rides a localize that runs on every wp-admin screen' );
ok( false !== strpos( $src, 'set_transient( SNT_DESKTOP_STATUS_EXTRA_KEY, $out, 5 * MINUTE_IN_SECONDS )' ), 'and it is held five minutes' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
