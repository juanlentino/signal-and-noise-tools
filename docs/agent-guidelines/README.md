# Signal & Noise Agent Guidelines

Speculative templates for `wp_guideline`-format agents that will become operational when [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240)'s Agents framework ships.

## What's in this directory

| File | Purpose | Role |
|---|---|---|
| `sn-brand-audit-agent.md` | Audit drafts for SN brand alignment | editor |
| `sn-draft-editor-agent.md` | Help write/refine SN-voiced content | editor |
| `sn-site-maintenance-agent.md` | Operational monitoring (non-destructive) | administrator |

## Status

These templates are PRE-PR-#240 (the Agents framework is currently a `[MOCK][DO NOT MERGE]` PR). The field names + structure may change. The ability slugs referenced are stable — verified against:

- Theme v9.1.2 abilities at [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise/blob/main/inc/abilities-registration.php) (12 abilities, `signal-and-noise/*` namespace)
- Plugin v3.7.4 abilities at [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise-tools/blob/main/inc/abilities-registration.php) (17 abilities, `signal-noise/*` namespace)

See the canonical [AI abilities catalog](../ai-abilities-catalog.md) for the full ability reference (tracks all 29 abilities across theme + plugin).

## Why pre-author

When the Agents framework ships and the harvester (PR #240 step 4 in the build order: Push MD compatibility audit) lands, SN will be among the first sites with ready-to-import agent definitions. Each `.md` here corresponds to one `wp_guideline` post that will be importable via the `pushmd push` direction documented in PR #240.

## How these templates map to PR #240's three-layer split

Per PR #240, an Agent is split across three WordPress primitives:

| Layer | Storage | This template covers |
|---|---|---|
| 1. Identity | `wp_users` row + role | `agent_role` field in frontmatter |
| 2. Behavior | `wp_guideline` post (`post_content` + `_agent_abilities` meta + child guideline links) | Body markdown + `agent_abilities` + `attached_skills` |
| 3. Bindings | Agent's `wp_users` row user_meta | "Trigger configurations" section — speculative |

Per PR #240, **Layer 2 is fully portable** — these files materialize to `wp_guideline/skills/{slug}/SKILL.md` via pushmd. Layer 3 (triggers, per-agent model overrides, rate limits) doesn't round-trip through pushmd and is intentionally annotated as speculative.

## What's intentionally NOT here

- **Destructive operations agents** (`signal-noise/purge-all-caches`, `signal-noise/clear-template-overrides`, `signal-noise/full-reset`, `signal-noise/unschedule-cron-event`) — these remain manual-only per the v9.1.2 audit decision. The Agents framework may eventually support `confirm: true` tool gating per the PR #240 security model; revisit then.
- **Skill posts** (sub-guidelines referenced via `attached_skills` in each agent) — those are separate `wp_guideline` posts. The agent templates reference them by slug; the skills themselves would be authored separately when the framework lands.
- **Any agent that requires `wp_guideline`-CPT registration code** — that's framework territory, not template territory. PR #240 step 1 (adopt `wp_guideline`) carries that work.

## Verification recipe (post-PR-#240)

When the Agents framework lands:

1. Run `pushmd pull` against a site that has these guidelines imported.
2. Confirm each agent materializes at `wp_guideline/skills/sn-{name}-agent/SKILL.md`.
3. Confirm the `_agent_abilities` post_meta values match the `agent_abilities` frontmatter list.
4. Confirm the agent's `wp_users` row carries the role specified in `agent_role`.

## Cross-references

- [AI abilities catalog](../ai-abilities-catalog.md) — canonical reference for all 29 abilities
- [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) — Agents framework mock
- [WordPress/desktop-mode#271](https://github.com/WordPress/desktop-mode/issues/271) — provider extensibility tracking
- [Plugin absorption roadmap](../superpowers/specs/2026-05-16-plugin-absorption-roadmap.md) — broader strategic context
