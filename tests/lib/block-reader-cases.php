<?php
/**
 * Stored block markup for the readers that pick paragraphs out of a body: the
 * correction-notice detector (inc/provenance-change-class.php) and the Jev
 * tells reader (inc/jev-tells.php). NOT a suite.
 *
 * The cases are the shapes a regex over block comments and core's block
 * parser disagree on (nesting, attribute JSON, long block names), plus the
 * ordinary ones they must agree on. Sentences are long enough for the tells
 * reader's 40-character floor.
 *
 * @return array<string,string> case name => post_content.
 */

$para = static function ( $html, $attrs = '' ) {
	return '<!-- wp:paragraph ' . ( '' === $attrs ? '' : $attrs . ' ' ) . '-->' . $html . '<!-- /wp:paragraph -->';
};
$fix  = '<p class="sn-correction"><strong>Correction, October 16, 2026.</strong> An earlier version of this note gave the wrong year for the ledger.</p>';
$body = '<p>The ledger was reopened in the spring, and nobody had expected what it showed.</p>';

return array(
	'a plain paragraph and a classed notice' => $para( $body ) . "\n\n" . $para( $fix, '{"className":"sn-correction"}' ),
	'attributes in any order, two classes'  => $para( '<p class="has-text-align-left is-style-note sn-correction">Fixed the figure in the second table, which had the columns the wrong way around.</p>', '{"align":"left","className":"is-style-note sn-correction","fontSize":"small"}' ),
	'attribute JSON with nested braces and an escaped >' => $para( $fix, '{"style":{"color":{"text":"#111111"},"elements":{"link":{"color":{"text":"a > b"}}}},"className":"sn-correction"}' ),
	'a notice nested in a group'             => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $para( $body ) . $para( $fix, '{"className":"sn-correction"}' ) . '</div><!-- /wp:group -->',
	'a notice nested in columns'             => '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $para( '<p><em>Correction, October 1, 2026: An earlier version of this note named the wrong archive.</em></p>' ) . '</div><!-- /wp:column --></div><!-- /wp:columns -->',
	'a quoted paragraph'                     => $para( $body ) . '<!-- wp:quote --><blockquote class="wp-block-quote">' . $para( '<p>Correction, they wrote in the margin, is the only honest form of revision there is.</p>' ) . '<cite>Somebody else</cite></blockquote><!-- /wp:quote -->',
	'an unclassed italic notice'             => $para( '<p><em>Correction, October 1, 2026: An earlier version of this note said the opposite.</em></p>' ),
	'uppercase lead-in with nested inline markup' => $para( '<p><strong>CORRECTION, <em>October</em> 16, 2026.</strong> See <a href="/notes/ledger/" title="a > b">the ledger note</a> for the corrected figure.</p>' ),
	'entity-led text'                        => $para( '<p>&#67;orrection, October 2, 2026: the caf&eacute; was on the other side of the street.</p>' ),
	'a class that only starts with sn-correction' => $para( '<p class="sn-correction-archive">An archive of earlier wording, kept here for readers who want to compare.</p>', '{"className":"sn-correction-archive"}' ),
	'sn-correction in another attribute'     => $para( '<p id="sn-correction">Readers have asked how corrections are handled on this site, so here it is.</p>', '{"anchor":"sn-correction"}' ),
	'the long block name'                    => '<!-- wp:core/paragraph {"className":"sn-correction"} -->' . $fix . '<!-- /wp:core/paragraph -->',
	'a void paragraph'                       => '<!-- wp:paragraph {"className":"sn-correction"} /-->' . $para( $body ),
	'correction mentioned mid-sentence'      => $para( '<p>What follows is not a Correction, only a longer explanation of the same point.</p>' ),
	'a heading that opens Correction,'       => '<!-- wp:heading --><h2 class="wp-block-heading">Correction, and what it costs to make one in public</h2><!-- /wp:heading -->' . $para( $body ),
	'whitespace and newlines inside'         => $para( "\n<p class=\"sn-correction\">\n\t<strong>Correction, October 16, 2026.</strong>\n\tAn earlier version said X, and that was wrong.\n</p>\n", '{"className":"sn-correction"}' ),
	'multibyte text'                         => $para( '<p class="sn-correction">Corrección, 16 de octubre: una versión anterior decía «señal» donde iba «ruido» 日本語.</p>', '{"className":"sn-correction"}' ) . $para( '<p>Señal y ruido: lo que el libro mayor mostraba no era lo que nadie esperaba 日本語.</p>' ),
	'a comment holding paragraph markup'     => '<!-- <p>Correction, this sentence sits in an HTML comment and nobody reads it at all.</p> -->' . $para( $body ),
	'a list and a paragraph inside details'  => '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>A list item long enough to pass the floor if it were ever counted.</li><!-- /wp:list-item --></ul><!-- /wp:list --><!-- wp:details --><details class="wp-block-details"><summary>More</summary>' . $para( '<p>Inside the details block there is one more paragraph of ordinary prose to read.</p>' ) . '</details><!-- /wp:details -->',
	'a pullquote and a custom HTML block'    => '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>A pulled sentence that repeats the body and is long enough to count.</p><cite>Body</cite></blockquote></figure><!-- /wp:pullquote --><!-- wp:html --><p>Correction, written by hand inside a custom HTML block, forty characters and more.</p><!-- /wp:html -->',
	'a classic chunk between blocks'         => $para( $body ) . "\n\n<p>Correction, typed into a Classic block years ago and never converted to a paragraph block.</p>\n\n" . $para( '<p>And then the note goes on in blocks, as every newer note on the site does.</p>' ),
	'classic content, no blocks'             => "<p>Correction, October 3, 2026: this body was written in the classic editor.</p>\n<p>It has a second paragraph that is also long enough to be read on its own.</p>",
	'empty input'                            => '',
);
