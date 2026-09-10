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
- **Broken links is one line per path, not one form per path.** A section per broken path is the right shape for one and the wrong shape for twenty: measured live 2026-09-10, 20 paths at ~450px each painted a **9,101px leaf — nine screens of scrolling** to reach the Clear button, because every path carried a create-redirect form open by default. Each path is now a fold headed by the path and hinted with its hits and last-seen date; the form opens on the one you are acting on. Same primitive the audit log already uses for its login list.
- **Both lists are capped.** The 404 log lists the 25 busiest paths and the redirect map the 50 most recent, each stating the true total — the log's real count comes from the status line above it, so a cap can never make the log look smaller than it is. The two caps are defined once, in `inc/redirects-404-log.php`, because the classic renderer and the OpenStation leaf are parity-tested against each other and two copies of the number would pass review and drift on the first edit.

### Fixed
- The app stylesheets were linked twice. OpenStation auto-loads `apps/<id>/<id>.css` under its own handle; we pushed ours at the same file into the window's styles list unconditionally, so the admin carried two `<link>` tags each for `sn-dashboard.css` and `sn-analytics.css`. The extra request is the small half — the two cache-bust on **different inputs** (theirs on `filemtime`, ours on `SNT_VERSION`), and ours loaded **last**, so a CSS edit without a version bump served a fresh copy that was overridden by a stale cached one. Ours is now dropped only when theirs is actually present, so an OpenStation without the auto-loader still gets a styled window. The same guard already existed for the `signal-noise` app and had never been extended to the two host windows.


## [13.109.7] - 2026-09-10 — the leaf width cap that never released a leaf

### Fixed
- Every leaf in S&N Home was clipped to 820px, and two of the six selectors meant to release that cap had never worked. They are `:has( os-table )` and `:has( os-row )`: a type selector scores `(0,3,1)` against the cap's `(0,4,0)`, so both **matched the leaf and were overruled**. Measured live in an 1820px window, `connections/webhooks` computed to `max-width: 820px` with its hatch matching — a 820px column with the right half empty. Every escape selector now carries the cap's own `.snt-dashboard-body >` shape and wins outright rather than on a source-order tie. `connections/webhooks` goes 820 → 1748px and paints the two-column layout it was always built for. This was diagnosed as a 19-leaf redesign for most of a session: three source-level censuses agreed with each other and all three were wrong, because at a ~900px window the cap is invisible. Only asking the browser which rules won settled it.
- `connections/cron`'s Args column no longer claims the table. One analytics rollup event carries a 1315-character JSON payload, and under `table-layout: auto` that single unbreakable cell took **2668px of a 3369px table** — every other column collapsed to its minimum, which is why timestamps wrapped onto four lines while the table scrolled sideways. The cell is clamped to one line and reports how much was elided, so a truncated value never reads as complete. The clamp lives in the data because `os-table` renders cells inside a shadow root exposing only `part=scroll`; no stylesheet outside it can reach a `td`.
- The 404 log's redirect suggester stopped proposing nonsense. Measured on the live log 2026-09-10: **192 paths** presented as actionable against **8** classified as scanner probes, and **every wrong suggestion scored exactly 66.7%** — `account`/`about`, `metrics`/`services`, `falsifiability`/`accessibility`, `users.js`/`uses`. `similar_text()`'s percent is `2*matched/(len1+len2)`, so weak pairs land on two thirds repeatedly and the 65.0 floor sat **1.7 points below where noise clusters**. The floor is now 72.0; the one genuine suggestion in the same log (`as-substrate.js` → `as-substrate`, 88.9%) is unaffected.
- Paths that were never content are no longer logged as broken links at all. A file extension this site does not publish is rejected outright — with an allowlist for things a human could genuinely have linked to (`pdf`, images, `txt`, `xml`, `ics`) — as are the infrastructure namespaces `/api/`, `/apis/`, `/_sn/`, `/v1/`, `/graphql`, `/rest/`, `/oauth`. This was not hypothetical harm: `/about.php7` was suggested → `/about` and **accepted**, so a vulnerability-scanner probe is now a permanent 301 in site configuration. `/metrics`, `/health`, `/status`, `/debug`, `/console` and `/swagger` join the single-segment scanner-guess list.
- A real broken link still surfaces. `/notes/desing-tokens` (a genuine typo), `/tag/falsifiability` (a real archive that was merely mis-suggested), `/notes/hero.png` and `/resume.pdf` are all still captured — pinned as negative controls, because a filter that quietly swallows real 404s would be worse than the noise it removes.

### Added
- `tests/os-leaf-width-cap-escape.php` computes CSS specificity from the stylesheet and asserts every cap-escape selector **strictly** outranks the cap — a tie fails, because a tie is decided by source order and a reorder would silently re-cap a leaf. The specificity calculator is unit-tested before it is trusted, including that `:has()` of a type contributes a type and `:where()` contributes nothing. It also had to learn to tell an escape from an unrelated rule that merely mentions a leaf: `.snt-leaf os-row > [col]` styles a column inside one, and comparing only the selector's final compound keeps it out.

### Changed
- The 404 log is its own screen. Site → Redirects was carrying two unrelated jobs in one view — the redirect table in the main column and the 404 log squeezed into a rail beside it — and the rail forced the leaf into a two-column layout when it has one column of content to paint. The log, the automated-probe bucket and every 404 action now live under **Site → Broken links**; redirects keeps the table and takes the full width. The classic admin splits the same way, with a matching `broken-links` tab. Both leaves gained assertions proving the *other* leaf's content is absent from them, and that split was negative-controlled before being trusted: injecting 404 copy into the redirects painter reddens the guard, and unregistering the broken-links painter reddens 18.

