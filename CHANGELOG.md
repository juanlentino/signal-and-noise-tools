# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.4.3] - 2026-09-20 — the leaf bar is native

### Fixed
- **The leaf bar is the native list toolbar's status control, not an os-tabs strip.** The owner put the S&N Home window beside the native Pages window: "The menus in the plugin aren't native to OpenStation." Read live 2026-09-20: the window chrome's active tab is bold white with no coloured mark, and the second row under it, our `<os-tabs class="os-app-list__tabs">`, painted the station's rose accent as an underline (os-tabs' own `::after` on `--os-ui-accent`, `rgb(225,29,72)`; nothing of ours), so the two navigation rows in one window never shared a colour. Every native window (Pages, Posts, Users, Plugins) paints that level as `statusControl()` in the runtime's `list-ui.ts` does: `<header class="os-app-list__toolbar"><div class="os-app-list__toolbar-left"><os-segmented class="os-app-list__status">` on a desk and `<os-select class="os-app-list__status">` on a phone, where nine pills in 360px wrap into ragged rows. `snt_kit_tabs()` now returns that shape, both twins bound to `sub` (`os-segmented` and `os-select` share the `os-pick` contract, so the binding is unchanged; a pick on the injected bar in the live station wrote `sub=cloudflare` and the server repainted the Cloudflare leaf). The runtime decides desk or phone by `isMobileStamped()` at paint time; a server paint cannot, so both ship and `sn-dashboard.css` shows the select only under `html[data-os-mode="mobile"]` and the segmented only off it. In the station the segmented's thumb is the neutral grey with white text, the same white the chrome strip uses: "same color in the two menu navs", the owner said. The phone select carries `os-key`: `os-select` mints an auto id on connect and the runtime morph keys a live node by `os-key` or id, so an un-keyed paint was replaced on every repaint (the Analytics selects got the same key in #1116). Off the stamp, a window narrower than the nine pills (819px: a tablet at 768, a desk window pulled in) scrolls the row sideways, the toolbar-left scrolling and the segments unshrinkable, never the segmented host, whose thumb is measured by `getBoundingClientRect` and would drift by `scrollLeft`. No JS, no state change, no leaf change; the Identity & SEO leaf's third-level `<os-tabs>` (a client-side section swap, not bound) is the one os-tabs left and a separate follow-up. The keyboard path is the one loss: `os-tabs` was a single Tab stop with arrow roving; `os-segmented` makes every pill a Tab stop and has no arrow handling, an upstream gap every native window shares, filed as WordPress/openstation#858. Pinned: the toolbar with both twins bound to `sub` above the body, no `<os-tabs>` or `os-app-list__tabs` at this level, the two mode rules each matched from its own rule start (a suffix match passed against an inverted sheet), and the scrolling row; five pins red against the unfixed code. #1590.

