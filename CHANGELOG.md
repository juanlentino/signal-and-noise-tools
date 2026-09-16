# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.6.0] - 2026-09-16 — Agent tools


- **Added:** AI › Agent tools, every tool an agent can call on this site, by door, and whether it does (`inc/agent-tools.php`, `inc/agent-tools-admin.php`, `apps/sn-dashboard/parts/leaves/ai-agent-tools.php`; one model, two painters). On the page: the WebMCP bridge's five tools with calls and outcomes (ok, absent, error) over 30 days, from the beacon's rows the fetch splits off the reads (`snt_mr_split_webmcp()` now keeps outcomes per tool, read from the purpose slot), under the caption "reported by browsers"; a tool seen in the rows but not registered is painted, starred; beside them, what the tools read with a verdict each: `/notes/index.json` (built when, how many), the related manifest on the latest note, the bridge's anchoring from the rights-anchoring check's remembered state. Through MCP: the call log open under the section and the four doors paired inside one fold; the door inventories and the call log moved here from MCP Clients, which keeps how to connect. Through Copilot: the retired Copilot Usage leaf's whole content. The AI tab reads config, config, observation: Models & Budget, MCP Clients, Agent tools.


