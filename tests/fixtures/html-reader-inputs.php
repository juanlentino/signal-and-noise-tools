<?php
/**
 * Markup the HTML readers are pinned against (tests/html-readers-on-core.php):
 * the link_reshape locator, the anchor-violation scan, the em-dash markup
 * ranges and the editorial table and SVG helpers. Label => raw content.
 * The em dashes here are data for the em-dash scan.
 */
return array(
	'empty'                       => '',
	'classic, no blocks'          => "<p>See <a href=\"/x/\">the note</a> for more — or not.</p>\n<h2>Plain heading</h2>",
	'block paragraph with a link' => "<!-- wp:paragraph -->\n<p>Read <a href=\"https://example.com/a?b=1&amp;c=2\">the report</a> first — then decide.</p>\n<!-- /wp:paragraph -->",
	'anchor equals its sentence'  => "<!-- wp:paragraph -->\n<p>Intro here. <a href=\"/y/\">The DAW signs the assembly.</a> Outro here.</p>\n<!-- /wp:paragraph -->",
	'heading with a link'         => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">About <a href=\"/z/\">the thing</a> today</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Body — text.</p>\n<!-- /wp:paragraph -->",
	'references list'             => "<ul class=\"wp-block-list is-style-references\"><li>Source, 2026. <a href=\"https://a.org/\">a.org</a></li><li>Other. <a href=\"https://b.org/\">b.org</a></li></ul>",
	'two identical links'         => "<p>One <a href=\"/a/\">same text</a> and two <a href=\"/b/\">same text</a>.</p>",
	'attributes any order, quotes' => "<p>Go <a class='k' href=/q/ target=\"_blank\" rel='noopener'>this way</a> now.</p>",
	'entities in the anchor'      => "<p>Try <a href=\"/e/\">R&amp;D &lt;fast&gt; caf&eacute;</a> today.</p>",
	'multibyte'                   => "<p>Señal — <a href=\"/m/\">café ☕ 日本語</a> y ruido — fin.</p>",
	'CRLF'                        => "<p>Line one — <a href=\"/c/\">a link</a>.</p>\r\n<p>Line two.</p>\r\n",
	'code and pre'                => "<p>Use <code>a — b</code> here — ok.</p>\n<pre class=\"wp-block-code\"><code>x — y</code></pre>\n<p>After — it.</p>",
	'script and style'            => "<p>Before — it.</p><script>var s = \"a — b <a href='/s/'>in script</a>\";</script><style>p:before{content:\"—\"}</style><p>After.</p>",
	'em dash in a block comment'  => "<!-- wp:paragraph {\"placeholder\":\"a — b\"} -->\n<p>Text.</p>\n<!-- /wp:paragraph -->",
	'table with a head'           => "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><thead><tr><th scope=\"col\">A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2 — 3</td></tr></tbody></table></figure>\n<!-- /wp:table -->",
	'table without a head'        => "<figure class=\"wp-block-table\"><table><tbody><tr><td>1</td></tr></tbody></table></figure>",
	'svg with the trio'           => "<svg role=\"img\" aria-labelledby=\"t d\" viewBox=\"0 0 1 1\"><title id=\"t\">T</title><desc id=\"d\">D</desc></svg>",
	'svg without them'            => "<svg viewBox=\"0 0 1 1\"><path d=\"M0 0\"/></svg>",
	// From here on a regular expression and core read the markup differently; each is named in the suite.
	'> inside an attribute'       => "<p>See <a href=\"/g/\" title=\"a > b — c\">greater</a> — there.</p>",
	'link inside a comment'       => "<!-- old: <a href=\"/h/\">hidden link</a> --><p>Visible <a href=\"/v/\">shown link</a>.</p>",
	'link inside an attribute'    => "<p><img alt=\"<a href='/i/'>attr link</a>\" src=\"/i.png\"> and <a href=\"/r/\">real link</a>.</p>",
	'heading inside a comment'    => "<!-- <h2>Old <a href=\"/o/\">linked</a> heading</h2> -->\n<p>Body.</p>",
	'custom element named a-b'    => "<p><a-b href=\"/n/\">not an anchor</a> text.</p>",
	'upper-case tags'             => "<P>See <A HREF=\"/u/\">upper link</A> here.</P><H2>Up <A HREF=\"/w/\">link</A></H2>",
	'references, single quotes'   => "<ul class='is-style-references'><li>Source. <a href=\"https://c.org/\">c.org</a></li></ul>",
	'references with a nested list' => "<ul class=\"is-style-references\"><li>Group<ul><li>Inner. <a href=\"https://d.org/\">d.org</a></li></ul></li><li>Last. <a href=\"https://e.org/\">e.org</a></li></ul>",
	'less-than in prose'          => "<p>When a < b — and c > d, stop.</p>",
	'comment that never closes'   => "<p>Shown — here.</p><!-- cut off — <a href=\"/k/\">lost link</a>",
	'tag cut off at the end'      => "<p>Shown — here.</p><a href=\"/t/\" title=\"cut — off",
	'th scope in single quotes'   => "<table><thead><tr><th scope='col'>A</th><th scope=COL>B</th><th title=\"x > y\">C</th></tr></thead></table>",
	'svg role in single quotes'   => "<svg role='img' aria-labelledby=t><title id=\"t\">T</title></svg>",
	'svg role inside a title attr' => "<svg data-note='x role=\"img\" aria-labelledby=\"q\"'><path d=\"M0 0\"/></svg>",
);
