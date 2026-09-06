<?php
/**
 * S&N Dashboard — Connections → Discography: the parts the leaf paints.
 *
 * The intro, the credential/profile/featured fields with their helpers, the
 * rail's Status facts and the "Sync now" form, each a kit counterpart of what
 * inc/admin-forms/music.php prints. Required by connections-music.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A field helper line (the classic `.sn-field-helper`), inline code kept.
 *
 * @param string $inner Painted HTML.
 * @return string
 */
function music_helper_html( $inner ) {
	return '<p class="snt-hint">' . $inner . '</p>';
}

/**
 * The leaf's intro paragraph.
 *
 * @return string
 */
function music_intro_html() {
	return '<p class="snt-prose">'
		. \snt_kit_esc( __( 'A zero-touch on-site discography. A daily WP-Cron job mirrors Juan’s verified', 'signal-and-noise-tools' ) ) . ' <strong>Muso.AI</strong> '
		. \snt_kit_esc( __( 'producer credits (no credential — the public credits endpoint), enriches each release with', 'signal-and-noise-tools' ) ) . ' <strong>Spotify</strong> '
		. \snt_kit_esc( __( 'album media, caches it, emits', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'MusicAlbum', false ) . ' '
		. \snt_kit_esc( __( 'schema, and renders the', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/music', false ) . ' '
		. \snt_kit_esc( __( 'timeline. Pages serve entirely from the cache — no request-time API calls.', 'signal-and-noise-tools' ) )
		. '</p>';
}

/**
 * A field a wp-config constant locks: the value shown disabled with NO name
 * (the classic input carries none, so the handler never sees it) and the lock
 * explained. `<os-field-row label>` around `<os-text-field type value disabled>`
 * (kit-help "Field row": label, default slot = the control; "Text field":
 * type, value, disabled) — through snt_kit_tag() because snt_kit_field()
 * always names its control.
 *
 * @param string $label Label.
 * @param string $value Shown value.
 * @param string $const The locking constant.
 * @return string
 */
function music_locked_field( $label, $value, $const ) {
	return \snt_kit_tag(
		'os-field-row',
		array( 'label' => (string) $label ),
		\snt_kit_tag( 'os-text-field', array( 'type' => 'text', 'value' => (string) $value, 'disabled' => true ) )
	) . music_helper_html( '<strong>' . \snt_kit_esc( __( 'Locked', 'signal-and-noise-tools' ) ) . '</strong> ' . \snt_kit_esc( __( 'by', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( $const, false ) . '.' );
}

/**
 * One masked, constant-lockable credential (Client ID / Secret), as
 * sn_music_render_cred_field(): locked → "••••" disabled; else the stored
 * value through the shared mask (never raw).
 *
 * @param string $name   Field name.
 * @param string $label  Label.
 * @param string $opt    Stored value.
 * @param bool   $locked Whether a constant locks it.
 * @param string $const  The constant's name.
 * @return string
 */
function music_cred_field( $name, $label, $opt, $locked, $const ) {
	if ( $locked ) {
		return music_locked_field( $label, '••••', $const );
	}
	$masked = function_exists( 'sn_mask_secret' ) ? (string) sn_mask_secret( $opt ) : '';
	return \snt_kit_field( 'text', $name, $label, $masked, array( 'autocomplete' => 'off' ) );
}

/**
 * Spotify (optional): intro + the two credentials.
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_spotify_section( array $s ) {
	$intro = '<p class="snt-prose">'
		. \snt_kit_esc( __( 'Client-credentials app from', 'signal-and-noise-tools' ) ) . ' <em>developer.spotify.com</em>. '
		. \snt_kit_esc( __( 'Resolves each release to its Spotify album for the lazy click-to-play embed. Stored non-autoloaded; lockable via', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'SN_SPOTIFY_CLIENT_ID', false ) . ' / ' . \snt_kit_code( 'SN_SPOTIFY_CLIENT_SECRET', false ) . ' '
		. \snt_kit_esc( __( 'in', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'wp-config.php', false ) . '. '
		. \snt_kit_esc( __( 'Leave the masked value to keep it; type', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'clear', false ) . ' '
		. \snt_kit_esc( __( 'to remove.', 'signal-and-noise-tools' ) )
		. '</p>';
	return \snt_kit_section(
		__( 'Spotify (optional)', 'signal-and-noise-tools' ),
		$intro
		. music_cred_field( 'sn_spotify_id', __( 'Client ID', 'signal-and-noise-tools' ), $s['id_opt'], $s['id_const'], 'SN_SPOTIFY_CLIENT_ID' )
		. music_cred_field( 'sn_spotify_secret', __( 'Client Secret', 'signal-and-noise-tools' ), $s['secret_opt'], $s['secret_const'], 'SN_SPOTIFY_CLIENT_SECRET' )
	);
}

/**
 * Muso.AI profile: intro + the profile id (locked, or editable with its helper).
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_muso_section( array $s ) {
	$intro = '<p class="snt-prose">'
		. \snt_kit_esc( __( 'The public profile whose credits are mirrored. Defaults to Juan’s. No credential — it’s the same id in the public credits URL. Lockable via', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'SN_MUSO_PROFILE_ID', false ) . '.'
		. '</p>';
	$field = $s['profile_const']
		? music_locked_field( __( 'Profile ID', 'signal-and-noise-tools' ), $s['profile_id'], 'SN_MUSO_PROFILE_ID' )
		: \snt_kit_field( 'text', 'sn_muso_profile', __( 'Profile ID', 'signal-and-noise-tools' ), $s['profile_id'] )
			. music_helper_html( \snt_kit_esc( __( 'Type', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'clear', false ) . ' ' . \snt_kit_esc( __( 'to revert to the default profile.', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Muso.AI profile', 'signal-and-noise-tools' ), $intro . $field );
}

/**
 * Featured release: intro + the Spotify URL field with its helper.
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_featured_section( array $s ) {
	$intro = '<p class="snt-prose">'
		. \snt_kit_esc( __( 'The single “press play” player at the top of', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/music', false ) . '. '
		. \snt_kit_esc( __( 'Paste any Spotify track, album, or playlist link; type', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'clear', false ) . ' '
		. \snt_kit_esc( __( 'to remove it.', 'signal-and-noise-tools' ) ) . ' '
		. '<strong>' . \snt_kit_esc( __( 'Leave it empty and', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/music', false ) . ' ' . \snt_kit_esc( __( 'auto-features your newest release', 'signal-and-noise-tools' ) ) . '</strong> — '
		. \snt_kit_esc( __( 'so the page is never headerless. Renders through the theme’s', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '[sn_music_featured]', false ) . ' '
		. \snt_kit_esc( __( 'shortcode.', 'signal-and-noise-tools' ) )
		. '</p>';
	$helper = ! empty( $s['featured'] )
		? \snt_kit_esc( __( 'Currently featuring a', 'signal-and-noise-tools' ) ) . ' <strong>' . \snt_kit_esc( (string) ( $s['featured']['type'] ?? '' ) ) . '</strong>.'
		: \snt_kit_esc( __( 'No manual pick —', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/music', false ) . ' ' . \snt_kit_esc( __( 'auto-features your newest release.', 'signal-and-noise-tools' ) );
	return \snt_kit_section(
		__( 'Featured release', 'signal-and-noise-tools' ),
		$intro
		. \snt_kit_field( 'text', 'sn_music_featured', __( 'Spotify URL', 'signal-and-noise-tools' ), $s['featured_url'], array( 'placeholder' => 'https://open.spotify.com/album/…', 'autocomplete' => 'off' ) )
		. music_helper_html( $helper )
	);
}

/**
 * The rail's Status facts: last sync, releases cached, data source, Spotify
 * media, and the last error when there is one.
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_rail_status_html( array $s ) {
	$rows = array(
		array(
			'label' => __( 'Last sync', 'signal-and-noise-tools' ),
			'html'  => true,
			'value' => $s['synced'] > 0
				? \snt_kit_esc( human_time_diff( $s['synced'], time() ) ) . ' ' . \snt_kit_esc( __( 'ago', 'signal-and-noise-tools' ) )
				: '<em>' . \snt_kit_esc( __( 'never', 'signal-and-noise-tools' ) ) . '</em>',
		),
		array( 'label' => __( 'Releases cached', 'signal-and-noise-tools' ), 'value' => (string) (int) $s['count'] ),
		array(
			'label' => __( 'Data source', 'signal-and-noise-tools' ),
			'html'  => true,
			'value' => \snt_kit_esc( __( 'Muso.AI profile', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( $s['profile_id'], false ) . ' ' . \snt_kit_badge( 'ok', __( 'no credential', 'signal-and-noise-tools' ) ),
		),
		array(
			'label' => __( 'Spotify media', 'signal-and-noise-tools' ),
			'html'  => true,
			'value' => $s['spotify_on']
				? \snt_kit_badge( 'ok', __( 'Configured', 'signal-and-noise-tools' ) ) . ' — ' . \snt_kit_esc( __( 'album embeds + artwork enrichment active', 'signal-and-noise-tools' ) )
				: \snt_kit_badge( 'warn', __( 'Not configured', 'signal-and-noise-tools' ) ) . ' — ' . \snt_kit_esc( __( 'Muso artwork only, no embeds (optional)', 'signal-and-noise-tools' ) ),
		),
	);
	if ( '' !== $s['last_error'] ) {
		$rows[] = array( 'label' => __( 'Last error', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_code( $s['last_error'], false ) );
	}
	return \snt_kit_section( __( 'Status', 'signal-and-noise-tools' ), \snt_kit_kv( $rows ) );
}

/**
 * Sync now: the classic card's title as the section heading, its helper as
 * the description, the one-button form posting `music_sync`.
 *
 * @return string
 */
function music_sync_html() {
	return \snt_kit_section(
		__( 'Sync now', 'signal-and-noise-tools' ),
		\snt_kit_form( 'music_sync', '', array( 'submit' => __( 'Sync now', 'signal-and-noise-tools' ), 'hidden' => music_hidden() ) ),
		__( 'Runs the full Muso → Spotify → store pass immediately. Keeps the last-good discography if a source fails.', 'signal-and-noise-tools' )
	);
}
