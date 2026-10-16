<?php
/**
 * Native window leaf: Security → Audit log (apps/sn-dashboard/parts/leaves/security-audit-log.php).
 *
 * The oracle is the classic leaf (`snt_audit_log_render_tab()`,
 * inc/audit-log-admin.php): the kit port must carry the same two field names
 * / sn_action values, the same numbers off the same readers, and none of
 * wp-admin's markup.
 *
 * Run: php tests/os-leaf-security-audit-log.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers.
$GLOBALS['__retention'] = 90;
function sn_setting( $key, $default = '' ) {
	if ( 'audit.retention_days' === $key ) {
		return $GLOBALS['__retention'];
	}
	return $default;
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = true ) { $GLOBALS['__options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { return true; }
}

// The classic renderer's own layout/hero helpers — not this leaf's concern
// (the kit port paints the same readings in one linear column; see the
// leaf file's header comment), but the classic file needs them to load.
if ( ! function_exists( 'sn_admin_shell_open' ) ) { function sn_admin_shell_open() { echo '<div class="sn-shell-main">'; } }
if ( ! function_exists( 'sn_admin_shell_rail' ) ) { function sn_admin_shell_rail( $title = '' ) { echo '</div><div class="sn-shell-rail">'; if ( '' !== $title ) { echo '<h2>' . esc_html( $title ) . '</h2>'; } } }
if ( ! function_exists( 'sn_admin_shell_close' ) ) { function sn_admin_shell_close() { echo '</div>'; } }
if ( ! function_exists( 'sn_admin_glance_grid' ) ) {
	function sn_admin_glance_grid( $cards ) {
		echo '<div class="sn-glance">';
		foreach ( $cards as $card ) {
			echo '<div class="sn-glance-card"><p>' . esc_html( (string) ( $card['label'] ?? '' ) ) . '</p><p>' . esc_html( (string) ( $card['value'] ?? '' ) ) . '</p></div>';
		}
		echo '</div>';
	}
}

require SNT_PATH . 'inc/audit-log.php';
require SNT_PATH . 'inc/audit-log-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/security-audit-log.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
// 2026-10-16: the one earned fold is the Setup card's Retention (a setting,
// its summary the window; tests/sn-dashboard-no-folds.php lists it). Any
// other fold, the logins ledger above all, is still a failure.
function al_unearned_folds( $html ) {
	return snt_leaf_folds( preg_replace( '/<os-disclosure heading="Retention" hint="[0-9,]+ days?"/', '', (string) $html, 1 ) );
}

/** One day key, matching how the readers compute it (today = 0). */
function day_key( $i ) { return gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ); }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['security/audit-log'] ), 'the painter is registered under security/audit-log' );

// ── Rich fixture: one populated day-bucket, three logins (one hostile), two active lockouts.
$GLOBALS['__options']['sn_audit_log_v1'] = array(
	'schema_version' => 1,
	'created_at'     => time(),
	'counters'       => array(
		day_key( 0 ) => array(
			'login_failed'        => 5,
			'mfa_failed'          => 2,
			'mfa_throttled'       => 1,
			'mfa_other'           => 1,
			'wp_login_404'        => 3,
			'wp_admin_unauth_404' => 1,
			'lockout_triggered'   => 0,
			'password_reset'      => 0,
			'unique_ips_count'    => 2,
		),
	),
	'login_success'  => array(
		array( 'ts' => time() - 3600, 'user' => 'alice' ),
		array( 'ts' => time() - 7200, 'user' => 'bob' ),
		array( 'ts' => time() - 10800, 'user' => 'carol' ),
	),
);
// LLA stores each lockout's END time and drops expired ones only at its next
// cleanup (LocalLockoutManager, LockoutCleanupService): two still running, one over.
$GLOBALS['__options']['limit_login_lockouts'] = array( '10.0.0.1' => time() + 600, '10.0.0.2' => time() + 1200, '10.0.0.3' => time() - 500 );
$GLOBALS['__options']['limit_login_lockouts_total'] = 7; // LLA's own running count of every lockout
$GLOBALS['__options']['sn_audit_lla_last_lockouts_total'] = 5;

$classic = snt_leaf_classic_html( 'snt_audit_log_render_tab' );
$kit     = snt_leaf_paint( 'security', 'audit-log' );

ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'audit_prune_now', 'audit_save_retention' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'both actions offered, matching the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Glance hero: last-24h totals, 7d trend, unique IPs, LLA status.
ok( false !== strpos( $kit, 'value="9"' ) && false !== strpos( $kit, 'caption="5 failed · 4 recon"' ), 'last-24h totals: 9 all, "5 failed · 4 recon"' );
// 2026-10-12 audit: growth from zero read "+100%", a percentage of nothing.
ok( false !== strpos( $kit, 'value="new" label="Last 7 days"' ) && false !== strpos( $kit, 'caption="9 vs 0 the 7 days before · new"' ) && false === strpos( $kit, '+100%' ), 'the 7-day trend from a zero baseline reads "new (9 vs 0)", never +100%, pill folded into the caption' );
ok( false !== strpos( $kit, 'tone="warning"' ), 'the new activity paints a warning pill' );
ok( false !== strpos( $kit, 'value="2"' ), 'unique attackers (24h) and active lockouts both read 2' );
ok( false !== strpos( $kit, 'locked' ) && false !== strpos( $kit, 'value="2" label="Lockouts now"' ), 'two lockouts still running (of three entries, one over) paint the "locked" pill' );
ok( false !== strpos( $kit, '<os-fact label="Latest lockout ends">' ) && false !== strpos( $classic, 'Latest lockout ends: <strong>' ) && false === strpos( $kit, 'Most recent lockout' ), 'the latest timestamp is said as the END of a lockout, on both twins' );
ok( false !== strpos( $kit, 'Lockouts (polled daily)' ) && false !== strpos( $classic, 'Lockouts (polled daily)' ), 'the Lockouts column says it is polled daily, on both twins' );
ok( false === strpos( $kit . $classic, 'login_success' ) && false === strpos( $kit . $classic, 'counter bucket' ) && false === strpos( $kit . $classic, 'ephemeral transient' ) && false === strpos( $kit, 'limit-login-attempts-reloaded</p>' ), 'no developer words on either twin: no login_success, counter buckets, ephemeral transient, or a bare plugin slug' );

// ── Counter timeline table: the same day-bucket, the same counts.
ok( false !== strpos( $kit, '&quot;mfa_detail&quot;:&quot;2 / 1 / 1&quot;' ), 'native audit renders populated MFA subsets' );
ok( false !== strpos( $kit, 'included in Failed' ) && false !== strpos( $classic, 'included in Failed' ), 'both surfaces explain subsets and legacy coverage' );
ok( false !== strpos( $kit, '&quot;login_failed&quot;:5' ) && false !== strpos( $kit, '&quot;wp_login_404&quot;:3' ) && false !== strpos( $kit, '&quot;wp_admin_unauth_404&quot;:1' ), 'the counter timeline carries the populated day-bucket counts' );

// ── Recent logins: all three users; 2026-10-11 (no folds) the count that
// rode the fold's heading is the section's finding sentence, open.
ok( false !== strpos( $kit, 'alice' ) && false !== strpos( $kit, 'bob' ) && false !== strpos( $kit, 'carol' ), 'all three recent logins are listed' );
ok( array() === al_unearned_folds( $kit ), 'no fold on the populated paint: ' . implode( ',', al_unearned_folds( $kit ) ) );
$al_newest = gmdate( 'Y-m-d H:i:s', $GLOBALS['__options']['sn_audit_log_v1']['login_success'][0]['ts'] ); // the fixture's own moment, never a second call to time()
ok( false !== strpos( $kit, '<p class="snt-prose">3 successful logins in the window, by 3 users; the newest was ' . $al_newest . ', by alice.</p>' ), 'the finding sentence carries the true count, the distinct users and the newest login, from the rows' );
ok( false !== strpos( $kit, '<os-section heading="Recent successful logins (last 30 days)"' ), 'the populated logins section keeps its classic heading' );
ok( false !== strpos( $kit, 'description="The store keeps at most 500 successful logins, dropping the oldest first' ), 'the store cap (500), once inside the fold, is the section description' );
$al_tables = snt_leaf_tables( $kit );
$al_log    = array_values( array_filter( $al_tables, static function ( $t ) { return array( 'Timestamp', 'User' ) === array_column( $t['columns'], 'label' ); } ) );
ok( 1 === count( $al_log ) && 3 === count( $al_log[0]['data'] ) && 'alice' === $al_log[0]['data'][0]['user'], 'the log is one Timestamp/User table, newest first, every row under the cap' );
ok( false === strpos( $kit, 'snt-ledger-more' ), 'under the cap: no "+N more" line' );

// ── Classic-heading parity: every sn-fieldset-h / sn-callout-h string in the
// classic markup shows up somewhere in the kit output.
preg_match_all( '/class="sn-(?:fieldset|callout)-h">([^<]+)</', $classic, $classic_headings );
foreach ( $classic_headings[1] as $heading ) {
	ok( false !== strpos( $kit, $heading ), "classic heading '" . $heading . "' survives in the kit output" );
}

// ── LLA card: active lockouts + door to the LLA settings screen (not a raw <a>).
ok( false !== strpos( $kit, 'Active lockouts' ) && false !== strpos( $kit, 'os-arg-url="https://example.test/wp-admin/admin.php?page=limit-login-attempts"' ), 'the LLA card links out via a door, not a raw admin URL' );

// ── Retention form: same field, same value, same handler.
ok( false !== strpos( $kit, '<os-number-field name="audit_retention_days" value="90"' ), 'the retention field carries the current value' );
ok( 1 === substr_count( $kit, '<os-disclosure' ) && false !== strpos( $kit, 'heading="Setup"' ) && 1 === preg_match( '/<os-disclosure heading="Retention" hint="90 days"[^>]*data-snt-open="0"><os-stack gap="12"><os-form[^>]*submit-label="Save retention"/', $kit ), 'Retention is the one fold, in a Setup card: closed, its summary the window (90 days), the form inside' );
ok( 1 === preg_match( '/<p class="snt-prose">Run the daily cleanup now[^<]*<\/p><os-form class="snt-form snt-form--inline"[^>]*submit-label="Prune now"/', $kit ), 'Prune now is the compact one-button form under its sentence, not a full-width form row' );

// ── Maintenance: prune form (inline pipeline) + both export doors, nonce intact.
$prune_is_inline_form = false !== strpos( $kit, 'os-arg-pipeline="inline"' ) && false === strpos( $kit, 'os-arg-action="sn_audit_prune_now"' );
ok( $prune_is_inline_form, 'the prune form is inline-pipelined (its sn_action is a hidden field, not a button arg)' );
ok( false !== strpos( $kit, 'Export JSON' ) && false !== strpos( $kit, 'action=sn_audit_export&amp;format=json' ) && false !== strpos( $kit, 'sn_audit_export_nonce=nonce-sn_audit_export' ), 'the JSON export door carries the nonced admin-post URL' );
ok( false !== strpos( $kit, 'Export CSV' ) && false !== strpos( $kit, 'action=sn_audit_export&amp;format=csv' ), 'the CSV export door is offered too' );

// ── Escaping: a hostile username never reaches the markup raw.
$GLOBALS['__options']['sn_audit_log_v1']['login_success'][2]['user'] = '"><script>x</script>';
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ) && array() === al_unearned_folds( $kit ), 'a hostile login username is escaped, and nothing folds' );
$GLOBALS['__options']['sn_audit_log_v1']['login_success'][2]['user'] = 'carol';

// ── Empty state: no successful logins in the window.
$saved_logins = $GLOBALS['__options']['sn_audit_log_v1']['login_success'];
$GLOBALS['__options']['sn_audit_log_v1']['login_success'] = array();
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( false !== strpos( $kit, 'No successful logins recorded in this window.' ) && array() === al_unearned_folds( $kit ), 'the empty-logins state paints the classic empty message, no fold' );
ok( false !== strpos( $kit, 'Recent successful logins (last 30 days)' ), 'the empty-logins state ALSO keeps the classic section heading' );
$GLOBALS['__options']['sn_audit_log_v1']['login_success'] = $saved_logins;

// ── AL1 display cap: 52 logins (over SN_AUDIT_LOGIN_DISPLAY_MAX = 50), with
// distinct users and ASCENDING ts, so an unsorted slice would keep the
// OLDEST 50 instead of the newest — proving the newest-first re-sort really
// runs, not just that a no-op slice happens to pass.
$many = array();
for ( $i = 0; $i < 52; $i++ ) {
	// Ascending ts, all well within the 30-day window: oldest first ($i=0),
	// newest last ($i=51) — so an unsorted slice would keep the WRONG end.
	$many[] = array( 'ts' => time() - ( 52 - $i ) * 60, 'user' => 'user' . $i );
}
$GLOBALS['__options']['sn_audit_log_v1']['login_success'] = $many;
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( false !== strpos( $kit, '<p class="snt-prose">52 successful logins in the window, by 52 users; the newest was ' ) && array() === al_unearned_folds( $kit ), 'the finding reads the true count of 52, not the 50 painted, and nothing folds' );
ok( false !== strpos( $kit, '&quot;user51&quot;' ), 'the newest login (highest ts) survives the display cap' );
ok( false === strpos( $kit, '&quot;user0&quot;' ) && false === strpos( $kit, '&quot;user1&quot;' ), 'the two oldest logins are dropped by the display cap, not kept by an unsorted slice' );
ok( false !== strpos( $kit, '<p class="snt-hint snt-ledger-more">+2 more logins; the list is capped, not complete. Newest first, so the tail is the oldest end.</p>' ), 'the "+2 more" remainder is reported on the kit more line, no em dash' );
ok( 50 === substr_count( $kit, '&quot;user&quot;:&quot;' ), 'exactly 50 login rows are painted, matching SN_AUDIT_LOGIN_DISPLAY_MAX' );
// Exactly at the cap: nothing is cut, so no "+N more" line.
$GLOBALS['__options']['sn_audit_log_v1']['login_success'] = array_slice( $many, 2 );
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( 50 === substr_count( $kit, '&quot;user&quot;:&quot;' ) && false === strpos( $kit, 'snt-ledger-more' ) && false !== strpos( $kit, '50 successful logins in the window' ), 'exactly 50 logins: all painted, no "+N more" line' );
$GLOBALS['__options']['sn_audit_log_v1']['login_success'] = $saved_logins;

// ── Clear state: no active lockouts paints "clear", not "locked".
$saved_lockouts = $GLOBALS['__options']['limit_login_lockouts'];
$GLOBALS['__options']['limit_login_lockouts'] = array();
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( false !== strpos( $kit, 'clear' ) && false === strpos( $kit, 'locked' ) && array() === al_unearned_folds( $kit ), 'zero active lockouts paints the "clear" LLA pill instead of "locked", and nothing folds' );
$GLOBALS['__options']['limit_login_lockouts'] = $saved_lockouts;

// ── The prune-now action, handled inline: paints the same stats notice the
// classic leaf echoes, without ever going through the shared PRG.
$kit = snt_leaf_paint( 'security', 'audit-log', array( 'post' => array( 'sn_action' => 'audit_prune_now' ) ) );
ok( false !== strpos( $kit, 'Prune complete.' ) && false !== strpos( $kit, '0 days of counts deleted, 0 login records deleted, and 2 new lockouts from Limit Login Attempts.' ) && false !== strpos( $kit, 'tone="success"' ) && array() === al_unearned_folds( $kit ), 'the inline prune-now paints a success notice with the real stats in words (2 new lockouts: LLA\'s total went from 5 to 7), and nothing folds' );

// ── The inline pipeline must not re-fire the prune on a second paint of the
// SAME state object — the state contract is ONE paint's $_POST. Reproduces
// the refuter's repro: call the painter twice against one $ctx['state']
// carrying the post, and the destructive prune must run only once.
class SNT_Test_State_AuditLog {
	private $v;
	public function __construct( array $v ) { $this->v = $v; }
	public function get( $k ) { return $this->v[ $k ] ?? null; }
	public function set( $k, $x ) { $this->v[ $k ] = $x; return $this; }
}
$prune_calls_before = $GLOBALS['__audit_prune_calls'] ?? 0;
$shared_state        = new SNT_Test_State_AuditLog( array( 'post' => array( 'sn_action' => 'audit_prune_now' ) ) );
$ctx_shared           = array( 'tab' => 'security', 'sub' => 'audit-log', 'state' => $shared_state, 'os' => array() );
$painter              = \SignalNoise\OpenStationHost\Dashboard\painters()['security/audit-log'];
$first_paint  = call_user_func( $painter, $ctx_shared );
$second_paint = call_user_func( $painter, $ctx_shared );
ok( false !== strpos( $first_paint, 'Prune complete.' ), 'the first paint against the shared state runs the prune' );
ok( false === strpos( $second_paint, 'Prune complete.' ), 'a second paint against the SAME state object does not re-run the prune' );
ok( array() === $shared_state->get( 'post' ), 'the leaf clears the consumed post from state after handling it' );
ok( array() === al_unearned_folds( $first_paint ) && array() === al_unearned_folds( $second_paint ), 'neither shared-state paint folds anything' );


// ── Siblings pair; a stack is for steps. ──
// 2026-10-11 (no folds): the logins log is an open table of up to 50 rows,
// several times the LLA card's height, so it stands alone at full width and
// only the two controls (retention, maintenance) still share a row.
$kitP = snt_leaf_paint( 'security', 'audit-log' );
// 2026-10-12 audit: the lockouts box stood alone at full width; it joins the two controls.
ok(
	1 === substr_count( $kitP, snt_leaf_row( 3, 260 ) ),
	'the lockouts box and the two controls are painted in one row of three -- ' . substr_count( $kitP, snt_leaf_row( 3, 260 ) )
);
$al_pair = substr( $kitP, (int) strpos( $kitP, snt_leaf_row( 3, 260 ) ) );
$al_pair = substr( $al_pair, 0, (int) strpos( $al_pair, '</os-grid>' ) );
ok( false === strpos( $al_pair, 'Recent successful logins' ) && false !== strpos( $al_pair, 'heading="Login lockouts (Limit Login Attempts)"' ) && false !== strpos( $al_pair, 'heading="Retention"' ) && false !== strpos( $al_pair, 'heading="Maintenance"' ), 'the logins ledger is not in the row; the row is lockouts, retention and maintenance' );
ok( 1 === preg_match( '/<os-form[^>]*os-confirm="Delete the audit records older than 90 days now\? This cannot be undone\."[^>]*os-confirm-danger/', $kitP ) && false !== strpos( snt_leaf_classic_html( 'snt_audit_log_render_tab' ), 'data-snt-confirm="Delete the audit records older than 90 days now? This cannot be undone." data-snt-confirm-danger="1">Prune now' ), 'Prune now asks first, on both twins (it deletes rows)' );

// ── Live-shaped preview: ~180 successful logins by two users, a few failed.
$al_live = array();
for ( $i = 0; $i < 180; $i++ ) {
	$al_live[] = array( 'ts' => time() - $i * 3 * 3600 - 600, 'user' => 0 === $i % 9 ? 'editor' : 'juan' );
}
$GLOBALS['__options']['sn_audit_log_v1']['login_success']                  = $al_live;
$GLOBALS['__options']['sn_audit_log_v1']['counters'][ day_key( 0 ) ]['login_failed'] = 3;
$kitL = snt_leaf_paint( 'security', 'audit-log' );
ok( array() === al_unearned_folds( $kitL ) && false !== strpos( $kitL, '180 successful logins in the window, by 2 users' ) && false !== strpos( $kitL, '+130 more logins; the list is capped' ), 'live-shaped: 180 logins read as a finding over 50 rows and "+130 more", no fold' );
snt_leaf_preview( 'security-audit-log', $kitL );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
