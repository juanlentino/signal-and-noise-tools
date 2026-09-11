# Search served by the kernel — design (2026-09-11)

**Board row:** Machine learning → *Search served by the kernel: the notes search
box ranked by the same tf-idf mathematics that picks related notes.*
**Repos:** plugin (`signal-and-noise-tools`) + theme (`signal-and-noise`).
**Release:** the net-new headline for plugin 14.0.0 / theme 13.0.0 (the first
cuts under the WordPress shape; `docs/VERSIONING.md`).
**Owner decisions during brainstorming:** C (recall + order) over A/B; notes
ranked, pages ride along unranked (A); the row shows its evidence (B); the
plugin shapes the theme's existing query (approach 2); "keep it small".

## What a reader gets

`/notes/?s=…` returns the notes that best answer the words typed, first, in
order of how well they answer — and each ranked row shows the sentence that
answered, with the typed words marked. Nothing is added to the page; the
order and one line of each row change. Pages (essays, editorial pages) keep
appearing after the ranked notes, in date order, as today. Deactivate the
plugin and search is today's search, byte for byte.

## Today

The theme's `sn_notes_query()` (`inc/notes-index-helpers.php`) runs one
`WP_Query`: `s => $term`, `post_type => [post, page]`, `has_password => false`,
`orderby date DESC`, 50 per page. WordPress splits the term on spaces and
requires **every** word (`LIKE '%w%'` per word, ANDed); results are dated,
not ranked. The plugin's ML kernel (`inc/ml-kernel.php`, pure) already has
`snt_ml_tokenize`, `snt_ml_corpus_stats`, `snt_ml_bm25_score`; the corpus
artifact (`inc/ml-artifacts.php`) is rebuilt on every publish transition
(async, +30 s) and nightly, over `post_status = publish`, `post_type = post`.
The tokenizer does **no stemming**; `LIKE` is a substring match, so plurals
already match today. What the kernel adds is *any-word* matching with a
score, instead of *every-word* matching with a date.

## 1. Ranking and recall

- Tokenise the sanitised term with `snt_ml_tokenize()` — the same function
  the corpus was built with, so query and documents agree on casing and
  stopwords.
- Score every note in the artifact with
  `snt_ml_bm25_score( $query_tokens, $doc_tokens, $stats )`, kernel defaults
  (k1 1.2, b 0.75). Keep score > 0, sort score DESC, ties by post ID DESC (the artifact
  carries no dates; a higher ID is a newer note, and this avoids a DB read
  inside the ranking). This ordered `int[]` is **the ranking**.
- **Recall:** a note is a result if the kernel scored it **or** `LIKE` found
  it. **Order:** ranked notes first in score order; then everything else
  `LIKE` found — pages, and notes the kernel did not score — in date order.
- Empty token list (all stopwords, or a token the tokenizer drops such as
  `v13`) → no ranking → today's behaviour, untouched.
- Notes only. Pages are never scored. Stemming is out of scope (it would
  change every pipeline's vectors).

## 2. The seam

**Theme** (`inc/notes-index-helpers.php`), two touches:

1. `sn_notes_query()` adds `'sn_notes_search' => true` to the `WP_Query`
   args when `$term !== ''`. A private query var; nothing else reads it.
2. Where a search row renders `get_the_excerpt()`, render
   `apply_filters( 'sn_notes_search_snippet', get_the_excerpt(), get_the_ID(), $term )`
   instead. Default is the excerpt.

Plus one CSS rule for `mark` inside a result row (rust text or underline, no
background block). Nothing else in the theme changes.

**Plugin**, one new module `inc/notes-search-ranking.php`, loaded from
`signal-and-noise-tools.php`, declarations `function_exists`-guarded:

- `snt_search_rank_notes( string $term ): int[]` — reads the search index
  `snt_ml_search_index` (an option written by `snt_ml_build_corpus()` beside
  the related rows: per note a term-frequency map and token length; corpus
  `idf` and `avg_length`), scores with `snt_ml_bm25_score_tf()`, sorts,
  returns ids. Empty array when the index is missing, its `built_at`
  disagrees with the corpus meta, or the term tokenises to nothing. Filter
  `snt_search_ranking_ids( int[] $ids, string $term )` may replace the list —
  the plugin's usual seam, nothing more. Memoised per request.
- `snt_search_posts_clauses( array $clauses, WP_Query $query ): array` on
  `posts_clauses`, priority 10. Untouched unless
  `true === $query->get( 'sn_notes_search' )` and the ranking is non-empty.
  Otherwise, with `$ids` int-cast and capped at 500:
  - **where**: the existing clause is kept whole and OR-ed with the ranked
    ids, and the OR branch re-applies the query's own scope so widening can
    never out-scope it:
    `AND ( (…existing…) OR ( {$wpdb->posts}.ID IN (ids) AND post_status = 'publish' AND post_password = '' ) )`
  - **orderby**: `FIELD( {$wpdb->posts}.ID, id_n, …, id_1 ) DESC, ` prepended
    to the existing `post_date DESC`. Ranked notes sort first in rank order;
    `FIELD()` returns 0 for everything else, which sorts last under `DESC`
    and then by date — pages and `LIKE`-only notes exactly as today.
- `snt_search_snippet( string $excerpt, int $post_id, string $term ): string`
  on `sn_notes_search_snippet` (§3).

The theme's pagination, `found_posts`, `has_password`, and the posts+pages
widening are untouched because the query is the same query with a wider
`WHERE` and a longer `ORDER BY`.

## 3. The snippet

Only for notes the ranking scored; a `LIKE`-only note or a page keeps its
excerpt (no evidence to show).

1. Prose from blocks via `sn_prov_normalize_v2()` — the normaliser the
   provenance ledger signs; no block markup reaches a snippet.
2. Pick the query token with the highest idf that occurs in the prose (the
   rarest word the reader typed is the strongest evidence); take the first
   sentence containing it via `snt_corpus_integrity_sentence_at()`.
3. Escape the sentence, then wrap every query token with `<mark>` —
   case-insensitive, word-boundaried, on the tokenizer's normalisation.
   Output through `wp_kses()` allowing only `mark`.
4. Cap at the excerpt length the theme uses, cut at a word boundary, add an
   ellipsis.
5. No sentence (token only in the title) → the excerpt.

## 4. Freshness and failure modes

| Case | Behaviour |
|---|---|
| Note published a minute ago | `LIKE`-findable at once; ranked after the async rebuild (~30 s). |
| Published note edited | Ranking and snippet may lag until the nightly rebuild — there is no rebuild on edit today. Accepted; not built. |
| Artifact missing / stale shape | Ranking `[]`; clauses untouched; today's search. Never fatal, never empty. |
| Query tokenises to nothing | Untouched. |
| Ranked id unpublished since the last rebuild | Excluded by the OR branch's own `post_status`/`post_password` scope. |
| Corpus > 500 notes | Ranking truncated to 500 for `FIELD()`; the design would move to a scores table before that day. |
| Plugin deactivated | Query var and filters inert; today's search. |

Cost: one artifact read (the related-notes block already makes it on every
single) plus BM25 over ~60 token arrays per search request.

## 5. Tests

Standalone suites in the sweep, no WP bootstrap; the kernel and the new
module are `require`d directly.

**`tests/notes-search-ranking.php`** (plugin)
- A six-note fixture corpus. **The acceptance fixture:** a two-word query
  where only one note carries the rarer word; the note ranks first under the
  kernel, and a simulated core `LIKE`-AND misses it. Asserted red against the
  unhooked clauses first (the guard can fail), then green.
- Ties break by ID DESC. Stopword-only query → clauses byte-identical.
  Missing artifact → byte-identical. Stale shape → byte-identical.
- The OR branch carries `post_status = 'publish' AND post_password = ''`
  (an unpublished ranked id must not surface).
- `FIELD()` argument order equals the ranked order; the 500 cap; ids are
  integers (a planted string id never reaches SQL).
- Negative control: a deliberately wrong rank order reds the order pin.

**Snippet pins** (same suite)
- Highest-idf token chooses the sentence; every query token is marked and
  nothing else; block markup never survives; word-boundary cut with
  ellipsis; title-only match falls back to the excerpt.
- XSS pin: a planted `<script>` in prose is asserted present in the fixture,
  then absent after `wp_kses` — never vacuous.

**`tests/notes-search-query.php`** (theme)
- `sn_notes_search` is set only when a term is present; the snippet filter is
  applied on search rows and not on browse rows; the `mark` rule exists in
  the results stylesheet.

**Live, after both ship:** `/notes/?s=<the fixture's two words>` on
production — the named note is first and its row shows a marked sentence.

## Out of scope (named so nobody re-opens them by accident)

Stemming; ranking pages; a results count line; rebuild-on-edit; a scores
table; exact-phrase quoting; search analytics.
