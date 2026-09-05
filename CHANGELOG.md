# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- The Signal & Noise window: a Notes + Discography app on OpenStation's App
  Framework (`apps/signal-noise/`, loaded through `openstation_apps_directories`).
  It is the successor to the WP Explorer folder OpenStation's maintainer
  contributed in v12.4.0 (#751): OpenStation 1.1.6 rebuilt WP Explorer on the
  framework and retired the two filters that folder hung on -- the hooks
  reference marks `openstation_my_wordpress_entities` inert and
  `_window_args` gone -- so the folder stopped rendering with no error anywhere.
  The rebuilt Explorer's seams carry whole post types only; Notes are posts in a
  category and the Discography is an option store, so neither fits there. The
  app carries both: Notes with the anchor-status chip, the signed commit chain,
  the ledger UID and the editor as a window; the Discography with cover art,
  roles, tracks and the Spotify / Muso links. Server views throughout -- no build,
  no bundle -- so the phone layer paints it too. Sections are a registry
  (`snt_os_app_sections`): a future surface is one descriptor with two callables
  and the shared frame paints it. The v12.4.0 module stays for its REST field.
  (#1047)
- A 25th health check: the machine-reader dataset went quiet. The rights-signals
  worker's sensor readout (`sensor.last_write_ok`) is isolate memory -- null until
  that isolate has attempted a write, and a fresh isolate on every colo -- so it
  reads null both on a normal afternoon and for a sensor that quietly stopped
  matching crawlers; the edge-workers check flags only `false`. The dataset is what
  can tell: at ~450 machine reads a day, a day of zero is a signal. The durable
  snapshot now carries a per-day series (`by_day`), and the check flags a zero
  day (relative to the CAPTURE, whose days are complete) against a baseline mean of
  at least 20/day, naming how many days the silence has lasted. It cannot say
  whether the silence is the sensor or the crawlers, and its note says so.
- A 26th health check: theme.json declares a preset the site does not serve. The
  live half of the theme's v12.18.9 guard (theme #284): since WordPress 6.6 core
  drops a theme preset whose slug collides with a core default unless the family's
  `default*` flag is false, and the theme served core's whole spacing scale that way
  for its entire life. The check compares the active theme.json's declared slugs
  per family with the merged settings' `theme` origin; a remainder names the family,
  the slugs and the flag. Flat (non-origin-keyed) settings are a skip, never a pass.

### Fixed
- Fourteen more health-check bail-outs reported a pass when they had not run, and
  two checks had no way to say so at all. v13.97.4 fixed seven calls that put the
  reason in `fix_hint`; a silent-failure audit of all 70 call sites found the
  class was wider. Now saying skipped: the ledger-CI `unknown` state (malformed
  API body, or no completed run yet -- the branch beside the one fixed last
  time), the rights probes when every fetch fails or the ledger index is
  unreachable, the provenance sweep when ext-intl is absent, three kill switches,
  two failed post queries, two modules-not-loaded guards and the related-notes
  artifact not yet built. Two checks conflated a failed query with a corpus of
  fewer than two posts; those are different answers and now say which.
  `missing_alt`, check number one, never used the shared envelope -- it built
  its array by hand with no `skipped` field, so three failed queries reported
  "no missing alt"; it now routes through `sn_health_pack_check()` and names
  the pass whose query failed. `stale_posts` and `stale_posts_evergreen` share
  one query with no failure signal; the scan carries `ok` and both consumers
  read it. The cadence adapter computed `cron_skipped`/`views_skipped` and then
  read only `flags`; it carries the skip through. The drift check `continue`d
  past every per-post AI failure with no count -- a provider outage on every
  candidate read as "no drift"; it counts, and every-call-failed is a skip.
  (#1042)
- A bold rule on the provenance version list has never matched: the selector
  named `.sn-prov-vlabel` and `code:first-child`; the markup emits `.sn-prov-v`
  as the first child. Same shape as the theme's `.sn-compare__title`. (#1043)
- 27 `var(--wp--preset--color--…)` fallbacks in the front-end stylesheets were
  `rgba()` guesses that disagreed with the opaque tokens they stand in for --
  `rust` as `rgba(0,0,0,.25)` is `#bfbfbf` where the token is `#666666`. None
  fire on this site; they are what the block gets anywhere else. Each is now
  the token's value. The two x-large reads from v13.97.5 gain a fallback. (#1043)

### Internal
- `tests/health-pack-check-empty-findings-say-why.php`: every
  `sn_health_pack_check()` call whose findings argument is literally `array()`
  must pass a fourth argument -- `null` for ran-and-found-nothing, a reason for
  did-not-run. A three-argument call with an empty literal is syntactically
  complete, which is why the pattern recurred; the decision now has to be made
  at the call site. Token-parsed, self-controlled on an in-memory fixture, and
  run against main before #1042 landed: 21 sites. (#1042)

## [13.97.5] - 2026-09-05 — two public pages that skipped a heading level

### Fixed
- `/stats/` and `/maturity/roadmap/` no longer skip a heading level. `[sn_public_stats]`
  emitted `<h3>Reading rhythm</h3>` and `<h3>Most read</h3>`, and `[sn_maturity_roadmap]`
  `<h3>Roadmap</h3>`, each directly under the page's H1 post title with nothing
  between (WCAG 1.3.1). Both are H2 now. Both stylesheets qualified the heading by
  element, so the selectors moved with the tag; and because theme.json styles a
  page-level `h2` at xx-large with tracking, the size and tracking are pinned to
  what the H3 rendered at (the theme's x-large preset), so nothing moves. Found by
  auditing 40 live pages as rendered, not from source. (#1040)
- `/verify/` has a `<main>` landmark. The standalone verifier had `<header>`,
  a labelled `<nav>` and `<footer>`, and its panels sat in a bare `<div>` -- no
  skip target on the one page whose entire purpose is reading the panels. (#1040)

## [13.97.4] - 2026-09-05 — two readouts that could not tell "didn't run" from "nothing wrong"

### Added
- A 24th health check: WP-Cron still spawning from ordinary page requests.
  v13.97.2 defines `DISABLE_WP_CRON` at load, and whether that happened was not
  observable — `cron_health`'s `cron_disabled_constant` is a PROBLEM FLAG
  (constant set AND nothing fired recently AND no system cron declared), so it
  reads false when the constant is absent and false again when the constant is
  set and everything works. Two opposite situations, one value. Within an hour
  of shipping v13.97.3 I read that field by its name, concluded the offload was
  inert, and began fixing a bug that was not there.

  The offload now records which of five things happened, and the check fires on
  the two that leave cron in the request path — `already_false` (wp-config
  defines the constant FALSE, so we decline to override and the offload is inert
  while looking installed) and `declined_filter`. They get different notes:
  one is a wp-config line to change, the other a choice someone made. (#1037)

### Fixed
- Seven health checks reported a PASS when they had not run. `sn_health_pack_check()`
  grew a `$skipped` parameter in v11.33.0 precisely because "zero findings"
  covered both "nothing wrong" and "could not run" — and seven calls were still
  passing their bail-out reason as `fix_hint`, which the tally never reads: the
  Cloudflare header probe (×2), the ledger CI check, the two rights probes (×3
  between them) and the edge-workers check. Each now reports as skipped.
  `cf-security-headers.php` already did this correctly one branch away, so it
  was drift rather than a considered choice. (#1039)

### Internal
- Fourteen test-local `sn_health_pack_check` stubs still had the pre-v11.33.0
  shape — three parameters, no `skipped` key — so they silently discarded the
  fourth argument. **A suite whose stub cannot carry the field cannot fail when
  a check forgets it**, which is why the seven above sat mis-reported for two
  minor versions on a green board. Thirteen are updated; one is exempt because
  it returns a positional pair and never claimed to be the envelope.
  `tests/health-pack-check-stub-parity.php` now reads the real signature from
  source and fails on drift, exempting by SHAPE rather than by filename so it
  cannot rot when a file is renamed. On its first run it caught ITSELF — its
  negative control is a literal stub definition — which is the clearest
  evidence the matcher works; it is excluded by path, not by pattern. (#1039)
- The field belonged next to `cron_disabled_constant`, and could not go there.
  That payload is one of eight remote-MCP twins whose output_schemas ARE the
  versioned contract, shape-hashed by `tests/remote-contract-shapes.php`. Adding
  it failed CI exactly as designed, and bumping to version 5 would leave the
  door expecting 5 while the worker runs 4. So the field was reverted and the
  check surface — which carries no such contract — is used instead. The
  misleading field keeps its name, since renaming is the same bump, but now
  carries a comment saying what it really means. (#1037)

## [13.97.3] - 2026-09-05 — a number worth going back for

### Added
- A watch on the origin 503 count, due 2026-09-12. The baseline is **10 per 24h**,
  measured BEFORE v13.97.2 stopped a visitor's pageview paying for a cron run
  averaging 10.6s. If the count has not fallen, the remaining cause is the
  FPM pool's size rather than the work removed from it — and a server resize
  would then be buying a bigger pool, not more RAM. Resizing before that
  reading spends against an untested guess.
  Registered `date_only`, which this file reserves for "a re-read of a number
  that will not announce itself" — the 503s are counted by the HOST, so there
  is no state here for the site to notice on its own. Read it from Cloudways
  app traffic → `top_statuses`, and compare against 10. (#1002)

