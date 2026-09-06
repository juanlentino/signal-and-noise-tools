<?php
/**
 * S&N Dashboard — Monitoring → Search Console, painted from the kit.
 *
 * The classic leaf (inc/search-console-admin.php, `snt_gsc_render_settings_section()`
 * + `snt_gsc_render_property_form()`) paints two classic `<form>`s: the
 * credential form (service-account JSON textarea, `sn_action=gsc_credential_save`,
 * plus a second submit `gsc_test` once an identity parses) and the property
 * form (property `<select>`, `sn_action=gsc_property_save`, plus a second
 * submit `gsc_sync` once a property is chosen). `gsc_test` and `gsc_sync`
 * read nothing from $_POST (both handlers `unset( $post )` immediately), so
 * here they paint as standalone action buttons instead of a second submit
 * crammed into an `<os-form>` that only supports one; the field-carrying
 * actions (`gsc_credential_save`, `gsc_property_save`) stay `snt_kit_form()`.
 * Same readers, same fields, same actions, same three-state credential card
 * and three-state scheduled-sync paragraph.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

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
		? \snt_kit_badge( 'ok', __( 'openssl available', 'signal-and-noise-tools' ) )
		: \snt_kit_badge( 'warn', __( 'openssl_sign() missing — this credential cannot mint a token on this host', 'signal-and-noise-tools' ) );
	return \snt_kit_kv(
		array(
			array( 'label' => __( 'Service account', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['client_email'] ?? '' ) ),
			array( 'label' => __( 'Google Cloud project', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['project_id'] ?? '' ) ),
			array( 'label' => __( 'Key ID', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['private_key_id'] ?? '' ) ),
			array( 'label' => __( 'Key fingerprint', 'signal-and-noise-tools' ), 'value' => (string) ( $identity['key_fingerprint'] ?? '' ) ),
			array( 'label' => __( 'Signing (RS256)', 'signal-and-noise-tools' ), 'value' => $signing, 'html' => true ),
		)
	);
}

/**
 * The credential section: identity card / unparseable warning / onboarding
 * steps, the textarea field, Save, and Test connection once an identity exists.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_credential_html( array $s ) {
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'A Google Cloud service-account key, granted read access to the Search Console property. Nothing here queries Google yet — this stores the credential the search reports will use.', 'signal-and-noise-tools' ) ) . '</p>';

	if ( null !== $s['identity'] ) {
		$out .= search_console_identity_html( (array) $s['identity'] );
		$out .= '<p class="snt-prose">' . \snt_kit_esc( __( 'The private key is never displayed. To change the credential, paste a whole new key file below; to remove it, type clear.', 'signal-and-noise-tools' ) ) . '</p>';
	} elseif ( $s['stored'] ) {
		$out .= '<p class="snt-prose">' . \snt_kit_badge( 'warn', __( 'A credential is stored but no longer parses as a service-account key. Paste a fresh one, or type clear to remove it.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$out .= '<p class="snt-prose">' . \snt_kit_badge( 'neutral', __( 'Not configured.', 'signal-and-noise-tools' ) ) . '</p>';
		$steps = array(
			__( 'In Google Cloud, ENABLE the "Google Search Console API" for the project. Skipping this returns 403 later even when everything else is correct — the error looks exactly like a missing permission.', 'signal-and-noise-tools' ),
			__( 'In that same project, create a service account and download a JSON key.', 'signal-and-noise-tools' ),
			__( 'In Search Console → Settings → Users and permissions, add that service account\'s email as a user on the property.', 'signal-and-noise-tools' ),
			__( 'Paste the whole JSON key file below, then Test connection.', 'signal-and-noise-tools' ),
		);
		$out .= '<ol class="snt-plain">' . implode( '', array_map( static function ( $step ) { return '<li>' . \snt_kit_esc( $step ) . '</li>'; }, $steps ) ) . '</ol>';
	}

	$placeholder = $s['stored']
		? __( 'Paste a fresh key file to replace; type clear to remove; leave empty to keep the current one', 'signal-and-noise-tools' )
		: __( '{ "type": "service_account", … }', 'signal-and-noise-tools' );
	$field = \snt_kit_field( 'textarea', 'sn_gsc_credential', __( 'Service-account JSON', 'signal-and-noise-tools' ), '', array( 'rows' => 8, 'placeholder' => $placeholder ) );
	$out  .= \snt_kit_form( 'gsc_credential_save', $field, array( 'submit' => __( 'Save credential', 'signal-and-noise-tools' ) ) );
	if ( null !== $s['identity'] ) {
		$out .= \snt_kit_action_button( __( 'Test connection', 'signal-and-noise-tools' ), 'gsc_test' );
	}
	return $out;
}

/**
 * The property picker: the last Test connection's site list, or a nudge to
 * run it; the current property when one is set.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_property_html( array $s ) {
	$out = '';
	if ( is_array( $s['last'] ) && empty( $s['last']['ok'] ) ) {
		$out .= '<p class="snt-prose">' . \snt_kit_badge( 'warn', (string) ( $s['last']['error'] ?? '' ) ) . '</p>';
	}
	$sites = (array) $s['sites'];
	if ( empty( $sites ) ) {
		$out .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Run Test connection above to list the properties this service account can read.', 'signal-and-noise-tools' ) ) . '</p>';
		if ( '' !== $s['current'] ) {
			/* translators: %s: the stored property string. */
			$out .= '<p class="snt-prose">' . sprintf( \snt_kit_esc( __( 'Currently reading: %s', 'signal-and-noise-tools' ) ), \snt_kit_code( (string) $s['current'], false ) ) . '</p>';
		}
		return $out;
	}
	$options = array( '' => __( '— choose a property —', 'signal-and-noise-tools' ) );
	foreach ( $sites as $site ) {
		$url = (string) ( $site['siteUrl'] ?? '' );
		if ( '' === $url ) {
			continue;
		}
		$options[ $url ] = $url . ' (' . (string) ( $site['permissionLevel'] ?? 'unknown' ) . ')';
	}
	$field = \snt_kit_field( 'select', 'sn_gsc_property', __( 'Read search data for', 'signal-and-noise-tools' ), (string) $s['current'], array( 'options' => $options ) );
	$out  .= \snt_kit_form( 'gsc_property_save', $field, array( 'submit' => __( 'Use this property', 'signal-and-noise-tools' ) ) );
	return $out;
}

/**
 * The Sync sub-section, shown once a property is chosen: sync status, the
 * scheduled-run paragraph (three honest states), and the Sync now button.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_sync_html( array $s ) {
	$out  = \snt_kit_tag( 'h3', array( 'class' => 'snt-col__h' ), \snt_kit_esc( __( 'Sync', 'signal-and-noise-tools' ) ) );
	$out .= null === $s['data']
		? '<p class="snt-prose">' . \snt_kit_badge( 'neutral', __( 'Never synced.', 'signal-and-noise-tools' ) ) . '</p>'
		: '<p class="snt-prose">' . \snt_kit_esc(
			sprintf(
				/* translators: 1: start date, 2: end date, 3: human-readable age. */
				__( 'Window %1$s to %2$s, synced %3$s ago.', 'signal-and-noise-tools' ),
				(string) $s['data']['window']['start'],
				(string) $s['data']['window']['end'],
				\human_time_diff( (int) $s['data']['synced_at'], time() )
			)
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
		/* translators: %s: human-readable time until the next scheduled run. */
		$out .= sprintf( \snt_kit_esc( __( 'Scheduled: daily, next in %s.', 'signal-and-noise-tools' ) ), \snt_kit_esc( \human_time_diff( time(), (int) $s['next'] ) ) );
		if ( null === $s['status'] ) {
			$out .= ' ' . \snt_kit_esc( __( 'The scheduled sync has not fired yet.', 'signal-and-noise-tools' ) );
		} elseif ( ! empty( $s['status']['ok'] ) ) {
			/* translators: %s: human-readable age of the last scheduled run. */
			$out .= ' ' . \snt_kit_esc( sprintf( __( 'Last scheduled run %s ago: ok.', 'signal-and-noise-tools' ), \human_time_diff( (int) $s['status']['ran_at'], time() ) ) );
		} else {
			$out .= ' ' . \snt_kit_esc(
				sprintf(
					/* translators: 1: age, 2: error message. */
					__( 'Last scheduled run %1$s ago FAILED: %2$s', 'signal-and-noise-tools' ),
					\human_time_diff( (int) $s['status']['ran_at'], time() ),
					isset( $s['status']['message'] ) ? (string) $s['status']['message'] : (string) ( $s['status']['code'] ?? 'unknown' )
				)
			);
		}
	} else {
		$out .= \snt_kit_esc( __( 'No scheduled sync: it schedules itself once a credential is stored and a property chosen.', 'signal-and-noise-tools' ) );
	}
	$out .= '</p>';
	$out .= \snt_kit_action_button( __( 'Sync now', 'signal-and-noise-tools' ), 'gsc_sync' );
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Data appears in Analytics → Search, and as impressions/position columns beside Top pages.', 'signal-and-noise-tools' ) ) . '</p>';
	return $out;
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_search_console( array $ctx ) {
	unset( $ctx );
	$s   = search_console_state();
	$out = \snt_kit_section(
		__( 'Search Console credential', 'signal-and-noise-tools' ),
		search_console_credential_html( $s )
	);

	if ( null !== $s['identity'] ) {
		$inner = search_console_property_html( $s );
		if ( '' !== $s['current'] ) {
			$inner .= search_console_sync_html( $s );
		}
		$out .= \snt_kit_section( __( 'Property', 'signal-and-noise-tools' ), $inner );
	}
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/search-console'] = __NAMESPACE__ . '\\paint_monitoring_search_console';
		return $painters;
	}
);
