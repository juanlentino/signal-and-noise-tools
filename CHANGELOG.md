# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Security: requests to the provenance Worker carry the v2 signed envelope.** Every POST the plugin sends to the Worker (the commit webhook, `/sweep`, `/next-key`, `/publish-rotation`, the genesis snapshot, rights evidence and its retraction) now signs `v2|<ts>|<nonce>|<sha256(body)>` with the shared secret in `X-SN-Sig`, beside `X-SN-Sig-Ts` and a fresh random `X-SN-Sig-Nonce`, so a captured request can no longer be replayed (Worker 1.26.0 refuses a timestamp more than 300 seconds off and a nonce it has already seen). One helper, `sn_prov_signed_headers()` in `inc/provenance-signed-headers.php`, builds the headers for every one of them. The old body-only `X-SN-Signature` is still sent alongside for the transition: Worker 1.26.0 checks only v2 when any v2 header is present, so v1 matters only to an older deploy. Once this release is live and the Worker logs show no v1 use, `SN_PROV_ACCEPT_V1` can be set to `0`; a later change drops the v1 header.

## [2026.10.26] - 2026-10-16 — security hardening from the audit: webmention limits, no draft titles on /stats, agent writes refuse active content

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

