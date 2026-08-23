# Refactor plan: `inc/admin-post-actions.php` (1,682 lines)

> **STATUS: EXECUTED in v12.21.2.** The split is DONE — all 15 domain files
> exist under `inc/admin-post-actions/`, and `inc/admin-post-actions.php` is a
> 52-line loader. **Do not run this plan again.** It is kept as the record of
> why the split was shaped this way, not as queued work.
>
> What actually happened, against what this plan predicted:
>
> - 63 top-level functions, not 64. The map has 62 entries; the extra
>   declarations are helpers with no action of their own.
> - The full sweep held at **496 suites / 19,786 assertions / 0 failed /
>   1 skipped** at every one of the 15 commits, and `tests/admin-post-actions.php`
>   stayed at 238/0 throughout.
> - **The plan missed a trap**: three suites — `tests/ml-embeddings.php`,
>   `tests/spend-watch.php`, `tests/audit-retention-bounds.php` — assert on the
>   SOURCE TEXT of `admin-post-actions.php` as a stand-in for "the admin-post
>   layer". A pure move breaks them. They were made layer-aware FIRST, in their
>   own commit, verified green BEFORE anything moved, so that a later red could
>   only be the move.
> - **Still over the house rule**: `content.php` (382) and `analytics.php` (364).
>   The second pass described below is NOT done. See the CHANGELOG for why it was
>   deliberately left: both are cohesive, and splitting them further trades one
>   over-long file for a helper-sharing seam that has to be got right.


**Written 2026-08-23** as a fresh-context handoff. Everything needed is here;
you should not need the session that produced it.

## The target

`inc/admin-post-actions.php` — **1,682 lines, 64 functions**, the largest file
in the plugin. It holds every wp-admin POST handler. The house rule is ~150
lines per file; this is 11× that.

Runners-up, for context — do these later, same method:
`inc/content-migrations.php` (1,442), `inc/ai-bootstrap.php` (1,054),
`inc/analytics-view-overview.php` (996).

## Why this is LOW RISK — read this before worrying

Three properties make the split close to mechanical:

1. **Dispatch is by NAME, not by file.** `sn_admin_post_handlers()` in
   `inc/admin-post-handler.php` is an explicit map of 62 entries,
   `'action_name' => 'sn_handle_function'`. It does not care which file a
   function lives in. **You never edit this map.**
2. **Every handler shares one contract**: `fn( array $post ): string`, returning
   a flash code. No shared state, no inheritance, no ordering between handlers.
3. **Coverage already exists**: `tests/admin-post-actions.php`, 843 lines,
   **238 assertions, currently 0 failed**. It calls handlers directly, so it
   keeps working through the move without edits.

## The strategy: a thin loader

`inc/admin-post-actions.php` is required in exactly two places —
`signal-and-noise-tools.php:203` and the test suite. **Do not remove it.**
Reduce it to a docblock plus `require_once` lines for the new domain files.

Then: the bootstrap is unchanged, the dispatch map is unchanged, the test suite
is unchanged, and every consumer keeps resolving. The blast radius is one file.

```php
// inc/admin-post-actions.php after the split
require_once SNT_PATH . 'inc/admin-post-actions/system.php';
require_once SNT_PATH . 'inc/admin-post-actions/cloudflare.php';
// … one line per domain
```

Use `SNT_PATH` (defined in the plugin bootstrap) with a `__DIR__` fallback, since
the test suite requires this file without the plugin loaded — check how the
suite bootstraps before assuming `SNT_PATH` exists there.

## Proposed split — 15 files, ~110 lines average

Line numbers are from the 1,682-line original at v12.21.0.

| new file | handlers | orig lines | approx |
| --- | --- | ---: | ---: |
| `system.php` | clear_overrides, purge_caches, full_reset, save_identity, save_login | 24–62 | 50 |
| `cloudflare.php` | cf_save, cf_purge_now | 63–90 | 30 |
| `health-insights.php` | health_scan, insights_run/dismiss/snooze/mark_done, save_insights_settings | 91–212 | 120 |
| `webhooks.php` | webhook_add/update/delete | 105–137 | 35 |
| `reports.php` | audit_save_retention, security_digest_save, morning_brief_save, scheduled_reads_save | 213–279 | 70 |
| `content.php` | now_save, uses_save, resume_save + the `sn_content_*` / `sn_*_rows_to_text` helpers | 280–627 | 350 |
| `scans.php` | pattern_adoption_scan, block_migrations_scan | 628–653 | 30 |
| `monitoring.php` | monitoring_save, perf_save | 654–718 | 65 |
| `theme-ai.php` | sn_theme_ai_models, sn_theme_ai_vision_models, save_theme, ai_settings_save, ml_embed_compare | 719–906 | 150 |
| `music.php` | music_save_cred, music_save, music_sync | 907–1001 | 95 |
| `tags.php` | tag_merge, tag_ai_suggest, tag_ai_apply, tag_prune_unused | 1002–1126 | 125 |
| `indexnow.php` | indexnow_save, indexnow_regenerate, indexnow_ping_now | 1127–1179 | 55 |
| `analytics.php` | analytics_save/test/exclude/tuning/funnels/export, funnels_error_flash_code, collector_save, machine_readers_save | 814, 1180–1229, 1370–1622 | 250 |
| `gsc.php` | gsc_property_save, gsc_sync, gsc_test, gsc_credential_save | 1230–1369 | 140 |
| `mcp.php` | bind_mcp_rw_credential, remote_toggle | 1623–1682 | 60 |

**`content.php` (350) and `analytics.php` (250) are still over the house rule.**
Split them further only if a natural seam exists — `content.php` may divide into
`content-now.php` / `content-uses.php` / `content-resume.php` with the shared
`sn_content_*` helpers in `content-shared.php`. Do that as a SECOND pass, after
the first split is green, so a regression has one obvious cause.

## Execution

Do it **one domain per commit**, sweeping between each. A single 1,682-line
move is one commit you cannot bisect.

Per domain:

1. Create `inc/admin-post-actions/<domain>.php` with the standard header
   (`if ( ! defined( 'ABSPATH' ) ) { exit; }` and a docblock naming the domain
   and the actions it serves).
2. **Move** the functions — cut, do not copy. A copy leaves a duplicate
   declaration and PHP fatals on `Cannot redeclare`.
3. Add the `require_once` to the thin loader, in the same relative order the
   functions had. Order should not matter given the contract, but preserving it
   removes a variable.
4. `php -l` the new file and the loader.
5. `bash tests/run.sh` — gate on `EXIT=0` **AND** `grep -c "^  FAIL"` being 0. A
   suite that fatals prints no FAIL line, which is why both are checked.
6. Commit with the domain named in the subject.

## Verification

- `php tests/admin-post-actions.php` must stay at **238 passed, 0 failed**
  throughout. If the count DROPS, functions went missing rather than moving.
- `bash tests/run.sh` — the full sweep, currently **495 suites, ~19,653
  assertions, 0 failed, 1 skipped** (`contracts-smoke.php`, CI-excluded, needs
  live WP — expected).
- Every action in `sn_admin_post_handlers()` must still resolve. Add a test that
  walks the map and asserts `function_exists()` for all 62 targets — cheap, and
  it turns "did I drop one?" from a hope into an assertion. Write this FIRST, so
  it guards the whole refactor.
- No behaviour change is intended. Do not "improve" a handler while moving it.
  If you spot a bug, note it and fix it in a SEPARATE commit after the move.

## Traps specific to this repo

- **Never `git checkout --` to undo a probe.** It restores the COMMIT, not the
  pre-probe state, and uncommitted work is lost. Back up to a file, or delete
  the probe lines by hand.
- **Workflow files are blocked by a write hook** — not relevant here, but it
  catches people mid-refactor.
- **`admin-registry` is a full-sweep contract.** Touching admin surfaces means
  running the FULL sweep, not a targeted suite.
- **The release ritual is THREE steps**: squash-merge → annotated tag (from the
  PRIMARY checkout; `git rev-parse --git-dir` must print `.git`) → `gh release
  create --draft --verify-tag` with awk-extracted CHANGELOG notes. Drafts stay
  drafts.
- Version this as a **PATCH** unless behaviour changes. It should not.

## What NOT to do

- Do not edit `sn_admin_post_handlers()`. If you find yourself editing the map,
  you have moved a function's NAME, which is out of scope.
- Do not delete `inc/admin-post-actions.php`. Two consumers require it by path.
- Do not combine this with the `content-migrations.php` split. One large file at
  a time, so a bisect lands somewhere useful.
