# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed (AI on core, 2026-10-24)
- **Copilot and agent spend come from core's AI event, call by call, and every other AI call has a row of its own.** Core 7.0 fires `wp_ai_client_after_generate_result` after every AI Client call with that call's token usage and the model the provider actually served (`PromptBuilder::generateResult()`). `inc/ai-copilot-spend.php` now prices each call from it, so a run that switches models is priced at each model's own rate (the station hooks summed the run and priced it at the last model's rate), the Copilot follow-up leg is priced (it reported no usage), and agent spend no longer needs OpenStation 1.1.14. Attribution reads `$context['source']` off OpenStation's `openstation_ai_model_config` filter, a pass-through that fires right before the station generates, handed to the call through core's before and after events so a call that fails inside the provider cannot lend its label to the next one: `ai-copilot/search` and `ai-copilot/followup` are Copilot, `agents/runner` is Agents, and everything else (agent drafting, the MIO window, the Drafts widget, comment analysis, the AI plugin's features, any unlabeled call) lands in a new Other AI bucket (`sn_ai_other_spend_month`, `sn_ai_other_unpriced_month`), never dropped. The plugin's own calls stay out: `snt_ai_generate_with_constraints()` holds `snt_ai_own_call_depth()` above zero around its generate call, which `inc/ai-bootstrap/usage-log.php` already prices into the cap's ledger. The `openstation_ai_search_completed` and `openstation_agent_completed` spend subscribers are removed: the core event carries everything they did, per call (the agent telemetry subscriber on `openstation_agent_completed` is untouched). Cache tokens are still summed into input by the provider (WordPress/ai-provider-for-anthropic#33), so every prompt token is priced at the fresh input rate, as before. AI › Models & Budget, classic and native, paints the Other AI row beside Copilot and Agents, outside the cap; the unpriced hints count calls, not turns or runs, and the Agents note no longer says a pre-1.1.14 station is unpriced. Pinned: `tests/ai-copilot-spend.php` (rewritten on the core event, 23 red on main), `tests/os-leaf-ai-models-budget.php` (10 red on main).

### Security
- **The official mcp-adapter's transport floor is raised to `manage_options`.** Its default server (the one the AI plugin's MCP Access experiment installs) passes no permission callback, so `HttpTransport::check_permission()` falls back to `mcp_adapter_default_transport_permission_user_capability`, default `read`: any logged-in subscriber reached the transport. Our own MCP doors require `manage_options`; `sn_mcp_adapter_transport_floor()` holds the adapter to the same floor. Inert while the adapter is absent. Pinned in `tests/mcp-endpoint.php` (red on main).

### Fixed
- **The ai/ai dedupe docblock no longer calls an existing-terms tag mode future.** The AI plugin's Content Classification has offered "Only suggest existing terms" since it landed in 0.7.0 (read at the 0.7.0 and 1.0.2 tags). Comment only; both dedupe filters are unchanged. Pinned in `tests/ai-ai-dedupe.php`.

## [2026.10.63] - 2026-10-24 — fixes from the core comparison

### Changed
- **The override count, list and confirm copy match what the clear removes.** Theme #604 scopes the template-override clear to the active theme's `wp_template` and `wp_template_part` rows, in every status core renders (auto-draft included), and keeps navigation posts (menus). The plugin still counted and listed every theme's rows plus menus, so the dashboard would keep reporting overrides after a clear and the list ability would disagree with the clear. One query, `snt_template_override_query_args()`, now feeds the dashboard count, the diagnostics list and `list-template-overrides`; the confirm dialog, command palette, admin bar and ability descriptions say menus are kept. Ships with the theme release carrying #604.

### Fixed (the core comparison, 2026-10-24)
- **The weekly narration digest gets its 120 seconds.** Core's AI client (7.0) passes an explicit 30 s timeout on every request, which beats `http_request_timeout`, so the raise did nothing and the digest kept dying at 30 s: no run is recorded and the remote narration is empty. The raise now goes through core's own `wp_ai_client_default_request_timeout`, scoped to the one call as before.
- **Logged-in visitors are not prerendered.** Core passes a null speculation configuration for a logged-in visitor (and a site without pretty permalinks); the plugin turned that back into prerender/moderate. Core's null now stays null; the toggle and the /contact/ exclusion are unchanged.
- **A note's Open Graph `article:modified_time` reads the same clock as its JSON-LD and sitemap** (`sn_schema_date_modified()`: the provenance commit, else post_modified). post_modified alone is untouched by a scheduled publish, so live notes said modified a week before published.
- **The CSP check reads again soon after an update.** Core's import-map hash changes with every core update and an update can add an inline script; enforced, a stale hash stops every script module, and the daily read alone left that up to a day. `upgrader_process_complete` and a changed core version (a host-side update) now queue one read five minutes out.
- **The door leak check reads a password-protected post.** Core posts the password form to `wp-login.php?action=postpass`, which the door's URL filter rewrites to the hidden slug, so one published protected post would publish the door. None exist today (95 published posts and pages, none protected); the newest one is read if one ever does.

### Removed
- **`inc/robots-txt.php`.** It never ran (a physical robots.txt and the rights-signals Worker answer /robots.txt), and its default list marked GPTBot, ClaudeBot, Google-Extended, CCBot and Applebot-Extended as allowed while the live rules block all five, so deleting the physical file would have printed a contradiction. The Worker owns the AI policy and the Sitemap line.

### Fixed
- **A post save purges its Markdown twin too.** The WordPress AI plugin's Markdown Feeds experiment serves every post and page as Markdown at `?output_format=markdown`, and the edge caches that address under its query string (`cf-cache-status: HIT`), so an edit left agents reading the old text until the cache revalidated. That copy answers before the theme tags the response, so the tag purge the live site uses never reaches it: every save path (the note and wide tag purges, and the URL list for an older theme) now sends the permalink's `?output_format=markdown` by URL, from the pre-change post when leaving publish.

