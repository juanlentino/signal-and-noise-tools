<?php
/**
 * Native window leaf: Security → Login defense (apps/sn-dashboard/parts/leaves/security-login-defense.php).
 *
 * The oracle is the classic leaf (inc/login-defense.php `sn_login_defense_render()`,
 * which mounts inc/security-digest.php `snt_security_digest_render_settings()`
 * after its status card): the kit port must carry the same field names, the
 * same one `sn_action` (`security_digest_save`), the same readouts, and none
 * of wp-admin's markup.
 *
 * Run: php tests/os-leaf-security-login-defense.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── The leaf's own readers, controlled by test fixtures.

// sn_login_defense_status()'s network probe: wp_safe_remote_get() et al.
$GLOBALS['__lg_remote'] = null; // null => probe fails (500/empty body); array => 200 + json body.
function wp_http_validate_url( $url ) { return true; }
function is_wp_error( $v ) { return false; }
function wp_safe_remote_get( $url, $args = array() ) {
	if ( null === $GLOBALS['__lg_remote'] ) {
		return array( 'code' => 500, 'body' => '' );
	}
	return array( 'code' => 200, 'body' => wp_json_encode( $GLOBALS['__lg_remote'] ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

// sn_setting()/snt_analytics_page_url(): shared with the classic digest form.
$GLOBALS['__settings'] = array();
function sn_setting( $key, $default = null ) { return array_key_exists( $key, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $key ] : $default; }
function snt_analytics_page_url( $args = array() ) {
	$url = admin_url( 'admin.php?page=sn-analytics' );
	return array() !== (array) $args ? $url . '&' . http_build_query( $args ) : $url;
}

require SNT_PATH . 'inc/login-defense.php';
require SNT_PATH . 'inc/security-digest.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/security-login-defense.php';
require_once SNT_PATH . 'inc/openstation-host-pipelines.php'; // for snt_os_host_expand() — the round-trip pin below.

/**
 * Mirror of os-form.ts's `_readField()` for the one field this leaf cares
 * about: OS-CHECKBOX-LABEL (and OS-CHECKBOX / INPUT[checkbox]) read their
 * boolean off the `checked` attribute; every other tag (OS-SWITCH included)
 * falls through to its static `value` attribute regardless of on/off state
 * (os-form.ts:536-556). Extracts the tag whose attributes contain
 * `name="$name"` and returns bool for a checkbox-shaped tag, else the string
 * `value="…"`.
 */
function os_form_read_field( $html, $name ) {
	if ( ! preg_match( '/<([a-z-]+)([^>]*\bname="' . preg_quote( $name, '/' ) . '"[^>]*)>/i', $html, $m ) ) {
		return null;
	}
	$tag   = strtoupper( $m[1] );
	$attrs = $m[2];
	$is_checkbox_shaped = in_array( $tag, array( 'OS-CHECKBOX', 'OS-CHECKBOX-LABEL' ), true )
		|| ( 'INPUT' === $tag && false !== strpos( $attrs, 'type="checkbox"' ) );
	if ( $is_checkbox_shaped ) {
		return (bool) preg_match( '/(^|\s)checked(\s|=|$|>)/', $attrs );
	}
	if ( preg_match( '/\bvalue="([^"]*)"/', $attrs, $vm ) ) {
		return $vm[1];
	}
	return null;
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['security/login-defense'] ), 'the painter is registered under security/login-defense' );

/**
 * Set the digest option fixtures directly (get_option() reads $GLOBALS['__options']).
 */
function set_digest_options( $enabled, $last_sent, $last_error ) {
	$GLOBALS['__settings']['audit.digest_email_enabled'] = $enabled;
	$GLOBALS['__options'][ SN_SECURITY_DIGEST_LAST_SENT ]  = $last_sent;
	$GLOBALS['__options'][ SN_SECURITY_DIGEST_LAST_ERROR ] = $last_error;
}

// ── Rich fixture: a configured worker status + an enabled, previously-sent digest.
$GLOBALS['__lg_remote'] = array(
	'version'       => '1.7.0',
	'deployed_at'   => '2026-09-01T00:00:00Z',
	'denylistCount' => 1234,
	'compiledAt'    => '2026-09-05T00:00:00Z',
);
set_digest_options( true, time() - 3600, false );

$classic = snt_leaf_classic_html( 'sn_login_defense_render' );
$kit     = snt_leaf_paint( 'security', 'login-defense' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'security_digest_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is security_digest_save, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

ok( false !== strpos( $kit, 'sn-login-guard v1.7.0' ) && false !== strpos( $kit, '2026-09-01T00:00:00Z' ), 'the worker version + deploy time are shown' );
ok( false !== strpos( $kit, '1,234' ) && false !== strpos( $kit, '2026-09-05T00:00:00Z' ), 'the denylist count + refresh time are shown' );
ok( false !== strpos( $kit, 'Spamhaus' ), 'the FireHOL/Spamhaus attribution survives' );
ok( false !== strpos( $kit, 'os-action="door"' ) && false !== strpos( $kit, 'page=sn-analytics' ) && false !== strpos( $kit, 'sn_view=login-defense' ), 'the analytics link is a door to the other admin screen' );
ok( false !== strpos( $kit, 'name="sn_digest_enabled"' ) && false !== strpos( $kit, 'checked' ), 'the digest toggle is a kit switch, checked when enabled' );
ok( false !== strpos( $kit, '1 hour' ), 'the last-sent readout survives (human_time_diff stub)' );
ok( false !== strpos( $kit, 'Save' ) && false !== strpos( $kit, 'Send test digest' ), 'both digest buttons survive' );
ok( false !== strpos( $kit, 'name="sn_digest_test"' ), 'the test button carries its differentiator field' );

// ── Escaping: a hostile worker string / error message never reaches the markup raw.
$GLOBALS['__lg_remote']['deployed_at'] = '"><script>x</script>';
set_digest_options( true, time() - 3600, array( 'message' => '<script>y</script>' ) );
$kit = snt_leaf_paint( 'security', 'login-defense' );
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, '&lt;script&gt;x&lt;/script&gt;' ), 'a hostile worker string is escaped' );
ok( false === strpos( $kit, '<script>y</script>' ) && false !== strpos( $kit, '&lt;script&gt;y&lt;/script&gt;' ), 'a hostile last-error message is escaped' );
$GLOBALS['__lg_remote']['deployed_at'] = '2026-09-01T00:00:00Z';

// ── The last-error notice: shown when present, silent when absent.
ok( false !== strpos( $kit, 'Last send failed' ), 'a last-send error paints a notice' );
set_digest_options( true, time() - 3600, false );
$kit = snt_leaf_paint( 'security', 'login-defense' );
ok( false === strpos( $kit, 'Last send failed' ), 'no error notice when last_error is false' );

// ── Digest disabled + never sent: the toggle is unchecked, no last-sent line.
set_digest_options( false, 0, false );
$kit = snt_leaf_paint( 'security', 'login-defense' );
ok( false === strpos( $kit, 'checked' ), 'the toggle is unchecked when the digest is disabled' );
ok( false === strpos( $kit, 'ago.' ), 'no last-sent line when it has never been sent' );

// ── Round-trip pin (refuter finding, major): the painted OFF state must
// survive os-form's field reader + the host's snt_os_host_expand() as an
// EMPTY value the handler's `! empty()` (not `isset()`) test would read as
// off. This is what actually distinguishes 'checkbox' from 'switch': a switch
// always submits its static value='1', so this pin goes RED against the
// pre-fix 'switch' paint and GREEN against the 'checkbox' paint below.
// NOTE: the shipped handler (inc/admin-post-actions/reports.php:38) still
// tests `isset( $post['sn_digest_enabled'] )`, which is true for '' just as
// much as for '1' — so this pin proves the FIELD carries the off-state
// correctly; it does not prove the handler reads it correctly. That handler
// line is outside this leaf's file scope (see the final report's `changed`).
$off_read   = os_form_read_field( $kit, 'sn_digest_enabled' );
$off_expand = \snt_os_host_expand( array( 'sn_digest_enabled' => $off_read ) );
ok( false === $off_read, 'os-form would read the unchecked field as boolean false' );
ok( empty( $off_expand['sn_digest_enabled'] ), 'the digest OFF state round-trips through snt_os_host_expand() as empty (would read off under !empty())' );

set_digest_options( true, time() - 3600, false );
$kit_on    = snt_leaf_paint( 'security', 'login-defense' );
$on_read   = os_form_read_field( $kit_on, 'sn_digest_enabled' );
$on_expand = \snt_os_host_expand( array( 'sn_digest_enabled' => $on_read ) );
ok( true === $on_read, 'os-form would read the checked field as boolean true' );
ok( ! empty( $on_expand['sn_digest_enabled'] ), 'the digest ON state round-trips through snt_os_host_expand() as non-empty' );

// ── Worker status unavailable (probe fails): the classic "unavailable" line, no Worker/Denylist facts.
$GLOBALS['__lg_remote'] = null;
$classic = snt_leaf_classic_html( 'sn_login_defense_render' );
$kit     = snt_leaf_paint( 'security', 'login-defense' );
ok( false !== strpos( $kit, 'Login guard status unavailable' ) && false !== strpos( $classic, 'Login guard status unavailable' ), 'an unreachable worker paints the unavailable message on both leaves' );
ok( false === strpos( $kit, 'sn-login-guard v' ), 'no worker-version fact when the status is unavailable' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names still match with the status unavailable' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
