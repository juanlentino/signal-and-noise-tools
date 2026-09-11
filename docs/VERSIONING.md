# Versioning

**The WordPress shape, since 2026-09-11.** `X.Y.0` is a release, `X.Y.Z` is a
fix to it, and `X` rolls by itself when `Y` would reach 10. This is how
WordPress core numbers (4.9 → 5.0), and how WooCommerce, Jetpack, Yoast and
ACF number, so a WordPress reader already knows how to read it.

| Component | Means | Moves when |
|---|---|---|
| `X` | the tens digit of the release count | `Y` would become 10: `14.9.0 → 15.0.0` |
| `Y` | a **release** — one arc of merged work, features or fixes | `tools/cut-release.sh release` |
| `Z` | a **fix** to a shipped release | `tools/cut-release.sh fix` |

Three components always (`14.0.0`, the WooCommerce/Jetpack form — not core's
`6.8`): PHP's `version_compare( '14.0', '14.0.0' )` calls the two-part form
*older*, and every tool in this repo parses three integers.

## What X is not

**`X` is not a breaking-change flag.** It carries the same information as
the tens digit of a page number. A release that breaks something — removes
or renames a public hook, changes a settings schema without a migration,
shifts behaviour so that a site has to act — says **BREAKING** in its
CHANGELOG headline and release title, on whatever number it happens to get.
That is where a reader looks; a first digit never told them.

There is no `major` argument. `cut-release.sh major` refuses and says why.

## Why this replaced SemVer

The global rule was SemVer with no caps: PATCH for fixes, MINOR for
user-visible capability, MAJOR for breaking. In practice:

- `MAJOR` moved once in 215 tags (17 days, 2026-08-25 → 09-11), because
  nothing broke. A signal nobody receives.
- `MINOR` reached **110**. It counted feature releases at a dozen a day and
  meant nothing to anyone reading `13.110.2`.
- The one thing SemVer promises — a warning to API consumers — this plugin
  has no external consumer to warn. The theme is its only integrator, both
  are owned here, and skew lands at install time, not at build time.

The owner's words on 2026-09-11: "I'm not liking the three digits," then
"I'd go WordPress since it's a WordPress plugin." The research that preceded
it, with what the kernel, Apple, Ubuntu and CalVer do about a number getting
big, is in
[proposals/2026-09-11-versioning-scheme.md](proposals/2026-09-11-versioning-scheme.md).

## Cadence — the half that keeps the number small

The shape bounds each component to two digits only if releases are cut per
**arc**, not per merged fix. One release when a body of work closes; a fix
only for something that has to reach sites before the next arc. At two arcs
a week `X` climbs about one per five weeks; that is the intended rate. Cutting
after every merge would roll `X` weekly, which is the old problem in a new
column.

A `tools/`, `docs/`, `.github/`-only merge never gets its own cut; it rides
the next release.

## What a cut is

A pull request does not bump `Version` and does not tag — it adds a bullet
under `## [Unreleased]`. A cut is the separate, deliberate act:

```bash
git checkout -b release/vX.Y.Z origin/main
tools/cut-release.sh release "headline"      # or: fix "headline"
git add -A && git commit -m "vX.Y.Z: headline"
git push -u origin release/vX.Y.Z && gh pr create …
# green → squash-merge → tag the SQUASH commit → push the tag → gh release create --verify-tag
```

The cut is a PR because direct pushes to `main` are refused (ruleset, admin
bypass is PR-only since 2026-09-11). `cut-release.sh` refuses a dirty tree,
an empty Unreleased, and a previous release whose section grew after its tag
(a branch opened before that cut lands its bullet under a released heading —
the guard names the strays).

## CHANGELOG

`CHANGELOG.md` holds `## [Unreleased]` and the current release; everything
older is in [changelog/](changelog/). Sections inside a release are
`### Security`, `### Fixed`, `### Added`, `### Changed`, `### Removed`.
Headings are `## [X.Y.Z] - YYYY-MM-DD — headline`; a breaking release's
headline starts with `BREAKING:`.

## See also

- The theme's [docs/VERSIONING.md](https://github.com/juanlentino/signal-and-noise/blob/main/docs/VERSIONING.md) — same shape, same day.
- `.github/workflows/ci.yml` "CHANGELOG entry present" — rule 1 (a bump needs its heading) and rule 2 (shippable code needs an Unreleased bullet) are unchanged by the shape.
- `.github/workflows/version-tag-parity.yml` — the header must have a tag on `main`, daily.
