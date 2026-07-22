<?php
/**
 * Tests: one-time pillar meta seed (v9.79.1).
 *
 * The three known essays get flag + designation exactly once, owner
 * edits always win, the sentinel is autoload=no, and a cap-less visit
 * retries later instead of burning the sentinel.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// ── Stubs ────────────────────────────────────────────────────────────────
$GLOBALS['__options']  = array(); // name => array( value, autoload )
$GLOBALS['__pages']    = array(); // path => page object
$GLOBALS['__meta']     = array(); // [ID][key] => value
$GLOBALS['__can']      = true;
function get_option( $name, $default = false ) {
	return isset( $GLOBALS['__options'][ $name ] ) ? $GLOBALS['__options'][ $name ][0] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = array( $value, $autoload );
	return true;
}
function get_page_by_path( $path ) {
	return $GLOBALS['__pages'][ $path ] ?? null;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['__meta'][ (int) $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['__meta'][ (int) $id ][ $key ] = $value;
	return true;
}
function current_user_can( $cap ) { return $GLOBALS['__can']; }
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][ $h ][] = $c; }

function seed_page( $id, $status = 'publish', $password = '' ) {
	return (object) array( 'ID' => $id, 'post_status' => $status, 'post_password' => $password );
}
function reset_world() {
	$GLOBALS['__options'] = array();
	$GLOBALS['__meta']    = array();
	$GLOBALS['__can']     = true;
	$GLOBALS['__pages']   = array(
		'provenance/over-detection' => seed_page( 71 ),
		'provenance/cheap-option'   => seed_page( 74 ),
		'provenance/as-substrate'   => seed_page( 72 ),
	);
}

require __DIR__ . '/../inc/pillar-meta-seed.php';

echo "Group: wiring\n";
ok( in_array( 'sn_pillar_meta_seed', $GLOBALS['__hooks']['admin_init'] ?? array(), true ),
	'seed runs on admin_init (install hooks cannot observe WP UI deploys)' );

echo "\nGroup: happy path seeds the three essays once\n";
reset_world();
sn_pillar_meta_seed();
ok( '1' === ( $GLOBALS['__meta'][71]['_sn_pillar'] ?? '' ), 'over-detection flagged' );
ok( '1.00' === ( $GLOBALS['__meta'][71]['_sn_pillar_designation'] ?? '' ), 'over-detection = 1.00' );
ok( '1' === ( $GLOBALS['__meta'][74]['_sn_pillar'] ?? '' ), 'cheap-option flagged' );
ok( '1.01' === ( $GLOBALS['__meta'][74]['_sn_pillar_designation'] ?? '' ), 'cheap-option = 1.01' );
ok( '1' === ( $GLOBALS['__meta'][72]['_sn_pillar'] ?? '' ), 'as-substrate flagged' );
ok( '2.00' === ( $GLOBALS['__meta'][72]['_sn_pillar_designation'] ?? '' ), 'as-substrate = 2.00' );
$sentinel = $GLOBALS['__options']['sn_pillar_meta_seeded'] ?? null;
ok( null !== $sentinel && '' !== (string) $sentinel[0], 'sentinel set after seeding' );
ok( false === ( $sentinel[1] ?? null ), 'sentinel is autoload=no (flush-volatile transients ruled out by design)' );

echo "\nGroup: sentinel makes it a one-shot\n";
$GLOBALS['__meta'] = array();
sn_pillar_meta_seed();
ok( array() === $GLOBALS['__meta'], 'second run writes nothing (sentinel present)' );

echo "\nGroup: owner edits always win\n";
reset_world();
$GLOBALS['__meta'][74]['_sn_pillar_designation'] = '0.99';
sn_pillar_meta_seed();
ok( '0.99' === $GLOBALS['__meta'][74]['_sn_pillar_designation'], 'existing designation never overwritten' );
ok( ! isset( $GLOBALS['__meta'][74]['_sn_pillar'] ), 'a page with ANY pillar key is skipped whole (no partial writes)' );
ok( '1.00' === ( $GLOBALS['__meta'][71]['_sn_pillar_designation'] ?? '' ), 'untouched pages still seed' );

echo "\nGroup: missing / protected pages skipped, seed still completes\n";
reset_world();
unset( $GLOBALS['__pages']['provenance/over-detection'] );
$GLOBALS['__pages']['provenance/as-substrate'] = seed_page( 72, 'publish', 'secret' );
sn_pillar_meta_seed();
ok( ! isset( $GLOBALS['__meta'][71] ), 'missing page skipped' );
ok( ! isset( $GLOBALS['__meta'][72] ), 'password-protected page never seeded (matches the rail gate)' );
ok( '1.01' === ( $GLOBALS['__meta'][74]['_sn_pillar_designation'] ?? '' ), 'remaining page seeded' );
ok( isset( $GLOBALS['__options']['sn_pillar_meta_seeded'] ), 'sentinel still set (deliberate one-shot)' );

echo "\nGroup: cap-less visit retries later\n";
reset_world();
$GLOBALS['__can'] = false;
sn_pillar_meta_seed();
ok( array() === $GLOBALS['__meta'], 'no writes without edit_pages' );
ok( ! isset( $GLOBALS['__options']['sn_pillar_meta_seeded'] ), 'sentinel NOT burned: a later editor visit retries' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
