# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **The "Cited by" list no longer sits inside a note's signed content.** It was appended through `the_content`, which puts it inside the element the public ledger's checker reads against the signed text. No note has a public citation yet, so nothing failed; the first one would have turned that note's public check red, and anyone can cause a citation by linking to a note and sending a webmention. On a block theme the list is now placed after the post-content element, as its sibling. Run through the ledger's own extractor: inside the element the checked text changes, after it the text is identical.

## [21.5.0] - 2026-10-04 — the widgets lead with their figure, and only the owner changes the doors

### Added
- **A claim shown to nobody can be forgotten.** Integrity › Citations kept every webmention claim forever, so a test claim (an `example.com` page that never linked here) sat in the list with no way out. Under the table, on the classic page and the native leaf alike, one small form now lists the claims in the two tiers the site shows to nobody (asserted, unverified) and removes the chosen one. A citation the site displays (verified, unattributed) is never offered, and the handler reads the tier from the stored row, not from the form, so a crafted request cannot remove one either. If the source sends its webmention again, it is a new claim.

### Changed
- **Only the owner changes what the MCP doors expose.** An ability reaches a door only by being on one of three lists in code (read, write, remote). A new test pins those lists whole against `tests/fixtures/mcp-door-manifest.json` and fails when a new file names the filters the lists pass through. A new required check, `door-owner`, fails a pull request that touches anything but prose (`docs/` and the top-level Markdown files) unless the owner was the last to push to its branch (Dependabot's own bumps aside); the owner takes over someone else's pull request by pushing to it. Everything else is owned because an exposed ability's behavior lives wherever its callback reaches: server code, the scripts that run in the owner's browser, data files, the served skill files, tests, tools and the repository's automation. The check runs from `main`, never from the pull request's copy, checks nothing out, reads the last pusher from the branch's activity log, and fails when it cannot read the changed files. The owner's pull requests merge as before, with no approval step. Nothing a door exposes changed.
- **The desktop widgets say more with less.** SN Audience opens with its visitor-days and SN Reading with the engaged share and its change, the way SN Site Views opens with its views; both end with the time the reading was taken. SN Machine Readers opens with who the readers are (verified by Cloudflare, named and not verified, not measured), and the per-surface list under the declared AI-training reads is the three largest plus one line for the rest; the direct rights-file row stays. SN Quick Actions no longer carries Full reset: a purge of every cache is not one click from the desktop, and it stays under Maintenance on the Dashboard, behind its confirm.

### Fixed
- **SN Health names a check that could not run.** The card read "17/18" beside a green dot and named nothing, because the eighteenth check was skipped, not failed. It now lists the check with its reason on hover, and the dot is amber while one exists.
- **SN Cron no longer reads "due" for a job that is on time.** WP-Cron runs on a page load, so a five-minute job is past its time for seconds at every load. It reads "running now", and only past ten minutes does it read late and turn amber.
- **SN Anchors paints the Internet Archive as rows**, the run and the captures, not a three-line sentence. Each run state reads in its own words (not configured, running, halted, not pushed yet, every note asked), a request past its cutoff with no answer is counted apart from one still waiting, and a halted run, a failed capture or an unanswered one is amber.
- **SN Reading's custom events carry their units**: "3 events · 3 visitor-days", and "1 visitor-day" when it is one.
- **The Dashboard's Full reset asked the wrong question.** Its confirm said every setting would be reset to default; the action clears template overrides and purges caches and changes no setting. The confirm now says that.

