# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- Analytics board graduation: signature verification moved later → done, rewritten so the claim carries its own limit (the crawlers that actually sign are search and testing tools; the AI crawlers sign nothing). It had been sitting in the lowest commitment tier while live since rights-signals worker v1.24.0. To make ceiling room, "Search-side metrics from Search Console" retired off the board and its claim graduated onto `/maturity/analytics/` as a 14th principle — graduation off the hub is not deletion. The count pin moved 13 → 14 in the same change and was negative-controlled (dropping the principle reds it).
- Refilled AI/considering, which fell to a single row after the scheduled-agent demotion, with two ideas built on machinery that already exists: a count of every write the doors decline set against the rule that declined it (the deliberate companion to the shipped spend ledger — what it cost beside what it would not do), and an accepted change carrying whether a machine proposed it and a person accepted, inside the version the chain already re-anchors (custody untouched; a thread shared with Proof of origin). Both clear the denied-list perimeter; neither asks for new collection. Board pins hold: 21 folds, no empty cell, no cross-column duplicate, `done` unchanged.
- Public roadmap board re-ranked: struck the duplicate "second, independent anchor" row from Proof of origin/considering (the parked *Second timestamp anchor* watch already owns it, with two named triggers), and demoted three considering rows to later — AI "Scheduled read-only agent runs" (downstream of the DISABLED native-agents gate), Analytics "A reading ledger that never phones home" (reader-facing, no measurement payoff), and Proof of origin "A witness that is a peer" (needs a second site to agree; none exists). No column emptied and the `done` column is untouched, so both board tripwires are unaffected. Rationale recorded per row in the source and in docs/BACKLOG.md.

## [13.107.7] - 2026-09-08 — Redirect guard fails closed on options it cannot read

### Fixed
- Close a blind spot in `tests/outbound-credential-redirect-guard.php`: a call whose options arrive as a variable (`wp_remote_get( $url, $args )`) or whose headers do (`'headers' => $headers`) hid its credential from the scanner. Measured — a probe assembling a Bearer into `$args` one line above the call passed the suite GREEN, exit 0, and was not even counted among the credentialed calls. Such opaque calls are now treated as credentialed by default. Their guard is then **read** from the enclosing function scope rather than asserted by a comment, so the four existing opaque sites (`inc/wp-update-integration.php`, `inc/health-check-rights-anchored.php`) verify with no annotation and no source change. A `redirect-ok:` note remains available for an opaque call that genuinely needs none, matching the annotate-or-guard contract the five worker repos now use. Credentialed call sites recognised: 14 → 18.

