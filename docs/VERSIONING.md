# Versioning

**By date, since 2026-10-11.** A version is `YYYY.M.N`: the year, the month,
and the cut's place in that month. `2026.10.1`, `2026.10.2`, then `2026.11.1`.

| Component | Means | Moves when |
|---|---|---|
| `YYYY` | the year of the cut (UTC) | the year changes |
| `M` | the month of the cut, no leading zero | the month changes |
| `N` | the cut's place in that month, from 1 | every cut; back to 1 in a new month |

## Why by date

This plugin is private. One site installs it, it is not on WordPress.org and
never will be. A version number has one reader, that site (and the agents that
work on it), and it has three jobs: always go up, say at a glance how old an
install is, and name a point to roll back to. A date does all three.

The two schemes before it assumed a reader who does not exist:

- **SemVer** (to 2026-09-11) warns API consumers about breakage. There are
  none: the theme is the only integrator, and both are owned here. `MAJOR`
  moved once in 215 tags; `MINOR` reached 110.
- **The WordPress shape** (2026-09-11 to 2026-10-11: `X.Y.0` a release,
  `X.Y.Z` a fix, `X` rolling when `Y` would reach 10) is how WordPress.org
  plugins number, so their readers can read it. Cut per merge, it rolled `X`
  ten times in a month: 102 releases and 130 fixes, from 14.0.0 to 24.0.0.

The number can no longer run away: its first part is the year. How often to
cut is now a question about installs (each one is a click, and a cache purge
when the release changes the public site), not about the number.

Three integers always, as before: PHP's `version_compare` orders
`2026.10.1` above `24.0.0` and `2026.10.12` above `2026.10.9`, the GitHub
tags API lists the highest version first, and the one hard-coded comparison
(the purge ledger's theme `>= 15.0.0`) still holds. `cut-release.sh` refuses a
next version that would not sort above the current one.

## Release, fix, BREAKING

The number no longer says whether a cut adds or repairs; the CHANGELOG does.
`cut-release.sh release` is the only kind (`fix`, `patch` and `minor` are
accepted and mean the same). A cut that breaks something (removes or renames
a public hook, changes a settings schema without a migration, needs the site
to act) says **BREAKING** in its CHANGELOG headline. There is no `major`
argument; `cut-release.sh major` refuses and says why.

## Cadence

Merge freely. Cut when there is something to install: an arc that closed, a
regression the site should not keep, or a change the owner wants to see. At
most once a day unless a regression cannot wait. A `tools/`, `docs/`,
`.github/`-only merge never gets its own cut; it rides the next one. Show a
visible change on live data (the widget preview, Playground) before it merges,
so a problem found after install does not cost a cut.

## What a cut is

A pull request does not bump `Version` and does not tag; it adds a bullet
under `## [Unreleased]`. A cut is the separate, deliberate act:

```bash
git checkout -b cut/YYYY.M.N origin/main
tools/cut-release.sh release "headline" --front-end=no   # --dry-run first; --front-end=yes when the public site changes
git add -A && git commit -m "vYYYY.M.N: headline"
git push -u origin cut/YYYY.M.N && gh pr create …
# green, squash-merge, then:
gh workflow run release.yml -f version=YYYY.M.N
```

`.github/workflows/release.yml` refuses unless main's `Version:` header equals
the input and the tag does not exist yet. It finds the commit that set the
header (the squash commit, never main's head) and tags it. It creates no
GitHub release: the site's updater reads tags, and nothing reads a release.

The cut is a PR because direct pushes to `main` are refused (ruleset, admin
bypass is PR-only since 2026-09-11). `cut-release.sh` refuses a dirty tree,
an empty Unreleased, a next version that does not sort above the current one,
and a previous release whose section grew after its tag (a branch opened
before that cut lands its bullet under a released heading; the guard names
the strays).

## CHANGELOG

`CHANGELOG.md` holds `## [Unreleased]` and the current release; everything
older is in [changelog/](changelog/). Sections inside a release are
`### Security`, `### Fixed`, `### Added`, `### Changed`, `### Removed`.
Headings are `## [YYYY.M.N] - YYYY-MM-DD — headline` (the dash before the
headline is part of the machine contract CI reads); a breaking release's
headline starts with `BREAKING:`.

## See also

- The theme's [docs/VERSIONING.md](https://github.com/juanlentino/signal-and-noise/blob/main/docs/VERSIONING.md): the same scheme, the same day.
- [proposals/2026-09-11-versioning-scheme.md](proposals/2026-09-11-versioning-scheme.md): the research behind the WordPress shape, including what CalVer projects do.
- `.github/workflows/ci.yml` "CHANGELOG entry present": a bump needs its heading, and shippable code needs an Unreleased bullet.
- `.github/workflows/version-tag-parity.yml`: the header must have a tag on `main`, daily.
- The workers and the provenance verifier keep SemVer: the verifier is downloaded by strangers, and Deploy Status reads the workers' GitHub releases.
