<?php
/**
 * Standalone test: every admin form action points at a REGISTERED page slug.
 *
 * THE BUG THIS EXISTS FOR (found live 2026-08-28). The Insights settings form
 * posted to `admin.php?page=sn-insights`. That slug stopped being a registered
 * page in v3.8.1, when inc/admin-menu.php moved to registering ONLY the top
 * tabs from sn_admin_top_tabs() instead of the 12 legacy slugs in
 * sn_admin_pages(). WordPress core's admin.php then wp_die()s "Sorry, you are
 * not allowed to access this page" on the POST.
 *
 * That failure reads exactly like a permissions problem and is not one — it is
 * routing. And it only bit on SAVE: a GET to a legacy slug is rescued by
 * sn_admin_maybe_redirect_legacy(), so the leaf rendered fine and only the
 * form broke. inc/admin-legacy-redirect.php's own docblock warns that "POST
 * bodies submitted to a legacy URL are lost in the redirect" — nothing
 * enforced it, so the drift shipped and sat.
 *
 * SCOPE IS THE LAYER, NOT A FILE. This scans every inc/*.php rather than the
 * files that happen to carry forms today, so splitting a module or adding a
 * new admin surface cannot slip past it.
 *
 * The registered set is read from the REAL producer — sn_admin_top_tabs() is
 * called, never re-declared here — so a slug rename moves both sides together
 * and this test cannot drift away from what WordPress actually registers.
 *
 * Standalone — no PHPUnit. Run: php tests/admin-form-action-routing.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

/** @param string $label @param bool $cond */
function t( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL  $label\n";
	}
}

// ─── minimal stubs so the registry module loads ──────────────────────
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }

require_once __DIR__ . '/../inc/admin-tabs-data.php';

// ─── the registered set, from the real producer ──────────────────────
$registered = array();
foreach ( sn_admin_top_tabs() as $tab ) {
	if ( isset( $tab['slug'] ) ) {
		$registered[] = $tab['slug'];
	}
}
sort( $registered );

echo "Registered page slugs (" . count( $registered ) . "): " . implode( ', ', $registered ) . "\n";

// A registry that came back empty or absurdly small would make every
// assertion below vacuous — the scan would find nothing to contradict.
t( 'registry returned a plausible number of slugs', count( $registered ) >= 5 );
t( 'registry contains the parent menu slug sn-theme-options', in_array( 'sn-theme-options', $registered, true ) );

// ─── scan every admin module for hardcoded form actions ──────────────
/**
 * Returns [ [file, slug, line], ... ] for every `<form ... admin.php?page=X`.
 *
 * @param string $dir
 * @return array
 */
function snt_scan_form_action_slugs( $dir ) {
	$found = array();
	foreach ( glob( $dir . '/*.php' ) as $file ) {
		$lines = file( $file, FILE_IGNORE_NEW_LINES );
		foreach ( $lines as $i => $line ) {
			if ( false === strpos( $line, '<form' ) ) {
				continue;
			}
			if ( preg_match( '/admin\.php\?page=([A-Za-z0-9_-]+)/', $line, $m ) ) {
				$found[] = array( basename( $file ), $m[1], $i + 1 );
			}
		}
	}
	return $found;
}

$actions = snt_scan_form_action_slugs( __DIR__ . '/../inc' );

// Print the derived set. A filter that silently matched nothing would
// otherwise pass as green — the scan IS the instrument, so it reports.
echo "Form actions with an explicit page slug (" . count( $actions ) . "):\n";
foreach ( $actions as $a ) {
	echo "  {$a[0]}:{$a[2]}  -> {$a[1]}\n";
}
if ( ! $actions ) {
	echo "  (none — every admin form posts to the current url)\n";
}

foreach ( $actions as $a ) {
	list( $file, $slug, $line ) = $a;
	t(
		"$file:$line form action page=$slug is a registered slug",
		in_array( $slug, $registered, true )
	);
}

// ─── NEGATIVE CONTROL ────────────────────────────────────────────────
// Prove the scanner can actually fail. Without this, a regex that matched
// nothing would report green forever and this whole file would be theatre.
$probe_dir = sys_get_temp_dir() . '/snt-form-action-probe-' . getmypid();
@mkdir( $probe_dir );
file_put_contents(
	$probe_dir . '/broken.php',
	"<?php echo '<form method=\"post\" action=\"' . esc_url( admin_url( 'admin.php?page=sn-insights' ) ) . '\">';\n"
);
$probe = snt_scan_form_action_slugs( $probe_dir );
@unlink( $probe_dir . '/broken.php' );
@rmdir( $probe_dir );

t( 'negative control: scanner FINDS a hardcoded legacy action', 1 === count( $probe ) );
t( 'negative control: it extracts the slug verbatim', isset( $probe[0][1] ) && 'sn-insights' === $probe[0][1] );
t( 'negative control: sn-insights is NOT in the registered set', ! in_array( 'sn-insights', $registered, true ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
