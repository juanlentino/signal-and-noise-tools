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
- **The owner's analytics rows can tell the two /resume lanes apart: `resume_lane`.** Each switch is a page view, but the beacon stored only the path, so music and operations read as one page. The theme beacon now sends the lane a view showed on every beacon of it, and the analytics worker (1.28.0) stores it as `double17` of the second-generation rows. `resume_lane` is a new dimension of the rows read on both doors (`sn-metrics{analytics_rows}`, `sn_remote_analytics_query`) and in the fields read: `music`, `operations`, or `(unknown)` on every other page and for a view stored before the worker kept it; the stored code is mapped, never passed through, so anything else is `(invalid)`. It reads Analytics Engine only (about 90 days; `source: stored` and the session dimensions refuse it), and while the second generation is not verified the read refuses it rather than call every view `(unknown)`. Time and scroll per view are kept per lane, as per page, because every beacon of a view carries its lane. Named `resume_lane`, not `lane`, because lane already means a traffic lane here. Owner-only: no public surface reads it, and a test fails if a file other than the rows read, its registry and the contract names it. Remote contract 28 to 29 (each row may carry `resume_lane`; additive), RED-then-pin; `tests/fixtures/remote-output-schemas.json` regenerated for the remote MCP Worker. `inc/analytics-rows.php`, `inc/analytics-rows-events.php`, `inc/analytics-fields.php`, `inc/abilities-analytics-rows.php`, `inc/abilities-sn-metrics.php`, `inc/mcp/mcp-remote-contract.php`. Tests: `tests/analytics-rows-resume-lane.php` (new, 31), `tests/remote-contract-shapes.php`.

## [2026.11.8] - 2026-11-02 — The résumé's Markdown follows the lane: /resume.md?lane=operations

### Changed
- **The résumé's Markdown follows the lane.** `/resume.md?lane=operations` (and `?output_format=markdown` on the operations lane) is the operations résumé: its headline and summary, What I've Built, its experience and sections, and no phone. On `/resume/?lane=operations` the page's `Link` header and head alternate name it, so an agent asking that address for Markdown gets that lane. The rights-signals Worker 1.37.2 reads the lane query; until then it converts the visible lane, as of 1.37.1. A section with nothing in it is left out of the Markdown. The music Markdown is unchanged byte for byte, pinned to main's output. `tests/resume-page-lanes.php` (+4).

