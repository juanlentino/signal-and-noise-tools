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
- **The Zenodo draft slot is per environment.** Flipping from production to sandbox read the production draft ids on sandbox, where "The persistent identifier does not exist" is a 404, and the 16.1.3 rule forgot them: five resumable production drafts became orphans. Production keeps the original meta key (every id stored before this fix is a production id); sandbox has its own, and neither environment reads or forgets the other's. Pinned.
- **The shell toast decodes entities.** The flash strings carry `&rsquo;`, `&mdash;` and `&hellip;` for the HTML notice, and the toast printed them as text ("Zenodo&rsquo;s answer"). Pinned.

## [16.2.0] - 2026-09-18 — the other index, read

### Added
- **Bing Webmaster Tools, the Bing twin of the Search Console reading.** One API key in the keyring (`bing_webmaster_key`, Connections › Credentials; the probe calls GetUserSites and passes only when this site is listed and verified, so a key for another site reads as refused, not as an empty search). A daily sync (`sn_bing_sync_daily`, scheduled while a key is stored, in the cron registries) reads the documented GetRankAndTrafficStats and GetQueryStats for the site, shapes a 28-day window ending on the newest day Bing reports, keeps the last good record with the failure beside it when a read fails, and stores it in one option. S&N Analytics › Search paints it after Google's tables as a Bing panel (impressions, clicks, CTR, the top 25 queries) in the same table shape, and `signal-noise/bing-search-performance` (read door, `source: "bing"`) hands it to an agent. Copilot and the engines that read Bing's index sit behind these numbers, which is why they sit beside Google's. The key travels as the documented `apikey` query parameter over HTTPS and is redacted from every error string. Pinned (30).

