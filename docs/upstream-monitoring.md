# Upstream signal monitoring

What to watch upstream for changes that affect our AI-readiness preparation. Run this playbook periodically (weekly is plenty); each section is self-contained and copy-pasteable.

## Why this exists

SN's 29 abilities are AI-tool-ready at theme v9.1.3 + plugin v3.7.5. We're waiting for one of three upstream signals to crystallize before the abilities light up as Copilot tools:

1. **[WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240)** — Agents framework MOCK. Step 3 of its 11-step plan adds the Abilities-as-tools harvester (auto-promotes `wp_register_ability()` registrations to LLM tools).
2. **[WordPress/desktop-mode#271](https://github.com/WordPress/desktop-mode/issues/271)** — Provider extensibility. Collaborator AllTerrainDeveloper signaled they're waiting for WordPress Core to crystallize the provider abstraction (per [their 2026-05-24 comment](https://github.com/WordPress/desktop-mode/issues/271#issuecomment-4530436691)). The issue is `roadmap`-labeled with no assignee or milestone.
3. **WordPress Core** — `wp_ai_client`-derived provider abstraction. If/when WP Core grows native AI provider plumbing, that becomes the primary integration surface.

Whichever ships first unlocks the next phase: Anthropic provider via that channel, then SN's abilities surface to the Copilot automatically (or via thin upstream adapter).

## Passive monitoring (already configured)

The user is subscribed to the WordPress/desktop-mode repo as of 2026-05-25 (`gh api -X PUT /repos/WordPress/desktop-mode/subscription -f subscribed=true`). Notifications fire on:
- Issues opened/commented on
- PRs opened/merged
- New releases

If noise becomes excessive, downgrade to "Watching: Custom" via the repo's web UI and select only `Issues` + `Pull requests` + `Releases`.

## Active monitoring playbook (run weekly)

Paste this block into a chat with Claude (or run inline via `gh` directly). Reports only when something actionable has changed since the last check.

```
Weekly upstream signal check for SN AI-readiness preparation. Run these in order; produce a brief report (one-line status if nothing changed, otherwise bullet list of changes + recommended next action):

1. Check WordPress/desktop-mode#271 status:
   gh issue view 271 --repo WordPress/desktop-mode --comments
   Flag: any new comments after the last check, state change (open → closed → assigned → milestoned), or maintainer commits to building this themselves.

2. Check WordPress/desktop-mode#240 status:
   gh pr view 240 --repo WordPress/desktop-mode
   Flag: status change (DRAFT → READY, MOCK → real implementation, merged, closed), new commits to the `my-agents` branch, any reviews requested.

3. Check for new built-in providers:
   gh api 'repos/WordPress/desktop-mode/contents/includes/ai-copilot?ref=trunk' --jq '.[] | .name'
   Baseline files as of 2026-05-24: bootstrap.php, analysis.php, hooks.php, jobs.php, openai.php, platform-settings.php, providers-registry.php, reindex.php, search.php, settings.php, tools-registry.php.
   Flag: any new file (e.g., anthropic.php, gemini.php, claude.php) → ALERT, this is the signal we've been waiting for.

4. Check recent commits to includes/ai-copilot/:
   gh api 'repos/WordPress/desktop-mode/commits?path=includes/ai-copilot&since=YYYY-MM-DD' (substitute today-minus-7-days)
   Flag: commits adding Abilities-as-tools harvester, new providers, or breaking changes to the registry contract.

5. Check WordPress Core AI activity:
   gh api repos/WordPress/wp-ai-client/commits --jq '.[0:5] | .[] | "\(.sha[0:7]) \(.commit.author.date) \(.commit.message)"'
   Flag: commits mentioning provider abstraction, function registry, or tool surfaces.

Context to bake into the response:
- SN abilities are AI-tool-ready at theme v9.1.3 + plugin v3.7.5
- 29 abilities registered (12 theme `signal-and-noise/*` + 17 plugin `signal-noise/*`)
- Waiting for: PR #240 step 3 (Abilities-as-tools bridge) OR a Core-driven provider story
- When signal fires: re-evaluate the Anthropic-provider work (currently parked in SN-tools git history at commits d3d89cc, 92e39cc, a1275b2 — preserved in case we port it later)
```

## What "actionable" looks like

The playbook output should answer one question: **do we need to do anything this week?**

- **Status quo (most common):** "No changes in #271 or #240 status; no new providers; no relevant Core commits. Continue waiting." → no action.
- **Minor activity:** "1 new comment on #271 from @user; no decision yet." → no action, FYI only.
- **Real signal:** "PR #240 step 3 commits landed in `my-agents` branch this week — Abilities-as-tools bridge appears to be in progress." → action: open a new SN session to test our 29 abilities against the harvester.
- **Big signal:** "anthropic.php landed in includes/ai-copilot/ as part of PR #X." → action: install desktop-mode `trunk` on a dev site, select Anthropic, smoke-test against our abilities, ship a coordinated SN release if anything needs adapting.
- **Different big signal:** "WordPress Core merged native AI provider abstraction in trunk." → action: reassess our preparation against the new Core APIs; some of our `wp_register_ability` work may compose differently.

## When the harvester ships

The first run after PR #240 step 3 lands should:

1. Install the upstream version on a dev WP site
2. Run `wp ability list` — verify all 29 SN abilities show up
3. Configure the Copilot with whichever provider is active (OpenAI initially, until our Anthropic story matures)
4. Test natural-language invocation against each ability category (read, generative, operations)
5. Check whether descriptions/parameters/schemas are interpreted correctly
6. Document any required tweaks → ship as theme v9.1.4 + plugin v3.7.6 (or later) coordinated release

## When the Anthropic story crystallizes

If/when desktop-mode (or WP Core) ships a path to add Anthropic as a Copilot provider:

1. Review what they shipped vs the architecture we'd planned in [`docs/superpowers/specs/2026-05-24-plugin-v3.8.0-anthropic-provider-design.md`](superpowers/specs/2026-05-24-plugin-v3.8.0-anthropic-provider-design.md) (CANCELLED, but preserved)
2. If upstream provides everything we need → done, no SN code change
3. If we still need to bridge something → port the implementation from git history (commits `d3d89cc`, `92e39cc`, `a1275b2`) with renames to match upstream conventions

## Manual check intervals

- **Weekly:** run the playbook above
- **After any GitHub notification firing from the subscription:** spot-check the firing issue/PR
- **After WordPress Core releases:** check for AI-related additions in the release notes
- **Whenever curious:** the playbook is idempotent and cheap

## Cross-references

- [AI abilities catalog](ai-abilities-catalog.md) — what we have
- [Agent guideline templates](agent-guidelines/README.md) — what we'll wire up when Agents ships
- [v3.8.0 cancellation spec](superpowers/specs/2026-05-24-plugin-v3.8.0-anthropic-provider-design.md) — the design we'd preserved
- [v3.8.0 cancellation plan](superpowers/plans/2026-05-24-plugin-v3.8.0-anthropic-provider.md) — the execution plan we'd preserved
