<?php
/**
 * S&N Dashboard — AI → MCP Clients, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/mcp-connect.php,
 * `sn_admin_render_mcp_connect_section()`) is a composite: a status glance,
 * the write-door credential-binding form, owner setup steps, both native-door
 * tool inventories, resources & prompts, the adapter door, the optional usage
 * readout, the "not Connector Approvals" callout and the deep links. Same
 * readers, same two forms (bind_mcp_rw_credential, remote_toggle), same
 * handlers; the kit's parts instead of wp-admin's. Helpers live in
 * ai-mcp-connect-parts.php (house line cap).
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/ai-mcp-connect-parts.php';

/**
 * The status-glance grid: same 4 cards as sn_admin_glance_grid() painted,
 * from the SAME pure/live builder pair the classic leaf calls.
 *
 * @return array<int,array<string,mixed>> Exactly 4 cards, or empty when the
 *                                        classic builder is unavailable.
 */
function mcp_connect_cards() {
	if ( ! function_exists( 'sn_admin_mcp_status_cards' ) || ! function_exists( 'sn_admin_mcp_status_state' ) ) {
		return array();
	}
	return sn_admin_mcp_status_cards( sn_admin_mcp_status_state() );
}

/**
 * Paint the 4 glance cards: label, value, pill (when set), meta (raw HTML —
 * the classic builder already escapes everything it assembles into it).
 *
 * @param array<int,array<string,mixed>> $cards From mcp_connect_cards().
 * @return string
 */
function mcp_connect_glance_html( array $cards ) {
	$cells = '';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$kind  = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		$pill  = (string) ( $card['pill']['text'] ?? '' );
		$meta  = (string) ( $card['meta_html'] ?? '' );
		$cells .= '<div class="snt-sys">'
			. '<span class="snt-sys__k">' . \snt_kit_esc( (string) ( $card['label'] ?? '' ) ) . '</span>'
			. '<span class="snt-sys__v">' . \snt_kit_esc( (string) ( $card['value'] ?? '' ) ) . '</span>'
			. ( '' !== $pill ? \snt_kit_badge( $kind, $pill ) : '' )
			. ( '' !== $meta ? '<span class="snt-sys__meta">' . $meta . '</span>' : '' )
			. '</div>';
	}
	return \snt_kit_section( __( 'Status at a glance', 'signal-and-noise-tools' ), '<div class="snt-systems">' . $cells . '</div>' );
}

/**
 * The remote-door toggle form: same field (`sn_remote_enabled`), same
 * disabled-when-constant-killed rule, same action (`remote_toggle`).
 *
 * @return string
 */
function mcp_connect_remote_toggle_html() {
	$constant_killed = defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED;
	$door_open       = function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && ! sn_mcp_remote_kill_switch_engaged();
	$field           = \snt_kit_field(
		'checkbox',
		'sn_remote_enabled',
		__( 'Remote analytics door enabled', 'signal-and-noise-tools' ),
		$door_open,
		array( 'value' => '1', 'disabled' => $constant_killed )
	);
	return \snt_kit_form( 'remote_toggle', $field, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_ai_mcp_connect( array $ctx ) {
	unset( $ctx );
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Three MCP doors can answer for this site (two native, one third-party) and every one of them sits behind your own WordPress login, an Application Password, never a shared secret. The native doors split by capability: the read door below can only look, the write door under it can also change things, so use whichever credential scope you actually mean to grant.', 'signal-and-noise-tools' ) ) . '</p>';

	$cards = mcp_connect_cards();
	if ( ! empty( $cards ) ) {
		$out .= mcp_connect_glance_html( $cards );
		$out .= mcp_connect_remote_toggle_html();
	}

	// THE TASK PATH, full width and in order: bind the credential, then connect a
	// client. "Connect a client" keeps the width because it carries a JSON config
	// block and a CLI command, and wrapping either is worse than the space costs.
	$out .= mcp_connect_rw_binding_html();
	$out .= mcp_connect_owner_steps_html();

	// THE REFERENCE PATH, as a 2x2 grid rather than a 1,000px vertical essay.
	//
	// The tile row at the top of this leaf already says there are FOUR doors and
	// puts them side by side. The body then explained the same four one under the
	// other, each in a full-width section whose prose used under half of it --
	// the header knew the shape of the content and the body ignored it. Measured
	// live 2026-09-10: these four sections stacked to 1,008px inside a 1,748px
	// leaf. Paired, they mirror the tiles above and read as what they are, four
	// parallel things, at ~830px each: a better measure for the prose than 1,700.
	$out .= \snt_kit_tag(
		'div',
		array( 'class' => 'snt-cols' ),
		mcp_connect_door_native_html() . mcp_connect_door_native_write_html()
	);
	$out .= \snt_kit_tag(
		'div',
		array( 'class' => 'snt-cols' ),
		mcp_connect_door_adapter_html() . mcp_connect_resources_prompts_html()
	);

	$out .= mcp_connect_usage_html();

	// The footer pairs the caveat with the deep links: two short blocks that each
	// took a full-width band of their own for no reason.
	$out .= \snt_kit_tag( 'div', array( 'class' => 'snt-cols' ), \snt_kit_notice(
		'info',
		'<b>' . \snt_kit_esc( __( 'Not the same as Connector Approvals', 'signal-and-noise-tools' ) ) . '</b><br>'
		. \snt_kit_esc( __( 'Tools → Connector Approvals (if the AI plugin is active) gates OUTBOUND use of this site’s configured AI-provider connectors by server-side plugin and theme code: it decides which of your plugins may spend against your Anthropic, OpenAI, or Google key. It has nothing to do with an external MCP client connecting IN. That inbound grant is the Application Password below.', 'signal-and-noise-tools' ) )
	) . mcp_connect_deep_links_html() );

	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['ai/mcp-connect'] = __NAMESPACE__ . '\\paint_ai_mcp_connect';
		return $painters;
	}
);
