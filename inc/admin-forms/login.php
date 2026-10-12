<?php
/**
 * Signal & Noise — Login URL admin section (Security tab → Login sub-tab).
 *
 * Renders the custom-login-URL module status (active / dormant-conflict /
 * bypassed / constant-locked), the slug form (sn_action=save_login), and the
 * emergency-unlock docs. Extracted verbatim from inc/admin-page.php in v4.5.4.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The module's state, read the way inc/login-hide.php decides it: its three
 * early returns, in its order (2026-10-12 audit: both surfaces checked only
 * the bypass and wps-hide-login, so a site on rename-wp-login read "Module
 * active", and every dormant state still offered the custom slug's URL,
 * which 404s while the module is off). `login_url` is the URL that works now.
 *
 * @return array{state:string,slug:string,locked:bool,login_url:string}
 */
function sn_login_module_state() {
	if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$genuine = static fn( $basename ) => function_exists( 'is_plugin_active' ) && is_plugin_active( $basename ) && defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $basename );
	$state   = 'active';
	if ( defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS ) {
		$state = 'bypassed';
	} elseif ( $genuine( 'wps-hide-login/wps-hide-login.php' ) ) {
		$state = 'wps-hide-login';
	} elseif ( $genuine( 'rename-wp-login/rename-wp-login.php' ) ) {
		$state = 'rename-wp-login';
	}
	// The constant first, as sn_login_get_slug() reads it: that function is
	// not declared when login-hide.php stood down.
	if ( defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG ) {
		$slug = trim( (string) SN_LOGIN_SLUG, '/' );
	} else {
		$slug = (string) ( function_exists( 'sn_login_get_slug' ) ? sn_login_get_slug() : sn_setting( 'login.slug', 'sn-login' ) );
	}
	return array(
		'state'     => $state,
		'slug'      => $slug,
		'locked'    => defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG,
		'login_url' => 'active' === $state ? home_url( '/' . $slug ) : wp_login_url(),
	);
}

/**
 * The status the two surfaces print for a state, in the same words.
 *
 * @param string $state From sn_login_module_state().
 * @return array{kind:string,title:string,body:string,pill:string,url_hint:string}
 */
function sn_login_status_copy( $state ) {
	$off = __( 'The custom slug is not in use while the module is off; this is the login URL that works now.', 'signal-and-noise-tools' );
	switch ( $state ) {
		case 'bypassed':
			return array( 'kind' => 'warn', 'title' => __( 'Module bypassed', 'signal-and-noise-tools' ), 'body' => __( 'The SN_LOGIN_BYPASS constant is set in wp-config.php. Default /wp-login.php behavior is restored. Remove the constant to re-enable.', 'signal-and-noise-tools' ), 'pill' => __( 'Bypassed', 'signal-and-noise-tools' ), 'url_hint' => $off );
		case 'wps-hide-login':
		case 'rename-wp-login':
			return array(
				'kind'     => 'warn',
				/* translators: %s: the other login plugin's slug */
				'title'    => sprintf( __( 'Module dormant: conflict with %s', 'signal-and-noise-tools' ), $state ),
				/* translators: %s: the other login plugin's slug */
				'body'     => sprintf( __( 'The %s plugin is still active. Our built-in module stands down to avoid rewrite conflicts. Deactivate that plugin to switch over to this one.', 'signal-and-noise-tools' ), $state ),
				'pill'     => __( 'Dormant', 'signal-and-noise-tools' ),
				'url_hint' => $off,
			);
	}
	return array( 'kind' => 'ok', 'title' => __( 'Module active', 'signal-and-noise-tools' ), 'body' => __( 'Direct visits to /wp-login.php and unauthenticated /wp-admin return 404. The login form is at the address in Current login URL.', 'signal-and-noise-tools' ), 'pill' => __( 'Active', 'signal-and-noise-tools' ), 'url_hint' => __( 'Bookmark this URL. The default /wp-login.php 404s for unauthenticated visitors.', 'signal-and-noise-tools' ) );
}

/**
 * Render the Login URL section body. Used as the sn_admin_render_section()
 * callback for the 'login' sub-tab.
 */
function sn_admin_render_login_section() {
	$st         = sn_login_module_state();
	$copy       = sn_login_status_copy( $st['state'] );
	$slug       = $st['slug'];
	$slug_const = $st['locked'];
	$login_url  = $st['login_url'];

	echo '<p class="sn-prose">Custom login URL module: replaces <code>/wp-login.php</code> with a configurable slug. Designed to mask the WordPress login surface from automated bots without changing real user flows (password-reset emails, logout redirects, etc. are rewritten automatically).</p>';

	echo '<div class="sn-status-box' . ( 'ok' === $copy['kind'] ? '' : ' sn-status-box--warn' ) . '">';
	echo '<div>';
	echo '<p class="sn-status-box-title">' . esc_html( $copy['title'] ) . '</p>';
	echo '<p class="sn-status-box-body">' . esc_html( $copy['body'] ) . '</p>';
	echo '</div>';
	echo '<span class="sn-pill sn-pill--' . esc_attr( $copy['kind'] ) . '">' . esc_html( $copy['pill'] ) . '</span>';
	echo '</div>';

	// Locked: no form. A constant decides the slug, so a Save could only fail.
	if ( ! $slug_const ) {
		echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
		wp_nonce_field( 'sn_save_login' );
		echo '<input type="hidden" name="action" value="sn_save_login">';
	}

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
	echo '<p class="sn-field-helper">' . esc_html( $copy['url_hint'] ) . '</p>';
	echo '</div>';

	if ( ! $slug_const ) {
		echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save</button></div>';
		echo '</form>';
	}

	// Emergency unlock docs (out-of-form, no submission)
	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">Emergency unlock</p>';
	echo '<p>If you ever lock yourself out (forgot the slug, can\'t reach the login form), add either of these constants to <code>wp-config.php</code> via SSH or your host\'s file manager:</p>';
	echo '<pre>// Option 1: pin the slug. Reachable at /&lt;slug-here&gt;.
define( \'SN_LOGIN_SLUG\', \'your-fallback-slug\' );

// Option 2: disable the module entirely. Restores /wp-login.php.
define( \'SN_LOGIN_BYPASS\', true );</pre>';
	echo '<p>The constants take priority over the setting and persist across plugin updates. Remove them once you\'ve regained access.</p>';
	echo '</div>';
}
