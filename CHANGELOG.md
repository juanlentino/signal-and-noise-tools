# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.4.1] - 2026-09-16 — the edge posture, measured


- **Fixed:** the edge posture's "Also set" line read `TLS 1.3 zrt`, Cloudflare's value for TLS 1.3 on with 0-RTT, as an API id; it reads `on (0-RTT)`. The three posture scopes on Connections › Credentials are `measured`: all three answered on 2026-09-16 (the ruleset read is also satisfied by Account WAF Read at the account level, which the schema lists beside Zone WAF Read). What the first live read found, for the record: SSL mode `full` (the origin served Cloudways' wildcard certificate, so strict would have failed) and DNSSEC `disabled`; by the end of the day the origin presents a Cloudflare Origin CA certificate for juanlentino.com, the zone is on Full (strict), the DS record is at the registry and DNSSEC reads `active`. Five green dots.


