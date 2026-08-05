<?php
/**
 * CLI fixture for the v10.48.0 glance-card command surface.
 *
 * Two behaviours, both load-bearing:
 *   - an href turns the card into an <a>, and the closing tag must TRACK that
 *     choice rather than assume </div> (a mismatched close would break the grid
 *     for every card after it);
 *   - the attention sort is STABLE, so calm cards keep their deliberate reading
 *     order instead of reshuffling on every page load.
 *
 * Run: php tests/admin-glance-command.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { $u = (string) $u; return preg_match( '#^https?://#i', $u ) ? $u : ''; }
function wp_kses_post( $s ) { return (string) $s; }

require __DIR__ . '/../inc/admin-glance.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Glance cards as a command surface (v10.48.0)\n\n";

ob_start();
sn_admin_glance_grid( array(
	array( 'label' => 'Health', 'value' => '0 findings', 'href' => 'https://x.test/wp-admin/admin.php?tab=monitoring' ),
	array( 'label' => 'Cron', 'value' => '43 events' ),
) );
$html = ob_get_clean();
ok( false !== strpos( $html, '<a class="sn-glance-card sn-glance-card--link"' ), 'a card with an href renders as an anchor' );
ok( false !== strpos( $html, 'href="https://x.test/wp-admin/admin.php?tab=monitoring"' ), 'the href is emitted' );
ok( false !== strpos( $html, '<div class="sn-glance-card"' ), 'a card without an href stays a div' );
ok( 1 === substr_count( $html, '</a>' ), 'the anchor closes with </a> — the tag is tracked, not assumed' );
ok( 1 === substr_count( $html, '</div>' ) - 1, 'the non-link card still closes with </div> (one card + the grid wrapper)' );

// Card definitions are first-party today; esc_url is the backstop that keeps
// that from being the only thing standing between here and an injected target.
ob_start();
sn_admin_glance_grid( array( array( 'label' => 'X', 'value' => 'y', 'href' => 'javascript:alert(1)' ) ) );
$evil = ob_get_clean();
ok( false === strpos( $evil, 'javascript:' ), 'a javascript: href is refused by esc_url' );

// ── Attention sort: rank, then stability ──
$cards = array(
	array( 'label' => 'A', 'pill' => array( 'kind' => 'ok', 'text' => 'fine' ) ),
	array( 'label' => 'B', 'pill' => array( 'kind' => 'warn', 'text' => 'look' ) ),
	array( 'label' => 'C' ),
	array( 'label' => 'D', 'pill' => array( 'kind' => 'err', 'text' => 'bad' ) ),
	array( 'label' => 'E', 'pill' => array( 'kind' => 'warn', 'text' => 'look' ) ),
	array( 'label' => 'F', 'pill' => array( 'kind' => 'ok', 'text' => 'fine' ) ),
);
$sorted = array_column( sn_admin_glance_sort_by_attention( $cards ), 'label' );
ok( array( 'D', 'B', 'E', 'A', 'C', 'F' ) === $sorted,
	'err first, then warn, then the rest — and ORIGINAL order is preserved inside each class (a reshuffling grid trains people to stop reading it)' );
ok( array() === sn_admin_glance_sort_by_attention( array() ), 'an empty set sorts to empty' );
$one = sn_admin_glance_sort_by_attention( array( array( 'label' => 'solo' ) ) );
ok( 'solo' === $one[0]['label'], 'a single card survives the sort' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
