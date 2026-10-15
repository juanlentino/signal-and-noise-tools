<?php
/**
 * Signal & Noise — Connections → Discography admin sub-tab (Music Identity).
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
 * Which of the four sync states the store is in, in the words both surfaces
 * say (2026-10-12 audit: the twins pointed at different places, "the status
 * panel on the right" and "the Sync status row below", and neither names a
 * box; they name the button now).
 *
 * @param int    $count      Releases cached.
 * @param string $last_error The last sync's error, '' when it worked.
 * @return array{kind:string,title:string,pill:string,body:string}
 */
function sn_music_status_copy( $count, $last_error ) {
	$count = (int) $count;
	if ( $count > 0 && '' === (string) $last_error ) {
		/* translators: %d: cached releases */
		return array( 'kind' => 'ok', 'title' => __( 'Synced', 'signal-and-noise-tools' ), 'pill' => __( 'Synced', 'signal-and-noise-tools' ), 'body' => sprintf( _n( '%d release cached. The /music timeline + MusicAlbum schema are live.', '%d releases cached. The /music timeline + MusicAlbum schema are live.', $count, 'signal-and-noise-tools' ), $count ) );
	}
	if ( $count > 0 ) {
		/* translators: %d: cached releases */
		return array( 'kind' => 'warn', 'title' => __( 'Showing last-good data', 'signal-and-noise-tools' ), 'pill' => __( 'Stale', 'signal-and-noise-tools' ), 'body' => sprintf( _n( '%d release still cached, but the last sync failed. The page never blanks: check the error, then press Sync now.', '%d releases still cached, but the last sync failed. The page never blanks: check the error, then press Sync now.', $count, 'signal-and-noise-tools' ), $count ) );
	}
	if ( '' !== (string) $last_error ) {
		return array( 'kind' => 'err', 'title' => __( 'Sync failed: no data yet', 'signal-and-noise-tools' ), 'pill' => __( 'Failed', 'signal-and-noise-tools' ), 'body' => __( 'The first sync hasn’t succeeded. The /music page falls back to its static content.', 'signal-and-noise-tools' ) );
	}
	return array( 'kind' => 'warn', 'title' => __( 'Not yet synced', 'signal-and-noise-tools' ), 'pill' => __( 'Pending', 'signal-and-noise-tools' ), 'body' => __( 'Press Sync now to populate the discography. The daily cron will keep it fresh after that.', 'signal-and-noise-tools' ) );
}

/**
 * Render the Connections → Discography sub-tab.
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

	sn_admin_shell_open();

	// ── MAIN: intro + status hero + credential/featured forms ──
	echo '<p class="sn-prose">A zero-touch on-site discography. A daily WP-Cron job mirrors Juan&rsquo;s verified <strong>Muso.AI</strong> producer credits (no credential: the public credits endpoint), enriches each release with <strong>Spotify</strong> album media, caches it, emits <code>MusicAlbum</code> schema, and renders the <code>/music</code> timeline. Pages serve entirely from the cache, with no request-time API calls.</p>';

	// ── STATUS BOX ── (2026-10-12 audit: one set of sentences for both surfaces)
	$status = sn_music_status_copy( $count, $last_error );
	$mod    = 'ok' === $status['kind'] ? '' : ' sn-status-box--' . $status['kind'];
	echo '<div class="sn-status-box' . esc_attr( $mod ) . '">';
	echo '<div><p class="sn-status-box-title">' . esc_html( $status['title'] ) . '</p>';
	echo '<p class="sn-status-box-body">' . esc_html( $status['body'] ) . '</p></div>';
	echo '<span class="sn-pill sn-pill--' . esc_attr( $status['kind'] ) . '">' . esc_html( $status['pill'] ) . '</span>';
	echo '</div>';

	// ── CREDENTIALS FORM ──
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
	wp_nonce_field( 'sn_music_save' );
	echo '<input type="hidden" name="tab" value="content">';
	echo '<input type="hidden" name="sub" value="music">';

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Spotify (optional)</h2>';
	echo '<p class="sn-fieldset-intro">Client-credentials app from <em>developer.spotify.com</em>. Resolves each release to its Spotify album for the lazy click-to-play embed.</p>';

	// 15.3.1: the client id and secret are keyring rows; this form reads them.
	echo '<table class="widefat"><tbody>';
	echo '<tr><th scope="row">Client ID</th><td>' . esc_html( $id_const ? 'wp-config (SN_SPOTIFY_CLIENT_ID)' : ( '' !== $id_opt ? 'Saved' : 'Not set' ) ) . '</td></tr>';
	echo '<tr><th scope="row">Client Secret</th><td>' . esc_html( $secret_const ? 'wp-config (SN_SPOTIFY_CLIENT_SECRET)' : ( '' !== $secret_opt ? 'Saved' : 'Not set' ) ) . '</td></tr>';
	echo '</tbody></table>';
	echo '<p class="sn-field-helper">Set with every other key under <a href="' . esc_url( admin_url( 'admin.php?page=sn-tools&tab=connections&sub=credentials' ) ) . '">Connections › Credentials</a>.</p>';
	echo '</div>';

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Muso.AI profile</h2>';
	echo '<p class="sn-fieldset-intro">The public profile whose credits are mirrored. Defaults to Juan&rsquo;s. No credential: it&rsquo;s the same id in the public credits URL. Lockable via <code>SN_MUSO_PROFILE_ID</code>.</p>';
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
	echo '<p class="sn-fieldset-intro">The single &ldquo;press play&rdquo; player at the top of <code>/music</code>. Paste any Spotify track, album, or playlist link; type <code>clear</code> to remove it. <strong>Leave it empty and <code>/music</code> auto-features your newest release</strong>, so the page is never headerless. Renders through the theme&rsquo;s <code>[sn_music_featured]</code> shortcode.</p>';
	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="sn_music_featured">Spotify URL</label>';
	echo '<input type="text" id="sn_music_featured" name="sn_music_featured" value="' . esc_attr( $featured_url ) . '" placeholder="https://open.spotify.com/album/&hellip;" class="sn-mono" autocomplete="off">';
	echo '<p class="sn-field-helper">' . ( $featured ? 'Currently featuring a <strong>' . esc_html( $featured['type'] ) . '</strong>.' : 'No manual pick: <code>/music</code> auto-features your newest release.' ) . '</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="action" value="sn_music_save" class="button button-primary">Save settings</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset (Featured)
	echo '</form>';

	// ── Release notes: the line under a release's roles (EN + ES). ──
	if ( function_exists( 'sn_discography_notes_render_classic' ) ) {
		sn_discography_notes_render_classic();
	}

	// ── RAIL: sync status detail + Sync-now (v6.42.0) ──
	sn_admin_shell_rail( 'Sync status' );

	// ── STATUS DETAILS ──
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Status</h2>';
	echo '<table class="form-table sn-status-table sn-status-table--full"><tbody>';
	echo '<tr><th>Last sync</th><td>' . ( $synced > 0 ? esc_html( human_time_diff( $synced, time() ) ) . ' ago' : '<em>never</em>' ) . '</td></tr>';
	echo '<tr><th>Releases cached</th><td>' . (int) $count . '</td></tr>';
	echo '<tr><th>Data source</th><td>Muso.AI profile <code>' . esc_html( $profile_id ) . '</code> <span class="sn-pill sn-pill--ok">no credential</span></td></tr>';
	echo '<tr><th>Spotify media</th><td>' . ( $spotify_on
		? '<span class="sn-pill sn-pill--ok">Configured</span>: album embeds + artwork enrichment active'
		: '<span class="sn-pill sn-pill--warn">Not configured</span>: Muso artwork only, no embeds (optional)' ) . '</td></tr>';
	if ( '' !== $last_error ) {
		echo '<tr><th>Last error</th><td><code>' . esc_html( $last_error ) . '</code></td></tr>';
	}
	echo '</tbody></table>';
	echo '</div>';

	// ── SYNC NOW ──
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-card sn-card--narrow">';
	wp_nonce_field( 'sn_music_sync' );
	echo '<input type="hidden" name="tab" value="content">';
	echo '<input type="hidden" name="sub" value="music">';
	echo '<strong>Sync now</strong>';
	echo '<p class="sn-helper">Sync now runs the full Muso &rarr; Spotify &rarr; store pass immediately. It keeps the last-good discography if a source fails.</p>';
	echo '<button type="submit" name="action" value="sn_music_sync" class="button">Sync now</button>';
	echo '</form>';

	sn_admin_shell_close();
}

