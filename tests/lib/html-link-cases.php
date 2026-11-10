<?php
/**
 * One set of markup for every reader that asks "what does this HTML link to?".
 *
 * NOT a suite. The health link checks, the citation verifier and the link
 * graph each run these same inputs and pin their own answer, so a reader that
 * drifts from the others shows up as a difference on a named case. The site
 * in every case is https://example.test; other.example is somewhere else.
 *
 * The cases are the shapes a regex over bytes and an HTML parser disagree on,
 * plus the ordinary ones they must agree on.
 *
 * @return array<string,string> case name => markup.
 */

return array(
	'nested inline markup'           => '<p>See <a href="https://example.test/notes/a/"><strong>the <em>note</em></strong></a> and <a href="https://other.example/a"><code>out</code></a>.</p>',
	'attributes in any order'        => "<a class='k' target=\"_blank\" href='/notes/b/' rel=noopener>b</a> <a rel=\"nofollow\" href='https://other.example/b' class=\"x\">b</a>",
	'uppercase tags'                 => '<A HREF="https://example.test/notes/c/">C</A> <A HREF="https://other.example/c">c</A>',
	'entities in the href'           => '<a href="https://example.test/notes/d/?a=1&amp;lang=en">d</a> <a href="https://other.example/d?a=1&amp;b=2&#38;c=3">d</a>',
	'a > inside an earlier attribute' => '<a title="a > b" href="/notes/e/">e</a> <a title="x > y" href="https://other.example/e">e</a>',
	'unquoted href'                  => '<a href=/notes/f/>f</a> <a href=https://other.example/f>f</a>',
	'data-href is not href'          => '<a data-href="/notes/g/">g</a> <a data-href="https://other.example/g">g</a>',
	'self-closing and void tags'     => '<img src="/i.png" alt=""/><br/><a href="/notes/h/"/>h</a><hr><a href="https://other.example/h" />h</a>',
	'links inside an HTML comment'   => '<!-- <a href="/notes/i/">i</a> <a href="https://other.example/i">i</a> --><p>after</p>',
	'block delimiters with JSON'     => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"url":"https://example.test/notes/j/","className":"is-style-outline","rel":"a > b"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link" href="https://example.test/notes/j/">J</a></div><!-- /wp:button --></div><!-- /wp:buttons --><!-- wp:paragraph --><p><a href="https://other.example/j">j</a></p><!-- /wp:paragraph -->',
	'multibyte text and paths'       => '<p>Señal y ruido: <a href="https://example.test/notes/señal/">la señal</a>, <a href="https://other.example/añejo">añejo</a> 日本語</p>',
	'empty input'                    => '',
	'an apostrophe in a quoted href' => '<a href="/notes/it\'s/">k</a> <a href="https://other.example/it\'s">k</a>',
	'links in a textarea and a script' => '<textarea><a href="/notes/l/">l</a></textarea><script>var s = \'<a href="https://other.example/l">l</a>\';</script>',
	'href text inside another attribute' => '<a title="x href=https://other.example/m y" name="m">m</a> <span title=\'<a href="/notes/m/">\'>m</span>',
	'schemes and fragments'          => '<a href="#top">top</a> <a href="mailto:a@example.test">m</a> <a href="tel:+1">t</a> <a href="javascript:void(0)">j</a> <a href="//example.test/notes/n/">n</a> <a href="/notes/n/#part">n</a>',
	'the same link twice'            => '<a href="/notes/o/">o</a> <a href="https://example.test/notes/o/">o</a> <a href="/notes/o/">o</a> <a href="https://other.example/o">o</a> <a href="https://other.example/o">o</a>',
	'no value, empty value'          => '<a href>p</a> <a href="">p</a> <a name="p">p</a> <a href=" /notes/p/ ">p</a>',
);
