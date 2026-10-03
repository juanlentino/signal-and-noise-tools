# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.0.0] - 2026-10-03 — the lists and the caches keep themselves current

### Added
- **Hacker News is asked directly.** A referrer undercounts Hacker News (its apps send none) and a submission by someone else is invisible until traffic arrives. Inside the hourly alert run, the Algolia search API finds any story whose URL is on this site and the official API gives its live points, comments and front-page position for its first three days; both are public, need no key, and receive only the site's host name. A story found young mails once (`HACKER NEWS: "..." was posted to Hacker News: 14 points, 3 comments`), reaching the front page mails once more, and a spike on a path Hacker News holds says so in its line. Stories that predate the first run are stored as history, not mailed. `inc/hn-mentions.php`; pinned in `tests/hn-mentions.php`.

### Changed
- **The model lists update themselves.** The pickers offered Sonnet 5 and Gemini 2.5 while both vendors had shipped newer generations, and the default id was a literal in eight files. Once a day a cron (`snt_ai_models_refresh`) now asks the WordPress AI Client's provider registry what each connected provider serves; the Anthropic and Google provider plugins answer from the vendors' own model-list APIs with the site's connector keys, and the pickers read the stored answer: Anthropic's text models for prose, Google's image-capable text models for vision, in the provider's order, without dated snapshots or speech, image-generation, live and preview variants. The built-in lists are the seed (before the first read, with no provider connected, or when the last read is over a week old) and were brought current: Sonnet 5.5 (the new default), Opus 5.5, Fable 5.1, Haiku 4.5; Gemini 3.1 Flash-Lite (the new vision default), 3.5 Flash-Lite, 3.8 Flash. The stored choice and the default always stay in the list, and a stored choice is never changed. Two things stay in code on purpose: the default is pinned (it does not advance by itself), and no settings screen reads a provider while it renders. Both AI settings screens say where their lists and prices came from. `inc/ai-model-catalog.php`, `inc/ai-model-discovery.php`; pinned in `tests/ai-model-catalog.php`.
- **Prices update themselves too.** Neither vendor's API returns a price, so the same daily cron reads LiteLLM's public price file (community-maintained, keyed by the vendors' own model ids; every row compared against both vendors' pricing pages on 2026-10-03 agreed) and keeps the Anthropic and Gemini chat models. A read price wins over the table in code while it is under two weeks old; the table is the seed and the fallback, and was corrected: Sonnet 5 lists at $2/$10 per million tokens, not the $3/$15 held since v6.52.0, so the spend estimate was over-counting it by half. Because the monthly budget cap pauses AI features on this estimate, nothing in a third party's file is taken on sight: an id must be well-formed, a price a positive number under $500 per million, a read with fewer than five usable rows is discarded, and a price that moved more than four times against the trusted one is held at the old value and named on the settings screen. A model no source prices is counted as unpriced, never as $0. `inc/ai-model-prices.php`.
- **A save purges the tag, and only the tag.** 20.9.0 sent the theme's cache tag beside the old URL list until a tag purge had been seen to work. It was, on 2026-10-03: a no-change save turned unrelated pages (`/about/`, `/music/`, other notes, `/llms.txt`) from HIT to MISS with no zone purge in the log. With a tagging theme (15.2.0+), an edit, a first publish and a post leaving publish now send one tag purge plus core's sitemaps (which carry no tag); the per-post URL list, the first-publish zone purge and the post-save probe are no longer used on that path, so static assets stay warm at the edge through a publish. They remain as the path for an older theme. The probe's history and its readers are left in place.
- **The README links OpenStation contributions instead of counting them.** The OpenStation paragraph said "six pull requests" and listed each by number; the real count is 15 merged and still growing. It now links the live merged-PR and issue searches, which cannot go stale. Nothing in `tests/` or `docs/` pinned the old count or the PR numbers.

