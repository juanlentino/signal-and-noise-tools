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
- Native Analytics focused tabs now open directly on their own reports instead
  of repeating Overview's insights, KPIs, and chart. The filter bar uses stable
  responsive rows, and custom date fields appear only when Custom is selected.
- Native Dashboard and Analytics windows use roomier cards and detail columns,
  readable supporting text, and proportional labels with monospace reserved for
  values, reducing the dense low-contrast wall visible in the first release.

## [13.106.0] - 2026-09-06 — native Dashboard and Analytics windows

### Added
- The S&N Dashboard is a native OpenStation window. What v13.104.0 shipped
  captured the classic page's wp-admin markup into a framework window with
  the tab in state and the page's own strip painted inside it; v13.105.1 then
  pinned wp-admin's light canvas onto it. Next to the shell's own Posts
  window it was the old page in a box, and the owner said so on live:
  "nothing was ported, I've even lost the tabs." The rebuild follows the App
  Framework guide and the shell's own apps: the eight top tabs are the
  framework's tabs (one session each, "Dashboard" the first tab's label
  through the window args), and every tab paints a bound sub-leaf strip and
  its leaf from the component kit -- sections, stats, tables fed through
  `os-prop-*`, histograms, notices, kit forms that ship the classic
  `sn_action` and nonce to the same replay pipeline, action buttons, doors,
  in-window links. All 36 leaves keep their readers, their fields and their
  handlers; a leaf suite compares field names, actions and wp-admin markers
  against the classic renderer. `inc/openstation-kit*.php` is the vocabulary
  every leaf painter speaks. The capture-and-rewrite pass and the light-canvas
  sheet are gone from the window. (#1083)
- The S&N Analytics window is native on the same seam: Overview is the main
  view and the twelve other views are the framework's tabs, each a session
  holding the page's nine parameters; a control's pick reaches `go` as
  `{ key, value }` and becomes the next query by the classic link rules; the
  companion script carries the window and the class across a tab switch as
  the classic tab link does; the frame paints the diagnostic, the insights
  band, the controls, the header region, the drill-down panel, the view and
  the empty note in the classic page's order, each through a kit painter.
  Every chrome piece and every view now has a painter that speaks the kit
  (`os-stat`, `os-table`, `os-histogram`, `os-button`, `os-section`) — the
  same parts the shell's own Posts window uses — so the classic scaffold
  cannot ship. (#1083)

### Fixed
- The weekly security digest can now be turned OFF from the S&N Dashboard
  window. The native window's form pipeline (`os-form` →
  `snt_os_host_expand()`) stringifies an unchecked toggle to `''` but keeps
  the key in `$_POST`, so `sn_handle_security_digest_save()`'s `isset()`
  read every submit as "on". The handler now reads presence the way its
  siblings do (`! empty()`), which is identical on the classic page (an
  unchecked checkbox is simply absent). `tests/security-digest.php` pins
  the present-but-empty case.

