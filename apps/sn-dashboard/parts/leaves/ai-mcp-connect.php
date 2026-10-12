<?php
/**
 * S&N Dashboard — AI → MCP Clients, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/mcp-connect.php,
 * `sn_admin_render_mcp_connect_section()`) reads the same status builder and
 * posts the same two forms (bind_mcp_rw_credential, remote_toggle) to the
 * same handlers.
 *
 * 2026-10-12 redesign (owner, on 2026.10.2: "The MCP clients page is also
 * weird. You need to do better."). The page said "three doors" over four
 * cards, pointed at a "Door 1" and "Door 2" no card named and at "the
 * Application Password below" from its last line, and floated the remote
 * switch away from the door it controls. Now: one line on what the doors are,
 * the four doors as tiles, the two controls side by side (the write-door
 * credential, the remote switch), then how to connect a client with both
 * addresses, then the one footnote. Helpers live in ai-mcp-connect-parts.php
 * and ai-mcp-connect-steps.php (house line cap).
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/ai-mcp-connect-parts.php';
require_once __DIR__ . '/ai-mcp-connect-steps.php';

/**
 * The status the classic leaf reads: the state and its four cards (read,
 * write, adapter, remote). Empty when the classic builder is unavailable.
 *
 * @return array{state:array,cards:array}
 */
function mcp_connect_status() {
	if ( ! function_exists( 'sn_admin_mcp_status_cards' ) || ! function_exists( 'sn_admin_mcp_status_state' ) ) {
		return array( 'state' => array(), 'cards' => array() );
	}
	$state = sn_admin_mcp_status_state();
	return array( 'state' => $state, 'cards' => sn_admin_mcp_status_cards( $state ) );
}

/**
 * The four doors as tiles: the door, its state, and what it is. Usage lives
 * once, in the section that controls the door, never on the tile as well.
 * The tone is the card's own pill (a swatch when the door is not ready).
 *
 * @param array $state The status state.
 * @param array $cards The four cards: read, write, adapter, remote.
 * @return string
 */
function mcp_connect_tiles_html( array $state, array $cards ) {
	list( $read, $write, $adapter, $remote ) = array_pad( $cards, 4, array() );
	$tools = static fn( $n ) => is_int( $n ) ? sprintf( /* translators: %d: tools. */ _n( '%d tool that can change things', '%d tools that can change things', $n, 'signal-and-noise-tools' ), $n ) : __( 'tools not readable', 'signal-and-noise-tools' );
	$tile  = static fn( array $card, $label, $caption ) => \snt_kit_stat( (string) ( $card['value'] ?? '' ), $label, $caption, (string) ( $card['pill']['kind'] ?? '' ) );
	return \snt_kit_grid(
		array(
			$tile( $read, __( 'Read door', 'signal-and-noise-tools' ), __( 'read-only, behind an Application Password', 'signal-and-noise-tools' ) ),
			$tile( $write, __( 'Write door', 'signal-and-noise-tools' ), $tools( $state['rw_count'] ?? null ) ),
			$tile( $remote, __( 'Remote door', 'signal-and-noise-tools' ), __( 'through the Cloudflare Worker', 'signal-and-noise-tools' ) ),
			$tile( $adapter, __( 'Core MCP adapter', 'signal-and-noise-tools' ), __( 'a separate WordPress plugin', 'signal-and-noise-tools' ) ),
		),
		160,
		12
	);
}

/**
 * The remote door's control: what its state means, then its switch. Same
 * field (`sn_remote_enabled`), same disabled-when-constant-killed rule, same
 * action (`remote_toggle`).
 *
 * @param array $card The remote card.
 * @return string
 */
function mcp_connect_remote_html( array $card ) {
	$constant_killed = defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED;
	$door_open       = function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && ! sn_mcp_remote_kill_switch_engaged();
	$field           = \snt_kit_field( 'checkbox', 'sn_remote_enabled', __( 'Remote analytics door enabled', 'signal-and-noise-tools' ), $door_open, array( 'value' => '1', 'disabled' => $constant_killed ) );
	// The card's meta is built escaped by the classic builder (it carries <code> and a <br>).
	$meta = '' !== (string) ( $card['meta_html'] ?? '' ) ? '<p class="snt-prose">' . (string) $card['meta_html'] . '</p>' : '';
	// The description says what the switch decides; the tile above names the state.
	return \snt_kit_section( __( 'Remote door', 'signal-and-noise-tools' ), $meta . \snt_kit_form( 'remote_toggle', $field, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ), __( 'Whether this site answers the Cloudflare Worker', 'signal-and-noise-tools' ) );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_ai_mcp_connect( array $ctx ) {
	unset( $ctx );
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Four MCP doors can answer for this site: the read and write doors this plugin serves, the remote door through the Cloudflare Worker, and the core MCP adapter when it is installed. The read and write doors sign in as you with an Application Password, never a shared secret: the read door can only look, the write door can also change things, so use whichever scope you mean to grant.', 'signal-and-noise-tools' ) ) . '</p>';

	$status = mcp_connect_status();
	if ( 4 === count( $status['cards'] ) ) {
		$out .= mcp_connect_tiles_html( $status['state'], $status['cards'] );
		// The two controls side by side: each sits with the door it changes.
		$explain = 'bound' === ( $status['state']['rw_state'] ?? '' ) ? '' : (string) ( $status['cards'][1]['meta_html'] ?? '' );
		$out    .= \snt_kit_grid( array( mcp_connect_rw_binding_html( $explain ), mcp_connect_remote_html( $status['cards'][3] ) ), 320, 18 );
	} else {
		$out .= mcp_connect_rw_binding_html();
	}
	$out .= mcp_connect_owner_steps_html( $status['state'] );
	$out .= mcp_connect_footer_html();
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['ai/mcp-connect'] = __NAMESPACE__ . '\\paint_ai_mcp_connect';
		return $painters;
	}
);
