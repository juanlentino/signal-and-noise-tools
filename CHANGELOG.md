# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **Owner identity for SN Traffic Lanes (Phase 1, plugin half).** Your own visits can now be told apart from readers at the edge, by a signed marker instead of an unsigned flag. Nothing reads the new data until the analytics Worker ships, so no figure changes with this release.
  - **One owner key on the keyring** (Connections › Credentials, worker group): owner key, with a previous twin used only during a rotation. Setup is three steps: generate the key, paste the same value into both Workers (the two `wrangler secret put SN_OWNER_KEY` commands the row prints), enroll your devices. It is generated here (32 random bytes, 64 hex), never derived from the site secret and never pasted. **Generate** moves the current key to its previous row, where it stays valid until cleared. The one key signs both the device marker and the optional agent token; the signed messages are domain-separated, so a marker can never pass as a token. Values show as the last four characters only.
  - **Owner devices** (Measurement › Analytics, beside "Exclude my own visits", classic and dashboard): enroll this browser under a label, revoke one device, or revoke all. The card shows each device's label, when it was enrolled, when it was last seen and whether this browser is enrolled; it never shows a device id, a marker or a key. Enrolling sets `sn_owner_dev` (Secure, HttpOnly, SameSite=Lax, host-only, 400 days), only on a wp-admin response, never on a page a reader can be served. A wp-admin visit re-signs an older marker once a day with the current key, and reads nothing when the browser carries no marker. Enroll refuses until the owner key is generated. Generate keeps the old key valid as the previous key until you clear it; the card names that step after Revoke all.
  - **`GET /wp-json/signal-noise/v1/owner/revoked`**, behind the analytics server token (Bearer, compared in constant time, closed when the token is unset), answers `{"revoked":[ids],"at":time}` for the analytics Worker, at most the newest 1,000 ids.
  - **`wp sn owner-token --agent=<agent> [--ttl=<seconds>]`** (optional; nothing in this phase needs a token) prints an `SN-Owner-Agent` header for an owner tool (Claude Code, Playwright, a script, the local MCP server). The TTL is 12 hours at most. The token is never stored or logged.
  - **Owner views now reach the edge.** The server no longer suppresses the beacon for excluded roles, so a logged-in owner view is sent and the analytics Worker keeps it out of every reader figure (today's Worker drops it, as before). The role list still gives those users the legacy owner flag. The "Exclude my own visits" copy says what stays true before and after the Workers ship.
  - **Collector status** ignores the analytics Worker's optional owner flags (`ae_owner_bound`, `owner_key_set`, and the earlier two-key names) when judging config health, so an unset owner key never reads as a failure ("All 9 config bindings report true." stays).
  - **Handling the token:** put `wp sn owner-token` output straight into an environment variable, never echo it; in Playwright attach it with `page.route` limited to `https://juanlentino.com/**`, never `extraHTTPHeaders`; never use curl `-L` with it. The keyring hint and the command's help say so.
  - One signer for both formats (`inc/owner-identity-sign.php`), pinned byte for byte to the contract's test vectors, with tampered, expired, too-far-ahead, too-old, non-canonical and previous-key cases. Pinned in `tests/owner-identity-sign.php`, `tests/owner-devices.php` and `tests/keyring.php`.
- **A stale-CV alert.** /cv/ is a translation and does not follow the English résumé on its own. When the English résumé changes, the Résumé screen (classic and dashboard) and a new Health check (`resume_es_stale`, Other checks family) say "The Spanish CV (/cv/) is out of date" and list the English lines added, changed or removed since the translation. The baseline is captured from the live English résumé on the first run. After that it moves only when the Spanish file's `translated_from` names the live English (the "English version" code the alert shows), so a typo fix or a partial Spanish update cannot clear an alert whose lines are still untranslated. No AI, no network. The scan now runs one more check. Pinned in `tests/resume-es-stale.php`.

## [2026.10.17] - 2026-10-15 — The Spanish résumé (/cv/ and its PDF), and a compact Release notes box

### Added
- **/cv/ and `JuanLentino_CV.pdf`, the Spanish résumé** (owner, 2026-10-15: a PDF too, and no second résumé system in the settings). One Spanish file, `inc/seed-content/resume-data-es.json`, renders both through the same page builder and PDF template as /resume, with their fixed headings in Spanish (`inc/resume-lang.php`); English output is byte for byte unchanged. Contact details come from the live English résumé, so the phone never reaches the repo. /cv/ re-renders on the next admin load after the file or the contact details change, and its PDF re-renders every time the English PDF is generated. /cv/ is a fifth Spanish page paired to /resume. The Spanish pages seed now records each slug it handled, so a slug added later is seeded on the next admin load. A lock (`sn_spanish_pages_seed_lock`, taken over after ten minutes) replaces the run-once flag. Pinned in `tests/resume-es.php` and `tests/bilingual.php`.

### Changed
- **The Release notes box is compact** (Connections › Discography). Each saved note is now one line: the release name, then the English and Spanish fields side by side. "Add a note" is folded into one line until opened. It was a fieldset of three stacked labeled fields per note, and the owner flagged how much of the page it took (2026-10-15). Saving is unchanged.

