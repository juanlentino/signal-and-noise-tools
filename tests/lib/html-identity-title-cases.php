<?php
/**
 * Markup for the citation verifier's two other readers: "does this page name
 * who publishes it?" and "what is its title?". NOT a suite.
 *
 * The page is a stranger's, fetched because they claimed to cite this site,
 * so the cases lean on text that only looks like the markup being asked about.
 *
 * @return array{identity:array<string,string>,title:array<string,string>}
 */

return array(
	'identity' => array(
		'nested inline markup'                => '<p><strong><a rel="me noopener" href="https://m.example/@a"><em>me</em></a></strong></p>',
		'uppercase tag and attribute, single quotes' => "<A CLASS='h-card' REL='ME' HREF='https://m.example/@a'>a</A>",
		'unquoted rel on a link element'      => '<link href="https://m.example/@a" rel=me>',
		'self-closing link element'           => '<LINK REL="webfinger" HREF="/.well-known/webfinger" />',
		'a > inside an earlier attribute'     => '<a title="a > b" rel="nofollow me" href="https://m.example/@a">a</a>',
		'rel=me written in prose'             => '<p>Set rel=me on your profile link and you are done.</p>',
		'a rel=me link inside a comment'      => '<!-- <link rel="me" href="https://m.example/@a"> --><p>hello</p>',
		'a rel=me link inside a script'       => '<script>var s = \'<a rel="me" href="/">me</a>\';</script>',
		'rel=me inside another attribute'     => '<a title=\'x rel="me" y\' href="/">x</a>',
		'data-rel is not rel'                 => '<a data-rel="me" href="/">x</a>',
		'rel=me on an element that is not a link' => '<div rel="me">x</div>',
		'block delimiter with JSON'           => '<!-- wp:social-link {"url":"https://m.example/@a","service":"mastodon","rel":"me"} /-->',
		'multibyte text'                      => '<p>Señal y ruido: 日本語</p><a rel="me" href="https://m.example/@señal">yo</a>',
		'rel=mention'                         => '<a rel="mention" href="/">x</a>',
		'a did:web in prose'                  => '<p>My identifier is did:web:example.com.</p>',
		'empty input'                         => '',
	),
	'title'    => array(
		'whitespace and newlines'       => "<html><head><title>  Hello\n\t World </title></head></html>",
		'uppercase tag with attributes' => '<TITLE lang="en">Upper &amp; Lower</TITLE>',
		'markup written inside the title' => '<title>A <b>bold</b> claim</title>',
		'escaped markup in the title'   => '<title>Using &lt;template&gt; in HTML</title>',
		'a title inside a comment'      => '<!-- <title>Old title</title> --><title>Real title</title>',
		'a title inside a script'       => '<script>document.write("<title>Fake</title>")</script><title>Real title</title>',
		'only an SVG title'             => '<body><svg viewBox="0 0 1 1"><title>Icon</title><path d="M0 0"/></svg></body>',
		'entities'                      => '<title>a &#8212; b&nbsp;c &quot;d&quot; &apos;e&apos;</title>',
		'multibyte text'                => '<title>Señal y ruido: 日本語</title>',
		'unclosed title'                => '<title>Never closed',
		'no title'                      => '<p>no title</p>',
		'empty input'                   => '',
	),
);
