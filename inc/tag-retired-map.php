<?php
/**
 * The tags retired by the 83-to-23 pass (2026-08-15, docs/ops/tag-merge-map.md §2).
 * That pass ran through wp-cli, not sn_tag_merge(), so it never filled the
 * sn_tag_redirects option and Search Console kept 404s for the old
 * archives. Old slug => surviving slug; '' means the tag was deleted with
 * no survivor, so it goes to the notes index; a value starting with '/' is a
 * page path (the provenance tags go to the /provenance/ hub, since the
 * `provenance` tag itself no longer exists). Generated from the map; the
 * recorded option wins on a clash. The ONE retired-tag map: the theme's
 * sn_notes_retired_tags() was folded in here (19.1.2).
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_TAG_RETIRED_MAP = array(
	'provenance' => '/provenance/',
	'ai-labeling' => 'ai-disclosure',
	'ai-tools' => 'music-production',
	'ai-generated-music' => 'ai-music',
	'argentina' => 'freelance-business',
	'art-market' => '',
	'artificial-intelligence' => 'ai-music',
	'artist-credit' => 'authorship',
	'audio-engineering' => 'music-production',
	'audio-fingerprinting' => 'music-metadata',
	'authorship-verification' => 'authorship',
	'backward-compatibility' => 'standards',
	'black-box' => 'black-box-royalties',
	'content-attribution' => 'authorship',
	'content-authentication' => 'content-authenticity',
	'content-credentials' => 'content-authenticity',
	'content-labeling' => 'ai-disclosure',
	'copyright-litigation' => 'music-rights',
	'court-of-appeal' => '',
	'cross-cultural-work' => 'freelance-business',
	'cryptographic-identifiers' => 'music-metadata',
	'cryptographic-provenance' => '/provenance/',
	'cryptographic-signing' => 'cryptographic-signatures',
	'cryptography' => 'cryptographic-signatures',
	'currency-controls' => 'freelance-business',
	'digital-audio-workstation' => 'music-production',
	'digital-authorship' => 'authorship',
	'digital-signatures' => 'cryptographic-signatures',
	'evidence' => '',
	'falsifiability' => '/provenance/',
	'freelance' => 'freelance-business',
	'generative-ai' => 'ai-music',
	'generative-music' => 'ai-music',
	'human-attribution' => 'authorship',
	'ifpi' => '',
	'immutability' => '',
	'insider-jargon' => 'music-industry',
	'memorization' => '',
	'metadata' => 'music-metadata',
	'music-authentication' => 'content-authenticity',
	'music-authenticity' => 'content-authenticity',
	'music-identification' => 'music-metadata',
	'music-provenance' => '/provenance/',
	'plain-language' => 'writing',
	'pricing-strategy' => 'freelance-business',
	'recording-studio' => 'freelance-business',
	'remote-freelance' => 'freelance-business',
	'remote-work' => 'freelance-business',
	'robots-txt' => 'ai-training',
	'royalties' => 'music-royalties',
	'royalty-disputes' => 'music-royalties',
	'royalty-distribution' => 'music-royalties',
	'royalty-statements' => 'music-royalties',
	'sample-marketplace' => 'music-production',
	'scope-management' => 'freelance-business',
	'spotify' => '',
	'stem-separation' => 'music-production',
	'streaming-platforms' => 'music-distribution',
	'tdmrep' => 'ai-training',
	'text-and-data-mining' => 'ai-training',
	'web-crawlers' => 'ai-training',
);
