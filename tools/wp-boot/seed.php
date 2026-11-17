<?php
// The same small site on both versions: a note with a category and a tag, the
// pages the theme routes by slug, so every archive type has something to show.
if ( get_page_by_path( 'cv' ) ) {
	return;
}
$cat = wp_insert_term( 'Provenance', 'category' );
$id  = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A boot note', 'post_name' => 'a-boot-note', 'post_date' => '2026-11-01 10:00:00',
	'post_content' => "<!-- wp:paragraph -->\n<p>One paragraph with <a href=\"/cv/\">a link</a>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">A heading</h2>\n<!-- /wp:heading -->" ) );
wp_set_post_categories( $id, array( is_array( $cat ) ? $cat['term_id'] : 1 ) );
wp_set_post_tags( $id, 'metadata' );
foreach ( array( 'cv' => 'CV', 'notes' => 'Notes', 'contact' => 'Contact', 'about' => 'About' ) as $slug => $title ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => "<!-- wp:paragraph -->\n<p>$title.</p>\n<!-- /wp:paragraph -->" ) );
}
