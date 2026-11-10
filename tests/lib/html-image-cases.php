<?php
/**
 * Markup for the alt-text health check's three readers: inline <img> with no
 * alt, inline <img> whose alt is judged in context (caption, heading), and
 * inline <svg> with no accessible name. NOT a suite.
 *
 * The cases are the shapes a regex over bytes and an HTML parser disagree on,
 * plus the stored block markup the check was written against.
 *
 * @return array<string,string> case name => post_content.
 */

return array(
	'nested inline markup'          => '<p><a href="/x"><strong><img src="a.png"></strong></a> and <em><img src="a2.png" alt="A second, described picture"></em></p>',
	'attributes in any order'       => "<img class='x' alt='Bob&#39;s desk' src='b.png' loading=lazy><img loading=\"lazy\" src='b2.png' class=\"y\">",
	'uppercase tags and attributes' => '<IMG SRC="c.png" CLASS="x"><IMG ALT="A Chart Of Sales" SRC="c2.png">',
	'entities in src and alt'       => '<img src="d.png?a=1&amp;b=2"><img src="d2.png?a=1&amp;b=2" alt="Tom &amp; Jerry at the desk">',
	'a > inside an attribute'       => '<img title="a > b" src="e.png" alt="An arrow from a to b"><img title="x > y" src="e2.png">',
	'a bare alt with no value'      => '<img src="f.png" alt>',
	'data-alt and data-src'         => '<img src="g.png" data-alt="not an alt"><img data-src="g2.png" class="lazy">',
	'alt= inside another attribute' => '<img src="h.png" title="alt=none">',
	'unquoted values'               => '<img src=i.png><img src=i2.png alt=word>',
	'self-closing tags'             => '<img src="j.png"/><img src="j2.png" alt="A self-closed picture" />',
	'images inside a comment'       => '<!-- <img src="k.png"> <img src="k2.png" alt="Commented out"> --><p>x</p>',
	'image blocks with JSON and captions' => '<!-- wp:image {"id":5,"sizeSlug":"large","alt":"x > y"} --><figure class="wp-block-image size-large"><img src="l.png" alt="" class="wp-image-5"/><figcaption class="wp-element-caption">The 1954 ledger, reopened</figcaption></figure><!-- /wp:image --><!-- wp:image {"id":6} --><figure class="wp-block-image"><img src="l2.png" alt="The 1954 ledger, reopened" class="wp-image-6"/><figcaption class="wp-element-caption">The 1954 <em>ledger</em>, reopened</figcaption></figure><!-- /wp:image -->',
	'multibyte text'                => '<p>señal <img src="señal.png"> <img src="ruido.png" alt="Señal y ruido 日本語"></p>',
	'empty input'                   => '',
	'images in a textarea and a script' => '<textarea><img src="m.png"></textarea><script>var s = \'<img src="m2.png" alt="In a script">\';</script>',
	'the other quote inside an alt' => '<img src="n.png" alt="Logo\'s sketch"><img src=\'n2.png\' alt=\'He said "hi"\'>',
	'a card: figure, then its heading, in block markup' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="production-min.png" alt="Production" class="wp-image-7"/></figure><!-- /wp:image --><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">PRODUCTION</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
	'a heading inside the skip budget, and one outside it' => '<img src="o.png" alt="Operations"><p>one</p><p>two</p><h3>Operations &amp; AI</h3><p>gap</p><img src="o2.png" alt="Too far"><p>1</p><p>2</p><p>3</p><h3>Too far</h3>',
	'the next image owns the heading' => '<img src="p.png" alt="First"><img src="p2.png" alt="Second"><h2>Second</h2>',
	'nested figures'                => '<figure class="wp-block-gallery"><figure class="wp-block-image"><img src="q.png" alt="Gallery one"/><figcaption>Gallery one</figcaption></figure><figure class="wp-block-image"><img src="q2.png" alt="Gallery two"/></figure><figcaption>Whole gallery</figcaption></figure>',
	'a heading with inline markup, inside a wrapper' => '<figure><img src="r.png" alt="Signal and noise"></figure><div class="x"><h2 class="t"><a href="/x">Signal <em>&amp;</em> Noise</a></h2></div>',
	'svg: bare, and named four ways' => '<svg viewBox="0 0 1 1"><path d="M0 0"/></svg><SVG CLASS="icon" ID="logo" ARIA-LABEL="Logo"><PATH D="M0"/></SVG><svg aria-labelledby="t"><path d="M0"/></svg><svg role="img">' . "\n\t" . '<title>A magnifying glass</title>' . "\n\t" . '<path d="M0"/></svg><svg><desc>d</desc><title><tspan>Named late</tspan></title></svg>',
	'svg: decorative, four spellings' => '<svg aria-hidden=true><path d="M0"/></svg><svg aria-hidden="false" id="shown"><path d="M0"/></svg><svg role=\'none\'><path d="M0"/></svg><svg role="presentation" class="deco"><path d="M0"/></svg>',
	'svg: a title that is not a direct child' => '<svg id="in-g"><g><title>in a group</title></g><path d="M0"/></svg><svg id="empty-title"><title>   </title><path d="M0"/></svg>',
	'svg: a > inside an attribute'  => '<svg aria-label="a > b" id="gt"><path d="M0"/></svg><svg title="x > y" id="gt2"><path d="M0"/></svg>',
	'svg: nested'                   => '<svg id="outer"><svg id="inner"><title>Inner</title></svg><path d="M0"/></svg>',
	'svg: self-closing, then a named one' => '<svg id="hollow"/><p>after</p><svg id="second"><title>Second</title></svg>',
	'svg: inside a comment'         => '<!-- <svg id="commented"><path d="M0"/></svg> --><p>x</p>',
	'svg: in a custom HTML block, multibyte class' => '<!-- wp:html --><svg class="señal  icono" viewBox="0 0 1 1"><path d="M0"/></svg><!-- /wp:html -->',
	'svg: data-aria-label is not a name' => '<svg data-aria-label="x" data-id="y" class="k"><path d="M0"/></svg>',
	'svg: never closed'             => '<p>x</p><svg id="unclosed"><path d="M0"/>',
);
