<?php
/**
 * Signal & Noise Tools: scheduled-content engine (table + row accessors).
 *
 * Phase 1 of the scheduled-content subsystem: a cache-coherent scheduler
 * that flips hand-authored content on/off on a date inside already-published
 * pages, purging only the affected Cloudflare URLs at each boundary.
 *
 * This file owns ONLY the queue table (wp_sn_schedules) plus its row
 * accessors. The render gate, the sn/scheduled dynamic block, the save_post
 * sync, the fire/purge handler, and the admin surface ship in later tasks
 * and reuse the column contract defined here.
 *
 * Schedule rows are addressed two ways:
 *   - schedule_id (a block uuid) for fragment rows mirrored from a post's
 *     sn/scheduled blocks. Upsert is idempotent on this key so a re-sync of
 *     the same block updates its one row rather than duplicating it.
 *   - empty schedule_id for table-canonical rows authored directly against
 *     the queue (page / theme_block / swap targets). Each such insert is its
 *     own row; they are never coalesced.
 *
 * Constrained columns (target_type, action, status) are VARCHAR, NOT MySQL
 * ENUM: dbDelta does not round-trip ENUM cleanly and would try to ALTER the
 * table on every install check (a known WP gotcha). Values are constrained at
 * the application layer in later tasks, not by the schema.
 *
 * Install pattern mirrors inc/analytics-events.php: constants, schema_sql,
 * install, maybe_install (version-gated on init). NOT yet required from the
 * plugin bootstrap; that wiring lands in the final task.
 *
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SCHEDULES_TABLE           = 'sn_schedules';
const SN_SCHEDULES_DB_VERSION      = '1';
const SN_SCHEDULES_DB_VERSION_OPT  = 'sn_schedules_db_version';

/**
 * The four states a row can hold, in the order the fire handler walks them.
 *
 * The vocabulary was four literals in five places (the fire handler's two
 * transition sets, the reconcile sweep's repeat of both, and the column
 * default). A READER now needs the set too — the app's Scheduled section
 * paints one status pill per state — and a sixth copy is the point at which a
 * fifth state could ship and be shown by nobody. The engine's own literals are
 * deliberately left alone here: replacing them is a behaviour-free refactor
 * that belongs in its own change, where its tests can say so.
 */
const SN_SCHEDULE_STATUSES         = array( 'queued', 'active', 'done', 'error' );

/**
 * The cron hook fired at each window boundary. The save_post sync (Task 5) arms
 * and clears events on this hook; the flip + Cloudflare purge handler (Task 6)
 * registers against it. It lives in this foundational file, required first in
 * the bootstrap, so the constant is defined before either consumer references
 * it: the sync module references it, and Task 6's fire handler (which ships in
 * THIS file) will add_action on it at file load. One source of truth for the
 * hook name; no second definition.
 */
const SN_SCHEDULE_FIRE_HOOK        = 'sn_schedule_fire';

/**
 * The recurring cron hook for the reconcile tick (Task 6). WP-Cron drops a
 * single boundary event on a quiet site (it only runs on a front-end request),
 * so a 5-minute reconcile sweeps the queue, catches up any boundary that should
 * have fired but did not, and re-arms any future boundary that has lost its
 * single event. The handler ships in THIS file and add_action's on the constant
 * at file load; one source of truth for the hook name.
 */
const SN_SCHEDULE_RECONCILE_HOOK   = 'sn_schedule_reconcile';

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/schedule-engine-store-parts.php';
require_once __DIR__ . '/schedule-engine-query-parts.php';
require_once __DIR__ . '/schedule-engine-cron-parts.php';
