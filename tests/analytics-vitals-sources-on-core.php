<?php
/**
 * CLS shift sources, the ability side: signal-noise/get-analytics-vitals
 * declares the new keys, and the REAL payloads (attributed rows, an empty
 * list, a failed leg with nulls) pass core's own output validation. Core
 * validates an ability's output against its output_schema and answers
 * ability_invalid_output on a mismatch, which would blank the whole payload.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/analytics-vitals-sources-on-core.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SNT_MQ_DIMENSIONS', array( 'day' ) );
define( 'SNT_MQ_METRICS', array( 'views' ) );

require __DIR__ . '/lib/wp-html-api.php';
snt_test_require_core( dirname( snt_test_html_api_dir() ), array( 'class-wp-error.php', 'plugin.php', 'rest-api.php', 'json-schema.php' ) );
function __( $s, $d = null ) { return $s; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_is_numeric_array( $data ) { return is_array( $data ) && 0 === count( array_filter( array_keys( $data ), 'is_string' ) ); }
function _doing_it_wrong( $fn, $msg, $ver ) { $GLOBALS['__wrong'][] = "$fn: $msg"; }
function get_transient( $k ) { return SNT_ANALYTICS_VDAY_CACHE_KEY === $k ? array( 'hashes' => array(), 'ok' => true, 'truncated' => false ) : false; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
$GLOBALS['__src'] = array( array( 'k' => '/notes/a/', 's' => 'div.sn-footer__meta.is-layout-flex', 'w' => '6', 'p' => '2', 'm' => '1000' ) );
function sn_analytics_query( $sql ) {
	if ( false !== strpos( $sql, "blob17 != ''" ) ) { return $GLOBALS['__src']; }
	if ( false !== strpos( $sql, "blob17 = ''" ) ) { return array( array( 'w' => '7' ) ); }
	return false !== strpos( $sql, 'blob2 IN' ) ? array() : array( array( 'e' => 'vc', 'k' => 'mobile', 'v' => '120', 'w' => '40' ) );
}
function sn_analytics_top_paths( $from, $to, $class, $limit ) { return array( array( 'path' => '/notes/a/' ) ); }
function sn_analytics_canonical_path( $p ) { $t = rtrim( (string) $p, '/' ); return '' === $t ? '/' : $t; }
function snt_metrics_args( $i ) { return array( array( 'from' => '2026-09-14', 'to' => '2026-10-11' ), 'human', 5 ); }
$GLOBALS['__reg'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__reg'][ $slug ] = $args; }

require_once __DIR__ . '/../inc/analytics-vitals.php';
// The registration alone: the file's requires pull the whole sn-metrics stack.
eval( '?>' . preg_replace( '/^require_once [^\n]*\n/m', '', (string) file_get_contents( __DIR__ . '/../inc/abilities-analytics-sections.php' ) ) );
do_action( 'wp_abilities_api_init' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "CLS shift sources: the ability, on core's validator\n\n";
$reg    = $GLOBALS['__reg']['signal-noise/get-analytics-vitals'] ?? array();
$schema = $reg['output_schema'] ?? array();
$props  = $schema['properties'] ?? array();
ok( array( 'array', 'null' ) === ( $props['cls_sources']['type'] ?? null ) && SNT_VITALS_SOURCES_MAX === ( $props['cls_sources']['maxItems'] ?? null ), 'output schema: cls_sources is a list of at most 50, or null' );
ok( array( 'path', 'source', 'readings', 'needs_improvement', 'poor', 'worst' ) === array_keys( $props['cls_sources']['items']['properties'] ?? array() ), 'output schema: a row is path, source, readings, needs_improvement, poor, worst' );
ok( array( 'integer', 'null' ) === ( $props['cls_sources_unattributed']['type'] ?? null ) && 'string' === ( $props['cls_sources_failure']['type'] ?? null ), 'output schema: the unattributed count (or null) and the failure sentence' );
ok( empty( $schema['required'] ) && ! isset( $schema['additionalProperties'] ), 'the new keys are not required (a payload cached before this release lacks them) and the rest of the payload stays open' );
ok( array( 'type' => 'object' ) === ( $GLOBALS['__reg']['signal-noise/get-analytics-devices']['output_schema'] ?? null ), 'the other sections keep their open object schema' );
$desc = (string) ( $reg['description'] ?? '' );
ok( false !== strpos( $desc, 'cls_sources' ) && false !== strpos( $desc, 'cls_sources_unattributed' ) && false !== strpos( $desc, 'worker 2.4.0' ) && false !== strpos( $desc, 'tag, id, class names' ), 'the description says what it is, when it fills, and that a source is page markup only' );
ok( false !== strpos( $desc, 'Until analytics worker 2.4.0' ) && false === strpos( $desc, 'zero-attributed' ), 'before the worker ships the list is empty and every not-good reading is unattributed: the description says so' );
ok( false === strpos( $desc, "\u{2014}" ), 'no em dash in the description' );

$valid = static function ( $payload ) use ( $schema ) {
	$v = rest_validate_value_from_schema( $payload, $schema, 'output' );
	return is_wp_error( $v ) ? $v->get_error_message() : true;
};
$full = snt_ability_get_analytics_vitals( array() );
ok( 1 === count( $full['cls_sources'] ) && 1.0 === $full['cls_sources'][0]['worst'] && true === $valid( $full ), 'core accepts the real payload with attributed rows (a whole-number worst is still a number): ' . json_encode( $valid( $full ) ) );
$GLOBALS['__src'] = array();
$empty = snt_ability_get_analytics_vitals( array() );
ok( array() === $empty['cls_sources'] && 7 === $empty['cls_sources_unattributed'] && true === $valid( $empty ), 'core accepts an empty list beside its count: ' . json_encode( $valid( $empty ) ) );
$GLOBALS['__src'] = null;
$bad = snt_ability_get_analytics_vitals( array() );
ok( is_array( $bad ) && null === $bad['cls_sources'] && null === $bad['cls_sources_unattributed'] && '' !== $bad['cls_sources_failure'] && true === $valid( $bad ), 'core accepts the failed leg (nulls and the reason), so it cannot blank the rest: ' . json_encode( $valid( $bad ) ) );
unset( $bad['cls_sources'], $bad['cls_sources_unattributed'], $bad['cls_sources_failure'] );
ok( true === $valid( $bad ), 'core accepts a payload cached before this release (none of the keys)' );
ok( true !== $valid( array( 'cls_sources' => array( array( 'readings' => 'many' ) ) ) + $full ), 'the control: a row whose readings is not a number is refused, so the schema is being applied' );
ok( empty( $GLOBALS['__wrong'] ), 'core raised no _doing_it_wrong on the schema' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
