<?php
/**
 * Tests: the ability permission policy (docs/ops/ability-permission-policy.md).
 *
 * Run: php tests/ability-permission-policy.php
 *
 * A CONTRACT test, not a unit test. It enumerates every registered ability and
 * its permission_callback by parsing the source, so a NEW ability fails this
 * suite until it is deliberately classified — the same discipline the health
 * surface map uses. Every permission_callback is a PUBLIC contract; the failure
 * this prevents is one landing by default rather than by decision.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/lib/inc-population.php'; // #987: inc/ is walked, not top-level-globbed.

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );

/** Parse every wp_register_ability() call into slug => [callback, readonly]. */
/**
 * Strip PHP comments so a registration discussed in a docblock is not counted
 * as one. Newlines are preserved so any line arithmetic stays honest.
 *
 * @param string $src Source.
 * @return string
 */
function snt_ap_strip_comments( $src ) {
	$src = preg_replace_callback(
		'#/\*.*?\*/#s',
		static function ( $m ) {
			return str_repeat( "\n", substr_count( $m[0], "\n" ) );
		},
		$src
	);
	return (string) preg_replace( '#^\s*//.*$#m', '', (string) $src );
}

/**
 * Every ability registration under inc/, however it is spelled.
 *
 * THE GLOB WAS THE BUG. This walked `inc/abilities-*.php` only and reported 93
 * of 103: three abilities are registered from feature files (`get-404-log` in
 * redirects-404-log.php, `provenance-integrity-status`, `uptime-status`), and
 * seven more through `wp_register_ability( $slug, … )` inside a foreach over a
 * definition map — three remote search twins and four Search Console reads.
 * Ten abilities sat outside the permission policy this file exists to enforce.
 *
 * A variable-slug site that CANNOT be resolved is reported, never skipped:
 * `$unresolved` is asserted empty below, so a new loop shape fails here rather
 * than quietly shrinking the population again.
 *
 * @param string $root Plugin root.
 * @return array<string,array{perm:string,readonly:string,file:string}>
 */
function app_registry( $root, &$unresolved = array() ) {
	$out        = array();
	$unresolved = array();
	// snt_test_inc_files() and nothing else: issue #987's meta-guard exists
	// because twelve suites globbed `inc/*.php`, which is the TOP of inc/ and
	// nothing below it — 86 files in packages were invisible to all of them.
	// A hand-rolled two-level glob here would have re-made that mistake in the
	// very file being widened to stop missing things.
	$files = snt_test_inc_files( '*.php' );
	sort( $files );

	foreach ( $files as $file ) {
		$src = snt_ap_strip_comments( (string) file_get_contents( $file ) );

		// ── literal slugs ────────────────────────────────────────────────
		$parts = preg_split( "/wp_register_ability\(\s*'([^']+)'/", $src, -1, PREG_SPLIT_DELIM_CAPTURE );
		for ( $i = 1; $i < count( $parts ); $i += 2 ) {
			$slug = $parts[ $i ];
			$body = $parts[ $i + 1 ];
			preg_match( "/'permission_callback'\s*=>\s*'([^']+)'/", $body, $pm );
			preg_match( "/'readonly'\s*=>\s*(true|false)/", $body, $rm );
			$out[ $slug ] = array(
				'perm'     => $pm[1] ?? '?',
				'readonly' => $rm[1] ?? '-',
				'file'     => basename( $file ),
			);
		}

		// ── variable slugs: `wp_register_ability( $slug, array( … ) )` ────
		if ( ! preg_match_all( '/wp_register_ability\(\s*\$(\w+)\s*,\s*array\((.*?)\n\t*\)\s*\);/s', $src, $vm, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $vm as $site ) {
			$body = $site[2];
			// The literal callback if the registration names one, else the
			// per-entry `'perm'` the loop body reads.
			preg_match( "/'permission_callback'\s*=>\s*'([^']+)'/", $body, $lit );

			// Every `'signal-noise/…' => array(` in this file that is not
			// already registered literally is a member of the looped map.
			preg_match_all( "/'(signal-noise\/[a-z0-9-]+)'\s*=>\s*array\((.*?)\n\t*\),/s", $src, $entries, PREG_SET_ORDER );
			$found = 0;
			foreach ( $entries as $entry ) {
				$slug = $entry[1];
				if ( isset( $out[ $slug ] ) ) {
					continue;
				}
				preg_match( "/'perm'\s*=>\s*'([^']+)'/", $entry[2], $ep );
				$perm = $lit[1] ?? ( $ep[1] ?? '?' );
				$out[ $slug ] = array(
					'perm'     => $perm,
					'readonly' => '-',
					'file'     => basename( $file ) . ' (looped)',
				);
				$found++;
			}
			if ( 0 === $found ) {
				$unresolved[] = basename( $file );
			}
		}
	}
	return $out;
}

$unresolved = array();
$reg        = app_registry( $root, $unresolved );

echo "Group: the parser is not vacuous\n";
// The floor tracks the real population (103 on 2026-09-10). It was `>= 70`
// against an actual 93 — 23 points of slack, enough to delete a fifth of the
// registry unnoticed. A floor only earns its place when it would bite.
ok( count( $reg ) >= 100, 'found the ability registry (' . count( $reg ) . ' abilities, floor 100)' );
ok( array() === $unresolved,
	'every variable-slug registration site resolved to its definition map'
	. ( $unresolved ? ' — UNRESOLVED in: ' . implode( ', ', array_unique( $unresolved ) ) : '' ) );
ok( isset( $reg['signal-noise/sn-scan'] ), 'and a known ability is in it' );
ok( '?' !== ( $reg['signal-noise/sn-scan']['perm'] ?? '?' ), 'with its permission callback resolved' );

// ── Tier A: content reads. THE list, pinned by name. ────────────────────────
$tier_a = array(
	'signal-noise/sn-posts', 'signal-noise/sn-scan', 'signal-noise/sn-validate',
	'signal-noise/get-post-content', 'signal-noise/list-posts', 'signal-noise/duplicate-body-scan',
	'signal-noise/draft-echoes', 'signal-noise/near-duplicate-scan', 'signal-noise/keyword-candidates',
	'signal-noise/link-candidates', 'signal-noise/topic-clusters', 'signal-noise/cadence-flags',
);

echo "\nGroup: Tier A reads at edit_others_posts\n";
foreach ( $tier_a as $slug ) {
	ok( isset( $reg[ $slug ] ), "registered: $slug" );
	ok( 'snt_ability_perm_read_corpus' === ( $reg[ $slug ]['perm'] ?? '' ), "tier A: $slug" );
	// A write tier-A ability would be the whole policy inverted.
	ok( 'true' === ( $reg[ $slug ]['readonly'] ?? '' ), "tier A is readonly: $slug" );
}
ok( 12 === count( $tier_a ), 'exactly 12 abilities in tier A' );

echo "\nGroup: nothing ELSE quietly joined tier A\n";
$actual_a = array();
foreach ( $reg as $slug => $r ) {
	if ( 'snt_ability_perm_read_corpus' === $r['perm'] ) { $actual_a[] = $slug; }
}
sort( $actual_a );
$expected = $tier_a;
sort( $expected );
// The load-bearing direction: a new ability added to the helper without being
// added to the policy fails HERE, which is the whole point of the contract.
ok( $actual_a === $expected, 'the set using the read helper is EXACTLY the policy list (' . count( $actual_a ) . ')' );

echo "\nGroup: PII and infrastructure stayed put\n";
$must_stay = array(
	'signal-noise/get-audit-log'        => 'records usernames and login events',
	'signal-noise/export-audit-log'     => 'same log, exported',
	'signal-noise/get-analytics-events' => 'visitor analytics',
	'signal-noise/get-analytics-summary'=> 'visitor analytics',
	'signal-noise/get-collector-status' => 'infrastructure config',
	'signal-noise/ai-cache-probe-status'=> 'provider config',
	'signal-noise/shape-stability'      => 'operational diagnostics',
	'signal-noise/watches'              => 'operational diagnostics',
	'signal-noise/cache-freshness'      => 'operational diagnostics',
	'signal-noise/sn-site-facts'        => 'settings drift + telemetry',
	'signal-noise/list-cron-events'     => 'scheduler internals',
	'signal-noise/get-cron-history'     => 'scheduler internals',
	'signal-noise/get-deploy-status'    => 'deploy infrastructure',
	'signal-noise/anchor-status'        => 'provenance chain state',
);
foreach ( $must_stay as $slug => $why ) {
	ok(
		'snt_ability_perm_manage_options' === ( $reg[ $slug ]['perm'] ?? '' ),
		"stays manage_options ($why): $slug"
	);
}

echo "\nGroup: no ability is left without a permission callback\n";
$missing = array();
foreach ( $reg as $slug => $r ) {
	if ( '?' === $r['perm'] ) { $missing[] = $slug; }
}
ok( array() === $missing, 'every registered ability declares a permission_callback' . ( $missing ? ': ' . implode( ', ', $missing ) : '' ) );

echo "\nGroup: the helpers exist and say what they check\n";
$helpers = (string) file_get_contents( $root . '/inc/abilities-permission-helpers.php' );
ok( false !== strpos( $helpers, 'function snt_ability_perm_read_corpus' ), 'the read helper is defined' );
ok( false !== strpos( $helpers, "current_user_can( 'edit_others_posts' )" ), 'and checks edit_others_posts' );
// edit_posts would ALSO satisfy "an Editor can call it" while additionally
// granting Authors, who must not read other people's unpublished posts.
ok( false === strpos( $helpers, "current_user_can( 'edit_posts' )" ), 'and NOT edit_posts, which would over-grant to Authors' );

echo "\nGroup: the policy document exists and lists the tier\n";
$policy = (string) @file_get_contents( $root . '/docs/ops/ability-permission-policy.md' );
ok( '' !== $policy, 'the policy document is committed' );
foreach ( $tier_a as $slug ) {
	$short = substr( $slug, strlen( 'signal-noise/' ) );
	ok( false !== strpos( $policy, '`' . $short . '`' ), "policy names $short" );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
