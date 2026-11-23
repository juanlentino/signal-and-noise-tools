<?php
/**
 * Signal & Noise: Settings > Accounts and connections, the pane's address
 * and name. Their own file so a screen that only points at the pane (the
 * Cloudflare, Webhooks, Discography, Machine Readers and AI model boxes) can
 * load them without the pane, as settings-analytics-pointers.php does.
 *
 * The address is written ONCE, here: `page=sn-theme-options&tab=settings`,
 * the spelling snt_desktop_admin_url() builds. A link that names another
 * registered page beside `tab=settings` still opens the pane, with the wrong
 * sidebar item lit (tests/settings-tab-links.php).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pane's address, for the lines the old screens keep.
 *
 * @return string
 */
function snt_settings_accounts_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-settings', 'accounts' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=settings&sub=accounts' );
}

/**
 * The pane's name as every pointer says it. Shared by both twins.
 *
 * @return string
 */
function snt_settings_accounts_label() {
	return __( 'Settings › Accounts and connections', 'signal-and-noise-tools' );
}
