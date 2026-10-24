<?php
/**
 * Tests: the overrides the plugin counts and lists are the set the theme's
 * clear removes (theme #604, 2026-10-24): this theme's wp_template and
 * wp_template_part rows, by their wp_theme term, in every status core can
 * render or restore. Navigation posts (menus) and other themes' rows are not
 * overrides of this site, so the dashboard count, the diagnostics list, the
 * list ability and the clear all name the same rows.
 *
 * Run: php tests/template-override-scope.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

function get_stylesheet() { return 'signal-and-noise'; }
function add_action() {}
function add_filter() {}
function __( $s, $d = null ) { return $s; }
// A small table: this theme's template and part (one an auto-draft), another
// theme's template, and a navigation post. Honors post_type, post_status and
// the wp_theme tax_query, as WP_Query would.
$GLOBALS['__rows'] = array(
	(object) array( 'ID' => 11, 'post_type' => 'wp_template', 'post_name' => 'single', 'post_status' => 'publish', 'theme' => 'signal-and-noise' ),
	(object) array( 'ID' => 12, 'post_type' => 'wp_template_part', 'post_name' => 'header', 'post_status' => 'auto-draft', 'theme' => 'signal-and-noise' ),
	(object) array( 'ID' => 13, 'post_type' => 'wp_template', 'post_name' => 'index', 'post_status' => 'publish', 'theme' => 'twentytwentyfive' ),
	(object) array( 'ID' => 14, 'post_type' => 'wp_navigation', 'post_name' => 'navigation', 'post_status' => 'publish', 'theme' => '' ),
);
function get_posts( $a ) {
	$GLOBALS['__args'] = $a;
	$types  = (array) $a['post_type'];
	$status = 'any' === ( $a['post_status'] ?? 'publish' ) ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : (array) $a['post_status'];
	$theme  = $a['tax_query'][0]['terms'] ?? null;
	$out    = array();
	foreach ( $GLOBALS['__rows'] as $r ) {
		if ( in_array( $r->post_type, $types, true ) && in_array( $r->post_status, $status, true ) && ( null === $theme || $theme === $r->theme ) ) {
			$out[] = 'ids' === ( $a['fields'] ?? '' ) ? $r->ID : $r;
		}
	}
	return $out;
}

require __DIR__ . '/../inc/admin-tab-dashboard-data-parts.php';
require __DIR__ . '/../inc/abilities-system-deploy-parts.php';

$args = snt_template_override_query_args();
ok( array( 'wp_template', 'wp_template_part' ) === $args['post_type'], 'templates and parts only: navigation posts hold menus, not overrides' );
ok( 'wp_theme' === $args['tax_query'][0]['taxonomy'] && 'name' === $args['tax_query'][0]['field'] && 'signal-and-noise' === $args['tax_query'][0]['terms'], 'scoped to the active theme by its wp_theme term, as core reads them' );
ok( in_array( 'auto-draft', $args['post_status'], true ) && in_array( 'trash', $args['post_status'], true ), 'every status core can render or restore, auto-draft and trash included' );
ok( 2 === snt_dashboard_override_count(), 'the dashboard count is this theme\'s two rows: the auto-draft part counts, the other theme and the menu do not' );
$list = snt_ability_list_template_overrides();
ok( array( 11, 12 ) === array_column( $list['items'], 'id' ) && 2 === $list['count'], 'the list ability names the same two rows the clear removes' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
