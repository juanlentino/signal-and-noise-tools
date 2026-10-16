# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.27] - 2026-10-16 — provenance requests carry the signed v2 envelope


- **Security: requests to the provenance Worker carry the v2 signed envelope.** Every POST the plugin sends to the Worker (the commit webhook, `/sweep`, `/next-key`, `/publish-rotation`, the genesis snapshot, rights evidence and its retraction) now signs `v2|<ts>|<nonce>|<sha256(body)>` with the shared secret in `X-SN-Sig`, beside `X-SN-Sig-Ts` and a fresh random `X-SN-Sig-Nonce`, so a captured request can no longer be replayed (Worker 1.26.0 refuses a timestamp more than 300 seconds off and a nonce it has already seen). One helper, `sn_prov_signed_headers()` in `inc/provenance-signed-headers.php`, builds the headers for every one of them. The old body-only `X-SN-Signature` is still sent alongside for the transition: Worker 1.26.0 checks only v2 when any v2 header is present, so v1 matters only to an older deploy. Once this release is live and the Worker logs show no v1 use, `SN_PROV_ACCEPT_V1` can be set to `0`; a later change drops the v1 header.


