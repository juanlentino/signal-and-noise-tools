# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **Sampled edge figures were counted twice over.** Cloudflare returns a grouped adaptive `count` (and every `sum`) already scaled up to its estimate ("Cloudflare will estimate 50,000 total events (5,000 × 10) and report this value", analytics/graphql-api/sampling). `sn_edge_corrected()` multiplied it by `sampleInterval` again, so every figure built from sampled groups was inflated: threats, edge locations and their bytes, every attack-surface panel, and the new 5xx reading. The inflation was measured, not guessed: the week's 5xx rows read about seven times the zone's exact total. Grouped counts and sums are now taken as reported. Raw, ungrouped events still weigh by their `sampleInterval`, because a raw row does stand for that many events.
- **The inflated history is repaired where it can be.** A one-shot cron job re-rolls every day Cloudflare's sampled dataset still retains, and clears each day's rows for the dims it re-fetched, so a value that fell out of the top list doesn't keep its old count. Days older than the retention can't be recomputed, so they stay as stored, and `sn_edge_honest_from` (also in `cloudflare-status` → `errors_5xx.honest_from`) records where the honest counts begin.
- Two test stubs of the corrector multiplied too, so their suites asserted the double count. Both now use the real behaviour, and every figure they pinned is restated as Cloudflare reports it.

## [17.9.0] - 2026-09-23 — kit tables and facts (needs OpenStation 1.1.11)

### Changed
- **Facts lists are the kit's `<os-facts>` (OpenStation 1.1.11 is now the floor).** `snt_kit_kv()`, the helper behind every label/value readout in the native window, painted its own `<dl class="snt-kv">` because the kit had nothing of that shape. Upstream #889 shipped `<os-facts>` / `<os-fact>` in OpenStation 1.1.11, so the helper paints those. Each row is `<os-fact label>` with its value in a `.snt-kv__v` span, which keeps the tone colour, the inline-code wrap and the provenance rules on the same hook, and the list is still a real `<dl>` for screen readers. Only the house rhythm (16px column gap, 12px labels) stays in `assets/os-app.css`. **1.1.11 is a floor, not an option**: on an older station every facts row would lose its label silently. `docs/openstation-compat.md` is re-verified at the v1.1.11 tag (42 names, clean). The 53 assertions across ten leaf suites that pinned the old markup now pin the new shape, and all 53 fail against the old helper.
- **The S&N Home refresh button is named by its `aria-label` alone.** `os-button` forwards a host `aria-label` to its inner button since OpenStation 1.1.11 (#857), so the hidden slotted copy of the name and the `.snt-sr-only` rule it needed are gone.
- **Four leaves are real tables (#1624).** Monitoring › Health findings, Content › Block Migrations, the Content › Tags merge picker and Tools › Citations each painted a table by hand, as an `<os-row>` grid or one `<os-card>` per row, because every row carries a control (Suggest, Dismiss, a radio, a link, a pill) and `<os-table>` took only strings from a server view. OpenStation 1.1.11's slot cells (upstream #874) lift that, so all four are `<os-table>`s now: sortable, with a real header, and a card per row on a phone.
  - `snt_kit_table()` takes a cell as `[ 'html' => ..., 'text' => ... ]`. The markup rides as a light-DOM slot child, so the runtime's delegation and the classic scripts still reach it, and `text` is what the column sorts on. Citations' two dates show "3 days ago" but sort by timestamp.
  - A row's `_key` names its slots by identity, so a morph follows a row that a dismissal moved; a repeated key still gets distinct slots.
  - Phones: `stack_on_phone` marks a table, and the new `assets/os-kit-stack.js` stacks it under the shell's phone stamp, the same rule OpenStation's own `stackOnPhone()` applies to its list windows. It re-applies after every morph, which strips attributes the server did not paint.
  - `health-suggest-actions.js` treats the slot cell as the cell. Without that, Health's Suggest button logged "no action cell" and did nothing, which the markup tests could not see and a new browser test (`tests/js/leaf-tables.mjs`, against the real v1.1.11 `<os-table>`) catches; a Dismiss inside a table marks its cell Dismissed until the repaint drops the row.
  - The hand-rolled header rows are gone, and so is the 640px rule that hid them. The `os-row` collapse stays for the four leaves whose forms still use it.

