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
		. \snt_kit_esc( __( 'producer credits (no credential: the public credits endpoint), enriches each release with', 'signal-and-noise-tools' ) ) . ' <strong>Spotify</strong> '
		. \snt_kit_esc( __( 'album media, caches it, emits', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'MusicAlbum', false ) . ' '
		. \snt_kit_esc( __( 'schema, and renders the', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/music', false ) . ' '
		. \snt_kit_esc( __( 'timeline. Pages serve entirely from the cache, with no request-time API calls.', 'signal-and-noise-tools' ) )
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
 * Spotify (optional): intro + the two credentials, as a Setup fold whose
 * closed summary says whether Spotify media is configured. Never open on
 * its own: Spotify is optional, and the status line already says it.
 *
 * @param array<string,mixed> $s From music_state().
 * @return array<string,mixed> A Setup fold.
 */
function music_spotify_fold( array $s ) {
	// 15.3.1: the client id and secret are keyring rows (Connections ›
	// Credentials); this section reads them and says where they come from.
	$src = static function ( $locked, $const, $value ) {
		if ( $locked ) {
			return sprintf( /* translators: %s: constant name. */ __( 'wp-config (%s)', 'signal-and-noise-tools' ), $const );
		}
		return '' !== (string) $value ? __( 'Saved', 'signal-and-noise-tools' ) : __( 'Not set', 'signal-and-noise-tools' );
	};
	$intro = '<p class="snt-prose">'
		. \snt_kit_esc( __( 'Client-credentials app from', 'signal-and-noise-tools' ) ) . ' <em>developer.spotify.com</em>. '
		. \snt_kit_esc( __( 'Resolves each release to its Spotify album for the lazy click-to-play embed.', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_esc( __( 'Set with every other key under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'connections' ) ) . '.'
		. '</p>';
	return array(
		'title'   => __( 'Spotify (optional)', 'signal-and-noise-tools' ),
		'summary' => $s['spotify_on'] ? __( 'Configured', 'signal-and-noise-tools' ) : __( 'Not configured', 'signal-and-noise-tools' ),
		'body'    => $intro . \snt_kit_kv( array(
			array( 'label' => __( 'Client ID', 'signal-and-noise-tools' ), 'value' => $src( $s['id_const'], 'SN_SPOTIFY_CLIENT_ID', $s['id_opt'] ) ),
			array( 'label' => __( 'Client Secret', 'signal-and-noise-tools' ), 'value' => $src( $s['secret_const'], 'SN_SPOTIFY_CLIENT_SECRET', $s['secret_opt'] ) ),
		) ),
	);
}

/**
 * Muso.AI profile: intro + the profile id (locked, or editable with its
 * helper), as a Setup fold whose closed summary is the profile id and its
 * lock. An empty id leaves no summary, so the fold paints open.
 *
 * @param array<string,mixed> $s From music_state().
 * @return array<string,mixed> A Setup fold.
 */
function music_muso_fold( array $s ) {
	$intro = '<p class="snt-prose">'
		. \snt_kit_esc( __( 'The public profile whose credits are mirrored. Defaults to Juan’s. No credential: it’s the same id in the public credits URL. Lockable via', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'SN_MUSO_PROFILE_ID', false ) . '.'
		. '</p>';
	$field = $s['profile_const']
		? music_locked_field( __( 'Profile ID', 'signal-and-noise-tools' ), $s['profile_id'], 'SN_MUSO_PROFILE_ID' )
		: \snt_kit_field( 'text', 'sn_muso_profile', __( 'Profile ID', 'signal-and-noise-tools' ), $s['profile_id'] )
			. music_helper_html( \snt_kit_esc( __( 'Type', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'clear', false ) . ' ' . \snt_kit_esc( __( 'to revert to the default profile.', 'signal-and-noise-tools' ) ) );
	$summary = (string) $s['profile_id'];
	if ( '' !== $summary && $s['profile_const'] ) {
		/* translators: %s: the profile id. */
		$summary = sprintf( __( '%s, locked by SN_MUSO_PROFILE_ID', 'signal-and-noise-tools' ), $summary );
	}
	return array(
		'title'   => __( 'Muso.AI profile', 'signal-and-noise-tools' ),
		'summary' => $summary,
		'body'    => $intro . ( $s['profile_const'] ? $field : music_save_form( $field ) ),
	);
}

/**
 * The Setup card (2026-10-16, docs/kit-leaf-anatomy.md): the two sources,
 * set once, each a fold whose closed line is its current value.
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_setup_html( array $s ) {
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array( music_muso_fold( $s ), music_spotify_fold( $s ) ),
		__( 'Each line is the source as it stands; open one to change it.', 'signal-and-noise-tools' )
	);
}

/**
 * One settings box's own form (2026-10-12 audit): `music_save` with only
 * that box's field, inside the box, so Save sits with what it saves. The
 * handler leaves a field it is not sent alone.
 *
 * @param string $fields The box's field and helper.
 * @return string
 */
function music_save_form( $fields ) {
	return \snt_kit_form( 'music_save', $fields, array( 'submit' => __( 'Save settings', 'signal-and-noise-tools' ), 'hidden' => music_hidden() ) );
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
		. '<strong>' . \snt_kit_esc( __( 'Leave it empty and', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/music', false ) . ' ' . \snt_kit_esc( __( 'auto-features your newest release', 'signal-and-noise-tools' ) ) . '</strong>, '
		. \snt_kit_esc( __( 'so the page is never headerless. Renders through the theme’s', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '[sn_music_featured]', false ) . ' '
		. \snt_kit_esc( __( 'shortcode.', 'signal-and-noise-tools' ) )
		. '</p>';
	$helper = ! empty( $s['featured'] )
		? \snt_kit_esc( __( 'Currently featuring a', 'signal-and-noise-tools' ) ) . ' <strong>' . \snt_kit_esc( (string) ( $s['featured']['type'] ?? '' ) ) . '</strong>.'
		: \snt_kit_esc( __( 'No manual pick:', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/music', false ) . ' ' . \snt_kit_esc( __( 'auto-features your newest release.', 'signal-and-noise-tools' ) );
	return \snt_kit_section(
		__( 'Featured release', 'signal-and-noise-tools' ),
		$intro . music_save_form(
			\snt_kit_field( 'text', 'sn_music_featured', __( 'Spotify URL', 'signal-and-noise-tools' ), $s['featured_url'], array( 'placeholder' => 'https://open.spotify.com/album/…', 'autocomplete' => 'off' ) )
			. music_helper_html( $helper )
		)
	);
}

/**
 * The Status card: the status line (2026-10-16), the state's notice while
 * it is a problem (its sentence as a hint while synced), then the facts:
 * last sync, releases cached, data source, Spotify media, and the last error
 * when there is one. Sync now and the How this works fold at its foot.
 *
 * @param array<string,mixed> $s    From music_state().
 * @param string              $foot Painted HTML under Sync now ('' for none).
 * @return string
 */
function music_rail_status_html( array $s, $foot = '' ) {
	$rows = array(
		array(
			'label' => __( 'Last sync', 'signal-and-noise-tools' ),
			'html'  => true,
			'value' => $s['synced'] > 0
				? \snt_kit_relative_time( (int) $s['synced'] )
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
				? \snt_kit_badge( 'ok', __( 'Configured', 'signal-and-noise-tools' ) ) . ': ' . \snt_kit_esc( __( 'album embeds + artwork enrichment active', 'signal-and-noise-tools' ) )
				: \snt_kit_badge( 'warn', __( 'Not configured', 'signal-and-noise-tools' ) ) . ': ' . \snt_kit_esc( __( 'Muso artwork only, no embeds (optional)', 'signal-and-noise-tools' ) ),
		),
	);
	if ( '' !== $s['last_error'] ) {
		$rows[] = array( 'label' => __( 'Last error', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_code( $s['last_error'], false ) );
	}
	// The "Sync status" landmark is this box alone, not the row it shares.
	return \snt_kit_section( __( 'Status', 'signal-and-noise-tools' ), music_status_line_html( $s ) . \snt_kit_kv( $rows ) . music_sync_html() . $foot, '', array( 'role' => 'complementary', 'aria-label' => __( 'Sync status', 'signal-and-noise-tools' ) ) );
}

/**
 * The status line: the sync state (its sentence as the chip's title), the
 * releases cached, Spotify media. A problem keeps its notice under the line
 * (status is never folded); a synced leaf says its sentence as a hint.
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_status_line_html( array $s ) {
	$c     = \sn_music_status_copy( (int) $s['count'], (string) $s['last_error'] );
	$chips = array(
		array( 'tone' => (string) $c['kind'], 'label' => (string) $c['pill'], 'title' => (string) $c['body'] ),
		/* translators: %s: releases cached. */
		array( 'tone' => 'neutral', 'label' => sprintf( _n( '%s release cached', '%s releases cached', (int) $s['count'], 'signal-and-noise-tools' ), \number_format_i18n( (int) $s['count'] ) ) ),
		$s['spotify_on'] ? array( 'tone' => 'ok', 'label' => __( 'Spotify configured', 'signal-and-noise-tools' ) ) : array( 'tone' => 'neutral', 'label' => __( 'Spotify not configured', 'signal-and-noise-tools' ) ),
	);
	$under = 'ok' === (string) $c['kind'] ? '<p class="snt-hint">' . \snt_kit_esc( (string) $c['body'] ) . '</p>' : music_status_html( $s );
	return \snt_kit_status_line( $chips ) . $under;
}

/**
 * Sync now, at the foot of Status (2026-10-12 audit: as its own box it was an
 * empty box with one button in its corner): the classic card's helper, then
 * the one-button form posting `music_sync`.
 *
 * @return string
 */
function music_sync_html() {
	return '<p class="snt-hint">' . \snt_kit_esc( __( 'Sync now runs the full Muso → Spotify → store pass immediately. It keeps the last-good discography if a source fails.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_form( 'music_sync', '', array( 'submit' => __( 'Sync now', 'signal-and-noise-tools' ), 'hidden' => music_hidden(), 'inline' => true ) );
}

/**
 * Release notes: the line under a release's roles on /music and /musica,
 * the same rows the classic leaf paints (inc/discography-notes-form.php).
 *
 * @return string
 */
function music_notes_section() {
	if ( ! function_exists( 'sn_discography_notes_fields_html' ) ) {
		return '';
	}
	return \snt_kit_section(
		__( 'Release notes', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( \sn_discography_notes_intro() ) . '</p>'
		. music_save_form( \sn_discography_notes_fields_html() )
	);
}
