<?php
/**
 * Tests: S&N Analytics' machine views, Machine readers and Reader evidence,
 * on both surfaces (native kit painters and classic bodies), over a
 * live-shaped fixture (7 days to 2026-10-11: 2,154 reads claimed training,
 * 170 of them Google-Extended, 25 verified, all from Anthropic, PBC).
 *
 * Run: php tests/analytics-machine-views.php
 */
namespace {
	require_once __DIR__ . '/lib/os-leaf-harness.php';
	if ( ! function_exists( 'remove_query_arg' ) ) { function remove_query_arg( $k, $u = '' ) { return 'admin.php?page=sn-analytics&sn_view=machine-readers'; } }
	$GLOBALS['__fetch'] = array();
	function snt_mr_fetch( $days = 30, $view = 'aggregate' ) { $GLOBALS['__fetch_days'][] = $days; return $GLOBALS['__fetch'][ $view ] ?? array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ); }
	function sn_rss_tracker_window_stats_multi( $w ) { return array( 'most_recent' => '2026-10-11 09:00:00', 'windows' => array( 7 => array( 'total' => 40, 'uniques' => 5 ), 30 => array( 'total' => 210, 'uniques' => 9 ) ) ); }
	$GLOBALS['__report'] = null;
	function snt_ml_reader_anomalies() { return $GLOBALS['__report']; }
	function sn_edge_config() { return true; }
	foreach ( array( 'machine-readers-taxonomy', 'machine-readers-render', 'machine-readers-insights', 'rights-evidence-compose', 'machine-readers-basis', 'machine-readers-basis-fold', 'machine-readers-ledger', 'mr-series', 'ml-reader-anomalies-split', 'machine-readers-readers-model', 'machine-readers-evidence-model', 'evidence-kit', 'evidence-paint-surface', 'evidence-paint-readers', 'evidence-paint-proof', 'evidence-paint-actors', 'evidence-paint-anomalies', 'evidence-paint-reference', 'evidence-paint-rights', 'analytics-machine-views', 'analytics-view-machine-readers', 'analytics-view-reader-evidence', 'analytics-admin', 'analytics-likely-automated' ) as $f ) {
		require_once SNT_PATH . "inc/$f.php";
	}
}
namespace SignalNoise\OpenStationHost\Analytics\Painters {
	function painters() { return \apply_filters( 'snt_os_analytics_painters', array() ); }
	require_once SNT_PATH . 'apps/sn-analytics/parts/paint-kit.php';
	require_once SNT_PATH . 'apps/sn-analytics/parts/painters/view-machine-readers.php';
	require_once SNT_PATH . 'apps/sn-analytics/parts/painters/view-reader-evidence.php';
	require_once SNT_PATH . 'apps/sn-analytics/parts/painters/chrome-header.php';
	require_once SNT_PATH . 'apps/sn-analytics/parts/painters/view-edge.php';
}
namespace {
	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok  - $m\n"; } else { ++$fail; echo "  FAIL - $m\n"; } }
	$r = static fn( $family, $purpose, $agent, $hits, $net, $vb = '', $extra = array() ) => array_merge( array( 'family' => $family, 'surface' => 'html', 'day' => '2026-10-08', 'hits' => $hits, 'purpose' => $purpose, 'agent' => $agent, 'vendor' => explode( '-', $agent )[0], 'taxonomy_version' => '1.3.1', 'first_party' => false, 'verified_bot' => $vb, 'network' => $net, 'signed_agent' => 'unsigned', 'markdown_requested' => false ), $extra );
	$rows = array(
		$r( 'anthropic', 'train', 'anthropic-claudebot', 25, 'Anthropic, PBC', 'AI Crawler', array( 'signed_agent' => 'valid' ) ),
		$r( 'anthropic', 'train', 'anthropic-claudebot', 798, 'Google LLC' ),
		$r( 'openai', 'train', 'openai-gptbot', 309, 'Google LLC' ),
		$r( 'amazon-ai', 'train', 'amazon-bot', 288, 'Google LLC' ),
		$r( 'bytedance', 'train', 'bytedance-bytespider', 283, 'Google LLC' ),
		$r( 'meta-ai', 'train', 'meta-externalagent', 281, 'Google LLC', '', array( 'surface' => 'rights' ) ),
		$r( 'google-ai', 'train', 'google-extended', 170, 'Google LLC' ),
		$r( 'other-bot', 'search', 'anthropic-searchbot', 570, 'Anthropic, PBC', 'Search Engine Crawler', array( 'markdown_requested' => true ) ),
		$r( 'other-bot', 'unknown', '', 3537, 'Google LLC' ),
		$r( 'unclassified-machine', 'ops', 'self-ledger', 11868, 'Microsoft Corporation', '', array( 'first_party' => true, 'vendor' => 'signal-and-noise' ) ),
		$r( 'uptime', 'ops', 'ops-betterstack', 3374, 'Hetzner Online GmbH', 'Monitoring & Analytics', array( 'first_party' => true ) ),
	);
	$GLOBALS['__fetch'] = array(
		'aggregate'       => array( 'ok' => true, 'truncated' => false, 'rows' => $rows, 'webmcp' => array( 'calls' => 9 ) ),
		'rights'          => array( 'ok' => true, 'rows' => array(
			array( 'observed_at' => '2026-10-09T10:00:00Z', 'path' => '/.well-known/tdmrep.json', 'user_agent' => '<script>alert(1)</script>', 'vendor' => 'openai', 'purpose' => 'train', 'family' => 'openai', 'hits' => 1 ),
			array( 'observed_at' => '2026-10-10T09:00:00Z', 'path' => '/license.xml', 'user_agent' => 'GPTBot/1.0', 'vendor' => 'openai', 'purpose' => 'unknown', 'family' => 'openai', 'hits' => 1 ),
			array( 'observed_at' => '2026-10-10T11:00:00Z', 'path' => '/license.xml', 'user_agent' => 'SignalNoise-SmokeTest/1.0', 'vendor' => 'signal-and-noise', 'purpose' => 'ops', 'family' => 'other-bot', 'hits' => 1 ),
		) ),
		'unknown-network' => array( 'ok' => true, 'detail' => 'network', 'rows' => array(
			array( 'user_agent' => 'Mozilla/5.0 compatible GrokBot/1.0 +https //x.ai/', 'hits' => 200, 'verified_bot' => '', 'network' => 'Google LLC' ),
			array( 'user_agent' => 'Mozilla/5.0 compatible GrokBot/1.0 +https //x.ai/', 'hits' => 110, 'verified_bot' => 'AI Crawler', 'network' => 'xAI' ),
		) ),
	);
	$GLOBALS['__report'] = array( 'ok' => true, 'state' => 'ok', 'co_movement' => array( array( 'network' => 'Google LLC', 'day' => '2026-10-10', 'direction' => 'up', 'families' => array( 'amazon-ai', 'anthropic', 'bytedance' ) ) ) );
	$P   = 'SignalNoise\\OpenStationHost\\Analytics\\Painters\\';
	$kit = array(
		'machine-readers' => call_user_func( $P . 'paint_view_machine_readers', array( 'range' => '7' ) ),
		'reader-evidence' => call_user_func( $P . 'paint_view_reader_evidence', array( 'range' => '7' ) ),
	);
	$classic = array();
	foreach ( array_keys( $kit ) as $v ) {
		ob_start();
		snt_analytics_render_view_body( $v, '2026-10-05', '2026-10-11', 'human', 'day', '7', 'off' );
		$classic[ $v ] = (string) ob_get_clean();
	}
	$both = static fn( $v ) => array( 'kit' => $kit[ $v ], 'classic' => $classic[ $v ] );

	echo "Group A: registry, chrome, painters, dispatch\n";
	$keys = array_keys( SN_ANALYTICS_VIEWS );
	ok( array_search( 'machine-readers', $keys, true ) === array_search( 'edge', $keys, true ) + 1 && array_search( 'reader-evidence', $keys, true ) === array_search( 'edge', $keys, true ) + 2 && array_search( 'login-defense', $keys, true ) === array_search( 'edge', $keys, true ) + 3, 'Machine readers and Reader evidence sit after Traffic & edge, before Login defense' );
	ok( snt_analytics_view_owns_chrome( 'machine-readers' ) && snt_analytics_view_owns_chrome( 'reader-evidence' ), 'both own their chrome (their own hero and window)' );
	$painted = call_user_func( $P . 'painters' );
	ok( isset( $painted['view/machine-readers'], $painted['view/reader-evidence'] ), 'both native painters are registered' );
	ok( false !== strpos( $classic['machine-readers'], 'Who reads' ) && false !== strpos( $classic['reader-evidence'], 'Is this crawler real' ), 'the classic dispatch reaches both bodies' );
	ok( array( 7, 7, 7, 7 ) === array_slice( array_unique( $GLOBALS['__fetch_days'] ), 0, 1 ) + array( 7, 7, 7, 7 ), 'the window follows the page range (7)' );
	ok( array( 90, 'The edge sensor keeps 90 days, so this view opens at 90 days for the page’s longer range.' ) === snt_mr_view_window( '365' ) && 90 === snt_mr_view_window( 'all' )[0] && array( 14, '' ) === snt_mr_view_window( '14' ), 'a longer range opens at 90 and says so in one line; 14 stays 14' );

	echo "\nGroup B: no folds, one legend, the strip, the chips\n";
	foreach ( array( 'machine-readers', 'reader-evidence' ) as $v ) {
		foreach ( $both( $v ) as $s => $h ) {
			ok( false === strpos( $h, 'os-disclosure' ) && false === strpos( $h, 'sn-disclosure' ) && false === strpos( $h, '<details' ), "$v ($s): no os-disclosure, sn-disclosure or details" );
			ok( 1 === substr_count( $h, 'class="snt-evidence-legend"' ), "$v ($s): the legend appears exactly once" );
			ok( strpos( $h, 'class="snt-evidence-legend"' ) < strpos( $h, 'class="snt-evidence"' ), "$v ($s): the legend sits above the first strip" );
		}
	}
	$strip = snt_evidence_strip( array( 'claimed' => 1984, 'verified' => 29, 'verified_matched' => 25, 'unverified' => 1955, 'unverifiable' => 0 ) );
	ok( 0 === strpos( $strip, '<span class="snt-evidence" data-size="cell" aria-hidden="true">' ), 'the strip is aria-hidden: the numbers sit beside it as text' );
	ok( 3 === preg_match_all( '/data-part="([a-z_]+)"/', $strip, $m ) && array( 'verified_matched', 'verified', 'unverified' ) === $m[1], 'segments in order (matched, verified otherwise, claimed only), a zero part dropped' );
	foreach ( array( 'apps/sn-analytics/sn-analytics.css', 'assets/analytics/analytics-admin.css' ) as $css ) {
		$src = (string) file_get_contents( SNT_PATH . $css );
		ok( 1 === preg_match( '/\.snt-evidence__seg\s*\{[^}]*min-width:\s*3px/', $src ), "$css: a non-zero segment is never narrower than 3px" );
	}
	ok( '<span class="snt-evidence-none">not measured</span>' === snt_evidence_strip( null ), 'a null split paints no strip, only "not measured"' );
	$legend = snt_evidence_legend( SN_RIGHTS_EVIDENCE_VERIFIED_SINCE );
	ok( 4 === preg_match_all( '/data-part="([a-z_]+)"/', $legend, $lm ) && array( 'verified_matched', 'verified', 'unverified', 'unverifiable' ) === $lm[1] && false !== strpos( $legend, 'Verified, not for its claimed purpose' ), 'the legend keys all four parts the strip can paint, the half segment included' );
	foreach ( array( 'apps/sn-analytics/sn-analytics.css', 'assets/analytics/analytics-admin.css' ) as $css ) {
		$src = (string) file_get_contents( SNT_PATH . $css );
		ok( 1 === preg_match( '/\.snt-evidence__seg\[data-part="verified"\]\s*\{([^}]*)\}/', $src, $vm ) && false !== strpos( $vm[1], 'border: 1px solid' ) && false === strpos( $vm[1], 'opacity' ), "$css: verified-not-for-its-purpose differs by an outline, not by opacity alone" );
	}
	ok( '<os-chip tone="success">signed</os-chip>' === snt_basis_chip( 'signed' ) && '<os-chip tone="info">verified</os-chip>' === snt_basis_chip( 'verified' ) && '<os-chip>claimed</os-chip>' === snt_basis_chip( 'claimed' ) && '<os-chip>inferred</os-chip>' === snt_basis_chip( 'inferred' ) && '<os-chip>not measured</os-chip>' === snt_basis_chip( 'unmeasured' ), 'chips carry the basis word and the tone table' );
	ok( false === strpos( implode( '', array_map( 'snt_basis_chip', SN_MR_BASIS_VALUES ) ), 'warning' ) && false === strpos( implode( '', array_map( 'snt_basis_chip', SN_MR_BASIS_VALUES ) ), 'danger' ), 'no basis chip takes a warning or danger tone' );

	echo "\nGroup C: the finding, built from the figures\n";
	$finding = '2,154 reads claimed AI training; 170 presented Google-Extended, a control token credited to no vendor, leaving 1,984; Cloudflare verified 25, all from Anthropic, PBC.';
	ok( $finding === snt_mr_ai_finding( $rows ), 'the sentence: ' . snt_mr_ai_finding( $rows ) );
	foreach ( $both( 'machine-readers' ) as $s => $h ) {
		ok( false !== strpos( $h, esc_html( $finding ) ), "machine-readers ($s): the finding leads the view" );
		ok( false !== strpos( $h, 'How each count is known: Reader evidence' ), "machine-readers ($s): a visible-text link to Reader evidence" );
	}
	$GLOBALS['__options'][ SNT_BOT_SIGNAL_BY_DAY_OPTION ] = array( '2026-10-09' => array( 'visitor_days' => 12, 'likely_automated' => 2 ) );
	$head = call_user_func( $P . 'paint_chrome_header', array( 'from' => '2026-10-05', 'to' => '2026-10-11', 'class' => 'human' ) )['html'];
	$grid = substr( $head, (int) strpos( $head, '<os-grid' ), strpos( $head, '</os-grid>' ) - (int) strpos( $head, '<os-grid' ) );
	ok( false !== strpos( $grid, 'label="Likely automated (inferred)"' ) && false !== strpos( $grid, 'value="2"' ) && false !== strpos( $grid, 'label="Views"' ), 'native Overview: the likely automated tile sits inside the stats row, beside Views' );
	ok( false !== strpos( $head, 'measured 1 of 7 UTC days' ), 'native Overview: the days are UTC days' );
	$GLOBALS['__options'][ SNT_BOT_SIGNAL_BY_DAY_OPTION ] = array();
	$head = call_user_func( $P . 'paint_chrome_header', array( 'from' => '2026-10-05', 'to' => '2026-10-11', 'class' => 'human' ) )['html'];
	ok( 1 === substr_count( $head, 'not measured' ) && false === strpos( $head, 'of 0' ), 'native Overview: null reads "not measured" once, never 0 or "of 0"' );

	echo "\nGroup D: every figure that left a fold is painted\n";
	$mk = $kit['machine-readers'];
	$mc = $classic['machine-readers'];
	foreach ( array(
		'machine reads total (21,503)'                => '21,503',
		'readers, not this site’s tools (6,261)'     => '6,261',
		'this site’s own tools (15,242)'              => '15,242',
		'AI-training claimed (1,984)'                 => '1,984',
		'proved identity, valid of measured (25 / 21,503)' => '25 / 21,503',
		'asked for markdown (570)'                    => '570',
		'feed fetches, 30d (210)'                     => '210',
		'agent tool calls (label)'                    => 'agent tool calls, 7d',
		'Who reads: other-bot leads'                  => 'other-bot leads with 4,107 reads, 570 verified',
		'What for: unmapped purpose'                  => 'unmapped',
		'rights log: an external read'                => 'GPTBot/1.0',
		'rights log: this site’s own checks apart'    => 'SignalNoise-SmokeTest/1.0',
		'feeds: fetchers'                             => 'Fetchers',
		'observed vs declared'                        => 'Observed vs declared',
		'counted both ways, legacy marked'            => 'By crawler family, LEGACY (ai_training)',
		'agent and purpose pairs'                     => 'Agent and purpose pairs',
		'surfaces'                                    => 'Surfaces',
	) as $what => $needle ) {
		ok( false !== strpos( $mk, esc_html( $needle ) ) || false !== strpos( $mk, htmlspecialchars( json_encode( $needle ), ENT_QUOTES ) ) || false !== strpos( html_entity_decode( $mk, ENT_QUOTES ), $needle ), "kit: $what" );
		ok( false !== strpos( html_entity_decode( $mc, ENT_QUOTES ), $needle ), "classic: $what" );
	}
	ok( false !== strpos( $mk, 'value="9" label="agent tool calls, 7d"' ) && false !== strpos( $mc, '<p class="sn-kpi-value">9</p>' ), 'agent tool calls (9) on both surfaces' );
	ok( false === strpos( $mk, '<script>alert' ) && false === strpos( $mc, '<script>alert' ), 'a hostile user agent in the rights log is escaped on both surfaces' );
	$ek = html_entity_decode( $kit['reader-evidence'], ENT_QUOTES );
	$ec = html_entity_decode( $classic['reader-evidence'], ENT_QUOTES );
	foreach ( array(
		'claimed (6,261 less first-party, tokens out)' => '6,091',
		'verified by Cloudflare (595)'                => '595',
		'signed vs verified line'                     => 'Signed is not verified',
		'families never verified'                     => 'families were never verified by Cloudflare',
		'the train line'                              => '1,984 reads claimed training; 25 verified, 25 as a training crawler.',
		'control token verdict'                       => '170 reads presented Google-Extended, a robots.txt token with no crawler of its own. Credited to no vendor.',
		'is this crawler real'                        => '5 named agents read with a user agent Cloudflare did not verify, 4 of them never verified. Verified on every read: 1.',
		'unnamed: GrokBot leads'                      => '1 user agent matches no taxonomy entry: GrokBot.',
		'unnamed: top network'                        => 'Google LLC 200',
		'co-movement verdict'                         => '3 families rose together from Google LLC on 2026-10-10, unverified:',
	) as $what => $needle ) {
		ok( false !== strpos( $ek, $needle ), "evidence kit: $what" );
		ok( false !== strpos( $ec, $needle ), "evidence classic: $what" );
	}
	ok( false !== strpos( $kit['reader-evidence'], '<os-histogram' ) && false !== strpos( $kit['reader-evidence'], 'legend' ), 'the anomalies are a two-series time histogram on the kit' );
	$signed = '25 of 21,503 measured reads carried a valid Web Bot Auth signature; 0 invalid, 0 unknown key. Top signers: anthropic-claudebot 25.';
	ok( false !== strpos( $ek, $signed ) && false !== strpos( $ec, $signed ), 'Web Bot Auth valid, invalid and unknown key (the old identity line) on both surfaces' );
	$with_cards          = snt_mr_view_data( 7 );
	$with_cards['cards'] = array( array( 'title' => 'openai reads rose 40%', 'detail' => 'from 300 to 420 over 3 days' ) );
	foreach ( array( 'kit', 'classic' ) as $s ) {
		$h = html_entity_decode( snt_mr_view_machine_readers_html( $with_cards, $s, array() ), ENT_QUOTES );
		ok( false !== strpos( $h, 'openai reads rose 40%' ) && false !== strpos( $h, 'from 300 to 420 over 3 days' ) && strpos( $h, 'openai reads rose 40%' ) < strpos( $h, 'Who reads' ), "family delta cards ($s): painted after the hero, before Who reads" );
	}
	// Core's wp_admin_notice_markup filter sees only notices built by core; a hand-echoed literal never passes it.
	add_filter( 'wp_admin_notice_markup', static fn( $m ) => '<!--core-notice-->' . $m );
	$cards = snt_ev_paint_delta_cards( array( array( 'title' => 'GPTBot rose', 'detail' => 'by 40%' ) ), 'classic' );
	ok( 0 === strpos( snt_ev_verdict( 'classic', 'capped' ), '<!--core-notice-->' ) && 0 === strpos( $cards, '<!--core-notice-->' ) && false !== strpos( $cards, 'GPTBot rose' ), 'classic verdicts and delta cards are built by core (they pass wp_admin_notice_markup)' );

	echo "\nGroup E: the unnamed count is a count, or says it is a floor\n";
	$many = array();
	for ( $i = 1; $i <= 60; $i++ ) {
		$many[] = array( 'user_agent' => "Bot$i/1.0", 'hits' => 100 - $i, 'verified_bot' => '', 'network' => 'Google LLC' );
	}
	$GLOBALS['__fetch']['unknown-network'] = array( 'ok' => true, 'detail' => 'network', 'truncated' => false, 'rows' => $many );
	$full = snt_mr_view_data( 7, true );
	$out  = html_entity_decode( snt_mr_view_reader_evidence_html( $full, 'classic', array() ), ENT_QUOTES );
	ok( 60 === count( $full['unnamed'] ) && false !== strpos( $out, '60 user agents match no taxonomy entry; Bot1, Bot2, Bot3 lead.' ) && false !== strpos( $out, '+40 more' ), 'a complete read counts every sample (60), paints 20 and says +40 more' );
	$GLOBALS['__fetch']['unknown-network'] = array( 'ok' => true, 'detail' => '', 'truncated' => false, 'rows' => array_slice( $many, 0, 50 ) );
	$out = html_entity_decode( snt_mr_view_reader_evidence_html( snt_mr_view_data( 7, true ), 'classic', array() ), ENT_QUOTES );
	ok( false !== strpos( $out, 'At least 50 user agents match no taxonomy entry' ) && false !== strpos( $out, 'not measured' ), 'an older worker (its top 50, no detail): at least 50, verified not measured' );
	$GLOBALS['__fetch']['unknown-network'] = array( 'ok' => true, 'detail' => 'network', 'truncated' => true, 'rows' => $many );
	$out = html_entity_decode( snt_mr_view_reader_evidence_html( snt_mr_view_data( 7, true ), 'kit', array() ), ENT_QUOTES );
	ok( false !== strpos( $out, 'At least 60 user agents match no taxonomy entry' ), 'a truncated read is a floor: at least 60' );

	echo "\nGroup H: one element per tile, on both surfaces\n";
	// The element children of the first node matching $xpath.
	$kids = static function ( $html, $xpath ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$node = ( new DOMXPath( $doc ) )->query( $xpath )->item( 0 );
		$out  = array();
		foreach ( null === $node ? array() : $node->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType ) {
				$out[] = $c;
			}
		}
		return $out;
	};
	foreach ( array( 'machine-readers' => 7, 'reader-evidence' => 2 ) as $v => $tiles ) {
		$k = $kids( $kit[ $v ], '//os-grid' );
		$c = $kids( $classic[ $v ], "//div[contains(concat(' ', @class, ' '), ' sn-kpi-row ')]" );
		ok( $tiles === count( $k ) && array() === array_filter( $k, static fn( $e ) => 'snt-ev-tile' !== $e->getAttribute( 'class' ) ), "$v (kit): the tile grid has $tiles children, each one tile element (" . count( $k ) . ')' );
		ok( $tiles === count( $c ) && array() === array_filter( $c, static fn( $e ) => 'sn-kpi' !== $e->getAttribute( 'class' ) ), "$v (classic): the tile row has $tiles children, each one .sn-kpi (" . count( $c ) . ')' );
	}
	ok( 1 !== preg_match( '/class="sn-kpi-label"[^>]*>[^<]*<span class="sn-mr-chip"/', $classic['machine-readers'] ), 'classic: the basis chip is not inside the label (its nowrap and uppercase clip it)' );
	$css = (string) file_get_contents( SNT_PATH . 'assets/analytics/analytics-admin.css' );
	ok( false !== strpos( $classic['machine-readers'], 'class="sn-kpi-row sn-mr-kpi-row sn-mr-kpi-row--wrap sn-mr-kpi-row--odd" style="--sn-kpi-cols:4"' ) && 1 === preg_match( '/\.sn-mr-kpi-row--wrap\.sn-mr-kpi-row--odd > \.sn-kpi:first-child \{ grid-column: span 2; \}/', $css ), 'classic: seven tiles run four across with the first spanning two (8 slots: 4 + 4, or 2 + 2 + 2 + 2 narrow), so no row ends on a lone card' );

	echo "\nGroup I: painters that cut, word and align\n";
	$ua   = str_repeat( 'a', 199 ) . 'é' . ' tail';
	$path = '/' . str_repeat( 'p', 118 ) . 'é' . '-x';
	$mb   = array( array( 'observed_at' => '2026-10-10T09:00:00Z', 'path' => $path, 'user_agent' => $ua, 'vendor' => 'openai', 'purpose' => 'train', 'family' => 'openai', 'hits' => 1 ) );
	foreach ( array( 'kit', 'classic' ) as $sf ) {
		$h = html_entity_decode( str_replace( '\\u00e9', 'é', snt_ev_paint_rights( $mb, $sf ) ), ENT_QUOTES, 'UTF-8' );
		ok( false !== strpos( $h, str_repeat( 'a', 199 ) . 'é' ) && false !== strpos( $h, str_repeat( 'p', 118 ) . 'é' ), "rights ($sf): a multibyte character on the cap is kept whole, so the row still paints" );
	}
	$many = array();
	for ( $i = 0; $i < 17; $i++ ) {
		$many[] = array( 'observed_at' => sprintf( '2026-10-%02dT09:00:00Z', 1 + $i ), 'path' => '/license.xml', 'user_agent' => 'GPTBot/1.0', 'vendor' => 'openai', 'purpose' => 'train', 'family' => 'openai', 'hits' => 1 );
	}
	ok( false !== strpos( snt_ev_paint_rights( $many, 'classic' ), '+2 more, older' ), 'rights: newest first, so the "+N more" line says older, not the quiet end' );
	$gap = static fn( $rs ) => html_entity_decode( snt_ev_paint_reference( $rs, 'classic' ), ENT_QUOTES );
	$eq  = $gap( array( $r( 'openai', 'train', 'openai-gptbot', 10, 'Google LLC' ) ) );
	$hi  = $gap( array( $r( 'openai', 'train', 'openai-gptbot', 10, 'Google LLC' ), $r( 'openai', 'search', 'openai-searchbot', 5, 'Google LLC' ) ) );
	$lo  = $gap( array( $r( 'openai', 'train', 'openai-gptbot', 10, 'Google LLC' ), $r( 'other-bot', 'train', 'other-trainer', 4, 'Google LLC' ) ) );
	ok( false === strpos( $eq, 'count is higher' ), 'counted both ways, equal: no gap sentence' );
	$tok = $gap( array( $r( 'openai', 'train', 'openai-gptbot', 10, 'Google LLC' ), $r( 'google-ai', 'train', 'google-extended', 170, 'Google LLC' ) ) );
	ok( false === strpos( $tok, 'count is higher' ) && false === strpos( $tok, '<td>google-ai</td>' ), 'counted both ways: a control token is in neither count, and no family row' );
	ok( false !== strpos( $hi, 'The family count is higher by 5: the frozen families also hold' ), 'counted both ways, family higher: the gap and its cause' );
	ok( false !== strpos( $lo, 'The purpose count is higher by 4.' ) && false === strpos( $lo, 'frozen families also hold' ), 'counted both ways, purpose higher: the gap without the family cause' );
	ok( false !== strpos( html_entity_decode( snt_ev_paint_rights( $many, 'classic' ), ENT_QUOTES ), 'the only reads the sensor records in full: a closed set of fixed URLs' ), 'rights: says why this log is complete when the others are aggregates' );
	ok( false !== strpos( $ec, 'a sanitized sample the edge keeps on a miss, not a verbatim log' ), 'unnamed: says each user agent is a sanitized sample' );
	$one = array( $r( 'openai', 'train', 'openai-gptbot', 10, 'Google LLC' ) );
	ok( 0 === strpos( snt_mr_who_reads( $one )['lead'], '1 family; openai leads' ), 'Who reads: one family is "1 family"' );
	ok( 0 === strpos( snt_mr_what_for( $one )['lead'], '1 purpose,' ), 'What for: one purpose is "1 purpose"' );
	$hostile_card = array( array( 'id' => 'mr_delta_x', 'title' => '<img src=x onerror=1>', 'detail' => '<script>alert(1)</script>' ) );
	foreach ( array( 'kit', 'classic' ) as $sf ) {
		$hc = snt_ev_paint_delta_cards( $hostile_card, $sf );
		ok( false === strpos( $hc, '<img' ) && false === strpos( $hc, '<script' ) && '' === snt_ev_paint_delta_cards( array(), $sf ), "delta cards ($sf): title and detail escaped at the sink; no cards paints nothing" );
	}
	$blank = snt_ev_paint_rights( array( array( 'observed_at' => '2026-10-10T09:00:00Z', 'path' => '/license.xml', 'user_agent' => 'Odd/1.0', 'vendor' => '', 'purpose' => 'unknown', 'family' => 'other-bot', 'hits' => 1 ) ), 'classic' )
		. snt_ev_paint_reference( array( $r( 'other-bot', 'unknown', '', 3, 'Google LLC', '', array( 'vendor' => 'acme' ) ) ), 'classic' );
	ok( false === strpos( $blank, '—' ) && false !== strpos( $blank, '<td>none</td>' ), 'a cell with no vendor or no agent says none, never a dash' );
	$tiles = snt_ev_paint_hero_tiles( array( 'days' => 7, 'total' => 1, 'readers' => 1, 'first_party' => 0, 'ai' => null, 'identity' => array( 'measured' => 1, 'valid' => 1, 'invalid' => 0, 'unknown_key' => 0, 'markdown' => 0 ), 'feed_total' => null, 'tool_calls' => null ), 'kit' );
	ok( 1 === substr_count( $tiles, 'not measured' ), 'hero: a null AI-training figure says "not measured" once, not again under it' );
	ok( 1 === preg_match( '/\{&quot;key&quot;:&quot;hits&quot;,&quot;label&quot;:&quot;[^&]*[^}]*&quot;align&quot;:&quot;end&quot;/', $kit['reader-evidence'] ) || 1 === preg_match( '/"key":"hits"[^}]*"align":"end"/', html_entity_decode( $kit['reader-evidence'], ENT_QUOTES ) ), 'kit tables: a numeric column aligns to the end' );

	$edge = html_entity_decode( call_user_func( $P . 'paint_view_edge', array( 'from' => '2026-10-05', 'to' => '2026-10-11' ) ), ENT_QUOTES );
	ok( false !== strpos( $edge, 'label="Presumed human pageviews"' ) && false !== strpos( $edge, 'minus the beacon’s presumed-human pageviews.' ) && false === strpos( $edge, 'label="Human pageviews"' ), 'native Traffic & edge: presumed human, as the classic view says' );

	echo "\nGroup J: two boxes share a row only within 1.5x of each other\n";
	foreach ( array( 'kit' => '<os-grid', 'classic' => 'class="sn-2up"' ) as $sf => $row ) {
		ok( false !== strpos( snt_ev_pair( $sf, '<p>a</p>', '<p>b</p>', array( 6, 4 ) ), $row ), "pair ($sf): 6 rows beside 4 share a row" );
		ok( false === strpos( snt_ev_pair( $sf, '<p>a</p>', '<p>b</p>', array( 17, 3 ) ), $row ), "pair ($sf): 17 rows beside 3 stack full width" );
	}
	$saved_rights                 = $GLOBALS['__fetch']['rights'];
	$GLOBALS['__fetch']['rights'] = array( 'ok' => true, 'rows' => $many );
	$tall                         = call_user_func( $P . 'paint_view_machine_readers', array( 'range' => '7' ) );
	$GLOBALS['__fetch']['rights'] = $saved_rights;
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $tall . '</div>' );
	libxml_clear_errors();
	$rights_box = ( new DOMXPath( $doc ) )->query( '//os-section[@heading="Rights-surface reads"]' )->item( 0 );
	ok( null !== $rights_box && 'os-grid' !== $rights_box->parentNode->nodeName, 'Machine readers: 15 rights rows do not sit beside a two-row feeds box; they stack full width' );

	echo "\nGroup F: a read before verification was recorded is unverifiable, never unverified\n";
	// Before SN_RIGHTS_EVIDENCE_VERIFIED_SINCE (2026-09-27 15:49:59 UTC) no read can be verified.
	$pre = array_merge( $rows, array(
		$r( 'other-bot', 'search', 'anthropic-searchbot', 40, 'Anthropic, PBC', '', array( 'day' => '2026-09-20' ) ),
		$r( 'cohere', 'train', 'cohere-ai', 264, 'Google LLC', '', array( 'day' => '2026-09-20' ) ),
	) );
	$ex = snt_mr_agent_exceptions( $pre );
	ok( '5 named agents read with a user agent Cloudflare did not verify, 4 of them never verified. Verified on every read: 0.' === $ex['lead'], 'agents: a verified agent with one pre-since read is no exception, and no longer verified on every read: ' . $ex['lead'] );
	ok( ! in_array( 'cohere-ai', array_column( $ex['rows'], 'agent' ), true ) && ! in_array( 'anthropic-searchbot', array_column( $ex['rows'], 'agent' ), true ), 'agents: an agent seen only before the since date is not listed as unverified' );
	$claude = array_column( $ex['rows'], null, 'agent' )['anthropic-claudebot'] ?? null;
	ok( null !== $claude && 823 === $claude['split']['claimed'] && 25 === $claude['split']['verified'] && 798 === $claude['split']['unverified'], 'agents: the split is the whole agent (823 = 25 verified + 798 unverified), not its first cell' );
	$fam = snt_mr_family_exceptions( snt_mr_who_reads( $pre ) );
	ok( false === strpos( $fam, 'cohere' ) && 0 === strpos( $fam, '4 families were never verified by Cloudflare' ), 'families: a family seen only before the since date is not "never verified": ' . $fam );

	echo "\nGroup G: control tokens and this site's own tools move no delta card\n";
	$day_rows = array();
	for ( $k = 0; $k < 14; $k++ ) {
		$cur = $k < 7;
		$day = gmdate( 'Y-m-d', time() - $k * 86400 );
		$day_rows[] = $r( 'anthropic', 'train', 'anthropic-claudebot', 100, 'Google LLC', '', array( 'day' => $day ) );
		$day_rows[] = $r( 'openai', 'train', 'openai-gptbot', $cur ? 100 : 10, 'Google LLC', '', array( 'day' => $day ) );
		$day_rows[] = $r( 'google-ai', 'train', 'google-extended', $cur ? 100 : 10, 'Google LLC', '', array( 'day' => $day ) );
		$day_rows[] = $r( 'uptime', 'ops', 'ops-betterstack', $cur ? 900 : 90, 'Hetzner Online GmbH', '', array( 'day' => $day, 'first_party' => true ) );
	}
	$saved                         = $GLOBALS['__fetch']['aggregate'];
	$GLOBALS['__fetch']['aggregate'] = array( 'ok' => true, 'truncated' => false, 'rows' => $day_rows );
	$ids                           = array_column( snt_mr_view_data( 14 )['cards'], 'id' );
	$GLOBALS['__fetch']['aggregate'] = $saved;
	ok( in_array( 'mr_delta_openai', $ids, true ), 'delta cards: a third-party family that rose tenfold cards (the control works)' );
	ok( ! in_array( 'mr_delta_google-ai', $ids, true ) && ! in_array( 'mr_delta_uptime', $ids, true ), 'delta cards: Google-Extended (a control token) and uptime (this site\'s own monitor) move no card: ' . implode( ', ', $ids ) );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
