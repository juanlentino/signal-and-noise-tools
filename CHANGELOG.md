# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.5] - 2026-09-10 — the webmention receiver speaks one error vocabulary

### Changed
- The webmention receiver now speaks **one** error vocabulary. It returned two shapes, both 400: core's `{code, message, data:{status}}` for a param-level rejection, and a bare `{"error": "…"}` from the handler with no machine-readable code, so a sender could not tell `source must be off-site` from `target is not a publicly viewable resource on this site` without string-matching English. All seven handler refusals are now `WP_Error` with a named code — `sn_cit_missing_params`, `sn_cit_invalid_source`, `sn_cit_source_equals_target`, `sn_cit_source_not_offsite`, `sn_cit_source_unreachable`, `sn_cit_target_not_found`, `sn_cit_not_recorded` — each condition distinct. The prefix mirrors the module's function prefix, which is the convention the sibling public endpoint already follows (`sn_prov_bad_sig`). **The human-readable strings are unchanged, verbatim**; they now travel as `message`. Status stays 400 on every path, the accept path is untouched at 202, and the param-level code stays core's `rest_missing_callback_param`. Nothing consumed the old shape: no plugin code, no admin UI, no worker, no automation, and no logging parses that body.

### Added
- The conformance suite asserts the shipped shape per condition — WP_Error, status 400, named code, and the original message verbatim — plus that every code is distinct and module-prefixed, since two conditions sharing one code is the same failure as no code. Mutations redden it: sharing a code (1), rewording a string (1), drifting the status (19), dropping the prefix (1), reverting to the string shape (19). The case loop short-circuits on the shape check so a reversion **reports** rather than fataling mid-run and hiding the assertions after it.

