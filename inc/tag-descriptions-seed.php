<?php
/**
 * Signal & Noise Tools: one-shot seed of the 23 tag descriptions.
 *
 * v13.23.0. Both consuming surfaces have waited on these sentences since they
 * shipped — the tag archive's hero dek (theme v12.11.0) and the tag page's
 * meta description (plugin v13.14.0) — each falling back cleanly per
 * undescribed tag. The sentences were owner-approved 2026-08-28; this walks
 * the vocabulary once and writes them.
 *
 * NOT REGISTERED IN sn_content_migrations_registry(). That registry sits
 * behind SN_CONTENT_MIGRATIONS_MASTER_OPT and its runner returns EARLY once
 * the master flag is set — which it is on any live install. A new entry there
 * would look registered, pass the registry's pinning test, and never execute
 * in production (the lesson inc/provenance-freshness-backfill.php recorded).
 * The master sentinel is an optimisation over a CLOSED set; this migration
 * carries its own flag and its own hook.
 *
 * NEVER CLOBBERS: a description is written only where the term's existing
 * description is empty, so a sentence the owner has edited in wp-admin —
 * before or after this ships — survives. A term the map names but the site
 * lacks (renamed, pruned) is skipped without error: the vocabulary is
 * wp-admin's to govern, not this file's. The flag burns after one full pass
 * regardless of skips — new tags added later are new editorial work, not this
 * seed's business.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_TAG_DESCRIPTIONS_SEED_OPT = 'sn_tag_descriptions_seeded_v1';

/**
 * The owner-approved sentence per tag NAME (2026-08-28). Names, not slugs:
 * this is the vocabulary as wp-admin displays it, and a lookup by name fails
 * soft on a rename exactly as intended.
 *
 * @return array<string, string> tag name => description sentence.
 */
function sn_tag_description_seed_map() {
	return array(
		'Provenance'               => 'Notes on making a work\'s origin provable at the moment of creation — because whatever isn\'t captured then never becomes evidence.',
		'Cryptographic Signatures' => 'What a signature actually promises — attribution, not truth — and what breaks when keys meet estates, remixes, live takes, and time.',
		'Authorship'               => 'Who made the work, recorded as a claim someone signs — and what happens to credit when the cast outgrows the format built to name it.',
		'Music Rights'             => 'How rights attach, move, and fail to pay when the record of who made what was never written at the source.',
		'Music Metadata'           => 'The fields music runs on, assigned by others and audited by nobody — and what changes when identity derives from the work itself.',
		'AI Music'                 => 'Generated and assisted music as it actually lands — in budgets, catalogs, and disputes — rather than as a genre panic.',
		'Content Authenticity'     => 'Whether a thing is what it claims to be — a question split between verifying the profile and verifying the work, which are not the same problem.',
		'Music Industry'           => 'How the business behaves around origin, leverage, and change — and why the tier that makes the work keeps losing the same way.',
		'Music Distribution'       => 'The handoffs between studio and listener — where metadata gets stripped, claims get lost, and provenance either survives the pipeline or doesn\'t.',
		'Music Production'         => 'Inside the session: what AI actually does there, what gets signed, and what the file never records about how it was made.',
		'Music Royalties'          => 'Where the money goes when matching fails: black boxes, categories with administrators, and payment systems that pay only what they can name.',
		'Artist Verification'      => 'Proving the person is who they say they are — real progress, and still silent about what gets uploaded under the verified name.',
		'AI Detection'             => 'Why scoring a file can\'t settle what a record of its making can: detectors describe artifacts, and descriptions can be manufactured.',
		'Standards'                => 'The open formats and long clocks provenance depends on — because a proprietary primitive at every handoff just moves the dispute.',
		'AI Training'              => 'What crawlers actually fetch, what training actually retains, and why being read is not being cited.',
		'AI Disclosure'            => 'Labels someone fills in from memory after the fact — and why a record of events beats a verdict every time the rules change.',
		'Digital Identity'         => 'The name behind the key: how identity attaches to signatures, drifts over time, and fails the people no institution vouches for.',
		'Independent Artists'      => 'The contributor with no institution to vouch for them — the hardest case for verifiable authorship, and the one it exists for.',
		'C2PA'                     => 'The content-credentials standard read closely against music\'s pipeline — good work, wrong assumptions about what survives the handoffs.',
		'Legacy Catalog'           => 'The 250 million tracks that already exist without creation-time records — what they can still receive, and what they never can.',
		'Black Box Royalties'      => 'The unmatched money: how it accumulates, who administers it, and why the failure sits upstream of every matching engine.',
		'Freelance Business'       => 'Running a remote music practice across borders and currencies — scope, pricing, and the judgment work AI didn\'t absorb.',
		'Writing'                  => 'Notes on the writing itself — including the industry\'s habit of talking to itself in code.',
	);
}

/**
 * Run the seed once. Writes only empty descriptions; skips missing terms;
 * burns the flag after one full pass.
 */
function sn_seed_tag_descriptions() {
	if ( get_option( SN_TAG_DESCRIPTIONS_SEED_OPT ) ) {
		return;
	}

	foreach ( sn_tag_description_seed_map() as $name => $sentence ) {
		$term = get_term_by( 'name', $name, 'post_tag' );
		if ( ! is_object( $term ) || empty( $term->term_id ) ) {
			continue; // Renamed or pruned — the vocabulary is wp-admin's call.
		}
		if ( '' !== trim( (string) ( $term->description ?? '' ) ) ) {
			continue; // Owner-written (or already seeded) — never clobber.
		}
		wp_update_term( (int) $term->term_id, 'post_tag', array( 'description' => $sentence ) );
	}

	update_option( SN_TAG_DESCRIPTIONS_SEED_OPT, time(), false );
}
add_action( 'admin_init', 'sn_seed_tag_descriptions' );
