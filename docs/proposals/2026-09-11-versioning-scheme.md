# Versioning scheme — what the number should say (proposal, 2026-09-11)

> **Decided the same day: Option B, the WordPress shape** — owner: "I'd go
> WordPress since it's a WordPress plugin." The recommendation below (C,
> CalVer) was not taken. The adopted rule is in [../VERSIONING.md](../VERSIONING.md).
> Kept as the record of what was weighed.

**Trigger:** the plugin cut v13.110.2 today and the owner said "I'm not liking
the three digits in the version." The theme sits at 12.20.6. Both follow the
global rule: SemVer, no caps, PATCH for fixes, MINOR for user-visible
capability, MAJOR for breaking — and one cut per merged fix in practice.

**Why the minor is 110.** Not SemVer's fault; cadence. Since v13.0.0 on
2026-08-25 the plugin has cut **215 tags in 17 days** — more than one per merged PR. SemVer's minor is a
*counter of feature releases*; at a dozen a day it reaches three digits in a
fortnight. No scheme with an unbounded counter survives that cadence with small
numbers — the choice is between a counter that resets on a rule and a
counter that resets on the calendar.

## What others do about the number getting big

| Who | Shape | What happens when the number gets big | Does MAJOR mean "breaking"? |
|---|---|---|---|
| **Linux kernel** | `X.Y.Z` | Linus bumps X when Y gets uncomfortable: 2.6.39→3.0 ("I can no longer comfortably count as high as 40"), 3.19→4.0 ("close to running out of fingers and toes"), 4.20→5.0, 5.19→6.0, 6.x→7.0. Stated openly as cosmetic. | **No.** Explicitly not. |
| **WordPress core** | `X.Y` is a major, `X.Y.Z` a fix | X.Y is bumped 3× a year; X rolls when Y hits 10 (4.9→5.0). "WordPress strives to never break backward compatibility." | **No.** Never. |
| **Apple OSes** | `26`, `26.1`, `26.1.2` | Moved in 2025 from +1 counters (iOS 18, macOS 15, watchOS 12) to the **year the release serves**; one number across every OS. | No — annual. |
| **Ubuntu** | `YY.MM.micro` | 26.04, 26.10. The calendar is the counter. | n/a |
| **pip / Twisted** | `YY.M.micro` | 26.2.1. Micro resets every month it ships. | n/a |
| **JetBrains** | `YYYY.N.micro` | 2026.2.3. N = nth release that year (3–4/yr). | n/a |
| **Chrome / Firefox** | integer `MAJOR` | 140, 141 … one per ~4 weeks; nobody minds three digits because there is only one number. | No. |
| **Node.js** | strict SemVer | MAJOR twice a year on a schedule; breaking changes batched to land there. | Yes, by schedule. |
| **Rust** | `1.Y.Z` | 1.90 and counting; MAJOR frozen at 1 forever by promise. | Yes — which is why it never moves. |

Two lessons stand out. **(1) Every project that cuts often either lets the
first number roll on a stated non-semantic rule (kernel, WordPress, Chrome)
or lets the calendar do it (Apple, Ubuntu, pip).** Nobody keeps strict
SemVer at a daily cadence. **(2) The projects whose numbers read best are the
ones where the first two components are *given*, not *earned*** — a reader
of "26.9.3" knows when it shipped without a changelog; a reader of
"13.110.2" knows nothing.

## What our SemVer digits actually carry today

- **MAJOR (13):** last moved for a real break. Under the current rule it
  will never move again unless something breaks, so it is a constant.
- **MINOR (110):** "a feature release". It carries no information a reader
  uses — nobody asks "is this after minor 87?"
- **PATCH (2):** "a fix". Also the only one that ever resets.

The one thing SemVer promises — *a MAJOR bump warns of breakage* — this
plugin has no external API consumer to warn. The theme is its only
integrator, both are owned here, and skew lands at install, not at build
(memory: `cross-repo-schema-skew`). The signal is spent on nobody.

## Options

### A — Keep SemVer, adopt the kernel rule for MAJOR

Bump MAJOR whenever MINOR reaches ~20, documented as cosmetic. Nothing else
changes. Numbers look like 14.3.1, 15.7.0.

- Cost: one sentence in `docs/VERSIONING.md`; `cut-release.sh major` already exists.
- Problem: at one cut per arc (say 8/month) MAJOR moves every 2–3 months. And it reintroduces exactly the "fictional major" the v4.4.x audit removed — the owner's own rule says MAJOR means breaking.

### B — WordPress shape: `X.Y` is the release, `X.Y.Z` the fix

Same arithmetic as A with a different story; the arc becomes the "major".
Rejected for the same reason: Y still counts arcs and rolls into X.

### C — CalVer `YY.M.micro` (pip/Twisted shape) — **recommended**

`26.9.0` is the first cut in September 2026; `26.9.1` the next; October
starts at `26.10.0`. Both repos move on the same day and share the prefix,
the way Apple unified its OSes on "26".

- The first two components are given by the date. **Only micro grows, and it resets monthly.** At the current cadence micro stays one digit if we cut per arc, two at worst.
- Reads as a date to any human and to `version_compare()` alike: `26.10.0 > 26.9.14 > 13.110.2 > 12.20.6`, so the WP updater sees every future release as newer than every past one on both repos. No downgrade window.
- CHANGELOG, tags, releases, `version-tag-parity`, CI rule 1 (`[0-9]+\.[0-9]+\.[0-9]+`) all keep working — the shape is still three integers.
- "What kind of change was it?" moves to where it already lives: the CHANGELOG heading and the `### Security / Fixed / Added / Changed` sections. A breaking change gets **BREAKING** in the release headline (Node's practice, without the schedule). That is more honest than a MAJOR that moved once in seventeen days of 215 tags.
- Cost: `cut-release.sh` computes NEXT from the date instead of `patch|minor|major` (~15 lines, keep the level argument as a no-op for one cycle so muscle memory does not fail); `docs/VERSIONING.md`, both `CLAUDE.md`s, the global CLAUDE.md line, the `versioning` skill, and three memories say the new rule; theme `style.css` and plugin header take their first `26.9.0`.

### D — CalVer `YYYY.N` (JetBrains shape)

`2026.45`. N counts releases in the year — at this cadence it reaches three digits again by December. Rejected.

### E — Cut less, change nothing

One cut per arc instead of per fix (already decided today; memory
`fourteen-waits-for-something-net-new`). Slows the growth from ~12/day to
~2/week; the minor still passes 200 within the year. Necessary under any
option; not sufficient on its own.

## Recommendation

**C, plus E.** `YY.M.micro`, both repos on the same day, one cut per arc.
The numbers get small and stay small because the calendar resets them; the
"kind of change" signal moves to the headline where a reader actually looks;
nothing in the release machinery has to learn a new shape.

What it gives up: the *theory* of SemVer. What it keeps: everything SemVer
was doing for this project in practice, which was the CHANGELOG.

If C is too big a jump: **A** is the honest fallback — the kernel has run on
it for fifteen years without anyone mistaking 5.0 for a rewrite — but say
so in `VERSIONING.md` in Linus's words, so the next audit does not read
14.0.0 as a fictional major.

## If you say go

1. Plugin: `cut-release.sh` date-driven NEXT; `docs/VERSIONING.md` (new, the theme has one, the plugin does not); README §Release log; first cut `26.9.0` carrying #1173 and whatever else is under Unreleased by then.
2. Theme: same script change; `docs/VERSIONING.md` + `CLAUDE.md` §Versioning; first cut `26.9.0` carrying #301.
3. Global `~/.claude/CLAUDE.md` §Git & Versioning: one paragraph replacing the SemVer line for these two repos; the `versioning` skill reads project overrides first, so it follows.
4. Memories: `a-fix-is-a-patch-even-when-it-adds-code` (retire), `fourteen-waits-for-something-net-new` (superseded — no 14.0.0, ever), `theme-version-bump-touches-three-files` (unchanged), `version-header-and-tag-are-contended` (unchanged).
5. Verify with the parity cron (`version-tag-parity.yml`) and one `get-deploy-status` read after each repo's first cut: `latest` must read `26.9.0` and `state: ok`.

Sources: [WordPress version numbering](https://make.wordpress.org/core/handbook/about/release-cycle/version-numbering/) · [Linux 3.0 announcement](https://www.linuxfoundation.org/blog/blog/its-official-linux-3-0-released) · [Torvalds on the 4.0 bump](https://linux.slashdot.org/story/15/02/13/1341213/torvalds-polls-desire-for-linuxs-next-major-version-bump) · [Linux 7.0 and the arbitrary bump](https://www.webpronews.com/linux-7-0-arrives-but-dont-expect-fireworks-inside-linus-torvalds-famously-arbitrary-version-bump/) · [Apple's move to year-based numbers](https://www.engadget.com/apps/ios-26-is-official-apple-changes-from-version-numbers-to-years-for-its-os-names-172129166.html) · [Why iOS 26, not 19](https://9to5mac.com/2025/09/16/why-is-it-called-ios-26-what-happened-to-ios-19-for-iphone/) · [calver.org](https://calver.org/)
