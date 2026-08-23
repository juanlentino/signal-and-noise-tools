<?php
/**
 * Signal & Noise — admin POST handlers: overrides, cache purge, full reset, identity and login.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: clear_overrides, purge_caches, full_reset, save_identity,
 * save_login
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_clear_overrides( $post ) {
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	return 'cleared_' . $count;
}

function sn_handle_purge_caches( $post ) {
	// v8.7.0: verified=true routes the theme's CF leg through the blocking variant
	// and writes the per-leg sn_last_purge_report. This is the deliberate, watched
	// manual purge, so the extra second on the CF confirmation is acceptable.
	apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false, 'verified' => true ) );
	return 'purged';
}

function sn_handle_full_reset( $post ) {
	// v4.1.1 (D-07): pass explicit template_overrides=true rather than an
	// empty args array. "Full reset" semantically includes template overrides;
	// being explicit prevents drift if the theme tightens its filter contract.
	// v8.7.0: verified=true (see sn_handle_purge_caches) for the confirmed report.
	$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => true, 'verified' => true ) );
	return 'reset_' . $count;
}

function sn_handle_save_identity( $post ) {
	$saved = sn_settings_save( $post );
	return $saved ? 'identity_saved' : 'identity_unchanged';
}

function sn_handle_save_login( $post ) {
	$slug = isset( $post['login_slug'] ) ? sanitize_title( wp_unslash( $post['login_slug'] ) ) : '';
	if ( ! $slug ) {
		return 'login_empty';
	}
	// v4.2.0 (D-06): write via sn_setting_update() so the per-request static
	// cache is busted — any sn_setting() call later in this request sees the
	// new slug.
	$ok = sn_setting_update( 'login.slug', $slug );
	return $ok ? 'login_saved' : 'login_failed';
}
