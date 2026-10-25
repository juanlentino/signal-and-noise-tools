<?php
/**
 * Signal & Noise Tools: is an administrator's login username public? (2026-10-18)
 *
 * The site locks /wp-json/wp/v2/users (inc/rest-hardening.php) and the ?author=
 * scan (inc/security-headers.php), but the oEmbed endpoint publishes author_url,
 * /notes/author/<user_nicename>/, and that archive answers 200. Core derives
 * user_nicename from user_login when a user is created (sanitize_title of it)
 * and never changes it afterwards, so unless someone set it by hand the public
 * author slug IS the login username.
 *
 * This observes and never enforces: a watch (public_username) and a direct core
 * Site Health test compare the two core user fields for every administrator,
 * live. Neither ever prints either value: the user ID only.
 *
 * Core has no profile field for user_nicename (Nickname and "Display name
 * publicly as" set other fields), so the fix names WP-CLI.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE: whether a public author slug gives away the login username.
 *
 * @param string $login    user_login.
 * @param string $nicename user_nicename.
 * @return bool
 */
function snt_public_username_matches( $login, $nicename ) {
	$login    = (string) $login;
	$nicename = (string) $nicename;
	if ( '' === $login || '' === $nicename ) {
		return false;
	}
	return 0 === strcasecmp( $login, $nicename ) || sanitize_title( $login ) === strtolower( $nicename );
}

/**
 * PURE: the verdict over administrators. Names users by ID only.
 *
 * @param object[] $admins Each with ID, user_login, user_nicename.
 * @return array{ripe:bool,note:string,public:int,admins:int}
 */
function snt_public_username_verdict( array $admins ) {
	$ids = array();
	foreach ( $admins as $u ) {
		if ( snt_public_username_matches( $u->user_login ?? '', $u->user_nicename ?? '' ) ) {
			$ids[] = (int) $u->ID;
		}
	}
	$note = '';
	if ( $ids ) {
		$note = sprintf(
			'%s: an administrator\'s public author slug is their login username, published by oEmbed as the author link. Core has no profile field for the slug (user_nicename); change it with WP-CLI: wp user update %d --user_nicename=<new-slug>',
			( 1 === count( $ids ) ? 'user ID ' : 'user IDs ' ) . implode( ', ', $ids ),
			$ids[0]
		);
	}
	return array( 'ripe' => (bool) $ids, 'note' => $note, 'public' => count( $ids ), 'admins' => count( $admins ) );
}

/** The live read: every administrator's two core fields. */
function snt_public_username_live() {
	return snt_public_username_verdict( (array) get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login', 'user_nicename' ) ) ) );
}

/** Watch: an administrator's login username is public. */
function snt_watch_ripe_public_username( $watch, $now ) {
	unset( $watch, $now );
	$v = snt_public_username_live();
	return array( 'ripe' => $v['ripe'], 'note' => $v['note'] );
}

/** Core Site Health registration (direct test). */
function snt_public_username_register_site_health_test( $tests ) {
	$tests['direct']['sn_public_username'] = array(
		'label' => __( 'Signal & Noise public username check', 'signal-and-noise-tools' ),
		'test'  => 'snt_public_username_site_health_result',
	);
	return $tests;
}

/** The Site Health row: counts and user IDs, never a name. */
function snt_public_username_site_health_result() {
	$v    = snt_public_username_live();
	$good = ! $v['ripe'];
	$text = $good
		/* translators: %d: administrators checked. */
		? sprintf( __( 'No administrator\'s public author slug is their login username (%d checked). oEmbed publishes the author slug, so this keeps the login username private.', 'signal-and-noise-tools' ), $v['admins'] )
		/* translators: 1: administrators with a public login, 2: administrators checked. */
		: sprintf( __( '%1$d of %2$d administrators have an author slug equal to their login username, so oEmbed publishes the login username.', 'signal-and-noise-tools' ), $v['public'], $v['admins'] ) . ' ' . $v['note'];
	return array(
		'label'       => $good ? __( 'Administrator login usernames are not published', 'signal-and-noise-tools' ) : __( 'An administrator\'s login username is published', 'signal-and-noise-tools' ),
		'status'      => $good ? 'good' : 'recommended',
		'badge'       => array(
			'label' => __( 'Security', 'signal-and-noise-tools' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html( $text ) . '</p>',
		'test'        => 'sn_public_username',
	);
}

/**
 * oEmbed answers every embed request with author_url, a link to the author
 * archive whose last segment is the author slug: the one public surface on
 * this site that published it (checked 2026-10-18). The display name stays;
 * every byline shows it anyway.
 *
 * @param mixed $data oEmbed response data.
 * @return mixed
 */
function snt_oembed_drop_author_url( $data ) {
	if ( is_array( $data ) ) {
		unset( $data['author_url'] );
	}
	return $data;
}

if ( ! defined( 'SN_PUBLIC_USERNAME_TEST' ) || ! SN_PUBLIC_USERNAME_TEST ) {
	add_filter( 'site_status_tests', 'snt_public_username_register_site_health_test' );
	add_filter( 'oembed_response_data', 'snt_oembed_drop_author_url', 20 );
}
