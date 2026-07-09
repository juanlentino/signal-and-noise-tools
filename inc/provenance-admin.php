<?php
/**
 * Signal & Noise Tools — Notes provenance: admin (Tools tab + live status).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the admin live-status payload: every commit still pending/unanchored,
 * plus genesis anchor status.
 *
 * @return array
 */
function sn_prov_admin_status() {
	$ids = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 100,
		'fields'      => 'ids',
		'meta_key'    => SN_PROV_UID_META,
	) );
	$pending = array();
	foreach ( $ids as $id ) {
		foreach ( sn_prov_get_chain( (int) $id ) as $c ) {
			$status = (string) ( $c['status'] ?? '' );
			if ( 'pending' === $status || 'unanchored' === $status ) {
				$pending[] = array(
					'post_id'      => (int) $id,
					'note_uid'     => (string) get_post_meta( (int) $id, SN_PROV_UID_META, true ),
					'version'      => (int) ( $c['version'] ?? 0 ),
					'status'       => $status,
					'committed_at' => (string) ( $c['committed_at'] ?? '' ),
				);
			}
		}
	}
	$genesis = get_option( 'sn_prov_genesis', array() );
	return array(
		'pending' => $pending,
		'genesis' => is_array( $genesis ) ? $genesis : array(),
	);
}

/**
 * Register the nonce-gated live-status REST route (manage_options only).
 */
function sn_prov_admin_register_status_route() {
	register_rest_route( 'sn-prov/v1', '/status', array(
		'methods'             => 'GET',
		'callback'            => 'sn_prov_admin_status_handler',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
}
add_action( 'rest_api_init', 'sn_prov_admin_register_status_route' );

/**
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sn_prov_admin_status_handler( $request ) {
	return new WP_REST_Response( sn_prov_admin_status(), 200 );
}

/**
 * Tools → Provenance section body. The live stepper is hydrated by
 * assets/provenance-admin.js polling the /status endpoint.
 */
function sn_admin_render_provenance_section() {
	$pubkey = function_exists( 'sn_prov_pubkey_b64' ) ? sn_prov_pubkey_b64() : '';
	echo '<div class="sn-prov-admin" data-endpoint="' . esc_attr( esc_url_raw( rest_url( 'sn-prov/v1/status' ) ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';
	echo '<h2>' . esc_html__( 'Provenance', 'signal-and-noise-tools' ) . '</h2>';
	echo '<div class="sn-prov-live" aria-live="polite"><p>' . esc_html__( 'Loading anchor status…', 'signal-and-noise-tools' ) . '</p></div>';
	echo '<p class="sn-prov-key"><strong>' . esc_html__( 'Public key', 'signal-and-noise-tools' ) . ':</strong> <code>' . esc_html( $pubkey ) . '</code></p>';
	echo '</div>';
}

/**
 * Enqueue the live-stepper CSS/JS ONLY on the plugin's admin screens
 * (external files — never inline; screen-gated per house rule).
 *
 * @param string $hook_suffix
 */
function sn_prov_admin_enqueue( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_hooks' ) || ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	$base = plugins_url( 'assets/', SNT_PATH . 'signal-and-noise-tools.php' );
	wp_enqueue_style( 'sn-provenance-admin', $base . 'provenance-admin.css', array(), SNT_VERSION );
	wp_enqueue_script( 'sn-provenance-admin', $base . 'provenance-admin.js', array(), SNT_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'sn_prov_admin_enqueue' );
