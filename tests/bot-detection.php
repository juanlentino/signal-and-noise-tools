<?php
/**
 * Standalone fixture test for sn_rss_tracker_is_bot().
 *
 * Runs without WordPress, PHPUnit, or composer — just bare PHP. Intended
 * to be runnable on a workstation that has `php` in PATH:
 *
 *     php tests/bot-detection.php
 *
 * Exits 0 on all-pass, 1 on any failure. The pre-merge bug (an earlier
 * revision's regex included substring `fetch`, which incorrectly filtered
 * Feedly's "FeedFetcher-Google" UA) is the first fixture: regression
 * coverage so it can't quietly come back.
 *
 * Moved from theme repo's mu-plugins/tests/ to plugin tests/ in
 * signal-and-noise-tools v1.1.0.
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would either bootstrap WordPress
// (contracts-smoke.php) or leak internal structure (all others). Allow only
// CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

// The plugin module file gates itself with `if (! defined('ABSPATH')) exit;` —
// stub ABSPATH so the require_once below passes that gate.
define( 'ABSPATH', '/' );

// Top-level add_action() calls in the plugin will fatal without WP.
// One trivial stub is all we need to load the file for function access.
function add_action() {}

require_once __DIR__ . '/../inc/rss-feed-tracker.php';

/**
 * Fixtures: [ short_name, user_agent, expected_is_bot ].
 *
 * The "should-pass" block covers the realistic set of feed aggregators
 * and modern browsers — any change to the regex that breaks one of
 * these is a real subscriber-counting regression. The "should-filter"
 * block covers crawlers and machine clients we never want to count.
 */
$fixtures = array(
	// ─── Aggregators we want to COUNT (expected: NOT a bot) ──────────────
	array( 'Feedly',         'Feedly/1.0 (+http://www.feedly.com/fetcher.html; like FeedFetcher-Google)',     false ),
	array( 'NewsBlur',       'NewsBlur Page Fetcher/1.0 (https://www.newsblur.com/site/123/...)',             false ),
	array( 'Inoreader',      'Mozilla/5.0 (compatible; Inoreader/1.0; +http://www.inoreader.com/feed-fetcher)', false ),
	array( 'NetNewsWire',    'NetNewsWire/6.1.2 (Macintosh; Mac OS X 14.1; en)',                              false ),
	array( 'Reeder',         'Reeder/5.2 (Macintosh)',                                                         false ),
	array( 'Tiny Tiny RSS',  'Tiny Tiny RSS/22.05 (Unsupported) (https://tt-rss.org/)',                       false ),
	array( 'Miniflux',       'Mozilla/5.0 (compatible; Miniflux/2.0.43; +https://miniflux.app)',              false ),
	array( 'FreshRSS',       'FreshRSS/1.20.0 (Linux; https://freshrss.org)',                                  false ),
	array( 'BazQux',         'BazQux/2.5 (+https://bazqux.com/fb)',                                            false ),
	array( 'The Old Reader', 'Mozilla/5.0 (compatible; theoldreader.com; 12 subscribers)',                     false ),

	// ─── Browsers we want to COUNT (expected: NOT a bot) ─────────────────
	array( 'Safari',  'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',                false ),
	array( 'Chrome',  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',             false ),
	array( 'Firefox', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:121.0) Gecko/20100101 Firefox/121.0',                                                false ),

	// ─── Crawlers / monitors / CLIs we want to FILTER (expected: bot) ────
	array( 'Empty UA',          '',                                                                                                                       true ),
	array( 'Googlebot',         'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',                                               true ),
	array( 'Bingbot',           'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',                                                true ),
	array( 'YandexBot',         'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',                                                       true ),
	array( 'Applebot',          'Mozilla/5.0 (compatible; Applebot/0.1; +http://www.apple.com/go/applebot)',                                              true ),
	array( 'DuckDuckBot',       'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)',                                                             true ),
	array( 'Twitterbot',        'Twitterbot/1.0',                                                                                                          true ),
	array( 'FacebookExternalHit', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',                                            true ),
	array( 'LinkedInBot',       'LinkedInBot/1.0 (compatible; Mozilla/5.0; +https://www.linkedin.com)',                                                   true ),
	array( 'AhrefsBot',         'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',                                                     true ),
	array( 'SemrushBot',        'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',                                            true ),
	array( 'UptimeRobot',       'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',                                                 true ),
	array( 'Pingdom',           'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)',                                                                  true ),
	array( 'StatusCake',        'Mozilla/5.0 (compatible; StatusCake)',                                                                                    true ),
	array( 'curl',              'curl/8.4.0',                                                                                                              true ),
	array( 'wget',              'Wget/1.21.4',                                                                                                             true ),
	array( 'python-requests',   'python-requests/2.31.0',                                                                                                  true ),
	array( 'Go HTTP client',    'Go-http-client/1.1',                                                                                                      true ),
	array( 'HTTPie',            'HTTPie/3.2.2',                                                                                                            true ),
	array( 'Java',              'Java/17.0.9',                                                                                                             true ),

	// ─── Better Stack probes (v8.1.6, BetterStack migration) — never count ─
	// Both UA generations (betterstack.com/docs/uptime FAQ, verified
	// 2026-07-02) contain "Bot", so the pattern's bare `bot` alternative
	// covers them. These pins keep that coverage deliberate: a future
	// "tighten the bot token" cleanup (e.g. the worker-style `bot\/`)
	// would flip these fixtures red.
	array( 'Better Stack (current)', 'Better Stack Better Uptime Bot Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', true ),
	array( 'Better Uptime (legacy)', 'Better Uptime Bot Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/74.0.3729.169 Safari/537.36',          true ),

	// ─── Fediverse fetchers (v8.1.3, ActivityPub adoption) — never count ─
	array( 'Mastodon',          'http.rb/5.1.1 (Mastodon/4.2.10; +https://mastodon.social/)',                                                             true ),
	array( 'http.rb',           'http.rb/5.2.0',                                                                                                           true ),
	array( 'Pleroma',           'Pleroma 2.7.0; https://pleroma.example/users/juan',                                                                       true ),
	array( 'Akkoma',            'Akkoma 3.13.2; https://akkoma.example',                                                                                   true ),
	array( 'Misskey',           'Misskey/2024.5.0 (https://misskey.io/)',                                                                                  true ),
	array( 'Friendica',         'Friendica (Yellow Archangel) 2024.03-1547; https://friendica.example',                                                    true ),
);

$pass = 0;
$fail = 0;
$failures = array();

foreach ( $fixtures as $f ) {
	list( $name, $ua, $expected ) = $f;
	$actual = sn_rss_tracker_is_bot( $ua );
	if ( $actual === $expected ) {
		$pass++;
		echo "  \u{2713} {$name}\n";
	} else {
		$fail++;
		$failures[] = sprintf(
			"  \u{2717} %s\n      UA:       %s\n      expected: %s\n      got:      %s",
			$name,
			$ua,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
		echo end( $failures ) . "\n";
	}
}

echo "\n" . str_repeat( '─', 60 ) . "\n";
echo sprintf( "%d passed, %d failed (%d total)\n", $pass, $fail, count( $fixtures ) );

exit( $fail > 0 ? 1 : 0 );
