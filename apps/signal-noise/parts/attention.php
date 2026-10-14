<?php
/**
 * Signal & Noise app — the Attention section: what needs the operator now.
 *
 * The phone's first screen (position 5, first at the root). Nine READERS over
 * signals something else already computed, composed into one queue of rows.
 *
 * THIS FILE IS THE COMPOSITION and touches no signal: the vocabulary (the
 * kinds, the stamps, the doors), the ordering, the cache, the item shape and
 * the descriptor. The ten readers live in parts/attention-readers.php, which
 * is the only half that calls the estate. The two fail differently -- a reader
 * breaks when the thing it reads changes shape, this breaks when the queue's
 * own contract changes -- and the suite pins the seam by name, so a tenth
 * signal read from this side is a red test rather than a habit.
 *
 * IT READS, IT NEVER COMPUTES. The rule is the desktop attention badge's, and
 * it is verbatim (inc/desktop-mode-attention.php:8-11): no reader here starts
 * a health scan, an integrity sweep, an edge probe or a live re-check. Every
 * one of them opens a stored measurement or runs one bounded, read-only query,
 * and every row says WHEN what it reports was measured — a queue whose rows
 * carry no stamp cannot be told from a queue reporting yesterday.
 *
 * ABSENT IS NOT ZERO, in three separate places:
 *
 *   - A reader whose subsystem is NOT INSTALLED yields no rows and makes no
 *     claim. It does not yield a warning: a row that is always present is the
 *     failure mode this queue exists to fix (inc/watches.php:4-23 — "a daily
 *     message that usually says 'nothing yet' trains its reader to stop
 *     opening it").
 *   - A reader that IS present and cannot answer — it threw, or handed back
 *     something that is not its contract — yields exactly ONE warning row
 *     saying so. Never a zero, never silence.
 *   - A reader that ran and measured nothing yields no rows, and the empty
 *     queue says when its readers last looked.
 *
 * The composition is cached for sixty seconds and is user-neutral; the why
 * sits on attention_rows() and attention_visible_rows(), in the parts files. (The guard
 * under the namespace must stay within the file's first fifty lines:
 * Plugin Check's direct-access regex reads no further, and its AST path
 * accepts only a bare exit, which the standalone-aware guard is not.)
 *
 * @package SignalNoiseTools
 * @since 13.103.0
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** The composed queue's transient key. */
const ATTENTION_CACHE_KEY = 'snt_os_attention';

/** How long a composition stands, in seconds. */
const ATTENTION_TTL = 60;

/**
 * The last composition's headline, kept past the transient's expiry. The
 * Posts window's Attention pill reads this (snt_os_attention_snapshot()) when
 * the transient is gone, so it can say "the app last saw N" instead of
 * nothing — without composing. Only ever written beside a composition.
 */
const ATTENTION_LAST_OPT = 'snt_os_attention_last';

/**
 * Acknowledged rows: `row key => stamp at acknowledgement`. A row is hidden
 * while its stamp equals the stored one, and reappears when the fact moves.
 */
const ATTENTION_ACK_OPT = 'snt_os_attention_acks';

// The rest of the composition lives in four parts files (house line cap,
// 2026-10-14), loaded in the order it always ran, after these four constants.
require_once __DIR__ . '/attention-vocab-parts.php';
require_once __DIR__ . '/attention-shape-parts.php';
require_once __DIR__ . '/attention-compose-parts.php';
require_once __DIR__ . '/attention-items-parts.php';
