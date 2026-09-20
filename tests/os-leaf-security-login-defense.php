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

// The breached-password box's readers (#1556): the real memo reader
// (inc/breached-credentials-login.php over a get_user_meta stub) and the real
// Site Health derivation (inc/breached-credentials-surface.php), the set-time
// stats stubbed the way tests/breached-credentials-surface.php stubs them.
$GLOBALS['__users'] = array(); // id => memo array | null (never checked).
function get_users( $args ) { return array_keys( $GLOBALS['__users'] ); }
function get_user_meta( $id, $k, $single = false ) { return $GLOBALS['__users'][ $id ] ?? ''; }
function get_edit_profile_url() { return 'https://example.test/wp-admin/profile.php'; }
$GLOBALS['__set_stats'] = array( 'breached_count' => 0, 'unavailable_count' => 0, 'last_breached_at' => 0, 'last_unavailable_at' => 0 );
function sn_hibp_set_stats() { return $GLOBALS['__set_stats']; }
$GLOBALS['__set_off'] = false;
function sn_hibp_set_disabled() { return $GLOBALS['__set_off']; }

require SNT_PATH . 'inc/login-defense.php';
require SNT_PATH . 'inc/security-digest.php';
require SNT_PATH . 'inc/breached-credentials.php';
require SNT_PATH . 'inc/breached-credentials-login.php';
require SNT_PATH . 'inc/breached-credentials-surface.php';
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
preg_match( '/<[^>]*name="sn_digest_enabled"[^>]*>/', $kit, $toggle_tag ); // the tag, not the leaf: the breach box says "N of N checked".
ok( isset( $toggle_tag[0] ) && false !== strpos( $toggle_tag[0], ' checked' ), 'the digest toggle is a kit switch, checked when enabled' );
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
preg_match( '/<[^>]*name="sn_digest_enabled"[^>]*>/', $kit, $toggle_tag ); // the tag, not the leaf: the breach box says "0 of 0 checked".
ok( isset( $toggle_tag[0] ) && false === strpos( $toggle_tag[0], 'checked' ), 'the toggle is unchecked when the digest is disabled' );
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

// ── Breached passwords (#1556): one box beside Login guard status, the
// viewer's memo or the posture summary as its notice, three facts rows. The
// oracle for the words and the numbers is the Site Health row, the same
// sn_hibp_health() read; the memo's oracle is the classic banner's sentence.

/** The `<dd>` of the facts row labelled $label: [inner, attrs] or null. */
function kv_row( $html, $label ) {
	if ( ! preg_match( '#<dt class="snt-kv__k">' . preg_quote( $label, '#' ) . '</dt><dd class="snt-kv__v"([^>]*)>([^<]*)</dd>#', $html, $m ) ) {
		return null;
	}
	return array( $m[2], $m[1] );
}
/** The inner HTML of the first `<os-notice>` after $from in $html, or ''. */
function first_notice_after( $html, $from ) {
	$at = strpos( $html, '<os-notice', $from );
	if ( false === $at || ! preg_match( '#<os-notice[^>]*>(.*?)</os-notice>#s', $html, $m, 0, $at ) ) {
		return '';
	}
	return $m[1];
}

$GLOBALS['__lg_remote'] = array( 'version' => '1.7.0', 'deployed_at' => '2026-09-01T00:00:00Z', 'denylistCount' => 1234, 'compiledAt' => '2026-09-05T00:00:00Z' );
set_digest_options( true, time() - 3600, false );
$profile_url = 'https://example.test/wp-admin/profile.php#password';

// (A) another account is flagged, the viewer is clean, a fail-closed rejection this week.
$GLOBALS['__users']     = array( 1 => array( 'digest' => 'a', 'verdict' => 'not_breached', 'count' => 0, 'checked_at' => time() ), 2 => array( 'digest' => 'b', 'verdict' => 'breached', 'count' => 1234, 'checked_at' => time() ), 3 => null );
$GLOBALS['__set_stats'] = array( 'breached_count' => 4, 'unavailable_count' => 2, 'last_breached_at' => 0, 'last_unavailable_at' => time() - 3600 );
$row     = sn_hibp_site_health_result();
$text    = strip_tags( $row['description'] );
$classic = snt_leaf_classic_html( 'sn_login_defense_render' );
$kit     = snt_leaf_paint( 'security', 'login-defense' );
$cols_at = strpos( $kit, '<div class="snt-cols">' );
$box_at  = strpos( $kit, 'heading="Breached passwords"' );
$dig_at  = strpos( $kit, 'heading="Weekly security digest"' );
ok( 1 === substr_count( $kit, '<div class="snt-cols">' ), 'one .snt-cols row' );
ok( false !== $cols_at && $cols_at < strpos( $kit, 'heading="Login guard status"' ) && strpos( $kit, 'heading="Login guard status"' ) < $box_at && $box_at < $dig_at, 'the row holds Login guard status then Breached passwords, the digest below' );
ok( 2 === substr_count( substr( $kit, $cols_at, $dig_at - $cols_at ), '<os-section heading=' ) && false !== strpos( substr( $kit, 0, $dig_at ), '</div><os-section' ), 'two boxes share the row; the digest forms stay outside it at full width' );
ok( 'recommended' === $row['status'] && preg_match( '/(\d+) of (\d+) checked/', $text, $m ) && '1' === $m[1] && '2' === $m[2], 'oracle: the Site Health row reads 1 of 2 checked' );
$flagged = kv_row( $kit, 'Accounts flagged at login' );
ok( null !== $flagged && $m[1] . ' of ' . $m[2] . ' checked' === $flagged[0] && false !== strpos( $flagged[1], 'data-tone="warning"' ), 'the flagged row carries the row\'s numbers, toned warning: ' . ( $flagged[0] ?? 'absent' ) );
$unavail = kv_row( $kit, 'Fail-closed rejections (API unreachable)' );
ok( null !== $unavail && 0 === strpos( $unavail[0], '2' ) && false !== strpos( $unavail[0], 'recent' ) && false !== strpos( $unavail[1], 'data-tone="warning"' ), 'the fail-closed row reads 2, recent, toned warning' );
$refused = kv_row( $kit, 'Breached passwords refused at set-time' );
ok( null !== $refused && '4' === $refused[0] && false === strpos( $refused[1], 'data-tone' ), 'the refused row reads 4, no tone' );
ok( snt_kit_esc( $text ) === first_notice_after( $kit, $box_at ) && false !== strpos( first_notice_after( $kit, $box_at ), 'known data breaches' ), 'the posture verdict is the notice on top of the box, the Site Health row\'s sentence' );
ok( false === strpos( $kit, 'Your current password appears' ), 'no personal memo when the viewer\'s own password is clean' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'names and actions parity survives the box (a reading adds no field)' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup in the box: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// (B) the viewer's own password is breached: the memo wins the notice slot, the same words as the classic banner, the link a door.
$GLOBALS['__users'][1] = array( 'digest' => 'a', 'verdict' => 'breached', 'count' => 1234, 'checked_at' => time() );
$row  = sn_hibp_site_health_result();
$text = strip_tags( $row['description'] );
$kit  = snt_leaf_paint( 'security', 'login-defense' );
$box_at = strpos( $kit, 'heading="Breached passwords"' );
$memo   = first_notice_after( $kit, $box_at );
$notice_tag = substr( $kit, strpos( $kit, '<os-notice', $box_at ), 60 );
ok( false !== strpos( $notice_tag, 'tone="warning"' ) && false !== strpos( $notice_tag, 'not-dismissible' ), 'the memo is a warning notice on top of the box, not dismissible (a reading, not a flash)' );
ok( strip_tags( sn_hibp_login_notice_html( $GLOBALS['__users'][1], $profile_url ) ) === strip_tags( $memo ) && false !== strpos( $memo, '1,234' ), 'the memo is the classic banner\'s sentence, word for word' );
ok( false !== strpos( $memo, 'os-action="door"' ) && false !== strpos( $memo, 'profile.php#password' ) && false === strpos( $memo, '<a ' ), 'the profile link is a kit door, not an anchor' );
ok( $box_at < strpos( $kit, 'Your current password appears' ) && 1 === substr_count( substr( $kit, $box_at ), '<os-notice' ), 'one notice in the box: the memo, the posture summary demoted to the hint line' );
ok( false !== strpos( $kit, '<p class="snt-hint">' . snt_kit_esc( $text ) . '</p>' ), 'the posture sentence is the hint under the memo' );
$flagged = kv_row( $kit, 'Accounts flagged at login' );
ok( null !== $flagged && '2 of 2 checked' === $flagged[0], 'the flagged row counts the viewer too: 2 of 2 checked' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'names and actions parity with the memo set' );

// (C) good: nobody flagged, the fail-closed rejection older than a week. Every figure equals the row's.
$GLOBALS['__users'][1] = array( 'digest' => 'a', 'verdict' => 'not_breached', 'count' => 0, 'checked_at' => time() );
$GLOBALS['__users'][2] = array( 'digest' => 'b', 'verdict' => 'not_breached', 'count' => 0, 'checked_at' => time() );
$GLOBALS['__set_stats']['last_unavailable_at'] = time() - 8 * DAY_IN_SECONDS;
$row  = sn_hibp_site_health_result();
$text = strip_tags( $row['description'] );
$kit  = snt_leaf_paint( 'security', 'login-defense' );
ok( 'good' === $row['status'] && preg_match( '/(\d+) of (\d+) account\(s\) checked at login.*?(\d+) breached password\(s\) refused.*?(\d+) fail-closed/', $text, $g ), 'oracle: the good row carries checked, users, refused, fail-closed' );
ok( '0 of ' . $g[1] . ' checked' === ( kv_row( $kit, 'Accounts flagged at login' )[0] ?? '' ), 'good: flagged reads 0 of ' . $g[1] . ' checked' );
ok( $g[3] === ( kv_row( $kit, 'Breached passwords refused at set-time' )[0] ?? '' ), 'good: refused equals the row\'s ' . $g[3] );
ok( $g[4] === ( kv_row( $kit, 'Fail-closed rejections (API unreachable)' )[0] ?? '' ), 'good: fail-closed equals the row\'s ' . $g[4] . ', nothing recent' );
ok( false === strpos( $kit, '<os-notice' ) && false !== strpos( $kit, '<p class="snt-hint">' . snt_kit_esc( $text ) . '</p>' ), 'good: no notice anywhere on the leaf, the sentence is a hint' );
ok( false === strpos( kv_row( $kit, 'Accounts flagged at login' )[1] ?? 'absent', 'data-tone' ) && false === strpos( kv_row( $kit, 'Fail-closed rejections (API unreachable)' )[1] ?? 'absent', 'data-tone' ) && null !== kv_row( $kit, 'Accounts flagged at login' ), 'good: no row is toned' );

// (D) set-time mode switched OFF: the row says so, so does the box; the rows still paint.
$GLOBALS['__set_off'] = true;
$text = strip_tags( sn_hibp_site_health_result()['description'] );
$kit  = snt_leaf_paint( 'security', 'login-defense' );
ok( false !== strpos( $text, 'SN_HIBP_SET_DISABLED' ) && false !== strpos( first_notice_after( $kit, strpos( $kit, 'heading="Breached passwords"' ) ), 'SN_HIBP_SET_DISABLED' ) && false === strpos( $kit, 'SN_HIBP_LOGIN_DISABLED' ), 'mode OFF: the box\'s notice names the switched-off mode, as the row does' );
ok( null !== kv_row( $kit, 'Accounts flagged at login' ) && null !== kv_row( $kit, 'Fail-closed rejections (API unreachable)' ) && null !== kv_row( $kit, 'Breached passwords refused at set-time' ), 'mode OFF: the three facts rows still paint' );
$GLOBALS['__set_off'] = false;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
