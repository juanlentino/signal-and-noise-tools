<?php
/**
 * Agent Skills Discovery index (/.well-known/agent-skills/index.json).
 *
 * The assertions that matter are the ones a fixture could not make: that the
 * published digest is the hash of the bytes actually served, and that a slug
 * arriving from a URL can never reach the filesystem unless the registry
 * already knows it. Both are driven through the real producer against the real
 * skills/ directory.
 *
 * Run: php tests/agent-skills.php
 *
 * @since 12.20.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
define( 'SN_AGENT_DISCOVERY_TEST', true );
define( 'SNT_PATH', __DIR__ . '/../' );

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return $s; } }

require_once __DIR__ . '/../inc/agent-discovery.php';
require_once __DIR__ . '/../inc/agent-skills.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

$index = sn_agent_skills_index();

// 1. RFC v0.2.0 index shape.
ok( isset( $index['$schema'] ) && $index['$schema'] === SN_AGENT_SKILLS_SCHEMA, '$schema names the 0.2.0 discovery schema' );
ok( isset( $index['skills'] ) && is_array( $index['skills'] ), 'skills is an array' );
ok( count( $index['skills'] ) > 0, 'at least one skill is published' );

// 2. Every entry carries the five required fields, correctly shaped.
foreach ( $index['skills'] as $s ) {
	$n = isset( $s['name'] ) ? $s['name'] : '(unnamed)';
	ok( preg_match( '/^[a-z0-9-]{1,64}$/', (string) $s['name'] ) === 1, "'$n' name matches the Agent Skills naming rule" );
	ok( 'skill-md' === $s['type'], "'$n' type is skill-md" );
	ok( '' !== $s['description'], "'$n' has a description" );
	ok( strlen( $s['description'] ) <= 1024, "'$n' description within the 1024 cap" );
	ok( preg_match( '#^https://juanlentino\.com/\.well-known/agent-skills/' . preg_quote( $s['name'], '#' ) . '/SKILL\.md$#', (string) $s['url'] ) === 1, "'$n' url is absolute and canonical" );
	ok( preg_match( '/^sha256:[0-9a-f]{64}$/', (string) $s['digest'] ) === 1, "'$n' digest is sha256:{64 lowercase hex}" );
}

// 3. THE DIGEST IS OF THE BYTES ACTUALLY SERVED. This is the assertion a
//    stored-digest table would fail silently: hash the file the artifact route
//    resolves to, and require it to equal what the index publishes.
foreach ( $index['skills'] as $s ) {
	$slug = $s['name'];
	$path = SNT_PATH . 'skills/' . $slug . '/SKILL.md';
	ok( is_file( $path ), "'$slug' artifact exists on disk" );
	ok( $s['digest'] === 'sha256:' . hash_file( 'sha256', $path ),
		"'$slug' published digest equals the hash of the served bytes" );
}

// 4. The description is the artifact's own first paragraph — not a parallel
//    table that can drift away from the document.
foreach ( $index['skills'] as $s ) {
	$md   = (string) file_get_contents( SNT_PATH . 'skills/' . $s['name'] . '/SKILL.md' );
	$first = sn_agent_skills_description( $md );
	ok( $s['description'] === $first, "'{$s['name']}' description is derived from the artifact" );
	ok( 0 !== strpos( $s['description'], '#' ), "'{$s['name']}' description is not the markdown title" );
}

// 5. ROUTING + PATH SAFETY. A slug reaches the filesystem only via the registry.
ok( sn_agent_skills_index_is_request( '/.well-known/agent-skills/index.json' ), 'index path matches' );
ok( ! sn_agent_skills_index_is_request( '/.well-known/agent-skills/' ), 'bare directory does not match the index' );
$known = array_keys( sn_agent_skills_registry() );
ok( count( $known ) > 0, 'registry discovered skills by scanning' );
ok( sn_agent_skills_match_artifact( '/.well-known/agent-skills/' . $known[0] . '/SKILL.md' ) === $known[0], 'known slug resolves' );
foreach ( array(
	'/.well-known/agent-skills/../../wp-config.php/SKILL.md',
	'/.well-known/agent-skills/%2e%2e/SKILL.md',
	'/.well-known/agent-skills/not-a-real-skill/SKILL.md',
	'/.well-known/agent-skills/' . $known[0] . '/../SKILL.md',
	'/.well-known/agent-skills/' . $known[0] . '/SKILL.md.bak',
) as $hostile ) {
	ok( '' === sn_agent_skills_match_artifact( $hostile ), 'rejected: ' . $hostile );
}

// 6. A directory whose name breaks the naming rule is never published.
ok( ! array_key_exists( 'Not_A_Slug', sn_agent_skills_registry() ), 'invalid directory names are skipped' );

// 7. It advertises itself on agents.json.
$surfaces = sn_agent_skills_advertise_surface( array() );
ok( 1 === count( $surfaces ) && 'agent-skills' === $surfaces[0]['type'], 'appends its own surface' );
ok( $surfaces[0]['url'] === 'https://juanlentino.com' . SN_AGENT_SKILLS_INDEX_PATH, 'surface url correct' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
