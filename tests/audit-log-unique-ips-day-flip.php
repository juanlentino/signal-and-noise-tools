<?php
/**
 * Regression test (#1225): the audit-log unique-IP transient set never
 * reset at a day flip.
 *
 * snt_audit_increment_counter_impl() refreshed the transient's 25h TTL on
 * every new IP seen, so under steady daily traffic the set never expired —
 * a returning IP from yesterday matched today's "already seen" check and
 * was undercounted as unique, and the transient grew without bound.
 *
 * Fix: the transient is now shaped { day, ips }, reset whenever the stored
 * day no longer matches today — independent of the TTL.
 *
 * Also pins the #1225 label-wording fix: the hero cards read "since site
 * midnight", not a rolling 24h window, so they must say "today", not "24h".
 *
 * @since plugin (fix for #1225)
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$pass = 0;
$fail = 0;
function check( $condition, $label ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "PASS: $label\n"; }
	else { ++$fail; echo "FAIL: $label\n"; }
}

$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__today']      = '2026-06-10';

function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['__transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['__transients'][ $key ] = $value; return true; }
function wp_salt( $type ) { return 'fixture-salt'; }
function wp_date( $format ) { return $GLOBALS['__today']; }
function wp_unslash( $v ) { return $v; }
function sn_setting( $path, $default = null ) { return $default; }
function number_format_i18n( $n ) { return number_format( $n ); }
function esc_html( $s ) { return $s; }
function add_action( $hook, $cb = null, $p = 10, $a = 1 ) {}
function wp_next_scheduled( $hook ) { return true; } // no-op: prune cron already "scheduled"
function wp_schedule_event( $ts, $recur, $hook ) {}

require __DIR__ . '/../inc/audit-log.php';
require __DIR__ . '/../inc/audit-log-admin.php';

// ── Day 1: two distinct IPs, one repeated ──────────────────────────────
snt_audit_increment_counter_impl( 'login_failed', '1.1.1.1' );
snt_audit_increment_counter_impl( 'login_failed', '2.2.2.2' );
snt_audit_increment_counter_impl( 'login_failed', '1.1.1.1' ); // repeat, not unique

$blob = snt_audit_get_blob();
check( 2 === (int) ( $blob['counters']['2026-06-10']['unique_ips_count'] ?? 0 ), 'day 1: two distinct IPs counted once each' );

// ── Day flip: same IP returns on day 2 — must count as unique again ────
$GLOBALS['__today'] = '2026-06-11';
snt_audit_increment_counter_impl( 'login_failed', '1.1.1.1' );

$blob = snt_audit_get_blob();
check( 1 === (int) ( $blob['counters']['2026-06-11']['unique_ips_count'] ?? 0 ), 'day 2: a returning IP from day 1 is counted as unique again (set reset on day flip)' );
check( 2 === (int) ( $blob['counters']['2026-06-10']['unique_ips_count'] ?? 0 ), 'day 1 total is untouched by the day-2 write' );

$set = get_transient( SN_AUDIT_TRANSIENT_IPS );
check( is_array( $set ) && '2026-06-11' === ( $set['day'] ?? null ), 'the transient itself is stamped with the current day' );
check( 1 === count( $set['ips'] ?? array() ), 'the transient ip set holds only day-2 entries, not an accumulation across days' );

// ── Label wording: hero cards say "today", not a rolling "24h" ─────────
$summary = array(
	'last_24h'             => array( 'all_total' => 3, 'failed_total' => 3, 'recon_total' => 0 ),
	'last_7d_vs_prior'     => array( 'current' => 3, 'prior' => 0, 'pct_delta' => 0 ),
	'unique_attackers_24h' => 2,
	'lla'                  => array( 'active_lockouts' => 0 ),
);
$cards  = snt_audit_log_glance_cards( $summary );
$labels = array_column( $cards, 'label' );
check( in_array( 'Today', $labels, true ), 'the volume card is labeled "Today" (since-midnight, not rolling 24h)' );
check( in_array( 'Unique IPs (today)', $labels, true ), 'the unique-IPs card is labeled "Unique IPs (today)"' );
check( ! in_array( 'Last 24h', $labels, true ) && ! in_array( 'Unique IPs (24h)', $labels, true ), 'no card claims a rolling 24h window' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
