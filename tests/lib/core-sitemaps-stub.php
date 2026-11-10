<?php
/**
 * Core's sitemap API, reduced to what a purge reads: get_sitemap_url(),
 * wp_sitemaps_get_server()->registry->get_provider()->get_max_num_pages() and
 * is_object_in_taxonomy().
 *
 * The page counts are core's to decide (the wp_sitemaps_max_urls filter, the
 * provider's own query), so a test SETS them:
 *   $GLOBALS['__sitemap_pages']['posts']['post'] = 2;
 * Unset means no pages. A subtype set to false is one core does not list:
 * get_sitemap_url() answers false for it, as core does.
 */

function get_sitemap_url( $name, $subtype_name = '', $page = 1 ) {
	if ( 'index' === $name ) {
		return home_url( '/wp-sitemap.xml' );
	}
	if ( ! isset( $GLOBALS['__sitemap_pages'][ $name ] ) || false === ( $GLOBALS['__sitemap_pages'][ $name ][ $subtype_name ] ?? false ) ) {
		return false;
	}
	return home_url( '/wp-sitemap-' . $name . '-' . $subtype_name . '-' . max( 1, (int) $page ) . '.xml' );
}

function wp_sitemaps_get_server() {
	$provider = static function ( $name ) {
		return new class( $name ) {
			public $name;
			public function __construct( $name ) { $this->name = $name; }
			public function get_max_num_pages( $object_subtype = '' ) { return (int) ( $GLOBALS['__sitemap_pages'][ $this->name ][ $object_subtype ] ?? 0 ); }
		};
	};
	return (object) array(
		'registry' => new class( $provider ) {
			public $make;
			public function __construct( $make ) { $this->make = $make; }
			public function get_provider( $name ) { return isset( $GLOBALS['__sitemap_pages'][ $name ] ) ? ( $this->make )( $name ) : null; }
		},
	);
}

function is_object_in_taxonomy( $object_type, $taxonomy ) {
	return 'post' === $object_type && 'post_tag' === $taxonomy;
}
