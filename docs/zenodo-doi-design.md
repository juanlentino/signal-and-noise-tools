# Zenodo DOIs for the research track (design, 2026-09-17)

## One sentence

Every document the site itself publishes on the provenance track (the three
pillar essays, every note) gets a DOI minted on Zenodo, with the signed provenance
record and the Bitcoin proof filed beside the text, and the DOI flows back
into the schema, the citation tool, the site map and the byline.

## Why (from the pressure test)

The site's technical layer is done: titles, descriptions, Article schema with
ORCID, `llms.txt`, rights signals, machine-readable citations. What it lacks
to a scholar, a citation manager or a citing model is a persistent identifier.
Scholar, Semantic Scholar, Crossref-aware tools and every answer engine that
cites resolve DOIs; a personal site without them is a blog to them. Zenodo
(CERN, free, DataCite DOIs) is the documented route for a researcher without
a publisher.

## Decisions (owner, 2026-09-17: "whatever fits what I do")

- **License on every record: CC BY-ND 4.0.** Attribution, no derivatives.
  Notes are never edited after publication and a paper is the record of an
  argument; ND keeps the text whole and restricts no citation. Not NC:
  commercial reuse is not the risk, and NC would complicate citation in
  commercial contexts.
- **`upload_type: publication`, `publication_type: other`** for notes and
  pillar essays (Zenodo's vocabulary has nothing truer; they are not
  preprints).
- **The papers stay with SSRN.** Owner, 2026-09-17: SSRN handles the papers'
  license and record; the plugin does not deposit them. Their SSRN URLs are
  already in the site map and the Person schema.
- **One record per document, one version per provenance version.** A note is
  evergreen: one DOI, no versions. A pillar essay that changes gets a new
  version under the same concept DOI (`actions/newversion`).
- **Record title = the document's H1** (the aphorism). The search title is a
  title-tag concern; a citation names the work.
- **Deposit AFTER the anchor confirms**, not at publish: Zenodo files are
  immutable once published, and the honest bundle carries the Bitcoin-
  confirmed `.ots`, not a pending one. The provenance sweep's WP callback is
  the trigger (`sn_prov_confirmed`, new action in 15.11.0). A note publishes,
  anchors within hours, then gets its DOI.
- **Sandbox first.** `sandbox.zenodo.org` with its own token; a setting flips
  production. Sandbox DOIs carry the `10.5072` prefix and never flow into the
  public surfaces.

## The record

```
upload_type          publication
publication_type     other
title                <H1>
description          <excerpt, HTML allowed>
creators             [{ name: "Lentino, Juan", orcid: "0009-0006-8151-5920" }]
publication_date     <post date, YYYY-MM-DD>
language             eng
license              cc-by-nd-4.0
keywords             <tag names>
version              v<provenance version>
access_right         open
related_identifiers  [{ identifier: <canonical URL>, relation: isIdenticalTo, resource_type: publication-other },
                      { identifier: <pillar URL>,    relation: isPartOf }]          (notes with a pillar)
notes                "Signed at publication (Ed25519, key <pubkey_id>) and anchored on Bitcoin
                      through OpenTimestamps (block <n>). Verify at https://juanlentino.com/verify."
files                <slug>.md            the note as Markdown (the site's own text/markdown answer)
                     <slug>.provenance.json   the signed ledger record
                     <slug>.ots           the Bitcoin-confirmed proof
```

## Components (plugin 15.11.0)

1. **Keyring**: `zenodo_token` and `zenodo_sandbox_token` (issued, secret,
   scopes `deposit:write` + `deposit:actions`); probe
   `GET /api/deposit/depositions?size=1` names the environment answering.
2. **`inc/zenodo-client.php`**: base URL by environment, bearer request
   helper (10 s timeout, never follows redirects), create deposition, upload
   to the bucket, set metadata, publish, new version. Every response is
   returned as `{ok, code, body}`; nothing throws.
3. **`inc/zenodo-records.php`**: `sn_zenodo_metadata_for( $post )` PURE from
   plain inputs; `sn_zenodo_bundle_for( $post )` (Markdown via the site's own
   negotiation, ledger record and proof from the public ledger repo);
   `sn_zenodo_deposit( $post_id )` runs the flow and writes
   `_sn_zenodo_doi`, `_sn_zenodo_concept_doi`, `_sn_zenodo_record_id`,
   `_sn_zenodo_env`, `_sn_zenodo_deposited_at`; a failure writes
   `_sn_zenodo_last_error` and leaves the post for the next pass.
4. **Triggers**: `sn_prov_confirmed` schedules a single event
   `sn_zenodo_deposit_one` (readiness-gated: token present, environment
   set); a backfill cron pass deposits up to 5 confirmed documents per run
   until none remain (well inside 100 requests a minute).
5. **Flow-back**: Article `identifier` gains a DOI `PropertyValue` and
   `sameAs` the `doi.org` URL; `get-citation` BibTeX and CSL carry `doi`;
   `/notes/index.json` and `llms-full.txt` carry it; the byline shows
   "DOI 10.5281/zenodo.N" (theme, one line).
6. **Health check 29 `zenodo_doi`**: confirmed documents without a
   production DOI. Excludes pending anchors and sandbox-only DOIs. A defect
   that reaches zero and stays there.
7. **Abilities**: `zenodo-status` (readonly: environment, token verdict,
   counts, the missing list) and `zenodo-deposit` (write door, owner-held:
   deposit one post or the next batch; dry-run default).
8. **Connections › Zenodo leaf**: the keyring rows, the environment switch,
   the ledger (document, state, DOI, deposited at), one action.

## Failure modes

- Token missing or refused: the probe says so; deposits are not attempted;
  the health check reports the count as SKIPPED with the reason, never as a
  pass.
- A deposition created but not published (network failure mid-flow): the
  record id is stored as `_sn_zenodo_draft_id`; the next pass resumes from
  it (`GET /deposit/depositions/{id}`) rather than minting a duplicate.
- Zenodo 429: back off, leave the post for the next pass; the cron never
  loops inside a run.
- Sandbox token used in production or vice versa: the probe names the
  environment that answered; the leaf paints both verdicts.

## Testing

Pure metadata builder (every field, the pillar relation, the notes line); client request shaping against a recorded fixture
(headers, no redirects, bucket upload PUT); the deposit flow over a fake
transport (create → upload ×3 → metadata → publish, and the resume path);
the schema, citation and site-map flow-back with and without a DOI; the
health check (missing, pending-anchor exclusion, sandbox exclusion, skipped
when no token); the keyring probe verdicts.

## Out of scope

Zenodo communities, Crossref, ORCID work-list pushes (ORCID auto-adds
DataCite DOIs with the author's ORCID), Google Scholar profile management.
