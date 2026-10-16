# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Security: the sn_apply active-content refusal now stands on core instead of regexes.** `snt_sn_apply_active_constructs()` (`inc/sn-apply/active-content.php`) walks an agent write with WordPress's own `WP_HTML_Tag_Processor`, which decodes attributes the way a browser does, so a URL like `&#106avascript:` (no semicolon) and a handler after an unquoted value holding a quote (`<img src=x"y onerror=...>`) are caught where the old patterns missed them. Elements come from core's post allowlist: `<script`, `<iframe`, `<object` and `<embed` are always refused, and `<form`, `<base`, `<link`, `<meta`, `<style`, `<noscript`, `<xmp`, `<noembed`, `<noframes`, `<plaintext`, `<frame`, `<frameset` and `<applet` are refused while `wp_kses_allowed_html( 'post' )` leaves them out. Every `on*` attribute is refused by name, and every URL attribute core lists in `wp_kses_uri_attributes()` (plus `xlink:href`) goes through `wp_kses_bad_protocol()` with `wp_allowed_protocols()`, so a refused scheme is named (`javascript:`, `vbscript:`, `data:`). The contents of `<title>` and `<textarea>` are read as markup too (inside SVG or MathML a browser parses them that way), and a fragment cut off mid-tag is read once more with its openers closed. Block comments and prose keep one probe for a quoted `javascript:`. The named 422 `snt_sn_apply_active_content` and its `data.constructs` are unchanged. Newly refused, by core's policy: `data:` images, and a relative link whose colon comes before any `/?` (`/p?x=a:b`), which core's own kses would mangle on save. Newly allowed: `javascript:` as plain text in a non-URL attribute such as `title`. Tests load core's HTML API and kses through a new shared loader, `tests/lib/wp-core-kses.php`.

## [2026.10.27] - 2026-10-16 — provenance requests carry the signed v2 envelope


- **Security: requests to the provenance Worker carry the v2 signed envelope.** Every POST the plugin sends to the Worker (the commit webhook, `/sweep`, `/next-key`, `/publish-rotation`, the genesis snapshot, rights evidence and its retraction) now signs `v2|<ts>|<nonce>|<sha256(body)>` with the shared secret in `X-SN-Sig`, beside `X-SN-Sig-Ts` and a fresh random `X-SN-Sig-Nonce`, so a captured request can no longer be replayed (Worker 1.26.0 refuses a timestamp more than 300 seconds off and a nonce it has already seen). One helper, `sn_prov_signed_headers()` in `inc/provenance-signed-headers.php`, builds the headers for every one of them. The old body-only `X-SN-Signature` is still sent alongside for the transition: Worker 1.26.0 checks only v2 when any v2 header is present, so v1 matters only to an older deploy. Once this release is live and the Worker logs show no v1 use, `SN_PROV_ACCEPT_V1` can be set to `0`; a later change drops the v1 header.


