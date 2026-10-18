# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.44] - 2026-10-18 — machine-reader evidence reads as nested rows on every surface

### Changed
- **The machine-reader evidence reads as rows on every surface.** The classification grew (vendor-verified reads, control tokens, four impostor reasons) and the surfaces had bolted sentences on. Now each part is a short label with one right-aligned number, nested under the part of the bar it sits in, and the long sentence moves to the row's title (or a note on /stats). One vocabulary, `snt_mr_reader_labels()` in `inc/machine-readers-impostor-copy.php`, gives every surface the same words.
  - **SN Provenance (desktop):** Verified splits into "by Cloudflare" and "by the vendor's published ranges"; "Named themselves, not verified" carries "failed a check" with one row per reason (Cloudflare's check, outside the vendor's published ranges, outside the vendor's network, network posing as many crawlers) and "control token (Google-Extended)". AI-training reads show the verified figure ("83 verified"); the claimed figure and its parts are the row's title. The card's words arrive in its payload (`labels`, `impostor_reasons`, `ai_training_title`); the script names none of them.
  - **Reader evidence (both twins, one painter):** a "Failed a check" tile beside Control tokens, and a "Failed a check" box (Check, What it means, Reads) paired with the control-token box, in place of the run-on sentence in the lead. The Verified tile names its sources once vendor ranges are measured. The Machine readers hero's AI-training tile and the Dashboard widget lead with the verified figure, the claimed figure beside it.
  - **/stats:** Who the machines are nests the same rows (shares of all machine reads, "<1%" for a real part under one percent) with one plain-words note under them; the AI-training key splits verified by source once measured. The two sentences under the key are gone.
  - **The zero rule, pinned in tests:** the four parts of the bar as before; "by the vendor's published ranges" shows once any read in the window carried the field (0 is then a real figure, never shown before); a reason, the failed-a-check row and the control-token row show only with reads.
  - **Data, additive and outside the remote contract:** the desktop identity split gains `vendor_verified` (null when not measured) and `control_token` (the control-token reads among the unverified), which /stats' snapshot carries too. The summary ability and the remote contract (20) are unchanged.

