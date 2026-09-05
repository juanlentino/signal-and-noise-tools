<?php
/**
 * Standalone test: health check 26 -- theme.json declares a preset the site
 * does not serve. Run: php tests/health-check-theme-presets.php
 * @since 13.98.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}
$GLOBALS['__settings'] = array();
function wp_get_global_settings( $path = array() ) { return $GLOBALS['__settings'][ implode( '.', $path ) ] ?? array(); }
$GLOBALS['__theme_dir'] = sys_get_temp_dir() . '/sn-presets-' . getmypid();
@mkdir( $GLOBALS['__theme_dir'] );
function get_stylesheet_directory() { return $GLOBALS['__theme_dir']; }
require_once __DIR__ . '/../inc/health-check-theme-presets.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function sizes( array $slugs ) { return array_map( function ( $s ) { return array( 'slug' => $s, 'size' => '1rem', 'name' => $s ); }, $slugs ); }

// The pre-v12.18.9 shape of the real theme: spacing 10..80 declared, no flag.
$declared = array( 'settings' => array(
	'spacing'    => array( 'spacingScale' => array( 'steps' => 0 ), 'spacingSizes' => sizes( array( '10', '20', '30', '40', '50', '60', '70', '80' ) ) ),
	'typography' => array( 'fontSizes' => sizes( array( 'small', 'medium', 'large', 'x-large', 'eyebrow' ) ) ),
	'color'      => array( 'defaultPalette' => false, 'palette' => array( array( 'slug' => 'void', 'color' => '#fff' ) ) ),
) );
$served_dropped = array(
	'spacing.spacingSizes' => array( '10' ),                       // what WP 7.1 really served
	'typography.fontSizes' => array( 'eyebrow' ),                  // the four core-named ones dropped
	'color.palette'        => array( 'void' ),
);
$served_ok = array(
	'spacing.spacingSizes' => array( '10', '20', '30', '40', '50', '60', '70', '80' ),
	'typography.fontSizes' => array( 'small', 'medium', 'large', 'x-large', 'eyebrow' ),
	'color.palette'        => array( 'void' ),
);

echo "health-check-theme-presets -- plugin v13.98.0\n\nGroup 1: the finding this was built from\n";
$r = sn_health_check_theme_presets( $declared, $served_dropped );
ok( 2 === $r['count'] && null === $r['skipped'], 'two families dropped -> two findings (spacing, typography); the palette is fine' );
$sp = null; foreach ( $r['findings'] as $f ) { if ( 'spacing.spacingSizes' === $f['subject_label'] ) { $sp = $f; } }
ok( is_array( $sp ) && array( '20', '30', '40', '50', '60', '70', '80' ) === $sp['missing_slugs'], 'the spacing finding names exactly the seven dropped slugs, not slug 10' );
ok( 'spacing.defaultSpacingSizes' === $sp['flag'] && false !== strpos( $sp['note'], 'is not set' ), 'the finding names the flag to set and says it is absent' );
$declared_flag_true = $declared; $declared_flag_true['settings']['spacing']['defaultSpacingSizes'] = true;
$r = sn_health_check_theme_presets( $declared_flag_true, $served_dropped );
$sp = null; foreach ( $r['findings'] as $f ) { if ( 'spacing.spacingSizes' === $f['subject_label'] ) { $sp = $f; } }
ok( is_array( $sp ) && false !== strpos( $sp['note'], 'is true' ), 'a flag explicitly TRUE reads as true, not absent' );

echo "\nGroup 2: the fixed theme\n";
$fixed = $declared; $fixed['settings']['spacing']['defaultSpacingSizes'] = false; $fixed['settings']['typography']['defaultFontSizes'] = false;
$r = sn_health_check_theme_presets( $fixed, $served_ok );
ok( 0 === $r['count'] && null === $r['skipped'], 'every declared slug served -> ran, nothing wrong' );
$r = sn_health_check_theme_presets( $declared, $served_ok );
ok( 0 === $r['count'], 'the check judges what is SERVED, not the flag: flag absent but slugs served is still a pass' );

echo "\nGroup 3: skips\n";
$r = sn_health_check_theme_presets( null, $served_ok );
ok( is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], 'theme.json could not be read' ), 'no theme.json in the stylesheet directory -> skipped' );
file_put_contents( $GLOBALS['__theme_dir'] . '/theme.json', '{not json' );
$r = sn_health_check_theme_presets( null, $served_ok );
ok( is_string( $r['skipped'] ), 'an unparseable theme.json -> skipped' );
file_put_contents( $GLOBALS['__theme_dir'] . '/theme.json', json_encode( $declared ) );
$GLOBALS['__settings'] = array( 'spacing.spacingSizes' => sizes( array( '10', '20' ) ) ); // FLAT, not origin-keyed
$r = sn_health_check_theme_presets( null, null );
ok( is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], 'keyed by origin' ), 'flat (non-origin-keyed) settings -> skipped: a flat list cannot tell theme from default' );

echo "\nGroup 4: reading the live shapes\n";
$GLOBALS['__settings'] = array(
	'spacing.spacingSizes' => array( 'default' => sizes( array( '20', '30' ) ), 'theme' => sizes( array( '10' ) ) ),
	'typography.fontSizes' => array( 'default' => sizes( array( 'small' ) ), 'theme' => sizes( array( 'small', 'medium', 'large', 'x-large', 'eyebrow' ) ) ),
	'color.palette'        => array( 'theme' => array( array( 'slug' => 'void', 'color' => '#fff' ) ) ),
);
$r = sn_health_check_theme_presets( null, null );
ok( 1 === $r['count'] && 'spacing.spacingSizes' === $r['findings'][0]['subject_label'], 'from the file + origin-keyed settings: only the spacing family is dropped' );
ok( false !== strpos( $r['findings'][0]['note'], '20, 30, 40, 50, 60, 70, 80' ), 'the served DEFAULT origin does not count as served: slugs 20 and 30 are still missing from the theme origin' );

@unlink( $GLOBALS['__theme_dir'] . '/theme.json' ); @rmdir( $GLOBALS['__theme_dir'] );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
