<?php
/**
 * The publish-time auto-fill is gone, and so is everything that tracked it.
 *
 * Until 2026-10-05, inc/ai-prepopulate.php hooked transition_post_status and a
 * cron event that wrote an empty meta description, excerpt and OG card title
 * with no review step, flagging each with an "auto-generated at publish"
 * sentinel that a notice and a dismiss ability read and
 * cleared. The owner removed the generator so every model output reaches a
 * published field through a human click, reviewed the four fields it had
 * written (the count of sentinels on the site read zero on 2026-10-05), and
 * the tracking went too. Pinned here: none of it can come back unnoticed.
 *
 * Run: php tests/prepop-removed.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

foreach ( array( 'inc/ai-prepopulate.php', 'inc/ai-prepopulate-notice.php', 'inc/abilities-prepop-dismiss.php', 'assets/prepop-notice.js' ) as $gone ) {
	ok( ! file_exists( "$root/$gone" ), "$gone is gone" );
}

// Strip comments before matching, so a history note cannot trip the guard.
$strip = function ( $src ) {
	return (string) preg_replace( '#//[^\n]*|/\*.*?\*/#s', '', $src );
};
$hits = array();
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = $file->getPathname();
	$rel  = substr( $path, strlen( $root ) + 1 );
	if ( ! preg_match( '#^(inc/|assets/|signal-and-noise-tools\.php$)#', $rel ) || ! preg_match( '/\.(php|js)$/', $rel ) ) {
		continue;
	}
	$src = $strip( (string) file_get_contents( $path ) );
	foreach ( array(
		'the auto-fill cron event'           => '/snt_prepop_event/',
		'a sentinel meta key'                => '/_sn_autogen_/',
		'the dismiss ability'                => '#prepop-dismiss#',
		'a dismiss REST route'               => '#prepop/dismiss#',
		'the sentinel helpers'               => '/sn_prepop_(fields|clear_sentinels|render_notice)/',
		'the generator'                      => '/snt_(run_prepop|prepop_on_transition|prepop_passes_content_gate)/',
	) as $what => $re ) {
		if ( preg_match( $re, $src ) ) {
			$hits[] = "$rel ($what)";
		}
	}
}
ok( array() === $hits, 'no shipped file names the auto-fill or its tracking' . ( $hits ? ': ' . implode( '; ', $hits ) : '' ) );

$main = (string) file_get_contents( "$root/signal-and-noise-tools.php" );
ok( false === strpos( $main, 'ai-prepopulate' ), 'the main plugin file loads neither removed module' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
