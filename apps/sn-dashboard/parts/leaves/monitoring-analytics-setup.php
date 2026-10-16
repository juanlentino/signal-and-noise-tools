<?php
/**
 * S&N Dashboard, Monitoring > Analytics: the Setup card and the leaf's one
 * fold helper.
 *
 * 2026-10-16 (owner: "a mess. Design, design, design."): the settings are
 * setup, changed once in a while, so each is an earned fold in one Setup card
 * (inc/openstation-kit-fold.php): the closed summary is the setting's current
 * state, and a fold opens itself when something inside needs the reader
 * (credentials while the pipeline is incomplete, the collector while unset,
 * the Worker while its version or salt window was not read). Warnings stay
 * on the Pipeline card; a fold never holds the only statement of a problem.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Every fold on this leaf goes through here (tests/sn-dashboard-no-folds.php
 * lists this file once, with the reason).
 *
 * @param string $heading The setting's heading.
 * @param string $summary Its current state, painted on the closed fold.
 * @param string $inner   The setting's body.
 * @param bool   $open    True when something inside needs the reader now.
 * @return string
 */
function analytics_fold( $heading, $summary, $inner, $open = false ) {
	return \snt_kit_fold( $heading, $summary, $inner, $open );
}

/**
 * The Setup card.
 *
 * @param array<string,mixed>                                      $ctx    Leaf context.
 * @param array{0:array<int,array<string,mixed>>,1:string,2:string} $worker analytics_worker_rows().
 * @param array<string,mixed>|null                                  $salt   sn_salt_window_get(), or null.
 * @return string
 */
function analytics_setup_html( array $ctx, array $worker, $salt ) {
	$tab = (string) ( $ctx['tab'] ?? 'monitoring' );
	return \snt_kit_section(
		__( 'Setup', 'signal-and-noise-tools' ),
		analytics_credentials_html( $tab )
			. analytics_collector_html()
			. analytics_exclusion_html()
			. analytics_tuning_html()
			. analytics_funnels_html()
			. analytics_edge_worker_html( $ctx, $worker, $salt )
			. analytics_mirrors_html( $tab ),
		__( 'Each line is the setting as it stands; open one to change it.', 'signal-and-noise-tools' )
	);
}

/**
 * Credentials: what the reads use, the Account ID, where both are set, and
 * Test connection. Open while the pipeline is incomplete, as on the classic page.
 *
 * @param string $tab The painting tab.
 * @return string
 */
function analytics_credentials_html( $tab ) {
	$configured = function_exists( '\sn_analytics_config' ) && \sn_analytics_config();
	$body       = \snt_kit_kv( analytics_reads_rows() )
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'The API token and the Account ID are set with every other key under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => $tab ) ) . '. ' . \snt_kit_esc( __( 'Analytics Engine reads use the Cloudflare API token (Account › Account Analytics › Read on it) unless an analytics token override is set there.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<footer>' . \snt_kit_action_button( __( 'Test connection', 'signal-and-noise-tools' ), 'analytics_test', array( 'disabled' => ! $configured ) ) . '</footer>';
	$summary    = function_exists( '\snt_an_credentials_snapshot' ) ? \snt_an_credentials_snapshot() : ( $configured ? __( 'Configured', 'signal-and-noise-tools' ) : __( 'Not configured', 'signal-and-noise-tools' ) );
	$open       = function_exists( '\sn_analytics_pipeline_complete' ) && ! \sn_analytics_pipeline_complete();
	return analytics_fold( __( 'Credentials', 'signal-and-noise-tools' ), $summary, $body, $open );
}

/**
 * Edge worker and salt: the Worker's facts, the identity salt window, and
 * Re-check now (a `go` back to this leaf that reads both live).
 *
 * @param array<string,mixed>                                      $ctx    Leaf context.
 * @param array{0:array<int,array<string,mixed>>,1:string,2:string} $worker analytics_worker_rows().
 * @param array<string,mixed>|null                                  $salt   sn_salt_window_get(), or null.
 * @return string
 */
function analytics_edge_worker_html( array $ctx, array $worker, $salt ) {
	$salt_ok = is_array( $salt ) && 'ok' === (string) ( $salt['state'] ?? '' );
	$state   = (string) ( $worker[2] ?? '' );
	$body    = \snt_kit_kv( array_merge( (array) $worker[0], analytics_salt_rows( $salt ) ) )
		. '<footer>' . \snt_kit_button( __( 'Re-check now', 'signal-and-noise-tools' ), 'go', array( 'args' => array( 'sub' => (string) ( $ctx['sub'] ?? 'analytics' ), 'sn_worker_recheck' => '1' ) ) ) . '</footer>';
	$summary = (string) ( $worker[0][0]['value'] ?? '' ) . ' · ' . ( $salt_ok ? __( 'salt window read', 'signal-and-noise-tools' ) : __( 'salt window not read', 'signal-and-noise-tools' ) );
	return analytics_fold( __( 'Edge worker and salt', 'signal-and-noise-tools' ), $summary, $body, 'ok' !== $state || ( null !== $salt && ! $salt_ok ) );
}
