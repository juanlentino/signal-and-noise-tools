# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Security
Fixes from the 2026-10-16 plugin audit, each with a test that fails against the old code.
- **Webmention inbox: flood limits.** `POST /signal-noise/v1/webmention` now allows 10 claims per IP per hour and 20 per source host per day, refusing past either with a named 429 (`sn_cit_rate_limited_ip`, `sn_cit_rate_limited_host`). The IP is `REMOTE_ADDR`, as login defense reads it, so the per-IP limit is per client only where the host restores the visitor address behind Cloudflare. The store holds at most 2,000 `unverified` rows; past that a new claim is a 429 (`sn_cit_inbox_full`) and the refusal is logged. The verification fetch stays behind the SSRF guard.
- **/stats no longer names drafts.** A Most read row whose path resolves to a post that is not publicly viewable (a draft, private or protected post) is dropped: neither its title nor its path renders.
- **Agent writes cannot add active content.** sn_apply refuses `<script`, `<iframe`, `<object`, `<embed`, inline `on*=` handlers and `javascript:` URLs in what a change adds (block_insert and block_replace blocks, sentence_replace replacements, payload.edits and batch members, create_draft content) with a named 422, `snt_sn_apply_active_content`, listing each construct. Existing post content is not read.
- **Worker calls share one SSRF gate.** The keyring's sensor probe and both key-rotation calls (`/next-key`, `/publish-rotation`) go through `sn_prov_url_allowed()` (https, public host) before a credential leaves; a refused URL makes no request and says so. The Cloudflare account id is `rawurlencode`d into the Analytics Engine path.
- **No token-state oracle.** `GET /owner/revoked` and `POST /analytics/refresh` answer an unset server token exactly as a wrong one: core's `rest_no_route` 404, byte for byte, as the MCP bridge route already does. Workers that read 503 or 403 from these routes now see 404; the keyring row and the settings pill still say when the token is unset.
- **Provenance confirm replay.** A confirmed commit never moves back to pending or unanchored. A confirm body may carry a signed `ts` (unix seconds, milliseconds or ISO 8601); when present, one more than 10 minutes off is refused (`sn_prov_stale`). Workers that send no `ts` still work.
- **Contact-form spam rule.** bitcoin, btc and crypto are a weak signal now (`crypto_word`), so a research inquiry about Bitcoin provenance passes; they mark spam only beside another signal. usdt, cloud mining, "new message", "you have won" and `#H123` stay strong.
- **No submitter PII to MCP.** `forms-spam-scan` rows carry id, date, reason and a masked hint (`J. S.` or `j***@domain`), never the entry title. `forms-spam-apply` is unchanged.
- **/live does not spawn cron for anonymous readers.** Only the authenticated `/live/admin` read runs a due refresh now; the public route still schedules one, and the host's system cron runs it, so with no admin reading the public figure can lag by up to that interval (5 minutes).
- **Music JSON-LD** is encoded with `JSON_HEX_TAG`, so a stored string cannot close its script element.
- **Dependabot** now watches the bundled PDF library (`/lib/pdf`).

## [2026.10.25] - 2026-10-16 — Which machines send a reader back joins the Analytics principles; roadmap board in sync

### Changed
- **Roadmap board: the static floor resynced to the third live write of 2026-10-16.** Analytics: "A quiet week explained…" moves planned to done, reworded as shipped in 2026.10.24 (the pages and sources that fell furthest against their own usual weeks, or a drop spread thin). The five-row done cap dropped the oldest done row, "Which machines send a reader back", which graduates onto the Analytics maturity page as its seventeenth principle with its "not a ratio" decline intact; the test guarding that decline moved with it. The md5 pin moves to `95715684be34e8e4b8d77acf53cd4f10`, verified against the live page.

