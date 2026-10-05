# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.5.0] - 2026-10-05 — the author's countersigning key is published

### Added
- **The author's countersigning key is published beside the publisher's.** Set in the `sn_prov_author_key` option (`id`, `public_key_base64`, `introduced_at`), it appears in `/.well-known/provenance-keys.json` as a `role: "author"` entry after the publisher key, and in `did.json` as a verification method that is never an assertion method, so no credential reader takes it for a key that signs notes. The site's verifier refuses it by role, both when it picks the active key and when a record names a key: a credential or retraction naming the author key fails. The ledger accepts the author key only when this entry and a `_provenance-author` DNS record agree with its key history. A malformed value (including an impossible date), one whose key bytes equal a publisher key in any base64 spelling, or one reusing a publisher id publishes nothing, and an unconfigured site serves exactly what it did before. Pinned in `tests/provenance-author-key.php` and `tests/js/prov-verify-core.test.mjs`.

### Changed
- **Gap analysis: gap 3 is designed within one author, and nothing waits on another person.** `docs/proposals/proving-the-provenance-thesis.md` records the owner's 2026-10-05 direction that this is and stays a single-author site and the design works within that: roles as a one-party list, additive signatures from the author's own second key (which also opens custody), ownership (the owner's declaration and owner-signed transfers) kept apart from signed rights terms, and disputes as self-corrections on the retraction shape. D-1 now ends when the publish-time signature moves to an author-held key; countersigning narrows it. Gap 1's parked list now says what each item actually needs: a recognition attestation is a third party's signed claim about the author (none exists, so the attestation term stays zero), and custody needs no one else. Docs only.

