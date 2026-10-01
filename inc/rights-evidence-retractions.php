<?php
/**
 * Signal & Noise Tools: rights evidence, the retraction text (pure data).
 *
 * The owner-approved wording of each retraction, fixed in code and never an
 * input: month => family => {claimed, what_was_wrong, root_cause,
 * what_changed}. A record with no entry here cannot be retracted (no button,
 * the handler refuses). Changing a byte here changes what is signed and
 * published; tests/rights-evidence.php pins the whole map by hash.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RIGHTS_EVIDENCE_RETRACTIONS = array(
	'2026-08' => array(
		'openai' => array(
			'claimed'        => 'The reservation in force for August 2026 was license-xml v2, robots-txt v5, tdm-policy v8, tdmrep-json v1 and webmcp-bridge v5, and openai read none of the rights files in August (rights_reads.reads 0, marked incomplete).',
			'what_was_wrong' => 'Two errors. The reservation named the versions current when the record was composed on 2026-09-19, not those in force during August: tdm-policy v8 and webmcp-bridge v5 were anchored in September, and earlier versions in force in August were not named. The rights-file reads were reported as none; re-read, openai fetched the rights files 6 times from 2026-08-11 (4 search, 1 user, 1 training read that is unconfirmed).',
			'root_cause'     => 'The reservation was read from the ledger index, which carries only each rights signal\'s current version, not the versions in force during the month. The rights-file reads came from a sensor stream capped at 500 rows that the site\'s own monitoring probes had filled, pushing August out of the window.',
			'what_changed'   => 'From September 2026 records use schema 2: the reservation lists every version in force during the month with its anchor block and span, rights-file reads are split by purpose, and the sensor stream is read per family with the site\'s own traffic excluded. The versions in force in August and the re-read rights-file reads are listed in ERRATA.md in this repository.',
		),
		'anthropic' => array(
			'claimed'        => 'The reservation in force for August 2026 was license-xml v2, robots-txt v5, tdm-policy v8, tdmrep-json v1 and webmcp-bridge v5, and anthropic read none of the rights files in August (rights_reads.reads 0, marked incomplete).',
			'what_was_wrong' => 'The reservation named the versions current when the record was composed on 2026-09-19, not those in force during August: tdm-policy v8 and webmcp-bridge v5 were anchored in September, and earlier versions in force in August were not named. The rights-file reads were reported as none from an incomplete stream; re-read, there is one training read on 2026-08-11 that is unconfirmed.',
			'root_cause'     => 'The reservation was read from the ledger index, which carries only each rights signal\'s current version, not the versions in force during the month. The rights-file reads came from a sensor stream capped at 500 rows that the site\'s own monitoring probes had filled, pushing August out of the window.',
			'what_changed'   => 'From September 2026 records use schema 2: the reservation lists every version in force during the month with its anchor block and span, rights-file reads are split by purpose, and the sensor stream is read per family with the site\'s own traffic excluded. The versions in force in August and the re-read rights-file reads are listed in ERRATA.md in this repository.',
		),
		'google-ai' => array(
			'claimed'        => 'The reservation in force for August 2026 was license-xml v2, robots-txt v5, tdm-policy v8, tdmrep-json v1 and webmcp-bridge v5.',
			'what_was_wrong' => 'The reservation named the versions current when the record was composed on 2026-09-19, not those in force during August: tdm-policy v8 and webmcp-bridge v5 were anchored in September, and earlier versions in force in August were not named. The record\'s report of no rights-file reads holds when re-read from 2026-08-11.',
			'root_cause'     => 'The reservation was read from the ledger index, which carries only each rights signal\'s current version, not the versions in force during the month. The rights-file reads came from a sensor stream capped at 500 rows that the site\'s own monitoring probes had filled, pushing August out of the window.',
			'what_changed'   => 'From September 2026 records use schema 2: the reservation lists every version in force during the month with its anchor block and span, rights-file reads are split by purpose, and the sensor stream is read per family with the site\'s own traffic excluded. The versions in force in August and the re-read rights-file reads are listed in ERRATA.md in this repository.',
		),
		'commoncrawl' => array(
			'claimed'        => 'The reservation in force for August 2026 was license-xml v2, robots-txt v5, tdm-policy v8, tdmrep-json v1 and webmcp-bridge v5.',
			'what_was_wrong' => 'The reservation named the versions current when the record was composed on 2026-09-19, not those in force during August: tdm-policy v8 and webmcp-bridge v5 were anchored in September, and earlier versions in force in August were not named. The record\'s report of no rights-file reads holds when re-read from 2026-08-11.',
			'root_cause'     => 'The reservation was read from the ledger index, which carries only each rights signal\'s current version, not the versions in force during the month. The rights-file reads came from a sensor stream capped at 500 rows that the site\'s own monitoring probes had filled, pushing August out of the window.',
			'what_changed'   => 'From September 2026 records use schema 2: the reservation lists every version in force during the month with its anchor block and span, rights-file reads are split by purpose, and the sensor stream is read per family with the site\'s own traffic excluded. The versions in force in August and the re-read rights-file reads are listed in ERRATA.md in this repository.',
		),
	),
);

/**
 * The approved retraction text for one record.
 *
 * @param string $month  YYYY-MM.
 * @param string $family Crawler family.
 * @return array{claimed:string,what_was_wrong:string,root_cause:string,what_changed:string}|null
 */
function sn_rights_evidence_retraction_text( $month, $family ) {
	$t = SN_RIGHTS_EVIDENCE_RETRACTIONS[ (string) $month ][ (string) $family ] ?? null;
	return is_array( $t ) ? $t : null;
}
