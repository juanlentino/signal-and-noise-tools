<?php
/**
 * Signal & Noise Tools: the operations résumé's starting content (owner brief
 * 2026-11-01, appendix "operations variant content").
 *
 * sn_resume_operations_setup() writes it into a document: the operations
 * settings, "What I've Built", the operations skills rows, two operations
 * affiliations, and music-only flags on what the operations résumé leaves out.
 * It finds existing entries, roles and bullets by the start of their text and
 * never rewords them; whatever it cannot find is reported, and stays in both
 * variants. It only fills what is missing: settings and "What I've Built"
 * that exist are kept, rows already marked keep their mark, and the existing
 * skills rows turn music-only only on the first run (before any operations
 * settings exist). Running it again never undoes an edit. The admin action
 * writes the result into the draft, so the owner reviews it before publishing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The new wording, from the appendix. */
const SN_RESUME_OPS_SETUP = array(
	'operations' => array(
		'headline'         => 'Founder and Operator',
		'tagline'          => 'AI-Native Business Operations | Partnerships',
		'location'         => 'Orlando, FL (open to relocation)',
		'links'            => array( 'https://github.com/juanlentino' ),
		'summary'          => 'Founder-operator with 15+ years building music businesses across the U.S. and Latin America. Built Panacea Studio in Buenos Aires from zero to sustained profitability with no outside capital, owned its P&L, pricing and team, and negotiated seven cross-border partnerships. Now advises studios, labels and creative companies on pricing, operating model and AI workflow adoption. MBA in Applied AI in Business; bilingual in English and Spanish.',
		'sections'         => array( 'summary', 'built', 'experience', 'education', 'affiliations', 'skills' ),
		'experience_order' => array( 'Independent Practice', 'Panacea Studio', 'Independent Research' ),
		'earlier_compact'  => true,
		'pdf_file'         => 'JuanLentino_Resume_Operations.pdf',
	),
	'built'      => array(
		'intro' => 'All shipped with coding agents (Claude Code) from written briefs, with tests that must pass before release.',
		'items' => array(
			array( 'title' => 'juanlentino.com platform', 'url' => '', 'text' => 'Runs the site on a custom WordPress theme and plugin, with a Cloudflare edge worker that separates human, AI-crawler and first-party traffic. Added MCP servers so AI agents can query site analytics and apply changes, guarded by kill switches, rate limits and an audit log.' ),
			array( 'title' => 'can-i-apply', 'url' => 'https://github.com/juanlentino/can-i-apply', 'text' => 'Labels about 500 roles on the a16z speedrun talent board by work-authorization status, each label tied to the one sentence in the posting it rests on. Deterministic rules, Node.js, no dependencies.' ),
			array( 'title' => 'Sealed Record', 'url' => 'https://github.com/juanlentino/sealedrecord', 'text' => 'Open-source verifier for sealed session records: recomputes a SHA-256 hash chain, Ed25519 signatures and audio anchors, then reports whether the record holds or where it breaks.' ),
		),
	),
	'skills'     => array(
		array( 'category' => 'Operations', 'items' => 'P&L ownership, pricing, project management, vendor and schedule management, client acquisition and retention' ),
		array( 'category' => 'Partnerships', 'items' => 'deal negotiation, distribution and co-production agreements, cross-border market entry (U.S., Latin America)' ),
		array( 'category' => 'AI and tools', 'items' => 'Claude Code and coding agents, MCP servers, Cloudflare Workers, Vercel, WordPress, GitHub, Node.js' ),
		array( 'category' => 'Languages', 'items' => 'English (fluent), Spanish (native)' ),
	),
	// Placed before the first affiliation both résumés keep.
	'affiliations' => array(
		array( 'title' => 'Voting Member, The Recording Academy and The Latin Recording Academy', 'lines' => array( 'September 2025 - Present' ) ),
		array( 'title' => 'Team Captain, Recording Academy Music Advocacy Day', 'lines' => array( '2026' ) ),
	),
	// What the operations résumé leaves out, by the start of its text.
	'music_only' => array(
		'bullets'      => array( 'Guide artists and label teams on release strategy', 'Supervised full recording workflows end to end', 'Partnered with artists on sonic identity', 'Publish ongoing research notes' ),
		'roles'        => array( 'Production Assistant' ),
		'earlier'      => array( 'FREELANCE' ),
		'affiliations' => array( 'Reviewer, Latin American Music Information Retrieval', 'Voting Member, The Recording Academy', 'Voting Member, The Latin Recording Academy', 'Spatial Audio with Dolby Atmos' ),
	),
);

/**
 * Whether $text starts with $prefix, ignoring tags and case.
 *
 * @param string $text
 * @param string $prefix
 * @return bool
 */
function sn_resume_starts( $text, $prefix ) {
	$plain = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $text ) : strip_tags( (string) $text );
	return 0 === stripos( trim( $plain ), $prefix );
}

/**
 * Write the operations résumé's starting content into a stored document. PURE.
 *
 * @param array $doc Normalized stored document (sn_resume_doc_get_raw()).
 * @return array{doc:array,missing:array<int,string>}
 */
function sn_resume_operations_setup( $doc ) {
	$set     = SN_RESUME_OPS_SETUP;
	$mo      = $set['music_only'];
	$missing = array();
	$found   = array();
	$mark    = static function ( &$row ) {
		$row['variant'] = 'music';
	};

	$first           = empty( $doc['variants']['operations'] ); // the first run: nothing set up yet.
	$doc['variants'] = (array) ( $doc['variants'] ?? array() );
	if ( $first ) {
		$doc['variants']['operations'] = $set['operations'];
	}
	if ( empty( $doc['built'] ) ) {
		$doc['built'] = $set['built'];
	}

	// Bullets and roles, in experience and earlier career alike.
	$walk = static function ( &$entries ) use ( $mo, &$found ) {
		foreach ( $entries as &$entry ) {
			foreach ( $mo['earlier'] as $org ) {
				if ( sn_resume_starts( $entry['org'] ?? '', $org ) ) {
					$entry['variant'] ??= 'music';
					$found[ 'earlier:' . $org ] = true;
				}
			}
			foreach ( $entry['roles'] as &$role ) {
				foreach ( $mo['roles'] as $title ) {
					if ( sn_resume_starts( $role['title'] ?? '', $title ) ) {
						$role['variant'] ??= 'music';
						$found[ 'role:' . $title ] = true;
					}
				}
				foreach ( $role['bullets'] as $bullet ) {
					foreach ( $mo['bullets'] as $start ) {
						if ( sn_resume_starts( $bullet, $start ) ) {
							$role['bullet_variants'][ sn_resume_bullet_key( $bullet ) ] ??= 'music';
							$found[ 'bullet:' . $start ] = true;
						}
					}
				}
			}
			unset( $role );
		}
		unset( $entry );
	};
	$walk( $doc['experience'] );
	$walk( $doc['earlier']['entries'] );

	// Affiliations: the left-out ones turn music-only, the new ones go in
	// before the first one both keep (once).
	$titles = array_map( static fn( $r ) => (string) ( $r['title'] ?? '' ), $doc['affiliations'] );
	$ours   = array_column( $set['affiliations'], 'title' );
	foreach ( $doc['affiliations'] as &$aff ) {
		foreach ( $mo['affiliations'] as $start ) {
			// "Voting Member, The Recording Academy" must not catch the merged operations line.
			if ( sn_resume_starts( $aff['title'] ?? '', $start ) && ! in_array( (string) ( $aff['title'] ?? '' ), $ours, true ) ) {
				$aff['variant'] ??= 'music';
				$found[ 'affiliation:' . $start ] = true;
			}
		}
	}
	unset( $aff );
	$new = array();
	foreach ( $set['affiliations'] as $row ) {
		if ( ! in_array( $row['title'], $titles, true ) ) {
			$new[] = $row + array( 'variant' => 'operations' );
		}
	}
	if ( $new ) {
		$at = 0;
		foreach ( $doc['affiliations'] as $i => $aff ) {
			$at = $i + 1;
			if ( ! isset( $aff['variant'] ) ) {
				$at = $i;
				break;
			}
		}
		array_splice( $doc['affiliations'], $at, 0, $new );
	}

	// Skills: the existing rows are the music résumé's; the operations rows are new.
	$have = array_map( static fn( $r ) => (string) ( $r['category'] ?? '' ) . "\0" . ( $r['variant'] ?? '' ), $doc['skills'] );
	foreach ( $doc['skills'] as &$row ) {
		if ( $first && ! isset( $row['variant'] ) ) { // a row added later stays as its author set it.
			$mark( $row );
		}
	}
	unset( $row );
	foreach ( $set['skills'] as $row ) {
		if ( ! in_array( $row['category'] . "\0operations", $have, true ) ) {
			$doc['skills'][] = $row + array( 'variant' => 'operations' );
		}
	}

	foreach ( array( 'bullets' => 'bullet', 'roles' => 'role', 'earlier' => 'earlier', 'affiliations' => 'affiliation' ) as $list => $kind ) {
		foreach ( $mo[ $list ] as $start ) {
			if ( empty( $found[ $kind . ':' . $start ] ) ) {
				$missing[] = $kind . ': ' . $start;
			}
		}
	}
	return array( 'doc' => $doc, 'missing' => $missing );
}
