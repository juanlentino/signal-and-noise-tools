<?php
/**
 * Native window leaf: Security → Login defense (apps/sn-dashboard/parts/leaves/security-login-defense.php).
 *
 * The oracle is the classic leaf (inc/login-defense.php `sn_login_defense_render()`):
 * the kit port must carry the same readouts and none of wp-admin's markup.
 * Neither twin paints a form any more: the weekly security digest's switch,
 * hint, Send test digest and last-sent lines moved to Settings > Notifications
 * and reports (tests/os-leaf-settings-notifications.php holds their pins),
 * and each twin keeps one line that says so.
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
require SNT_PATH . 'inc/admin-forms/notifications.php'; // the "moved to" line.
require SNT_PATH . 'inc/breached-credentials.php';
require SNT_PATH . 'inc/breached-credentials-login.php';
require SNT_PATH . 'inc/breached-credentials-surface.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/security-login-defense.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['security/login-defense'] ), 'the painter is registered under security/login-defense' );

/**
 * Set the digest option fixtures directly (get_option() reads $GLOBALS['__options']).
 * Kept after the move: with this state set the digest box WOULD paint here,
 * so its absence below is a fact about the leaf, not an empty fixture.
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
// What LEFT for Settings > Notifications and reports, on both twins (the
// digest is ON, sent an hour ago: the box would paint if it were still here).
ok( array() === snt_leaf_names( $kit ) && array() === snt_leaf_names( $classic ), 'neither twin carries a field any more: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array() === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $classic ) && false === strpos( $kit, '<os-form' ) && false === strpos( $classic, '<form' ), 'neither twin posts an action or paints a form' );
foreach ( array( 'native' => $kit, 'classic' => $classic ) as $side => $html ) {
	ok( false === strpos( $html, 'sn_digest_enabled' ) && false === strpos( $html, 'sn_digest_test' ) && false === strpos( $html, 'Send test digest' ) && false === strpos( $html, 'Weekly security digest' ), "$side: the digest switch, its box and Send test digest are gone from Login defense" );
	ok( false === strpos( $html, 'Last sent' ) && false === strpos( $html, 'Last send failed' ) && false === strpos( $html, 'the quiet email is the heartbeat' ) && false === strpos( $html, 'Sends this week’s digest' ), "$side: so are its hint, its Last sent and Last send failed lines, and the test button's line" );
	ok( 1 === substr_count( $html, 'The weekly security digest switch moved to' ) && 1 === substr_count( $html, 'Settings › Notifications and reports' ), "$side: one line says where it went" );
}
ok( 1 === preg_match( '#moved to <os-button class="snt-go" variant="link" data-snt-tab="settings" data-snt-sub="notifications"[^>]*>Settings › Notifications and reports</os-button>\.</p>#', $kit ), 'native: the line carries a go to the settings tab, notifications leaf' );
ok( 1 === preg_match( '#moved to <a href="https://example.test/wp-admin/admin.php\?page=sn-theme-options&(amp;|\#038;)?tab=settings&(amp;|\#038;)?sub=notifications">Settings › Notifications and reports</a>\.</p>#', $classic ), 'classic: the line links to tab=settings&sub=notifications' );
snt_leaf_preview( 'security-login-defense', $kit );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// 2026-10-12 audit: the deploy and refresh times read as how long ago, never raw ISO or "UTC".
ok( false !== strpos( $kit, 'sn-login-guard v1.7.0, deployed 1 hour ago' ) && false === strpos( $kit, '2026-09-01T00:00:00Z' ) && false !== strpos( $classic, 'Worker: sn-login-guard v1.7.0, deployed 1 hour ago.' ), 'the worker version + deploy time are shown, readably, on both twins' );
ok( false !== strpos( $kit, '1,234 ranges, updated 1 hour ago' ) && false === strpos( $kit, ' UTC' ), 'the denylist count + refresh time are shown, readably' );
ok( false !== strpos( $kit, 'Spamhaus' ), 'the FireHOL/Spamhaus attribution survives' );
ok( false !== strpos( $kit, 'os-action="door"' ) && false !== strpos( $kit, 'page=sn-analytics' ) && false !== strpos( $kit, 'sn_view=login-defense' ), 'the analytics link is a door to the other admin screen' );

// ── Escaping: a hostile worker string never reaches the markup raw.
$GLOBALS['__lg_remote']['deployed_at'] = '"><script>x</script>';
set_digest_options( true, time() - 3600, array( 'message' => '<script>y</script>' ) );
$kit = snt_leaf_paint( 'security', 'login-defense' );
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, '&lt;script&gt;x&lt;/script&gt;' ), 'a hostile worker string is escaped' );
ok( false === strpos( $kit, 'script&gt;y' ) && false === strpos( $kit, '<script>y' ), 'the digest\'s last error is not painted here at all, escaped or raw' );
$GLOBALS['__lg_remote']['deployed_at'] = '2026-09-01T00:00:00Z';

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

/** The Site Health row's sentence: its first paragraph (the figures follow it as a list since the review on #2022). */
function hibp_sentence( $html ) {
	return strip_tags( preg_replace( '#<ul>.*</ul>#s', '', (string) $html ) );
}
/** The `<dd>` of the facts row labelled $label: [inner, attrs] or null. */
function kv_row( $html, $label ) {
	if ( ! preg_match( '#<os-fact label="' . preg_quote( $label, '#' ) . '"><span class="snt-kv__v"([^>]*)>([^<]*)</span></os-fact>#', $html, $m ) ) {
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
$text    = hibp_sentence( $row['description'] );
$classic = snt_leaf_classic_html( 'sn_login_defense_render' );
$kit     = snt_leaf_paint( 'security', 'login-defense' );
$cols_at = strpos( $kit, snt_leaf_row() );
$box_at  = strpos( $kit, 'heading="Breached passwords"' );
// The digest's box was the third on this row until it moved to Settings.
ok( 0 === strpos( $kit, snt_leaf_row( 2, 280 ) ) && 1 === substr_count( $kit, '<os-grid' ), 'one row of two' );
// Live check on #2022: the cards were 212 / 251 / 375px, a 295px label left its value 29px.
ok( array( 'Flagged at login', 'Refused when set', 'Refused, service down' ) === array_column( sn_hibp_surface_rows( sn_hibp_surface_data(), sn_hibp_health( sn_hibp_surface_data(), time() ) ), 'label' ), 'Breached passwords\' labels are short, the same on both twins and in Site Health' );
$__css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
ok( 1 === preg_match( '/\[data-snt-leaf="login-defense"\] \.snt-kv os-fact::part\( label \) \{\s*max-width: 14em;/', $__css ) && 1 === preg_match( '/\[data-snt-leaf="login-defense"\] \.snt-kv__v \{\s*overflow-wrap: normal;\s*word-break: normal;/', $__css ), 'the label column is capped and a value never breaks inside a word' );
ok( 1 === preg_match( '/:is\( \[data-snt-leaf="mcp-connect"\], \[data-snt-leaf="login-defense"\] \) os-grid > os-section \{\s*display: flex;\s*flex-direction: column;/', $__css ) && 1 === preg_match( '/os-grid > os-section::part\( body \) \{\s*flex: 1 1 auto;/', $__css ), 'the boxes of a row stretch to the row on Login defense and MCP Clients' );
ok( strpos( $kit, 'heading="Login guard status"' ) < $box_at && $box_at < strpos( $kit, '</os-grid>' ) && strpos( $kit, '</os-grid>' ) < strpos( $kit, 'The weekly security digest switch moved to' ), 'the row holds Login guard status and Breached passwords, in that order, and the moved line sits after the row' );
ok( 'recommended' === $row['status'] && preg_match( '/(\d+) of (\d+) checked/', $text, $m ) && '1' === $m[1] && '2' === $m[2], 'oracle: the Site Health row reads 1 of 2 checked' );
$flagged = kv_row( $kit, 'Flagged at login' );
ok( null !== $flagged && $m[1] . ' of ' . $m[2] . ' checked accounts' === $flagged[0] && false !== strpos( $flagged[1], 'data-tone="warning"' ), 'the flagged row carries the row\'s numbers and says what they count, toned warning: ' . ( $flagged[0] ?? 'absent' ) );
$unavail = kv_row( $kit, 'Refused, service down' );
ok( null !== $unavail && 0 === strpos( $unavail[0], '2' ) && false !== strpos( $unavail[0], 'recent' ) && false !== strpos( $unavail[1], 'data-tone="warning"' ), 'the unreachable row reads 2, recent, toned warning, in plain words' );
$refused = kv_row( $kit, 'Refused when set' );
ok( null !== $refused && '4' === $refused[0] && false === strpos( $refused[1], 'data-tone' ), 'the refused row reads 4, no tone' );
ok( snt_kit_esc( $text ) === first_notice_after( $kit, $box_at ) && false !== strpos( first_notice_after( $kit, $box_at ), 'known data breaches' ), 'the posture verdict is the notice on top of the box, the Site Health row\'s sentence' );
ok( false === strpos( $kit, 'Your current password appears' ), 'no personal memo when the viewer\'s own password is clean' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'names and actions parity survives the box (a reading adds no field)' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup in the box: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// (B) the viewer's own password is breached: the memo wins the notice slot, the same words as the classic banner, the link a door.
$GLOBALS['__users'][1] = array( 'digest' => 'a', 'verdict' => 'breached', 'count' => 1234, 'checked_at' => time() );
$row  = sn_hibp_site_health_result();
$text = hibp_sentence( $row['description'] );
$kit  = snt_leaf_paint( 'security', 'login-defense' );
$box_at = strpos( $kit, 'heading="Breached passwords"' );
$memo   = first_notice_after( $kit, $box_at );
$notice_tag = substr( $kit, strpos( $kit, '<os-notice', $box_at ), 60 );
ok( false !== strpos( $notice_tag, 'tone="warning"' ) && false !== strpos( $notice_tag, 'not-dismissible' ), 'the memo is a warning notice on top of the box, not dismissible (a reading, not a flash)' );
ok( strip_tags( sn_hibp_login_notice_html( $GLOBALS['__users'][1], $profile_url ) ) === strip_tags( $memo ) && false !== strpos( $memo, '1,234' ), 'the memo is the classic banner\'s sentence, word for word' );
ok( false !== strpos( $memo, 'os-action="door"' ) && false !== strpos( $memo, 'profile.php#password' ) && false === strpos( $memo, '<a ' ), 'the profile link is a kit door, not an anchor' );
ok( $box_at < strpos( $kit, 'Your current password appears' ) && 1 === substr_count( substr( $kit, $box_at ), '<os-notice' ), 'one notice in the box: the memo, the posture summary demoted to the hint line' );
ok( false !== strpos( $kit, '<p class="snt-hint">' . snt_kit_esc( $text ) . '</p>' ), 'the posture sentence is the hint under the memo' );
$flagged = kv_row( $kit, 'Flagged at login' );
ok( null !== $flagged && '2 of 2 checked accounts' === $flagged[0], 'the flagged row counts the viewer too: 2 of 2 checked accounts' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'names and actions parity with the memo set' );

// (C) good: nobody flagged, the fail-closed rejection older than a week. Every figure equals the row's.
$GLOBALS['__users'][1] = array( 'digest' => 'a', 'verdict' => 'not_breached', 'count' => 0, 'checked_at' => time() );
$GLOBALS['__users'][2] = array( 'digest' => 'b', 'verdict' => 'not_breached', 'count' => 0, 'checked_at' => time() );
$GLOBALS['__set_stats']['last_unavailable_at'] = time() - 8 * DAY_IN_SECONDS;
$row  = sn_hibp_site_health_result();
$text = hibp_sentence( $row['description'] );
$kit  = snt_leaf_paint( 'security', 'login-defense' );
// 2026-10-12 audit: the good sentence said "2 of 3 account(s) checked" beside a
// row saying "0 of 2 checked": two X of Y counting different things. The
// sentence keeps how many accounts were checked; the rows keep the counts.
ok( 'good' === $row['status'] && preg_match( '/(\d+) of (\d+) accounts have been checked at login/', $text, $g ) && false === strpos( $text, '(s)' ) && false === strpos( $text, 'refused' ), 'oracle: the good row says how many accounts were checked, and leaves the counts to the rows' );
ok( '0 of ' . $g[1] . ' checked accounts' === ( kv_row( $kit, 'Flagged at login' )[0] ?? '' ), 'good: flagged reads 0 of ' . $g[1] . ' checked accounts' );
ok( '4' === ( kv_row( $kit, 'Refused when set' )[0] ?? '' ), 'good: refused reads the stored 4' );
ok( '2' === ( kv_row( $kit, 'Refused, service down' )[0] ?? '' ), 'good: the unreachable count reads 2, nothing recent' );
ok( false === strpos( $kit, '<os-notice' ) && false !== strpos( $kit, '<p class="snt-hint">' . snt_kit_esc( $text ) . '</p>' ), 'good: no notice anywhere on the leaf, the sentence is a hint' );
ok( false === strpos( kv_row( $kit, 'Flagged at login' )[1] ?? 'absent', 'data-tone' ) && false === strpos( kv_row( $kit, 'Refused, service down' )[1] ?? 'absent', 'data-tone' ) && null !== kv_row( $kit, 'Flagged at login' ), 'good: no row is toned' );

// (D) set-time mode switched OFF: the row says so, so does the box; the rows still paint.
$GLOBALS['__set_off'] = true;
$text = hibp_sentence( sn_hibp_site_health_result()['description'] );
$kit  = snt_leaf_paint( 'security', 'login-defense' );
ok( false !== strpos( $text, 'switched off in wp-config.php for new passwords' ) && false !== strpos( first_notice_after( $kit, strpos( $kit, 'heading="Breached passwords"' ) ), 'switched off in wp-config.php for new passwords' ) && false === strpos( $kit, 'below this line' ) && false === strpos( $kit, 'SN_HIBP' ), 'mode OFF: the box\'s notice names the switched-off check in plain words, as the row does' );
ok( '0 of 2 checked accounts' === ( kv_row( $kit, 'Flagged at login' )[0] ?? '' ) && 'not measured (switched off)' === ( kv_row( $kit, 'Refused, service down' )[0] ?? '' ) && 'not measured (switched off)' === ( kv_row( $kit, 'Refused when set' )[0] ?? '' ), 'mode OFF: its rows say not measured instead of a count; the login row keeps its figure' );
$classic = snt_leaf_classic_html( 'sn_login_defense_render' );
ok( false !== strpos( $classic, 'Breached passwords' ) && false !== strpos( $classic, '<th scope="row">Refused when set</th><td>not measured (switched off)</td>' ), 'classic: the same Breached passwords readout (#23, native-only until 2026-10-12)' );
$GLOBALS['__set_off'] = false;

// 2026-10-11: the worker answers null when its list meta is unreadable; that is
// unknown, never "0 ranges, updated ?". Both twins say "not reported", and the
// IPv6 list the worker also reports is counted beside the IPv4 one.
$GLOBALS['__lg_remote'] = array( 'version' => '1.14.1', 'deployed_at' => '2026-10-01T00:00:00Z', 'denylistCount' => 3922, 'compiledAt' => '2026-10-11T16:07:43.338Z', 'denylist6Count' => 81 );
$kit     = snt_leaf_paint( 'security', 'login-defense' );
$classic = snt_leaf_classic_html( 'sn_login_defense_render' );
ok( false !== strpos( $kit, '3,922 IPv4 and 81 IPv6 ranges, updated 1 hour ago' ) && false !== strpos( $classic, '3,922 IPv4 and 81 IPv6 ranges, updated 1 hour ago' ), 'both twins count both lists and say when, as how long ago' );
$GLOBALS['__lg_remote'] = array( 'version' => '1.14.1', 'deployed_at' => '2026-10-01T00:00:00Z', 'denylistCount' => null, 'compiledAt' => null );
$kit     = snt_leaf_paint( 'security', 'login-defense' );
$classic = snt_leaf_classic_html( 'sn_login_defense_render' );
ok( false === strpos( $kit, '0 ranges' ) && false === strpos( $kit, 'updated ?' ) && false !== strpos( $kit, 'not reported by the worker' ) && false !== strpos( $classic, 'not reported by the worker' ), 'a null count is not reported, never 0 ranges, updated ?' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
