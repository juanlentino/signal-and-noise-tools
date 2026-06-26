<?php
/**
 * Signal & Noise Tools: sn/scheduled dynamic-block render callback.
 *
 * Task 3 of the scheduled-content subsystem: the server-side render gate that
 * reveals or withholds a hand-authored fragment based on a date window. This is
 * the ONLY cache-coherent place to gate: the site is fronted by Cloudflare
 * Cache-Everything, so any decision baked into the cached page HTML (a class, a
 * display:none wrapper, an inline conditional) would be frozen at cache-fill
 * time and leak to everyone. A dynamic block re-runs this callback on each
 * un-cached render, so the gate lives here.
 *
 * The callback asks one question of the pure window gate from
 * inc/schedule-engine.php (sn_schedule_is_open): is [from, until) open at the
 * current UTC instant? Two outcomes, both verbatim:
 *
 *   - OPEN   -> return $content UNCHANGED. No wrapper, no attribute, no markup is
 *     added, so a gated-open fragment is byte-identical to an un-gated one. There
 *     is nothing to escape on this path: $content is already-serialized block
 *     output that core hands us, returned untouched.
 *   - CLOSED -> return '' (the empty string). The content never enters the served
 *     HTML, so there is zero view-source / scraper leak before the window opens.
 *     A CSS-hidden wrapper would still ship the bytes; the empty string does not.
 *
 * Attributes (from / until) are read from $attrs, which for a dynamic block is
 * the block-comment JSON only. They are NOT read from $content: a `source:html`
 * attribute arrives EMPTY server-side (a known trap in this codebase), so the
 * window data is carried as plain comment-JSON attributes instead.
 *
 * UTC-now is obtained through current_time( 'timestamp', true ) — a WordPress
 * function (not bare PHP time()) so tests can stub it, and the `true`/GMT flag so
 * the integer it returns is a UTC Unix timestamp, matching the basis the gate
 * parses its UTC string boundaries against.
 *
 * The block.json metadata, the buildless editor script, and the
 * register_block_type wiring all live in this file too (Task 4). They are hooked
 * on init but stay DORMANT until the plugin bootstrap require's this file in the
 * final task, the same dormant pattern the rest of this subsystem follows. The
 * init hooks are skipped under SN_SCHEDULE_BLOCK_TEST so the contract test can
 * require this file without triggering WP registration side effects.
 *
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render callback for the sn/scheduled dynamic block.
 *
 * Gates $content on the half-open UTC window [from, until). Open returns the
 * content verbatim; closed returns the empty string so nothing leaks into the
 * cached HTML before the window opens.
 *
 * The from/until values are trimmed before they reach the gate: a stray
 * whitespace-only attribute (e.g. ' ') would otherwise be passed to
 * strtotime( ' UTC' ), which can resolve to the CURRENT time and flip a fragment
 * open early. A trimmed empty string is the gate's "unbounded" case (open on
 * that side), which is the safe, intended reading of a blank boundary.
 *
 * @param array  $attrs   Block attributes from the comment JSON. Reads
 *                        $attrs['from'] and $attrs['until'] (UTC datetime strings
 *                        in MySQL `Y-m-d H:i:s` form, or absent/empty = unbounded).
 * @param string $content The serialized inner block content to gate. Returned
 *                        verbatim when open; never escaped (it is already
 *                        block-rendered output handed in by core).
 * @return string $content when the window is open, '' when it is closed.
 */
function sn_scheduled_block_render( array $attrs, $content ) {
	// Half-open window boundaries from the comment-JSON attributes. Trim so a
	// whitespace-only value collapses to the gate's unbounded ('') case rather
	// than aliasing to "now" inside strtotime (an early-leak guard).
	$from  = trim( (string) ( $attrs['from'] ?? '' ) );
	$until = trim( (string) ( $attrs['until'] ?? '' ) );

	// UTC-now as a Unix timestamp. current_time( 'timestamp', true ) is a WP
	// function (stubbable in tests) and the GMT flag makes the integer UTC, the
	// basis the gate parses its UTC string boundaries against.
	$now_utc = (int) current_time( 'timestamp', true );

	if ( sn_schedule_is_open( $from, $until, $now_utc ) ) {
		// OPEN: byte-identical passthrough. No wrapper, no attribute, no markup.
		return (string) $content;
	}

	// CLOSED: emit nothing. The content must not reach the served HTML at all,
	// so there is no display:none leak and no view-source / scraper exposure
	// before the window opens.
	return '';
}

/**
 * Register the buildless editor script for the sn/scheduled block.
 *
 * editorScript in block.json is the manually-registered handle string
 * 'signal-noise-scheduled-editor', NOT a file: path. A file: path in a no-build
 * repo loads with EMPTY deps (there is no .asset.php sidecar) and throws
 * 'wp is undefined'. Registering the handle here with explicit deps is the
 * proven pattern from the sibling theme's inc/blocks-register.php.
 *
 * The asset URL derives from SNT_URL (plugin_dir_url, with a trailing slash) and
 * the version from SNT_VERSION (read from the plugin docblock at bootstrap), both
 * defined in signal-and-noise-tools.php. The cache-buster therefore tracks every
 * plugin release with no second source of truth.
 */
function sn_scheduled_register_editor_script() {
	wp_register_script(
		'signal-noise-scheduled-editor',
		SNT_URL . 'blocks/scheduled/editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ),
		SNT_VERSION,
		true
	);
}

/**
 * Register the sn/scheduled dynamic block from its blocks/scheduled directory.
 *
 * The block.json metadata is read from disk and composed with the PHP
 * render_callback supplied here (block.json carries no `render` key; the
 * callback is passed as a register_block_type arg). The callback gates the
 * inner-block content on the UTC window, returning it verbatim when open and the
 * empty string when closed.
 */
function sn_scheduled_register_block() {
	register_block_type(
		SNT_PATH . 'blocks/scheduled',
		array( 'render_callback' => 'sn_scheduled_block_render' )
	);
}

// Hook on init (the theme registers its blocks on init too). Skipped under the
// contract-test constant so tests can require this file without WP side effects.
if ( ! defined( 'SN_SCHEDULE_BLOCK_TEST' ) || ! SN_SCHEDULE_BLOCK_TEST ) {
	add_action( 'init', 'sn_scheduled_register_editor_script' );
	add_action( 'init', 'sn_scheduled_register_block' );
}
