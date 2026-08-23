<?php
/**
 * Signal & Noise — admin POST handlers: music service credentials and sync.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: music_save, music_sync
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v4.13.0 (Music Identity, T6): save ONE masked, constant-lockable credential.
 *
 * Shared by the Spotify client id + secret. Mirrors the cf_save per-field
 * pattern (locked fields skip; 'clear' deletes) BUT fixes the masked-skip check:
 * the obscured value is "••••" + last 4 chars, so the placeholder is detected
 * with 0 === strpos($v, '••••'), NOT substr($v, 0, 4) (a bullet is 3 bytes, so
 * substr cuts mid-character and the comparison never matches — which would
 * persist the literal placeholder). Returns the running $changed flag, OR'd with
 * whether THIS field actually changed (update_option returns false when the
 * value is identical, so an unedited save reports music_unchanged).
 *
 * @param array  $post    Raw $_POST.
 * @param string $field   POST field name.
 * @param string $opt     Option key.
 * @param string $const   wp-config constant name that locks this field.
 * @param bool   $changed Running changed flag.
 * @return bool Updated changed flag.
 */
function sn_music_save_cred( $post, $field, $opt, $const, $changed ) {
	if ( defined( $const ) && constant( $const ) ) {
		return $changed; // locked by wp-config — admin edits are ignored.
	}
	$value = isset( $post[ $field ] ) ? sanitize_text_field( wp_unslash( $post[ $field ] ) ) : '';
	if ( 'clear' === $value ) {
		delete_option( $opt );
		return true;
	}
	// Skip the masked placeholder (leaves the stored value untouched). A real
	// pasted value never begins with the bullet run.
	if ( '' !== $value && 0 !== strpos( $value, '••••' ) && update_option( $opt, $value, false ) ) {
		return true;
	}
	return $changed;
}

/**
 * v4.13.0 (Music Identity, T6): save the Connections → Discography credentials.
 *
 * Spotify client id + secret (masked, non-autoloaded, constant-lockable via
 * SN_SPOTIFY_CLIENT_ID / SN_SPOTIFY_CLIENT_SECRET) + the Muso profile id (not
 * secret — it's in the public Muso URL — but still constant-lockable via
 * SN_MUSO_PROFILE_ID). No Muso credential exists: the data source is the
 * unauthenticated public endpoint. Drops the cached Spotify token on any change
 * so the next sync re-authenticates.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_music_save( $post ) {
	// v4.14.0: featured-release URL — validate BEFORE any write so a bad paste
	// errors cleanly instead of partially saving the other fields.
	$raw_featured    = isset( $post['sn_music_featured'] ) ? trim( (string) wp_unslash( $post['sn_music_featured'] ) ) : '';
	$featured_parsed = null;
	if ( '' !== $raw_featured && 'clear' !== $raw_featured ) {
		$featured_parsed = function_exists( 'sn_music_featured_parse' ) ? sn_music_featured_parse( $raw_featured ) : null;
		if ( ! $featured_parsed ) {
			return 'music_featured_invalid';
		}
	}

	$changed = false;
	$changed = sn_music_save_cred( $post, 'sn_spotify_id', SN_SPOTIFY_ID_OPT, 'SN_SPOTIFY_CLIENT_ID', $changed );
	$changed = sn_music_save_cred( $post, 'sn_spotify_secret', SN_SPOTIFY_SECRET_OPT, 'SN_SPOTIFY_CLIENT_SECRET', $changed );

	// Muso profile id — plain (no mask), constant-lockable.
	if ( ! ( defined( 'SN_MUSO_PROFILE_ID' ) && SN_MUSO_PROFILE_ID ) ) {
		$pid = isset( $post['sn_muso_profile'] ) ? sanitize_text_field( wp_unslash( $post['sn_muso_profile'] ) ) : '';
		if ( 'clear' === $pid ) {
			delete_option( SN_MUSO_PROFILE_OPT );
			$changed = true;
		} elseif ( '' !== $pid && update_option( SN_MUSO_PROFILE_OPT, $pid, false ) ) {
			$changed = true;
		}
	}

	// Featured release — apply (validated above).
	if ( defined( 'SN_MUSIC_FEATURED_OPT' ) ) {
		if ( 'clear' === $raw_featured ) {
			delete_option( SN_MUSIC_FEATURED_OPT );
			$changed = true;
		} elseif ( is_array( $featured_parsed ) && update_option( SN_MUSIC_FEATURED_OPT, $featured_parsed, false ) ) {
			$changed = true;
		}
	}

	if ( $changed && function_exists( 'sn_spotify_invalidate_token' ) ) {
		sn_spotify_invalidate_token(); // creds changed → force re-auth next sync.
	}
	return $changed ? 'music_saved' : 'music_unchanged';
}

/**
 * v4.13.0 (Music Identity, T6): run a discography sync on demand ("Sync now").
 * Routes through the central orchestrator; a false return means the source
 * failed and the last-good store was preserved (page never blanks).
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_music_sync( $post ) {
	if ( ! function_exists( 'sn_discography_run_sync' ) ) {
		return 'music_sync_failed';
	}
	return sn_discography_run_sync() ? 'music_synced' : 'music_sync_failed';
}
