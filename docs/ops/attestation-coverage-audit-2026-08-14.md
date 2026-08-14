# Attestation-coverage audit — 2026-08-14

The one-time audit the **Dependency provenance gate** board row declares it
lands after: does enough of the workers' locked dependency trees publish
registry provenance attestations for a verify-before-deploy check to mean
anything? This is the R6 gate spike named in
[roadmap-release-sequence.md](../roadmap-release-sequence.md) (R0, third
prerequisite). **Verdict: yes, the gate is meaningful — with two conditions
recorded below.**

## Method

Every locked `package@version` across the five worker repos (all
`package-lock.json`, lockfileVersion 3) was probed against npm's attestation
endpoint (`/-/npm/v1/attestations/<pkg>@<ver>`): HTTP 200 → attested (kinds
parsed from `predicateType`), 404 → none. Zero probe errors across 354 pairs.

The instrument was negative-controlled before trusting it:
`lodash@4.17.21` (pre-attestation era) → none; `sigstore@2.3.1` → attested
with both `https://slsa.dev/provenance/v1` and npm's publish attestation.
Both controls answered as required.

## Numbers

| Worker | Locked pkgs | Attested | Coverage |
|---|---|---|---|
| sn-analytics | 161 | 114 | 70.8% |
| sn-login-guard | 161 | 114 | 70.8% |
| sn-provenance | 161 | 122 | 75.8% |
| sn-remote-mcp | 243 | 156 | 64.2% |
| sn-rights-signals | 213 | 127 | 59.6% |
| **Union (unique pairs)** | **354** | **246** | **69.5%** |

## The two structural findings

**1. The tree is toolchain, all the way down.** All five workers declare
**zero runtime dependencies** — every locked package descends from
`wrangler`, `vitest`, or `@cloudflare/vitest-pool-workers` in
`devDependencies`. The shipped bundles import nothing. So this gate protects
the **deploy machine and the bundle step** (the supply-chain layer the
security stack memo already names as the real self-hosted-runner exposure),
not the deployed code's own imports. That is still worth gating — the
compiler (`esbuild`) and the local runtime (`workerd`, `miniflare`) are
exactly where a poisoned release does the most damage — but the gate's threat
model sentence should say "toolchain", not "runtime deps".

**2. The gap is two-thirds staleness, one-third refusal.** Of the 108
unattested pairs (99 unique names), the latest version of **41 names attests
today** — including the entire `@esbuild/*` platform family (25 shims, all at
the stale 0.25.4), the five `@cloudflare/workerd-*` binaries, plus `esbuild`,
`workerd`, `zod`, `cookie`, `tinypool`. The four lockfiles frozen around
Oct 2025 predate those projects turning provenance on. A routine toolchain
update raises union coverage from 69.5% to an estimated **low-to-mid 80s**
before any policy work happens.

The remaining **58 names attest nowhere, even at latest**: the sourcemap
cluster (`@jridgewell/*`, `source-map-js`, `convert-source-map`), `@types/*`,
`debug`/`ms`/`supports-color`, `lightningcss` + its 11 platform shims,
`acorn`, `ws`, `tslib`, `fsevents`, and kin — small, stable, widely-pinned
utilities. As an allowlist this is enumerable, slow-moving, and dominated by
two families. Full list in the audit's raw output (session artifact; the
names above are the load-bearing ones).

## What this licenses the gate to be

1. **Verify what attests, allowlist what cannot, alarm on allowlist growth.**
   `npm audit signatures` already verifies registry signatures for all 354
   pairs **and** provenance attestations wherever they exist — the vehicle is
   stock npm, no bespoke verifier. The gate fails a deploy when a package that
   should attest doesn't, or when the never-attests allowlist gains a name
   nobody reviewed.
2. **The minimum-age cooldown is independent of attestation coverage.**
   Publish timestamps come from the packument for 100% of packages, attested
   or not. The cooldown leg of the board row works at full coverage today.

## Conditions attached to the verdict

- **Refresh the four stale toolchains first** (sn-analytics, sn-login-guard,
  sn-provenance share one Oct-2025 lockfile vintage; sn-remote-mcp is
  partially stale). Gating on 69.5% coverage would put a third of the tree
  on the allowlist for no reason; after the refresh the allowlist is the
  irreducible 58.
- **Pin the allowlist by name, not by count**, and treat any addition as a
  reviewed event — an allowlist reused as a silent pass-through is the
  inversion trap the memory index already documents.

One-time audit; not scheduled to recur. The gate itself, when it ships in R6,
re-derives coverage on every deploy — this document only had to establish
that the number is high enough to build on.
