<?php
/**
 * Signal & Noise Tools — painting the shell's component kit from PHP.
 *
 * The two native windows (apps/sn-dashboard, apps/sn-analytics) paint their
 * bodies from OpenStation's `<os-*>` kit in server views. These helpers are the
 * vocabulary every leaf painter speaks: escaping that mirrors the framework's
 * own `OpenStation\App\Html` helpers byte for byte (so a painter behaves the
 * same under a test harness without the framework), and one function per kit
 * element the leaves use. A helper never invents an attribute: every name here
 * is in the component's `static help` block (OpenStation 1.1.6).
 *
 * Sister files: inc/openstation-kit-display.php (stat, section, notice, badge,
 * chip, code, empty state, the bound tab strip), inc/openstation-kit-data.php
 * (tables, histograms, lists), inc/openstation-kit-forms.php (forms, fields)
 * and inc/openstation-kit-triggers.php (buttons, doors, in-window links).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escape text for HTML — the framework's `esc()`.
 *
 * @param mixed $text Value.
 * @return string
 */
function snt_kit_esc( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * JSON for an attribute value — the framework's `json()`.
 *
 * @param mixed $value Value.
 * @return string
 */
function snt_kit_json( $value ) {
	return snt_kit_esc( (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/**
 * Attributes — the framework's `attr()`: `true` is bare, `false`/`null` are
 * skipped, arrays are JSON, names are validated, values escaped.
 *
 * @param array<string,mixed> $attrs Attributes.
 * @return string Leading space included when non-empty.
 */
function snt_kit_attr( array $attrs ) {
	$out = '';
	foreach ( $attrs as $name => $value ) {
		if ( false === $value || null === $value ) {
			continue;
		}
		$name = (string) $name;
		if ( '' === $name || ! preg_match( '/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name ) ) {
			continue;
		}
		if ( true === $value ) {
			$out .= ' ' . $name;
			continue;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$out .= ' ' . $name . '="' . snt_kit_json( $value ) . '"';
			continue;
		}
		$out .= ' ' . $name . '="' . snt_kit_esc( $value ) . '"';
	}
	return $out;
}

/**
 * One element — the framework's `tag()`. Attributes are escaped; the inner
 * HTML is the caller's to escape.
 *
 * @param string              $name  Tag name.
 * @param array<string,mixed> $attrs Attributes.
 * @param string              $inner Inner HTML.
 * @return string
 */
function snt_kit_tag( $name, array $attrs = array(), $inner = '' ) {
	static $void = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
	$name = strtolower( (string) preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $name ) );
	if ( '' === $name ) {
		return '';
	}
	$open = '<' . $name . snt_kit_attr( $attrs ) . '>';
	if ( in_array( $name, $void, true ) ) {
		return $open;
	}
	return $open . (string) $inner . '</' . $name . '>';
}

/**
 * The plugin's pill vocabulary (`ok` / `warn` / `err` / `info`) as the kit's
 * tone vocabulary. Anything else is `neutral`.
 *
 * @param string $kind Pill kind.
 * @return string success|warning|danger|info|neutral
 */
function snt_kit_tone( $kind ) {
	$map = array(
		'ok'      => 'success',
		'success' => 'success',
		'warn'    => 'warning',
		'warning' => 'warning',
		'err'     => 'danger',
		'error'   => 'danger',
		'danger'  => 'danger',
		'info'    => 'info',
	);
	return $map[ (string) $kind ] ?? 'neutral';
}
