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
- Public roadmap board re-ranked: struck the duplicate "second, independent anchor" row from Proof of origin/considering (the parked *Second timestamp anchor* watch already owns it, with two named triggers), and demoted three considering rows to later — AI "Scheduled read-only agent runs" (downstream of the DISABLED native-agents gate), Analytics "A reading ledger that never phones home" (reader-facing, no measurement payoff), and Proof of origin "A witness that is a peer" (needs a second site to agree; none exists). No column emptied and the `done` column is untouched, so both board tripwires are unaffected. Rationale recorded per row in the source and in docs/BACKLOG.md.

## [13.107.7] - 2026-09-08 — Redirect guard fails closed on options it cannot read

### Fixed
- Close a blind spot in `tests/outbound-credential-redirect-guard.php`: a call whose options arrive as a variable (`wp_remote_get( $url, $args )`) or whose headers do (`'headers' => $headers`) hid its credential from the scanner. Measured — a probe assembling a Bearer into `$args` one line above the call passed the suite GREEN, exit 0, and was not even counted among the credentialed calls. Such opaque calls are now treated as credentialed by default. Their guard is then **read** from the enclosing function scope rather than asserted by a comment, so the four existing opaque sites (`inc/wp-update-integration.php`, `inc/health-check-rights-anchored.php`) verify with no annotation and no source change. A `redirect-ok:` note remains available for an opaque call that genuinely needs none, matching the annotate-or-guard contract the five worker repos now use. Credentialed call sites recognised: 14 → 18.

