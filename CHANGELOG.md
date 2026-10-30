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
- **The queue twin suites no longer fail near a DST change.** `queue-cadence-twins`, `queue-runway-twins` and `queue-slot-twins` placed fixture notes at `time() + days × 86400`. Across a DST change a day is 23 or 25 hours, so two notes "one day apart" could land on one calendar day (Nov 1 2026, New York) or two, and `queue-cadence-twins` failed at some hours of the week before each change: 24 of 3,456 ten-minute instants across the 2026 and 2027 change weeks. The three suites now share one fixture, `tests/lib/queue-post.php`, which places each note at local noon a whole number of calendar days out; across the same instants it fails at none. Test-only: no plugin code changes.

## [2026.10.78] - 2026-10-29 — signed-in archives keep the loose CSP, and surfaces writes report what they replace

### Fixed
- **Signed-in archive pages keep the loose CSP again.** The `X-SN-CSP: lax` marker was decided at `template_redirect` priority 1, but the theme draws `/notes/`, its paged and tag views, and `/index/` at `template_redirect` priority 0 and exits. Those pages reached a signed-in visitor without the marker, so the strict policy blocked the per-request inline scripts there (the admin bar's `sntAbilityRunData`, and core's import map whenever its hash was stale). The marker now runs on `wp`, which fires after the query is set and before any `template_redirect` handler. Strangers are unaffected: their reason is still empty on every public page.
- **A `surfaces` write reports what it replaced.** Both write modes answered `diff.before: null`, so an overwritten description survived only in whatever transcript previewed it. The write now reads the stored values first and returns the same `{before, after}` its dry run previews, from one helper (`snt_sn_apply_surfaces_diff()`). `after` names only the fields the write stores, so a key it ignores is no longer reported as written. Pinned in `tests/sn-apply-surfaces-diff.php`.

### Added
- **A `surfaces` or `create_draft` write refuses a leaked field label.** A drafting note ("FOCUS KEYWORD: …") once reached a live meta description. Gate 2 now raises a `field_label` error, which refuses the write, when a field the type writes carries a field label followed by a colon at the start of the value, a line or a sentence: focus keyword or keyphrase, meta or SEO description or title, OG, OG card or Open Graph title, or excerpt, however it is spelled (`meta_description:`, `SEO Title :`, `_sn_meta_description:`, `post_excerpt:`). The same words mid-sentence ("An excerpt: the first bars") pass, since a finding cannot be acknowledged. `surfaces` checks all five of its fields; `seo_title` and `focus_keyword` had no gate 2 check at all, so a write naming only them no longer reports `no_applicable_checks`. `create_draft` checks `excerpt`, `meta_description` and `og_card_title`. The detector is `snt_surface_leaked_label()` in `inc/surface-fields.php`; the findings come from one helper, `snt_sn_apply_field_label_findings()`. A read of the 59 published posts on 2026-10-29 found no label in any of these fields. Pinned in `tests/sn-apply-surfaces-label.php`.

### Removed
- **The `.git` preservation module.** `inc/wp-update-git-preservation.php` moved the plugin's `.git` to a fixed path, `wp-content/upgrade/sn-signal-and-noise-tools-git-backup`, for the length of a WP UI install, then moved it back. It existed for the old deploy that ran `git checkout` inside the live plugin directory. Production has had no `.git` since the owner removed it by hand on 2026-07-15 (it had survived every footprint-janitor sweep on file ownership), and nothing brings one back: `deploy.yml` rsyncs a `git archive` payload with `--delete`, and a WP UI install unpacks a tag archive. The module returned early when `.git` was absent, so on production it did nothing. Kept, it would park a `.git` under a predictable name in the web-served `wp-content/upgrade/`, the pattern WordPress 7.1.4 removed from core (`WP_Upgrader::get_working_dir_name()`, new in 7.1.4, names core's working directory with 16 random characters). No behavior changes on the live site. On a site that is still a git checkout, a WP UI update now replaces `.git` like any other file; deploy those with the archive path.

