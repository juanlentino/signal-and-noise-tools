<?php
/**
 * Signal & Noise — Login URL admin section (Security tab → Login sub-tab).
 *
 * Renders the custom-login-URL module status (active / dormant-conflict /
 * bypassed / constant-locked), the slug form (sn_action=save_login), and the
 * emergency-unlock docs. Extracted verbatim from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Login URL section body. Used as the sn_admin_render_section()
 * callback for the 'login' sub-tab.
 */
function sn_admin_render_login_section() {
	// Detect module state. Three possibilities:
	//   1. ACTIVE: our login-hide.php is firing (no wps-hide-login,
	//      no SN_LOGIN_BYPASS)
	//   2. DORMANT (conflict): wps-hide-login is still active so
	//      our module stood down
	//   3. DORMANT (bypass): SN_LOGIN_BYPASS constant is set
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$wps_basename = 'wps-hide-login/wps-hide-login.php';
	$wps_active   = is_plugin_active( $wps_basename ) && file_exists( WP_PLUGIN_DIR . '/' . $wps_basename );
	$bypassed     = defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS;
	$slug         = function_exists( 'sn_login_get_slug' ) ? sn_login_get_slug() : sn_setting( 'login.slug', 'sn-login' );
	$slug_const   = defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG;
	$login_url    = home_url( '/' . $slug );

	echo '<p class="sn-prose">Custom login URL module — replaces <code>/wp-login.php</code> with a configurable slug. Designed to mask the WordPress login surface from automated bots without changing real user flows (password-reset emails, logout redirects, etc. are rewritten automatically).</p>';

	// Status box
	if ( $bypassed ) {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Module bypassed</p>';
		echo '<p class="sn-status-box-body">The <code>SN_LOGIN_BYPASS</code> constant is set in <code>wp-config.php</code>. Default <code>/wp-login.php</code> behavior is restored. Remove the constant to re-enable.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Bypassed</span>';
		echo '</div>';
	} elseif ( $wps_active ) {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Module dormant — conflict with wps-hide-login</p>';
		echo '<p class="sn-status-box-body">The <code>wps-hide-login</code> plugin is still active. Our built-in module stands down to avoid rewrite conflicts. Deactivate that plugin to switch over to this one.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Dormant</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Module active</p>';
		echo '<p class="sn-status-box-body">Direct visits to <code>/wp-login.php</code> and unauthenticated <code>/wp-admin</code> return 404. Login form reachable at the custom URL below.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--ok">Active</span>';
		echo '</div>';
	}

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="save_login">';

	echo '<h2 class="sn-fieldset-h">Custom login slug</h2>';
	echo '<p class="sn-fieldset-intro">The path segment used in place of <code>wp-login.php</code>.</p>';

	echo '<div class="sn-field sn-field-w-sm">';
	echo '<label class="sn-field-label" for="sn_login_slug">Slug</label>';
	if ( $slug_const ) {
		echo '<input type="text" id="sn_login_slug" value="' . esc_attr( $slug ) . '" disabled>';
		echo '<p class="sn-field-helper"><strong>Locked.</strong> The <code>SN_LOGIN_SLUG</code> constant in <code>wp-config.php</code> is overriding this field. Remove the constant to edit here.</p>';
	} else {
		echo '<input type="text" id="sn_login_slug" name="login_slug" value="' . esc_attr( $slug ) . '" placeholder="sn-login">';
		echo '<p class="sn-field-helper">Letters, numbers, dashes only. Avoid common guesses (admin, login, panel, etc.).</p>';
	}
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Current login URL</label>';
	echo '<a class="sn-url-preview" href="' . esc_url( $login_url ) . '" target="_blank" rel="noopener">' . esc_html( $login_url ) . '</a>';
	echo '<p class="sn-field-helper">Bookmark this URL. The default <code>/wp-login.php</code> 404s for unauthenticated visitors.</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	if ( $slug_const ) {
		echo '<p class="sn-fieldset-actions-hint">Slug locked by <code>SN_LOGIN_SLUG</code> constant.</p>';
	}
	echo '<button type="submit" class="button button-primary"' . ( $slug_const ? ' disabled' : '' ) . '>Save</button>';
	echo '</div>';

	echo '</form>';

	// Emergency unlock docs (out-of-form, no submission)
	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">Emergency unlock</p>';
	echo '<p>If you ever lock yourself out (forgot the slug, can\'t reach the login form), add either of these constants to <code>wp-config.php</code> via SSH or your host\'s file manager:</p>';
	echo '<pre>// Option 1 — pin the slug. Reachable at /&lt;slug-here&gt;.
define( \'SN_LOGIN_SLUG\', \'your-fallback-slug\' );

// Option 2 — disable the module entirely. Restores /wp-login.php.
define( \'SN_LOGIN_BYPASS\', true );</pre>';
	echo '<p>The constants take priority over the setting and persist across plugin updates. Remove them once you\'ve regained access.</p>';
	echo '</div>';
}
