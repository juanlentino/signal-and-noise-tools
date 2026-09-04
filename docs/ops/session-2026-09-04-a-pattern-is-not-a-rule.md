# Session — 2026-09-04: a pattern is not a rule

Fourth arc of the day. The others are `the-things-that-come-due` (the watch
system), `found-by-using-it` (the audit and seven releases) and
`a-version-should-mean-a-release` (the process rewrite). This one started as
"find issues and put the new mechanics to the test" and turned into the same
sentence, found six times in six places.

Plugin **13.96.0 → 13.96.2**, theme **12.18.5**, eleven issues, one filed
upstream. Every fix shipped through the process the previous arc built, which
was the actual test.

## The sentence

**A population defined by a pattern, reported as if it were defined by a rule.**

The pattern and the rule agree the day they are written. Then the tree grows and
they diverge in silence, because nothing was ever comparing them.

| where | the pattern | the rule it stood in for |
|---|---|---|
| 14 test guards (#987) | `inc/*.php` | every PHP file under `inc/` |
| the contrast health check (#988) | `assets/*.css` | every front-end stylesheet |
| `editor-api-smoke` (#992) | `inc/*.php` + `assets/*.js` | everything that registers a script |
| the theme (#268) | a hand-written map of three | every file in `styles/blocks/` |
| the post-save purge (#1008) | five hardcoded URLs | every cached surface a save invalidates |
| `no-literal-unicode-escapes` | `inc/*.php` + one hand-listed package | every PHP file |

That last one is the sharpest. It merged `inc/*.php` with a hand-listed
`inc/admin-forms/*.php` — correct when written, when `admin-forms` was the only
package. Five more appeared and 71 files left its reach. Its own anti-vacuity
check is `count( $files ) > 100`, and **443 clears that exactly as comfortably
as 514 does.** A size check cannot tell a complete population from a truncated
one. It is the guard that caught a stray `…` the day before, so its reach
was load-bearing.

## The cache one, which is the one that mattered to a human

The owner said the cache was broken. I said the instruments disagreed:
`cache-freshness` answered `fresh`, verified twenty minutes earlier.

The instrument was wrong and he was right. `sn_cf_purge_urls_for_post()`
submitted five hardcoded URLs. Measured against the live edge, three cached
surfaces were absent — `/notes/page/2..4/`, `/wp-sitemap.xml` and
`/wp-sitemap-posts-post-1.xml`, all `cf-cache-status: HIT`. Publishing shifts
every item across page boundaries, so the pages most likely to be wrong were
exactly the ones never purged.

And the probe fetches `get_permalink()` and nothing else — a **subset of the
producer's own action**. It cannot fail for an omission, however bad the
omission gets. Both statements were true, about different URLs.

I let a green readout talk me out of a person's direct observation. That is the
worst version of this failure, because the other five cost nobody anything yet.

## Every instrument I built lied, and always in the same direction

Eleven times, and I am listing them because the count is the finding:

1. a glob resolver using the repo root for patterns written relative to `tests/`
2. a variable-stripper that deleted `$inc_dir` — the very substring it filtered
   on — under-reporting 9 of 12
3. a per-suite assertion diff run against six lines of `tail` output, which
   answered "no changes" from no data
4. a mutation that silently never applied and reported zero failures
5. a PHP scanner run over five **JavaScript** repos, reporting "0 candidates"
   after scanning zero files
6. zsh aborting an `ls` because one glob in the list did not match, so
   "no vitest config" was false for three workers
7. `timeout` not existing on macOS, so a test run produced empty output
8. `grep "^FAIL"` missing a suite that indents its failures
9. `grep -c "…"` matching a function's own **declaration**, so a
   delete-mutation passed
10. the twin of #9 on the other side of the same comparison, making an ordering
    pin compare declarations instead of call sites
11. four separate assertions going red on **comments explaining the rule they
    enforce**

Not one was a bug in the thing being measured. Every one failed toward *clean*.

The corollary I keep re-learning: **a check that has never been made to fail is
not evidence.** Everything real today was found by a deliberately hostile move —
a planted violation, a fixture that should not pass, a mutation asserted to have
actually applied first.

## Three wrong attributions in a row

The installed OpenStation PWA lands on a 404 when its session lapses. I blamed,
in order:

1. an orphaned service worker — its scope is `/tools/`, it cannot touch
   `/wp-admin/`;
2. the login-guard **worker** — routed to `juanlentino.com/sn-login*`, returns
   403/429, never 404s;
3. finally, correctly, **our own** `inc/login-hide.php` decoy — which our audit
   counters have been naming all along as `wp_admin_unauth_404`.

Each correction came from reading a route or a counter, never from reasoning
harder. The third was in our own repo the whole time.

The fix is narrow: that one URL redirects to the custom login. It is defensible
because the concealment is already leaky — OpenStation's manifest is served
**unauthenticated** and names the URL in plain text.

## What the new process did well, and the defect it creates

Every fix went issue → PR → `## [Unreleased]` bullet → merge, and the release
was a separate act. The CHANGELOG gate correctly exempted docs and tests.

But rule 2 has every PR prepend its **own** `### Fixed`, in its own branch,
where the author cannot see what another open branch will add. Nothing merged
them. Three PRs produced `### Fixed` twice, and `cut-release.sh` promotes the
block verbatim — into the changelog, the archive, and the release notes.

**The process we shipped this morning created that by construction.** Fixed in
#1010 as a standalone awk program so a fixture can drive it, and ported to the
theme in #272. The first real cut through it turned three headings into two with
all five bullets intact.

## The 503s, unresolved and left that way

Fourteen assets failing `net::ERR_ABORTED 503` on the OpenStation screen.
Everything reproducible returns **200**: sequential, 14 in parallel, forced
cache MISS, multiplexed on one HTTP/2 connection, a 60-request burst, browser
UA and `Sec-Fetch-*`, login-shaped cookies, cookies to 12 KB.

Found on the way: the origin hard-fails at **~13 KB of request headers**,
surfacing as Cloudflare **520** — bisected to 13,056 B OK / 13,120 B failing,
and not cookie-specific. Filed as #1006 on its own terms. **503 is not 520**, and
folding a finding I can reproduce into a symptom I cannot would be exactly the
mistake the attribution chain above already made three times.

The missing datum is small and known: the response headers off one failing row.

## Upstream

`WordPress/openstation#762` — Post Stats' canvas chart paints its grid, axis
numbers and month labels in hardcoded black, measuring **1.01–1.03:1** on the
card glass where the shell's own token measures 9.21. Their token-contract test
already fixed this legibility class *for CSS* on this same widget; a canvas
`fillStyle` is neither a custom property nor CSS, so the same bug in a different
medium was never in scope.

My confidence check was reproducing their own published figure: computing the
reference token's contrast gave 9.21:1 against the 9.2:1 their source states.

Three of my hypotheses died before that one lived — undeclared token, stale
built CSS, a dark desktop theme. The owner's one-line observation, *"only the
data in the graph is black and the rest is white"*, is what located it. Ask for
the narrow observation before theorising from source.
