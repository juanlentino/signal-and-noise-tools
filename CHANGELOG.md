# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The Insights scan and the weekly digest ask the AI Client for structured JSON.** Both requests now carry a JSON schema through core's `as_json_response()` (`snt_insights_response_schema()`, `snt_narration_response_schema()`), passed as a new optional `$json_schema` argument of `snt_ai_generate_with_constraints()`. The scan's schema root is an object, so its questions arrive under `questions` and the parser unwraps them. A response that came back under the schema is decoded as-is, with no fence strip and no salvage. The wrapper calls a reply structured only when it is a JSON object (both schema roots are objects); a schema request whose reply is not one (the format ignored, a refusal stop, another provider) goes to today's tolerant parser, so it never parses worse than today. If the structured request is refused (`prompt_client_error`, any 4xx from the provider, or `prompt_invalid_argument`, the client declining the options), the wrapper writes one `error_log` line saying so and sends the request once more without the schema, through itself: the monthly budget cap is checked again, and spend is recorded only for the request that ran. That retry keeps the scan's thinking, effort, ceiling and timeout, and today's tolerant parsing reads it. A token-limit stop is not retried, since that request ran and billed. The Anthropic provider (1.0.5, and its main branch) still sends the deprecated top-level `output_format` with the `structured-outputs-2025-11-13` beta header; whether the API takes it beside the scan's `output_config` effort is what the first live scan shows. Tests: `tests/insights-generation-budget.php`, `tests/insights-narration.php`.
- **The test sweep passes on WordPress 7.2 Beta 5 as well as 7.1.4.** In 7.2, core's block parser is built on `WP_Block_Processor`, so a block delimiter inside a plain HTML comment, and attribute JSON that never closes, now read the same to `parse_blocks()` and to the sn_apply delimiter reader. `tests/sn-apply-block-delimiters.php` and `tests/sn-apply-fingerprint-splice.php` now ask which the loaded core does (`tests/lib/parser-walker-agree.php`). Where the two disagree (7.1), the write is refused as before. Where they agree (7.2), the write must splice exactly the block `parse_blocks()` puts at that path and leave every other block as stored. The check that refuses a disagreement stays, because the live site runs 7.1. `tests/run.sh` now says how to sweep 7.2 (`WP_TEST_CORE_REF=trunk` until wordpress-develop has a 7.2 branch). One visible change comes with 7.2 itself: core lets list items through its automatic excerpts (7.1 already listed `core/list`, but its `core/list-item` children were dropped; 7.2 adds `core/list-item` and treats `core/list` as a wrapper), and the social card dek is built on core's `excerpt_remove_blocks()`, so a post with no hand-written excerpt (and a page with no meta description) that opens with a list will start its dek with the list's text. On 7.1 the list is skipped, as before; nothing changes until the site updates. `tests/og-card-dek-fallback.php` reads the loaded core's list and expects what it says. Otherwise test setup and comments only.

## [2026.11.23] - 2026-11-10 — Pull-quote suggestions build core's Pull Quote

### Fixed
- **Pull-quote suggestions follow the theme's core pull quote.** Since theme 2026.11.7 the pull-quote pattern is `core/pullquote` with the Pull-quote block style (`is-style-pull-quote`). The pattern-adoption suggestion now builds that markup, copied from the theme's `patterns/pull-quote.php`, instead of the old `sn-pattern-pull-quote` group, and the conventions registry fixture is refreshed from the theme's registry. Pull quotes already written as the old group or the `signal-noise/pull-quote` block are still not candidates, so published notes are neither flagged nor rewritten. Tests: `tests/pattern-adoption-suggest.php`, `tests/pattern-adoption-detect.php`.

