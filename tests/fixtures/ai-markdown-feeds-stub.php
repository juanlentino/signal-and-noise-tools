<?php
/**
 * Fixture for tests/resume-format-headers.php: the AI plugin's Markdown
 * feature as far as a singular request touches it, after
 * Markdown_Feeds::handle_template_redirect() and
 * Markdown_Singular_Renderer::render() at ai 1.5.0. The handler is hooked on
 * template_redirect at 10; for ?output_format=markdown it runs the sections
 * through `wpai_markdown_singular_sections`, joins them with blank lines, ends
 * the document with one newline, sends its two headers and exits.
 *
 * @package SignalNoiseTools
 */

namespace WordPress\AI\Experiments\Markdown_Feeds;

class Markdown_Feeds {
	public function handle_template_redirect() {
		if ( 'markdown' !== ( $_GET['output_format'] ?? '' ) ) {
			return;
		}
		$sections = array( 'title' => '# Generic', 'meta' => '- Link: x', 'content' => 'generic conversion', 'comments' => '' );
		if ( function_exists( 'sn_resume_formats_markdown_sections' ) ) {
			$sections = \sn_resume_formats_markdown_sections( $sections, (object) array( 'ID' => 7 ) );
		}
		$sections = array_filter( array_map( 'strval', $sections ), static fn( $s ) => '' !== $s );
		header( 'Content-Type: text/markdown; charset=UTF-8' ); // get_option( 'blog_charset' ).
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Test-Served-By: ai-plugin' ); // the test's own marker, not a header the plugin sends.
		echo implode( "\n\n", $sections ) . "\n";
		exit;
	}
}

$GLOBALS['__ai']                             = new Markdown_Feeds();
$GLOBALS['wp_filter']['template_redirect'] = (object) array(
	'callbacks' => array( 10 => array( array( 'function' => array( $GLOBALS['__ai'], 'handle_template_redirect' ) ) ) ),
);
