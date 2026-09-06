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
$GLOBALS['__options']['limit_login_lockouts'] = array( time() - 100, time() - 500 );

$classic = snt_leaf_classic_html( 'snt_audit_log_render_tab' );
$kit     = snt_leaf_paint( 'security', 'audit-log' );

ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'audit_prune_now', 'audit_save_retention' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'both actions offered, matching the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Glance hero: last-24h totals, 7d trend, unique IPs, LLA status.
ok( false !== strpos( $kit, 'value="9"' ) && false !== strpos( $kit, 'caption="5 failed · 4 recon"' ), 'last-24h totals: 9 all, "5 failed · 4 recon"' );
ok( false !== strpos( $kit, 'value="+100%"' ) && false !== strpos( $kit, 'caption="9 vs 0 prior · rising"' ), 'the 7d-vs-prior trend is shown (from-zero growth reads as +100%), pill folded into the caption' );
ok( false !== strpos( $kit, 'rising' ) && false !== strpos( $kit, 'tone="warning"' ), 'the rising trend paints a warning pill' );
ok( false !== strpos( $kit, 'value="2"' ), 'unique attackers (24h) and active lockouts both read 2' );
ok( false !== strpos( $kit, 'locked' ), 'two active lockouts paint the "locked" LLA pill' );

// ── Counter timeline table: the same day-bucket, the same counts.
ok( false !== strpos( $kit, '&quot;login_failed&quot;:5' ) && false !== strpos( $kit, '&quot;wp_login_404&quot;:3' ) && false !== strpos( $kit, '&quot;wp_admin_unauth_404&quot;:1' ), 'the counter timeline carries the populated day-bucket counts' );

// ── Recent logins: all three users, newest-first count in the disclosure heading.
ok( false !== strpos( $kit, 'alice' ) && false !== strpos( $kit, 'bob' ) && false !== strpos( $kit, 'carol' ), 'all three recent logins are listed' );
ok( false !== strpos( $kit, '3 successful logins' ), 'the disclosure heading carries the true count' );
ok( false !== strpos( $kit, 'Recent successful logins (last 30 days)' ), 'the populated logins card keeps its classic section heading (not just a bare disclosure)' );

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

// ── Maintenance: prune form (inline pipeline) + both export doors, nonce intact.
$prune_is_inline_form = false !== strpos( $kit, 'os-arg-pipeline="inline"' ) && false === strpos( $kit, 'os-arg-action="audit_prune_now"' );
ok( $prune_is_inline_form, 'the prune form is inline-pipelined (its sn_action is a hidden field, not a button arg)' );
ok( false !== strpos( $kit, 'Export JSON' ) && false !== strpos( $kit, 'action=sn_audit_export&amp;format=json' ) && false !== strpos( $kit, 'sn_audit_export_nonce=nonce-sn_audit_export' ), 'the JSON export door carries the nonced admin-post URL' );
ok( false !== strpos( $kit, 'Export CSV' ) && false !== strpos( $kit, 'action=sn_audit_export&amp;format=csv' ), 'the CSV export door is offered too' );

// ── Escaping: a hostile username never reaches the markup raw.
$GLOBALS['__options']['sn_audit_log_v1']['login_success'][2]['user'] = '"><script>x</script>';
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile login username is escaped' );
$GLOBALS['__options']['sn_audit_log_v1']['login_success'][2]['user'] = 'carol';

// ── Empty state: no successful logins in the window.
$saved_logins = $GLOBALS['__options']['sn_audit_log_v1']['login_success'];
$GLOBALS['__options']['sn_audit_log_v1']['login_success'] = array();
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( false !== strpos( $kit, 'No successful logins recorded in this window.' ) && false === strpos( $kit, '<os-disclosure' ), 'the empty-logins state paints the classic empty message, no disclosure' );
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
ok( false !== strpos( $kit, '52 successful logins' ), 'the disclosure heading reads the true count of 52' );
ok( false !== strpos( $kit, '&quot;user51&quot;' ), 'the newest login (highest ts) survives the display cap' );
ok( false === strpos( $kit, '&quot;user0&quot;' ) && false === strpos( $kit, '&quot;user1&quot;' ), 'the two oldest logins are dropped by the display cap, not kept by an unsorted slice' );
ok( false !== strpos( $kit, '+2 more logins' ), 'the "+2 more" remainder is reported' );
ok( 50 === substr_count( $kit, '&quot;user&quot;:&quot;' ), 'exactly 50 login rows are painted, matching SN_AUDIT_LOGIN_DISPLAY_MAX' );
$GLOBALS['__options']['sn_audit_log_v1']['login_success'] = $saved_logins;

// ── Clear state: no active lockouts paints "clear", not "locked".
$saved_lockouts = $GLOBALS['__options']['limit_login_lockouts'];
$GLOBALS['__options']['limit_login_lockouts'] = array();
$kit = snt_leaf_paint( 'security', 'audit-log' );
ok( false !== strpos( $kit, 'clear' ) && false === strpos( $kit, 'locked' ), 'zero active lockouts paints the "clear" LLA pill instead of "locked"' );
$GLOBALS['__options']['limit_login_lockouts'] = $saved_lockouts;

// ── The prune-now action, handled inline: paints the same stats notice the
// classic leaf echoes, without ever going through the shared PRG.
$kit = snt_leaf_paint( 'security', 'audit-log', array( 'post' => array( 'sn_action' => 'audit_prune_now' ) ) );
ok( false !== strpos( $kit, 'Prune complete.' ) && false !== strpos( $kit, 'LLA delta +2' ) && false !== strpos( $kit, 'tone="success"' ), 'the inline prune-now paints a success notice with the real stats' );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
