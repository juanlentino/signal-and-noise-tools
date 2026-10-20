<?php
/**
 * Signal & Noise Tools: the theme's pre-paint dark-mode script, for pages the
 * plugin prints itself (/verify).
 *
 * The strict CSP pins this script by hash, so it must be the THEME's bytes
 * exactly (theme inc/dark-mode.php, SN_THEME_INIT_SHA256). The theme prints it
 * from an anonymous wp_head closure and exposes no function to reuse, so this
 * is the one copy; tests/provenance-verify.php pins its hash to the theme's.
 * If the theme's script changes, change this with it, or /verify's replay is
 * blocked once the policy is enforced.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The theme-init <script> element, byte-identical to the theme's.
 *
 * The key is a slug: filtered to slug characters, it can carry nothing that
 * closes a JS string or the element (esc_js() is absent from the test harness).
 *
 * @return string
 */
function sn_theme_init_script() {
	$key = preg_replace( '/[^A-Za-z0-9_-]/', '', defined( 'SN_THEME_STORAGE_KEY' ) ? (string) SN_THEME_STORAGE_KEY : 'sn-theme' );
	return '<script id="sn-theme-init">' . "\n"
		. "(function(){try{var t=localStorage.getItem('" . $key . "');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();\n"
		. '</script>';
}
