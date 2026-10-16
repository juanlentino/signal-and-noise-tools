<?php
/**
 * Signal & Noise Tools — [sn_maturity_roadmap], the HUB-WIDE roadmap
 * BOARD: one hard-framed row per family, three status columns
 * (done / planned / considering), readable both ways — scan a row for
 * one family's arc, scan a column for everything planned site-wide.
 * An empty cell renders an em-dash: a family with no future tense is
 * information, not a gap. An item moves LEFT as it matures, so the
 * page demonstrates the promotion flow it documents.
 *
 * Same family contract as every maturity sibling: static data behind a
 * filter seam, whitelisted statuses, escaped at build, its own front
 * stylesheet, and the security contract (no option names, endpoint
 * paths, tool or change-type slugs, or meta keys ever reach the public
 * page). 'considering' is an idea, never a commitment, and the copy
 * should read that way; 'planned' names its gate in the sentence.
 * Edits flow through the `sn_maturity_roadmap_board` filter, a
 * deliberate owner edit here, or — since the board-as-data release —
 * the sn_apply write door's 'roadmap_board' change type, which stores
 * an owner-approved override in an option. The override, when present
 * AND valid, replaces the static board wholesale (option-canonical,
 * the /resume pattern); anything invalid falls back to the static
 * board silently — the public page never renders a broken override.
 * The static array below stays the versioned default and the
 * disaster-recovery floor.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MATURITY_ROADMAP_STATUSES = array( 'done', 'planned', 'considering', 'later' );

// The board-as-data override option (written ONLY by sn_apply's
// 'roadmap_board' change type; never rendered, never echoed) and the
// override's structural bounds — generous editorial ceilings, not
// design targets.
const SN_MATURITY_ROADMAP_OPTION       = 'snt_maturity_roadmap_board';
const SN_MATURITY_ROADMAP_MAX_FAMILIES = 12;
const SN_MATURITY_ROADMAP_MAX_ITEMS    = 12;
const SN_MATURITY_ROADMAP_MAX_ITEM_LEN = 400;
const SN_MATURITY_ROADMAP_MAX_LABEL_LEN = 80;

/**
 * The 'done' column's own, TIGHTER ceiling (v10.76.0).
 *
 * Every other column churns — rows leave by graduating or by being dropped.
 * 'done' only grows, and since v10.63.0's "fold the future" it is the column
 * left OPEN while the future tenses fold, so it is also the one that governs
 * how tall the board reads.
 *
 * The generic 12-item ceiling is the wrong instrument for it, because the
 * failure at that ceiling is WHOLESALE: the roadmap write replaces the entire
 * board, so the first family to overflow fails gate 2 and blocks EVERY board
 * edit — including the one that would fix it. The same validator guards the
 * read path (sn_maturity_roadmap_effective_report()), so an over-cap board
 * is not served: the public page reverts to the static floor, flagged invalid
 * for the Health check.
 *
 * A tighter ceiling does not make that failure shape better; it makes the
 * board hit it while the board is still small enough to fix by hand, and it
 * lets the refusal name the fix. The actual prevention is the CI canary in
 * tests/maturity-roadmap-shortcode.php, which reds one row BEFORE the wall.
 *
 * Graduating a row off the hub is not deletion: the family maturity pages
 * already state what acts today, so a shipped row that leaves this board is
 * still on record one level down.
 */
const SN_MATURITY_ROADMAP_MAX_DONE = 5;

// The rest of the module lives in three parts files (house line cap, 2026-10-15),
// loaded in the order it always ran so the shortcode registration keeps its place.
require_once __DIR__ . '/maturity-roadmap-shortcode-board-parts.php';
require_once __DIR__ . '/maturity-roadmap-shortcode-gate-parts.php';
require_once __DIR__ . '/maturity-roadmap-shortcode-render-parts.php';
