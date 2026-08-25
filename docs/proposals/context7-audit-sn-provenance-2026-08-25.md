# Context7 audit — sn-provenance hand-rolled modules (2026-08-25)

The deferred audit from the 2026-08-23 session: check `sn-provenance`'s
hand-rolled code against maintained packages. The deliverable is a verdict per
module — **load-bearing choice** (the hand-roll is doing something a package
would not) versus **merely older than the package that replaced it** (adopt the
package). Evidence is the npm registry read on 2026-08-25 plus the JCS
reference implementation via Context7.

The worker's posture going in: **zero runtime dependencies**, deliberate.
Every module below runs on Web Crypto + `fetch` only.

## Verdicts

| Module | Lines | Candidate package | Verdict |
| --- | --- | --- | --- |
| `src/ots.mjs` | 297 | `opentimestamps` (npm) · `@otskit/*` · `@vitrified/typescript-opentimestamps` | **Load-bearing — keep** |
| `src/canonical-json.mjs` | 13 | `canonicalize` v4.0.0 · `json-canonicalize` v3.0.0 | **Load-bearing — keep** |
| `src/crypto.mjs` | 38 | `@noble/ed25519` v3.1.0 | **Not a hand-roll — keep** |
| `src/bounded-body.mjs` | 39 | (none) | **Keep** — no package does this |

No module is "merely older than its package." One latent defect was found in
passing (§5) — it is a bounds disagreement, not a package question. **Resolved
same day** in sn-provenance-worker v1.12.2.

## 1. `ots.mjs` — the OpenTimestamps client

The obvious package, `opentimestamps` (the official JS port), is **dead**:
last published 2021-01-29, and its dependency list is a museum —
`request` (deprecated 2020), `bytebuffer`, `bitcore-lib`, `moment-timezone`,
and literally `fs` (the npm placeholder package). It is Node-only; it would
not run in workerd at all. Adopting it is not an option, so the 2026-08-23
zero-dep decision stands on the merits, not just on posture.

Three successors have appeared, all young:

- `@otskit/core` 0.2.0 + `@otskit/client` 0.7.1 — first published
  **2026-06-03**, single maintainer (`alexalves87`), ~2k downloads/month,
  self-described "official" though the GitHub org (`OTSkit`) is not the
  OpenTimestamps org.
- `@vitrified/typescript-opentimestamps` 0.2.0 — single release 2026-05-21,
  depends on `@noble/hashes`.

None is replacement-grade against what the hand-roll specifically provides,
each of which is a security or correctness property a general client does not
carry:

- **Calendar allowlist on the upgrade path** (`isAllowedCalendar`): calendar
  URIs are parsed out of *stored proof bytes*, so a corrupted or poisoned
  proof can never steer an outbound fetch. A general client dials whatever
  URI the proof names.
- **Bounded response reads** (`readBytesBounded`, 1 MB cap) and a 10 s
  `AbortSignal.timeout` on every calendar call — a hanging calendar cannot
  stall a publish.
- **`spliceUpgrade` is deliberately narrow**: sole-terminal single-calendar
  proofs only, byte-identical to python-opentimestamps' `Timestamp.merge`,
  validated against a live Bitcoin-confirmed proof
  (`test/ots-upgrade.test.mjs`). Branched proofs are rejected loudly rather
  than mis-spliced. A general merge is exactly the code we do not want to
  own — and the published ledger contains proofs whose bytes this code
  produced, so byte-exact behavior is a compatibility contract, not a style
  preference.

**Re-check condition:** if `@otskit` reaches 1.x with multiple maintainers and
real adoption (revisit ~2027-03), it becomes worth a comparison run — as a
*verifier* to cross-check our proofs, not as a replacement for the stamping
path.

## 2. `canonical-json.mjs` — RFC 8785 in 13 lines

The maintained packages (`canonicalize` v4.0.0, `json-canonicalize` v3.0.0,
both zero-dep, both published August 2026) implement the same algorithm the
hand-roll does: recursive key sort by UTF-16 code units + native
`JSON.stringify` for primitives. Confirmed against the JCS reference
implementation (cyberphone/json-canonicalization): the hand-roll's explicit
comparator (`a < b ? -1 : …`) is exactly default string sort, which is exactly
JCS ordering.

The one behavioral divergence from RFC 8785: objects with `toJSON` (e.g.
`Date`) — JCS serializes via `toJSON`, the hand-roll would recurse into their
(empty) own keys and emit `{}`. **Unreachable by construction**: every input
is `JSON.parse` output (`index.mjs:112-113`, ledger entries, hardening
paths), and parsed JSON cannot contain a `toJSON` object.

Why this is load-bearing rather than swappable-for-identical-bytes:

- The canonical form is a **cross-language contract** — WordPress computes
  `msg.canonical` in PHP and the worker asserts
  `canonicalize(payload) === msg.canonical` (`index.mjs:114`). Both sides of
  that contract are owned code; introducing a third implementation adds a
  drift surface for zero functional gain.
- Every published signature and `content_hash` in the ledger covers bytes
  this exact function produced. The 13 lines are the specification.

## 3. `crypto.mjs` — not a hand-roll at all

38 lines of glue over `crypto.subtle`: HMAC verify (via `subtle.verify`, so
constant-time), Ed25519 sign/verify (platform-native in workerd), hex/base64
codecs. `@noble/ed25519` is excellent and maintained, but adopting it would
*replace platform crypto with userland JS* — strictly more supply-chain
surface for the same primitive. The right amount of code here is the glue.

`ed25519Verify`'s swallow-everything `catch { return false }` is a documented
decision in the file (a verifier that throws forces every call site to invent
its own exception policy) and matches how the verify endpoint consumes it.

## 4. `bounded-body.mjs`

Size-capped response reading. There is no package for this because it is
three ideas long; it exists so `ots.mjs` and the webhook path share one
bound. Keep.

## 5. Finding in passing: `toB64` vs the 1 MB calendar bound — RESOLVED

> **Resolved 2026-08-25** in sn-provenance-worker
> [v1.12.2](https://github.com/juanlentino/sn-provenance-worker/releases/tag/v1.12.2)
> ([PR #23](https://github.com/juanlentino/sn-provenance-worker/pull/23)):
> `toB64` now builds the binary string in 32 KB slices before a single `btoa`,
> and a test round-trips a 1 MB `Uint8Array` through `toB64`/`fromB64` so the
> encoder's bound can never again fall below the calendar cap. The chunking
> option was chosen over lowering `MAX_CALENDAR_BODY_BYTES` — the encoder now
> meets the bound the calendar path already promises, rather than shrinking
> that promise around the encoder's defect. The original finding follows.

`toB64 = (u8) => btoa(String.fromCharCode(...u8))` spreads the whole array
as arguments. Engines cap argument spreads around ~64–125k elements, so
`toB64` throws `RangeError` somewhere above ~100 KB — while
`MAX_CALENDAR_BODY_BYTES` admits calendar subtrees up to **1 MB** into
`upgradeOts` → `spliceUpgrade` → `toB64(next)` (`sweep.mjs:121`). Real
Bitcoin-path subtrees are a few hundred bytes to a few KB, so this has never
fired — but the two bounds disagree, and the failure would be an unhandled
throw inside the sweep, not a clean rejection.

Fix when convenient (its own small release): chunk the `fromCharCode` loop
(~3 lines) or lower the calendar bound to something a proof can actually be
(64 KB is generous). Not urgent; noted so the disagreement is on record.

## Conclusion

The zero-runtime-dependency posture survives the audit intact. Every
hand-roll is either a security property (`ots.mjs` allowlist/bounds/narrow
splice), a compatibility contract (`canonical-json.mjs`), or platform glue
(`crypto.mjs`). The only package that could have claimed a module —
`opentimestamps` — turned out to be four years dead and Node-bound. The
young `@otskit` family is the one thing worth a calendar entry, as a future
cross-check verifier, around 2027-03.
