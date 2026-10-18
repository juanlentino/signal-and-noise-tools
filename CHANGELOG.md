# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.45] - 2026-10-18 — invented crawler names from a rotator network are impostors

### Changed
- **Invented crawler names from a rotator network are impostors.** The operator on "Google LLC" that presents about twenty vendors' agents, none verified, also sends names no taxonomy lists, built from one template (GrokBot/1.0, DeepSeekBot/1.0, Qwenbot/1.0, KimiBot/1.0, ChatGLM-Spider/1.0 and more): about 4,340 reads in 14 days, credited to other-bot under agent ''. The credit ladder's last rule now also catches an unnamed crawler (agent '' filed under other-bot, which the frozen family classifier assigns only to a user agent carrying a crawler token, every dev-client token it also matches having a taxonomy entry) on a network the rotator rule already flags: impostor, reason `rotator_network`, counted in the total and credited to no one. Verified, unverifiable and first-party reads are never reclassified; an unnamed crawler on its own network (serpstatbot, Barkrowler) stays credited; unnamed reads are caught by a rotator and never count toward making one. No sensor change: the aggregate row's family already carries the signal. Every surface picks them up through the "network posing as many crawlers" row, and the "failed a check" subtotal still nests inside "Named themselves, not verified" (that parent counts unnamed unverified reads; pinned in `tests/machine-readers-impostor-unnamed.php`). The "failed a check" description now reads "Named a crawler", since these names are invented. Impostor lists gain an entry with agent '' (a value, not a new shape); the remote contract (20) is unchanged.

