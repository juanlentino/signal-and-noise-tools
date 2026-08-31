# Breached-credential rejection — the one auth surface neither layer can see

> Origin: a GitHub survey for login-guard hardening (2026-08-31). Almost everything the
> search returned, the estate already does one layer up and better. This is the exception.

**Goal:** stop a known-breached password from being *set*, and surface one already in use,
without adding a third-party dependency to the login path or letting an outage read as a pass.

---

## Why this is not covered today

`grep -rilE 'pwned|password_strength|user_profile_update_errors|validate_password' inc`
returns **nothing**. There is no password policy in the plugin, and WordPress core does not
check passwords against any breach corpus.

The two existing layers cannot reach it, and not by oversight:

| Layer | Defends | Blind to |
|---|---|---|
| Login-guard Worker (edge) | the *attempt* — rate, ASN, country, family | a correct password, residential IP, human rate |
| `two-factor` + WebAuthn provider | a stolen password being *sufficient* | a breached password being *set* |

Both instruments watch the attempt. Neither watches the secret. A credential reused from a
breach corpus, presented once, from a normal IP, at normal speed, is indistinguishable from
the owner at every layer we currently operate.

---

## The mechanism

HIBP Pwned Passwords **k-anonymity**: send the first five hex characters of the password's
SHA-1, receive ~800 suffixes, match locally. **The password never leaves the origin.** Worth
stating explicitly, because "check the password against a third party" is disqualifying and
this is not that.

| Repo | Licence | Stars | Role |
|---|---|---|---|
| `HaveIBeenPwned/PwnedPasswordsCloudflareWorker` | BSD-3-Clause | 412 | first-party reference implementation |
| `mxrxdxn/pwned-passwords` | MIT | 33 | PHP client, read for shape |
| `ItinerisLtd/disallow-pwned-passwords` | GPL-2.0 | 28 | WP hook placement — **last pushed 2019-02-20**, read only |

No dependency is adopted. `wp_remote_get()` plus `sha1()` is the whole client; the value in
the repos above is the hook placement and the response-parsing edge cases.

---

## A measurement that reversed the design

The first draft of this plan preferred `PwnedPasswordsDownloader` (BSD-3-Clause, 1,292 stars)
to avoid a runtime dependency in the auth path. **That was wrong, and the number that killed
it was never looked up.** Measured directly against the live API on 2026-08-31 — eight ranges
sampled across the keyspace:

```
avg range = 80,274 bytes over 8 samples
full SHA-1 corpus ≈ 84.2 GB uncompressed, across 1,048,576 files
```

84 GB and a million files is not going on managed hosting, and no amount of ETag-based
incremental refresh changes the floor. **Live k-anonymity is the only viable path.**

The reversal improves the design rather than compromising it. The offline corpus was wanted
to dodge a fail-open/fail-closed dilemma; measuring the *event rate* dissolves the same
dilemma more cheaply. A single-author site sets a password a handful of times a year. At that
frequency **fail-closed is affordable** — "the breach check is unavailable, try again in a
minute" is an acceptable answer to something that happens twice a year, and it is the safe
direction. The offline corpus was solving a problem the traffic pattern does not have.

---

## Two modes, because the hooks answer different questions

Plaintext exists in only three places: registration, password-set, and login submit. The
stored value is a hash, so an *already-set* password can never be checked at rest.

### Mode A — set-time, blocking, fail-CLOSED

Hooks: `user_profile_update_errors`, `validate_password_reset`, `registration_errors`.

Rare, user-initiated, already-interactive. A network failure returns a `WP_Error` and the
password is not set. **UNAVAILABLE must never render as "not breached"** — the standing rule,
and the whole point of choosing fail-closed at a hook that fires twice a year.

### Mode B — login-time, advisory, fail-OPEN, memoized

Hook: successful authentication only.

Mode A cannot see a password set before this ships, which on day one is every password on the
site. Mode B closes that, and its constraints are the inverse of A's: frequent, latency-
sensitive, and must never lock anyone out. So it warns, never blocks, and a failed lookup is
silently dropped.

**Memoize against the stored hash.** Write a user-meta flag keyed to a short digest of the
value already in `user_pass`, so the check runs at most once per password rather than once per
login, and self-invalidates when the password changes. Keying on the stored hash stores nothing
that is not already in the database — do not key on, derive from, or persist anything computed
from the plaintext.

**Never log, cache, or transmit the plaintext or its full SHA-1.** Only the 5-character prefix
leaves the origin; the 35-character suffix is compared in memory and discarded.

---

## Phases

| Phase | Ships | Risk | Gated on |
|---|---|---|---|
| 0 | The client + its offline fixture suite | Low | nothing |
| 1 | Mode A (set-time, fail-closed) | Low — rare hook, safe direction | Phase 0 |
| 2 | Mode B (login-time, advisory, memoized) | Medium — touches the login path | Phase 1 merged |
| 3 | Admin surface + security-digest row | Low | Phase 2 |

### Phase 0 — the client

`sha1()` → uppercase → split 5/35 → `wp_remote_get` → parse `SUFFIX:COUNT` lines → match.

Fixtures drive **parsing**, never the network. Pin: a known-breached password's real prefix
response (captured once, stored as a fixture), a clean prefix, a malformed body, an HTTP 429,
a timeout, and an empty 200. **The empty-200 case is the one that matters** — an empty body and
"no match" are byte-identical outcomes with opposite meanings, and the client must return
UNAVAILABLE for the first and NOT-BREACHED for the second.

**Negative-control it.** Feed the fixture for a password known to be in the corpus and confirm
Mode A goes red. A breach check that passes against a breached password is worse than none.

### Phase 3 — the surface

One `security-digest.php` row — count of accounts whose current password is flagged by Mode B —
and a Site Health check. Report a Mode A fail-closed rejection rate too: if it is non-zero and
climbing, the API is degrading and the site is quietly refusing legitimate password changes.

---

## What this deliberately does NOT do

**Application passwords.** Generated, 24 characters, never in a breach corpus. Out of scope.

**Rate-limiting, slug-hiding, country blocking.** Already at the edge, which is strictly better
than the origin — it blocks before the request reaches Cloudways.

**fail2ban / nginx bad-bot blockers.** Same reason. `mitchellkrogza/nginx-ultimate-bad-bot-blocker`
is the loudest result in the survey (4,783 stars) and is excluded twice over: `NOASSERTION`
licence, and it duplicates the edge at the origin.

**File-integrity monitoring.** Already available — WP-CLI ships `wp core verify-checksums` and
`wp plugin verify-checksums`. Nothing to build or adopt.

**2FA enforcement per role.** A real gap the `two-factor` plugin handles weakly, but the best
repo found had one star. Roughly twenty lines of our own against `two_factor_providers` and a
`wp_login` redirect — a separate, smaller proposal, not a dependency.

---

## Appendix — survey caveats

**Four** `gh search repos` queries returned empty for subjects that demonstrably exist —
`"pwned passwords wordpress"` found nothing while `"pwned password"` immediately returned
HIBP's own organisation. Every empty result was re-run with different phrasing before being
treated as an absence; the same artifact appeared in the 2026-08-31 analytics survey. Assume it
recurs.

**Two axes remain unresolved, not clear:** WordPress session / concurrent-login management, and
application-password auditing. Re-run before concluding anything about either.
