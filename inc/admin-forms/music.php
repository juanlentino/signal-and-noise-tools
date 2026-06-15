<?php
/**
 * Signal & Noise — Monitoring → Music admin sub-tab (Music Identity).
 *
 * Render-only (POST is handled in sn_handle_music_save / sn_handle_music_sync on
 * admin_init, PRG). Surfaces the discography sync: a status panel (last run,
 * release count, last error) read from the store, the Spotify credentials
 * (masked, constant-lockable — mirrors the Plausible tab), the Muso profile id
 * (the only Muso input — there is NO Muso credential; the data source is the
 * unauthenticated public endpoint), and a "Sync now" button.
 *
 * Native WP styling only (brand vocabulary belongs to the front end).
 *
 * @package SignalNoiseTools
 * @since v4.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obscure a stored secret for display: "••••" + last 4 chars (or '' when unset).
 *
 * @param string $value Stored credential.
 * @return string Masked value for the field, or ''.
 */
function sn_music_mask( $value ) {
	// Delegates to the shared length-aware mask (v4.14.2) so a short secret
	// never renders in cleartext. Kept as a named wrapper for existing callers.
	return sn_mask_secret( $value );
}

/**
 * Render the Monitoring → Music sub-tab.
 *
 * @return void
 */
function sn_admin_render_music_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$store      = sn_discography_get();
	$synced     = (int) $store['last_synced'];
	$count      = (int) $store['count'];
	$last_error = (string) $store['last_error'];

	$id_const      = defined( 'SN_SPOTIFY_CLIENT_ID' ) && SN_SPOTIFY_CLIENT_ID;
	$secret_const  = defined( 'SN_SPOTIFY_CLIENT_SECRET' ) && SN_SPOTIFY_CLIENT_SECRET;
	$profile_const = defined( 'SN_MUSO_PROFILE_ID' ) && SN_MUSO_PROFILE_ID;
	$id_opt        = (string) get_option( SN_SPOTIFY_ID_OPT, '' );
	$secret_opt    = (string) get_option( SN_SPOTIFY_SECRET_OPT, '' );
	$spotify_on    = (bool) sn_spotify_config();
	$profile_id    = sn_muso_profile_id();
	$featured      = function_exists( 'sn_music_featured_get' ) ? sn_music_featured_get() : array();
	$featured_url  = ! empty( $featured['open_url'] ) ? (string) $featured['open_url'] : '';

	echo '<p class="sn-prose">A zero-touch on-site discography. A daily WP-Cron job mirrors Juan&rsquo;s verified <strong>Muso.AI</strong> producer credits (no credential &mdash; the public credits endpoint), enriches each release with <strong>Spotify</strong> album media, caches it, emits <code>MusicAlbum</code> schema, and renders the <code>/music</code> timeline. Pages serve entirely from the cache &mdash; no request-time API calls.</p>';

	// ── STATUS BOX ──
	if ( $count > 0 && '' === $last_error ) {
		echo '<div class="sn-status-box">';
		echo '<div><p class="sn-status-box-title">Synced</p>';
		echo '<p class="sn-status-box-body">' . (int) $count . ' release(s) cached. The /music timeline + MusicAlbum schema are live.</p></div>';
		echo '<span class="sn-pill sn-pill--ok">Synced</span>';
		echo '</div>';
	} elseif ( $count > 0 && '' !== $last_error ) {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div><p class="sn-status-box-title">Showing last-good data</p>';
		echo '<p class="sn-status-box-body">' . (int) $count . ' release(s) still cached, but the last sync failed. The page never blanks &mdash; fix the error below and re-sync.</p></div>';
		echo '<span class="sn-pill sn-pill--warn">Stale</span>';
		echo '</div>';
	} elseif ( '' !== $last_error ) {
		echo '<div class="sn-status-box sn-status-box--err">';
		echo '<div><p class="sn-status-box-title">Sync failed &mdash; no data yet</p>';
		echo '<p class="sn-status-box-body">The first sync hasn&rsquo;t succeeded. The /music page falls back to its static content.</p></div>';
		echo '<span class="sn-pill sn-pill--err">Failed</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div><p class="sn-status-box-title">Not yet synced</p>';
		echo '<p class="sn-status-box-body">Hit &ldquo;Sync now&rdquo; to populate the discography. The daily cron will keep it fresh after that.</p></div>';
		echo '<span class="sn-pill sn-pill--warn">Pending</span>';
		echo '</div>';
	}

	// ── STATUS DETAILS ──
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Status</h2>';
	echo '<table class="form-table sn-status-table sn-status-table--full"><tbody>';
	echo '<tr><th>Last sync</th><td>' . ( $synced > 0 ? esc_html( human_time_diff( $synced, time() ) ) . ' ago' : '<em>never</em>' ) . '</td></tr>';
	echo '<tr><th>Releases cached</th><td>' . (int) $count . '</td></tr>';
	echo '<tr><th>Data source</th><td>Muso.AI profile <code>' . esc_html( $profile_id ) . '</code> <span class="sn-pill sn-pill--ok">no credential</span></td></tr>';
	echo '<tr><th>Spotify media</th><td>' . ( $spotify_on
		? '<span class="sn-pill sn-pill--ok">Configured</span> &mdash; album embeds + artwork enrichment active'
		: '<span class="sn-pill sn-pill--warn">Not configured</span> &mdash; Muso artwork only, no embeds (optional)' ) . '</td></tr>';
	if ( '' !== $last_error ) {
		echo '<tr><th>Last error</th><td><code>' . esc_html( $last_error ) . '</code></td></tr>';
	}
	echo '</tbody></table>';
	echo '</div>';

	// ── CREDENTIALS FORM ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="tab" value="monitoring">';
	echo '<input type="hidden" name="sub" value="music">';

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Spotify (optional)</h2>';
	echo '<p class="sn-fieldset-intro">Client-credentials app from <em>developer.spotify.com</em>. Resolves each release to its Spotify album for the lazy click-to-play embed. Stored non-autoloaded; lockable via <code>SN_SPOTIFY_CLIENT_ID</code> / <code>SN_SPOTIFY_CLIENT_SECRET</code> in <code>wp-config.php</code>. Leave the masked value to keep it; type <code>clear</code> to remove.</p>';

	sn_music_render_cred_field( 'sn_spotify_id', 'Client ID', $id_opt, $id_const, 'SN_SPOTIFY_CLIENT_ID' );
	sn_music_render_cred_field( 'sn_spotify_secret', 'Client Secret', $secret_opt, $secret_const, 'SN_SPOTIFY_CLIENT_SECRET' );
	echo '</div>';

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Muso.AI profile</h2>';
	echo '<p class="sn-fieldset-intro">The public profile whose credits are mirrored. Defaults to Juan&rsquo;s. No credential &mdash; it&rsquo;s the same id in the public credits URL. Lockable via <code>SN_MUSO_PROFILE_ID</code>.</p>';
	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="sn_muso_profile">Profile ID</label>';
	if ( $profile_const ) {
		echo '<input type="text" value="' . esc_attr( $profile_id ) . '" disabled class="sn-mono">';
		echo '<p class="sn-field-helper"><strong>Locked</strong> by <code>SN_MUSO_PROFILE_ID</code>.</p>';
	} else {
		echo '<input type="text" id="sn_muso_profile" name="sn_muso_profile" value="' . esc_attr( $profile_id ) . '" class="sn-mono">';
		echo '<p class="sn-field-helper">Type <code>clear</code> to revert to the default profile.</p>';
	}
	echo '</div>';
	echo '</div>'; // .sn-fieldset (Muso profile)

	// ── Featured release (v4.14.0): the one "press play" player on /music. ──
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Featured release</h2>';
	echo '<p class="sn-fieldset-intro">The single &ldquo;press play&rdquo; player at the top of <code>/music</code>. Paste any Spotify track, album, or playlist link; type <code>clear</code> to remove it. <strong>Leave it empty and <code>/music</code> auto-features your newest release</strong> &mdash; so the page is never headerless. Renders through the theme&rsquo;s <code>[sn_music_featured]</code> shortcode.</p>';
	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="sn_music_featured">Spotify URL</label>';
	echo '<input type="text" id="sn_music_featured" name="sn_music_featured" value="' . esc_attr( $featured_url ) . '" placeholder="https://open.spotify.com/album/&hellip;" class="sn-mono" autocomplete="off">';
	echo '<p class="sn-field-helper">' . ( $featured ? 'Currently featuring a <strong>' . esc_html( $featured['type'] ) . '</strong>.' : 'No manual pick &mdash; <code>/music</code> auto-features your newest release.' ) . '</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="music_save" class="button button-primary">Save settings</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset (Featured)
	echo '</form>';

	// ── SYNC NOW ──
	echo '<form method="post" class="sn-card sn-card--narrow">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="tab" value="monitoring">';
	echo '<input type="hidden" name="sub" value="music">';
	echo '<strong>Sync now</strong>';
	echo '<p class="sn-helper">Runs the full Muso &rarr; Spotify &rarr; store pass immediately. Keeps the last-good discography if a source fails.</p>';
	echo '<button type="submit" name="sn_action" value="music_sync" class="button">Sync now</button>';
	echo '</form>';
}

/**
 * Render one masked, constant-lockable credential field (Client ID / Secret).
 *
 * @param string $name     Field/POST name.
 * @param string $label    Visible label.
 * @param string $opt      Current stored value.
 * @param bool   $locked   Whether a wp-config constant locks this field.
 * @param string $const    The constant name (for the helper copy).
 * @return void
 */
function sn_music_render_cred_field( $name, $label, $opt, $locked, $const ) {
	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
	if ( $locked ) {
		echo '<input type="text" value="••••" disabled class="sn-mono">';
		echo '<p class="sn-field-helper"><strong>Locked</strong> by <code>' . esc_html( $const ) . '</code>.</p>';
	} else {
		echo '<input type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( sn_music_mask( $opt ) ) . '" class="sn-mono" autocomplete="off">';
	}
	echo '</div>';
}
