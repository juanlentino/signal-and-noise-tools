# Handoff — 2026-09-06..08: the native port, the redirect sweep, the signed handshake

Picks up where `HANDOFF-2026-09-05` stops (plugin v13.98.0). Three days,
**plugin v13.99.0 → v13.107.5** (41 releases), one theme release, five worker
patches. All merged and tagged; `origin/main` is at v13.107.5 with a clean tree.

**Provenance of this document:** I did not do this work. I assembled it on
2026-09-08 from `git log` across ten repos plus `docs/changelog/v13.md`. Commit
subjects and changelog bullets are the evidence; anything about live install
state or owner verification is NOT in that evidence and is marked unknown below.

## What shipped (do not redo)

### The native OpenStation port — the whole arc

Two admin surfaces became native App Framework windows. The releases fall into
three clean phases, and the shape is worth reading before touching either app:

**Build (Sep 6, v13.99.0 → v13.106.0).** `v13.99.0` the window in the Explorer's
idiom; `v13.99.1` Citations at full width; `v13.99.2` the Dashboard icon;
`v13.100.0` the deeper note; `v13.101.0` the control surface; `v13.102.0` more
sections; `v13.103.0` the phone; `v13.104.0` the **S&N Dashboard host**;
`v13.105.0` the **S&N Analytics host**; `v13.105.1` both hosts on live — canvas,
tab strip, icon, placement. Then `v13.106.0`: native Dashboard and Analytics
windows.

**Repaint (Sep 7, v13.106.3 → v13.106.22 — 41 commits, 20 releases in one day).**
This is the expensive half and it is entirely predictable: the port moved markup
into a host that did not supply what the old host supplied implicitly. Almost
every title in this range begins *Restore* or *Polish*.

- **Report parity first** (`.12`, `.13`): the native report was rebuilt through
  the *shared classic dispatcher* — correct units, session paths, maps,
  distributions, lifecycle, search, defense diagnostics — rather than
  re-implemented. That decision is why parity was recoverable at all.
- **Selectors** (`.11`): styles were bound to the native frame roots. Two-column
  containers and card surfaces had never applied before this.
- **Layout and contrast** (`.5`–`.10`, `.14`, `.15`, `.17`, `.18`): Station Home
  hierarchy for Home, `.snt-2up` / `.snt-report-columns` two-column leaves,
  container-query breakpoints 860px → 640px, OpenStation tokens for chips,
  badges, maturity tiers, Uptime tables, login-defense decision chips. `.18` also
  replaced the white PWA tiles with opaque dark artwork on new manifest URLs.
- **Provenance rail** (`.19`, `.20`): the classic grouping restored, then
  compacted to action-only forms with nonces preserved.
- **Dock** (`.6`): disabling native windows left a stale duplicate tile;
  `removeSystemItem` now fires on toggle and `os-registry-changed`.
- **Preferences** (`.2`–`.4`): per-user native-window toggles, settled on
  Option B — Home and Analytics are switchable, Signal & Noise stays native-only.

**Settle (Sep 8, v13.107.0 → v13.107.5).** Responsive mobile/desktop layout,
minimum app size cut from 760–800px to **360×360** so a desktop resize can reach
phone layouts, visible body-level Refresh (the shell hides titlebar actions on
mobile), and a **browser regression harness at `tests/js/mobile-apps.mjs` — 46
app/size cases, 800+ assertions**. Then five patches against what the harness and
the owner found: `.1` scroll on container *height* not just width, plus URL
wrapping; `.2` the window-body height chain through tab stack and panel wrappers
(native Analytics reports were rendering **blank**); `.3` controls that wrap
instead of hiding behind a sheet, plus stable morph keys so toolkit-generated IDs
stop replacing focused controls after a server response; `.4` fixed-minute MCP
read windows (was extending the same counter per request), last-known widget data
with explicit stale notices; `.5` background polls go quiet — content and colors
retained while pending, timestamps advance only on success.

Three CSS root causes recur and are worth naming: a toolbar `flex-direction:
column` turned 190px/170px select *widths* into *heights*;
`--os-ui-button-min-height` only styled the shell's `fill-cell` variant (inner
buttons needed `::part(button)`); an `os-form` host's wrapping flex collapsed
date inputs at wide sizes.

### The redirect sweep — all five workers, Sep 7

One coordinated pass, one bug class: a credentialed `fetch()` that follows a
redirect leaks its `Authorization` header to the redirect target, and an unread
response body leaks a stream.

| Worker | Now | What it got |
|---|---|---|
| `sn-remote-mcp-worker` | v1.4.1 | redirects refused on the credentialed origin bridge |
| `sn-provenance-worker` | v1.18.2 | redirects rejected on authenticated GitHub ledger reads *and* writes; unused error/missing-file responses cancelled |
| `sn-rights-signals-worker` | v1.24.2 | authenticated AE SQL pinned, 10s cap, 10 MiB streamed response limit |
| `signal-and-noise-analytics-worker` | v1.21.2 | redirects rejected on credentialed WP refresh; failure verdict retained; response released |
| `signal-and-noise-login-guard-worker` | v1.13.0 | failed and declared-oversized denylist responses cancelled, last-known IPv4+IPv6 lists and failure markers preserved |

### The signed authentication handshake — both halves, Sep 7–8

`login-guard-worker` v1.13.0 verifies signed WordPress authentication outcomes;
plugin `v13.106.22` emits them — request-bound, HMAC-signed, dedicated shared
secret, over the verified audit/MFA hooks. Enforcement unchanged and outcomes are
**not** counted as extra edge requests. Preceded by `v13.106.21`, which stopped
recording MFA-protected logins before Two-Factor actually completed (WebAuthn and
backup methods included) and added rejection / rate-limit / other-error subsets.

### Theme — v12.18.10 (Sep 7)

Theme preference persistence, and input **composition** respected (IME keystrokes
were being treated as committed input).

## Open

- **Live install state is unverified in this document.** Every release is tagged
  on `origin/main`; whether the owner has installed v13.107.5 from wp-admin, and
  whether the five workers deployed green, I did not check. Read `sn-status`
  and the deploy rows before assuming.
- **The harness is not full-shell verification.** `tests/js/mobile-apps.mjs` and
  the v13.107.1/.3 fixtures state their own limits: not installed-PWA, not
  full-shell interaction. Five patches in one day landed *after* it went green,
  which is the measurement to trust over the harness's own claim of coverage.
- Nothing in `## [Unreleased]`. `docs/BACKLOG.md` remains the queue.

## Coordination

- Worktree `classifai-mu-plugins-explore-dd1665` sits on
  `claude/signal-noise-review-aa7c4b` at 62dcc20, clean.
- `signal-and-noise-memory` logged 34 session snapshots Sep 4–6 and **nothing
  since 09-06** — the Sep 7–8 work is not in that store.
- Version header and tag stay contended across sessions; verify `origin/main`
  before any bump.

## Operational lessons (also in memory)

- **A port paints the shell's parts.** 20 patch releases in one day, most titled
  *Restore*, is the signature. Budget the repaint as its own phase.
- **Route the port through the shared dispatcher, not a reimplementation.**
  `v13.106.12` recovered full report parity because the classic path was still
  the one computing it.
- **A green harness is not a counter-argument to the owner.** v13.107.0 shipped
  800+ assertions; `.1` through `.5` followed within a day.
- **Blank is a height-chain symptom, not a data symptom** (`v13.107.2`). Before
  suspecting the reader, check whether the container has a height to give.
- **A stale widget must say so.** `.4`/`.5` settled the contract: retain
  last-known data with an explicit stale notice, advance timestamps only on
  success, never show current-green over a failed poll.
