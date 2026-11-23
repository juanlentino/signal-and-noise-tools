<?php
/**
 * Signal & Noise: the author's name, for every output that names the person.
 *
 * The plugin's field (Settings > General, identity.person_name) complements
 * the author's WordPress profile and never shadows it: empty, the name is the
 * profile's display name, as provenance already printed it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The person's name: identity.person_name when it is set; else the display
 * name provenance resolves for the post in context (sn_prov_author(), the one
 * resolution, its filter included); else the site title.
 *
 * "Set" is read from the stored option. sn_setting() merges the site title in
 * as the default and keeps a saved-empty key, so it cannot tell a site that
 * never saved the field from one that saved the site title, and it hands a
 * saved-empty field back as ''.
 *
 * A set name is returned through the expression every reader used before, so
 * a site with the field set prints what it printed.
 *
 * @param object|null $post The post in context. Null: the queried post on a singular view.
 * @return string
 */
function sn_identity_person_name( $post = null ) {
	$stored = defined( 'SN_SETTINGS_OPTION' ) ? (array) get_option( SN_SETTINGS_OPTION, array() ) : null;
	$set    = null === $stored ? (string) sn_setting( 'identity.person_name', '' ) : (string) ( $stored['identity']['person_name'] ?? '' );
	if ( '' !== $set ) {
		return sn_setting( 'identity.person_name', get_bloginfo( 'name' ) );
	}
	if ( null === $post && function_exists( 'is_singular' ) && function_exists( 'get_queried_object' ) && is_singular() ) {
		$post = get_queried_object();
	}
	$author = is_object( $post ) && ! empty( $post->post_author ) && function_exists( 'sn_prov_author' ) ? trim( sn_prov_author( $post ) ) : '';
	return '' !== $author ? $author : (string) get_bloginfo( 'name' );
}
