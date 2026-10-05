<?php
/**
 * The publish-time auto-fill is gone, and the "unreviewed" notice it left
 * behind still works.
 *
 * Until this change, inc/ai-prepopulate.php hooked transition_post_status and
 * a cron event that wrote an empty meta description, excerpt and OG card
 * title with no review step. The owner removed it on 2026-10-05 so every
 * model output reaches a published field through a human click. Pinned here:
 * nothing in the plugin schedules or handles that event, the generator's
 * functions and constants are gone, and the sentinel helpers the kept notice
 * reads still clear what an earlier run flagged.
 *
 * Run: php tests/prepop-removed.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__actions'] = array();
$GLOBALS['__meta']    = array();
function add_action( $hook, $cb ) { $GLOBALS['__actions'][ $hook ][] = $cb; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['__meta'][ $id ][ $key ] ); return true; }

require __DIR__ . '/../inc/ai-prepopulate.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( empty( $GLOBALS['__actions']['transition_post_status'] ), 'nothing hooks a publish to start an auto-fill' );
ok( empty( $GLOBALS['__actions']['snt_prepop_event'] ), 'nothing handles the auto-fill cron event' );
foreach ( array( 'snt_prepop_on_transition', 'snt_run_prepop', 'snt_prepop_passes_content_gate' ) as $fn ) {
	ok( ! function_exists( $fn ), "{$fn}() is gone" );
}
foreach ( array( 'SNT_PREPOP_MIN_WORDS', 'SNT_PREPOP_SCHEDULE_JITTER_MAX', 'SNT_PREPOP_DAILY_CALL_CEILING' ) as $const ) {
	ok( ! defined( $const ), "{$const} is gone" );
}

// No other file brings it back: no plugin code schedules or handles the event.
$hits = array();
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../inc', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$src = preg_replace( '#//[^\n]*|/\*.*?\*/#s', '', (string) file_get_contents( $file->getPathname() ) );
	if ( preg_match( '/wp_schedule_\w+\([^;]*snt_prepop_event|add_action\(\s*[\'"]snt_prepop_event/', $src ) ) {
		$hits[] = basename( $file->getPathname() );
	}
}
ok( array() === $hits, 'no file in inc/ schedules or handles snt_prepop_event (' . implode( ', ', $hits ) . ')' );

// The kept half: the notice's sentinel map and the clear the dismiss uses.
ok( array( '_sn_autogen_meta_description', '_sn_autogen_excerpt', '_sn_autogen_og_card_title' ) === array_keys( sn_prepop_fields() ), 'the sentinel map still names the three fields' );
$GLOBALS['__meta'][7] = array( '_sn_autogen_excerpt' => '1', '_sn_meta_description' => 'kept' );
sn_prepop_clear_sentinels( 7 );
ok( ! isset( $GLOBALS['__meta'][7]['_sn_autogen_excerpt'] ), 'clearing removes an earlier run\'s sentinel' );
ok( 'kept' === ( $GLOBALS['__meta'][7]['_sn_meta_description'] ?? '' ), 'clearing never touches the field itself' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
