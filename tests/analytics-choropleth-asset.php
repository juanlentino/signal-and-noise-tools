<?php
/**
 * Integrity guard for the vendored SimpleMaps world map (assets/analytics/world-map.svg).
 * Asserts the file is present, MIT-attributed, ISO-2 keyed, and free of script/remote refs.
 * Run: php tests/analytics-choropleth-asset.php
 * @since plugin v6.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$svg_path = __DIR__ . '/../assets/analytics/world-map.svg';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Choropleth asset integrity\n\n";
$exists = is_file( $svg_path );
ok( $exists, 'asset: assets/analytics/world-map.svg exists' );
$svg = $exists ? (string) file_get_contents( $svg_path ) : '';
ok( strlen( $svg ) > 100000 && strlen( $svg ) < 200000, 'asset: size is ~140KB (sane bounds)' );
ok( stripos( $svg, 'The MIT License' ) !== false, 'asset: MIT license header preserved' );
ok( stripos( $svg, 'simplemaps' ) !== false, 'asset: SimpleMaps attribution present' );
ok( preg_match( '/\bid="US"/', $svg ) === 1 && preg_match( '/\bid="FR"/', $svg ) === 1, 'asset: uppercase ISO-2 path ids (US, FR)' );
ok( count( array_unique( preg_match_all( '/\bid="([A-Z]{2})"/', $svg, $m ) ? $m[1] : array() ) ) >= 200, 'asset: ~200+ country paths' );
ok( stripos( $svg, '<script' ) === false, 'asset: no <script> tags' );
ok( ! preg_match( '#<image\b|xlink:href="https?://|href="https?://(?!simplemaps)#i', $svg ), 'asset: no remote refs / external images' );
ok( strpos( $svg, 'viewBox="0 0 2000 1001"' ) !== false, 'asset: Robinson viewBox intact' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
