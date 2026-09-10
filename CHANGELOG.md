# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.18] - 2026-09-10 — a rewritten link answers the keyboard

### Fixed
- **Every link the window rewriter un-hrefed was mouse-only.** `snt_os_host_rewrite_link()` drops an anchor's `href` so a click cannot navigate the whole desktop out from under the window — necessary and correct — but an `<a>` with no `href` is not focusable and exposes no role, and nothing put either back. Measured on the running product: seven S&N Analytics cross-view links (*Content →*, *Sessions →*, *Geography →*, …) were reachable by pointer and by nothing else, `cursor: default` and all. The removal and its compensation now happen together in one `snt_os_host_unhref()`, which sets `tabindex="0"` and `role="link"` — they were separate before, which is exactly how the compensation went missing beside a removal that had to happen. A first diagnosis blamed `canonical.php` for stripping `os-action`; a controlled comparison against anchors that *kept* `os-action` found them equally unfocusable and withdrew it.
- Enter and Space now activate those links. `assets/os-kit.js` listened for `click` alone, and an `<a>` without an `href` does not fire a click on Enter the way a real link does — so focusable-but-dead would have been worse than mouse-only. The new listener keys off the rewriter's own `role="link"`/`tabindex` marker rather than `.snt-go`, so it covers both the cross-tab links this file dispatches and the `os-action` links the framework runtime dispatches.
- **`--os-ui-fg-faint` was styling 11px text in S&N Home.** Measured against the app surface it is a 3.00:1 token — the 3:1 tier, for large text, borders and icons — and `.snt-home__pulse-group-label` (the AUDIENCE / PUBLISHING / TRUST & OPERATIONS labels) and `.snt-home__timestamp` used it for body-sized text needing 4.5:1. Both move to `--os-ui-fg-muted`, which measures 8.18:1. The token was used exactly as named; the name does not carry the size limit.
- `.sn-an-settings-help` inherited WordPress core's `#646970` at **3.19:1**, being emitted by PHP and styled by nobody. S&N Analytics already keeps a repair list for shared helpers that arrive wearing classic light colours; this class was missing from it and is now in it.

### Added
- `tests/openstation-app-contrast-tier.php` pins the *rule* rather than the two rules it was born from: `--os-ui-fg-faint` may not be the `color` of small text in an app stylesheet. It parses each rule's own font declaration so the WCAG large-text exemption still applies, and carries its own negative controls — it must detect the exact shape that shipped, and must not flag a 28px heading or a border that uses the same token correctly.
- `tests/openstation-host.php` gains two pins: an anchor that **lost** its href gains `tabindex`/`role`, and an anchor that **kept** one gains neither — without the second, a fix that tabindexed every anchor would put the `mailto:` and the `#fragment` into the tab order and pass.

