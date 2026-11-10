<?php
/**
 * Standalone test: health check 26 takes its family list from core.
 *
 * The check used to hand-copy the guarded families out of
 * WP_Theme_JSON::PRESETS_METADATA. A family core starts guarding later would
 * then go unchecked, which is the case the check exists for. With the class
 * present the list is derived from the constant; the hand list is only the
 * fallback for a context without WordPress (tests/health-check-theme-presets.php).
 *
 * Run: php tests/health-check-theme-presets-core.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}

/**
 * Core's constant, reduced to the two keys the check reads. The first ten
 * rows are WordPress 7.1.4 (class-wp-theme-json.php:132-225), in core's order;
 * the last stands for a family a later core guards.
 */
class WP_Theme_JSON {
	const PRESETS_METADATA = array(
		array( 'path' => array( 'dimensions', 'aspectRatios' ), 'prevent_override' => array( 'dimensions', 'defaultAspectRatios' ) ),
		array( 'path' => array( 'color', 'palette' ), 'prevent_override' => array( 'color', 'defaultPalette' ) ),
		array( 'path' => array( 'color', 'gradients' ), 'prevent_override' => array( 'color', 'defaultGradients' ) ),
		array( 'path' => array( 'color', 'duotone' ), 'prevent_override' => array( 'color', 'defaultDuotone' ) ),
		array( 'path' => array( 'typography', 'fontSizes' ), 'prevent_override' => array( 'typography', 'defaultFontSizes' ) ),
		array( 'path' => array( 'typography', 'fontFamilies' ), 'prevent_override' => false ),
		array( 'path' => array( 'spacing', 'spacingSizes' ), 'prevent_override' => array( 'spacing', 'defaultSpacingSizes' ) ),
		array( 'path' => array( 'shadow', 'presets' ), 'prevent_override' => array( 'shadow', 'defaultPresets' ) ),
		array( 'path' => array( 'border', 'radiusSizes' ), 'prevent_override' => false ),
		array( 'path' => array( 'dimensions', 'dimensionSizes' ), 'prevent_override' => false ),
		array( 'path' => array( 'future', 'widgets' ), 'prevent_override' => array( 'future', 'defaultWidgets' ) ),
	);
}
require_once __DIR__ . '/../inc/health-check-theme-presets.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$families = sn_health_theme_preset_families();
$fallback = sn_health_theme_preset_families_fallback();

ok( isset( $families['future.widgets'] ) && array( array( 'future', 'widgets' ), array( 'future', 'defaultWidgets' ) ) === $families['future.widgets'], 'a family core newly guards is picked up from PRESETS_METADATA, as [ settings path, flag path ]' );
ok( ! isset( $families['typography.fontFamilies'] ) && ! isset( $families['border.radiusSizes'] ) && ! isset( $families['dimensions.dimensionSizes'] ), 'a family core does not guard (prevent_override false) is left out' );
ok( 8 === count( $families ), 'seven guarded families from 7.1.4 plus the new one' );

$core_714 = $families;
unset( $core_714['future.widgets'] );
ksort( $core_714 );
ksort( $fallback );
ok( $core_714 === $fallback, 'the fallback list is exactly what core 7.1.4 guards, path for path' );

$declared = array( 'settings' => array( 'future' => array( 'widgets' => array( array( 'slug' => 'a' ), array( 'slug' => 'b' ) ) ) ) );
$r        = sn_health_check_theme_presets( $declared, array( 'future.widgets' => array( 'a' ) ) );
ok( 1 === $r['count'] && array( 'b' ) === $r['findings'][0]['missing_slugs'] && 'future.defaultWidgets' === $r['findings'][0]['flag'], 'the check reports a dropped slug in the new family and names its flag' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
