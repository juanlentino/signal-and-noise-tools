<?php
/**
 * Signal & Noise app — the Attention queue's NINE READERS.
 *
 * THE SEAM: this file and its parts are the only half that calls the estate. Its
 * siblings -- parts/attention*.php -- hold the vocabulary, the composition, the
 * item shape and the descriptor, and touch no signal at all. Split at that
 * line, in that direction, because the two halves fail differently: a reader
 * breaks when the thing it reads changes shape, and the composition breaks
 * when the queue's own contract changes. tests/openstation-app-attention.php
 * pins the seam by name, so a tenth signal read from the wrong side of it is
 * a red test rather than a habit.
 *
 * EVERY READER, WITHOUT EXCEPTION:
 *
 *   1. gates on function_exists() -- an absent subsystem makes NO claim, and
 *      never a standing warning row (inc/watches.php:4-23: a row that is
 *      always there teaches its reader to stop looking);
 *   2. wraps its call in try/catch -- a subsystem that IS present and cannot
 *      answer yields one warning row saying so, never a zero;
 *   3. carries a stamp -- the measurement's own when it has one (a sweep, a
 *      scan, a probe, a capture), the read time when the fact is a live query
 *      and the store keeps no reading time;
 *   4. READS. It never starts a scan, a sweep, a probe or a re-check.
 *
 * The return contract is one shape:
 * `array( 'rows' => [...], 'stamp' => 'Y-m-d H:i:s'|'', 'unreadable' => bool )`.
 *
 * @package SignalNoiseTools
 * @since 13.103.0
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** How close a scheduled transition must be to be worth a row, in seconds. */
const ATTENTION_SOON = 86400;

/** How many citation rows the due-for-a-check query may read. */
const ATTENTION_CITATIONS_DUE_CAP = 50;

/** How stale a citation check may be before it is due, in days. */
const ATTENTION_CITATIONS_STALE_DAYS = 7;

/** Seconds after a stale reading within which a zone purge is its own escalation, not a later fix. */
const ATTENTION_EDGE_ESCALATION_S = 60;


// The readers live in four parts files (house line cap, 2026-10-14), loaded
// in the order they always ran, after these four constants.
require_once __DIR__ . '/attention-readers-integrity-parts.php';
require_once __DIR__ . '/attention-readers-edge-parts.php';
require_once __DIR__ . '/attention-readers-schedule-parts.php';
require_once __DIR__ . '/attention-readers-search-parts.php';
