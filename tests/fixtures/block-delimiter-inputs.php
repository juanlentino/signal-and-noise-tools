<?php
/**
 * Block markup the delimiter readers are pinned against
 * (tests/sn-apply-block-delimiters.php). Label => raw content.
 */
return array(
	'empty'                    => '',
	'classic, no blocks'       => "<p>Just a classic post.</p>\n<p>Second &amp; last.</p>",
	'one paragraph'            => "<!-- wp:paragraph -->\n<p>One.</p>\n<!-- /wp:paragraph -->",
	'two top-level'            => "<!-- wp:paragraph -->\n<p>One.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Two</h3>\n<!-- /wp:heading -->",
	'nested group'             => "<!-- wp:group {\"className\":\"a\"} -->\n<div class=\"wp-block-group a\"><!-- wp:paragraph -->\n<p>In.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>x</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></div>\n<!-- /wp:group -->",
	'void blocks'              => "<!-- wp:separator /-->\n<!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:paragraph -->\n<!-- wp:latest-posts {\"postsToShow\":3} /-->",
	'void inside a group'      => "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:spacer {\"height\":\"20px\"} /--></div>\n<!-- /wp:group -->",
	'freeform between blocks'  => "Lead text.\n<!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:paragraph -->\nloose <b>html</b>\n<!-- wp:paragraph -->\n<p>b</p>\n<!-- /wp:paragraph -->\ntail",
	'namespaced block'         => "<!-- wp:acme/card {\"id\":7} -->\n<div class=\"wp-block-acme-card\">c</div>\n<!-- /wp:acme/card -->",
	'JSON with braces'         => "<!-- wp:group {\"style\":{\"spacing\":{\"padding\":{\"top\":\"1px\"}}},\"note\":\"a } b { c\"} -->\n<div class=\"wp-block-group\"></div>\n<!-- /wp:group -->",
	'JSON with escaped arrow'  => "<!-- wp:paragraph {\"note\":\"a \\u002d\\u002d\\u003e b\"} -->\n<p>x</p>\n<!-- /wp:paragraph -->",
	'multibyte text'           => "<!-- wp:paragraph -->\n<p>Señal y ruido — café ☕ 日本語</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Después.</p>\n<!-- /wp:paragraph -->",
	'CRLF line endings'        => "<!-- wp:paragraph -->\r\n<p>One.</p>\r\n<!-- /wp:paragraph -->\r\n\r\n<!-- wp:paragraph -->\r\n<p>Two.</p>\r\n<!-- /wp:paragraph -->\r\n",
	'entities and attributes'  => "<!-- wp:paragraph -->\n<p>A &amp; B &lt;tag&gt; <a href='x' title=\"a > b\">l</a></p>\n<!-- /wp:paragraph -->",
	'plain HTML comment'       => "<!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:paragraph -->\n<!-- just a note, <b>not</b> a block -->\n<!-- wp:paragraph -->\n<p>b</p>\n<!-- /wp:paragraph -->",
	'unclosed opener'          => "<!-- wp:paragraph -->\n<p>Unclosed.</p>",
	'stray closer'             => "<p>x</p>\n<!-- /wp:paragraph -->",
	'mismatched closer'        => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:heading -->",
	'unclosed inside a group'  => "<!-- wp:group -->\n<div><!-- wp:paragraph -->\n<p>x</p></div>\n<!-- /wp:group -->",
	// From here on the two former patterns and core disagree; each is named in the suite.
	'core/ namespace spelled'  => "<!-- wp:core/paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->",
	'no space after <!--'      => "<!--wp:paragraph -->\n<p>x</p>\n<!--/wp:paragraph -->",
	'no space before -->'      => "<!-- wp:paragraph-->\n<p>x</p>\n<!-- /wp:paragraph-->",
	'closer with void flag'    => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph /-->",
	'delimiter in a comment'   => "<!-- note: <!-- wp:paragraph --> was here -->\n<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->",
	'broken JSON, then a block' => "<!-- wp:paragraph {\"a\":1 -->\n<p>x</p>\n<!-- wp:heading {\"level\":2} -->\n<h2>y</h2>\n<!-- /wp:heading -->",
	'truncated last delimiter' => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph {\"a\":",
	'only a truncated opener'  => '<!-- wp:paragraph {"a":',
	'literal arrow in JSON'    => "<!-- wp:paragraph {\"content\":\"a --> b\"} -->\n<p>x</p>\n<!-- /wp:paragraph -->",
	'attrs without a space'    => "<!-- wp:paragraph{\"a\":1} -->\n<p>x</p>\n<!-- /wp:paragraph -->",
);
