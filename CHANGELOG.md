# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Fixed:** the edge posture's "Also set" line read `TLS 1.3 zrt`, Cloudflare's value for TLS 1.3 on with 0-RTT, as an API id; it reads `on (0-RTT)`. The three posture scopes on Connections › Credentials are `measured`: all three answered on 2026-09-16 (the ruleset read is also satisfied by Account WAF Read at the account level, which the schema lists beside Zone WAF Read). What the first live read found, for the record: SSL mode `full` (the origin served Cloudways' wildcard certificate, so strict would have failed) and DNSSEC `disabled`; by the end of the day the origin presents a Cloudflare Origin CA certificate for juanlentino.com, the zone is on Full (strict), the DS record is at the registry and DNSSEC reads `active`. Five green dots.

## [15.4.0] - 2026-09-16 — the edge posture


- **Added:** the edge posture (`inc/cloudflare-posture.php`): three documented Free-plan REST reads, daily on the Cloudflare monitor's hook and on Refresh now, of what the edge is SET TO, which the headers probe cannot see: zone settings (SSL mode, minimum TLS, Always Use HTTPS, Development Mode, plus readings), DNSSEC status, and the custom WAF rules by name, action and state. Security › Firewall paints it as "Edge posture" under Acted on (judged rows with a dot, the readings as one quiet line, the rules by name; a refused read is a notice naming the scope), and the Top rules list carries rule names instead of ids. New Health check 27, `cf_edge_posture` (edge family, Health surface): Flexible or Full without strict, TLS below 1.2, Always Use HTTPS off, Development Mode on and DNSSEC not active are findings; a refused read is a finding naming the scope, never a pass; no record, a stale one or a transport failure is skipped with the reason. The abilities WAF witness reads the ruleset first: a rule that exists, is enabled and blocks is the fact on a quiet day; disabled or absent is open whatever the log or the monitor say; a refused or stale read falls through to the log, then Better Stack, as before. `signal-noise/cloudflare-status` carries `posture`. The token needs three more read scopes, named as the API reference names them: Zone Settings Read, Zone WAF Read, DNS Read (Connections › Credentials lists them; `documented` until the first live read).


