<?php
/**
 * S&N Dashboard, Monitoring > Analytics: the collector and exclusion
 * settings, folds in the Setup card since 2026-10-16 (the summary is the
 * classic fold's snapshot), and the This device control the Your devices
 * card paints.
 *
 * Moved out of monitoring-analytics-parts.php when the folds came out
 * (2026-10-11, owner rule: no collapsible sections). Each setting is an open
 * section: the short heading, ONE line of current state as the description
 * (the classic fold's own snapshot function, `snt_an_*_snapshot()`, called
 * as is), then the form. Readers, field names and sn_action values are the
 * classic renderer's, unchanged.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Collector endpoint (v10.46.0 on the classic leaf): where every beacon posts.
 *
 * @return array<string,mixed> A Setup fold (snt_kit_setup_card()).
 */
function analytics_collector_html() {
	$rss       = function_exists( '\sn_rss_tracker_settings' ) ? (array) \sn_rss_tracker_settings() : array();
	$collector = (string) ( $rss['collector_url'] ?? '' );
	$default   = function_exists( '\home_url' ) ? \home_url( '/_sn/px' ) : '';

	$fields = \snt_kit_field(
		'url',
		'sn_an_collector_url',
		__( 'Endpoint URL', 'signal-and-noise-tools' ),
		$collector,
		array(
			'required' => true,
			'hint'     => __( 'Defaults to this site’s own endpoint. If WordPress cannot reach the site domain through the edge, point this at the Worker’s *.workers.dev URL instead.', 'signal-and-noise-tools' ),
		)
	);
	if ( '' !== $default && $collector !== $default ) {
		/* translators: %s: the default collector URL for this site. */
		$fields .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Site default: %s', 'signal-and-noise-tools' ) ), '<os-code>' . \snt_kit_esc( $default ) . '</os-code>' ) . '</p>';
	}

	/* translators: 1: the worker route; 2: the shared-token constant name. */
	$body  = '<p class="snt-prose">' . \snt_kit_esc( sprintf( __( 'Where every first-party beacon on this site posts: the Cloudflare Worker’s %1$s route. Authenticated with the shared %2$s constant from wp-config.php.', 'signal-and-noise-tools' ), '/_sn/px', 'SN_BEACON_TOKEN' ) ) . '</p>';
	$body .= \snt_kit_form( 'analytics_collector_save', $fields, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );

	// Opens while unset, as the classic fold does.
	return array( 'title' => __( 'Collector endpoint', 'signal-and-noise-tools' ), 'summary' => \snt_an_collector_snapshot(), 'body' => $body, 'open' => '' === $collector );
}

/**
 * Roles kept out (the classic "Exclude my own visits"): a role allow-list.
 *
 * A shared `sn_exclude_roles[]` name per checkbox (the classic markup) is
 * silent by construction in the runtime: OsForm.getValues() keys by name, so
 * N controls sharing one name collapse to ONE key, and os-checkbox-label has
 * no `value` prop, so the slug is dropped and a save would clear every
 * excluded role. Each role gets its OWN indexed name instead, carrying the
 * slug as an `<os-select>` value, so the handler receives
 * `sn_exclude_roles => array( 'subscriber', ... )`, the shape
 * sn_handle_analytics_exclude_save() expects.
 *
 * @return array<string,mixed> A Setup fold (snt_kit_setup_card()).
 */
function analytics_exclusion_html() {
	$roles    = function_exists( '\sn_beacon_excludable_roles' ) ? \sn_beacon_excludable_roles() : array();
	$excluded = (array) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.exclude_roles', array() ) : array() );
	$heading  = __( 'Roles kept out', 'signal-and-noise-tools' );

	$intro = '<p class="snt-prose">' . \snt_kit_esc( __( 'Logged-in visits never count as readers, whatever the role: the collector keeps them out at the edge. The roles you select here get one more thing: when they log in, their browser is marked as the owner’s, so their visits after logging out are kept out too. Forward-only: visits already recorded are unaffected.', 'signal-and-noise-tools' ) ) . '</p>';

	if ( empty( $roles ) ) {
		return array( 'title' => $heading, 'summary' => \snt_an_exclusion_snapshot(), 'body' => $intro . '<p class="snt-hint">' . \snt_kit_esc( __( 'No roles available on this site.', 'signal-and-noise-tools' ) ) . '</p>' );
	}

	$fields = '';
	$i      = 0;
	foreach ( $roles as $slug => $name ) {
		$fields .= \snt_kit_field(
			'select',
			'sn_exclude_roles[' . $i++ . ']',
			$name . ' (' . $slug . ')',
			in_array( $slug, $excluded, true ) ? $slug : '',
			array(
				'options' => array(
					''    => __( 'Counted', 'signal-and-noise-tools' ),
					$slug => __( 'Excluded', 'signal-and-noise-tools' ),
				),
			)
		);
	}
	$status = ( function_exists( '\sn_beacon_owner_current_user_excluded' ) && \sn_beacon_owner_current_user_excluded() )
		? '<p class="snt-hint" full-width><b>' . \snt_kit_esc( __( 'Your role is selected: your logged-in visits are kept out, and this browser is marked as yours when you log in.', 'signal-and-noise-tools' ) ) . '</b></p>'
		: '<p class="snt-hint" full-width>' . \snt_kit_esc( __( 'Your role is not selected: your logged-in visits are still kept out, but this browser is not marked for your logged-out visits.', 'signal-and-noise-tools' ) ) . '</p>';
	$footnote = '<p class="snt-hint" full-width>' . \snt_kit_esc( __( 'Your logged-in views never count as readers: the collector recognizes the WordPress login cookie, even on a page served from cache. To prove a device when you are logged out, enroll it under Your devices.', 'signal-and-noise-tools' ) ) . '</p>';

	$form = \snt_kit_form( 'analytics_exclude_save', $fields . $status . $footnote, array( 'submit' => __( 'Save exclusion', 'signal-and-noise-tools' ), 'columns' => 'auto' ) );
	return array( 'title' => $heading, 'summary' => \snt_an_exclusion_snapshot(), 'body' => $intro . $form );
}

/**
 * This device: excluded even when logged out, or counted when logged out,
 * and the one action that flips it (2026-10-12 audit: the classic box had
 * it, S&N Home did not, so a device could not be flagged here). Only for a
 * user whose role is excluded, as on the classic box. Its own form, because
 * the role form's submit is Save exclusion; `analytics_owner_device` sets
 * the same cookie the classic link does.
 *
 * @return string
 */
function analytics_device_html() {
	if ( ! function_exists( '\sn_beacon_owner_current_user_excluded' ) || ! \sn_beacon_owner_current_user_excluded() || ! function_exists( '\sn_owner_device_flagged' ) ) {
		return '';
	}
	$flagged = \sn_owner_device_flagged();
	// One line: the state, then its compact button beside it (2026-10-16).
	return '<os-cluster gap="8" class="snt-actions"><p class="snt-hint">' . \snt_kit_esc( $flagged ? __( 'This device is excluded even when logged out.', 'signal-and-noise-tools' ) : __( 'This device is counted when logged out.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_form(
			'analytics_owner_device',
			\snt_kit_field( 'hidden', 'sn_owner_device', '', $flagged ? 'forget' : 'mark' ),
			array( 'submit' => $flagged ? __( 'Count this device again', 'signal-and-noise-tools' ) : __( 'Exclude this device', 'signal-and-noise-tools' ), 'inline' => true )
		) . '</os-cluster>';
}
