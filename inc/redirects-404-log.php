<?php
/**
 * Signal & Noise Tools — front-end 404 capture log (B2, v8.10.0).
 *
 * A capped, aggregating option (`sn_404_log`) of paths that hit a real front-end
 * 404, so the owner can spot broken inbound links and turn the worst offenders
 * into redirects (B1). Pure data layer — the template_redirect capture hook that
 * feeds it lives in inc/redirects-handler.php.
 *
 * Aggregating (path-keyed with a hit count), not append-only: a link a bot
 * hammers 10,000 times is ONE row with count 10000, not 10,000 rows. That bounds
 * both the option size and the signal. A junk filter keeps the exec/probe noise
 * (wp-login.php, /.env, vendor/phpunit RCE probes) out entirely.
 *
 *   '/broken-path' => array( 'count' => int, 'first_seen' => ts, 'last_seen' => ts, 'referer' => str )
 *
 * @package SignalNoiseTools
 * @since 8.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_404_LOG_OPT' ) ) {
	define( 'SN_404_LOG_OPT', 'sn_404_log' );
}
if ( ! defined( 'SN_404_LOG_MAX' ) ) {
	define( 'SN_404_LOG_MAX', 200 );
}

/**
 * Should this 404 path be logged? Filters out the site root and the executable /
 * config-file probes automated scanners fire in bulk (they aren't broken links
 * an owner can fix, and they'd swamp the log). Everything content-shaped passes.
 *
 * @param string $path Request path (any form; normalized here).
 * @return bool True to capture, false to ignore.
 */
function sn_404_should_capture( $path ) {
	$path = sn_redirects_normalize_path( $path );
	if ( '/' === $path ) {
		return false;
	}
	$lower = strtolower( $path );
	// Executable / config / dump extensions — never a legit content 404.
	if ( preg_match( '#\.(php|phtml|asp|aspx|jsp|cgi|env|git|sql|bak|old|ini|conf|config|sh|py|yml|yaml|json|lock)$#', $lower ) ) {
		return false;
	}
	// Browser/OS auto-requested assets: a missing one is an agent default, not a
	// human-followable broken link (matched by name, so a real missing content
	// image like /notes/hero.png still surfaces).
	if ( preg_match( '#^/(apple-touch-icon[\w.-]*\.png|apple-touch-icon-precomposed\.png|favicon\.ico|browserconfig\.xml|apple-app-site-association)$#', $lower ) ) {
		return false;
	}
	// Author-archive username enumeration (the author-enum guard blocks these; they
	// are recon, never a link an owner fixes). Normalization strips the trailing
	// slash, so match the bare '/author' as well as any '/author/<name>'.
	if ( '/author' === $lower || 0 === strpos( $lower, '/author/' ) ) {
		return false;
	}
	// Substrings that mark an infra path or a known probe campaign.
	$probes = array( 'wp-login', 'wp-admin', 'xmlrpc', '/wp-json', '/.git', '/.env', '/.svn', '.htaccess', '.ds_store', '/vendor/', '/wp-includes/', '/wp-content/', 'phpunit', 'eval-stdin', '/cgi-bin/', '/.well-known/acme' );
	foreach ( $probes as $needle ) {
		if ( false !== strpos( $lower, $needle ) ) {
			return false;
		}
	}
	// Single-segment scanner guesses (CMS location, admin panels, backup/dump dirs).
	// Matched EXACTLY — never as a substring — so a real path like /news, /renew, or
	// /database-design is never suppressed by /new or /db.
	$guesses = array(
		'/wp', '/wordpress', '/joomla', '/drupal', '/typo3', '/magento', '/cms', '/site',
		'/admin', '/administrator', '/login', '/signin', '/user', '/users', '/account',
		'/panel', '/dashboard', '/cpanel', '/phpmyadmin', '/pma', '/mysql', '/db', '/database', '/sql',
		'/backup', '/backups', '/bak', '/old', '/new', '/dump', '/config', '/configuration',
		'/setup', '/install', '/test', '/dev', '/staging', '/tmp', '/temp', '/shell', '/cmd', '/api',
	);
	if ( in_array( $lower, $guesses, true ) ) {
		return false;
	}
	return true;
}

/**
 * The 404 log filtered to actionable broken links only — the raw store minus any
 * entry the current sn_404_should_capture() rejects. This lets a broadened filter
 * retroactively hide junk logged under an older, narrower ruleset WITHOUT mutating
 * the stored option on read. The admin surface + its "N broken paths" count read
 * through this; the raw sn_404_log_all() stays the source of truth for writes.
 *
 * @return array<string,array{count:int,first_seen:int,last_seen:int,referer:string}>
 */
function sn_404_log_actionable() {
	$out = array();
	foreach ( sn_404_log_all() as $path => $entry ) {
		if ( sn_404_should_capture( $path ) ) {
			$out[ $path ] = $entry;
		}
	}
	return $out;
}

/**
 * The full 404 log, path-keyed.
 *
 * @return array<string,array{count:int,first_seen:int,last_seen:int,referer:string}>
 */
function sn_404_log_all() {
	$log = get_option( SN_404_LOG_OPT, array() );
	return is_array( $log ) ? $log : array();
}

/**
 * Record a 404 hit. Aggregates onto an existing path (bump count + last_seen +
 * latest referer) or inserts a new one; enforces the FIFO cap on distinct paths.
 * Junk paths (sn_404_should_capture) are silently ignored.
 *
 * @param string $uri     Request URI (may include a query string).
 * @param string $referer Referring URL, if any.
 * @return bool True if the hit was recorded, false if filtered out.
 */
function sn_404_log_record( $uri, $referer = '' ) {
	if ( ! sn_404_should_capture( $uri ) ) {
		return false;
	}
	$path    = sn_redirects_normalize_path( $uri );
	$referer = trim( (string) $referer );
	$now     = time();
	$log     = sn_404_log_all();

	// Self-heal: drop any entry a since-broadened filter now rejects (logged under
	// an older, narrower ruleset). Free — we are already rewriting the option below.
	foreach ( array_keys( $log ) as $stale ) {
		if ( ! sn_404_should_capture( $stale ) ) {
			unset( $log[ $stale ] );
		}
	}

	if ( isset( $log[ $path ] ) ) {
		$log[ $path ]['count']     = (int) $log[ $path ]['count'] + 1;
		$log[ $path ]['last_seen'] = $now;
		if ( '' !== $referer ) {
			$log[ $path ]['referer'] = $referer;
		}
	} else {
		$log[ $path ] = array(
			'count'      => 1,
			'first_seen' => $now,
			'last_seen'  => $now,
			'referer'    => $referer,
		);
	}
	if ( count( $log ) > SN_404_LOG_MAX ) {
		$log = array_slice( $log, -SN_404_LOG_MAX, null, true );
	}
	// Non-autoloaded: this log is read only in wp-admin and can hold 200 entries —
	// it has no business in the autoload bundle loaded on every front-end request.
	update_option( SN_404_LOG_OPT, $log, false );
	return true;
}

/**
 * Delete a single logged path (e.g. once the owner has added a redirect for it).
 *
 * @param string $path Path (any form; normalized here).
 * @return bool True if a matching entry existed and was removed.
 */
function sn_404_log_delete( $path ) {
	$path = sn_redirects_normalize_path( $path );
	$log  = sn_404_log_all();
	if ( ! isset( $log[ $path ] ) ) {
		return false;
	}
	unset( $log[ $path ] );
	update_option( SN_404_LOG_OPT, $log );
	return true;
}

/**
 * Clear the entire 404 log.
 *
 * @return bool
 */
function sn_404_log_clear() {
	return delete_option( SN_404_LOG_OPT );
}

// The similar_text percent floor a published slug must clear before it is
// offered as a redirect-target suggestion. Conservative: a weak match prefills
// nothing (an empty box beats a wrong guess the owner rubber-stamps).
if ( ! defined( 'SN_404_SUGGEST_MIN_PCT' ) ) {
	define( 'SN_404_SUGGEST_MIN_PCT', 65.0 );
}

/** Recent 404 rows the get-404-log ability returns at most. */
if ( ! defined( 'SN_404_LOG_ABILITY_MAX' ) ) {
	define( 'SN_404_LOG_ABILITY_MAX', 50 );
}

/**
 * Suggest a redirect target for a 404'd path from the published slug set,
 * using classical string distance only (levenshtein rank, similar_text floor
 * — no AI, fully deterministic). PURE: takes the candidate paths, returns the
 * best candidate path or '' when nothing clears the similarity floor. The
 * WRITE path is untouched — this only prefills the existing audited admin
 * form; the owner still reviews and submits.
 *
 * Comparison surface is the LAST path segment (the slug): '/notes/desing-tokens'
 * should suggest '/notes/design-tokens' even though the prefixes already match.
 *
 * @since 9.81.0
 * @param string   $path       The 404'd path (any form; normalized here).
 * @param string[] $candidates Published candidate paths (e.g. '/notes/design-tokens').
 * @return string Best candidate path, or '' when none clears the floor.
 */
function sn_404_suggest_target( $path, array $candidates ) {
	$path    = sn_redirects_normalize_path( $path );
	$needle  = strtolower( (string) basename( $path ) );
	if ( '' === $needle || '/' === $path ) {
		return '';
	}
	$best      = '';
	$best_pct  = 0.0;
	$best_lev  = PHP_INT_MAX;
	foreach ( $candidates as $candidate ) {
		$candidate = sn_redirects_normalize_path( (string) $candidate );
		if ( '' === $candidate || '/' === $candidate || $candidate === $path ) {
			continue;
		}
		$slug = strtolower( (string) basename( $candidate ) );
		if ( '' === $slug ) {
			continue;
		}
		$pct = 0.0;
		similar_text( $needle, $slug, $pct );
		if ( $pct < SN_404_SUGGEST_MIN_PCT ) {
			continue;
		}
		$lev = levenshtein( $needle, $slug );
		// Rank: smallest edit distance wins; similar_text percent breaks ties.
		if ( $lev < $best_lev || ( $lev === $best_lev && $pct > $best_pct ) ) {
			$best     = $candidate;
			$best_pct = $pct;
			$best_lev = $lev;
		}
	}
	return $best;
}

/**
 * Published post + page paths, the candidate set the 404 suggester ranks.
 * Bounded (one ids query, capped) and derived from real permalinks so custom
 * structures survive.
 *
 * @since 9.81.0
 * @param int $limit Max candidates.
 * @return string[]
 */
function sn_404_published_paths( $limit = 200 ) {
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$ids = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $limit ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	$paths = array();
	foreach ( (array) $ids as $id ) {
		$p = (string) wp_parse_url( (string) get_permalink( (int) $id ), PHP_URL_PATH );
		if ( '' !== $p && '/' !== $p ) {
			$paths[] = $p;
		}
	}
	return array_values( array_unique( $paths ) );
}

/**
 * Register the readonly get-404-log ability on the canonical registrar hook.
 *
 * @since 9.81.0
 */
function snt_abilities_404_log_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/get-404-log', array(
		'label'               => 'Recent front-end 404 log',
		'description'         => 'Returns the recent actionable front-end 404 log (bot/probe noise filtered): each row carries the broken path, hit count, first/last seen timestamps, the latest referring host, and a deterministic redirect-target suggestion from published slugs when one clears the similarity floor. Read-only; redirects are still created through the audited admin form.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_404_log',
		'input_schema'        => array(
			// The [object,null] union: readonly ⇒ GET run-path ⇒ an omitted
			// ?input= delivers NULL, and a plain 'object' rejects every such call.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'total'   => array( 'type' => 'integer' ),
				'entries' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'path'       => array( 'type' => 'string' ),
							'count'      => array( 'type' => 'integer' ),
							'first_seen' => array( 'type' => 'integer' ),
							'last_seen'  => array( 'type' => 'integer' ),
							'referer'    => array( 'type' => 'string' ),
							'suggested'  => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
}
add_action( 'wp_abilities_api_init', 'snt_abilities_404_log_register' );

/**
 * Ability execute callback: signal-noise/get-404-log. Read-only: the
 * actionable log (junk filtered), most-recently-seen first, capped, each row
 * carrying the deterministic slug suggestion.
 *
 * @since 9.81.0
 * @param array|null $input Unused.
 * @return array{total:int,entries:array[]}
 */
function snt_ability_get_404_log( $input = null ) {
	$log = sn_404_log_actionable();
	uasort(
		$log,
		static function ( $a, $b ) {
			return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
		}
	);
	$candidates = sn_404_published_paths();
	$entries    = array();
	foreach ( $log as $path => $entry ) {
		if ( count( $entries ) >= SN_404_LOG_ABILITY_MAX ) {
			break;
		}
		$entries[] = array(
			'path'       => (string) $path,
			'count'      => (int) ( $entry['count'] ?? 0 ),
			'first_seen' => (int) ( $entry['first_seen'] ?? 0 ),
			'last_seen'  => (int) ( $entry['last_seen'] ?? 0 ),
			'referer'    => (string) ( $entry['referer'] ?? '' ),
			'suggested'  => sn_404_suggest_target( (string) $path, $candidates ),
		);
	}
	return array(
		'total'   => count( $log ),
		'entries' => $entries,
	);
}
