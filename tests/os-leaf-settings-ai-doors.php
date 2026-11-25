<?php
/**
 * Settings › AI, the three MCP door controls, on both twins: the remote door's
 * switch (remote_toggle), the write door's credential
 * (bind_mcp_rw_credential) and the read door's (bind_mcp_read_credential).
 * They came from AI › MCP Clients, which keeps the four doors' states, how to
 * connect a client, and one line saying where the controls went.
 *
 * These bind credentials and open a remote door, so the pane does not retype
 * them: it calls the functions the old leaf called. Each control is pinned
 * here as a substring of the pane, byte for byte, in every state: the nonce,
 * the hidden fields, the off and unbound warnings, the Unbind confirmation,
 * the disabled switch under SN_MCP_REMOTE_DISABLED. An Application Password's
 * UUID is printed only where it was (the pickers' option values); its stored
 * hash is printed nowhere.
 *
 * Run: php tests/os-leaf-settings-ai-doors.php
 */
require_once __DIR__ . '/lib/settings-ai-fixture.php';

use function SignalNoise\OpenStationHost\Dashboard\Leaves\mcp_connect_read_binding_html;
use function SignalNoise\OpenStationHost\Dashboard\Leaves\mcp_connect_rw_binding_html;
use function SignalNoise\OpenStationHost\Dashboard\Leaves\mcp_connect_remote_html;

/** The three controls as the old leaf's own functions paint them: classic, then native. */
function doors_old() {
	$state  = sn_admin_mcp_status_state();
	$cards  = sn_admin_mcp_status_cards( $state );
	$remote = array( 'meta_html' => (string) ( $cards[3]['state_html'] ?? 'no state_html yet' ) ) + $cards[3];
	return array(
		'classic' => array( 'read' => ai_out( 'sn_admin_render_mcp_read_binding' ), 'write' => ai_out( 'sn_admin_render_mcp_rw_binding' ), 'remote' => ai_out( 'sn_admin_render_mcp_remote_toggle' ) ),
		'native'  => array( 'read' => mcp_connect_read_binding_html(), 'write' => mcp_connect_rw_binding_html( (string) $state['rw_state'], sn_admin_mcp_status_off_reason( $state ) ), 'remote' => mcp_connect_remote_html( $remote ) ),
	);
}
/** How many times either fixture UUID is printed. */
function doors_uuids( $html ) { return substr_count( strtolower( (string) $html ), SNT_AI_UUID_A ) + substr_count( strtolower( (string) $html ), SNT_AI_UUID_B ); }

$states = array(
	'unbound, remote off'                   => array(),
	'both bound, remote on'                 => array( '__rw_bound_uuid' => SNT_AI_UUID_A, '__read_bound_uuid' => SNT_AI_UUID_B, '__remote_kill_engaged' => false ),
	'write door switched off, read off'     => array( '__rw_option_on' => false, '__read_off' => true ),
	'write door killed in wp-config'        => array( '__rw_constant_killed' => true, '__rw_bound_uuid' => SNT_AI_UUID_A ),
	'bound to a credential that is gone'    => array( '__rw_bound_uuid' => '99999999-9999-4999-8999-999999999999', '__read_bound_uuid' => '88888888-8888-4888-8888-888888888888' ),
	'no Application Passwords at all'       => array( '__passwords' => array() ),
);
$all_passwords = $GLOBALS['__passwords'];
foreach ( $states as $name => $set ) {
	foreach ( array( '__rw_bound_uuid', '__read_bound_uuid', '__remote_kill_engaged', '__rw_option_on', '__read_off', '__rw_constant_killed' ) as $g ) { unset( $GLOBALS[ $g ] ); }
	$GLOBALS['__passwords'] = $all_passwords;
	foreach ( $set as $g => $v ) { $GLOBALS[ $g ] = $v; }
	$old   = doors_old();
	$panes = array( 'classic' => ai_classic(), 'native' => ai_kit() );
	foreach ( $panes as $twin => $pane ) {
		$whole = '' !== $pane;
		foreach ( $old[ $twin ] as $door => $html ) {
			$whole = $whole && '' !== $html && 1 === substr_count( $pane, $html );
		}
		ok( $whole, "$name, $twin: each control is on the pane once, byte for byte as the old leaf's function paints it" );
		ok( '' !== $pane && doors_uuids( $pane ) === doors_uuids( implode( '', $old[ $twin ] ) ) && false === strpos( $pane, 'never-printed' ), "$name, $twin: a UUID is printed only where the pickers printed it (" . doors_uuids( $pane ) . ' option values), the stored hash nowhere' );
	}
	// The old leaf: its readings, one pointer, none of the three controls.
	$leaf = array( 'classic' => snt_leaf_classic_html( 'sn_admin_render_mcp_connect_section' ), 'native' => snt_leaf_paint( 'ai', 'mcp-connect' ) );
	foreach ( $leaf as $twin => $html ) {
		ok( array() === array_values( array_diff( snt_leaf_names( $html ), array( 'submit' ) ) ) && array() === snt_leaf_actions( $html ) && 0 === doors_uuids( $html ) && false === strpos( $html, 'never-printed' ) && false === strpos( $html, 'Unbind' ), "$name, $twin: AI › MCP Clients holds no field, no action and no UUID" );
	}
}

// ── The guards, named, in the states that raise them (the last loop state has no passwords; reset).
foreach ( array( '__rw_bound_uuid', '__read_bound_uuid', '__remote_kill_engaged', '__rw_option_on', '__read_off', '__rw_constant_killed' ) as $g ) { unset( $GLOBALS[ $g ] ); }
$GLOBALS['__passwords']       = $all_passwords;
$GLOBALS['__rw_bound_uuid']   = SNT_AI_UUID_A;
$GLOBALS['__read_bound_uuid'] = SNT_AI_UUID_B;
$classic = ai_classic();
$kit     = ai_kit();
foreach ( array( 'bind_mcp_rw_credential', 'bind_mcp_read_credential', 'remote_toggle' ) as $action ) {
	ok( substr_count( $classic, '<input type="hidden" name="_wpnonce" value="nonce-sn_' . $action . '">' ) === substr_count( $classic, '<input type="hidden" name="action" value="sn_' . $action . '"' ) && substr_count( $classic, 'value="sn_' . $action . '"' ) >= 1 && substr_count( $kit, 'value="sn_' . $action . '"><input type="hidden" name="_wpnonce" value="nonce-sn_' . $action . '">' ) >= 1, "$action: every form that posts it carries that action's own nonce, on both twins" );
}
ok( false !== strpos( $classic, 'form="sn-mcp-rw-unbind" class="button button-link-delete" data-snt-confirm="Unbind the write-door credential? Every write-door call is denied until you bind one again." data-snt-confirm-danger="1"' ) && false !== strpos( $classic, '<form id="sn-mcp-rw-unbind"' ) && false !== strpos( $classic, 'name="sn_mcp_rw_uuid" value=""><input type="hidden" name="sn_mcp_rw_unbind" value="1">' ), 'classic: Unbind (write) is its own hidden form behind its confirmation, marked dangerous' );
ok( false !== strpos( $classic, 'form="sn-mcp-read-unbind" class="button button-link-delete" data-snt-confirm="Unbind the read-door credential? That Application Password stops being limited to the read door."' ) && false !== strpos( $classic, 'name="sn_mcp_read_uuid" value=""><input type="hidden" name="sn_mcp_read_unbind" value="1">' ), 'classic: Unbind (read) is its own hidden form behind its confirmation' );
ok( 1 === preg_match( '#<os-form class="snt-form snt-form--saves-inline" os-action="post" submit-label="Unbind"[^>]*os-confirm="Unbind the write-door credential\? Every write-door call is denied until you bind one again\." os-confirm-danger>(?:(?!</os-form>).)*name="sn_mcp_rw_uuid" value=""><input type="hidden" name="sn_mcp_rw_unbind" value="1"></os-form>#s', $kit ) && 1 === preg_match( '#submit-label="Unbind"[^>]*os-confirm="Unbind the read-door credential\?[^"]*">(?:(?!</os-form>).)*name="sn_mcp_read_unbind" value="1"></os-form>#s', $kit ), 'native: both Unbinds are their own confirmed forms carrying the empty credential and the unbind field' );
ok( false !== strpos( $classic, 'Rotate this Application Password periodically: anyone who holds it can call the write door.' ) && false !== strpos( $kit, 'Rotate this Application Password periodically: anyone who holds it can call the write door.' ) && false !== strpos( $classic, 'The write door is bound to <strong>Laptop</strong>.' ) && false !== strpos( $kit, 'The write door is bound to Laptop.' ), 'bound: the name (never the UUID) and the rotate warning, on both twins' );
$old = doors_old();
ok( 0 === substr_count( strtolower( $old['classic']['read'] . $old['native']['read'] ), SNT_AI_UUID_A ) && 1 === substr_count( strtolower( $classic ), 'value="' . SNT_AI_UUID_A . '"' ) && 1 === substr_count( strtolower( $kit ), '<os-option value="' . SNT_AI_UUID_A . '"' ), 'the write door\'s bound password is offered once, in the write picker, and is left out of the read picker' );

unset( $GLOBALS['__rw_bound_uuid'], $GLOBALS['__read_bound_uuid'] );
$classic = ai_classic();
$kit     = ai_kit();
ok( false !== strpos( $classic, '<strong>The write door is INACTIVE.</strong> No Application Password is bound to it yet, so every call to /mcp-rw is denied.' ) && false !== strpos( $kit, '<os-notice tone="warning"' ) && false !== strpos( $kit, '<b>The write door is INACTIVE.</b>' ) && false === strpos( $classic . $kit, 'Unbind' ), 'unbound: the INACTIVE warning on both twins, and no Unbind to press' );
$GLOBALS['__rw_constant_killed'] = true;
$classic = ai_classic();
$kit     = ai_kit();
ok( false !== strpos( $classic, '<strong>The write door is off.</strong> The <code>SN_MCP_RW_DISABLED</code> constant is set in wp-config.php' ) && false !== strpos( $classic, 'Binding a credential does not open it while it is off.' ) && false !== strpos( $kit, '<b>The write door is off.</b> The <code>SN_MCP_RW_DISABLED</code> constant' ), 'killed in wp-config: both twins say binding does not open the door, and why' );
unset( $GLOBALS['__rw_constant_killed'] );

// ── The remote switch: the state sentence comes with it, the usage stays behind.
foreach ( array( 'form' => $classic, 'os-form' => $kit ) as $tag => $html ) {
	ok( 1 === snt_whole_forms( $html, $tag, 'remote_toggle', array( 'sn_remote_enabled' ) ), "<$tag>: one form posts remote_toggle and it carries the switch" );
}
ok( false !== strpos( $classic, 'The door’s switch is off (the <code>sn_mcp_remote_enabled</code> option), so the endpoint answers 404.' ) && false !== strpos( $kit, 'The door’s switch is off (the <code>sn_mcp_remote_enabled</code> option), so the endpoint answers 404.' ), 'the switch has its state sentence beside it on both twins (the classic toggle had none of its own)' );
ok( false === strpos( $classic . $kit, 'Last used yesterday' ) && false !== strpos( snt_leaf_classic_html( 'sn_admin_render_mcp_connect_section' ), 'Last used yesterday, 2 refused' ) && false !== strpos( snt_leaf_paint( 'ai', 'mcp-connect' ), 'Last used yesterday, 2 refused' ), 'the door\'s usage line is a reading: it stays on AI › MCP Clients and is not on the pane' );
$sentence = 'The door’s switch is off (the <code>sn_mcp_remote_enabled</code> option)';
ok( strpos( $classic, 'name="sn_remote_enabled"' ) < strpos( $classic, $sentence ) && strpos( $kit, 'name="sn_remote_enabled"' ) < strpos( $kit, $sentence ), 'the Remote door box puts its switch first and the state sentence after it, as the Read and Write door boxes do, on both twins' );
ok( 1 === preg_match( '#<input type="checkbox" name="sn_remote_enabled" value="1"\s*/>#', $classic ) && false === strpos( $classic, 'disabled="disabled"' ), 'classic: off and not locked, the switch is unchecked and enabled' );
define( 'SN_MCP_REMOTE_DISABLED', true );
$classic = ai_classic();
$kit     = ai_kit();
ok( 1 === preg_match( '#<input type="checkbox" name="sn_remote_enabled" value="1"\s+disabled="disabled" />#', $classic ) && false !== strpos( $kit, '<os-checkbox-label name="sn_remote_enabled" disabled value="1" label="Remote door enabled"></os-checkbox-label>' ), 'SN_MCP_REMOTE_DISABLED: the switch is disabled on both twins, a killed door does not look reopenable' );
ok( false !== strpos( $classic, 'The <code>SN_MCP_REMOTE_DISABLED</code> constant is set in wp-config.php, so the Remote door enabled switch cannot reopen this door.' ) && false !== strpos( $kit, 'The <code>SN_MCP_REMOTE_DISABLED</code> constant is set in wp-config.php' ), '   ...and both twins say why' );
ok( 'remote_constant_locked' === sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) ), '   ...and the handler still refuses the save, as it did' );

// ── AI › MCP Clients keeps its readings and says where the controls went.
$leaf_c = snt_leaf_classic_html( 'sn_admin_render_mcp_connect_section' );
$leaf_k = snt_leaf_paint( 'ai', 'mcp-connect' );
ok( 1 === substr_count( $leaf_c, '<p class="sn-field-helper">The remote door switch and the two door credentials moved to <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=ai">Settings › AI</a>.</p>' ) && 1 === preg_match( '#<p class="snt-hint">The remote door switch and the two door credentials moved to <os-button class="snt-go" variant="link" data-snt-tab="settings" data-snt-sub="ai"[^>]*>Settings › AI</os-button>\.</p>#', $leaf_k ), 'one line, with a link, on both twins' );
ok( false !== strpos( $leaf_c, 'Killed in wp-config' ) && false !== strpos( $leaf_c, 'claude_desktop_config.json' ) && false !== strpos( $leaf_k, 'Killed in wp-config' ) && false !== strpos( $leaf_k, 'heading="Connect a client"' ) && false !== strpos( $leaf_k, 'SN_MCP_REMOTE_DISABLED' ), 'it keeps the four doors\' states (the killed remote door named, with its reason) and how to connect a client' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
