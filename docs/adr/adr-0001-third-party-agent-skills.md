# ADR-0001: Third-Party Agent Skills Policy

- **Status:** Accepted
- **Date:** 2026-07-30
- **Applies to:** reverbeat-demo, career-radar, sntools (Laravel), signal-and-noise (WP theme/plugin)
- **Supersedes:** none

## Context

Third-party agent-skill directories (skills.sh, skilld.dev, agenticskills.io) have made it
trivial to install community-authored `SKILL.md` files into Claude Code, Cursor, and similar
agents. A skill is markdown that an agent reads as instructions, executing inside repos that
hold Supabase service credentials, Vercel deploy rights, and a GitHub PAT.

We evaluated the skills.sh directory (~944K installs) against our stack: Next.js 15 + Supabase
+ Vercel (ReverBeat, Career Radar), Laravel + Railway (S&N Tools), WordPress FSE
(juanlentino.com).

Findings that drove the decision:

1. **Audit coverage is ~50 skills.** The skills.sh `/audits` page covers roughly fifty entries
   out of a directory with hundreds of published skills. "Check the audit first" is unactionable
   for most of the catalogue because no audit exists.

2. **The three audit columns measure different things and disagree.**
   `microsoft/azure-skills/azure-validate` scores Safe (Gen Agent Trust Hub), 0 alerts (Socket),
   and **Critical** (Snyk). Gen is behavioural, Socket is supply-chain, Snyk is dependency risk.
   For a markdown-only skill the Snyk column is close to noise. A single green badge is not a
   verdict.

3. **Aggregators contradict each other.** skills.sh reports `mattpocock/skills/handoff` as
   Safe / 0 alerts / Low Risk. skilld.dev reports "no third-party audits yet" for the same skill.

4. **Trust is per-skill, never per-repo.** `vercel-labs/agent-skills` contains
   `vercel-react-best-practices` (audited clean, instructional, no scripts) and
   `vercel-cli-with-tokens`, which discovers tokens in `.env` files and environment variables and
   is **not audited at all**. Same publisher, same repo, one directory apart.

5. **The real exposure is update-time, not install-time.** A reviewed SKILL.md can be silently
   changed by its author after installation. The agent then follows instructions nobody read,
   inside a repo with live credentials. No audit badge covers this.

6. **Dependency chains defeat single-file vetting.** `improve-codebase-architecture` spawns
   subagents, walks `git log`, writes to `CONTEXT.md` and `docs/adr/` inline (creating files
   lazily), emits an HTML report, and depends on `/codebase-design`, `/domain-modeling`, and a
   `setup-matt-pocock-skills` step rated Med Risk. It cannot be vendored as one file. `tdd` from
   the same repo is a stub into the same ecosystem.

7. **Content quality is not the same as fitness.** `mattpocock/skills/handoff` writes its handoff
   document to the OS temp directory rather than the workspace, which is the opposite of our
   documentation discipline. Open issue #306 documents the skill transcribing unverified beliefs
   as facts, causing a reporter to rebuild a feature that already existed. Fix proposed, not merged.

8. **Vercel's own evals favour AGENTS.md over skills.** Vercel published
   "AGENTS.md outperforms skills in our agent evals," and their KB directs users to AGENTS.md for
   always-on rules and skills only for on-demand specialised workflows.

9. **But the upstream AGENTS.md is 3,810 lines / 106 KB.** Roughly 25-30K tokens in every turn.
   Pasting it wholesale into an always-on file is not viable. Neither install nor wholesale copy
   is correct. The correct move is extraction.

## Decision

1. **No third-party agent skills are installed from any registry** into any repo we own.
2. **ReverBeat is zero-tolerance.** Anthropic first-party skills only. RB-PROV-002 and RB-PROV-003
   are held as trade secrets; a leak via a file-reading skill is a patent-strategy failure, not
   just a security incident.
3. **Where third-party content has real value, we extract, not install.** Read the source, take
   the principles, rewrite them compactly in our own architecture vocabulary, commit the result
   to our repo under our own authorship with upstream attribution. No registry link, no auto-pull.
4. **Always-on context is budgeted.** `AGENTS.md` stays under ~4 KB per repo. Anything longer
   lives in a reference file loaded on demand, never in the always-on path.
5. **One extraction executed:** Vercel's React/Next.js performance rules, distilled into
   `AGENTS.md` for reverbeat-demo and career-radar. Source:
   `vercel-labs/agent-skills/skills/react-best-practices` (MIT, © Vercel). Full rule set left
   upstream and consulted manually when a specific area needs depth.

## Consequences

**Accepted costs**
- No automatic upstream improvements. Re-check the source manually, roughly quarterly.
- Some genuinely good tooling is left on the table (`obra/superpowers` in full, Supabase's own
  agent-skills, Playwright CLI), because none of it is audited.
- Extraction costs an hour per source instead of one `npx` command.

**Benefits**
- No silent-update channel into repos holding live credentials.
- Always-on context stays lean, so our own custom skill layer keeps its trigger space instead of
  competing with thirty imported skill descriptions.
- Rules arrive already phrased in our vocabulary and merged with our non-negotiables, which
  imported skills cannot do.

**Explicitly rejected**
- `coreyhaines31/marketingskills` (cold-email, copywriting): conflicts head-on with the
  public-voice posture. Pitch energy, CTAs, urgency framing. Not a security call, a voice call.
- Whole-repo installs of anything, regardless of publisher.

## Revisit triggers

- Audit coverage on skills.sh moves from ~50 skills to a meaningful share of the catalogue.
- A registry ships version pinning with signature verification, so an installed skill cannot
  change under us without an explicit bump.
- The Agent Skills spec (agentskills.io) adds a provenance or signing mechanism.
- Anthropic ships first-party skills covering Supabase or Playwright, at which point they are in
  scope by default.
- Vercel publishes follow-up evals that reverse the AGENTS.md finding.
