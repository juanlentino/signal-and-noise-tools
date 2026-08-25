# Context7 audit, part 2 — the rest of the estate (2026-08-25)

Extends [context7-audit-sn-provenance-2026-08-25.md](context7-audit-sn-provenance-2026-08-25.md)
to everything that audit did not reach: the four remaining workers and the
PHP surfaces (plugin + theme). Same method — npm registry evidence, code
reading, verdict per hand-roll: **load-bearing choice** vs **merely older
than the package that replaced it**. Registry read 2026-08-25.

## Verdicts at a glance

| Surface | Hand-roll | Candidate package | Verdict |
| --- | --- | --- | --- |
| sn-rights-signals | `html-to-markdown.mjs` (275) | `turndown` 7.2.4 | **Keep** — the package proves the file's own argument |
| sn-login-guard | CIDR ranges + binary search (`index.js`) | `ipaddr.js` 2.5.0 | **Keep** — the structure is the value, parsing is incidental |
| analytics worker | JS-capable-bot filter · `salt.js` | `isbot` 5.2.1 · (none) | **Keep** — wrong population · no package does salt lifecycle |
| sn-remote-mcp | `mcp.mjs` protocol layer (187) | `@modelcontextprotocol/sdk` 1.30.0 | **Keep, emphatically** — the SDK has 17 runtime deps incl. express/hono |
| sn-remote-mcp | `access.mjs` JWT verify (152) | `jose` 6.2.10 | **Keep, with a named fallback** |
| plugin (PHP) | `sn_prov_ksort_recursive` canonical JSON | (none bundleable) | **Keep** — the PHP half of the signature contract |
| plugin (PHP) | `wp-update-integration.php` updater (692) | `plugin-update-checker` | **Keep** — git-preservation is a capability PUC lacks |

Every verdict is keep. As with part 1, that is not reflexive: two adoption
decisions in this estate went the other way when the evidence said so
(`web-bot-auth` + `jsonwebkey-thumbprint` in rights-signals v1.19.0 —
signature-base construction IS the security property there).

## The workers

**rights-signals `html-to-markdown.mjs`.** The file's own header claims
"every JS html→markdown library assumes a DOM." The registry confirms it:
`turndown` 7.2.4 depends on `@mixmark-io/domino` — a bundled DOM
implementation — while the hand-roll rides workerd-native HTMLRewriter and
streams. The module also carries a documented deletion path (Cloudflare's
Markdown for Agents, the day the zone's plan includes it), which is better
than a package: it plans its own obsolescence. The partial fidelity
(tables flatten to text) is a stated floor for an LLM-reader audience.

**login-guard CIDR matching.** ~40 lines: FireHOL v4 CIDRs → uint32
ranges → sort → merge overlaps → binary search on the hot path, with the
merge existing precisely because the search assumes disjoint ranges.
`ipaddr.js` is healthy (2.5.0, zero-dep) but offers per-address
`match()` — adopting it replaces only the parsing lines and you keep the
sorted-merged structure regardless, because the structure IS the design
(3,914 ranges per request). The v4-only scope is not a gap: extending to
128-bit ranges is exactly what the IPv6 criterion gates, deliberately.

**analytics bot filter.** `isbot` classifies the general crawler-UA
population; this filter deliberately targets the OPPOSITE population —
JS-capable agents that actually execute the beacon (headless browsers,
automation, synthetic monitors), because HTTP-fetcher crawlers never fire
it. Adopting a general classifier here is the population-bound trap in
package form. The BOT_UA/DC_ASN fingerprint-parity guard (v1.17.0) also
pins these patterns to a mirror the package could not honor. `salt.js` is
a KV salt *lifecycle* (48h forward-secrecy TTL, midnight rotation,
outage fallback chain) — no package does this; the crypto is WebCrypto.

**remote-mcp `mcp.mjs`.** The strongest keep in the estate. The official
`@modelcontextprotocol/sdk` 1.30.0 carries **17 runtime dependencies —
`express`, `hono`, `cors`, `ajv`, `zod`, `jose` among them** — to provide
transports, sessions, and streaming that this server's design explicitly
rejects (stateless by documented choice, spec-cited, satisfying both the
2025-11-25 and 2026-07-28 revisions by never issuing a session). 187
hand-rolled lines versus a dependency tree that would fight the design.

**remote-mcp `access.mjs`.** The one place a package genuinely competes:
`jose` 6.2.10 is canonical, zero-dep, workerd-clean. Read line by line,
the hand-roll already does everything jose would: algorithm pinned
server-side (the confusion attack is called out in a comment), kid+kty
matched against the team JWKS, signature via WebCrypto, `exp`/`nbf`/
`aud`/`iss` all validated, every path fails closed — and it handles the
service-token `common_name` nuance Cloudflare doesn't document, which
jose would not do for you. It matches Cloudflare's own published
validation pattern for exactly one issuer shape. **Fallback condition,
on the record:** if this surface ever widens — a second issuer, a second
algorithm, key types beyond RSA — adopt `jose` at that moment rather
than growing the hand-roll.

## The PHP surfaces

A structural difference first: the plugin and theme ship through the WP
updater with no Composer runtime, so "adopt the package" means *bundling
vendored code into the distribution* — a different and higher bar than
`npm install` in a worker.

**`sn_prov_ksort_recursive` + `wp_json_encode`**
([inc/provenance-core.php](../../inc/provenance-core.php)) — the PHP half
of the canonicalization contract whose JS half part 1 already ruled on.
Same verdict for the same reason: the worker asserts byte-parity against
this function's output, and every published signature covers its bytes.
The two implementations ARE the specification; a third would be a drift
surface.

**The updater** ([inc/wp-update-integration.php](../../inc/wp-update-integration.php),
692 lines + git-preservation). The canonical package,
`yahnis-elsts/plugin-update-checker`, is mature — but this updater is
tag-driven off this repo's own release ritual, has its own test suites in
the sweep, and pairs with `wp-update-git-preservation.php`, a behavior
PUC does not have and this estate's install flow depends on (the plugin
directory on the server IS a git checkout). Swapping would be a lateral
move requiring re-verification of the entire update path for zero new
capability, on the most load-bearing owner-ruled surface in the repo
("NEVER skip the WP updater").

**Not audited item-by-item, by policy:** the remaining PHP is WP-API glue
and domain logic with no package-shaped seams; the standing practice
(Context7 for third-party surfaces, grep for ours) already governs it.

## Conclusion

The estate-wide result matches part 1: every hand-roll is a security
property, a population-bound classifier, a compatibility contract, or
platform glue — and in the two places where a package truly was the
security property, the estate already adopted it. One dated fallback
condition (jose, if the Access surface widens) and one dated re-check
(`@otskit`, ~2027-03) are the audit's only future obligations.
