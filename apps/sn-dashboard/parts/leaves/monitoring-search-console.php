<?php
/**
 * S&N Dashboard — Monitoring → Search Console, painted from the kit.
 *
 * Readings and actions, the twin of inc/search-console-admin.php: the
 * credential's identity card (or its two other states) with Test connection,
 * the property in force, Sync with Sync now, and Unanswered searches.
 * `gsc_test` and `gsc_sync` read nothing from $_POST, so they are standalone
 * action buttons.
 *
 * 2026-11: the two forms that SET something (the service-account JSON,
 * `gsc_credential_save`, and the property picker, `gsc_property_save`) moved
 * to Settings > Accounts and connections (settings-accounts-search-console.php),
 * which calls search_console_credential_form_html() and
 * search_console_property_picker_html() from this file, unchanged.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/settings-accounts-parts.php'; // accounts_moved_html(): the line saying where the credential form and the picker went.
require_once __DIR__ . '/monitoring-search-console-unanswered.php'; // Unanswered searches, the row under Sync

/**
 * The module's state, read the way the classic leaf reads it.
 *
 * @return array<string,mixed>
 */
function search_console_state() {
	$identity = function_exists( 'snt_gsc_credential_identity' ) ? \snt_gsc_credential_identity() : null;
	$stored   = function_exists( 'snt_gsc_credential_raw' ) ? '' !== \snt_gsc_credential_raw() : false;
	$last     = \get_transient( 'snt_gsc_last_test' );
	$current  = (string) \sn_setting( 'search_console.property', '' );
	$data     = function_exists( 'snt_gsc_data' ) ? \snt_gsc_data() : null;
	$sites    = ( is_array( $last ) && ! empty( $last['ok'] ) ) ? (array) ( $last['sites'] ?? array() ) : array();
	$next     = \wp_next_scheduled( \SNT_GSC_SYNC_HOOK );
	$status   = \snt_gsc_sync_last_status();
	return compact( 'identity', 'stored', 'last', 'current', 'data', 'sites', 'next', 'status' );
}

/**
 * The identity card + signing-readiness row (kv), for a parsed credential.
 *
 * @param array<string,mixed> $identity From snt_gsc_credential_identity().
 * @return string
 */
function search_console_identity_html( array $identity ) {
	$signing = ! empty( $identity['signing_ready'] )
		? \snt_kit_badge( 'ok', __( 'Available', 'signal-and-noise-tools' ) )
		: \snt_kit_badge( 'warn', __( 'Not available: this host’s PHP cannot sign the token request, so the credential cannot be used here', 'signal-and-noise-tools' ) );
	return \snt_kit_kv(
		array(
			array( 'label' => __( 'Service account', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['client_email'] ?? '' ) ),
			array( 'label' => __( 'Google Cloud project', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['project_id'] ?? '' ) ),
			array( 'label' => __( 'Key ID', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['private_key_id'] ?? '' ) ),
			array( 'label' => __( 'Key fingerprint', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['key_fingerprint'] ?? '' ) ),
			array( 'label' => __( 'Token signing', 'signal-and-noise-tools' ), 'value' => $signing, 'html' => true ),
		)
	);
}

/**
 * The credential readout: identity card / unparseable warning / onboarding
 * steps, and Test connection once an identity exists.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_credential_html( array $s ) {
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'A Google Cloud service-account key, granted read access to the Search Console property. Test connection lists the properties it can read; Sync now pulls their pages and queries, and a daily sync keeps them fresh.', 'signal-and-noise-tools' ) ) . '</p>';

	if ( null !== $s['identity'] ) {
		$out .= search_console_identity_html( (array) $s['identity'] );
		$out .= '<p class="snt-prose">' . \snt_kit_esc( \snt_gsc_copy()['never'] ) . '</p>';
	} elseif ( $s['stored'] ) {
		$out .= '<p class="snt-prose">' . \snt_kit_badge( 'warn', __( 'A credential is stored but no longer parses as a service-account key. Paste a fresh one, or type clear to remove it.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$out .= '<p class="snt-prose">' . \snt_kit_badge( 'neutral', __( 'Not configured.', 'signal-and-noise-tools' ) ) . '</p>';
		$steps = array(
			__( 'In Google Cloud, ENABLE the "Google Search Console API" for the project. Skipping this returns 403 later even when everything else is correct: the error looks exactly like a missing permission.', 'signal-and-noise-tools' ),
			__( 'In that same project, create a service account and download a JSON key.', 'signal-and-noise-tools' ),
			__( 'In Search Console → Settings → Users and permissions, add that service account\'s email as a user on the property.', 'signal-and-noise-tools' ),
			\snt_gsc_copy()['paste'],
		);
		$out .= \snt_kit_steps( array_map( '\snt_kit_esc', $steps ) );
	}
	if ( null !== $s['identity'] ) {
		$out .= \snt_kit_action_button( __( 'Test connection', 'signal-and-noise-tools' ), 'gsc_test' );
	}
	// 2026-11: the credential form and the property picker are settings of an
	// account and moved to Settings > Accounts and connections.
	return $out . accounts_moved_html( 'gsc', 'monitoring' );
}

/**
 * The credential form: the textarea field and Save.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_credential_form_html( array $s ) {
	$placeholder = $s['stored']
		? __( 'Paste a fresh key file to replace; type clear to remove; leave empty to keep the current one', 'signal-and-noise-tools' )
		: __( '{ "type": "service_account", … }', 'signal-and-noise-tools' );
	$field = \snt_kit_field( 'textarea', 'sn_gsc_credential', __( 'Service-account JSON', 'signal-and-noise-tools' ), '', array( 'rows' => 8, 'placeholder' => $placeholder ) );
	return \snt_kit_form( 'gsc_credential_save', $field, array( 'submit' => __( 'Save credential', 'signal-and-noise-tools' ) ) );
}

/**
 * The property this leaf reads: the last test's error when it failed, the
 * nudge to run it while there is no list, and the property in force. The
 * picker is on Settings > Accounts and connections since 2026-11.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_property_html( array $s ) {
	$copy = \snt_gsc_copy();
	$out  = '';
	if ( is_array( $s['last'] ) && empty( $s['last']['ok'] ) ) {
		$out .= '<p class="snt-prose">' . \snt_kit_badge( 'warn', (string) ( $s['last']['error'] ?? '' ) ) . '</p>';
	}
	if ( empty( $s['sites'] ) ) {
		$out .= '<p class="snt-prose">' . \snt_kit_esc( $copy['list'] ) . '</p>';
	}
	if ( '' !== $s['current'] ) {
		/* translators: %s: the stored property string. */
		$out .= '<p class="snt-prose">' . sprintf( \snt_kit_esc( __( 'Currently reading: %s', 'signal-and-noise-tools' ) ), \snt_kit_code( (string) $s['current'], false ) ) . '</p>';
	} elseif ( ! empty( $s['sites'] ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( $copy['none'] ) . '</p>';
	}
	return $out;
}

/**
 * The property picker, painted on Settings > Accounts and connections: the
 * last Test connection's site list in one form, or where to run the test.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_property_picker_html( array $s ) {
	$sites = (array) $s['sites'];
	if ( empty( $sites ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( \snt_gsc_copy()['list_at'] ) . ' ' . \snt_kit_go( __( 'Measurement › Search Console', 'signal-and-noise-tools' ), array( 'tab' => 'monitoring', 'sub' => 'search-console', 'current' => 'settings' ) ) . '.</p>';
	}
	$options = array( '' => __( 'Choose a property', 'signal-and-noise-tools' ) );
	foreach ( $sites as $site ) {
		$url = (string) ( $site['siteUrl'] ?? '' );
		if ( '' === $url ) {
			continue;
		}
		$options[ $url ] = $url . ' (' . (string) ( $site['permissionLevel'] ?? 'unknown' ) . ')';
	}
	$field = \snt_kit_field( 'select', 'sn_gsc_property', __( 'Read search data for', 'signal-and-noise-tools' ), (string) $s['current'], array( 'options' => $options ) );
	return \snt_kit_form( 'gsc_property_save', $field, array( 'submit' => __( 'Use this property', 'signal-and-noise-tools' ) ) );
}

/**
 * The Sync sub-section, shown once a property is chosen: sync status, the
 * scheduled-run paragraph (three honest states), and the Sync now button.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_sync_html( array $s ) {
	$out  = null === $s['data']
		? '<p class="snt-prose">' . \snt_kit_badge( 'neutral', __( 'Never synced.', 'signal-and-noise-tools' ) ) . '</p>'
		: '<p class="snt-prose">' . sprintf(
			/* translators: 1: start date, 2: end date, 3: relative time element. */
			\snt_kit_esc( __( 'Window %1$s to %2$s, synced %3$s.', 'signal-and-noise-tools' ) ),
			\snt_kit_esc( (string) $s['data']['window']['start'] ),
			\snt_kit_esc( (string) $s['data']['window']['end'] ),
			\snt_kit_relative_time( (int) $s['data']['synced_at'] )
		) . ' ' . \snt_kit_esc(
			sprintf(
				/* translators: 1: page count, 2: query count. */
				__( '%1$d pages, %2$d queries.', 'signal-and-noise-tools' ),
				count( (array) $s['data']['pages'] ),
				count( (array) $s['data']['queries'] )
			)
		) . '</p>';

	$out .= '<p class="snt-hint">';
	if ( $s['next'] ) {
		/* translators: %s: relative time element for the next scheduled run. */
		$out .= sprintf( \snt_kit_esc( __( 'Scheduled: daily, next %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( (int) $s['next'] ) );
		if ( null === $s['status'] ) {
			$out .= ' ' . \snt_kit_esc( __( 'The scheduled sync has not fired yet.', 'signal-and-noise-tools' ) );
		} elseif ( ! empty( $s['status']['ok'] ) ) {
			/* translators: %s: relative time element for the last scheduled run. */
			$out .= ' ' . sprintf( \snt_kit_esc( __( 'Last scheduled run %s: ok.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( (int) $s['status']['ran_at'] ) );
		} else {
			$out .= ' ' . sprintf(
				/* translators: 1: relative time element, 2: error message. */
				\snt_kit_esc( __( 'Last scheduled run %1$s FAILED: %2$s', 'signal-and-noise-tools' ) ),
				\snt_kit_relative_time( (int) $s['status']['ran_at'] ),
				\snt_kit_esc( isset( $s['status']['message'] ) ? (string) $s['status']['message'] : (string) ( $s['status']['code'] ?? 'unknown' ) )
			);
		}
	} else {
		$out .= \snt_kit_esc( __( 'No scheduled sync: it schedules itself once a credential is stored and a property chosen.', 'signal-and-noise-tools' ) );
	}
	$out .= '</p>';
	$out .= \snt_kit_action_button( __( 'Sync now', 'signal-and-noise-tools' ), 'gsc_sync' );
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Data appears in Analytics › Search, and as the Impressions and Position columns of the Top pages table.', 'signal-and-noise-tools' ) ) . '</p>';
	return $out;
}

/**
 * The leaf: readings and actions. The credential readout (identity card or
 * its two other states, Test connection, and the line saying where the
 * credential form went); once the credential parses, Property beside it, and
 * once a property is chosen, Property over Sync in that column, then
 * Unanswered searches on a row of its own.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_search_console( array $ctx ) {
	unset( $ctx );
	$s    = search_console_state();
	$cred = \snt_kit_section( __( 'Search Console credential', 'signal-and-noise-tools' ), search_console_credential_html( $s ) );
	if ( null === $s['identity'] ) {
		return $cred;
	}
	$property = \snt_kit_section( __( 'Property', 'signal-and-noise-tools' ), search_console_property_html( $s ) );
	if ( '' === $s['current'] ) {
		return \snt_kit_grid( array( $cred, $property ) );
	}
	// 2026-10-16: Unanswered searches is a wide table, so it takes the next
	// row alone (docs/kit-leaf-anatomy.md), under the Sync box whose window it reads.
	return \snt_kit_grid( array( $cred, \snt_kit_tag( 'os-stack', array( 'gap' => '18' ), $property . \snt_kit_section( __( 'Sync', 'signal-and-noise-tools' ), search_console_sync_html( $s ) ) ) ) )
		. search_console_unanswered_html();
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/search-console'] = __NAMESPACE__ . '\\paint_monitoring_search_console';
		return $painters;
	}
);
