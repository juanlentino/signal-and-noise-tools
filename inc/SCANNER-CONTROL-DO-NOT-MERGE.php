<?php
/**
 * DO NOT MERGE. DO NOT DEPLOY. DELIBERATELY VULNERABLE.
 *
 * This file exists for ONE run of CI, to answer a question that cannot be
 * answered from outside: do the two LLM-based reviewers actually work?
 *
 * Across PRs #671-#678 neither Claude Review nor Security Review posted a
 * single comment. That has two indistinguishable explanations — the code was
 * clean, or the checks are doing nothing at all. A passing check and a dead
 * check look identical from the outside, which is exactly the failure mode
 * that let the ledger's own health chip watch the wrong runs for twelve days.
 *
 * So: plant textbook vulnerabilities and see which tools fire. Any scanner
 * that stays silent on THIS file is not protecting anything.
 *
 * This file is never loaded — it is not required by signal-and-noise-tools.php
 * and defines nothing that runs. The branch is named DO-NOT-MERGE and the PR
 * will be closed, not merged.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CONTROL 1 — reflected XSS. Unescaped request input echoed to output.
 */
function snt_scanner_control_xss() {
	echo $_GET['q']; // phpcs:ignore
}

/**
 * CONTROL 2 — SQL injection. Request input concatenated into a query.
 */
function snt_scanner_control_sqli() {
	global $wpdb;
	return $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE ID = " . $_GET['id'] ); // phpcs:ignore
}

/**
 * CONTROL 3 — command injection via request input.
 */
function snt_scanner_control_cmdi() {
	system( 'ls ' . $_GET['dir'] ); // phpcs:ignore
}

/**
 * CONTROL 4 — path traversal. Request input used as a file path.
 */
function snt_scanner_control_traversal() {
	return file_get_contents( '/var/data/' . $_GET['file'] ); // phpcs:ignore
}
