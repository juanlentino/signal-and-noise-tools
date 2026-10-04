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
- **SN Health and SN Anchors read at a glance again.** 21.8.0's accessibility pass made hover-only text visible, and both cards filled up. Health's headline now counts apart what it used to fold together ("16 passed · 1 to look at · 1 could not run", or "All 18 checks passed"), and a check that could not run shows one line of reason ("The AI provider refused the first 2 of 2 calls. Full reason on the Health tab.") instead of the provider's raw error. Anchors says "✓ All anchored: 50 notes, 6 pages" in one line instead of three, and drops the Archive sentence that repeated its two rows, keeping it when the run is halted (it carries the reason) or Archive is not configured (it names the two wp-config keys).
- **An error notice interrupts.** `os-notice` defaults to `role="status"` (polite, confirmed in OpenStation's source); the kit now marks the danger tone `role="alert"`. The per-row naming of `os-repeater` buttons is OpenStation's to fix (WordPress/openstation#946).

## [21.8.0] - 2026-10-04 — a Proof section on /workflow, and an accessibility pass

### Added
- **/workflow gets a Proof section, edited on Content › Workflow.** A heading (default placeholder "Check it yourself") and a list of links to evidence a reader can check, each row a title, a link, a one-line note and "Show on page", in Resume's repeater (add, remove, reorder). It renders after the rules, each link in the row's lead line with its note beside it. A row goes public only when it is ticked, has a title, and its link is a path on this site (`/maturity/`) or an https URL; http, `javascript:`, protocol-relative and untitled rows stay off the page, and brackets in a link are encoded so no shortcode can run from it. The owner writes every word; the plugin ships no default rows. The title is the link's text, so its field hint asks for a name of where the link goes, never "here" or "link".

### Fixed
- **Accessibility pass over everything shipped since 21.4.0 (WCAG 2.2 AA audit, owner request).**
  - Dashboard widgets: "Sweep now" keeps keyboard focus through its refresh. Reasons that lived only in hover titles ("recording", why a check could not run, the Archive line, what advisories are) are visible text. Loading figures are polite live regions and load errors are alerts. The fallback toast is a status and a running action is aria-busy. The audience "down" red is the lighter text red, about 3.6:1 before. 10px text is 11px. Group widgets carry heading and list roles. Labels wrap instead of clipping. Arrows and trend glyphs are hidden from screen readers. The two "Open Analytics" links name their widget, the name starting with the visible words (2.5.3).
  - Citations: "No response" in words instead of a bare dash. The forget hint is tied to its select, and the confirm points at the claim selected in the list.
  - The classic wp-admin repeater (Resume and Workflow): 24px control targets (2.5.8), buttons named per row ("Remove row 3"), focus kept on a real control after add, move and remove, and each change announced. Field hints are tied to their fields with aria-describedby.
  - Two kit-side questions stay open for OpenStation: whether os-notice announces itself, and whether os-repeater names its buttons per row.
- **The Colophon's AI credit links /workflow, and loses its hyphen.** The line now reads "engineered with Claude (Anthropic) as a pair programmer" (no hyphen, the owner's call); "pair programmer" links the /workflow page in the same tab, and only while that page is published, so a withdrawn page leaves plain text rather than a dead link. For screen-reader users browsing by links, the link carries hidden context after its visible words ("pair programmer: how I work with AI"); it is a hidden suffix, not an aria-label, so the accessible name still starts with what is on screen (WCAG 2.5.3) and voice control still finds it. Owner approved the exact line and the hidden text.

