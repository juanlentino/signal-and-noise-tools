<?php
/**
 * Signal & Noise Tools — Abilities run-path client (verb map + shared script).
 *
 * The abilities run controller enforces the HTTP verb by annotation
 * (validate_request_method: readonly => GET, destructive+idempotent => DELETE,
 * else POST). Client verbs must therefore track the SERVER's annotations —
 * hardcoded verbs drift: the v6.39.2 annotation-truthfulness fixes silently
 * turned every hardcoded-POST JS caller of a readonly ability into a 405, and
 * v7.7.0's palette change repeated the class. This module makes drift
 * impossible: the slug→verb map is derived from the live registry at enqueue
 * time and localized onto the shared 'snt-ability-run' script
 * (assets/snt-ability-run.js), which every JS caller depends on.
 *
 * tests/ability-run-client.php guards the whole arrangement, including that
 * '/wp-abilities/' appears in NO other script.
 *
 * @package SignalNoiseTools
 * @since 7.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derive the run-path HTTP verb from an ability's annotations.
 *
 * Byte-for-byte mirror of the run controller's validate_request_method():
 * readonly wins, then destructive+idempotent, then POST. empty() semantics
 * match the controller ("readonly => false" behaves like absent).
 *
 * @param array $annotations The ability's meta.annotations array.
 * @return string 'GET' | 'DELETE' | 'POST'.
 */
function snt_ability_verb( $annotations ) {
	$annotations = (array) $annotations;
	if ( ! empty( $annotations['readonly'] ) ) {
		return 'GET';
	}
	if ( ! empty( $annotations['destructive'] ) && ! empty( $annotations['idempotent'] ) ) {
		return 'DELETE';
	}
	return 'POST';
}

/**
 * Build the slug→verb map for every signal-noise/* ability in the registry.
 *
 * Foreign namespaces are excluded — we only vouch for our own registrations
 * (their authors may change annotations release-to-release).
 *
 * @return array<string,string> e.g. [ 'signal-noise/get-audit-log' => 'GET' ].
 */
function snt_ability_verb_map() {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return array();
	}

	$map = array();
	foreach ( wp_get_abilities() as $ability ) {
		$name = $ability->get_name();
		if ( 0 !== strpos( $name, 'signal-noise/' ) ) {
			continue;
		}
		$meta         = $ability->get_meta();
		$annotations  = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$map[ $name ] = snt_ability_verb( $annotations );
	}
	return $map;
}

/**
 * Register + localize the shared runner script.
 *
 * Priority 1 so consumer registrations (default 10 on the same hooks) can
 * declare 'snt-ability-run' as a dependency. Runs on both admin_enqueue_scripts
 * (admin tabs, desktop-mode, palette) and enqueue_block_editor_assets (the
 * ai-* post-editor buttons). Safe to call twice — registration is guarded.
 * The registry is live here: WP_Abilities_Registry initializes after `init`,
 * and both hooks fire well after it.
 */
function snt_ability_run_client_register() {
	if ( wp_script_is( 'snt-ability-run', 'registered' ) ) {
		return;
	}
	wp_register_script(
		'snt-ability-run',
		plugins_url( 'assets/snt-ability-run.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);
	wp_localize_script(
		'snt-ability-run',
		'sntAbilityRunData',
		array( 'verbs' => snt_ability_verb_map() )
	);
}
add_action( 'admin_enqueue_scripts', 'snt_ability_run_client_register', 1 );
add_action( 'enqueue_block_editor_assets', 'snt_ability_run_client_register', 1 );
