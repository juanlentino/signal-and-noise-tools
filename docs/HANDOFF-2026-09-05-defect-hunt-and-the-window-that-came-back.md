# Handoff — 2026-09-05: the defect hunt, the workers, and the window that came back

Seven plugin releases (v13.97.0 → v13.98.0), one theme release (v12.18.9), four
worker patches — all merged, tagged, drafted, and installed by the owner from
wp-admin. Live verified at 19:30 UTC: plugin 13.98.0, theme 12.18.9, workers
1.21.1 / 1.12.1 / 1.18.1 / 1.24.1 / 1.4.0, every deploy row `ok`.

## What shipped (do not redo)

**Plugin v13.98.0** (#1044 → #1046 → #1048, stacked, cut as one minor):

- Fourteen more health-check bail-outs that reported a pass when they had not
  run, plus two checks that structurally could not say so (`missing_alt` built
  its envelope by hand; `stale_posts` shared a query with no failure signal).
  Guard: `tests/health-pack-check-empty-findings-say-why.php` — a call with
  literally empty findings MUST state its fourth argument. (#1042)
- 25th check `machine_reader_liveness` (the dataset can tell a dead sensor from
  a quiet day; the worker's isolate-memory readout cannot). The snapshot now
  carries `by_day`. 26th check `theme_presets` (declared vs served, the live
  half of the theme guard). (#1045)
- **The Signal & Noise window** — `apps/signal-noise/`, an OpenStation App
  Framework app loaded via `openstation_apps_directories`. Successor to the
  WP Explorer folder from #751, whose two filters OpenStation 1.1.6 retired
  (entities filter INERT, window_args gone, no error anywhere). Sections are a
  registry (`snt_os_app_sections`); a new surface is one descriptor. (#1047)
  **Rejected on sight the same evening** — a flat server-rendered list, newest
  date first (the scheduled queue filled page 1), empty icon squares
  (`<os-icon icon>`; the attribute is `name`), verified against one seeded
  note. **Rebuilt as a client view in WP Explorer's idiom in #1049 / PR
  #1050** (folder tiles, `<os-tile>` canvas with the kit's ribbons and an
  anchor badge, `statusControl` pills, search, Icons | List, `<os-table>`,
  the Explorer's dossier column, a page on the phone; local reducers, no
  build). Verified in the sandbox against fifteen notes and six releases,
  desktop and phone. Awaiting the owner's word to merge and cut.
- Earlier in the day: v13.97.4 (cron offload observable + seven skips),
  v13.97.5 (H3→H2 on /stats and /maturity/roadmap; `<main>` on /verify).

**Theme v12.18.9** (#286, #287): theme.json now declares the spacing scale and
four font sizes the site ACTUALLY serves. Since WordPress 6.6 core drops a theme
preset whose slug collides with a core default unless the family's `default*`
flag is false; the theme served core's geometric spacing scale and 13/20/36/42px
for its whole life. Byte-identical `wp_get_global_stylesheet()` before/after.
Notes index rows are H2. Guard: `tests/theme-json-default-presets.php`.

**Workers:** analytics 1.21.1 (72 h salt TTL so yesterday's salt exists; AE
write failures counted as `write_error`), login-guard 1.12.1 (null-not-zero on
`/status`; list-before-meta write order), provenance 1.18.1 (`followedLedger`
finally read → `signer-key-mismatch` status reason), rights-signals 1.24.1
(detail-write failure no longer flips the aggregate sensor).

## Open — owner-observable, nothing blocking

- **Design decision, not a bug:** restore the theme's ORIGINALLY declared
  spacing/font sizes? It would change spacing everywhere by 2–3× and the body
  font size. Offered in signal-and-noise#286, not taken. Leave unless asked.
- **Content-side headings** (owner edits, no code): /services/ jumps H1→H3;
  /provenance/ and /contact/personal/ have no H1.
- **#1002** 503 watch: registered in `inc/watches.php`, due 2026-09-12,
  baseline 10 per 24 h. **#1006** header cliff: open at low priority, not fixed
  by decision.
- **Health scan** has not re-run since the installs; `checks_total` should read
  26 on the next scheduled run. `machine_reader_liveness` reports skipped until
  the snapshot cron captures its first `by_day`.
- **Analytics** `/_sn/version` → `salt.prev_present` turns true from the first
  afternoon after two rotations under the new TTL.
- **Provenance** `/_sn/status` → `signer.followed_ledger` should be true. If it
  ever reads `key-not-held`, that IS the missed key swap — a Health finding
  with no plugin change.

## Coordination

- `inc/desktop-mode-explorer.php` (the v12.4.0 module) stays for its
  `sn_provenance` REST field; its filters are inert on 1.1.6 and harmless.
- OpenStation upstream: nothing to file. The retirement was documented
  ("Experimental (filter, inert)"); we just never read it.
- The SQLite sandbox recipe (WP 7.1 + OpenStation 1.1.6 + this plugin) is in
  memory `openstation-app-framework-port`; it reproduces framework behaviour
  the standalone stubs cannot (two real defects were caught only there).

## Operational lessons (also in memory)

- A signal computed and never read (`followedLedger`, `prev_present`,
  `cron_skipped`) is the consumer-side twin of "a readout that cannot separate
  two states". Grep every returned field for a reader.
- An edit script that did not run reports nothing: heredoc PHP without
  `<?php` prints its source; confirm `git diff --stat` before measuring.
- Core drops colliding theme presets silently; the theme guard + the live
  check now cover both halves.
- App Framework: resolve sections at RENDER time (a list frozen at `init` is
  empty under WP-CLI); a symlinked plugin loses its stylesheet and its client
  script. The kit's `.os-file-tile` is `position: absolute` — in a grid cell it
  is in the DOM, computed visible, and paints nothing until the cell forces it
  back to `relative` (the Explorer's own rule). `<os-table>` and
  `<os-segmented>` live in shadow DOM: listen for `os-table-row-click`, and a
  synthetic `click()` on a segment does nothing. The docs' list of the
  pending-queue client API is shorter than `CLIENT_API` in `index.ts`; the
  code is the contract.
- A window on the desktop is judged against the desktop: build it from the
  shell's own parts in the shell's idiom, and verify it against a realistic
  population, never one seeded row.
