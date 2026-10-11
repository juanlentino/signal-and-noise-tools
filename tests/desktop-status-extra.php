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
function snt_purge_ledger_last_edge() { return 5000; }
function snt_cf_freshness_summary() { return array( 'last' => 'pending', 'last_time' => 1000, 'headline' => 'Purge dispatched, verifying' ); }
require __DIR__ . '/../inc/desktop-mode-status-extra.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "\nCron over 24 hours\n";
$c = snt_desktop_cron_24h_shape( array( array( 'hook' => 'a', 'fires' => '10', 'failed' => '0' ), array( 'hook' => 'b', 'fires' => 5, 'failed' => 1 ), array( 'hook' => 'c', 'fires' => 3, 'failed' => 3 ) ) );
ok( 18 === $c['fires'] && 4 === $c['failed'], 'runs and failures add across hooks (numeric strings from wpdb included)' );
ok( array( 'c', 'b' ) === $c['failing'], 'failing hooks, most failures first; a clean hook is not named' );
ok( array( 'fires' => 0, 'failed' => 0, 'failing' => array() ) === snt_desktop_cron_24h_shape( array() ), 'no runs: zero runs and nothing failing (a read that answered, not a failed read)' );

echo "\nCache\n";
$cs = snt_desktop_cache_state();
ok( 1000 === $cs['fresh_time'] && 5000 === $cs['last_purge'], 'Codex on #1925: the report\'s own time travels beside the ledger\'s last purge, so a pending verdict ages from the report' );

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
$keep = $GLOBALS['__edge'][ $d2 ];
$GLOBALS['__edge'][ $d2 ] = array( 'total' => 0, 'days' => array( array( 'day' => $d2, 'read' => 'failed' ) ) );
ok( true === ( snt_desktop_edge_yesterday()['failed'] ?? false ), 'Codex on b872a9f: a failed newest non-pending day is said, never replaced by an older day' );
$GLOBALS['__edge'][ $d2 ] = $keep;
$GLOBALS['__edge'][ $d1 ] = array( 'total' => 12, 'days' => array( array( 'day' => $d1, 'read' => 'read' ) ) );
ok( 12 === snt_desktop_edge_yesterday()['total'] && 9 === snt_desktop_edge_yesterday()['prior'], 'once covered, yesterday answers' );
$GLOBALS['__edge'] = array();
ok( null === snt_desktop_edge_yesterday(), 'no day readable: null, the card says so instead of a 0' );

echo "\nEdge: whose 5xx\n";
$dp = array( array( 'value' => '/wp-json/desktop-mode/v1/session', 'requests' => 33 ), array( 'value' => '/', 'requests' => 6 ), array( 'value' => '/wp-json/wp-abilities/v1/abilities/signal-noise/get-deploy-status/run', 'requests' => 5 ), array( 'value' => '/wp-admin/admin.php', 'requests' => 3 ), array( 'value' => '/openstation/sw.js', 'requests' => 3 ), array( 'value' => '/lib/editor/tiny/loader.php', 'requests' => 3 ), array( 'value' => '/wp-json/allterrain-forms/v1/forms', 'requests' => 3 ), array( 'value' => '/provenance/', 'requests' => 2 ) );
ok( 44 === snt_desktop_edge_dashboard_count( $dp ), 'the session ping, the abilities polls, wp-admin and the shell count as the dashboard; the home page, a scanner path, a public form and a note do not' );
ok( null === snt_desktop_edge_dashboard_count( null ) && 0 === snt_desktop_edge_dashboard_count( array() ), 'no paths: unknown, never a 0; an empty list is 0' );
ok( 0 === snt_desktop_edge_dashboard_count( array( array( 'value' => '/wp-admin-fake/', 'requests' => 9 ), array( 'value' => '/notes/wp-admin/', 'requests' => 9 ) ) ), 'a path that only contains the words is not the dashboard' );
$GLOBALS['__edge'] = array( $d1 => array( 'total' => 12, 'paths' => array( array( 'value' => '/wp-admin/x', 'requests' => 10 ) ), 'days' => array( array( 'day' => $d1, 'read' => 'read' ) ) ) );
ok( 10 === snt_desktop_edge_yesterday()['dashboard'], 'the card row carries the dashboard count' );
$GLOBALS['__edge'] = array();

echo "\nIntegrity\n";
$ig = snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 50 ), 'notes' => array( 1 => array( 'last_checked' => 5, 'failures' => array() ), 2 => array( 'last_checked' => 5, 'failures' => array( 'twin' ) ), 3 => array( 'last_checked' => 0 ) ) ) );
ok( 50 === $ig['fleet'] && 2 === $ig['checked'] && 1 === $ig['clean'] && 1 === $ig['failing'], 'Codex on 2bee69f: counted from stored per-subject results; a subject not reached is not passing' );
ok( null === snt_desktop_integrity_shape( array() ), 'no sweep yet: null' );
$ur = snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 3 ), 'notes' => array( 1 => array( 'last_checked' => 5, 'failures' => array( 'twin_unreachable' ) ), 2 => array( 'last_checked' => 5, 'failures' => array( 'hash_mismatch' ) ), 3 => array( 'last_checked' => 5, 'failures' => array() ) ) ) );
ok( 1 === $ur['unreachable'] && 1 === $ur['failing'] && 1 === $ur['clean'], 'Codex on 90eb194: an unreachable twin is not a failure and not a pass' );
$kg = snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 2, 'keys' => 'keys_missing' ), 'notes' => array( 1 => array( 'last_checked' => 5, 'failures' => array() ), 2 => array( 'last_checked' => 5, 'failures' => array() ) ) ) );
ok( 'keys_missing' === $kg['keys'] && 2 === $kg['clean'], 'Codex on 1aa80a1: a fleet-level key finding is carried, however clean the subjects' );
ok( '' === snt_desktop_integrity_shape( array( 'last_sweep' => array( 'fleet' => 2, 'keys' => 'ok' ), 'notes' => array() ) )['keys'], 'a good key verdict carries nothing' );

echo "\nEdge: a day stored before who-asked\n";
$GLOBALS['__edge'] = array( $d1 => array( 'total' => 12, 'days' => array( array( 'day' => $d1, 'read' => 'read' ) ) ) );
ok( null === snt_desktop_edge_yesterday()['visitor'], '2026-10-10: no visitor key on the day is unknown, never a 0 the card would paint' );
$GLOBALS['__edge'] = array();

echo "\nAnalytics 2.0 (the stored verdict)\n";
$a2 = snt_desktop_analytics2_shape( array( 'ok' => true, 'day' => '2026-10-09', 'clean_from' => '2026-10-05', 'events_ok' => true, 'at' => 1, 'why' => '' ) );
ok( true === $a2['ok'] && 5 === $a2['days'] && 'Oct 5' === $a2['since'] && '' === $a2['reset'], 'clean days: the clean day through the last day that matched, and since when' );
ok( 'Oct 9' === $a2['last'], 'the last exact day rides beside since: a sampled day after it is neither counted nor a reset' );
$rs = snt_desktop_analytics2_shape( array( 'ok' => false, 'day' => '', 'clean_from' => '2026-10-10', 'events_ok' => false, 'at' => 1, 'why' => 'no complete day has matched since the mismatch before 2026-10-10' ) );
ok( '' === $rs['last'], 'no verdict, no last exact day' );
ok( false === $rs['ok'] && 0 === $rs['days'] && 'Oct 9' === $rs['reset'], 'a reset names the day that did not match (the day before the new clean day)' );
ok( '' === snt_desktop_analytics2_shape( array( 'ok' => false, 'clean_from' => '2026-10-05', 'at' => 1 ) )['reset'], 'never verified is not a reset' );
ok( '' === snt_desktop_analytics2_shape( array( 'ok' => false, 'clean_from' => '', 'at' => 1 ) )['reset'], 'an empty clean day falls back to the first full day, not a reset' );
$fb = snt_desktop_analytics2_shape( array( 'ok' => true, 'day' => '2026-10-09', 'clean_from' => '2026-10-05', 'events_ok' => false, 'at' => 1, 'frozen' => 99 ) );
ok( true === $fb['frozen'] && true === $fb['bad'], 'frozen before the events dataset was proven is the bad freeze the watch names' );
ok( false === snt_desktop_analytics2_shape( array( 'ok' => true, 'day' => '2026-10-09', 'clean_from' => '2026-10-05', 'events_ok' => true, 'at' => 1, 'frozen' => 99 ) )['bad'], 'a good frozen verdict is not bad' );
ok( null === snt_desktop_analytics2_shape( null ) && null === snt_desktop_analytics2_shape( array() ), 'no verdict stored: null, the card says so' );

echo "\nWatches due\n";
ok( array( array( 'id' => 'a', 'label' => 'A watch' ) ) === snt_desktop_watches_shape( array( array( 'id' => 'a', 'label' => 'A watch', 'why' => 'long', 'note' => 'n' ), array( 'id' => 'b' ), 'junk' ) ), 'id and label only; a row without a label is left out' );
ok( array() === snt_desktop_watches_shape( array() ), 'nothing due: an empty list, the card paints no line' );

echo "\nCredentials\n";
$now = (int) strtotime( '2026-10-10 00:00:00 UTC' );
$kr  = array( 'rows' => array( array( 'id' => 'a', 'verdict' => 'ok' ), array( 'id' => 'b', 'verdict' => 'refused', 'detail' => 'secret-ish' ), array( 'id' => 'c', 'verdict' => null ), array( 'id' => 'd', 'verdict' => 'error' ), array( 'id' => 'e', 'verdict' => 'unset' ) ) );
$cr  = snt_desktop_credentials_shape( $kr, null, $now );
ok( array( array( 'id' => 'b', 'verdict' => 'refused' ), array( 'id' => 'd', 'verdict' => 'error' ) ) === $cr['keyring'], 'refused and error are failing; never verified and unset are not; no detail travels' );
ok( null === $cr['cloudflare'], 'no monitor record: nothing to say wrong' );
$cf = static fn( $t ) => array( 'configured' => true, 'token' => $t );
ok( null === snt_desktop_credentials_shape( null, $cf( array( 'verified' => true, 'status' => 'active', 'expires_on' => '' ) ), $now )['cloudflare'], 'an active token that never expires is fine' );
ok( null === snt_desktop_credentials_shape( null, $cf( array( 'verified' => true, 'status' => 'active', 'expires_on' => '2026-11-30T00:00:00Z' ) ), $now )['cloudflare'], 'one that expires in seven weeks is fine' );
ok( array( 'status' => 'active', 'expires' => 'Oct 20' ) === snt_desktop_credentials_shape( null, $cf( array( 'verified' => true, 'status' => 'active', 'expires_on' => '2026-10-20T00:00:00Z' ) ), $now )['cloudflare'], 'one that expires within 14 days is said, with the day' );
ok( 'expired' === snt_desktop_credentials_shape( null, $cf( array( 'verified' => true, 'status' => 'expired', 'expires_on' => '' ) ), $now )['cloudflare']['status'], 'a token not active is said' );
ok( 'unreachable' === snt_desktop_credentials_shape( null, $cf( array( 'verified' => false, 'status' => 'unreachable', 'expires_on' => '' ) ), $now )['cloudflare']['status'], 'a token that could not be verified is said' );
ok( null === snt_desktop_credentials_shape( null, array( 'configured' => false, 'token' => null ), $now )['cloudflare'], 'not configured is no reading' );

echo "\nZenodo\n";
function snt_ability_zenodo_status() { return $GLOBALS['__zen']; }
$GLOBALS['__zen'] = array( 'total' => 9, 'minted' => 7, 'missing' => array( array( 'state' => 'ready', 'error' => '' ), array( 'state' => 'ready', 'error' => '2026-10-09T00:00:00+00:00 publish: 500' ) ) );
ok( array( 'minted' => 7, 'total' => 9, 'failed' => 1 ) === snt_desktop_zenodo(), 'a document whose last deposit stored an error counts as failed; one only waiting does not' );

echo "\nThe payload carries the new lines\n";
$GLOBALS['__opts'] = array( 'sn_analytics_v2_verified' => array( 'ok' => true, 'day' => '2026-10-09', 'clean_from' => '2026-10-05', 'events_ok' => true, 'at' => 1 ) );
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { $GLOBALS['__set'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function snt_watches_ripe() { return array( array( 'id' => 'w', 'label' => 'A watch' ) ); }
function snt_ability_keyring_status() { return array( 'rows' => array( array( 'id' => 'k', 'verdict' => 'error' ) ) ); }
function sn_cf_monitor_read() { return null; }
$pl = snt_desktop_status_extra();
ok( 5 === $pl['systems']['analytics2']['days'], 'analytics2 from the stored option' );
ok( array( array( 'id' => 'w', 'label' => 'A watch' ) ) === $pl['systems']['watches'], 'the ripe watches' );
ok( 'k' === $pl['systems']['credentials']['keyring'][0]['id'], 'the failing credential' );
ok( 1 === $pl['provenance']['zenodo']['failed'] && is_int( $pl['at'] ) && $pl['at'] > 0, 'the zenodo failures and the read time' );
ok( isset( $GLOBALS['__set'][ SNT_DESKTOP_STATUS_EXTRA_KEY ] ), 'cached under the v2 key' );

echo "\nLocal reads only\n";
$src = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-status-extra.php' );
ok( false === strpos( $src, 'wp_remote_' ) && false === strpos( $src, 'snt_mr_fetch' ) && false === strpos( $src, 'sn_uptime_status' ), 'no remote call: the payload rides a localize that runs on every wp-admin screen' );
ok( false !== strpos( $src, 'set_transient( SNT_DESKTOP_STATUS_EXTRA_KEY, $out, 5 * MINUTE_IN_SECONDS )' ), 'and it is held five minutes' );
ok( 'snt_desktop_status_extra_v2' === SNT_DESKTOP_STATUS_EXTRA_KEY, '2026-10-10: the shape changed, so the key moved: a v1 payload still cached is never served' );
ok( false === strpos( $src, 'sn_analytics_v2_verify(' ) && false === strpos( $src, 'sn_analytics_v2_check(' ), 'the analytics 2.0 line reads the stored verdict, never the live check' );
$assets = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-assets.php' );
ok( false !== strpos( $assets, "'credentials'     => snt_desktop_admin_url( 'sn-connections', 'credentials' )" ) && false !== strpos( $assets, "'zenodo'          => snt_desktop_admin_url( 'sn-connections', 'zenodo' )" ), 'the failing-credential and failed-deposit lines have pages to link to' );
foreach ( array( "'analytics2'", "'watches'", "'credentials'", "'at'" ) as $k ) {
	ok( false !== strpos( $src, $k . ' ' ), "the payload carries $k" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
