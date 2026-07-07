<?php
/**
 * Signal & Noise Tools — Redirects admin tab (Connections → Redirects, v8.10.0).
 *
 * Render + form handlers for the redirect manager (B1) and the 404 capture log
 * (B2). Actions (redirect_add / redirect_update / redirect_delete /
 * redirect_404_delete / redirect_404_clear) route through the shared
 * sn_handle_admin_post dispatcher (inc/admin-post-handler.php) — same nonce +
 * PRG flow as every other SN tab. Handlers live here (not admin-post-actions.php)
 * to keep the subsystem cohesive, exactly as the schedule ops do.
 *
 * @package SignalNoiseTools
 * @since 8.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_redirects_tab', 'sn_redirects_render_admin_tab' );

/**
 * Render the Redirects tab: manager in the main column, 404 log in the rail.
 */
function sn_redirects_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$redirects = sn_redirects_all();
	$log       = sn_404_log_all();

	echo '<p class="sn-prose">Send old or broken URLs to a new destination with a 301 (permanent) or 302 (temporary) redirect. Targets can be an on-site path (<code>/new-page</code>) or a full external URL (<code>https://…</code>). The <strong>404 log</strong> in the sidebar surfaces paths visitors actually hit that don&rsquo;t exist — one click turns any of them into a redirect.</p>';

	sn_admin_shell_open();

	// ── MAIN: existing redirects, newest first ──
	foreach ( array_reverse( $redirects, true ) as $source => $r ) {
		$status = (int) ( $r['status'] ?? 301 );
		echo '<form method="post">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="source" value="' . esc_attr( $source ) . '">';
		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h sn-mono">' . esc_html( $source ) . '</h2>';
		echo '<p class="sn-fieldset-intro">Added ' . esc_html( wp_date( 'Y-m-d', (int) ( $r['created_at'] ?? 0 ) ) ) . '</p>';

		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label" for="rd_to_' . esc_attr( md5( $source ) ) . '">Redirects to</label>';
		echo '<input type="text" id="rd_to_' . esc_attr( md5( $source ) ) . '" name="target" value="' . esc_attr( (string) ( $r['to'] ?? '' ) ) . '" class="sn-mono">';
		echo '</div>';

		echo '<div class="sn-field">';
		echo '<label class="sn-field-label">Type</label>';
		echo sn_redirects_status_select_html( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper builds escaped markup (static strings + selected()).
		echo '</div>';

		echo '<div class="sn-fieldset-actions">';
		echo '<button type="submit" name="sn_action" value="redirect_update" class="button button-primary">Save changes</button>';
		echo ' <button type="submit" name="sn_action" value="redirect_delete" class="button button-link-delete" data-snt-confirm="' . esc_attr__( 'This redirect will stop working immediately.', 'signal-and-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Delete this redirect?', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Delete', 'signal-and-noise-tools' ) . '" data-snt-confirm-danger="1">Delete</button>';
		echo '</div>';
		echo '</div>'; // .sn-fieldset
		echo '</form>';
	}

	// ── MAIN: add new ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset sn-fieldset--new">';
	echo '<h2 class="sn-fieldset-h">Add a redirect</h2>';
	echo '<div class="sn-field sn-field-w-md">';
	echo '<label class="sn-field-label" for="rd_new_source">From (path on this site)</label>';
	echo '<input type="text" id="rd_new_source" name="source" placeholder="/old-page" class="sn-mono">';
	echo '<p class="sn-field-helper">The path to match, e.g. <code>/old-page</code>. Trailing slash and query string are ignored.</p>';
	echo '</div>';
	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="rd_new_target">To (path or full URL)</label>';
	echo '<input type="text" id="rd_new_target" name="target" placeholder="/new-page  or  https://example.com/page" class="sn-mono">';
	echo '</div>';
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Type</label>';
	echo sn_redirects_status_select_html( 301 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper builds escaped markup (static strings + selected()).
	echo '</div>';
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="redirect_add" class="button button-primary">Add redirect</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';

	// ── RAIL: 404 log ──
	sn_admin_shell_rail( 'Broken links (404s)' );
	$total = count( $log );
	if ( 0 === $total ) {
		echo '<div class="sn-status-box">';
		echo '<div><p class="sn-status-box-title">No 404s logged</p><p class="sn-status-box-body">Front-end requests that hit a missing page will show up here (bot and probe noise is filtered out).</p></div>';
		echo '<span class="sn-pill sn-pill--ok">Clean</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div><p class="sn-status-box-title">' . esc_html( $total ) . ' broken path' . ( 1 === $total ? '' : 's' ) . '</p><p class="sn-status-box-body">Add a target below to redirect it — doing so also clears it from this list.</p></div>';
		echo '<span class="sn-pill sn-pill--warn">Attention</span>';
		echo '</div>';

		// Busiest first.
		uasort( $log, function ( $a, $b ) { return (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 ); } );
		foreach ( $log as $path => $e ) {
			echo '<form method="post"><div class="sn-fieldset">';
			wp_nonce_field( 'sn_theme_options_nonce' );
			echo '<input type="hidden" name="source" value="' . esc_attr( $path ) . '">';
			echo '<h2 class="sn-fieldset-h sn-mono">' . esc_html( $path ) . '</h2>';
			echo '<p class="sn-fieldset-intro">' . esc_html( (int) ( $e['count'] ?? 0 ) ) . ' hit' . ( 1 === (int) ( $e['count'] ?? 0 ) ? '' : 's' ) . ' · last ' . esc_html( wp_date( 'Y-m-d', (int) ( $e['last_seen'] ?? 0 ) ) );
			if ( ! empty( $e['referer'] ) ) {
				echo ' · from <code>' . esc_html( (string) wp_parse_url( (string) $e['referer'], PHP_URL_HOST ) ) . '</code>';
			}
			echo '</p>';
			echo '<div class="sn-field sn-field-w-lg">';
			echo '<label class="sn-field-label">Redirect to</label>';
			echo '<input type="text" name="target" placeholder="/new-page  or  https://…" class="sn-mono">';
			echo '</div>';
			echo '<div class="sn-fieldset-actions">';
			echo '<button type="submit" name="sn_action" value="redirect_add" class="button button-primary">Create redirect</button>';
			echo ' <button type="submit" name="sn_action" value="redirect_404_delete" class="button button-link-delete">Dismiss</button>';
			echo '</div>';
			echo '</div></form>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<button type="submit" name="sn_action" value="redirect_404_clear" class="button" data-snt-confirm="' . esc_attr__( 'Clear the entire 404 log?', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Clear', 'signal-and-noise-tools' ) . '">Clear 404 log</button>';
		echo '</form>';
	}

	sn_admin_shell_close();
}

/**
 * The 301/302 <select> markup, with $current pre-selected.
 *
 * @param int $current Currently selected status.
 * @return string
 */
function sn_redirects_status_select_html( $current ) {
	$current = ( 302 === (int) $current ) ? 302 : 301;
	$out     = '<select name="status">';
	$out    .= '<option value="301"' . selected( 301, $current, false ) . '>301 — Permanent</option>';
	$out    .= '<option value="302"' . selected( 302, $current, false ) . '>302 — Temporary</option>';
	$out    .= '</select>';
	return $out;
}

// ── Form handlers (dispatched by inc/admin-post-handler.php) ──

/**
 * Add / upsert a redirect. On success also prunes the source from the 404 log,
 * so converting a broken link removes it from the "needs attention" list.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_redirect_add( $post ) {
	$source = isset( $post['source'] ) ? sanitize_text_field( wp_unslash( $post['source'] ) ) : '';
	// Trim before classifying so incidental whitespace (" https://…") can't route an
	// external URL down the internal-path branch and skip esc_url_raw's scheme check.
	$raw    = isset( $post['target'] ) ? trim( (string) wp_unslash( $post['target'] ) ) : '';
	// Absolute http(s) targets go through esc_url_raw (they can leave the site);
	// on-site paths through sanitize_text_field (esc_url_raw would mangle a bare
	// path). Non-http targets are neutralized at resolve time — sn_redirect_target
	// prefixes them with home_url(), so a stray "javascript:" or "//evil" can only
	// become a same-host path, never an off-site or scheme redirect.
	$target = preg_match( '#^https?://#i', $raw )
		? esc_url_raw( $raw, array( 'http', 'https' ) )
		: sanitize_text_field( $raw );
	$status = isset( $post['status'] ) ? (int) $post['status'] : 301;
	if ( sn_redirect_save( $source, $target, $status ) ) {
		sn_404_log_delete( $source );
		return 'redirect_added';
	}
	return 'redirect_invalid';
}

/**
 * Update an existing redirect (upsert on the same source).
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_redirect_update( $post ) {
	return 'redirect_added' === sn_handle_redirect_add( $post ) ? 'redirect_updated' : 'redirect_invalid';
}

/**
 * Delete a redirect.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_redirect_delete( $post ) {
	$source = isset( $post['source'] ) ? sanitize_text_field( wp_unslash( $post['source'] ) ) : '';
	sn_redirect_delete( $source );
	return 'redirect_deleted';
}

/**
 * Dismiss a single 404-log entry.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_redirect_404_delete( $post ) {
	$source = isset( $post['source'] ) ? sanitize_text_field( wp_unslash( $post['source'] ) ) : '';
	sn_404_log_delete( $source );
	return 'redirect_404_deleted';
}

/**
 * Clear the whole 404 log.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_redirect_404_clear( $post ) {
	sn_404_log_clear();
	return 'redirect_404_cleared';
}
