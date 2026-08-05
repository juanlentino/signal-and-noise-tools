<?php
/**
 * Drift guard for the three AI suggest SYSTEM prompts.
 *
 * SNT_AI_DRIFT_SUGGEST_SYSTEM, SNT_AI_LINK_SUGGEST_SYSTEM and
 * SNT_AI_PAIR_SUGGEST_SYSTEM are MODEL INPUT, not copy. They were the only
 * system prompts in the plugin with no pin, and the v10.48.2 admin-copy
 * em-dash sweep rewrote all three because nothing marked them as prompts:
 * `tests/ai-alt-prompt-shared.php` protected the alt-text prompt purely by
 * accident of pinning it. One of the rewrites was actively malformed — the
 * paired-em-dash rule opened a parenthesis in the pair-suggest anchor rule and
 * closed it with a period, because the two dashes sat on different
 * concatenated lines.
 *
 * Pinned by SHA-256 rather than by full text: these prompts are long and
 * multi-line, and a hash keeps the guard readable while still being
 * byte-exact. When a prompt is INTENTIONALLY reworded, update the hash in the
 * same commit as the prompt and say why — that is the signal this test exists
 * to force.
 *
 * Run: php tests/ai-suggest-prompt-pins.php
 * @since plugin v10.49.x
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }

function add_action( $t = null, $c = null, $p = 10, $a = 1 ) {}
function add_filter( $t = null, $c = null, $p = 10, $a = 1 ) {}
function wp_register_ability() {}
function register_rest_route() {}

require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php';
require_once __DIR__ . '/../inc/ai-link-suggest.php';
require_once __DIR__ . '/../inc/ai-pair-suggest.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

/** const name => [ sha256 of the known-good prompt, expected length ] */
$PINS = array(
	'SNT_AI_DRIFT_SUGGEST_SYSTEM' => array( '082a56d88d98ef364fce27d1285891377f6abcbdc7a8964104b25f9ba34262b6', 0 ),
	'SNT_AI_LINK_SUGGEST_SYSTEM'  => array( 'a976f02aaf07a443bf4b3daa0ba7f713c83f1bfb4ed31fc62829f755da6fd550',  0 ),
	'SNT_AI_PAIR_SUGGEST_SYSTEM'  => array( 'b44836592257c5f0cb1e47638c39ced626add2fceec832f17ee6501a183d2176',  0 ),
);

echo "AI suggest prompt pins\n\n";

echo "Group: each prompt is defined and byte-identical to its pin\n";
foreach ( $PINS as $const => $pin ) {
	if ( ! defined( $const ) ) { ok( false, "$const is defined" ); continue; }
	ok( true, "$const is defined" );
	$val = constant( $const );
	ok( hash( 'sha256', $val ) === $pin[0], "$const is byte-identical to its pin (got " . substr( hash( 'sha256', $val ), 0, 16 ) . ", len " . strlen( $val ) . ')' );
}

echo "\nGroup: structural sanity — a rewrite must not leave a prompt malformed\n";
foreach ( array_keys( $PINS ) as $const ) {
	if ( ! defined( $const ) ) { continue; }
	$val = constant( $const );
	ok( substr_count( $val, '(' ) === substr_count( $val, ')' ), "$const has balanced parentheses" );
	ok( false === strpos( $val, '( ' ) || substr_count( $val, '(' ) === substr_count( $val, ')' ), "$const has no dangling open parenthesis" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
