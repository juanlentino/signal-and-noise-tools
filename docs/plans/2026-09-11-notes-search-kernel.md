# Search served by the kernel — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/notes/?s=` returns kernel-ranked notes first (BM25 over the nightly corpus), each ranked row showing the sentence that matched with the query words marked; pages and `LIKE`-only notes follow in date order exactly as today.

**Architecture:** The plugin persists a small search index at corpus-build time (term frequencies + idf), ranks with the kernel's BM25, and shapes the theme's existing `WP_Query` through `posts_clauses` (wider `WHERE`, `FIELD()` ordering) when the theme flags the query with `sn_notes_search`. The snippet arrives through one theme filter, `sn_notes_search_snippet`. Plugin off ⇒ today's search, byte for byte. Spec: `docs/proposals/2026-09-11-notes-search-kernel-design.md`.

**Tech Stack:** PHP 8.3+, WordPress 7.0 (`WP_Query`, `posts_clauses`), the plugin's pure ML kernel (`inc/ml-kernel.php`), standalone test sweep (`bash tests/run.sh`, no WP bootstrap). Two repos: plugin `signal-and-noise-tools`, theme `signal-and-noise`.

**Spec deviations, decided while planning (spec amended in Task 0):**
- The corpus artifact stores per-post *related* rows and a topics option — **not** the tokenised documents or the corpus stats. Ranking therefore needs its own index, written by the same build: `snt_ml_search_index` option (`autoload = no`), holding `tf` maps + `len` per note and `idf` + `avg_length`. A new pure kernel function `snt_ml_bm25_score_tf()` scores from a tf map; the existing `snt_ml_bm25_score()` delegates to it, so its exact-value pins keep passing.
- The prose normaliser is `sn_prov_normalize_v2()` (`inc/provenance-core.php`); the spec's `snt_corpus_render_text()` does not exist.

---

## File structure

**Plugin (`signal-and-noise-tools`)**

| File | Responsibility |
|---|---|
| `inc/ml-kernel.php` (modify) | `snt_ml_bm25_score_tf()` — BM25 from a tf map; `snt_ml_bm25_score()` delegates |
| `inc/ml-artifacts.php` (modify) | `SNT_ML_SEARCH_OPT` written in `snt_ml_build_corpus()`; `snt_ml_search_index()` reader |
| `inc/notes-search-ranking.php` (create) | `snt_search_rank_notes()`, `snt_search_posts_clauses()`, `snt_search_snippet()`, hooks |
| `signal-and-noise-tools.php` (modify) | `require_once` the module after `ml-artifacts.php` |
| `inc/maturity-roadmap-shortcode.php` (modify) | the board row moves Planned → done |
| `tests/ml-kernel.php`, `tests/ml-artifacts.php` (modify) | pins for the two additions |
| `tests/notes-search-ranking.php` (create) | ranking, clauses, snippet, acceptance fixture, XSS pin |
| `CHANGELOG.md` | `[Unreleased]` bullet |

**Theme (`signal-and-noise`)**

| File | Responsibility |
|---|---|
| `inc/notes-index-helpers.php` (modify) | `sn_notes_search => true` on the search query |
| `inc/notes-index-row.php` (modify) | apply `sn_notes_search_snippet`; `wp_kses` with `mark` when a snippet came back |
| `assets/css/notes.css` (modify) | `.sn-notes-row-excerpt mark` |
| `tests/notes-search-query.php` (create) | flag set only with a term; snippet applied only on search rows; mark rule present |
| `CHANGELOG.md` | `[Unreleased]` bullet |

---

### Task 0: Amend the spec for the two deviations

**Files:**
- Modify: `docs/proposals/2026-09-11-notes-search-kernel-design.md`

- [ ] **Step 1: Edit §2 and §3**

In §2, replace the sentence beginning "`snt_search_rank_notes( string $term ): int[]` — reads the artifact the way `snt_ml_related_for_post()` does" with:

```markdown
- `snt_search_rank_notes( string $term ): int[]` — reads the search index
  `snt_ml_search_index` (an option written by `snt_ml_build_corpus()` beside
  the related rows: per note a term-frequency map and token length; corpus
  `idf` and `avg_length`), scores with `snt_ml_bm25_score_tf()`, sorts,
  returns ids. Empty array when the index is missing, its `built_at`
  disagrees with the corpus meta, or the term tokenises to nothing.
```

In §3 step 1, replace `snt_corpus_render_text()` with `sn_prov_normalize_v2()`.

- [ ] **Step 2: Commit**

```bash
git add docs/proposals/2026-09-11-notes-search-kernel-design.md
git commit -m "docs: spec — the ranking reads a search index the build writes; prose via sn_prov_normalize_v2"
```

---

### Task 1: Kernel — BM25 from a term-frequency map

**Files:**
- Modify: `inc/ml-kernel.php:223-245`
- Test: `tests/ml-kernel.php` (append to group (e))

- [ ] **Step 1: Write the failing test**

Append before the final `Result:` echo in `tests/ml-kernel.php`:

```php
echo "\nGroup (e2): bm25 from a tf map equals bm25 from the token list\n";
$on_tf = array_count_values( $bm_docs['on'] );
ok( function_exists( 'snt_ml_bm25_score_tf' ), '(e2) snt_ml_bm25_score_tf exists' );
ok( function_exists( 'snt_ml_bm25_score_tf' ) && feq( snt_ml_bm25_score_tf( $q, $on_tf, count( $bm_docs['on'] ), $bm_stats ), snt_ml_bm25_score( $q, $bm_docs['on'], $bm_stats ) ), '(e2) tf-map form equals token-list form on the pinned document' );
ok( function_exists( 'snt_ml_bm25_score_tf' ) && 0.0 === snt_ml_bm25_score_tf( $q, array(), 0, $bm_stats ), '(e2) empty tf map scores exactly 0.0' );
ok( function_exists( 'snt_ml_bm25_score_tf' ) && 0.0 === snt_ml_bm25_score_tf( array(), $on_tf, count( $bm_docs['on'] ), $bm_stats ), '(e2) empty query scores exactly 0.0' );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/ml-kernel.php | grep -E "e2|Result"`
Expected: the three `(e2)` lines after the first FAIL (function missing), `Result: … 3 failed` (the `function_exists &&` guards make them fail, not fatal).

- [ ] **Step 3: Implement**

In `inc/ml-kernel.php`, replace the body of `snt_ml_bm25_score()` and add the new function directly after it (inside the same `if ( ! function_exists( 'snt_ml_bm25_score' ) )` block is fine for the delegate; give the new one its own guard):

```php
	function snt_ml_bm25_score( array $query_tokens, array $doc_tokens, array $stats, $k1 = 1.2, $b = 0.75 ) {
		if ( array() === $query_tokens || array() === $doc_tokens ) {
			return 0.0;
		}
		return snt_ml_bm25_score_tf( $query_tokens, array_count_values( $doc_tokens ), count( $doc_tokens ), $stats, $k1, $b );
	}
}

if ( ! function_exists( 'snt_ml_bm25_score_tf' ) ) {
	/**
	 * BM25 from a term-frequency map and a token length — the form a stored
	 * index holds (v13.111.0, notes search). snt_ml_bm25_score() delegates
	 * here, so the two can never disagree.
	 *
	 * @param string[]          $query_tokens Query tokens.
	 * @param array<string,int> $tf_map       Term => count in the document.
	 * @param int               $doc_len      Total token count of the document.
	 * @param array             $stats        snt_ml_corpus_stats() output (idf + avg_length are read).
	 * @param float             $k1           TF saturation (default 1.2).
	 * @param float             $b            Length-normalization strength (default 0.75).
	 * @return float 0.0 when nothing overlaps or the doc is empty.
	 */
	function snt_ml_bm25_score_tf( array $query_tokens, array $tf_map, $doc_len, array $stats, $k1 = 1.2, $b = 0.75 ) {
		$doc_len = (int) $doc_len;
		if ( array() === $query_tokens || array() === $tf_map || $doc_len <= 0 ) {
			return 0.0;
		}
		$idf_map = isset( $stats['idf'] ) && is_array( $stats['idf'] ) ? $stats['idf'] : array();
		$avg_len = isset( $stats['avg_length'] ) ? (float) $stats['avg_length'] : 0.0;
		if ( $avg_len <= 0.0 ) {
			$avg_len = (float) $doc_len; // Degenerate corpus: neutral length norm.
		}
		$score = 0.0;
		foreach ( array_unique( $query_tokens ) as $term ) {
			$tf = (int) ( $tf_map[ $term ] ?? 0 );
			if ( 0 === $tf || ! isset( $idf_map[ $term ] ) ) {
				continue; // Out-of-corpus query terms carry no ranking signal.
			}
			$score += $idf_map[ $term ] * ( $tf * ( $k1 + 1 ) )
				/ ( $tf + $k1 * ( 1 - $b + $b * $doc_len / $avg_len ) );
		}
		return $score;
	}
```

(Remove the old body's `$idf_map … return $score;` lines from `snt_ml_bm25_score()` — the delegate is its whole body now.)

- [ ] **Step 4: Run to verify it passes, including the existing exact-value pin**

Run: `php tests/ml-kernel.php | grep -E "e\)|e2|Result"`
Expected: every `(e)` and `(e2)` line PASS (the `4.4723657671` pin still holds), `Result: … 0 failed`.

- [ ] **Step 5: The purity pin still holds**

Run: `php tests/ml-kernel.php | grep -i "pure\|WP function"`
Expected: PASS — the file gained no WordPress call.

- [ ] **Step 6: Commit**

```bash
git add inc/ml-kernel.php tests/ml-kernel.php
git commit -m "feat(kernel): bm25 from a term-frequency map; the token-list form delegates to it"
```

---

### Task 2: Artifacts — write the search index at build, read it back

**Files:**
- Modify: `inc/ml-artifacts.php` (constants block ~line 32; inside `snt_ml_build_corpus()` after the `SNT_ML_CORPUS_META_OPT` write ~line 236; new reader after `snt_ml_related_for_post()`)
- Test: `tests/ml-artifacts.php`

- [ ] **Step 1: Write the failing test**

Append before the final `Result:` echo in `tests/ml-artifacts.php` (the file already stubs `get_option`/`update_option` into `$GLOBALS['__options']` and builds a fixture corpus with `tf_post()`; `snt_ml_build_corpus()` has been called by this point):

```php
echo "\nGroup: the search index (v13.111.0)\n";
snt_ml_build_corpus();
$idx = get_option( 'snt_ml_search_index', false );
ok( is_array( $idx ) && isset( $idx['docs'], $idx['stats'], $idx['built_at'] ), 'build writes snt_ml_search_index with docs, stats, built_at' );
$meta = get_option( SNT_ML_CORPUS_META_OPT, false );
ok( is_array( $idx ) && is_array( $meta ) && $idx['built_at'] === $meta['built_at'], 'index built_at equals the corpus meta built_at (the pairing the reader checks)' );
$any_id = is_array( $idx ) ? (int) array_key_first( $idx['docs'] ) : 0;
ok( $any_id > 0 && isset( $idx['docs'][ $any_id ]['tf'], $idx['docs'][ $any_id ]['len'] ) && is_array( $idx['docs'][ $any_id ]['tf'] ) && $idx['docs'][ $any_id ]['len'] === array_sum( $idx['docs'][ $any_id ]['tf'] ), 'each doc carries a tf map and len == sum(tf)' );
ok( is_array( $idx ) && isset( $idx['stats']['idf'], $idx['stats']['avg_length'] ) && is_array( $idx['stats']['idf'] ) && ! isset( $idx['stats']['doc_lengths'] ), 'stats carry idf + avg_length only (no per-doc lengths — they live on the docs)' );
ok( function_exists( 'snt_ml_search_index' ) && is_array( snt_ml_search_index() ) && count( snt_ml_search_index()['docs'] ) === count( $idx['docs'] ), 'snt_ml_search_index() returns the stored index' );
$GLOBALS['__options']['snt_ml_search_index']['built_at'] = 1;
ok( function_exists( 'snt_ml_search_index' ) && null === snt_ml_search_index(), 'a built_at that disagrees with the corpus meta reads as NULL (stale index, not a half-answer)' );
unset( $GLOBALS['__options']['snt_ml_search_index'] );
ok( function_exists( 'snt_ml_search_index' ) && null === snt_ml_search_index(), 'a missing index reads as NULL' );
snt_ml_build_corpus(); // restore for anything below
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/ml-artifacts.php | grep -E "search index|index|tf map|NULL|Result"`
Expected: FAIL on "build writes snt_ml_search_index" and the reader lines; `Result: … failed` > 0.

- [ ] **Step 3: Implement — constant, write, reader**

Constants block (next to `SNT_ML_CORPUS_META_OPT`):

```php
const SNT_ML_SEARCH_OPT = 'snt_ml_search_index'; // v13.111.0: tf maps + idf for notes search (autoload=no).
```

Inside `snt_ml_build_corpus()`, right after the `update_option( SNT_ML_CORPUS_META_OPT, … )` call:

```php
		// v13.111.0: the SEARCH INDEX. The related rows above are per-post and
		// the topics option is per-cluster; neither holds what a ranking needs —
		// the term frequencies and the corpus idf. Written beside them, from the
		// same $docs/$stats, stamped with the same built_at so the reader can
		// refuse a half-updated pair. Term-frequency maps, not token lists: the
		// map is what BM25 consumes and it is a fraction of the size.
		$search_docs = array();
		foreach ( $docs as $id => $tokens ) {
			$search_docs[ (int) $id ] = array(
				'tf'  => array_count_values( $tokens ),
				'len' => count( $tokens ),
			);
		}
		update_option( SNT_ML_SEARCH_OPT, array(
			'built_at' => $built_at,
			'built_by' => defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '',
			'stats'    => array(
				'idf'        => isset( $stats['idf'] ) ? $stats['idf'] : array(),
				'avg_length' => isset( $stats['avg_length'] ) ? (float) $stats['avg_length'] : 0.0,
			),
			'docs'     => $search_docs,
		), false );
```

Reader, after `snt_ml_related_for_post()`'s closing `}`:

```php
if ( ! function_exists( 'snt_ml_search_index' ) ) {
	/**
	 * The search index, or null when there is nothing trustworthy to rank
	 * with: never built, malformed, or built_at disagreeing with the corpus
	 * meta (a rebuild that wrote one option and died before the other). Null
	 * is "do not rank", which the caller turns into today's search.
	 *
	 * @since 13.111.0
	 * @return array{built_at:int,stats:array{idf:array<string,float>,avg_length:float},docs:array<int,array{tf:array<string,int>,len:int}>}|null
	 */
	function snt_ml_search_index() {
		$index = get_option( SNT_ML_SEARCH_OPT, false );
		$meta  = get_option( SNT_ML_CORPUS_META_OPT, false );
		if ( ! is_array( $index ) || ! is_array( $meta )
			|| ! isset( $index['built_at'], $index['docs'], $index['stats'], $meta['built_at'] )
			|| ! is_array( $index['docs'] ) || ! is_array( $index['stats'] )
			|| (int) $index['built_at'] !== (int) $meta['built_at'] ) {
			return null;
		}
		return $index;
	}
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/ml-artifacts.php | tail -1`
Expected: `Result: … 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add inc/ml-artifacts.php tests/ml-artifacts.php
git commit -m "feat(ml): the corpus build writes a search index (tf maps + idf); snt_ml_search_index() reads it or answers null"
```

---

### Task 3: Ranking — `snt_search_rank_notes()`

**Files:**
- Create: `inc/notes-search-ranking.php`
- Create: `tests/notes-search-ranking.php`

- [ ] **Step 1: Write the failing test (harness + ranking group)**

Create `tests/notes-search-ranking.php`:

```php
<?php
/**
 * Tests: notes search served by the kernel (v13.111.0).
 *
 * The spec: docs/proposals/2026-09-11-notes-search-kernel-design.md. Three
 * groups — ranking, the posts_clauses shaping, the snippet — plus the
 * ACCEPTANCE FIXTURE: a two-word query where core's every-word LIKE misses
 * the note the kernel ranks first. Asserted red against the unhooked clauses
 * before green, so the guard is proven able to fail.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

// ── WP stubs ────────────────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
function apply_filters( $h, $v ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v, ...array_slice( func_get_args(), 2 ) ); } return $v; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ][] = $cb; return true; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { return true; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
$GLOBALS['__posts'] = array();
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses( $s, $allowed ) {
	// Minimal faithful stand-in: keep only the allowed tag names, drop every other tag whole.
	$keep = implode( '|', array_map( 'preg_quote', array_keys( (array) $allowed ) ) );
	return preg_replace( '#</?(?!(?:' . $keep . ')\b)[a-z][^>]*>#i', '', (string) $s );
}
class WPDB_Stub { public $posts = 'wp_posts'; }
$GLOBALS['wpdb'] = new WPDB_Stub();
class WPQ_Stub {
	private $vars;
	public function __construct( array $vars ) { $this->vars = $vars; }
	public function get( $k, $d = '' ) { return $this->vars[ $k ] ?? $d; }
}

require_once __DIR__ . '/../inc/ml-kernel.php';
require_once __DIR__ . '/../inc/corpus-integrity-scan.php'; // snt_corpus_integrity_sentence_at()
require_once __DIR__ . '/../inc/notes-search-ranking.php';

// ── Fixture corpus: six notes, built with the kernel itself ─────────────
$corpus = array(
	11 => 'The provenance ledger anchors every note to Bitcoin. A ledger entry is a signed record.',
	12 => 'Detection scales the wrong way. Watermarks fade; detection budgets grow. Nothing here about ledgers.',
	13 => 'Verifying the artist is not enough. A signature proves a key, not a person.',
	14 => 'A ledger without an anchor is a diary. The anchor is what makes the ledger public evidence.',
	15 => 'Analytics without cookies: the beacon carries no identity, and the salt rotates daily.',
	16 => 'The provenance question, restated: who wrote this, and can a stranger check without trusting me?',
);
$docs = array();
foreach ( $corpus as $id => $text ) { $docs[ $id ] = snt_ml_tokenize( $text ); }
$stats = snt_ml_corpus_stats( $docs );
$search_docs = array();
foreach ( $docs as $id => $t ) { $search_docs[ $id ] = array( 'tf' => array_count_values( $t ), 'len' => count( $t ) ); }
function seed_index( $search_docs, $stats, $built_at = 1000 ) {
	$GLOBALS['__options']['snt_ml_search_index'] = array( 'built_at' => $built_at, 'built_by' => 't', 'stats' => array( 'idf' => $stats['idf'], 'avg_length' => $stats['avg_length'] ), 'docs' => $search_docs );
	$GLOBALS['__options']['snt_ml_corpus_meta']  = array( 'built_at' => 1000, 'fingerprint' => 'x', 'posts' => count( $search_docs ) );
}
function like_and( $term, $text ) { // core search: every word as a substring, ANDed
	foreach ( preg_split( '/\s+/', trim( $term ) ) as $w ) { if ( '' !== $w && false === stripos( $text, $w ) ) { return false; } }
	return true;
}
// Define the reader here ONLY if ml-artifacts.php is not loaded (it is not, in this suite).
if ( ! function_exists( 'snt_ml_search_index' ) ) {
	function snt_ml_search_index() {
		$i = get_option( 'snt_ml_search_index', false ); $m = get_option( 'snt_ml_corpus_meta', false );
		return ( is_array( $i ) && is_array( $m ) && (int) $i['built_at'] === (int) $m['built_at'] ) ? $i : null;
	}
}

echo "Group: ranking\n";
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true ); // reset memo
$r = snt_search_rank_notes( 'ledger anchor' );
ok( array() !== $r && 14 === $r[0], 'the note carrying BOTH query words ranks first (14)' );
ok( in_array( 11, $r, true ) && in_array( 16, $r, true ) === false, 'a note with one of the words is IN the ranking (11); a note with neither is not (16)' );
ok( ! in_array( 15, $r, true ) && ! in_array( 13, $r, true ), 'notes with no query word are absent' );
ok( array() === snt_search_rank_notes( 'the of and' ), 'a stopword-only query ranks nothing' );
ok( array() === snt_search_rank_notes( '' ), 'an empty query ranks nothing' );
$GLOBALS['__options']['snt_ml_search_index']['built_at'] = 999;
snt_search_rank_notes( '', true );
ok( array() === snt_search_rank_notes( 'ledger' ), 'a stale index (built_at != corpus meta) ranks nothing' );
unset( $GLOBALS['__options']['snt_ml_search_index'] );
snt_search_rank_notes( '', true );
ok( array() === snt_search_rank_notes( 'ledger' ), 'a missing index ranks nothing' );
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
add_filter( 'snt_search_ranking_ids', function ( $ids ) { return array( 12, '13', 'x', -4 ); } );
ok( array( 12, 13 ) === snt_search_rank_notes( 'ledger' ), 'the snt_search_ranking_ids filter replaces the list; non-positive and non-numeric entries are dropped' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
// Tie-break: two docs with the same score → higher ID first. Build a tie on purpose.
$tie_docs = array( 21 => snt_ml_tokenize( 'salt rotates' ), 22 => snt_ml_tokenize( 'salt rotates' ) );
$tie_stats = snt_ml_corpus_stats( $tie_docs );
seed_index( array( 21 => array( 'tf' => array_count_values( $tie_docs[21] ), 'len' => 2 ), 22 => array( 'tf' => array_count_values( $tie_docs[22] ), 'len' => 2 ) ), $tie_stats );
snt_search_rank_notes( '', true );
ok( array( 22, 21 ) === snt_search_rank_notes( 'salt' ), 'ties break by ID DESC' );
// The cap.
$big = array(); $big_docs = array();
for ( $i = 1; $i <= 520; $i++ ) { $big_docs[ 1000 + $i ] = snt_ml_tokenize( 'anchor anchor' ); }
$big_stats = snt_ml_corpus_stats( $big_docs );
foreach ( $big_docs as $id => $t ) { $big[ $id ] = array( 'tf' => array_count_values( $t ), 'len' => count( $t ) ); }
seed_index( $big, $big_stats );
snt_search_rank_notes( '', true );
ok( SNT_SEARCH_RANK_CAP === count( snt_search_rank_notes( 'anchor' ) ) && 500 === SNT_SEARCH_RANK_CAP, 'the ranking is capped at 500 ids' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/notes-search-ranking.php 2>&1 | tail -3`
Expected: a fatal on `require_once … notes-search-ranking.php` (file missing) — no summary line. (`tests/run.sh` would count that as a failure; good.)

- [ ] **Step 3: Implement the module (ranking only)**

Create `inc/notes-search-ranking.php`:

```php
<?php
/**
 * Signal & Noise Tools — notes search served by the kernel (v13.111.0).
 *
 * The theme's /notes/?s= query is WordPress's every-word LIKE, in date order.
 * This module ranks NOTES with the kernel's BM25 over the search index the
 * corpus build writes (inc/ml-artifacts.php), and shapes the theme's
 * EXISTING WP_Query through posts_clauses: the WHERE is widened to admit
 * kernel-ranked ids, the ORDER BY gets FIELD() in rank order in front of the
 * date. Pages and LIKE-only notes follow in date order exactly as before.
 *
 * The theme opts in with one query var, sn_notes_search => true, and takes
 * the evidence snippet through one filter, sn_notes_search_snippet. With the
 * plugin off, both are inert: today's search, byte for byte.
 *
 * Spec: docs/proposals/2026-09-11-notes-search-kernel-design.md.
 *
 * @package SignalNoiseTools
 * @since 13.111.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hard cap on ranked ids reaching SQL — FIELD() with hundreds of args is fine, thousands is not. */
const SNT_SEARCH_RANK_CAP = 500;

/**
 * Rank the notes corpus against a search term. Memoised per request (one
 * search page = one term = several callers: the clauses hook and every row's
 * snippet).
 *
 * @param string $term  The search term as the theme sanitised it.
 * @param bool   $reset Tests only: clear the memo.
 * @return int[] Note ids, best first; empty when nothing can be ranked.
 */
function snt_search_rank_notes( $term, $reset = false ) {
	static $memo = array();
	if ( $reset ) {
		$memo = array();
		return array();
	}
	$key = (string) $term;
	if ( array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}

	$ids   = array();
	$index = function_exists( 'snt_ml_search_index' ) ? snt_ml_search_index() : null;
	$query = function_exists( 'snt_ml_tokenize' ) ? array_values( array_unique( snt_ml_tokenize( $key ) ) ) : array();

	if ( is_array( $index ) && array() !== $query && function_exists( 'snt_ml_bm25_score_tf' ) ) {
		$scores = array();
		foreach ( (array) $index['docs'] as $id => $doc ) {
			$score = snt_ml_bm25_score_tf( $query, (array) ( $doc['tf'] ?? array() ), (int) ( $doc['len'] ?? 0 ), (array) $index['stats'] );
			if ( $score > 0.0 ) {
				$scores[ (int) $id ] = $score;
			}
		}
		// Score DESC, then ID DESC: the index carries no dates, and a higher
		// id is a newer note — no DB read inside the ranking.
		uksort( $scores, static function ( $a, $b ) use ( $scores ) {
			return ( $scores[ $b ] <=> $scores[ $a ] ) ?: ( $b <=> $a );
		} );
		$ids = array_keys( $scores );
	}

	/**
	 * Replace the ranked id list. The plugin's usual seam; nothing else here
	 * is filterable.
	 *
	 * @param int[]  $ids  Ranked note ids, best first.
	 * @param string $term The search term.
	 */
	$ids = (array) apply_filters( 'snt_search_ranking_ids', $ids, $key );
	$ids = array_values( array_filter( array_map( 'intval', $ids ), static function ( $i ) { return $i > 0; } ) );

	$memo[ $key ] = array_slice( $ids, 0, SNT_SEARCH_RANK_CAP );
	return $memo[ $key ];
}
```

- [ ] **Step 4: Run to verify the ranking group passes**

Run: `php tests/notes-search-ranking.php`
Expected: every line in "Group: ranking" PASS, `Result: 11 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add inc/notes-search-ranking.php tests/notes-search-ranking.php
git commit -m "feat(search): snt_search_rank_notes — BM25 over the search index, memoised, capped, filterable"
```

---

### Task 4: Clauses — shape the theme's query, and the acceptance fixture

**Files:**
- Modify: `inc/notes-search-ranking.php` (append)
- Modify: `tests/notes-search-ranking.php` (insert a group before the `Result:` echo)

- [ ] **Step 1: Write the failing test**

Insert before `echo "\nResult: …"` in `tests/notes-search-ranking.php`:

```php
echo "\nGroup: posts_clauses — the theme's query, widened and ordered\n";
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
$base = array( 'where' => " AND (((wp_posts.post_title LIKE '%ledger%') OR (wp_posts.post_content LIKE '%ledger%'))) AND wp_posts.post_type IN ('post','page') AND wp_posts.post_status = 'publish'", 'orderby' => 'wp_posts.post_date DESC', 'join' => '', 'groupby' => '', 'limits' => 'LIMIT 0, 50', 'distinct' => '', 'fields' => 'wp_posts.*' );

// THE ACCEPTANCE FIXTURE. "ledger anchor": note 11 has "ledger" but not "anchor".
// Core's every-word LIKE misses it; the kernel ranks it (14 first, then 11).
ok( false === like_and( 'ledger anchor', $corpus[11] ), 'fixture: core LIKE-AND misses note 11 for "ledger anchor" (it lacks "anchor")' );
ok( true === like_and( 'ledger anchor', $corpus[14] ), 'fixture: core LIKE-AND finds note 14 (it has both words)' );
$untouched = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger anchor' ) ) ); // NO sn_notes_search flag
ok( $untouched === $base, 'RED HALF: without the sn_notes_search flag the clauses are byte-identical — the query would still miss note 11' );
$shaped = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger anchor', 'sn_notes_search' => true ) ) );
ok( false !== strpos( $shaped['where'], 'wp_posts.ID IN (14,11)' ), 'GREEN HALF: the WHERE now admits the ranked ids (14,11) — note 11 is reachable' );
ok( 0 === strpos( $shaped['where'], ' AND ( (1=1' . $base['where'] . ') OR (' ), 'the existing WHERE is kept whole inside the OR' );
ok( false !== strpos( $shaped['where'], "wp_posts.post_status = 'publish' AND wp_posts.post_password = ''" ), 'the OR branch re-applies publish + no-password scope, so widening never out-scopes the query' );
ok( 0 === strpos( $shaped['orderby'], 'FIELD(wp_posts.ID, 11,14) DESC, wp_posts.post_date DESC' ), 'FIELD() lists the ranked ids REVERSED (so the best gets the highest position under DESC), then the date order the theme had' );
foreach ( array( 'join', 'groupby', 'limits', 'distinct', 'fields' ) as $k ) { ok( $shaped[ $k ] === $base[ $k ], "clause '$k' untouched" ); }
ok( $base === snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'the of', 'sn_notes_search' => true ) ) ), 'a stopword-only term leaves the clauses byte-identical' );
$no_order = $base; $no_order['orderby'] = '';
$shaped2 = snt_search_posts_clauses( $no_order, new WPQ_Stub( array( 's' => 'ledger', 'sn_notes_search' => true ) ) );
ok( 'FIELD(wp_posts.ID, 11,14) DESC' === $shaped2['orderby'] || 0 === strpos( $shaped2['orderby'], 'FIELD(' ), 'an empty theme orderby gets FIELD() alone, no dangling comma' );
ok( false === strpos( $shaped2['orderby'], ', ' . '' ) || ! str_ends_with( trim( $shaped2['orderby'] ), ',' ), 'no trailing comma' );
// Injection guard: a string id from a rogue filter never reaches SQL.
add_filter( 'snt_search_ranking_ids', function ( $ids ) { return array( '14; DROP TABLE wp_posts', 11 ); } );
snt_search_rank_notes( '', true );
$shaped3 = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger', 'sn_notes_search' => true ) ) );
ok( false === strpos( $shaped3['where'], 'DROP' ) && false !== strpos( $shaped3['where'], 'IN (14,11)' ), 'ids are int-cast before they touch SQL' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
// Negative control: a wrong order must red the order pin.
add_filter( 'snt_search_ranking_ids', function ( $ids ) { return array( 11, 14 ); } );
snt_search_rank_notes( '', true );
$shaped4 = snt_search_posts_clauses( $base, new WPQ_Stub( array( 's' => 'ledger', 'sn_notes_search' => true ) ) );
ok( 0 !== strpos( $shaped4['orderby'], 'FIELD(wp_posts.ID, 11,14)' ), 'control: a reversed ranking produces a DIFFERENT FIELD() list — the order pin can fail' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/notes-search-ranking.php 2>&1 | grep -E "posts_clauses|Fatal|Result"`
Expected: fatal — `snt_search_posts_clauses` undefined.

- [ ] **Step 3: Implement**

Append to `inc/notes-search-ranking.php`:

```php
/**
 * posts_clauses: shape the theme's search query. Untouched unless the query
 * carries sn_notes_search => true AND the ranking is non-empty.
 *
 * WHERE: the existing clause is kept whole and OR-ed with the ranked ids;
 * the OR branch re-applies publish + no-password, so widening can never
 * out-scope the query it widens (a note unpublished since the last rebuild
 * stays out).
 *
 * ORDER BY: FIELD(ID, …ids reversed…) DESC in front of whatever the theme
 * ordered by. FIELD() returns the 1-based position or 0; with the list
 * reversed the best-ranked id has the highest position, DESC puts it first,
 * and every unranked row (0) sorts after all of them, in the theme's order.
 *
 * @param array  $clauses
 * @param object $query   WP_Query (or a stand-in exposing get()).
 * @return array
 */
function snt_search_posts_clauses( $clauses, $query = null ) {
	if ( ! is_array( $clauses ) || ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
		return $clauses;
	}
	if ( true !== $query->get( 'sn_notes_search' ) ) {
		return $clauses;
	}
	$ids = snt_search_rank_notes( (string) $query->get( 's' ) );
	if ( array() === $ids ) {
		return $clauses;
	}
	global $wpdb;
	$t    = isset( $wpdb->posts ) ? (string) $wpdb->posts : 'wp_posts';
	$list = implode( ',', array_map( 'intval', $ids ) ); // int-cast: the only thing that reaches SQL

	$where = (string) ( $clauses['where'] ?? '' );
	$clauses['where'] = " AND ( (1=1{$where}) OR ( {$t}.ID IN ({$list}) AND {$t}.post_status = 'publish' AND {$t}.post_password = '' ) )";

	$field   = 'FIELD(' . $t . '.ID, ' . implode( ',', array_reverse( array_map( 'intval', $ids ) ) ) . ') DESC';
	$orderby = trim( (string) ( $clauses['orderby'] ?? '' ) );
	$clauses['orderby'] = '' === $orderby ? $field : $field . ', ' . $orderby;
	return $clauses;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'posts_clauses', 'snt_search_posts_clauses', 10, 2 );
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/notes-search-ranking.php | grep -E "FAIL|Result"`
Expected: `Result: 26 passed, 0 failed` (11 + 15).

- [ ] **Step 5: Commit**

```bash
git add inc/notes-search-ranking.php tests/notes-search-ranking.php
git commit -m "feat(search): posts_clauses widens the WHERE to the ranked ids and orders by FIELD() — the acceptance fixture is red without the flag, green with it"
```

---

### Task 5: The snippet

**Files:**
- Modify: `inc/notes-search-ranking.php` (append)
- Modify: `tests/notes-search-ranking.php` (insert a group before `Result:`)

- [ ] **Step 1: Write the failing test**

```php
echo "\nGroup: the snippet — the row shows its evidence\n";
seed_index( $search_docs, $stats );
snt_search_rank_notes( '', true );
$GLOBALS['__posts'][11] = (object) array( 'ID' => 11, 'post_content' => "<!-- wp:paragraph -->\n<p>The provenance ledger anchors every note to Bitcoin.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>A ledger entry is a signed record.</p>\n<!-- /wp:paragraph -->" );
$GLOBALS['__posts'][12] = (object) array( 'ID' => 12, 'post_content' => '<p>Detection scales the wrong way.</p>' );
$s = snt_search_snippet( 'EXCERPT', 11, 'ledger anchor' );
ok( 'EXCERPT' !== $s, 'a ranked note gets a snippet, not its excerpt' );
ok( false !== strpos( $s, '<mark>ledger</mark>' ) && false === strpos( $s, '<mark>anchors</mark>' ), 'the query word is marked; the tokenizer does no stemming, so "anchors" is NOT marked for "anchor"' );
ok( false === strpos( $s, '<!--' ) && false === strpos( $s, '<p>' ), 'block markup never survives' );
ok( false !== strpos( $s, 'The provenance <mark>ledger</mark> anchors' ), 'the sentence chosen is the one holding the highest-idf query token, marked in place' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 12, 'ledger anchor' ), 'a note the ranking did not score keeps its excerpt (no evidence to show)' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 11, '' ), 'an empty term keeps the excerpt' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 999, 'ledger' ), 'an unknown post keeps the excerpt' );
// Title-only match: the token is in the ranking (index) but not in the prose → excerpt.
$GLOBALS['__posts'][14] = (object) array( 'ID' => 14, 'post_content' => '<p>Nothing from the query appears in this body at all.</p>' );
ok( 'EXCERPT' === snt_search_snippet( 'EXCERPT', 14, 'ledger anchor' ), 'no sentence carries a query token → excerpt' );
// Word boundary: "ledgers" must not mark "ledger" inside it… but "ledger" inside "ledgers" IS a substring —
// the rule is whole-word, so a query "ledger" leaves "ledgers" unmarked.
$GLOBALS['__posts'][16] = (object) array( 'ID' => 16, 'post_content' => '<p>Ledgers everywhere, and one ledger here.</p>' );
add_filter( 'snt_search_ranking_ids', function () { return array( 16 ); } );
snt_search_rank_notes( '', true );
$s16 = snt_search_snippet( 'EXCERPT', 16, 'ledger' );
ok( false !== strpos( $s16, 'Ledgers everywhere' ) && false === strpos( $s16, '<mark>Ledgers' ) && false !== strpos( $s16, 'one <mark>ledger</mark> here' ), 'marking is whole-word and case-insensitive: "Ledgers" untouched, "ledger" marked' );
// XSS pin: planted script in prose is asserted PRESENT first, then absent after.
$GLOBALS['__posts'][16] = (object) array( 'ID' => 16, 'post_content' => '<p>A ledger <script>alert(1)</script> line.</p>' );
ok( false !== strpos( $GLOBALS['__posts'][16]->post_content, '<script>' ), 'fixture: the script tag IS in the source (so the next pin cannot be vacuous)' );
$sx = snt_search_snippet( 'EXCERPT', 16, 'ledger' );
ok( false === strpos( $sx, '<script' ), 'no <script> survives (the tag is stripped before escaping; its text, if any, is escaped prose)' );
ok( 1 === preg_match( '#^[^<]*(<mark>[^<]*</mark>[^<]*)*$#', $sx ), 'the only tag in a snippet is <mark>' );
$GLOBALS['__filters'] = array();
snt_search_rank_notes( '', true );
// Length cap at a word boundary with an ellipsis.
$long = str_repeat( 'word ', 120 ) . 'ledger.';
$GLOBALS['__posts'][11] = (object) array( 'ID' => 11, 'post_content' => '<p>' . $long . '</p>' );
$sl = snt_search_snippet( 'EXCERPT', 11, 'ledger' );
ok( str_ends_with( $sl, '…' ) && mb_strlen( $sl ) < mb_strlen( $long ) && ! str_ends_with( rtrim( $sl, '…' ), 'wor' ), 'a long sentence is cut at a word boundary with an ellipsis' );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/notes-search-ranking.php 2>&1 | grep -E "snippet|Fatal|Result"`
Expected: fatal — `snt_search_snippet` undefined.

- [ ] **Step 3: Implement**

Append to `inc/notes-search-ranking.php`:

```php
/**
 * The evidence snippet for one ranked note: the first sentence holding the
 * rarest query token the reader typed, escaped, every query token wrapped
 * in <mark>, cut at a word boundary. Anything else — a LIKE-only note, a
 * page, an unknown post, no matching sentence — returns the excerpt it was
 * given, so the theme's default stands.
 *
 * Prose comes from sn_prov_normalize_v2(), the normaliser the ledger
 * signs: no block markup reaches a snippet by construction.
 *
 * @param string $excerpt The theme's excerpt (the fallback).
 * @param int    $post_id
 * @param string $term
 * @return string HTML-safe: text escaped, <mark> the only tag.
 */
function snt_search_snippet( $excerpt, $post_id, $term ) {
	$post_id = (int) $post_id;
	$term    = trim( (string) $term );
	if ( $post_id <= 0 || '' === $term || ! function_exists( 'snt_ml_tokenize' ) ) {
		return $excerpt;
	}
	if ( ! in_array( $post_id, snt_search_rank_notes( $term ), true ) ) {
		return $excerpt;
	}
	$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
	if ( ! $post || '' === trim( (string) ( $post->post_content ?? '' ) ) ) {
		return $excerpt;
	}
	$prose = function_exists( 'sn_prov_normalize_v2' )
		? sn_prov_normalize_v2( (string) $post->post_content )
		: wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', ' ', (string) $post->post_content ) );
	$prose = trim( preg_replace( '/\s+/u', ' ', (string) $prose ) );
	if ( '' === $prose ) {
		return $excerpt;
	}

	// Rarest query token first: the strongest evidence the reader typed.
	$tokens = array_values( array_unique( snt_ml_tokenize( $term ) ) );
	if ( array() === $tokens ) {
		return $excerpt;
	}
	$index = function_exists( 'snt_ml_search_index' ) ? snt_ml_search_index() : null;
	$idf   = is_array( $index ) && isset( $index['stats']['idf'] ) ? (array) $index['stats']['idf'] : array();
	usort( $tokens, static function ( $a, $b ) use ( $idf ) {
		return ( (float) ( $idf[ $b ] ?? 0 ) <=> (float) ( $idf[ $a ] ?? 0 ) ) ?: strcmp( $a, $b );
	} );

	$sentence = '';
	foreach ( $tokens as $token ) {
		if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $token, '/' ) . '(?![\p{L}\p{N}])/iu', $prose, $m, PREG_OFFSET_CAPTURE ) ) {
			$sentence = function_exists( 'snt_corpus_integrity_sentence_at' )
				? snt_corpus_integrity_sentence_at( $prose, (int) $m[0][1] )
				: $prose;
			break;
		}
	}
	if ( '' === $sentence ) {
		return $excerpt;
	}

	// Cap at the excerpt length, at a word boundary.
	$max_words = (int) apply_filters( 'excerpt_length', 55 );
	$words     = preg_split( '/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY );
	if ( is_array( $words ) && count( $words ) > $max_words ) {
		$sentence = implode( ' ', array_slice( $words, 0, $max_words ) ) . '…';
	}

	// Escape first, then mark whole words — tokens are letters/digits only,
	// so escaping cannot split one.
	$safe = esc_html( $sentence );
	foreach ( $tokens as $token ) {
		$safe = preg_replace( '/(?<![\p{L}\p{N}])(' . preg_quote( $token, '/' ) . ')(?![\p{L}\p{N}])/iu', '<mark>$1</mark>', $safe );
	}
	return wp_kses( $safe, array( 'mark' => array() ) );
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sn_notes_search_snippet', 'snt_search_snippet', 10, 3 );
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/notes-search-ranking.php | grep -E "FAIL|Result"`
Expected: `Result: 39 passed, 0 failed`. If the "sentence chosen" pin fails on the exact string, print `$s` and adjust the fixture wording — not the rule.

Note on the fixture: `sn_prov_normalize_v2()` is not loaded in this suite (it lives in `inc/provenance-core.php`, which pulls more of WordPress); the `wp_strip_all_tags` fallback branch is what runs here. Add one more pin so that is explicit:

```php
ok( ! function_exists( 'sn_prov_normalize_v2' ), 'harness note: the ledger normaliser is not loaded here, so these pins exercise the strip fallback; the normaliser has its own suite' );
```

- [ ] **Step 5: Commit**

```bash
git add inc/notes-search-ranking.php tests/notes-search-ranking.php
git commit -m "feat(search): the evidence snippet — rarest query token picks the sentence, whole-word <mark>, escaped then kses'd"
```

---

### Task 6: Wire it in, move the board row, document

**Files:**
- Modify: `signal-and-noise-tools.php` (after the `inc/ml-artifacts.php` require, ~line 504)
- Modify: `inc/maturity-roadmap-shortcode.php` (~line 306)
- Modify: `CHANGELOG.md`
- Test: `tests/maturity-roadmap-shortcode.php`, `tests/inc-population-guard.php`, `bash tests/run.sh`

- [ ] **Step 1: Require the module**

In `signal-and-noise-tools.php`, directly after the `require_once __DIR__ . '/inc/ml-artifacts.php';` line:

```php
require_once __DIR__ . '/inc/notes-search-ranking.php'; // v13.111.0: notes search ranked by the kernel (reads the search index ml-artifacts writes; shapes the theme's /notes/?s= query via posts_clauses; snippet via sn_notes_search_snippet)
```

- [ ] **Step 2: Board row: Planned → done**

In `inc/maturity-roadmap-shortcode.php`, the Machine learning family: remove the `'Search served by the kernel: …'` sentence from `'planned'` and add to the END of that family's `'done'` array:

```php
				__( 'Search served by the kernel: the notes search box ranks by the same BM25 arithmetic that picks related notes — any word the reader typed can match, the best answer comes first, and each ranked row shows the sentence that answered with the words marked. Deterministic corpus arithmetic, no model in the browser; pages still follow in date order', 'signal-and-noise-tools' ),
```

Run: `php tests/maturity-roadmap-shortcode.php | tail -1`
Expected: `0 failed` (the suite pins that a sentence is moved, not copied — the Planned copy must be gone).

- [ ] **Step 3: The population guard and the whole sweep**

Run: `php tests/inc-population-guard.php | tail -1 && bash tests/run.sh | tail -1`
Expected: guard `0 failed`; sweep `… 0 failed` apart from the two known `.claude/worktrees` path artifacts if run from a worktree (`admin-class-orphans`, `direct-access-guard-window`) — confirm those two pass from the main checkout.

- [ ] **Step 4: phpcs + phpstan on the touched files**

Run (from a checkout with `vendor/`): `vendor/bin/phpcs --standard=phpcs.xml.dist inc/notes-search-ranking.php inc/ml-kernel.php inc/ml-artifacts.php && vendor/bin/phpstan analyse --memory-limit=2G --no-progress inc/notes-search-ranking.php inc/ml-kernel.php inc/ml-artifacts.php`
Expected: no errors. If phpcs flags the `$_` reads or the SQL interpolation: the ids are int-cast and the table name comes from `$wpdb->posts`; add a `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are int-cast, table is $wpdb->posts` on the two interpolating lines rather than excluding the file.

- [ ] **Step 5: CHANGELOG**

Under `## [Unreleased]` add:

```markdown
### Added
- **Search served by the kernel.** `/notes/?s=` now ranks notes with the kernel's BM25 over a search index the corpus build writes (`snt_ml_search_index`: term frequencies + idf, `autoload = no`, stamped with the build's `built_at` so a half-written pair is refused). Any word the reader typed can match — WordPress's search required every word — and the best answer comes first; pages and notes only `LIKE` found follow in date order exactly as before. The plugin shapes the theme's existing query through `posts_clauses` when the theme flags it (`sn_notes_search`): the `WHERE` is widened to the ranked ids (re-scoped to publish + no-password, so widening never out-scopes), `ORDER BY` gets `FIELD()` in rank order ahead of the date. Each ranked row shows its evidence through `sn_notes_search_snippet`: the sentence holding the rarest query token, escaped, the words in `<mark>`. Plugin off → today's search, byte for byte. Board row Machine learning → done. Spec `docs/proposals/2026-09-11-notes-search-kernel-design.md`; `tests/notes-search-ranking.php` (40 pins, the acceptance fixture red without the flag), `tests/ml-kernel.php` (+4), `tests/ml-artifacts.php` (+7). **Needs theme ≥ 13.0.0 for the flag and the snippet; older themes see no change.**
```

- [ ] **Step 6: Commit**

```bash
git add signal-and-noise-tools.php inc/maturity-roadmap-shortcode.php CHANGELOG.md
git commit -m "feat(search): wire the kernel-ranked notes search; the board row graduates; changelog"
```

---

### Task 7: Theme — flag the query, render the snippet, style the mark

**Files (repo `signal-and-noise`):**
- Modify: `inc/notes-index-helpers.php` (in `sn_notes_query_posts()`, the `if ( '' !== $term )` block ~line 147)
- Modify: `inc/notes-index-row.php:43` and `:104-107`
- Modify: `assets/css/notes.css` (near `.sn-notes-row-excerpt-wrap`, ~line 414)
- Create: `tests/notes-search-query.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the failing test**

Create `tests/notes-search-query.php`:

```php
<?php
/**
 * Tests: the search query carries sn_notes_search, and a search row renders
 * the plugin's snippet (kses'd, mark only) while a browse row does not.
 * Companion to signal-and-noise-tools v13.111.0 (search served by the kernel).
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

// ── stubs (mirror tests/notes-index-row.php, plus the two this suite needs) ──
$GLOBALS['__filters'] = array(); $GLOBALS['__query_vars'] = array(); $GLOBALS['__get'] = array();
function apply_filters( $h, $v ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v, ...array_slice( func_get_args(), 2 ) ); } return $v; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ][] = $cb; return true; }
function add_action() { return true; }
function get_query_var( $k, $d = '' ) { return $GLOBALS['__query_vars'][ $k ] ?? $d; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function get_option( $k, $d = false ) { return $d; }
function get_post_meta( $id, $key, $single = false ) { return $single ? '' : array(); }
function get_the_tags( $id ) { return false; }
function get_the_excerpt( $p ) { return $p->excerpt ?? ''; }
function get_permalink( $p ) { return 'https://x.test/notes/' . ( $p->slug ?? 'x' ) . '/'; }
function get_the_title( $p ) { return $p->title ?? ''; }
function get_the_date( $fmt, $p ) { return gmdate( $fmt, strtotime( $p->post_date ) ); }
function get_the_time( $fmt, $p ) { return gmdate( $fmt, strtotime( $p->post_date ) ); }
function wp_date( $fmt, $ts ) { return gmdate( $fmt, (int) $ts ); }
function date_i18n( $f, $ts ) { return gmdate( $f, (int) $ts ); }
function number_format_i18n( $n ) { return (string) $n; }
function get_post_type( $p ) { return 'post'; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_kses( $s, $allowed ) { $keep = implode( '|', array_map( 'preg_quote', array_keys( (array) $allowed ) ) ); return preg_replace( '#</?(?!(?:' . $keep . ')\b)[a-z][^>]*>#i', '', (string) $s ); }
class WP_Query { public $args; public $posts = array(); public $found_posts = 0; public $max_num_pages = 0; public function __construct( $a ) { $this->args = $a; } public function have_posts() { return false; } }
$GLOBALS['__last_query'] = null;
// The helpers call `new WP_Query( $args )`; capture the args.
require_once __DIR__ . '/../inc/notes-index-helpers.php';
require_once __DIR__ . '/../inc/notes-index-row.php';

echo "Group: the query var\n";
$GLOBALS['__query_vars'] = array( 's' => 'ledger anchor' );
$q = sn_notes_query_posts();
ok( $q instanceof WP_Query && true === ( $q->args['sn_notes_search'] ?? null ), 'search mode sets sn_notes_search => true' );
ok( 'ledger anchor' === ( $q->args['s'] ?? '' ), 'and still passes s (the LIKE floor stays)' );
$GLOBALS['__query_vars'] = array();
$q2 = sn_notes_query_posts();
ok( ! array_key_exists( 'sn_notes_search', $q2->args ), 'browse mode does not set it' );

echo "\nGroup: the row\n";
$p = (object) array( 'ID' => 11, 'title' => 'T', 'slug' => 't', 'post_date' => '2026-05-01 00:00:00', 'excerpt' => 'Plain excerpt & co' );
add_filter( 'sn_notes_search_snippet', function ( $excerpt, $id, $term ) { return 'The <mark>ledger</mark> line <script>x</script>'; } );
$GLOBALS['__query_vars'] = array( 's' => 'ledger' );
ob_start(); sn_notes_render_row( $p ); $html = ob_get_clean();
ok( false !== strpos( $html, '<p class="sn-notes-row-excerpt">The <mark>ledger</mark> line x</p>' ), 'a search row renders the snippet with <mark> kept and every other tag dropped' );
$GLOBALS['__query_vars'] = array();
ob_start(); sn_notes_render_row( $p ); $html2 = ob_get_clean();
ok( false !== strpos( $html2, 'Plain excerpt &amp; co' ) && false === strpos( $html2, '<mark>' ), 'a browse row renders the escaped excerpt and never calls the snippet filter' );
$GLOBALS['__filters'] = array();
$GLOBALS['__query_vars'] = array( 's' => 'ledger' );
ob_start(); sn_notes_render_row( $p ); $html3 = ob_get_clean();
ok( false !== strpos( $html3, 'Plain excerpt &amp; co' ), 'with no plugin filter registered, a search row still renders the escaped excerpt (plugin-off parity)' );

echo "\nGroup: the style\n";
$css = (string) file_get_contents( __DIR__ . '/../assets/css/notes.css' );
ok( 1 === preg_match( '/\.sn-notes-row-excerpt\s+mark\s*\{/', $css ), 'notes.css styles mark inside the excerpt' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/notes-search-query.php | grep -E "FAIL|Result"`
Expected: FAIL on "sets sn_notes_search", "renders the snippet", "styles mark"; the rest pass (they describe today).

If `require` fatals on a stub the helpers need that this list lacks, copy the missing stub from `tests/notes-index-row.php` — do not change the helpers.

- [ ] **Step 3: Implement — the flag**

In `inc/notes-index-helpers.php`, inside `sn_notes_query_posts()`:

```php
	if ( '' !== $term ) {
		$args['s']         = $term;
		$args['post_type'] = array( 'post', 'page' );
		// v13.0.0: the companion plugin (>= 13.111.0) ranks NOTES with its
		// kernel when this flag is present, by shaping this same query through
		// posts_clauses; pages and LIKE-only notes keep their date order. With
		// the plugin off the var is inert and this is the query it always was.
		$args['sn_notes_search'] = true;
	}
```

- [ ] **Step 4: Implement — the row**

In `inc/notes-index-row.php`, replace `$excerpt = get_the_excerpt( $p );` with:

```php
	$excerpt = get_the_excerpt( $p );
	// v13.0.0: in search mode the plugin may answer with the sentence that
	// matched, query words in <mark>. Anything that is not literally the
	// excerpt came from the filter and is trusted for exactly one tag.
	$term    = function_exists( 'sn_notes_search_term' ) ? sn_notes_search_term() : '';
	$snippet = ( '' !== $term ) ? (string) apply_filters( 'sn_notes_search_snippet', $excerpt, (int) $p->ID, $term ) : $excerpt;
```

and replace the excerpt echo block with:

```php
	if ( $excerpt || $snippet ) {
		echo '<div class="sn-notes-row-excerpt-wrap">';
		if ( $snippet !== $excerpt ) {
			echo '<p class="sn-notes-row-excerpt">' . wp_kses( $snippet, array( 'mark' => array() ) ) . '</p>';
		} else {
			echo '<p class="sn-notes-row-excerpt">' . esc_html( wp_strip_all_tags( $excerpt ) ) . '</p>';
		}
		echo '</div>';
	}
```

- [ ] **Step 5: Implement — the style**

In `assets/css/notes.css`, after the `.sn-notes-row-excerpt-wrap` rules:

```css
/* v13.0.0: a search row's snippet marks the query words. Text colour, no
   background block — the row already has enough surfaces. */
.sn-notes-row-excerpt mark {
	background: transparent;
	color: var(--wp--preset--color--rust, inherit);
	font-weight: 600;
	text-decoration: underline;
	text-decoration-thickness: 1px;
	text-underline-offset: 0.15em;
}
```

- [ ] **Step 6: Run to verify it passes; run the sibling suites and the sweep**

Run: `php tests/notes-search-query.php | tail -1 && php tests/notes-index-row.php | tail -1 && php tests/notes-search.php | tail -1 && php tests/notes-index-helpers.php | tail -1 && bash tests/run.sh | tail -1`
Expected: all `0 failed`.

- [ ] **Step 7: CHANGELOG (theme)**

Under `## [Unreleased]`:

```markdown
### Added
- **Search results ranked by the plugin's kernel, each row showing its evidence.** `sn_notes_query_posts()` flags the search query with `sn_notes_search => true`; the companion plugin (≥ 13.111.0) ranks notes with BM25 through that flag and hands each ranked row the sentence that matched via `sn_notes_search_snippet`, query words in `<mark>` (kses'd to that one tag; a browse row and a plugin-off row still render the escaped excerpt). One `mark` rule in `notes.css`, text colour only. `tests/notes-search-query.php` (7 pins). Pages keep their date order.
```

- [ ] **Step 8: Commit**

```bash
git add inc/notes-index-helpers.php inc/notes-index-row.php assets/css/notes.css tests/notes-search-query.php CHANGELOG.md
git commit -m "feat(notes): search flags its query for the kernel and renders the plugin's evidence snippet"
```

---

### Task 8: Ship and verify live

- [ ] **Step 1: PRs** — plugin branch `notes-search-kernel` (Tasks 0–6) and theme branch `notes-search-kernel` (Task 7). Each PR body links the spec. Wait for green on a fresh `gh pr checks` re-read; squash-merge.

- [ ] **Step 2: Cut both** — this is the net-new arc: plugin `tools/cut-release.sh release "search served by the kernel"` → **14.0.0**; theme the same → **13.0.0**. Each cut is a PR (`docs/VERSIONING.md`); tag the squash commit; publish. Install via wp-admin → Updates, plugin first (the theme's flag is inert without it either way).

- [ ] **Step 3: Rebuild the index once** — the search index exists only after a corpus build. Trigger one: publish/unpublish transition, or `wp eval 'snt_ml_build_corpus();'`. Confirm: `wp option get snt_ml_search_index --format=json | head -c 200` shows `built_at` equal to `wp option get snt_ml_corpus_meta`.

- [ ] **Step 4: Live acceptance** — open `https://juanlentino.com/notes/?s=ledger%20anchor` (or whichever two words the live corpus makes the fixture — pick a pair where one note has only the rarer word). Expect: that note is in the results (it was not before), the best match is first, each ranked row shows a marked sentence, pages follow by date. Then `?s=the` → today's list, nothing marked. Then a stopword-free single word that appears in a page but no note → the page shows, unranked, as before.

- [ ] **Step 5: Purge and re-read** — the edge caches `/notes/`: purge-all-caches, reload, confirm the mark colour renders in the light palette.

---

## Self-review

**Spec coverage:** §1 ranking/recall → Tasks 3–4; §2 seam (flag, clauses, snippet filter, cap, filterability, plugin-off) → Tasks 3, 4, 7; §3 snippet (normaliser, rarest token, whole-word mark, kses, cap, fallback) → Task 5; §4 freshness/failure (stale/missing index → untouched; unpublished id excluded; cap) → Tasks 2, 3, 4; §5 tests (acceptance fixture red-then-green, ties, stopwords, missing/stale, OR scope, FIELD order, cap, int-cast, negative control, snippet pins, XSS pin, theme flag/row/style) → Tasks 3, 4, 5, 7; live check → Task 8. Board row → Task 6. Gap found and closed: the spec's "excerpt length the theme uses" is `apply_filters('excerpt_length', 55)` in Task 5.

**Placeholders:** none; every code step carries its code.

**Type consistency:** `snt_search_rank_notes( string, bool ) : int[]`; `snt_search_posts_clauses( array, object ) : array`; `snt_search_snippet( string, int, string ) : string`; `snt_ml_bm25_score_tf( string[], array<string,int>, int, array, float, float ) : float`; `snt_ml_search_index() : array|null`; option `snt_ml_search_index` shape identical in Task 2's writer, reader, and Task 3's `seed_index()`.
