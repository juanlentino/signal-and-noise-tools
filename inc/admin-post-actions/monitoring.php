<?php
/**
 * Signal & Noise — admin POST handlers: uptime/spend monitoring and performance budgets.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: monitoring_save, perf_save
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save the monitoring CREDENTIALS from the Webhooks tab.
 *
 * v12.19.0: the push heartbeat this action was built for (v4.9.0, T4) is gone,
 * and with it the URL field, the enabled toggle and the cron reconcile this
 * docblock used to describe. What remains is credential handling, delegated to
 * the owning modules — inc/uptime-status.php (Better Stack token) and
 * inc/spend-watch.php (GitHub billing, Anthropic admin) — each on its own
 * masked/'clear' contract.
 */
function sn_handle_monitoring_save( $post ) {
	// v8.2.0: Better Stack API token (status panel). Handled FIRST and
	// independently of the push-URL https gate so a rejected URL never eats
	// a freshly pasted token. Cloudflare-token contract: obscured round-trip
	// and empty field keep the stored value; only the literal 'clear'
	// removes it. Constant-locked installs never reach this (the field is
	// disabled and unnamed). Snapshot transient dropped on change so the
	// panel never serves a stale token's data.
	if ( ! defined( 'SN_BETTERSTACK_API_TOKEN' ) || ! SN_BETTERSTACK_API_TOKEN ) {
		$token_opt = defined( 'SN_UPTIME_STATUS_TOKEN_OPT' ) ? SN_UPTIME_STATUS_TOKEN_OPT : 'sn_betterstack_api_token';
		$new_token = isset( $post['sn_betterstack_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_betterstack_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			delete_option( $token_opt );
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( 'sn_uptime_status_snapshot' );
				delete_transient( 'sn_uptime_availability' ); // v8.3.0 map
			}
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
			update_option( $token_opt, $new_token, false ); // not autoloaded
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( 'sn_uptime_status_snapshot' );
				delete_transient( 'sn_uptime_availability' ); // v8.3.0 map
			}
		}
	}

	// v10.75.0: the Spend-watch credentials ride the same monitoring form,
	// each on the identical masked/'clear' contract (module owns the logic).
	if ( function_exists( 'sn_spend_watch_handle_save' ) ) {
		sn_spend_watch_handle_save( $post );
	}

	// v12.19.0: the heartbeat fields are gone; this action now saves only the
	// monitoring CREDENTIALS handled by the module hooks above.
	return 'monitoring_saved';
}

/**
 * v4.10.0 (T6): save the Speculation Rules toggle from the Site → Performance
 * sub-tab. Writes the boolean through sn_setting_update('perf.speculative_loading',
 * …); the wp_speculation_rules_configuration filter reads it on the next page load.
 */
function sn_handle_perf_save( $post ) {
	$enabled = ! empty( $post['speculative_loading'] );
	sn_setting_update( 'perf.speculative_loading', $enabled );
	return 'perf_saved';
}
