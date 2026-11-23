<?php
/**
 * Signal & Noise: the person's name, for every output that names them.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The person's name: identity.person_name (Settings > General) when it is
 * set, else the site title. The same on every page: it names one schema node.
 *
 * sn_setting() alone is not enough. The save always stores the key and the
 * reader keeps a stored '', so a field saved empty came back as '' and the
 * schema printed "name": "", while a site that never saved it got the site
 * title from the defaults. Both now get the site title.
 *
 * A set name is returned as sn_setting() hands it over, untouched, so a site
 * with the field set prints what it printed.
 *
 * @return string
 */
function sn_identity_person_name() {
	$name = sn_setting( 'identity.person_name', get_bloginfo( 'name' ) );
	return '' !== trim( (string) $name ) ? $name : (string) get_bloginfo( 'name' );
}
