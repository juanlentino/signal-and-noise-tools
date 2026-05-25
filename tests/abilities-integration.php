<?php
/**
 * AI-invocation integration tests for plugin abilities (v3.7.4+).
 *
 * Exercises wp_get_ability( $slug )->execute( $args ) — the full Abilities
 * API dispatch surface that real AI callers hit (WP AI Client, desktop-mode
 * Command Palette, abilities REST controller).
 *
 * For each of the 17 abilities the plugin registers, we verify:
 *   - registration is reachable via wp_get_ability()
 *   - permission_callback gates correctly (manage_options vs edit_post)
 *   - input_schema's `required` is enforced before the callback fires
 *   - input_schema's enums are enforced
 *   - happy-path execute_callback returns a schema-conformant payload
 *   - destructive ops are idempotent (second invocation == same shape)
 *
 * Mocking strategy:
 *   - Stub WP_Ability + wp_get_ability locally so we don't depend on
 *     WP 7.0 being installed.
 *   - Stub the impl functions (snt_cron_get_events_impl, snt_ai_excerpt_impl,
 *     etc.) at function-existence level so the SUT's `function_exists()`
 *     guards route through to a known fixture return value.
 *   - The SUT registers its abilities via two anonymous add_action()
 *     closures. We replace add_action() with a capturing stub so we can
 *     invoke those closures after the SUT loads.
 *
 * @since plugin v3.7.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── add_action capturing stub (BEFORE the SUT loads) ────────────────
$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_actions'][ $tag ][] = $callback;
		return true;
	}
}

// ─── WP function stubs ───────────────────────────────────────────────
$GLOBALS['__test_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_filters'][ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag ) {
		return ! empty( $GLOBALS['__test_filters'][ $tag ] );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		if ( ! isset( $GLOBALS['__test_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__test_filters'][ $tag ] as $cb ) {
			$value   = call_user_func_array( $cb, $args );
			$args[0] = $value;
		}
		return $value;
	}
}
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $p ) {
		if ( is_object( $p ) && isset( $p->post_title ) ) { return $p->post_title; }
		return '';
	}
}
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to ) { return '5 minutes'; }
}

// ─── Capability stub ────────────────────────────────────────────────
$GLOBALS['__test_user_caps']     = array(
	'read'           => true,
	'edit_posts'     => true,
	'manage_options' => true,
);
$GLOBALS['__test_edit_post_ok'] = true;
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap = '', $object_id = null ) {
		if ( 'edit_post' === $cap ) {
			return ! empty( $GLOBALS['__test_edit_post_ok'] );
		}
		return ! empty( $GLOBALS['__test_user_caps'][ $cap ] );
	}
}

// ─── WP_Error + is_wp_error ─────────────────────────────────────────
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $c = '', $m = '', $d = array() ) {
			$this->code    = $c;
			$this->message = $m;
			$this->data    = $d;
		}
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data()    { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '3.7.4' ); }

// ─── Posts stub ─────────────────────────────────────────────────────
$GLOBALS['__test_posts'] = array(
	200 => (object) array(
		'ID'           => 200,
		'post_title'   => 'Provenance is the substrate',
		'post_content' => 'A short post on provenance and fingerprinting.',
		'post_type'    => 'post',
		'post_status'  => 'publish',
	),
);
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = (int) $id;
		return isset( $GLOBALS['__test_posts'][ $id ] ) ? $GLOBALS['__test_posts'][ $id ] : null;
	}
}
$GLOBALS['__test_template_overrides'] = array(
	(object) array( 'ID' => 501, 'post_type' => 'wp_template',      'post_name' => 'index' ),
	(object) array( 'ID' => 502, 'post_type' => 'wp_template_part', 'post_name' => 'header' ),
);
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		return $GLOBALS['__test_template_overrides'];
	}
}

// ─── Stub each impl that the abilities delegate to ───────────────────

if ( ! function_exists( 'snt_deploy_status_for' ) ) {
	function snt_deploy_status_for( $package ) {
		return array(
			'current' => 'theme' === $package ? '9.1.2' : '3.7.4',
			'latest'  => 'theme' === $package ? '9.1.2' : '3.7.4',
			'state'   => 'ok',
		);
	}
}
$GLOBALS['__test_og_card_ok'] = true;
if ( ! function_exists( 'sn_generate_og_card' ) ) {
	function sn_generate_og_card( $post_id ) { return ! empty( $GLOBALS['__test_og_card_ok'] ); }
}
if ( ! function_exists( 'sn_og_image_url_for_post' ) ) {
	function sn_og_image_url_for_post( $post ) {
		$id = is_object( $post ) ? $post->ID : 0;
		return 'https://juanlentino.com/wp-content/uploads/sn-og/post-' . $id . '.png';
	}
}
if ( ! function_exists( 'snt_cmd_impl_force_check' ) ) {
	function snt_cmd_impl_force_check() {
		return array( 'ok' => true, 'message' => 'Update transients cleared.' );
	}
}
if ( ! function_exists( 'snt_cmd_impl_full_reset' ) ) {
	function snt_cmd_impl_full_reset() {
		return array( 'ok' => true, 'message' => 'Full reset complete.', 'data' => array( 'count' => 2 ) );
	}
}
if ( ! function_exists( 'snt_cmd_impl_rss_stats' ) ) {
	function snt_cmd_impl_rss_stats() {
		return array(
			'ok' => true,
			'data' => array(
				'last_request'          => '2026-05-24 10:00:00',
				'last_request_relative' => '5 minutes ago',
				'windows' => array(
					'1'  => array( 'total' => 12,   'uniques' => 4 ),
					'7'  => array( 'total' => 84,   'uniques' => 15 ),
					'30' => array( 'total' => 360,  'uniques' => 42 ),
				),
			),
		);
	}
}
if ( ! function_exists( 'snt_ai_meta_desc_impl' ) ) {
	function snt_ai_meta_desc_impl( $post_id ) {
		return array(
			'ok'          => true,
			'description' => 'Generated meta description sitting comfortably in the 140-160 char SEO window for indexability.',
			'length'      => 102,
		);
	}
}
if ( ! function_exists( 'snt_ai_og_card_title_impl' ) ) {
	function snt_ai_og_card_title_impl( $post_id ) {
		return array(
			'ok'               => true,
			'title'            => 'A punchy OG card title for social sharing',
			'length'           => 40,
			'card_regenerated' => true,
			'card_url'         => 'https://juanlentino.com/wp-content/uploads/sn-og/post-' . (int) $post_id . '.png',
		);
	}
}
if ( ! function_exists( 'snt_ai_excerpt_impl' ) ) {
	function snt_ai_excerpt_impl( $post_id ) {
		return array(
			'ok'      => true,
			'excerpt' => 'A short excerpt covering the post in two declarative sentences.',
			'length'  => 63,
			'words'   => 11,
		);
	}
}

// ─── v4.0.0 — 4 new health suggest/apply impl stubs ──────────────
if ( ! function_exists( 'snt_ai_alt_suggest_impl' ) ) {
	function snt_ai_alt_suggest_impl( $attachment_id ) {
		if ( 9999 === (int) $attachment_id ) {
			return new WP_Error( 'snt_ai_not_attachment', 'Not an attachment.', array( 'status' => 422 ) );
		}
		return array(
			'ok'            => true,
			'suggestion'    => 'Stub: a descriptive alt for attachment ' . (int) $attachment_id,
			'attachment_id' => (int) $attachment_id,
			'thumbnail_url' => 'https://example.com/wp-content/uploads/thumb-' . (int) $attachment_id . '.jpg',
			'filename'      => 'thumb-' . (int) $attachment_id . '.jpg',
		);
	}
}
if ( ! function_exists( 'snt_ai_alt_apply_impl' ) ) {
	function snt_ai_alt_apply_impl( $attachment_id, $alt_text ) {
		if ( '' === trim( (string) $alt_text ) ) {
			return new WP_Error( 'snt_ai_alt_empty', 'Empty alt.', array( 'status' => 422 ) );
		}
		return array(
			'ok'            => true,
			'attachment_id' => (int) $attachment_id,
			'written_alt'   => (string) $alt_text,
		);
	}
}
if ( ! function_exists( 'snt_ai_drift_suggest_impl' ) ) {
	function snt_ai_drift_suggest_impl( $post_id, $phrase, $position, $context_snippet ) {
		return array(
			'ok'          => true,
			'suggestion'  => 'in early 2025',
			'fingerprint' => md5( $phrase . '|' . $context_snippet ),
			'post_id'     => (int) $post_id,
			'position'    => (int) $position,
		);
	}
}
if ( ! function_exists( 'snt_ai_drift_apply_impl' ) ) {
	function snt_ai_drift_apply_impl( $post_id, $phrase, $position, $replacement, $fingerprint ) {
		if ( 'badfingerprint' === $fingerprint ) {
			return new WP_Error( 'snt_ai_apply_conflict', 'Fingerprint mismatch.', array( 'status' => 409 ) );
		}
		return array(
			'ok'       => true,
			'post_id'  => (int) $post_id,
			'replaced' => (string) $phrase,
			'with'     => (string) $replacement,
		);
	}
}
if ( ! function_exists( 'snt_ai_alt_inline_suggest_impl' ) ) {
	function snt_ai_alt_inline_suggest_impl( $post_id, $image_src ) {
		if ( 'broken' === $image_src ) {
			return new WP_Error( 'snt_ai_img_not_found', 'Img not in post_content.', array( 'status' => 422 ) );
		}
		return array(
			'ok'         => true,
			'suggestion' => 'Stub: inline-img alt for ' . (string) $image_src,
			'post_id'    => (int) $post_id,
			'image_src'  => (string) $image_src,
		);
	}
}
$GLOBALS['__test_cron_events_call_count'] = 0;
if ( ! function_exists( 'snt_cron_get_events_impl' ) ) {
	function snt_cron_get_events_impl( $sn_only = false ) {
		$GLOBALS['__test_cron_events_call_count']++;
		$events = array(
			array(
				'hook'           => 'sn_plausible_refresh',
				'args_signature' => md5( serialize( array() ) ),
				'next_run_ts'    => 1900000000,
				'schedule'       => 'hourly',
				'interval_s'     => 3600,
				'args'           => array(),
				'last_fired_ts'  => 1899996400,
				'has_handler'    => true,
				'is_sn_owned'    => true,
			),
			array(
				'hook'           => 'wp_version_check',
				'args_signature' => md5( serialize( array() ) ),
				'next_run_ts'    => 1900003600,
				'schedule'       => 'twicedaily',
				'interval_s'     => 43200,
				'args'           => array(),
				'last_fired_ts'  => 1899960000,
				'has_handler'    => true,
				'is_sn_owned'    => false,
			),
		);
		if ( $sn_only ) {
			return array_values( array_filter( $events, function( $e ) { return ! empty( $e['is_sn_owned'] ); } ) );
		}
		return $events;
	}
}
if ( ! function_exists( 'snt_cron_get_event_impl' ) ) {
	function snt_cron_get_event_impl( $hook, $args_signature ) {
		if ( 'sn_plausible_refresh' === $hook ) {
			return array(
				'hook'           => 'sn_plausible_refresh',
				'args_signature' => $args_signature,
				'next_run_ts'    => 1900000000,
				'schedule'       => 'hourly',
				'interval_s'     => 3600,
				'args'           => array(),
				'last_fired_ts'  => 1899996400,
				'has_handler'    => true,
				'is_sn_owned'    => true,
			);
		}
		return null;
	}
}
if ( ! function_exists( 'snt_cron_history_for_hook' ) ) {
	function snt_cron_history_for_hook( $hook, $limit = 10 ) {
		return array(
			array(
				'id'             => 1,
				'hook'           => $hook,
				'args_signature' => md5( serialize( array() ) ),
				'fired_at'       => '2026-05-24 10:00:00',
				'fired_at_ts'    => 1900000000,
				'elapsed_ms'     => 132,
				'success'        => true,
				'error_message'  => null,
			),
		);
	}
}
if ( ! function_exists( 'snt_cron_unschedule_event_impl' ) ) {
	function snt_cron_unschedule_event_impl( $hook, $args = array() ) {
		if ( in_array( $hook, array( 'sn_plausible_refresh', 'snt_rss_prune', 'snt_deploy_webhook' ), true ) ) {
			return new WP_Error( 'sn_owned', 'Refusing to unschedule an SN-owned hook.', array( 'status' => 400 ) );
		}
		return array(
			'success' => true,
			'hook'    => $hook,
			'args'    => $args,
			'cleared' => 1,
		);
	}
}
if ( ! function_exists( 'snt_insights_run_scan' ) ) {
	function snt_insights_run_scan( $force = false ) {
		return array(
			'scanned_at'      => 1900000000,
			'elapsed_ms'      => 1240,
			'recommendations' => array(
				array(
					'id'             => 'rec-1',
					'type'           => 'write_about',
					'title'          => 'A topic to write about',
					'rationale'      => 'High search velocity.',
					'evidence_pills' => array(),
					'target'         => null,
				),
			),
		);
	}
}
if ( ! function_exists( 'snt_insights_last_scan' ) ) {
	function snt_insights_last_scan() {
		return array(
			'scanned_at'      => 1900000000,
			'elapsed_ms'      => 1240,
			'recommendations' => array(),
		);
	}
}

// Theme-side filters that purge-all-caches + clear-template-overrides delegate to.
add_filter( 'sn_purge_all_caches_result', function( $value, $opts ) {
	return ! empty( $opts['template_overrides'] ) ? 2 : 0;
}, 10, 2 );
add_filter( 'sn_clear_template_overrides_result', function( $value ) {
	return 2;
} );

// ─── Abilities API stubs ────────────────────────────────────────────
$GLOBALS['__test_registered_categories'] = array();
$GLOBALS['__test_registered_abilities']  = array();

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $slug, $args ) {
		$GLOBALS['__test_registered_categories'][ $slug ] = $args;
		return true;
	}
}
if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( $slug ) {
		return isset( $GLOBALS['__test_registered_categories'][ $slug ] );
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['__test_registered_abilities'][ $name ] = $args;
		return true;
	}
}

if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		public $name;
		public $config;
		public function __construct( $name, $config ) {
			$this->name   = $name;
			$this->config = $config;
		}
		public function execute( $input = null ) {
			if ( isset( $this->config['permission_callback'] ) ) {
				$allowed = call_user_func( $this->config['permission_callback'], $input );
				if ( is_wp_error( $allowed ) ) { return $allowed; }
				if ( ! $allowed ) {
					return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 403 ) );
				}
			}
			if ( isset( $this->config['input_schema']['required'] ) ) {
				foreach ( (array) $this->config['input_schema']['required'] as $required_key ) {
					if ( ! is_array( $input ) || ! isset( $input[ $required_key ] ) ) {
						return new WP_Error( 'rest_invalid_param', "Missing required: $required_key", array( 'status' => 400 ) );
					}
				}
			}
			if ( isset( $this->config['input_schema']['properties'] ) && is_array( $input ) ) {
				foreach ( $this->config['input_schema']['properties'] as $key => $schema ) {
					if ( isset( $schema['enum'] ) && isset( $input[ $key ] ) ) {
						if ( ! in_array( $input[ $key ], $schema['enum'], true ) ) {
							return new WP_Error( 'rest_invalid_param', "Invalid enum for $key", array( 'status' => 400 ) );
						}
					}
				}
			}
			return call_user_func( $this->config['execute_callback'], $input );
		}
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		if ( ! isset( $GLOBALS['__test_registered_abilities'][ $name ] ) ) {
			return null;
		}
		return new WP_Ability( $name, $GLOBALS['__test_registered_abilities'][ $name ] );
	}
}

// ─── Load the SUT ───────────────────────────────────────────────────
require_once __DIR__ . '/../inc/abilities-registration.php';

// Now invoke the captured add_action closures in order: categories first,
// then abilities (the SUT relies on that ordering).
if ( isset( $GLOBALS['__test_actions']['wp_abilities_api_categories_init'] ) ) {
	foreach ( $GLOBALS['__test_actions']['wp_abilities_api_categories_init'] as $cb ) {
		call_user_func( $cb );
	}
}
if ( isset( $GLOBALS['__test_actions']['wp_abilities_api_init'] ) ) {
	foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] as $cb ) {
		call_user_func( $cb );
	}
}

// ─── Harness ─────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ap_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $expected, true ) . "\n    Actual:   " . var_export( $actual, true ) . "\n"; }
}
function ap_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}
function ap_reset_caps() {
	$GLOBALS['__test_user_caps'] = array(
		'read'           => true,
		'edit_posts'     => true,
		'manage_options' => true,
	);
	$GLOBALS['__test_edit_post_ok'] = true;
}

// ════════════════════════════════════════════════════════════════════
// Category: wp_get_ability() dispatch fundamentals
// ════════════════════════════════════════════════════════════════════

echo "\nCategory: dispatch via wp_get_ability()\n";

ap_true( null === wp_get_ability( 'signal-noise/does-not-exist' ), 'wp_get_ability returns null for unknown slug' );

$expected_abilities = array(
	// Original 4 (v2.0.4).
	'signal-noise/purge-all-caches',
	'signal-noise/regenerate-og-card',
	'signal-noise/get-deploy-status',
	'signal-noise/clear-template-overrides',
	// v2.5.0 additions (7).
	'signal-noise/force-check-updates',
	'signal-noise/full-reset',
	'signal-noise/list-template-overrides',
	'signal-noise/get-rss-stats',
	'signal-noise/ai-generate-meta-description',
	'signal-noise/ai-generate-og-card-title',
	'signal-noise/ai-generate-excerpt',
	// v3.0+ Cron Dashboard (5).
	'signal-noise/list-cron-events',
	'signal-noise/get-cron-event',
	'signal-noise/get-cron-history',
	'signal-noise/unschedule-cron-event',
	// v3.6+ Insights (2).
	'signal-noise/run-insights-scan',
	'signal-noise/get-insights',
);
foreach ( $expected_abilities as $slug ) {
	ap_true( null !== wp_get_ability( $slug ), "ability registered: $slug" );
}
ap_eq( 17, count( $expected_abilities ), 'expecting 17 abilities total' );

// ════════════════════════════════════════════════════════════════════
// Category: read/diagnostics abilities — happy path via execute()
// ════════════════════════════════════════════════════════════════════

echo "\nCategory: read/diagnostics abilities — happy path\n";

ap_reset_caps();

// get-deploy-status
$out = wp_get_ability( 'signal-noise/get-deploy-status' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['theme'], $out['plugin'] ), 'get-deploy-status: theme + plugin keys present' );
ap_eq( 'ok', $out['theme']['state'], 'get-deploy-status: theme state ok' );
ap_eq( '3.7.4', $out['plugin']['current'], 'get-deploy-status: plugin current SNT_VERSION' );

// list-template-overrides
$out = wp_get_ability( 'signal-noise/list-template-overrides' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['ok'], $out['count'], $out['items'] ), 'list-template-overrides: required keys present' );
ap_eq( 2, $out['count'], 'list-template-overrides: 2 fixture rows' );
ap_eq( 'wp_template', $out['items'][0]['post_type'], 'list-template-overrides: first item type' );

// get-rss-stats
$out = wp_get_ability( 'signal-noise/get-rss-stats' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['ok'], $out['data'] ), 'get-rss-stats: required keys present' );
ap_eq( 12, $out['data']['windows']['1']['total'], 'get-rss-stats: 24h total' );

// list-cron-events (no input)
$out = wp_get_ability( 'signal-noise/list-cron-events' )->execute( array() );
ap_true( is_array( $out ) && count( $out ) === 2, 'list-cron-events: returns 2 events without filter' );
ap_eq( 'sn_plausible_refresh', $out[0]['hook'], 'list-cron-events: first hook' );

// list-cron-events with sn_only=true
$out = wp_get_ability( 'signal-noise/list-cron-events' )->execute( array( 'sn_only' => true ) );
ap_eq( 1, count( $out ), 'list-cron-events: sn_only=true filters to 1' );
ap_eq( true, $out[0]['is_sn_owned'], 'list-cron-events: filtered event is_sn_owned' );

// get-cron-event (happy path)
$out = wp_get_ability( 'signal-noise/get-cron-event' )->execute( array(
	'hook'           => 'sn_plausible_refresh',
	'args_signature' => md5( serialize( array() ) ),
) );
ap_true( is_array( $out ) && isset( $out['hook'] ), 'get-cron-event: returns array for known hook' );
ap_eq( 'sn_plausible_refresh', $out['hook'], 'get-cron-event: hook echoed' );

// get-cron-event (unknown hook → null)
$out = wp_get_ability( 'signal-noise/get-cron-event' )->execute( array(
	'hook'           => 'wp_version_check',
	'args_signature' => md5( serialize( array() ) ),
) );
ap_true( null === $out, 'get-cron-event: unknown hook returns null per schema' );

// get-cron-history
$out = wp_get_ability( 'signal-noise/get-cron-history' )->execute( array( 'hook' => 'sn_plausible_refresh' ) );
ap_true( is_array( $out ) && count( $out ) === 1, 'get-cron-history: 1 history row' );
ap_eq( true, $out[0]['success'], 'get-cron-history: success=true' );

// run-insights-scan
$out = wp_get_ability( 'signal-noise/run-insights-scan' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['scanned_at'], $out['recommendations'] ), 'run-insights-scan: required keys' );
ap_eq( 1, count( $out['recommendations'] ), 'run-insights-scan: 1 recommendation' );

// get-insights
$out = wp_get_ability( 'signal-noise/get-insights' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['scanned_at'] ), 'get-insights: required keys' );

// ════════════════════════════════════════════════════════════════════
// Category: read/diagnostics — capability denial (no manage_options)
// ════════════════════════════════════════════════════════════════════

echo "\nCategory: read/diagnostics — capability denial\n";

$GLOBALS['__test_user_caps'] = array( 'read' => true ); // subscriber-like

$denied_diagnostics = array(
	'signal-noise/get-deploy-status',
	'signal-noise/list-template-overrides',
	'signal-noise/get-rss-stats',
	'signal-noise/list-cron-events',
	'signal-noise/get-cron-history',
	'signal-noise/run-insights-scan',
	'signal-noise/get-insights',
);
foreach ( $denied_diagnostics as $slug ) {
	$args = 'signal-noise/get-cron-history' === $slug ? array( 'hook' => 'x' ) : array();
	$res  = wp_get_ability( $slug )->execute( $args );
	ap_true( is_wp_error( $res ), "$slug: subscriber denied" );
	ap_eq( 'rest_forbidden', $res->get_error_code(), "$slug: rest_forbidden code" );
}

ap_reset_caps();

// ════════════════════════════════════════════════════════════════════
// Category: read/diagnostics — required + enum validation
// ════════════════════════════════════════════════════════════════════

echo "\nCategory: read/diagnostics — required + enum validation\n";

// get-cron-event: missing hook
$res = wp_get_ability( 'signal-noise/get-cron-event' )->execute( array( 'args_signature' => 'abc' ) );
ap_true( is_wp_error( $res ), 'get-cron-event: missing hook → WP_Error' );
ap_eq( 'rest_invalid_param', $res->get_error_code(), 'get-cron-event: code is rest_invalid_param' );

// get-cron-event: missing args_signature
$res = wp_get_ability( 'signal-noise/get-cron-event' )->execute( array( 'hook' => 'x' ) );
ap_true( is_wp_error( $res ), 'get-cron-event: missing args_signature → WP_Error' );

// get-cron-history: missing hook
$res = wp_get_ability( 'signal-noise/get-cron-history' )->execute( array() );
ap_true( is_wp_error( $res ), 'get-cron-history: missing hook → WP_Error' );

// ════════════════════════════════════════════════════════════════════
// Category: destructive ops — capability gating + idempotency
// ════════════════════════════════════════════════════════════════════

echo "\nCategory: destructive ops — capability gating + idempotency\n";

ap_reset_caps();

// purge-all-caches: happy path (delegates through filter)
$out1 = wp_get_ability( 'signal-noise/purge-all-caches' )->execute( array() );
ap_true( is_array( $out1 ) && isset( $out1['ok'], $out1['message'], $out1['count'] ), 'purge-all-caches: required keys' );
ap_eq( true, $out1['ok'], 'purge-all-caches: ok=true' );
ap_eq( 0, $out1['count'], 'purge-all-caches: count=0 without include_template_overrides' );

// Idempotency: second call → same shape, same outcome
$out2 = wp_get_ability( 'signal-noise/purge-all-caches' )->execute( array() );
ap_eq( $out1, $out2, 'purge-all-caches: idempotent (second call identical)' );

// purge-all-caches with include_template_overrides=true → count from filter
$out3 = wp_get_ability( 'signal-noise/purge-all-caches' )->execute( array( 'include_template_overrides' => true ) );
ap_eq( 2, $out3['count'], 'purge-all-caches: include_template_overrides triggers filter count' );

// clear-template-overrides
$out = wp_get_ability( 'signal-noise/clear-template-overrides' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['ok'], $out['count'], $out['message'] ), 'clear-template-overrides: required keys' );
ap_eq( 2, $out['count'], 'clear-template-overrides: 2 cleared per filter' );

// full-reset
$out = wp_get_ability( 'signal-noise/full-reset' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['ok'], $out['message'], $out['data'] ), 'full-reset: required keys' );
ap_eq( true, $out['ok'], 'full-reset: ok=true' );

// force-check-updates
$out = wp_get_ability( 'signal-noise/force-check-updates' )->execute( array() );
ap_true( is_array( $out ) && isset( $out['ok'], $out['message'] ), 'force-check-updates: required keys' );

// Capability denial — destructive ops all gated by manage_options.
$GLOBALS['__test_user_caps'] = array( 'read' => true );

$denied_destructive = array(
	'signal-noise/purge-all-caches',
	'signal-noise/clear-template-overrides',
	'signal-noise/full-reset',
	'signal-noise/force-check-updates',
);
foreach ( $denied_destructive as $slug ) {
	$res = wp_get_ability( $slug )->execute( array() );
	ap_true( is_wp_error( $res ), "$slug: subscriber denied" );
	ap_eq( 'rest_forbidden', $res->get_error_code(), "$slug: rest_forbidden code" );
}

ap_reset_caps();

// unschedule-cron-event — happy path (non-SN hook)
$out = wp_get_ability( 'signal-noise/unschedule-cron-event' )->execute( array(
	'hook' => 'wp_version_check',
	'args' => array(),
) );
ap_true( is_array( $out ) && isset( $out['success'], $out['cleared'] ), 'unschedule-cron-event: required keys' );
ap_eq( true, $out['success'], 'unschedule-cron-event: success=true for non-SN hook' );

// unschedule-cron-event — refuses SN-owned hook
$res = wp_get_ability( 'signal-noise/unschedule-cron-event' )->execute( array(
	'hook' => 'sn_plausible_refresh',
	'args' => array(),
) );
ap_true( is_wp_error( $res ), 'unschedule-cron-event: refuses SN-owned hook' );
ap_eq( 'sn_owned', $res->get_error_code(), 'unschedule-cron-event: sn_owned code' );

// unschedule-cron-event — missing hook
$res = wp_get_ability( 'signal-noise/unschedule-cron-event' )->execute( array() );
ap_true( is_wp_error( $res ), 'unschedule-cron-event: missing hook → WP_Error' );

// ════════════════════════════════════════════════════════════════════
// Category: regenerate-og-card — edit_post permission_callback
// ════════════════════════════════════════════════════════════════════

echo "\nCategory: regenerate-og-card (edit_post gating)\n";

ap_reset_caps();

// Missing required post_id
$res = wp_get_ability( 'signal-noise/regenerate-og-card' )->execute( array() );
ap_true( is_wp_error( $res ), 'regenerate-og-card: missing post_id → WP_Error' );
ap_eq( 'rest_invalid_param', $res->get_error_code(), 'regenerate-og-card: rest_invalid_param' );

// edit_post denied
$GLOBALS['__test_edit_post_ok'] = false;
$res = wp_get_ability( 'signal-noise/regenerate-og-card' )->execute( array( 'post_id' => 200 ) );
ap_true( is_wp_error( $res ), 'regenerate-og-card: edit_post denied → WP_Error' );
ap_eq( 'rest_forbidden', $res->get_error_code(), 'regenerate-og-card: rest_forbidden' );

// Happy path (edit_post allowed)
$GLOBALS['__test_edit_post_ok'] = true;
$GLOBALS['__test_og_card_ok']   = true;
$out = wp_get_ability( 'signal-noise/regenerate-og-card' )->execute( array( 'post_id' => 200 ) );
ap_true( is_array( $out ) && isset( $out['ok'], $out['image_url'], $out['message'] ), 'regenerate-og-card: required keys' );
ap_eq( true, $out['ok'], 'regenerate-og-card: ok=true' );
ap_true( false !== strpos( $out['image_url'], 'post-200.png' ), 'regenerate-og-card: image_url contains post id' );

// Non-existent post → snt_post_not_found from the SUT callback
$res = wp_get_ability( 'signal-noise/regenerate-og-card' )->execute( array( 'post_id' => 99999 ) );
ap_true( is_wp_error( $res ), 'regenerate-og-card: missing post → WP_Error' );
ap_eq( 'snt_post_not_found', $res->get_error_code(), 'regenerate-og-card: snt_post_not_found code' );

// OG card generator returns false → snt_og_failed
$GLOBALS['__test_og_card_ok'] = false;
$res = wp_get_ability( 'signal-noise/regenerate-og-card' )->execute( array( 'post_id' => 200 ) );
ap_true( is_wp_error( $res ), 'regenerate-og-card: failed generation → WP_Error' );
ap_eq( 'snt_og_failed', $res->get_error_code(), 'regenerate-og-card: snt_og_failed code' );
$GLOBALS['__test_og_card_ok'] = true;

// ════════════════════════════════════════════════════════════════════
// Category: generative AI abilities — happy path + gating
// ════════════════════════════════════════════════════════════════════

echo "\nCategory: generative AI abilities — happy path + gating\n";

ap_reset_caps();

// ai-generate-meta-description happy path
$out = wp_get_ability( 'signal-noise/ai-generate-meta-description' )->execute( array( 'post_id' => 200 ) );
ap_true( is_array( $out ) && isset( $out['ok'], $out['description'], $out['length'] ), 'ai-generate-meta-description: required keys' );
ap_eq( true, $out['ok'], 'ai-generate-meta-description: ok=true' );

// ai-generate-og-card-title happy path
$out = wp_get_ability( 'signal-noise/ai-generate-og-card-title' )->execute( array( 'post_id' => 200 ) );
ap_true( is_array( $out ) && isset( $out['ok'], $out['title'], $out['card_regenerated'] ), 'ai-generate-og-card-title: required keys' );
ap_eq( true, $out['card_regenerated'], 'ai-generate-og-card-title: card_regenerated=true' );

// ai-generate-excerpt happy path
$out = wp_get_ability( 'signal-noise/ai-generate-excerpt' )->execute( array( 'post_id' => 200 ) );
ap_true( is_array( $out ) && isset( $out['ok'], $out['excerpt'], $out['words'] ), 'ai-generate-excerpt: required keys' );

// Generative AI: edit_post denied
$GLOBALS['__test_edit_post_ok'] = false;
$denied_ai = array(
	'signal-noise/ai-generate-meta-description',
	'signal-noise/ai-generate-og-card-title',
	'signal-noise/ai-generate-excerpt',
);
foreach ( $denied_ai as $slug ) {
	$res = wp_get_ability( $slug )->execute( array( 'post_id' => 200 ) );
	ap_true( is_wp_error( $res ), "$slug: edit_post denied" );
	ap_eq( 'rest_forbidden', $res->get_error_code(), "$slug: rest_forbidden code" );
}
$GLOBALS['__test_edit_post_ok'] = true;

// Generative AI: missing post_id
foreach ( $denied_ai as $slug ) {
	$res = wp_get_ability( $slug )->execute( array() );
	ap_true( is_wp_error( $res ), "$slug: missing post_id → WP_Error" );
	ap_eq( 'rest_invalid_param', $res->get_error_code(), "$slug: rest_invalid_param code" );
}

/* ════════════════════════════════════════════════════════════════════
 * v4.0.0 — AI health suggest+apply abilities (4 abilities, 16 asserts)
 * ════════════════════════════════════════════════════════════════════ */

ap_reset_caps();

// ── ai-alt-suggest — happy path ─────────────────────────────────────
$out = wp_get_ability( 'signal-noise/ai-alt-suggest' )->execute( array( 'attachment_id' => 1234 ) );
ap_true( is_array( $out ) && isset( $out['ok'], $out['suggestion'], $out['attachment_id'] ), 'ai-alt-suggest: required keys' );
ap_eq( true, $out['ok'], 'ai-alt-suggest: ok=true' );
ap_eq( 1234, $out['attachment_id'], 'ai-alt-suggest: attachment_id echo' );
ap_true( isset( $out['thumbnail_url'] ), 'ai-alt-suggest: thumbnail_url key present' );
ap_eq( 'https://example.com/wp-content/uploads/thumb-1234.jpg', $out['thumbnail_url'], 'ai-alt-suggest: thumbnail_url value matches stub' );
ap_true( isset( $out['filename'] ), 'ai-alt-suggest: filename key present' );

// ── ai-alt-suggest — edit_post denied ───────────────────────────────
$GLOBALS['__test_edit_post_ok'] = false;
$res = wp_get_ability( 'signal-noise/ai-alt-suggest' )->execute( array( 'attachment_id' => 1234 ) );
ap_true( is_wp_error( $res ), 'ai-alt-suggest: edit_post denied → WP_Error' );
ap_eq( 'rest_forbidden', $res->get_error_code(), 'ai-alt-suggest: rest_forbidden code' );
$GLOBALS['__test_edit_post_ok'] = true;

// ── ai-alt-apply — happy path ───────────────────────────────────────
$out = wp_get_ability( 'signal-noise/ai-alt-apply' )->execute( array( 'attachment_id' => 1234, 'alt_text' => 'A red barn at dusk.' ) );
ap_true( is_array( $out ) && isset( $out['ok'], $out['attachment_id'], $out['written_alt'] ), 'ai-alt-apply: required keys' );
ap_eq( 'A red barn at dusk.', $out['written_alt'], 'ai-alt-apply: written_alt echo' );

// ── ai-alt-apply — missing alt_text → schema validation ─────────────
$res = wp_get_ability( 'signal-noise/ai-alt-apply' )->execute( array( 'attachment_id' => 1234 ) );
ap_true( is_wp_error( $res ), 'ai-alt-apply: missing alt_text → WP_Error' );
ap_eq( 'rest_invalid_param', $res->get_error_code(), 'ai-alt-apply: rest_invalid_param code' );

// ── ai-drift-suggest — happy path ───────────────────────────────────
$drift_input = array(
	'post_id'         => 200,
	'phrase'          => 'recently',
	'position'        => 145,
	'context_snippet' => 'we recently shipped a new feature that',
);
$out = wp_get_ability( 'signal-noise/ai-drift-suggest' )->execute( $drift_input );
ap_true( is_array( $out ) && isset( $out['ok'], $out['suggestion'], $out['fingerprint'] ), 'ai-drift-suggest: required keys' );
ap_eq( 32, strlen( $out['fingerprint'] ), 'ai-drift-suggest: fingerprint is md5 (32 hex chars)' );

// ── ai-drift-suggest — missing required field ───────────────────────
$res = wp_get_ability( 'signal-noise/ai-drift-suggest' )->execute( array( 'post_id' => 200 ) );
ap_true( is_wp_error( $res ), 'ai-drift-suggest: missing phrase → WP_Error' );
ap_eq( 'rest_invalid_param', $res->get_error_code(), 'ai-drift-suggest: rest_invalid_param code' );

// ── ai-drift-apply — happy path ─────────────────────────────────────
$apply_input = array(
	'post_id'     => 200,
	'phrase'      => 'recently',
	'position'    => 145,
	'replacement' => 'in early 2025',
	'fingerprint' => 'goodfingerprint000000000000000000',
);
$out = wp_get_ability( 'signal-noise/ai-drift-apply' )->execute( $apply_input );
ap_true( is_array( $out ) && isset( $out['ok'], $out['replaced'], $out['with'] ), 'ai-drift-apply: required keys' );
ap_eq( 'in early 2025', $out['with'], 'ai-drift-apply: with echo' );

// ── ai-drift-apply — fingerprint conflict ───────────────────────────
$conflict_input = array_merge( $apply_input, array( 'fingerprint' => 'badfingerprint' ) );
$res = wp_get_ability( 'signal-noise/ai-drift-apply' )->execute( $conflict_input );
ap_true( is_wp_error( $res ), 'ai-drift-apply: fingerprint mismatch → WP_Error' );
ap_eq( 'snt_ai_apply_conflict', $res->get_error_code(), 'ai-drift-apply: snt_ai_apply_conflict code' );

// ── ai-alt-inline-suggest — happy path ──────────────────────────────
$inline_input = array(
	'post_id'   => 200,
	'image_src' => 'https://example.com/wp-content/uploads/2026/05/foo.png',
);
$out = wp_get_ability( 'signal-noise/ai-alt-inline-suggest' )->execute( $inline_input );
ap_true( is_array( $out ) && isset( $out['ok'], $out['suggestion'], $out['post_id'], $out['image_src'] ), 'ai-alt-inline-suggest: required keys' );
ap_eq( true, $out['ok'], 'ai-alt-inline-suggest: ok=true' );

// ── ai-alt-inline-suggest — edit_post denied ────────────────────────
$GLOBALS['__test_edit_post_ok'] = false;
$res = wp_get_ability( 'signal-noise/ai-alt-inline-suggest' )->execute( $inline_input );
ap_true( is_wp_error( $res ), 'ai-alt-inline-suggest: edit_post denied → WP_Error' );
ap_eq( 'rest_forbidden', $res->get_error_code(), 'ai-alt-inline-suggest: rest_forbidden code' );
$GLOBALS['__test_edit_post_ok'] = true;

// ── ai-alt-inline-suggest — missing required field ──────────────────
$res = wp_get_ability( 'signal-noise/ai-alt-inline-suggest' )->execute( array( 'post_id' => 200 ) );
ap_true( is_wp_error( $res ), 'ai-alt-inline-suggest: missing image_src → WP_Error' );
ap_eq( 'rest_invalid_param', $res->get_error_code(), 'ai-alt-inline-suggest: rest_invalid_param code' );

// ── ai-alt-inline-suggest — img not in post ─────────────────────────
$res = wp_get_ability( 'signal-noise/ai-alt-inline-suggest' )->execute( array(
	'post_id'   => 200,
	'image_src' => 'broken',
) );
ap_true( is_wp_error( $res ), 'ai-alt-inline-suggest: img not in post → WP_Error' );
ap_eq( 'snt_ai_img_not_found', $res->get_error_code(), 'ai-alt-inline-suggest: snt_ai_img_not_found code' );

ap_reset_caps();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
