<?php
/**
 * Stubs for core's _print_emoji_detection_script() when tests/core-fingerprint.php
 * evals it into this namespace. Unqualified calls resolve here first, then fall
 * back to the global test stubs (apply_filters, so our real filter runs).
 */

namespace cfcore;

const WPINC = 'wp-includes';

function get_bloginfo( $k ) { return 'version' === $k ? $GLOBALS['wp_version'] : ''; }
function includes_url( $p = '' ) { return 'https://juanlentino.com/wp-includes/' . ltrim( $p, '/' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_scripts_get_suffix() { return '.min'; }
function file_get_contents( $f ) { return 'loader();'; }
function esc_url_raw( $u ) { return $u; }
function wp_print_inline_script_tag( $js, $attrs = array() ) { echo '<script>' . $js . "</script>\n"; }
