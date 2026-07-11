<?php
/**
 * Signal & Noise Tools — AI-assisted unlinked-mention linking (v7.4.0).
 *
 * Suggest+Apply impls for the unlinked_mentions Health check
 * (sn_health_check_unlinked_mentions in inc/health-checks.php). Mirrors the
 * drift-phrase machinery exactly and REUSES its primitives:
 * snt_ai_drift_locate_in_raw() resolves the mention's RAW-content offset
 * (the v4.1.1 raw-vs-stripped lesson) and snt_ai_drift_fingerprint() gates
 * Apply against concurrent edits (409 on mismatch).
 *
 * Suggest is deliberately re-derived from (source_id, target_id) alone — it
 * never trusts scan-time data, so a stale finding self-heals into a 409
 * instead of a wrong splice. The AI verdict (link / skip / unsure) caches in
 * a transient keyed md5(source|target|source_post_modified); fingerprints
 * are always recomputed from CURRENT content, never cached.
 *
 * Abilities: signal-noise/ai-link-suggest + signal-noise/ai-link-apply
 * (inc/abilities-ai-health.php). Surface convention follows
 * inc/ai-drift-phrase-suggest.php.
 *
 * @package SignalNoiseTools
 * @since 7.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Paired with SNT_AI_DRIFT_SYSTEM's design split: detection is zero-AI
// (health-checks.php); this prompt only judges whether one located mention
// really refers to the target note.
const SNT_AI_LINK_SUGGEST_SYSTEM = "You are an editor deciding whether a mention of another note's title inside a post should become an internal link to that note.\n\n" .
	"Input JSON: { source_title, target_title, mention, context } — `mention` is the matched text inside the source post; `context` is ~200 chars around it.\n\n" .
	"Return ONLY a JSON object: {\"verdict\": \"link\" | \"skip\" | \"unsure\", \"reason\": \"<one sentence>\"}\n\n" .
	"Rules:\n" .
	"- \"link\" only when the mention clearly refers to the target note itself (its subject, or the essay by that name) and a link would help the reader.\n" .
	"- \"skip\" when the words merely coincide with the title (generic phrase), the mention sits inside quoted third-party text, or a link would read as noise.\n" .
	"- \"unsure\" when the context is too thin to judge.\n" .
	"- Output JSON only. No markdown, no preamble.";

// v8.4.1: 120 → 200. A long two-field reason could still clip at 120; the
// parser's truncation salvage (below) makes a clipped response survivable,
// but headroom keeps reasons intact in the first place.
const SNT_AI_LINK_SUGGEST_MAX_TOKENS = 200;
const SNT_AI_LINK_ANCHOR_MAX_LENGTH  = 300;

// v8.4.1: durable verdict memory — verdicts were 30-day TRANSIENTS, and the
// v10.22.0 auto-purges flushed them on every release, resurrecting judged
// pairs ("persistent entries") and re-billing the AI.
// v8.4.3: one option row PER VERDICT. v8.4.1 consolidated verdicts into a
// single map option, which reintroduced a race the transient era never
// had: Suggest All fires OVERLAPPING requests (the JS throttle is 500ms,
// the AI calls take seconds), and concurrent read-modify-writes of one
// shared map lost each other's entries (live: 10 → 8 → 6 findings
// resurrecting across scan cycles — last-writer-wins). Per-row writes have
// no shared state to clobber. The verdict key IS the option name
// (sn_link_verdict_… / sn_pair_verdict_…, autoload=no); the purge chain's
// transient sweep only matches _transient_-prefixed rows, so these survive
// every flush. Age/cap pruning walks the rows on write (the corpus is a
// few dozen rows; no cron needed).
const SNT_AI_VERDICT_STORE_OPT     = 'sn_ai_link_verdicts'; // legacy v8.4.1 map — migrated + dropped by the prune
const SNT_AI_VERDICT_STORE_CAP     = 300;
const SNT_AI_VERDICT_STORE_MAX_AGE = 180 * DAY_IN_SECONDS;

/**
 * Read one stored verdict (its own option row since v8.4.3).
 *
 * @param string $key Full verdict key (sn_link_verdict_… / sn_pair_verdict_…).
 * @return array|null
 *
 * @since 8.4.1
 */
function snt_ai_verdict_store_get( $key ) {
	$row = get_option( $key, null );
	return is_array( $row ) ? $row : null;
}

/**
 * Write one verdict into ITS OWN option row (autoload=no), stamping ts,
 * then prune. The per-row shape is the concurrency contract: overlapping
 * Suggest All requests each write a different row and can never clobber
 * each other (the v8.4.3 fix).
 *
 * @param string $key  Full verdict key.
 * @param array  $data Verdict payload (verdict/reason/anchor…).
 *
 * @since 8.4.1 (map), 8.4.3 (per-row)
 */
function snt_ai_verdict_store_set( $key, $data ) {
	$data['ts'] = time();
	update_option( $key, $data, false ); // own row, durable, autoload=no
	snt_ai_verdict_store_prune();
}

/**
 * Prune the verdict rows: migrate any v8.4.1 map remnant into rows first
 * (existing rows win, then the map is dropped), then delete rows past the
 * max age, then evict the oldest beyond the cap. Concurrent pruning is
 * harmless — deletes are idempotent, and a rare over-eviction just means
 * one future re-suggest.
 *
 * @since 8.4.3
 */
function snt_ai_verdict_store_prune() {
	global $wpdb;
	// is_callable, not method_exists — the repo rule for db handles.
	if ( ! $wpdb || ! is_callable( array( $wpdb, 'get_results' ) ) ) {
		return;
	}

	// One-time v8.4.1 → v8.4.3 migration.
	$legacy = get_option( SNT_AI_VERDICT_STORE_OPT, null );
	if ( is_array( $legacy ) ) {
		foreach ( $legacy as $k => $entry ) {
			if ( is_string( $k ) && is_array( $entry ) && null === get_option( $k, null ) ) {
				update_option( $k, $entry, false );
			}
		}
		delete_option( SNT_AI_VERDICT_STORE_OPT );
	}

	$rows = $wpdb->get_results(
		"SELECT option_name, option_value FROM {$wpdb->options}
		 WHERE option_name LIKE 'sn\\_link\\_verdict\\_%'
		    OR option_name LIKE 'sn\\_pair\\_verdict\\_%'"
	);
	if ( ! is_array( $rows ) ) {
		return;
	}

	$cutoff = time() - SNT_AI_VERDICT_STORE_MAX_AGE;
	$live   = array();
	foreach ( $rows as $row ) {
		$value = maybe_unserialize( $row->option_value );
		$ts    = is_array( $value ) ? (int) ( $value['ts'] ?? 0 ) : 0;
		if ( $ts < $cutoff ) {
			delete_option( $row->option_name );
			continue;
		}
		$live[ $row->option_name ] = $ts;
	}
	if ( count( $live ) > SNT_AI_VERDICT_STORE_CAP ) {
		asort( $live ); // oldest first
		$evict = count( $live ) - SNT_AI_VERDICT_STORE_CAP;
		foreach ( array_slice( array_keys( $live ), 0, $evict ) as $name ) {
			delete_option( $name );
		}
	}
}

/**
 * Canonical verdict keys: ID-only since v8.4.5. The modified stamps used to
 * be part of the key, which orphaned every sibling verdict the moment
 * Apply's own wp_update_post bumped the source's post_modified — judged
 * pairs resurrected on the next scan and re-billed on the next Suggest (the
 * owner's "still doing the same" on v8.4.4). The stamps now live INSIDE the
 * payload (src_mod / tgt_mod, with src_id / tgt_id for the restamp sweep);
 * lookups compare them there, so the invalidation semantics are unchanged
 * while the rows survive our own applies.
 *
 * @param int $src_id Source post id.
 * @param int $tgt_id Target post id.
 * @return string Option name.
 *
 * @since 8.4.5
 */
function snt_ai_pair_verdict_key( $src_id, $tgt_id ) {
	return 'sn_pair_verdict_' . md5( (int) $src_id . '|' . (int) $tgt_id );
}

/**
 * Mention-verdict key twin of snt_ai_pair_verdict_key().
 *
 * @param int $src_id Source post id.
 * @param int $tgt_id Target post id.
 * @return string Option name.
 *
 * @since 8.4.5
 */
function snt_ai_link_verdict_key( $src_id, $tgt_id ) {
	return 'sn_link_verdict_' . md5( (int) $src_id . '|' . (int) $tgt_id );
}

/**
 * Look up a PAIR verdict for the current content generation: the ID-keyed
 * row suppresses only while BOTH payload stamps match. Falls back to the
 * pre-v8.4.5 stamp-embedded key (findable exactly while the stamps still
 * match, same semantics it always had) and migrates it forward on hit so a
 * pre-upgrade judgment is never re-billed.
 *
 * @param int    $src_id  Source post id.
 * @param int    $tgt_id  Target post id.
 * @param string $src_mod Source post_modified_gmt.
 * @param string $tgt_mod Target post_modified_gmt.
 * @return array|null Verdict payload, or null when unjudged / stale.
 *
 * @since 8.4.5
 */
function snt_ai_verdict_lookup_pair( $src_id, $tgt_id, $src_mod, $tgt_mod ) {
	$src_id  = (int) $src_id;
	$tgt_id  = (int) $tgt_id;
	$src_mod = (string) $src_mod;
	$tgt_mod = (string) $tgt_mod;

	$row = snt_ai_verdict_store_get( snt_ai_pair_verdict_key( $src_id, $tgt_id ) );
	if ( is_array( $row ) ) {
		return ( (string) ( $row['src_mod'] ?? '' ) === $src_mod && (string) ( $row['tgt_mod'] ?? '' ) === $tgt_mod ) ? $row : null;
	}

	$legacy_key = 'sn_pair_verdict_' . md5( $src_id . '|' . $tgt_id . '|' . $src_mod . '|' . $tgt_mod );
	$legacy     = snt_ai_verdict_store_get( $legacy_key );
	if ( ! is_array( $legacy ) ) {
		return null;
	}
	$legacy['src_id']  = $src_id;
	$legacy['tgt_id']  = $tgt_id;
	$legacy['src_mod'] = $src_mod;
	$legacy['tgt_mod'] = $tgt_mod;
	update_option( snt_ai_pair_verdict_key( $src_id, $tgt_id ), $legacy, false );
	delete_option( $legacy_key );
	return $legacy;
}

/**
 * Mention-verdict twin of snt_ai_verdict_lookup_pair() — single stamp (the
 * mentions check never needed the target's).
 *
 * @param int    $src_id  Source post id.
 * @param int    $tgt_id  Target post id.
 * @param string $src_mod Source post_modified_gmt.
 * @return array|null Verdict payload, or null when unjudged / stale.
 *
 * @since 8.4.5
 */
function snt_ai_verdict_lookup_link( $src_id, $tgt_id, $src_mod ) {
	$src_id  = (int) $src_id;
	$tgt_id  = (int) $tgt_id;
	$src_mod = (string) $src_mod;

	$row = snt_ai_verdict_store_get( snt_ai_link_verdict_key( $src_id, $tgt_id ) );
	if ( is_array( $row ) ) {
		return ( (string) ( $row['src_mod'] ?? '' ) === $src_mod ) ? $row : null;
	}

	$legacy_key = 'sn_link_verdict_' . md5( $src_id . '|' . $tgt_id . '|' . $src_mod );
	$legacy     = snt_ai_verdict_store_get( $legacy_key );
	if ( ! is_array( $legacy ) ) {
		return null;
	}
	$legacy['src_id']  = $src_id;
	$legacy['tgt_id']  = $tgt_id;
	$legacy['src_mod'] = $src_mod;
	update_option( snt_ai_link_verdict_key( $src_id, $tgt_id ), $legacy, false );
	delete_option( $legacy_key );
	return $legacy;
}

/**
 * Restamp every verdict row involving $post_id to its post-apply modified
 * stamp. Called ONLY from snt_ai_link_apply_impl after its own
 * wp_update_post: our splice is not an owner edit, so the AI's judgments of
 * this post's OTHER pairs still stand. A real editor save never restamps,
 * so it still re-nominates everything for the post. Legacy rows carry no
 * ids and are skipped (they orphan on apply exactly as before v8.4.5).
 * The ts is deliberately preserved — restamping is not a fresh judgment.
 *
 * @param int    $post_id      The post Apply just wrote.
 * @param string $new_modified Its post_modified_gmt after the write.
 *
 * @since 8.4.5
 */
function snt_ai_verdict_store_restamp( $post_id, $new_modified ) {
	global $wpdb;
	// is_callable, not method_exists — the repo rule for db handles.
	if ( ! $wpdb || ! is_callable( array( $wpdb, 'get_results' ) ) ) {
		return;
	}
	$post_id      = (int) $post_id;
	$new_modified = (string) $new_modified;

	$rows = $wpdb->get_results(
		"SELECT option_name, option_value FROM {$wpdb->options}
		 WHERE option_name LIKE 'sn\\_link\\_verdict\\_%'
		    OR option_name LIKE 'sn\\_pair\\_verdict\\_%'"
	);
	if ( ! is_array( $rows ) ) {
		return;
	}
	foreach ( $rows as $row ) {
		$value = maybe_unserialize( $row->option_value );
		if ( ! is_array( $value ) ) {
			continue;
		}
		$dirty = false;
		if ( isset( $value['src_mod'] ) && (int) ( $value['src_id'] ?? 0 ) === $post_id ) {
			$value['src_mod'] = $new_modified;
			$dirty            = true;
		}
		if ( isset( $value['tgt_mod'] ) && (int) ( $value['tgt_id'] ?? 0 ) === $post_id ) {
			$value['tgt_mod'] = $new_modified;
			$dirty            = true;
		}
		if ( $dirty ) {
			update_option( $row->option_name, $value, false );
		}
	}
}

/**
 * Pure impl: AI verdict + splice coordinates for linking one unlinked mention.
 *
 * @param int $source_id Post that contains the mention.
 * @param int $target_id Post being mentioned.
 * @return array{ok:bool,verdict:string,reason:string,anchor:string,position:int,context_snippet:string,fingerprint:string,post_id:int,target_id:int,target_url:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_unavailable         (gate)
 *   snt_ai_post_not_found      (404) — source or target missing / target unpublished
 *   snt_ai_link_invalid        (422) — source == target
 *   snt_ai_link_already_linked (409) — source already links the target (stale finding)
 *   snt_ai_mention_drifted     (409) — mention no longer present
 *   snt_ai_runtime_error       (500) — payload encode / unparseable AI verdict
 *
 * @since 7.4.0
 */
function snt_ai_link_suggest_impl( $source_id, $target_id ) {
	$gate = snt_ai_require_text_generation();
	if ( $gate ) {
		return $gate;
	}

	$source_id = (int) $source_id;
	$target_id = (int) $target_id;
	if ( $source_id === $target_id ) {
		return new WP_Error( 'snt_ai_link_invalid', __( 'Source and target are the same post.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$source = get_post( $source_id );
	$target = get_post( $target_id );
	if ( ! $source || ! $target || 'publish' !== $target->post_status ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Source or target post not found (target must be published).', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$raw = (string) $source->post_content;

	// Stale-finding guard: the link may have been added since the scan.
	if ( sn_health_contains_note_link( $raw, (string) $target->post_name ) ) {
		return new WP_Error( 'snt_ai_link_already_linked', __( 'The source already links to this note. Re-run the scan to refresh.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	// Re-derive the mention from CURRENT content (never trust scan data).
	$stripped = wp_strip_all_tags( strip_shortcodes( $raw ) );
	$title    = trim( (string) $target->post_title );
	$pos      = ( '' !== $title ) ? stripos( $stripped, $title ) : false;
	if ( false === $pos ) {
		return new WP_Error( 'snt_ai_mention_drifted', __( 'Mention no longer present in post content — post was edited since the scan. Re-run the scan to refresh.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}
	$mention = substr( $stripped, $pos, strlen( $title ) );
	$start   = max( 0, $pos - 80 );
	$context = trim( substr( $stripped, $start, 200 ) );

	// RAW-content offset: the stripped offset cannot be used against raw
	// content for any post with block markup (drift v4.1.1 lesson).
	$raw_position = snt_ai_drift_locate_in_raw( $raw, $mention, $context );
	if ( -1 === $raw_position ) {
		// Mention exists in prose but not contiguously in raw content (split
		// by inline markup) — Apply could not splice it safely.
		return new WP_Error( 'snt_ai_mention_drifted', __( 'Mention is split by inline markup — link it manually in the editor.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	// v8.1.1: the mention can sit inside an EXISTING <a> to a third note —
	// the AI judges stripped prose (links invisible), and apply refuses (400)
	// anchors inside links. Run apply's own guard here so the UI never offers
	// a "Link it" that is guaranteed to fail; the verdict still renders as
	// advice-only (empty anchor, can_apply=false).
	$inside_anchor = snt_ai_link_position_inside_anchor( $raw, $raw_position );

	// Verdict memory: ID-keyed row, source stamp compared in the payload
	// (v8.4.5 — stamp-keyed rows orphaned on every Apply). Content change =
	// stamp mismatch = cache miss, so fingerprints stay honest below.
	// Durable store since v8.4.1 (transients died on every purge).
	$cached = snt_ai_verdict_lookup_link( $source_id, $target_id, (string) $source->post_modified_gmt );
	if ( is_array( $cached ) && isset( $cached['verdict'], $cached['reason'] ) ) {
		$verdict = (string) $cached['verdict'];
		$reason  = (string) $cached['reason'];
	} else {
		$payload = array(
			'source_title' => (string) $source->post_title,
			'target_title' => $title,
			'mention'      => $mention,
			'context'      => $context,
		);
		$prompt = wp_json_encode( $payload );
		if ( false === $prompt ) {
			return new WP_Error( 'snt_ai_runtime_error', __( 'Failed to encode AI payload.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
		}

		$result = snt_ai_generate_with_constraints( $prompt, SNT_AI_LINK_SUGGEST_SYSTEM, SNT_AI_LINK_SUGGEST_MAX_TOKENS, 'link_suggest' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = snt_ai_parse_verdict_json( (string) $result );
		if ( ! is_array( $parsed ) || ! isset( $parsed['verdict'] ) ) {
			return new WP_Error( 'snt_ai_runtime_error', __( 'AI returned an unparseable verdict.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
		}
		$verdict = in_array( (string) $parsed['verdict'], array( 'link', 'skip', 'unsure' ), true ) ? (string) $parsed['verdict'] : 'unsure';
		$reason  = (string) ( $parsed['reason'] ?? '' );

		snt_ai_verdict_store_set( snt_ai_link_verdict_key( $source_id, $target_id ), array(
			'verdict' => $verdict,
			'reason'  => $reason,
			'src_id'  => $source_id,
			'tgt_id'  => $target_id,
			'src_mod' => (string) $source->post_modified_gmt,
		) );
	}

	// v8.1.1: inside-anchor mentions degrade the splice contract to
	// advice-only — verdict and reason stand, Apply is never offered.
	return array(
		'ok'              => true,
		'verdict'         => $verdict,
		'reason'          => $reason,
		'anchor'          => $inside_anchor ? '' : $mention,
		'can_apply'       => ! $inside_anchor,
		'position'        => $inside_anchor ? -1 : $raw_position,
		'context_snippet' => $inside_anchor ? '' : $context,
		'fingerprint'     => $inside_anchor ? '' : snt_ai_drift_fingerprint( $raw, $mention, $raw_position ),
		'post_id'         => $source_id,
		'target_id'       => $target_id,
		'target_url'      => (string) get_permalink( $target ),
	);
}

/**
 * Parse an AI verdict response into an array, defensively.
 *
 * v8.1.1, shared by ai-link-suggest + ai-pair-suggest. Handles the model
 * output shapes seen live: markdown fences (opener and/or closer,
 * independently) and prose preambles/trailers around the JSON object (the
 * outermost brace span is retried before giving up). An unparseable
 * response logs its head so the NEXT occurrence is diagnosable from the
 * error log — the 2026-07-02 live "unparseable verdict" left no evidence.
 *
 * @param string $text Raw model output.
 * @return array|null Decoded array, or null when nothing parses.
 *
 * @since 8.1.1
 */
function snt_ai_parse_verdict_json( $text ) {
	$text   = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( (string) $text ) ) );
	$parsed = json_decode( $text, true );
	if ( is_array( $parsed ) ) {
		return $parsed;
	}
	$start = strpos( $text, '{' );
	$end   = strrpos( $text, '}' );
	if ( false !== $start && false !== $end && $end > $start ) {
		$parsed = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		if ( is_array( $parsed ) ) {
			return $parsed;
		}
	}
	// Truncation salvage (v8.4.1): a response clipped by the token budget
	// has no closing brace, so neither decode above can save it — the live
	// "persistent unparseable verdict" class. verdict is the FIRST field by
	// prompt design, so a field-level regex still rescues the decision;
	// reason/anchor are taken only when their strings CLOSED (a half string
	// is dropped, and a link verdict without an anchor already degrades to
	// advice-only downstream by contract).
	if ( preg_match( '/"verdict"\s*:\s*"(link|skip|unsure)"/', $text, $m ) ) {
		$out = array( 'verdict' => $m[1] );
		if ( preg_match( '/"reason"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*[,}]/', $text, $m2 ) ) {
			$out['reason'] = stripcslashes( $m2[1] );
		}
		if ( preg_match( '/"anchor"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*[,}]/', $text, $m3 ) ) {
			$out['anchor'] = stripcslashes( $m3[1] );
		}
		return $out;
	}
	error_log( 'snt_ai verdict unparseable (head): ' . substr( $text, 0, 200 ) );
	return null;
}

/**
 * Whether $position in $content sits inside an existing <a>…</a> element.
 *
 * Heuristic on the text BEFORE the position: the last '<a' tag-open (the
 * tag name must END right after — '<a' alone would also match <abbr>,
 * <aside>, <article>, <audio>) vs the last '</a>'. Open after close =
 * inside a link.
 *
 * @param string $content  Raw post_content.
 * @param int    $position Byte offset.
 * @return bool
 *
 * @since 7.4.0
 */
function snt_ai_link_position_inside_anchor( $content, $position ) {
	$before    = substr( (string) $content, 0, max( 0, (int) $position ) );
	$last_open = -1;
	if ( preg_match_all( '#<a[\s>]#i', $before, $m, PREG_OFFSET_CAPTURE ) ) {
		$hit       = end( $m[0] );
		$last_open = (int) $hit[1];
	}
	$close      = strripos( $before, '</a>' );
	$last_close = ( false === $close ) ? -1 : (int) $close;
	return $last_open > $last_close;
}

/**
 * Apply: wrap the mention in <a href="target_url">anchor</a>.
 *
 * Same contract as snt_ai_drift_apply_impl: raw position re-resolved via the
 * context snippet, fingerprint validated at the resolved position (409 on
 * mismatch), write via wp_update_post() with wp_error. Additional guards:
 * the anchor must not already sit inside an <a> (400), and target_url must
 * be same-host (422 — this feature only creates internal links).
 *
 * @param int    $post_id         Source post.
 * @param string $anchor          Mention text exactly as it appears in raw content.
 * @param string $context_snippet ~200 stripped chars around the mention (disambiguates occurrences).
 * @param string $fingerprint     md5 from the matching suggest call.
 * @param string $target_url      Permalink to link to (same-host required).
 * @return array{ok:bool,post_id:int,anchor:string,target_url:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_ai_anchor_invalid      (422) — empty / over-long anchor
 *   snt_ai_link_target_invalid (422) — target_url not same-host
 *   snt_ai_capability          (403)
 *   snt_ai_post_not_found      (404)
 *   snt_ai_apply_conflict      (409) — anchor gone OR fingerprint mismatch
 *   snt_ai_link_already_linked (400) — anchor already inside an <a>
 *   snt_ai_write_failed        (500)
 *
 * @since 7.4.0
 */
function snt_ai_link_apply_impl( $post_id, $anchor, $context_snippet, $fingerprint, $target_url ) {
	$post_id         = (int) $post_id;
	$anchor          = (string) $anchor; // NOT trimmed — must match content bytes.
	$context_snippet = (string) $context_snippet;
	$fingerprint     = (string) $fingerprint;
	$target_url      = (string) $target_url;

	if ( '' === $anchor || strlen( $anchor ) > SNT_AI_LINK_ANCHOR_MAX_LENGTH ) {
		return new WP_Error( 'snt_ai_anchor_invalid', __( 'Anchor is empty or too long.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$target_host = wp_parse_url( $target_url, PHP_URL_HOST );
	$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $target_host || ! $site_host || strtolower( (string) $target_host ) !== strtolower( (string) $site_host ) ) {
		return new WP_Error( 'snt_ai_link_target_invalid', __( 'Target URL must be an internal permalink.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'snt_ai_capability', __( 'You cannot edit this post.', 'signal-and-noise-tools' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_ai_post_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}

	$current_content = (string) $post->post_content;

	$raw_position = snt_ai_drift_locate_in_raw( $current_content, $anchor, $context_snippet );
	if ( -1 === $raw_position ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Mention no longer present in post content. Re-run scan.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	$current_fp = snt_ai_drift_fingerprint( $current_content, $anchor, $raw_position );
	if ( $current_fp !== $fingerprint ) {
		return new WP_Error( 'snt_ai_apply_conflict', __( 'Post changed since suggest. Re-run scan to refresh.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}

	if ( snt_ai_link_position_inside_anchor( $current_content, $raw_position ) ) {
		return new WP_Error( 'snt_ai_link_already_linked', __( 'The mention already sits inside a link.', 'signal-and-noise-tools' ), array( 'status' => 400 ) );
	}

	$link        = '<a href="' . esc_url( $target_url ) . '">' . $anchor . '</a>';
	$new_content = substr_replace( $current_content, $link, $raw_position, strlen( $anchor ) );

	$result = wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => $new_content,
	), true );

	if ( is_wp_error( $result ) ) {
		/* translators: %s is the error message from wp_update_post() */
		return new WP_Error( 'snt_ai_write_failed', sprintf( __( 'wp_update_post failed: %s', 'signal-and-noise-tools' ), $result->get_error_message() ), array( 'status' => 500 ) );
	}

	// v8.4.5: OUR write just bumped this post's modified stamp — restamp its
	// verdict rows so the judged siblings stay judged. Without this, one
	// Apply resurrected every other judged pair on the source (and re-billed
	// them), which is the owner-reported treadmill v8.4.4 did not close.
	$updated = get_post( $post_id );
	if ( $updated ) {
		snt_ai_verdict_store_restamp( $post_id, (string) $updated->post_modified_gmt );
	}

	return array(
		'ok'         => true,
		'post_id'    => $post_id,
		'anchor'     => $anchor,
		'target_url' => $target_url,
	);
}
