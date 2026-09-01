#!/usr/bin/env php
<?php
/**
 * Regenerate data/family-drift/pinned.json from the two upstream corpora.
 *
 * Dev-only (never bundled into a runtime path). Records the upstream commit
 * for each source via `gh api`, keeps ONLY the vocabulary the runtime diff
 * needs (pattern → tags; agent → operator/function/respect), and writes the
 * file sorted so the diff in review is readable.
 *
 *   php tools/family-drift-pin.php            # writes the pin
 *   php tools/family-drift-pin.php --check    # exit 1 if the live vocabulary differs from the pin
 *
 * A pin is a DECISION: re-pinning absorbs whatever changed upstream, so read
 * the family_drift report's vocabulary/respect_flips rows first and re-pin
 * only when the change is understood.
 *
 * @package SignalNoiseTools
 * @since 13.62.0
 */

$sources = array(
	'crawler_user_agents' => array( 'repo' => 'monperrus/crawler-user-agents', 'url' => 'https://raw.githubusercontent.com/monperrus/crawler-user-agents/master/crawler-user-agents.json' ),
	'ai_robots_txt'       => array( 'repo' => 'ai-robots-txt/ai.robots.txt', 'url' => 'https://raw.githubusercontent.com/ai-robots-txt/ai.robots.txt/main/robots.json' ),
);
$check = in_array( '--check', $argv, true );
$path  = dirname( __DIR__ ) . '/data/family-drift/pinned.json';

$fetch = static function ( $url ) {
	$body = @file_get_contents( $url ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool, no WP.
	if ( false === $body || '' === $body ) {
		fwrite( STDERR, "FETCH FAILED: $url\n" );
		exit( 2 );
	}
	$d = json_decode( $body, true );
	if ( ! is_array( $d ) || array() === $d ) {
		fwrite( STDERR, "NOT JSON: $url\n" );
		exit( 2 );
	}
	return $d;
};
$meta = static function ( $repo ) {
	$j = json_decode( (string) shell_exec( 'gh api repos/' . escapeshellarg( $repo ) . '/commits/HEAD 2>/dev/null' ), true );
	$l = json_decode( (string) shell_exec( 'gh api repos/' . escapeshellarg( $repo ) . ' 2>/dev/null' ), true );
	return array(
		'repo'        => $repo,
		'commit'      => substr( (string) ( $j['sha'] ?? '' ), 0, 12 ),
		'commit_date' => substr( (string) ( $j['commit']['committer']['date'] ?? '' ), 0, 10 ),
		'license'     => (string) ( $l['license']['spdx_id'] ?? '' ),
	);
};

$ua = $fetch( $sources['crawler_user_agents']['url'] );
$ai = $fetch( $sources['ai_robots_txt']['url'] );
$ua_patterns = array();
foreach ( $ua as $e ) {
	if ( is_array( $e ) && isset( $e['pattern'] ) ) {
		$t = (array) ( $e['tags'] ?? array() );
		sort( $t );
		$ua_patterns[ (string) $e['pattern'] ] = $t;
	}
}
$ai_agents = array();
foreach ( $ai as $name => $v ) {
	$ai_agents[ (string) $name ] = array(
		'function' => (string) ( $v['function'] ?? '' ),
		'operator' => (string) ( $v['operator'] ?? '' ),
		'respect'  => (string) ( $v['respect'] ?? '' ),
	);
}
ksort( $ua_patterns );
ksort( $ai_agents );

if ( $check ) {
	$old = json_decode( (string) @file_get_contents( $path ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$same = is_array( $old ) && ( $old['ua_patterns'] ?? null ) === $ua_patterns && ( $old['ai_agents'] ?? null ) === $ai_agents;
	echo $same ? "OK: pin matches live vocabulary\n" : "DRIFT: live vocabulary differs from the pin (re-pin deliberately)\n";
	exit( $same ? 0 : 1 );
}

$cua = $meta( $sources['crawler_user_agents']['repo'] );
$cai = $meta( $sources['ai_robots_txt']['repo'] );
$pin = array(
	'$comment'    => 'Pinned upstream corpora for the family-drift check (weave Phase 5). Data files, not libraries: vendored and DIFFED at runtime, never trusted live. Regenerate with tools/family-drift-pin.php.',
	'pinned_at'   => gmdate( 'Y-m-d' ),
	'sources'     => array(
		'crawler_user_agents' => $cua + array( 'entries' => count( $ua ) ),
		'ai_robots_txt'       => $cai + array( 'entries' => count( $ai ) ),
	),
	'ua_patterns' => $ua_patterns,
	'ai_agents'   => $ai_agents,
);
ksort( $pin );
file_put_contents( $path, json_encode( $pin, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
printf( "pinned: %d UA patterns (%s@%s), %d AI agents (%s@%s) -> %s\n", count( $ua_patterns ), $cua['repo'], $cua['commit'], count( $ai_agents ), $cai['repo'], $cai['commit'], $path );
