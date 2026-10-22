<?php
/** Standalone fixture tests for the R6a Operations morning brief. */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_DEPLOY_REPOS', array( 'theme' => 'owner/theme', 'plugin' => 'owner/plugin' ) );
// WordPress core constants + i18n helper the composer relies on. WP always
// defines these; the harness did not, and the R6b search section was the first
// code path here to reach for them — it fataled the moment a fixture exercised it.
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
}

// Recording hooks: the send adds a phpmailer_init filter for the plain-text
// part and must remove it, or it would leak into the next plugin's email.
$GLOBALS['__hooks'] = array();
function add_action( $hook = '', $cb = null ) { $GLOBALS['__hooks'][ $hook ][] = $cb; }
function remove_action( $hook, $cb ) { $GLOBALS['__hooks'][ $hook ] = array_values( array_filter( $GLOBALS['__hooks'][ $hook ] ?? array(), static fn( $c ) => $c !== $cb ) ); return true; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function checked() {}
function wp_nonce_field() {}
function get_bloginfo( $key = '' ) { return 'Test Site'; }
function wp_specialchars_decode( $value, $flags = 0 ) { return $value; }
function is_email( $email ) { return false !== strpos( $email, '@' ); }
function human_time_diff( $from, $to = 0 ) { return '2 hours'; }
function current_datetime() { return new DateTimeImmutable( '2026-08-17 06:30:00', new DateTimeZone( 'America/New_York' ) ); }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }

$GLOBALS['__settings'] = array();
function sn_setting( $key, $default = null ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
$GLOBALS['__options'] = array( 'admin_email' => 'owner@example.com' );
function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }

$GLOBALS['__cron'] = array();
function wp_next_scheduled( $hook ) { return $GLOBALS['__cron'][ $hook ]['timestamp'] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['__cron'][ $hook ] = compact( 'timestamp', 'recurrence' ); return true; }
function wp_unschedule_event( $timestamp, $hook ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }

$GLOBALS['__mail'] = array();
// Mirrors core: wp_mail fires phpmailer_init with the mailer, so a test can see
// the AltBody the send set while it was in flight.
function wp_mail( $to, $subject, $body, $headers = '' ) {
	$mailer = new stdClass();
	$mailer->AltBody = '';
	foreach ( $GLOBALS['__hooks']['phpmailer_init'] ?? array() as $cb ) { $cb( $mailer ); }
	$alt = $mailer->AltBody;
	$GLOBALS['__mail'][] = compact( 'to', 'subject', 'body', 'headers', 'alt' );
	return true;
}
// Ripe watches and the indexing report, driven by fixtures below.
$GLOBALS['__ripe'] = array();
function snt_watches_ripe() { return $GLOBALS['__ripe']; }
$GLOBALS['__report'] = null;
function sn_indexing_report() { if ( 'throw' === $GLOBALS['__report'] ) { throw new RuntimeException( 'report failed' ); } return $GLOBALS['__report']; }
function sn_bing_url_info_data() { return array( '/notes/a-note/' => array( 'state' => 'crawled', 'crawled' => '2026-10-01' ) ); }
function sn_bing_url_info_label( $a ) { return is_array( $a ) ? 'Bing crawled ' . $a['crawled'] : null; }

function sn_health_last_scan() { return array( 'scanned_at' => 100, 'checks' => array( 'links' => array(), 'seo' => array() ) ); }
function sn_health_finding_total( $scan ) { return 2; }
// #1190: mirrors the real production shape -- all four advisory keys live on
// the `worklist` surface, so the default $surface = 'health' counts none of
// them (structural 0) and only $surface = null (every surface) sees them.
function sn_health_advisory_total( $scan, $surface = 'health' ) { return null === $surface ? 4 : 0; }
function snt_cron_get_events_impl() {
	return array(
		array( 'hook' => 'sn_one', 'is_sn_owned' => true, 'has_handler' => true ),
		array( 'hook' => 'orphan', 'is_sn_owned' => false, 'has_handler' => false ),
	);
}
function snt_cron_history_for_hook( $hook, $limit = 10 ) { return array( array( 'success' => true ), array( 'success' => false ) ); }
function sn_uptime_status_configured() { return true; }
function sn_uptime_status_fetch( $force = false ) { return array( 'rows' => array( array( 'status' => 'up' ), array( 'status' => 'down' ) ) ); }
function is_wp_error( $value ) { return false; }
function snt_deploy_status_for( $package ) { return array( 'state' => 'theme' === $package ? 'ok' : 'available' ); }
function snt_deploy_history_merged( $repos, $limit ) { return array( array( 'created_at' => '2026-08-17T05:00:00Z' ) ); }
function snt_deploy_runs_age_label( $runs ) { return '2 hours ago'; }
$GLOBALS['__drift'] = array( 'has_drift' => false, 'count' => 0, 'changed' => array(), 'added' => array(), 'removed' => array() );
function snt_config_drift_status() { return $GLOBALS['__drift']; }

require_once __DIR__ . '/lib/admin-post-url-stub.php';
require __DIR__ . '/../inc/morning-brief.php';

$pass = 0; $fail = 0;
function ok( $condition, $message ) { global $pass, $fail; if ( $condition ) { $pass++; echo "  PASS: $message\n"; } else { $fail++; echo "  FAIL: $message\n"; } }

echo "\nTest: default and collector\n";
ok( false === snt_morning_brief_enabled(), 'new mail surface defaults off' );
$data = snt_morning_brief_collect();
ok( 2 === $data['health']['findings'] && 4 === $data['health']['advisories'],
	'#1190: collector counts advisories on the `worklist` surface (via $surface=null), not the health-only default' );
ok( 2 === $data['cron']['total'] && 1 === $data['cron']['failed'], 'collector reads cron events and real history shape' );
ok( 1 === $data['uptime']['up'] && 1 === $data['uptime']['attention'], 'collector summarizes uptime rows' );
ok( 'available' === $data['deploy']['plugin']['state'], 'collector reads deploy status helper' );

echo "\nTest: prose composer\n";
$body = snt_morning_brief_compose( $data );
ok( false !== strpos( $body, '2 fault-tier issues across 2 checks' ), 'compose narrates health facts as prose' );
ok( false !== strpos( $body, '2 scheduled events' ) && false !== strpos( $body, '1 of the 2 recent owned firings' ), 'compose narrates cron facts' );
ok( false !== strpos( $body, '1 of 2 monitors and heartbeats up' ), 'compose narrates uptime facts' );
ok( false !== strpos( $body, '1 update available' ), 'compose narrates deploy facts' );
ok( false === strpos( $body, 'Configuration drift' ), 'compose is silent when drift does not exist' );
$data['drift'] = array( 'has_drift' => true, 'count' => 3, 'changed' => array( 'a' ), 'added' => array( 'b' ), 'removed' => array( 'c' ) );
$body = snt_morning_brief_compose( $data );
ok( 1 === substr_count( $body, 'Configuration drift' ), 'compose adds exactly one drift sentence' );
ok( false !== strpos( $body, '1 changed, 1 added, and 1 removed' ), 'drift sentence states the diff shape' );

echo "\nTest: R6b search section\n";
// SILENT without data, on purpose: an operations brief that says "no search
// data" every morning for a site that never connected GSC is noise, and the
// setup nag belongs on the settings screen.
ok( false === strpos( $body, 'Google showed the site' ), 'compose is SILENT when nothing has synced' );
$data['search'] = array(
	'impressions' => 4200, 'clicks' => 61, 'zero_click' => 3,
	'top_query' => 'music provenance', 'synced_at' => time(),
	'window' => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
);
$body = snt_morning_brief_compose( $data );
ok( false !== strpos( $body, 'Google showed the site 4,200 times' ), 'search sentence reports impressions' );
ok( false !== strpos( $body, '61 clicks' ), 'and clicks' );
ok( false !== strpos( $body, '2026-07-01 to 2026-07-28' ), "and names GOOGLE's window, not the brief's day" );
ok( false !== strpos( $body, '3 pages drew meaningful impressions without a single click' ), 'the zero-click count is called out — it is the actionable one' );
ok( false !== strpos( $body, '"music provenance"' ), 'and the most-seen query is quoted' );
ok( false === strpos( $body, 'has not been re-synced' ), 'a FRESH window raises no staleness sentence' );

// A window nobody refreshed must read as stale, not as current.
$data['search']['synced_at'] = time() - ( 9 * DAY_IN_SECONDS );
$body = snt_morning_brief_compose( $data );
ok( false !== strpos( $body, 'search window is 9 days old' ), 'a STALE window says so — otherwise old numbers read as this morning\'s' );

$data['search']['zero_click'] = 0;
$body = snt_morning_brief_compose( $data );
ok( false === strpos( $body, 'without a single click' ), 'no zero-click sentence when there are none' );
ok( 1 === substr_count( $body, 'Google showed the site' ), 'exactly one search sentence' );

echo "\nTest: subject, send, and cron\n";
ok( false !== strpos( snt_morning_brief_subject( $data ), '[Test Site] Morning operations brief' ), 'subject names the site and brief' );
$GLOBALS['__mail'] = array();
ok( true === snt_morning_brief_send( true ) && 1 === count( $GLOBALS['__mail'] ), 'test sender dispatches one email' );
ok( 0 === strpos( $GLOBALS['__mail'][0]['subject'], '[TEST] ' ), 'test sender prefixes the subject' );
ok( '2026-08-17 07:00' === ( new DateTimeImmutable( '@' . snt_morning_brief_next_run() ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i' ), 'next run is 7:00 a.m. site time' );
$GLOBALS['__settings']['operations.morning_brief_enabled'] = true;
snt_morning_brief_maybe_schedule_cron();
ok( 'daily' === $GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ]['recurrence'], 'cron schedules daily when enabled' );
snt_morning_brief_maybe_schedule_cron();
ok( 1 === count( $GLOBALS['__cron'] ), 'cron scheduling is idempotent' );
// DST drift: wp_schedule_event repeats at fixed +86400s, so after a
// transition the pending firing lands off 7:00 site time. The init hook
// must re-anchor it rather than no-op on "already scheduled".
$drifted = ( new DateTimeImmutable( '2026-11-02 06:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
$GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ] = array( 'timestamp' => $drifted, 'recurrence' => 'daily' );
snt_morning_brief_maybe_schedule_cron();
$re_anchored = ( new DateTimeImmutable( '@' . $GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ]['timestamp'] ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'H:i' );
ok( '07:00' === $re_anchored, 'a DST-drifted firing (6:00 site time) is re-anchored to 7:00' );

// #1190: if the mis-anchored occurrence already fired TODAY (LAST_SENT is
// today, site time), re-anchoring must land on TOMORROW at 7:00 -- landing
// back on today would send a second brief on the transition day.
$GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_SENT ] =
	( new DateTimeImmutable( '2026-08-17 06:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
$GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ] = array(
	'timestamp'  => ( new DateTimeImmutable( '2026-08-17 06:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp(),
	'recurrence' => 'daily',
);
snt_morning_brief_maybe_schedule_cron();
$re_anchored2 = ( new DateTimeImmutable( '@' . $GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ]['timestamp'] ) )
	->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i' );
ok( '2026-08-18 07:00' === $re_anchored2,
	'#1190: already sent today -> re-anchor lands TOMORROW at 7:00, not a second send today' );
unset( $GLOBALS['__options'][ SNT_MORNING_BRIEF_LAST_SENT ] );

$GLOBALS['__settings']['operations.morning_brief_enabled'] = false;
snt_morning_brief_maybe_schedule_cron();
ok( false === wp_next_scheduled( SNT_MORNING_BRIEF_CRON_HOOK ), 'cron unschedules when disabled' );

echo "\nTest: settings shape\n";
$GLOBALS['__drift'] = array( 'has_drift' => true, 'count' => 2, 'changed' => array( 'a' ), 'added' => array( 'b' ), 'removed' => array() );
ob_start();
snt_morning_brief_render_settings();
$rendered = ob_get_clean();
ok( false !== strpos( $rendered, 'class="sn-fieldset"' ), 'settings render uses the digest fieldset shape' );
ok( false !== strpos( $rendered, 'name="snt_morning_brief_enabled"' ), 'settings render includes the opt-in toggle' );
ok( false !== strpos( $rendered, 'name="snt_morning_brief_test"' ), 'settings render includes a test-send action' );
ok( false !== strpos( $rendered, 'name="snt_config_drift_acknowledge"' ), 'settings render exposes explicit drift acknowledgement only when needed' );


// ─── v13.90.0: watches that have come due ─────────────────────────────────
// Every other section speaks on every send, including to say "unavailable",
// because its subject always exists. A watch that is not due has nothing to
// report, and a daily line saying so would train the reader to skip the
// paragraph it sits in.
$base = array( 'health' => null, 'cron' => null, 'uptime' => null, 'deploy' => null, 'drift' => null, 'search' => null, 'watches' => array() );

$quiet = snt_morning_brief_compose( $base );
// Asserting the MARKER alone was too narrow: a mutation adding "No watches are
// due." passed, because that string does not contain "Watch due". The property
// is that the brief says nothing about watches at all.
ok( false === stripos( $quiet, 'watch' ) && false === stripos( snt_morning_brief_compose_html( $base ), 'watch' ),
	'NO ripe watches emits NOTHING about watches — silence is the signal, not a daily "nothing yet"' );

$loud = snt_morning_brief_compose( array_merge( $base, array( 'watches' => array(
	array( 'id' => 'x', 'label' => 'reader-anomalies remote twin', 'note' => 'unchanged across 26 readings over 7.4 days', 'read' => 'sn-status{shape_stability}' ),
) ) ) );
ok( false !== strpos( $loud, 'Watch due: reader-anomalies remote twin' ), 'a ripe watch is named' );
ok( false !== strpos( $loud, '26 readings' ), 'with the evidence that ripened it, not a restatement of the label' );
ok( false !== strpos( $loud, 'sn-status{shape_stability}' ), 'and WHERE to read it, so the mail is actionable without a lookup' );

// One sentence per watch, so two ripe watches cannot collapse into one line.
$two = snt_morning_brief_compose( array_merge( $base, array( 'watches' => array(
	array( 'id' => 'a', 'label' => 'first', 'note' => 'n1', 'read' => 'r1' ),
	array( 'id' => 'b', 'label' => 'second', 'note' => 'n2', 'read' => 'r2' ),
) ) ) );
ok( 2 === substr_count( $two, 'Watch due:' ), 'each ripe watch gets its own sentence' );

// The subject line must reflect them, or a due watch arrives under a heading
// that says nothing needs attention.
$s_quiet = snt_morning_brief_subject( $base );
$s_loud  = snt_morning_brief_subject( array_merge( $base, array( 'watches' => array( array( 'id' => 'a', 'label' => 'l', 'note' => 'n', 'read' => 'r' ) ) ) ) );
ok( $s_quiet !== $s_loud, 'a ripe watch changes the SUBJECT — it must not arrive under "nothing needs attention"' );


// ─── 2026-10-22: the index_gap watch reads as one line per page ───────────
// Before: the watch note pasted verbatim, one paragraph of raw Search Console
// URLs, ending "click Request indexing.. Read it with ...".
$inspect = static fn( $p ) => 'https://search.google.com/search-console/inspect?resource_id=sc-domain%3Aexample.test&id=https%3A%2F%2Fexample.test' . rawurlencode( $p );
$GLOBALS['__report'] = array(
	'request_total' => 3,
	'request_list'  => array(
		array( 'path' => '/notes/a-note/', 'coverage_state' => 'Crawled - currently not indexed', 'days' => 21, 'inspect_url' => $inspect( '/notes/a-note/' ) ),
		array( 'path' => '/about/', 'coverage_state' => 'Discovered - currently not indexed', 'days' => 16, 'inspect_url' => $inspect( '/about/' ) ),
		array( 'path' => '/notes/<b>/', 'coverage_state' => 'URL is unknown to Google', 'days' => 9, 'inspect_url' => $inspect( '/notes/<b>/' ) ),
	),
);
$gap_note = '2 published notes or pages not indexed after 14 days: /notes/a-note/ (Crawled - currently not indexed, 21 days since publish; Bing crawled 2026-10-01): ' . $inspect( '/notes/a-note/' ) . ' | /about/ (Discovered - currently not indexed, 16 days since publish; Bing not read yet): ' . $inspect( '/about/' ) . '. The API cannot request indexing: open each link and click Request indexing.';
$GLOBALS['__ripe'] = array( array( 'id' => 'index_gap', 'label' => 'a published note or page is not indexed after 14 days', 'note' => $gap_note, 'read' => 'signal-noise/indexing-requests{request_list}' ) );
$gdata = snt_morning_brief_collect();
ok( 3 === count( $gdata['watches'][0]['rows'] ?? array() ) && 3 === ( $gdata['watches'][0]['rows_total'] ?? 0 ), 'collect attaches the ranked request list to a ripe index_gap watch' );
ok( 'Bing crawled 2026-10-01' === ( $gdata['watches'][0]['rows'][0]['bing'] ?? '' ), 'and keeps the Bing state the old note carried' );

$saved = $GLOBALS['__report'];
$GLOBALS['__report'] = 'throw';
$tdata = snt_morning_brief_collect();
ok( ! isset( $tdata['watches'][0]['rows'] ) && false !== strpos( snt_morning_brief_compose( $tdata ), 'a published note or page is not indexed after 14 days' ), 'a failing indexing report never stops the brief: the watch keeps its own note' );
$GLOBALS['__report'] = $saved;

$text  = snt_morning_brief_compose( $gdata );
$lines = explode( "\n", $text );
$page_lines = array_values( array_filter( $lines, static fn( $l ) => 0 === strpos( $l, '- /' ) ) );
ok( 3 === count( $page_lines ), 'TEXT: one line per page' );
ok( false !== strpos( $page_lines[0], '/notes/a-note/: Crawled - currently not indexed, 21 days since publish, Bing crawled 2026-10-01' ), 'TEXT: each line reads path, Google state, days, Bing' );
$url_lines = array_values( array_filter( $lines, static fn( $l ) => false !== strpos( $l, 'https://' ) ) );
ok( 3 === count( $url_lines ) && array() === array_filter( $url_lines, static fn( $l ) => 0 !== strpos( $l, '  Inspect: https://' ) ), 'TEXT: every URL sits on its own short "Inspect:" line, never mid-sentence' );
ok( false === strpos( $text, '..' ), 'TEXT: no double period anywhere' );
ok( false !== strpos( $text, 'Integrity › Reports' ), 'TEXT: points at the request list in Integrity › Reports' );
ok( false === strpos( $text, ' | ' ), 'TEXT: the pasted note (pipe-joined) is gone' );

$html = snt_morning_brief_compose_html( $gdata );
ok( 3 === substr_count( $html, '<li>' ), 'HTML: one list item per page' );
ok( 3 === substr_count( $html, '>Inspect</a>' ), 'HTML: each page has a short labeled Inspect link' );
ok( false === strpos( strip_tags( $html ), 'https://' ), 'HTML: no raw URL shows as text' );
ok( false !== strpos( $html, 'href="https://search.google.com/search-console/inspect?resource_id=sc-domain%3Aexample.test&amp;id=' ), 'HTML: the href is escaped' );
ok( false !== strpos( $html, '/notes/&lt;b&gt;/' ) && false === strpos( $html, '/notes/<b>/' ), 'HTML: paths are escaped' );
ok( false === strpos( $html, '..' ), 'HTML: no double period' );
ok( false !== strpos( $html, '<html lang="en">' ), 'HTML: the document declares its language' );

// Any other watch whose note carries a URL: the link moves off the sentence.
$other = array_merge( $base, array( 'watches' => array(
	array( 'id' => 'x', 'label' => 'a ticket moved', 'note' => 'see https://core.trac.wordpress.org/ticket/65551.', 'read' => 'https://core.trac.wordpress.org/ticket/65551' ),
) ) );
$ot = snt_morning_brief_compose( $other );
ok( false === strpos( $ot, '..' ) && 0 === count( array_filter( explode( "\n", $ot ), static fn( $l ) => false !== strpos( $l, 'https://' ) && ! preg_match( '/^\s+\[\d+\] https:/', $l ) ) ), 'TEXT: a URL in any watch note or read moves to its own footnote line' );
$oh = snt_morning_brief_compose_html( $other );
ok( false !== strpos( $oh, '>core.trac.wordpress.org</a>' ) && false === strpos( strip_tags( $oh ), 'https://' ), 'HTML: and becomes a link labeled by its host' );

// The send: HTML body, plain-text part via core's phpmailer_init, hook removed after.
$GLOBALS['__mail'] = array();
snt_morning_brief_send();
$m = $GLOBALS['__mail'][0];
ok( false !== stripos( implode( "\n", (array) $m['headers'] ), 'Content-Type: text/html' ), 'SEND: the body goes out as HTML' );
ok( 0 === strpos( ltrim( $m['body'] ), '<!DOCTYPE html>' ) && false !== strpos( $m['alt'], '- /notes/a-note/:' ), 'SEND: the plain-text part rides along as the alternative' );
ok( array() === ( $GLOBALS['__hooks']['phpmailer_init'] ?? array() ), 'SEND: the plain-text hook is removed, so it cannot leak into another email' );
$GLOBALS['__ripe'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
