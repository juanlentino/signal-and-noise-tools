<?php
/**
 * S&N Dashboard — AI → MCP Clients, helpers for ai-mcp-connect.php.
 *
 * Split out to keep the leaf file under the house line cap. Every reader
 * here is the SAME one inc/admin-forms/mcp-connect.php and
 * inc/admin-forms/mcp-connect-status.php call, behind the same
 * function_exists()/class_exists() guards those files already use.
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The write-door credential-binding form, the one form on this otherwise
 * read-only leaf. Same states (off, unbound, bound, unresolvable), same "no
 * Application Passwords yet" fallback (a door to the profile screen, no form
 * at all), same field (`sn_mcp_rw_uuid`), same action
 * (`bind_mcp_rw_credential`). 2026-10-12 audit: a killed or switched-off door
 * said "Bind one to turn it on" under a card saying nothing here can; the
 * unbound denial printed twice; the select rested on the action "Unbind".
 * Now an off door says binding does not open it, the card's words print only
 * where no notice repeats them, the select rests on "Not bound", and
 * unbinding is its own confirmed button.
 *
 * @param string $rw_state The status state's `rw_state` ('' when unknown).
 * @param string $explain  The write card's own explanation (escaped HTML), painted for an off door.
 * @return string
 */
function mcp_connect_rw_binding_html( $rw_state = '', $explain = '' ) {
	$bound_uuid = function_exists( 'sn_mcp_rw_bound_uuid' ) ? sn_mcp_rw_bound_uuid() : '';
	$passwords  = class_exists( 'WP_Application_Passwords' )
		? (array) \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() )
		: array();

	$bound_password = null;
	if ( '' !== $bound_uuid ) {
		foreach ( $passwords as $pw ) {
			if ( is_array( $pw ) && ! empty( $pw['uuid'] ) && hash_equals( (string) $pw['uuid'], $bound_uuid ) ) {
				$bound_password = $pw;
				break;
			}
		}
	}

	if ( in_array( (string) $rw_state, array( 'constant_killed', 'option_off' ), true ) ) {
		$status = \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'The write door is off.', 'signal-and-noise-tools' ) ) . '</b> ' . (string) $explain . ' ' . \snt_kit_esc( __( 'Binding a credential does not open it while it is off.', 'signal-and-noise-tools' ) ) );
	} elseif ( '' === $bound_uuid ) {
		$status = \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'The write door is INACTIVE.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( __( 'No Application Password is bound to it yet, so every call to /mcp-rw is denied. Bind one to turn it on.', 'signal-and-noise-tools' ) ) );
	} elseif ( null !== $bound_password ) {
		$last_used = ! empty( $bound_password['last_used'] ) ? (int) $bound_password['last_used'] : 0;
		$when      = ( $last_used > 0 && function_exists( 'human_time_diff' ) )
			? sprintf( /* translators: %s: relative time element. */ \snt_kit_esc( __( 'Last used %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( $last_used ) )
			: \snt_kit_esc( __( 'Never used yet.', 'signal-and-noise-tools' ) );
		// The tile already says the door is bound; here, a plain line, not a second banner.
		$status = '<p class="snt-prose"><b>' . sprintf( /* translators: %s: bound Application Password name. */ \snt_kit_esc( __( 'The write door is bound to %s.', 'signal-and-noise-tools' ) ), \snt_kit_esc( (string) ( $bound_password['name'] ?? '' ) ) ) . '</b> '
			. $when . ' ' . \snt_kit_esc( __( 'Rotate this Application Password periodically: anyone who holds it can call the write door.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$status = \snt_kit_notice( 'warn', \snt_kit_esc( __( 'A write-door credential is bound, but it no longer matches any of your own Application Passwords: it may have been revoked, or belongs to a different user. Bind a current one.', 'signal-and-noise-tools' ) ) );
	}

	if ( empty( $passwords ) ) {
		$profile = function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#application-passwords-section' : '';
		return \snt_kit_section(
			__( 'Write-door credential', 'signal-and-noise-tools' ),
			$status . '<p class="snt-prose">' . \snt_kit_esc( __( 'You have no Application Passwords yet: create one under your profile first.', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_door( __( 'Your profile', 'signal-and-noise-tools' ), $profile ) . '</p>',
			__( 'Which Application Password may call the write door', 'signal-and-noise-tools' )
		);
	}

	$options = function_exists( 'sn_admin_mcp_rw_options' ) ? sn_admin_mcp_rw_options( $passwords, $bound_uuid, null !== $bound_password ) : array();
	$field   = \snt_kit_field(
		'select',
		'sn_mcp_rw_uuid',
		__( 'Application Password', 'signal-and-noise-tools' ),
		null !== $bound_password ? $bound_uuid : '',
		array( 'options' => $options, 'hint' => __( 'The same list as your profile’s Application Passwords: this only scopes one of them to the write door.', 'signal-and-noise-tools' ) )
	);
	$form    = \snt_kit_form( 'bind_mcp_rw_credential', $field, array( 'submit' => __( 'Save write-door credential', 'signal-and-noise-tools' ) ) );
	$unbind  = '' === $bound_uuid ? '' : \snt_kit_form(
		'bind_mcp_rw_credential',
		'',
		array(
			'submit'  => __( 'Unbind', 'signal-and-noise-tools' ),
			'hidden'  => array( 'sn_mcp_rw_uuid' => '' ),
			'confirm' => __( 'Unbind the write-door credential? Every write-door call is denied until you bind one again.', 'signal-and-noise-tools' ),
			'danger'  => true,
		)
	);
	return \snt_kit_section( __( 'Write-door credential', 'signal-and-noise-tools' ), $status . $form . $unbind, __( 'Which Application Password may call the write door', 'signal-and-noise-tools' ) );
}

/**
 * The three doors' live lists (read, write, remote), for AI › Agent tools
 * (ai-agent-tools-doors.php paints them). Null when a reader is not loaded:
 * unknown, never an empty door. The read stays in this file because the door
 * manifest suite lists the files allowed to name the allowlist functions.
 *
 * @return array{read:?array,rw:?array,remote:?array}
 */
function mcp_connect_door_slugs() {
	return array(
		'read'   => function_exists( 'sn_mcp_allowlist' ) ? array_values( (array) sn_mcp_allowlist() ) : null,
		'rw'     => function_exists( 'sn_mcp_rw_allowlist' ) ? array_values( (array) sn_mcp_rw_allowlist() ) : null,
		'remote' => function_exists( 'sn_mcp_remote_slugs' ) ? array_values( (array) sn_mcp_remote_slugs() ) : null,
	);
}

/**
 * The footnote: Connector Approvals is a different thing, then where the tool
 * lists live. One quiet paragraph, not a callout beside a link card.
 *
 * @return string
 */
function mcp_connect_footer_html() {
	$approvals = \snt_kit_esc( __( 'Tools → Connector Approvals (if the AI plugin is active) gates OUTBOUND use of this site’s configured AI-provider connectors by server-side plugin and theme code: it decides which of your plugins may spend against your Anthropic, OpenAI, or Google key. It has nothing to do with an external MCP client connecting IN. That inbound grant is the Application Password each client signs in with.', 'signal-and-noise-tools' ) );
	$links     = \snt_kit_go( __( 'Agent tools: every tool each door exposes, and its calls', 'signal-and-noise-tools' ), array( 'sub' => 'agent-tools' ) )
		. ' · ' . \snt_kit_door( __( 'Tools menu: Abilities Explorer, if the AI plugin adds it', 'signal-and-noise-tools' ), function_exists( 'admin_url' ) ? admin_url( 'tools.php' ) : '' )
		. ' · ' . \snt_kit_link( __( 'The Abilities catalog (this repo’s docs)', 'signal-and-noise-tools' ), 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/ai-abilities-catalog.md' );
	return '<p class="snt-hint"><b>' . \snt_kit_esc( __( 'Not the same as Connector Approvals', 'signal-and-noise-tools' ) ) . '.</b> ' . $approvals . '</p><p class="snt-hint">' . $links . '</p>';
}
