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

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );

/** Parse every wp_register_ability() call into slug => [callback, readonly]. */
function app_registry( $root ) {
	$out = array();
	foreach ( glob( $root . '/inc/abilities-*.php' ) as $file ) {
		$src   = (string) file_get_contents( $file );
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
	}
	return $out;
}

$reg = app_registry( $root );

echo "Group: the parser is not vacuous\n";
ok( count( $reg ) >= 70, 'found the ability registry (' . count( $reg ) . ' abilities)' );
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
