<?php
/**
 * S&N Dashboard — AI → MCP Clients, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/mcp-connect.php,
 * `sn_admin_render_mcp_connect_section()`) reads the same status builder.
 * 2026-11: the leaf's three controls (the two door credentials and the remote
 * switch) are settings and paint on Settings > AI (settings-ai.php), through
 * mcp_connect_controls_html() below; this leaf keeps the doors' states, how
 * to connect, and one line saying where the controls went.
 *
 * 2026-10-12 redesign (owner, on 2026.10.2: "The MCP clients page is also
 * weird. You need to do better."): one line on what the doors are, the four
 * doors as tiles, then how to connect a client with both addresses, then the
 * one footnote. Helpers live in ai-mcp-connect-parts.php and
 * ai-mcp-connect-steps.php (house line cap).
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/ai-mcp-connect-parts.php';
require_once __DIR__ . '/ai-mcp-connect-read-binding.php';
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
	$tools = static fn( $n ) => is_int( $n ) ? sprintf( /* translators: %d: tools. */ _n( '%d tool: changes and AI-billed drafts', '%d tools: changes and AI-billed drafts', $n, 'signal-and-noise-tools' ), $n ) : __( 'tools not readable', 'signal-and-noise-tools' );
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
 * The remote door's control: its switch, then what its state means, the
 * Read and Write door boxes' order. Same field (`sn_remote_enabled`), same
 * disabled-when-constant-killed rule, same action (`remote_toggle`).
 *
 * @param array $card The remote card.
 * @return string
 */
function mcp_connect_remote_html( array $card ) {
	$constant_killed = defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED;
	$door_open       = function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && ! sn_mcp_remote_kill_switch_engaged();
	$field           = \snt_kit_field( 'checkbox', 'sn_remote_enabled', __( 'Remote door enabled', 'signal-and-noise-tools' ), $door_open, array( 'value' => '1', 'disabled' => $constant_killed ) );
	// The card's meta is built escaped by the classic builder (it carries <code> and a <br>).
	$meta = '' !== (string) ( $card['meta_html'] ?? '' ) ? '<p class="snt-prose">' . (string) $card['meta_html'] . '</p>' : '';
	// The description says what the switch decides; the state sentence follows the switch.
	return \snt_kit_section( __( 'Remote door', 'signal-and-noise-tools' ), \snt_kit_form( 'remote_toggle', $field, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ) . $meta, __( 'Whether this site answers the Cloudflare Worker', 'signal-and-noise-tools' ) );
}

/**
 * The three controls side by side, in door order: the read door's
 * credential, the write door's, the remote door's switch. Painted on
 * Settings > AI (settings-ai.php) since 2026-11; this is the call this leaf
 * made, moved into a function, with the same arguments: the write box gets
 * the door's state and its off reason, without which an off door's warning
 * would not paint. The switch's box carries the Remote card's state sentence
 * (why it is off or locked); the usage line is a reading and stays here.
 *
 * @param array{state:array,cards:array} $status From mcp_connect_status().
 * @return string
 */
function mcp_connect_controls_html( array $status ) {
	if ( 4 !== count( $status['cards'] ) ) {
		return mcp_connect_read_binding_html() . mcp_connect_rw_binding_html();
	}
	$rw_state = (string) ( $status['state']['rw_state'] ?? '' );
	$remote   = array( 'meta_html' => (string) ( $status['cards'][3]['state_html'] ?? '' ) ) + $status['cards'][3];
	return \snt_kit_grid( array_values( array_filter( array( mcp_connect_read_binding_html(), mcp_connect_rw_binding_html( $rw_state, sn_admin_mcp_status_off_reason( $status['state'] ) ), mcp_connect_remote_html( $remote ) ) ) ), 320, 18 );
}

/**
 * What stays here of the remote door: its state in words and its usage (the
 * Remote card's meta, built escaped by the classic builder), then the one
 * line saying where this leaf's three controls went.
 *
 * @param array $cards The four cards, or fewer when the builder is unavailable.
 * @return string
 */
function mcp_connect_moved_html( array $cards ) {
	$meta  = (string) ( $cards[3]['meta_html'] ?? '' );
	$moved = function_exists( 'snt_settings_ai_moved_text' )
		? '<p class="snt-hint">' . \snt_kit_esc( \snt_settings_ai_moved_text( 'doors' ) ) . ' ' . \snt_kit_go( __( 'Settings › AI', 'signal-and-noise-tools' ), array( 'tab' => 'settings', 'sub' => 'ai', 'current' => 'ai' ) ) . '.</p>'
		: '';
	return ( '' !== $meta ? \snt_kit_section( __( 'Remote door', 'signal-and-noise-tools' ), '<p class="snt-prose">' . $meta . '</p>', __( 'Whether this site answers the Cloudflare Worker', 'signal-and-noise-tools' ) ) : '' ) . $moved;
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
	$out   .= 4 === count( $status['cards'] ) ? mcp_connect_tiles_html( $status['state'], $status['cards'] ) : '';
	return $out . mcp_connect_moved_html( $status['cards'] ) . mcp_connect_owner_steps_html( $status['state'] ) . mcp_connect_footer_html();
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['ai/mcp-connect'] = __NAMESPACE__ . '\\paint_ai_mcp_connect';
		return $painters;
	}
);
