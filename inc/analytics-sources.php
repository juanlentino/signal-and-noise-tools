<?php
/**
 * Signal & Noise — referrer host → canonical traffic source (read-side fold).
 *
 * The dims table stores the raw referrer host the worker captured (blob3). That
 * raw host fragments a single source three ways the dashboard shouldn't show:
 *
 *   1. Self-referrals — your own domain appearing as a referrer (edge-cached
 *      cross-page nav). Every analytics convention folds these into Direct.
 *   2. www variants    — www.facebook.com vs facebook.com are the same source.
 *   3. Multi-host brands — google.com / news.google.com / the Gmail app uri are
 *      all "Google"; m./l./lm.facebook.com are all "Facebook".
 *
 * This is a PURE READ-TIME fold: no AE query, no new table, no Worker change. The
 * raw host stays in AE/dims (drill-downs + the categories panel still need it),
 * and the mapping can evolve without re-ingesting. It is the single source of
 * truth for both the brand label (Top sources) and the category split
 * (Search / AI assistants / Social / Direct / Other) —
 * sn_analytics_referrer_category() delegates here, so the two can never drift.
 *
 * THE 'ai' CATEGORY IS THE HUMAN SEGMENT (R2B): a READER an assistant sent,
 * counted like any other referral. It is deliberately NOT the crawler
 * taxonomy (inc/machine-readers-taxonomy.php) and this host list must never
 * be reused as an "is this an AI request?" predicate — an allowlist inverts
 * under reuse, and R3's give-back ratio depends on this being the humans.
 *
 * @package SignalNoiseTools
 * @since 6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's own host(s) — home_url host + its www variant, lowercased. Mirrors
 * the self-referral exclusion sn_analytics_pageroles_rollup_sql() applies at the
 * AE layer. Filterable for extra owned domains. Cached per request.
 *
 * @return array<int, string>
 */
function sn_analytics_self_hosts() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$hosts = array();
	$home  = function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) : '';
	if ( '' !== $home ) {
		$bare           = preg_replace( '/^www\./', '', $home );
		$hosts[ $bare ] = true;
	}
	$hosts = array_keys( $hosts );
	if ( function_exists( 'apply_filters' ) ) {
		$hosts = (array) apply_filters( 'sn_analytics_self_hosts', $hosts );
	}
	$cache = array_values( array_unique( array_map( 'sn_analytics_normalize_host', $hosts ) ) );
	return $cache;
}

/**
 * A referrer value → bare canonical host: lowercased, scheme/path/query/fragment
 * dropped, a single leading `www.` removed. The '(direct)'/'(unknown)' sentinels
 * (and '') pass through unchanged so callers can detect them.
 *
 * @param string $host Referrer host, URL, or sentinel.
 * @return string
 */
function sn_analytics_normalize_host( $host ) {
	$h = strtolower( trim( (string) $host ) );
	if ( '' === $h || '(direct)' === $h || '(unknown)' === $h ) {
		return $h;
	}
	$pos = strpos( $h, '://' );
	if ( false !== $pos ) {
		$h = substr( $h, $pos + 3 );
	}
	$h = preg_replace( '~[/?#].*$~', '', $h ); // strip path/query/fragment → host
	$h = preg_replace( '/^www\./', '', $h );    // strip a single leading www.
	return (string) $h;
}

/**
 * Ordered brand rules: first match wins, `exact` before `contains` within a rule.
 * Short ambiguous shorteners match EXACTLY (so 'first.co' can't match 't.co');
 * branded hosts match as substrings so subdomains resolve. Each rule carries the
 * 4-way category, so source_category_of_label() needs no second list. Reproduces
 * (and extends) the legacy sn_analytics_referrer_category() vocabulary.
 *
 * @return array<int, array{label:string, cat:string, exact:array<int,string>, contains:array<int,string>}>
 */
function sn_analytics_source_rules() {
	$r = static function ( $label, $cat, $exact, $contains ) {
		return array( 'label' => $label, 'cat' => $cat, 'exact' => $exact, 'contains' => $contains );
	};
	return array(
		// ── AI assistants — readers referred by an assistant's answer (R2B).
		// ORDER-CRITICAL: this block sits BEFORE Search because first match
		// wins and 'gemini.google' must claim gemini.google.com before the
		// generic 'google.' needle folds it into Google.
		$r( 'ChatGPT',    'ai', array(), array( 'chatgpt.com', 'chat.openai' ) ),
		$r( 'Claude',     'ai', array(), array( 'claude.ai' ) ),
		$r( 'Perplexity', 'ai', array(), array( 'perplexity.' ) ),
		$r( 'Gemini',     'ai', array(), array( 'gemini.google' ) ),
		$r( 'Copilot',    'ai', array(), array( 'copilot.microsoft' ) ),
		$r( 'DeepSeek',   'ai', array(), array( 'deepseek.' ) ),
		$r( 'Le Chat',    'ai', array(), array( 'chat.mistral' ) ),
		$r( 'Grok',       'ai', array( 'grok.com' ), array( 'grok.x.ai' ) ),
		$r( 'Meta AI',    'ai', array( 'meta.ai' ), array() ),
		// ── Search.
		$r( 'Google',     'search', array(), array( 'google.', 'com.google.android.gm' ) ),
		$r( 'Bing',       'search', array(), array( 'bing.' ) ),
		$r( 'DuckDuckGo', 'search', array(), array( 'duckduckgo' ) ),
		$r( 'Yahoo',      'search', array(), array( 'yahoo.' ) ),
		$r( 'Yandex',     'search', array(), array( 'yandex.' ) ),
		$r( 'Baidu',      'search', array(), array( 'baidu.' ) ),
		$r( 'Ecosia',     'search', array(), array( 'ecosia.' ) ),
		$r( 'Startpage',  'search', array(), array( 'startpage.' ) ),
		$r( 'Brave',      'search', array(), array( 'search.brave' ) ),
		$r( 'Qwant',      'search', array(), array( 'qwant.' ) ),
		$r( 'Searx',      'search', array(), array( 'searx' ) ),
		// ── Social + communities.
		$r( 'X',           'social', array( 't.co', 'x.com' ), array( 'twitter.com' ) ),
		$r( 'Facebook',    'social', array( 'fb.me' ),         array( 'facebook.' ) ),
		$r( 'Instagram',   'social', array(),                  array( 'instagram.' ) ),
		$r( 'LinkedIn',    'social', array( 'lnkd.in' ),       array( 'linkedin.' ) ),
		$r( 'Reddit',      'social', array( 'redd.it' ),       array( 'reddit.' ) ),
		$r( 'Hacker News', 'social', array(),                  array( 'ycombinator' ) ),
		$r( 'Lobsters',    'social', array(),                  array( 'lobste.rs' ) ),
		$r( 'YouTube',     'social', array( 'youtu.be' ),      array( 'youtube.' ) ),
		$r( 'Mastodon',    'social', array(),                  array( 'mastodon' ) ),
		$r( 'Bluesky',     'social', array(),                  array( 'bsky.', 'bluesky' ) ),
		$r( 'Threads',     'social', array(),                  array( 'threads.net' ) ),
		$r( 'TikTok',      'social', array(),                  array( 'tiktok.' ) ),
		$r( 'Pinterest',   'social', array(),                  array( 'pinterest.' ) ),
		$r( 'Telegram',    'social', array( 't.me' ),          array( 'telegram' ) ),
		$r( 'Buffer',      'social', array( 'buff.ly' ),       array() ),
		$r( 'dlvr.it',     'social', array( 'dlvr.it' ),       array() ),
		$r( 'Substack',    'social', array(),                  array( 'substack.com' ) ),
		$r( 'Medium',      'social', array(),                  array( 'medium.com' ) ),
	);
}

/**
 * Referrer value → canonical source label. Self-referrals + empty/sentinel →
 * '(direct)'; a known brand → its label; an unknown host → its bare (normalized)
 * host. $self_hosts defaults to sn_analytics_self_hosts() (pass explicitly to test
 * without WP).
 *
 * @param string                    $host       Raw referrer value.
 * @param array<int,string>|null    $self_hosts Own hosts to fold to (direct).
 * @return string
 */
function sn_analytics_canonical_source( $host, $self_hosts = null ) {
	$h = sn_analytics_normalize_host( $host );
	if ( null === $self_hosts ) {
		$self_hosts = function_exists( 'sn_analytics_self_hosts' ) ? sn_analytics_self_hosts() : array();
	}
	$self = array_map( 'sn_analytics_normalize_host', (array) $self_hosts );
	if ( '' === $h || '(direct)' === $h || '(unknown)' === $h || in_array( $h, $self, true ) ) {
		return '(direct)';
	}
	foreach ( sn_analytics_source_rules() as $rule ) {
		if ( in_array( $h, $rule['exact'], true ) ) {
			return $rule['label'];
		}
		foreach ( $rule['contains'] as $needle ) {
			if ( sn_analytics_host_label_match( $h, $needle ) ) {
				return $rule['label'];
			}
		}
	}
	return $h;
}

/**
 * Boundary-aware host substring: the needle must sit at a domain-label boundary —
 * at the START of the host, or immediately after a dot. So 'facebook.' matches
 * 'facebook.com' and 'm.facebook.com' but NOT 'notfacebook.com'; 'ycombinator'
 * matches 'news.ycombinator.com' but not 'fakeycombinator.example'. Without this a
 * fragment of an unrelated domain would be mis-branded (a plain strpos matches any
 * position). $needle is a lowercase host fragment from sn_analytics_source_rules().
 *
 * @param string $host   Normalized (lowercased, www-stripped) host.
 * @param string $needle Lowercase host fragment.
 * @return bool
 */
function sn_analytics_host_label_match( $host, $needle ) {
	if ( '' === $needle ) {
		return false;
	}
	return 0 === strpos( $host, $needle ) || false !== strpos( $host, '.' . $needle );
}

/**
 * Canonical source label → category (search|ai|social|direct|other). '(direct)'
 * is direct; a known brand carries its rule's category; anything else (a bare
 * unknown host) is 'other'.
 *
 * @param string $label
 * @return string
 */
function sn_analytics_source_category_of_label( $label ) {
	if ( '(direct)' === $label ) {
		return 'direct';
	}
	foreach ( sn_analytics_source_rules() as $rule ) {
		if ( $rule['label'] === (string) $label ) {
			return $rule['cat'];
		}
	}
	return 'other';
}

/**
 * Top traffic sources for [from,to]/class, brand-folded. Reads a WIDE raw top-N
 * (so a fold can't drop a row that should rank), merges views/visits by canonical
 * label, records each label's member hosts (for the drill-down), sorts by views,
 * and slices to $limit. '(direct)' never carries member hosts — it aggregates but
 * is not drillable.
 *
 * Contract (v9.68.1): null = the underlying dims read FAILED (propagated —
 * a database failure must not fold into an empty source list); [] = an empty
 * window, which is an ANSWER.
 *
 * @param string $from  YYYY-MM-DD.
 * @param string $to    YYYY-MM-DD.
 * @param string $class Traffic class.
 * @param int    $limit Max rows (1..500).
 * @return array<int, array{value:string, views:int, visits:int, hosts:array<int,string>}>|null
 */
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) {
	$raw = function_exists( 'sn_analytics_top_dimension' )
		? sn_analytics_top_dimension( 'referrer', $from, $to, $class, 500 )
		: array();
	if ( null === $raw ) {
		return null; // v9.68.1: the accessor's failed-read verdict propagates.
	}
	$self = function_exists( 'sn_analytics_self_hosts' ) ? sn_analytics_self_hosts() : array();

	$acc = array();
	foreach ( (array) $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$value = (string) ( $row['value'] ?? '' );
		$label = sn_analytics_canonical_source( $value, $self );
		if ( ! isset( $acc[ $label ] ) ) {
			$acc[ $label ] = array( 'views' => 0, 'visits' => 0, 'hosts' => array() );
		}
		$acc[ $label ]['views']  += (int) ( $row['views'] ?? 0 );
		$acc[ $label ]['visits'] += (int) ( $row['visits'] ?? 0 );

		// Member host = the value EXACTLY as stored (lowercased only — we do NOT
		// www-strip it ourselves). The drill-down + sparkline series query AE/the dims
		// table by the literal blob3 that was written, and that literal differs by era:
		// historical + imported rows may keep 'www.' while the worker (v1.6.0+) writes
		// it www-stripped. Preserving whatever was stored is what lets the IN-set match
		// BOTH 'www.facebook.com' and 'facebook.com' even though they DISPLAY as one
		// "Facebook".
		$raw         = strtolower( trim( $value ) );
		$is_sentinel = ( '' === $raw || '(direct)' === $raw || '(unknown)' === $raw );
		if ( '(direct)' !== $label && ! $is_sentinel ) {
			$acc[ $label ]['hosts'][ $raw ] = true;
		}
	}

	$out = array();
	foreach ( $acc as $label => $a ) {
		$out[] = array(
			'value'  => (string) $label,
			'views'  => (int) $a['views'],
			'visits' => (int) $a['visits'],
			'hosts'  => array_keys( $a['hosts'] ),
		);
	}
	usort( $out, static function ( $a, $b ) {
		return $b['views'] <=> $a['views'];
	} );
	return array_slice( $out, 0, max( 1, min( 500, (int) $limit ) ) );
}

/**
 * The member hosts behind one canonical source label, for the current window —
 * the drill-down resolves a clicked brand to its raw blob3 hosts. Returns [] for
 * '(direct)' and for any label not in the current top sources (the whitelist).
 *
 * @param string $label Canonical source label (untrusted — validated here).
 * @param string $from  YYYY-MM-DD.
 * @param string $to    YYYY-MM-DD.
 * @param string $class Traffic class.
 * @return array<int, string>
 */
function sn_analytics_source_hosts( $label, $from, $to, $class = 'human' ) {
	$rows = sn_analytics_top_sources( $from, $to, $class, 500 );
	if ( ! is_array( $rows ) ) {
		// v9.68.1 fail-CLOSED: on a failed read the whitelist cannot be
		// verified, so no label resolves — the drill-down rejects (its own
		// null/empty state) rather than querying AE on a guessed host set.
		return array();
	}
	foreach ( $rows as $row ) {
		if ( (string) $row['value'] === (string) $label ) {
			return $row['hosts'];
		}
	}
	return array();
}

/**
 * Label-keyed trend series for a folded top-sources set: fetches the per-host
 * series for every member host, then sums them per (label, day). A label with no
 * member hosts (i.e. '(direct)') gets no series. Mirrors the [{day,views}] shape
 * sn_analytics_dimension_series() returns so the sparkline renderer is unchanged.
 *
 * @param array  $rows        Output of sn_analytics_top_sources().
 * @param string $from        YYYY-MM-DD.
 * @param string $to          YYYY-MM-DD.
 * @param string $class       Traffic class.
 * @param string $granularity 'day' | 'week' | 'month'.
 * @return array<string, array<int, array{day:string, views:int}>>
 */
function sn_analytics_top_sources_series( $rows, $from, $to, $class = 'human', $granularity = 'day' ) {
	if ( ! function_exists( 'sn_analytics_dimension_series' ) ) {
		return array();
	}
	$all_hosts   = array();
	$label_hosts = array();
	foreach ( (array) $rows as $row ) {
		$hosts                          = isset( $row['hosts'] ) ? (array) $row['hosts'] : array();
		$label_hosts[ $row['value'] ]   = $hosts;
		foreach ( $hosts as $h ) {
			$all_hosts[] = $h;
		}
	}
	if ( empty( $all_hosts ) ) {
		return array();
	}
	$host_series = sn_analytics_dimension_series( 'referrer', array_values( array_unique( $all_hosts ) ), $from, $to, $class, $granularity );

	$out = array();
	foreach ( $label_hosts as $label => $hosts ) {
		$by_day = array();
		foreach ( $hosts as $h ) {
			foreach ( (array) ( $host_series[ $h ] ?? array() ) as $pt ) {
				$day            = (string) ( $pt['day'] ?? '' );
				$by_day[ $day ] = ( $by_day[ $day ] ?? 0 ) + (int) ( $pt['views'] ?? 0 );
			}
		}
		if ( empty( $by_day ) ) {
			continue;
		}
		ksort( $by_day );
		$series = array();
		foreach ( $by_day as $day => $views ) {
			$series[] = array( 'day' => $day, 'views' => $views );
		}
		$out[ $label ] = $series;
	}
	return $out;
}
