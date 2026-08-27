<?php
/**
 * Standalone tests for inc/desktop-mode-theme.php — the "Signal & Noise"
 * OpenStation desktop theme manifest.
 *
 * What these pin, and why:
 *   1. PALETTE PARITY — every hex in the manifest is one of the FSE theme's
 *      dark-mode literals (signal-and-noise assets/css/critical.css,
 *      `:root[data-theme="dark"]`), RECORDED here because the two repos
 *      cannot read each other at test time. If the theme re-tunes its dark
 *      palette, this failing is the re-sync reminder.
 *   2. RESTRAINT — the owner's direction ("not too big, not too brutalist")
 *      as invariants: a token-count ceiling, zero radius/geometry tokens,
 *      and zero accent-DERIVED tokens (pinning one severs the accent chain
 *      upstream computes at runtime — the upstream doc's own rule).
 *   3. VOCABULARY — shell chrome uses the estate's ONE admin monospace
 *      stack (v13.6.2 ratchet), verbatim.
 *   4. Known traps from the upstream doc: `--os-ui-accent-text` does not
 *      exist (silent no-op); a changed holo FILL requires a changed INK.
 *
 * Run: php tests/desktop-mode-theme.php
 *
 * @since plugin v13.7.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── Minimal stubs: the module only needs add_action at file scope. ──
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = array( 'cb' => $cb, 'p' => $p ); }

require_once __DIR__ . '/../inc/desktop-mode-theme.php';

$m      = snt_desktop_theme_manifest();
$tokens = $m['args']['tokens'];

// ── Shape ────────────────────────────────────────────────────────────
ok( 'signal-noise/asphalt' === $m['id'], 'theme id is signal-noise/asphalt (vendor-namespaced, matches upstream id grammar)' );
ok( 1 === preg_match( '#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#', $m['id'] ) && strlen( $m['id'] ) <= 64, 'id satisfies upstream\'s fatal-field grammar' );
ok( 'Signal & Noise' === $m['args']['name'], 'theme name is the estate name' );
ok( ! isset( $m['args']['fonts'] ), 'no fonts array — the mono stack needs no served files' );
ok( ! isset( $m['args']['recommendedOsSettings'] ), 'no recommendedOsSettings — the owner\'s layout is not this file\'s opinion' );
ok( ! isset( $m['args']['icons'] ) && ! isset( $m['args']['textures'] ), 'no icons, no textures — tokens only' );

// ── Restraint: the owner steer as a ratchet ──────────────────────────
ok( count( $tokens ) <= 30, 'token count ' . count( $tokens ) . ' is at or under the 30-token restraint ceiling' );
$radius_like = array();
foreach ( $tokens as $name => $v ) {
	if ( false !== strpos( $name, 'radius' ) || false !== strpos( $name, 'corner' ) ) { $radius_like[] = $name; }
}
ok( array() === $radius_like, 'no radius/corner tokens — the shell keeps its own geometry' . ( $radius_like ? ' — FOUND: ' . implode( ', ', $radius_like ) : '' ) );

// Accent-DERIVED tokens: pinning any of these severs the runtime accent
// chain (upstream doc: "Answering it with a literal restores nothing and
// severs the chain"). holo-fill/ink/track are NOT derived (they are the
// "on"-state literals Legacy itself answers) and are deliberately set.
$derived = array( '--os-ui-accent-dim', '--os-ui-tab-wash', '--os-ui-tab-bloom', '--os-ui-tab-edge', '--os-ui-focus-ring', '--os-ui-focus-ring-field', '--os-ui-holo-glow', '--os-ui-holo-glow-strong', '--os-ui-holo-sheen', '--os-ui-holo-edge', '--os-ui-holo-edge-quiet' );
$pinned  = array_values( array_intersect( $derived, array_keys( $tokens ) ) );
ok( array() === $pinned, 'no accent-derived tokens pinned — one accent in, the derivations follow' . ( $pinned ? ' — FOUND: ' . implode( ', ', $pinned ) : '' ) );

// ── Namespace discipline: only the three accepted namespaces ─────────
$bad_ns = array();
foreach ( array_keys( $tokens ) as $name ) {
	if ( 0 !== strpos( $name, '--os-' ) && '--wp-admin-theme-color' !== $name ) { $bad_ns[] = $name; }
}
ok( array() === $bad_ns, 'every token is in an upstream-accepted namespace' );
ok( ! isset( $tokens['--os-ui-accent-text'] ), 'the --os-ui-accent-text trap is absent (that name does not exist upstream; the real one is --os-ui-fg-on-accent)' );
ok( isset( $tokens['--os-ui-fg-on-accent'] ), '--os-ui-fg-on-accent is declared (dark ink on the bright red)' );

// ── Palette parity: recorded FSE dark literals ───────────────────────
// signal-and-noise assets/css/critical.css `:root[data-theme="dark"]`,
// recorded 2026-08-27. Plus #000000 (focused titlebar) and #e00404
// (blood LIGHT, reused as the pressed danger state).
$palette = array( '#0a0a0a', '#171717', '#383838', '#9e9e9e', '#ffffff', '#ff4c47', '#ff6b66', '#000000', '#e00404' );
$off = array();
foreach ( $tokens as $name => $v ) {
	if ( 1 === preg_match( '/^#[0-9a-f]{6}$/i', $v ) && ! in_array( strtolower( $v ), $palette, true ) ) { $off[] = "$name=$v"; }
}
ok( array() === $off, 'every hex literal is a recorded estate palette value' . ( $off ? ' — OFF-PALETTE: ' . implode( ', ', $off ) : '' ) );

// Non-hex values: only rgba() derivations of bone/void, or the mono stack.
$loose = array();
foreach ( $tokens as $name => $v ) {
	if ( 1 === preg_match( '/^#[0-9a-f]{6}$/i', $v ) ) { continue; }
	$is_rgba = 1 === preg_match( '/^rgba\( (10, 10, 10|255, 255, 255), 0\.\d+ \)$/', $v );
	$is_font = false !== strpos( $v, 'monospace' );
	if ( ! $is_rgba && ! $is_font ) { $loose[] = "$name=$v"; }
}
ok( array() === $loose, 'every non-hex value is an rgba() of bone/void or the mono stack' . ( $loose ? ' — LOOSE: ' . implode( ', ', $loose ) : '' ) );

// ── Vocabulary: the v13.6.2 admin mono stack, verbatim ───────────────
$stack = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
ok( $tokens['--os-font'] === $stack, '--os-font is the estate admin mono stack, verbatim' );
ok( $tokens['--os-titlebar-font'] === $stack, '--os-titlebar-font matches it (one vocabulary, not two)' );

// ── The doc's fill/ink pair rule ─────────────────────────────────────
ok( isset( $tokens['--os-ui-holo-fill'] ) === isset( $tokens['--os-ui-holo-ink'] ), 'holo fill and ink are set as a pair or not at all' );
ok( '#ff4c47' === $tokens['--os-ui-holo-fill'] && '#0a0a0a' === $tokens['--os-ui-holo-ink'], 'the "on" state is blood with dark ink (bright fill => dark ink, per the upstream rule)' );

// ── Registration wiring ──────────────────────────────────────────────
ok( isset( $GLOBALS['__actions']['init'] ) && 1 === count( $GLOBALS['__actions']['init'] ), 'the module adds exactly one init callback' );
ok( 10 === $GLOBALS['__actions']['init'][0]['p'], 'init priority is 10 — no ordering contract with the init:6 command/widget payload' );

// Absent shell => the callback is a clean no-op (snt_os_active() false).
function snt_os_active() { return false; }
$err = null;
try { call_user_func( $GLOBALS['__actions']['init'][0]['cb'] ); } catch ( \Throwable $e ) { $err = $e; }
ok( null === $err, 'with no shell present the init callback no-ops without touching the register wrapper' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
