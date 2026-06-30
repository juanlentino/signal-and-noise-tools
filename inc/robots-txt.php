<?php
/**
 * Signal & Noise Tools — robots.txt AI-crawler policy.
 *
 * Augments WordPress's virtual robots.txt (the `robots_txt` filter, which fires
 * for /robots.txt only when no static file shadows it) with an explicit,
 * filterable per-AI-crawler policy plus an idempotent Sitemap pointer. The
 * reader-facing complement is the theme's /llms.txt (what is worth reading);
 * this declares who may crawl.
 *
 * The default posture is ALLOW for the major answer-engine crawlers, consistent
 * with the project's AEO direction (llms.txt, IndexNow, sitemaps). Flip any agent
 * to 'disallow' via the `snt_robots_ai_agents` filter.
 *
 * SECURITY: this NEVER emits the masked login slug (inc/login-hide.php). robots.txt
 * is public; listing the hidden login path would defeat the point of hiding it.
 *
 * @package SignalNoiseTools
 * @since 6.53.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI-crawler policy map: user-agent token => 'allow' | 'disallow'.
 * Filterable so the owner can flip any agent (or add new ones) without code edits.
 *
 * @return array<string,string>
 */
function snt_robots_ai_agents() {
	return (array) apply_filters(
		'snt_robots_ai_agents',
		array(
			'GPTBot'            => 'allow', // OpenAI crawler
			'ChatGPT-User'      => 'allow', // OpenAI on-demand fetch
			'OAI-SearchBot'     => 'allow', // OpenAI search
			'ClaudeBot'         => 'allow', // Anthropic crawler
			'Claude-User'       => 'allow', // Anthropic on-demand fetch
			'PerplexityBot'     => 'allow', // Perplexity
			'Google-Extended'   => 'allow', // Google Gemini / Vertex training
			'Applebot-Extended' => 'allow', // Apple Intelligence
			'CCBot'             => 'allow', // Common Crawl
		)
	);
}

/**
 * Build the AI-crawler policy block appended to robots.txt. Allowed agents are
 * documented in one comment (they inherit the `User-agent: *` rules already
 * present); disallowed agents each get an explicit `Disallow: /` group.
 *
 * @return string
 */
function snt_robots_ai_policy() {
	$allowed = array();
	$blocks  = array();
	foreach ( snt_robots_ai_agents() as $ua => $policy ) {
		$ua = trim( (string) $ua );
		if ( '' === $ua ) {
			continue;
		}
		if ( 'disallow' === $policy ) {
			$blocks[] = 'User-agent: ' . $ua;
			$blocks[] = 'Disallow: /';
			$blocks[] = '';
		} else {
			$allowed[] = $ua;
		}
	}

	$out = "# AI crawler policy — signal-and-noise-tools (flip any agent via the snt_robots_ai_agents filter).\n";
	if ( $allowed ) {
		$out .= '# Allowed (answer-engine discoverability is intentional; these inherit the User-agent: * rules above): ' . implode( ', ', $allowed ) . "\n";
	}
	if ( $blocks ) {
		$out .= "\n" . implode( "\n", $blocks );
	}
	return rtrim( $out, "\n" ) . "\n";
}

/**
 * `robots_txt` filter: append the AI-crawler policy and ensure a Sitemap pointer.
 * Hooked at priority 20 so WP core's own Sitemap line (added at 10) is already in
 * $output and we only add one if absent.
 *
 * @param string $output The robots.txt content assembled so far.
 * @param bool   $public Whether the site is set to be indexed (blog_public).
 * @return string
 */
function snt_robots_txt( $output, $public ) {
	if ( ! $public ) {
		return $output; // Respect "Discourage search engines"; do not override.
	}
	$output  = rtrim( (string) $output, "\n" ) . "\n\n";
	$output .= snt_robots_ai_policy();
	if ( false === strpos( $output, 'Sitemap:' ) ) {
		$output .= 'Sitemap: ' . esc_url_raw( home_url( '/wp-sitemap.xml' ) ) . "\n";
	}
	return $output;
}

add_filter( 'robots_txt', 'snt_robots_txt', 20, 2 );
