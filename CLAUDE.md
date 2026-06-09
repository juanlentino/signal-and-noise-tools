# Signal & Noise Tools — companion plugin

Operational tooling plugin for juanlentino.com, companion to the `signal-and-noise` FSE theme (which holds the broader project CLAUDE.md + `docs/VERSIONING.md`). PHP / WordPress. Releases are user-driven via wp-admin → Updates (deploy is `workflow_dispatch`-only since v1.10.1).

## Dev-loop tooling (local Claude Code — not a wp-admin surface)

Local Claude Code plugins harden the dev loop *before* commit (dev/CI tooling lives here in CLAUDE.md, never as a plugin settings tab — it has no WordPress runtime config):

- **php-lsp / Intelephense** (`/plugin install php-lsp@claude-plugins-official` + `npm i -g intelephense`) — real PHP diagnostics on the primary language. Catches unknown-symbol fatals before runtime (the class behind the theme's v9.11.2 single-notes incident: `get_the_queried_object_id()` vs `get_queried_object_id()`), which neither phpcs nor a test stub of the bad symbol catch.
- **security-guidance** (`/plugin install security-guidance@claude-plugins-official`) — pattern + LLM review for injection/XSS/SSRF/hardcoded-secrets at edit + commit time (the webhook-SSRF (v4.5.2) + never-commit-PAT classes). Scope its LLM hooks to commit/PR moments, not every keystroke, to control token spend.

CI mirrors this on PRs — see `.github/workflows/security-review.yml` + `.github/workflows/claude-review.yml`.
