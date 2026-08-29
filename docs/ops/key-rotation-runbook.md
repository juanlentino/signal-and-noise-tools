# Runbook: rotating the provenance signing key

**For:** the day you replace the Ed25519 key that signs Notes.
**Audience:** you, or a session acting for you, with none of this context loaded.
**Status:** this has NEVER been performed. The key `sn-ed25519-2026-07` is the only one that
has ever existed. Read [What has never been tested](#what-has-never-been-tested) before starting.

---

## The one sentence that matters

**A rotation does not replace the old key. It adds a new one and keeps the old one published
forever.** Every record ever signed names its key in `pubkey_id`, and a verifier resolves that
name against the published key document. Drop the retired key and every Note it signed becomes
unverifiable at once — not wrong, *unverifiable*, which on a provenance site is worse.

Everything below exists to make that impossible to get wrong by accident.

---

## Order is the whole procedure

There is a safe direction and an unsafe one:

- **Publish the new key everywhere BEFORE the Worker signs with it.** Records keep naming the
  old id until the Worker changes, and the old id is still published, so nothing breaks.
- **Signing before publishing is the unsafe direction.** New records name a key no document
  serves; `/verify` FAILs and the sweep fires `signing_key_unpublished`.

So: ledger first, plugin second, Worker last. Never the reverse.

---

## The transaction

| # | Where | Action |
| --- | --- | --- |
| 1 | worker repo | `node scripts/gen-keypair.mjs` — keep the private half offline until step 6 |
| 2 | ledger | add `keys/<new-id>.pub`, and a row in `keys/key-history.json`; close the OLD row's window, do not remove it |
| 3 | worker | `POST /anchor-key-fingerprint` so the new key's fingerprint is itself anchored |
| 4 | plugin | append the OLD key to the `sn_prov_key_history` option (shape below) |
| 5 | plugin | set the new active key: `SN_PROV_PUBKEY_B64`, `sn_prov_pubkey_id`, `sn_prov_key_introduced_at` |
| 6 | worker | `wrangler secret put ED25519_PRIVATE_KEY`, bump `PUBKEY_ID` in `wrangler.jsonc`, deploy |
| 7 | everywhere | [verify](#verify-with-an-OLD-note) |

**Step 4 must land before step 5.** Between them the served document would otherwise carry only
the new key, and every historical Note fails for as long as that window is open.

---

## The retired-key row shape

`sn_prov_key_history` is a plain WordPress option holding a list of rows. Only three fields are
read; the rest are derived ([inc/provenance-did.php](../../inc/provenance-did.php)):

```php
array(
  array(
    'id'                => 'sn-ed25519-2026-07',
    'public_key_base64' => '+aDvAWcZA6awAX3+y76cteKbIGKyVLDjpG7rp7IVNWs=',
    'valid_from'        => '2026-07-09',
    'valid_until'       => '2027-03-01', // the rotation date
  ),
)
```

`algorithm`, `sha256_fingerprint` and `status: retired` are computed — do not supply them.

**A row whose key does not base64-decode to exactly 32 bytes is silently DROPPED**, not
published half-formed. That is the right call for the document and a trap for you: a typo in the
key bytes does not error, it produces a document missing the retired key, which is the exact
failure this runbook exists to prevent. **After step 4, fetch the document and count the keys.**

---

## Setting the values

Nothing here has an admin UI, deliberately: an admin form that can rotate the signing key is a
new privilege on the one surface whose value is that it cannot be quietly changed.

| Value | Set via |
| --- | --- |
| `sn_prov_key_history` | `wp option update sn_prov_key_history --format=json '<rows>'` |
| `sn_prov_pubkey_id` | `wp option update sn_prov_pubkey_id sn-ed25519-2027-03` |
| `sn_prov_key_introduced_at` | `wp option update sn_prov_key_introduced_at 2027-03-01` |
| `SN_PROV_PUBKEY_B64` | `wp-config.php` constant — **wins over the option** |

Constants beat options ([`sn_prov_config()`](../../inc/provenance-webhook.php)). If a constant
for the id is already defined in `wp-config.php`, the option will not take effect and the
document will keep serving the old id while you stare at a correct option value.

---

## Verify with an OLD note

**Verifying a NEW Note proves nothing.** It is signed by the new key, which you just published;
it would pass even if you had deleted every retired key. The test that matters is a Note signed
*before* the rotation.

```bash
# 1. The served document carries BOTH keys.
curl -s https://juanlentino.com/.well-known/provenance-keys.json | jq '.keys[] | {id, status}'

# 2. The ledger agrees with it, and the history verifies.
cd ~/Projects/signal-and-noise-provenance && npm run verify:keys && npm run verify:key-pins

# 3. An OLD Note still verifies end to end. This is the real test.
open https://juanlentino.com/verify   # paste a Note published before the rotation
```

Then run the Content-Health scan and confirm `provenance_integrity` reports no
`signing_key_unpublished`. That check exists precisely for this failure and covers the whole
fleet rather than the one Note you happened to try.

---

## If it goes wrong

The failure is loud and recoverable, which is why this procedure is safe to perform by hand.

**Symptom:** `/verify` says a Note is signed by a key no published document lists, or the sweep
reports `signing_key_unpublished`.

**Cause, almost always:** the retired key is missing from the served document — a dropped row
(bad base64, see above), or step 5 landed before step 4.

**Fix:** re-add the retired key to `sn_prov_key_history` and purge the cache. Verification
recovers immediately; nothing on the ledger was damaged, because a rotation never edits or
removes a record.

**Nothing needs to be re-signed.** Old records stay valid under their old key forever. If you
find yourself about to re-sign historical records to "fix" a rotation, stop — that rewrites
history to paper over a publishing mistake.

---

## What has never been tested

Written 2026-08-29, after an audit found the rotation path had **no producer**: nothing writes
`sn_prov_key_history` or `sn_prov_next_key_commitment`, and no code performs a rotation.

- **No rotation has ever been performed.** This document is derived from reading the code, not
  from doing it. Treat every step as a hypothesis.
- **`next_key_commitment` is unused.** The plugin can publish a commitment to the successor key
  and `tests/provenance-key-history.php` states that a rotation revealing a key which does not
  hash to the prior commitment must be REJECTED — but nothing implements that rejection, and the
  live document publishes no commitment at all. Do not rely on it to catch a wrong key.
- **`keyPinDivergences()` does not compare the commitment**, so even a published one is not
  cross-checked against the ledger.
- **The two halves are synced by hand.** The plugin's `sn_prov_pubkey_id` and the Worker's
  `PUBKEY_ID` must match; nothing enforces it at write time. The ledger's `verify:key-pins`
  catches a mismatch on the next CI run — after the fact, not before.

**When this is performed for the first time, write the script from what actually happened** and
replace the table above with it. A tool written from this document would be a tool written from
a guess.
