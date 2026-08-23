<?php
/**
 * Signal & Noise Tools — Agent Skills Discovery (RFC v0.2.0).
 *
 *   /.well-known/agent-skills/index.json        the index
 *   /.well-known/agent-skills/{slug}/SKILL.md   the artifacts
 *
 * WHY THE DIGEST CANNOT DRIFT. The RFC requires a sha256 of "the raw bytes of
 * the skill's artifact". This module hashes THE SAME FILE IT SERVES, at request
 * time, with no cached copy and no hand-entered value in between. Editing a
 * SKILL.md therefore changes its digest automatically; there is no second place
 * to update and so no way to forget. A stored digest table would be a parallel
 * source of truth whose only job is to go stale.
 *
 * WHY DESCRIPTIONS ARE PARSED FROM THE ARTIFACT. Same reasoning: the index's
 * description is the skill document's own first paragraph, so the index cannot
 * describe something the document does not say.
 *
 * PATH SAFETY. The slug from the URL is NEVER used to build a filesystem path.
 * It is matched against the registry — built by scanning the skills directory —
 * and only a slug already in that set resolves to a file. A traversal attempt
 * simply does not match a known slug and falls through to WordPress's 404.
 *
 * @package Signal_And_Noise_Tools
 * @since   12.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_AGENT_SKILLS_INDEX_PATH' ) ) {
	define( 'SN_AGENT_SKILLS_INDEX_PATH', '/.well-known/agent-skills/index.json' );
}

if ( ! defined( 'SN_AGENT_SKILLS_PREFIX' ) ) {
	define( 'SN_AGENT_SKILLS_PREFIX', '/.well-known/agent-skills/' );
}

if ( ! defined( 'SN_AGENT_SKILLS_SCHEMA' ) ) {
	define( 'SN_AGENT_SKILLS_SCHEMA', 'https://schemas.agentskills.io/discovery/0.2.0/schema.json' );
}

/** @return string Absolute path of the directory holding the skill artifacts. */
function sn_agent_skills_dir() {
	return defined( 'SNT_PATH' ) ? SNT_PATH . 'skills' : __DIR__ . '/../skills';
}

/**
 * Pull the description out of a SKILL.md: its first paragraph that is not the
 * title. Whitespace is collapsed and the result capped at the RFC's 1024.
 *
 * @param string $markdown
 * @return string
 */
function sn_agent_skills_description( $markdown ) {
	$blocks = preg_split( '/\R{2,}/', (string) $markdown );
	foreach ( (array) $blocks as $block ) {
		$block = trim( (string) $block );
		if ( '' === $block || 0 === strpos( $block, '#' ) ) {
			continue;
		}
		$block = (string) preg_replace( '/\s+/', ' ', $block );
		return function_exists( 'mb_substr' ) ? mb_substr( $block, 0, 1024 ) : substr( $block, 0, 1024 );
	}
	return '';
}

/**
 * The registry: one entry per directory under skills/ containing a SKILL.md.
 *
 * Scanned rather than declared, so adding a skill is adding a file. The slug
 * pattern is the Agent Skills naming rule (1-64 chars, lowercase alphanumeric
 * and hyphens); a directory that does not match is skipped rather than
 * published under an invalid name.
 *
 * @return array<string,array<string,string>> slug => [path, description]
 */
function sn_agent_skills_registry() {
	$dir = sn_agent_skills_dir();
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$out     = array();
	$entries = scandir( $dir );
	foreach ( (array) $entries as $slug ) {
		if ( '.' === $slug || '..' === $slug ) {
			continue;
		}
		if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', (string) $slug ) ) {
			continue;
		}
		$path = $dir . '/' . $slug . '/SKILL.md';
		if ( ! is_file( $path ) ) {
			continue;
		}
		$out[ $slug ] = array(
			'path'        => $path,
			'description' => sn_agent_skills_description( (string) file_get_contents( $path ) ),
		);
	}
	ksort( $out );
	return $out;
}

/**
 * The index document.
 *
 * @return array<string,mixed>
 */
function sn_agent_skills_index() {
	$home   = function_exists( 'home_url' ) ? (string) home_url() : '';
	$skills = array();

	foreach ( sn_agent_skills_registry() as $slug => $entry ) {
		if ( '' === $entry['description'] ) {
			// The RFC makes description required. Publishing an entry without
			// one would be an invalid index; skipping is the honest degrade.
			continue;
		}
		$skills[] = array(
			'name'        => $slug,
			'type'        => 'skill-md',
			'description' => $entry['description'],
			'url'         => $home . SN_AGENT_SKILLS_PREFIX . $slug . '/SKILL.md',
			'digest'      => 'sha256:' . hash_file( 'sha256', $entry['path'] ),
		);
	}

	return array(
		'$schema' => SN_AGENT_SKILLS_SCHEMA,
		'skills'  => $skills,
	);
}

/**
 * Resolve a request path to a skill slug, or '' if it addresses none.
 *
 * @param string $uri
 * @return string
 */
function sn_agent_skills_match_artifact( $uri ) {
	$path = sn_agent_normalize_path( $uri );
	if ( 0 !== strpos( $path, SN_AGENT_SKILLS_PREFIX ) ) {
		return '';
	}
	$rest = substr( $path, strlen( SN_AGENT_SKILLS_PREFIX ) );
	if ( ! preg_match( '#^([a-z0-9-]{1,64})/SKILL\.md$#', (string) $rest, $m ) ) {
		return '';
	}
	// Registry membership is the authorization, not the regex.
	$registry = sn_agent_skills_registry();
	return isset( $registry[ $m[1] ] ) ? $m[1] : '';
}

/** @param string $uri @return bool */
function sn_agent_skills_index_is_request( $uri ) {
	return sn_agent_normalize_path( $uri ) === SN_AGENT_SKILLS_INDEX_PATH;
}

/**
 * Send a skill artifact as text/markdown.
 *
 * @param string $slug Already validated against the registry.
 * @return void
 */
function sn_agent_skills_send_artifact( $slug ) {
	$registry = sn_agent_skills_registry();
	if ( ! isset( $registry[ $slug ] ) ) {
		return;
	}
	if ( function_exists( 'status_header' ) ) {
		status_header( 200 );
	}
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'Cache-Control: public, max-age=3600' );
	header( 'Access-Control-Allow-Origin: *' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown artifact; HTML escaping would corrupt it and change its digest.
	echo file_get_contents( $registry[ $slug ]['path'] );
}

/** Route the request. */
function sn_agent_skills_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_agent_skills_index_is_request( $req ) ) {
		sn_agent_send_document( sn_agent_skills_index(), 'application/json; charset=utf-8' );
		exit;
	}
	$slug = sn_agent_skills_match_artifact( $req );
	if ( '' !== $slug ) {
		sn_agent_skills_send_artifact( $slug );
		exit;
	}
}

/**
 * Advertise the index on agents.json.
 *
 * @param array<int,array<string,string>> $surfaces
 * @return array<int,array<string,string>>
 */
function sn_agent_skills_advertise_surface( $surfaces ) {
	$home       = function_exists( 'home_url' ) ? (string) home_url() : '';
	$surfaces[] = array(
		'type'        => 'agent-skills',
		'url'         => $home . SN_AGENT_SKILLS_INDEX_PATH,
		'format'      => 'application/json',
		'title'       => 'Agent skills index',
		'description' => 'Agent Skills Discovery RFC v0.2.0 index. Each entry carries a sha256 digest of the artifact it points at.',
	);
	return $surfaces;
}

if ( ! defined( 'SN_AGENT_DISCOVERY_TEST' ) || ! SN_AGENT_DISCOVERY_TEST ) {
	add_action( 'template_redirect', 'sn_agent_skills_maybe_serve', 0 );
	add_filter( 'sn_agents_surfaces', 'sn_agent_skills_advertise_surface' );
}
