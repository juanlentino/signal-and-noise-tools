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

