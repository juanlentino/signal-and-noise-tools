<?php
/**
 * Signal & Noise Tools — corpus-integrity scan.
 *
 * Three independent, deterministic, read-only checks over post bodies —
 * zero AI, zero writes. Built 2026-08-14 after a hand audit found four
 * published Notes carrying content-level corruption that every structural
 * dashboard read as healthy (a mid-sentence paste splice live since April,
 * a duplicated half-draft, contradictory event dates on a backdated post):
 *
 *   (a) intra_post_duplication  Near-duplicate paragraph/heading pairs
 *                               within one post. similar_text ratio >
 *                               SNT_CORPUS_INTEGRITY_DUP_THRESHOLD, both
 *                               sides >= the 40-char floor.
 *   (b) splice_artifact         /[a-z]{2}\.[a-z]{3,}/ in body prose — a
 *                               lowercase word fused to a period with no
 *                               space (the 1495 signature). Domains, file
 *                               names, tokens with digits, inline <code>,
 *                               and wp:html / wp:code blocks are excluded.
 *   (c) date_coherence          An in-body date LATER than post_date.
 *                               WARNING when the sentence carries a
 *                               past-tense event verb (the backdating
 *                               signature: "announced ... June 17" on a
 *                               May-dated post), INFO otherwise (notes
 *                               legitimately cite future regulation
 *                               dates). The spec'd second check — two
 *                               dates for one named event — is deliberately
 *                               NOT shipped: naming "the same event" needs
 *                               entity resolution, and every cheap proxy
 *                               (two dates near one proper noun) flags any
 *                               legitimate timeline narration. Check 1
 *                               already catches the observed class.
 *
 * Every finding is severity warning or info, NEVER error — these are
 * judgement calls for the owner, and the correction path for a published
 * Note is a signed supersede, not a silent fix.
 *
 * Candidates carry the block-fingerprint shape the sibling scans use
 * (post+path-bound, v11.4.0 scheme) so a future sn-apply consumer can
 * address the exact block. Walks publish, future, draft AND pending —
 * corruption is cheapest to catch before publish makes it canonical.
 *
 * Mirrors inc/block-migrations-detect.php structurally: pure compute() +
 * run_scan() (compute + user-scoped 1-hour transient) + last_scan().
 *
 * @package SignalNoiseTools
 * @since 11.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_CORPUS_INTEGRITY_TRANSIENT_TTL = HOUR_IN_SECONDS;
const SNT_CORPUS_INTEGRITY_DUP_THRESHOLD = 0.80;
const SNT_CORPUS_INTEGRITY_DUP_MIN_CHARS = 40;
const SNT_CORPUS_INTEGRITY_DISMISS_META  = '_snt_corpus_integrity_dismissed';

/**
 * Prose block types the checks read. wp:html / wp:code / everything else
 * is excluded by construction — SVG text, code samples and embeds are not
 * prose and every check here reasons about prose.
 */
const SNT_CORPUS_INTEGRITY_PROSE_BLOCKS = array( 'core/paragraph', 'core/heading' );

/**
 * Walk one post's block tree collecting prose blocks as
 * { path, block, text } rows. Inline <code> spans are removed BEFORE tag
 * stripping so code identifiers (options.reload) never reach the splice
 * regex; whitespace is collapsed.
 *
 * @param array  $tree
 * @param string $path_prefix
 * @param array  $rows Accumulator (by ref).
 * @return void
 */
function snt_corpus_integrity_collect_prose( $tree, $path_prefix, &$rows ) {
	foreach ( $tree as $idx => $block ) {
		$name = $block['blockName'] ?? '';
		if ( in_array( $name, SNT_CORPUS_INTEGRITY_PROSE_BLOCKS, true ) ) {
			$html = (string) ( $block['innerHTML'] ?? '' );
			$html = preg_replace( '#<code\b[^>]*>.*?</code>#is', ' ', $html );
			$text = wp_strip_all_tags( $html );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
			if ( '' !== $text ) {
				$rows[] = array(
					'path'  => $path_prefix . '/' . $idx,
					'block' => $block,
					'text'  => $text,
				);
			}
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			snt_corpus_integrity_collect_prose( $block['innerBlocks'], $path_prefix . '/' . $idx . '/innerBlocks', $rows );
		}
	}
}

/**
 * The sentence around byte offset $pos in $text, capped for display.
 *
 * @param string $text
 * @param int    $pos
 * @return string
 */
function snt_corpus_integrity_sentence_at( $text, $pos ) {
	$start = 0;
	if ( preg_match_all( '/[.!?]\s+/', substr( $text, 0, $pos ), $m, PREG_OFFSET_CAPTURE ) ) {
		$last  = end( $m[0] );
		$start = $last[1] + strlen( $last[0] );
	}
	$end = strlen( $text );
	if ( preg_match( '/[.!?](\s|$)/', $text, $m, PREG_OFFSET_CAPTURE, min( $pos + 1, strlen( $text ) ) ) ) {
		$end = $m[0][1] + 1;
	}
	$sentence = trim( substr( $text, $start, $end - $start ) );
	return ( strlen( $sentence ) > 280 ) ? substr( $sentence, 0, 277 ) . '...' : $sentence;
}

/**
 * Check (a): near-duplicate prose pairs within one post.
 *
 * similar_text ratio (2*common/(lenA+lenB), the same family of measure as
 * difflib.SequenceMatcher) with a length pre-filter: a pair whose best
 * POSSIBLE ratio (2*min/(min+max)) cannot clear the threshold is skipped
 * unmeasured. Compared text is capped at 3000 chars per side.
 *
 * @param array $rows Prose rows from snt_corpus_integrity_collect_prose().
 * @return array<int,array{path_a:string,path_b:string,block_a:array,block_b:array,text_a:string,text_b:string,ratio:float}>
 */
function snt_corpus_integrity_find_duplicates( $rows ) {
	$eligible = array_values( array_filter( $rows, static function ( $r ) {
		return strlen( $r['text'] ) >= SNT_CORPUS_INTEGRITY_DUP_MIN_CHARS;
	} ) );

	$found = array();
	$n     = count( $eligible );
	for ( $i = 0; $i < $n; $i++ ) {
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$a = substr( $eligible[ $i ]['text'], 0, 3000 );
			$b = substr( $eligible[ $j ]['text'], 0, 3000 );
			$min = min( strlen( $a ), strlen( $b ) );
			$max = max( strlen( $a ), strlen( $b ) );
			if ( ( 2 * $min ) / ( $min + $max ) <= SNT_CORPUS_INTEGRITY_DUP_THRESHOLD ) {
				continue; // best possible ratio cannot clear the bar
			}
			similar_text( $a, $b, $pct );
			$ratio = round( $pct / 100, 4 );
			if ( $ratio > SNT_CORPUS_INTEGRITY_DUP_THRESHOLD ) {
				$found[] = array(
					'path_a'  => $eligible[ $i ]['path'],
					'path_b'  => $eligible[ $j ]['path'],
					'block_a' => $eligible[ $i ]['block'],
					'block_b' => $eligible[ $j ]['block'],
					'text_a'  => substr( $eligible[ $i ]['text'], 0, 300 ),
					'text_b'  => substr( $eligible[ $j ]['text'], 0, 300 ),
					'ratio'   => $ratio,
				);
			}
		}
	}
	return $found;
}

/**
 * Check (b): splice artifacts. Returns matches with sentence context;
 * the exclusions run on the fused TOKEN (the run of non-space characters
 * around the match): URLs, domain-shaped tokens, file extensions, and
 * anything carrying a digit (versions, hosts) are not prose splices.
 *
 * @param string $text Normalized prose (inline code already removed).
 * @return array<int,array{pos:int,token:string,sentence:string}>
 */
function snt_corpus_integrity_find_splices( $text ) {
	$out = array();
	if ( ! preg_match_all( '/[a-z]{2}\.[a-z]{3,}/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
		return $out;
	}
	foreach ( $m[0] as $hit ) {
		$pos = (int) $hit[1];
		// Expand to the whole whitespace-delimited token around the match.
		$tok_start = $pos;
		while ( $tok_start > 0 && ! ctype_space( $text[ $tok_start - 1 ] ) ) {
			$tok_start--;
		}
		$tok_end = $pos + strlen( $hit[0] );
		$len     = strlen( $text );
		while ( $tok_end < $len && ! ctype_space( $text[ $tok_end ] ) ) {
			$tok_end++;
		}
		$token = substr( $text, $tok_start, $tok_end - $tok_start );
		$bare  = rtrim( $token, '.,;:!?)"\'' );

		if ( preg_match( '/\d/', $bare ) ) {
			continue; // versions, hosts, filenames with digits
		}
		if ( preg_match( '#^(?:https?://|www\.)#i', $bare ) ) {
			continue; // URLs
		}
		// Domain-shaped: dot-separated labels ending in a known TLD.
		if ( preg_match( '/^[a-z][a-z0-9.-]*\.(?:com|org|net|edu|gov|int|mil|io|dev|app|fm|co|uk|de|fr|es|ai|ly|me|tv|info|xyz|site|online)$/i', $bare ) ) {
			continue;
		}
		// File-shaped: known extension after the last dot.
		if ( preg_match( '/\.(?:php|css|js|mjs|ts|json|html|htm|xml|yml|yaml|txt|md|ini|conf|sql|csv|tsv|zip|tar|gz|jpg|jpeg|png|gif|svg|webp|avif|ico|mp3|mp4|wav|flac|ogg|pdf|woff|woff2|exe)$/i', $bare ) ) {
			continue;
		}
		$out[] = array(
			'pos'      => $pos,
			'token'    => $hit[0],
			'sentence' => snt_corpus_integrity_sentence_at( $text, $pos ),
		);
	}
	return $out;
}

/**
 * Check (c): in-body dates later than post_date.
 *
 * Recognized shapes: "June 17, 2026" (day optional ordinal), "June 2026",
 * "2026-06-17". Month-only dates compare at first-of-month. A finding is
 * WARNING when its sentence carries a past-tense event verb — the exact
 * signature of a backdated post narrating a later event as history — and
 * INFO otherwise (future regulation/effective dates are normal prose).
 *
 * @param string $text      Normalized prose.
 * @param string $post_date Y-m-d (or full MySQL datetime) of the post.
 * @return array<int,array{found_date:string,sentence:string,severity:string,pos:int}>
 */
function snt_corpus_integrity_find_date_issues( $text, $post_date ) {
	$post_ts = strtotime( substr( (string) $post_date, 0, 10 ) . ' 23:59:59' );
	if ( false === $post_ts ) {
		return array();
	}

	$months  = 'January|February|March|April|May|June|July|August|September|October|November|December';
	$pattern = '/\b(?:(' . $months . ')\s+(?:(\d{1,2})(?:st|nd|rd|th)?,\s*)?(\d{4})|(\d{4})-(\d{2})-(\d{2}))\b/';
	if ( ! preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return array();
	}

	$out = array();
	foreach ( $m as $match ) {
		$raw = $match[0][0];
		$pos = (int) $match[0][1];
		if ( '' !== ( $match[4][0] ?? '' ) ) {
			$ts = strtotime( $raw );
		} else {
			$month = $match[1][0];
			$day   = ( '' !== ( $match[2][0] ?? '' ) ) ? (int) $match[2][0] : 1;
			$year  = (int) $match[3][0];
			$ts    = strtotime( "$month $day, $year" );
		}
		if ( false === $ts || $ts <= $post_ts ) {
			continue;
		}
		$sentence = snt_corpus_integrity_sentence_at( $text, $pos );
		$past_verb = (bool) preg_match(
			'/\b(?:announced|shipped|launched|released|published|debuted|unveiled|introduced|reported|said|confirmed|went\s+live|rolled\s+out|found|flagged|filed)\b/i',
			$sentence
		);
		$out[] = array(
			'found_date' => $raw,
			'sentence'   => $sentence,
			'severity'   => $past_verb ? 'warning' : 'info',
			'pos'        => $pos,
		);
	}
	return $out;
}

/**
 * Walk publish/future/draft/pending posts and return every finding as a
 * candidate row, filtered against per-post dismiss meta
 * (SNT_CORPUS_INTEGRITY_DISMISS_META entries "<check>:<fingerprint>").
 *
 * @return array<int,array>
 */
function snt_corpus_integrity_detect_candidates() {
	$posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	) );

	$candidates = array();

	foreach ( $posts as $post ) {
		$dismissed = (array) get_post_meta( $post->ID, SNT_CORPUS_INTEGRITY_DISMISS_META, true );
		$rows      = array();
		snt_corpus_integrity_collect_prose( parse_blocks( (string) $post->post_content ), '0', $rows );

		$base = array(
			'post_id'     => (int) $post->ID,
			'post_status' => (string) ( $post->post_status ?? '' ),
			'post_title'  => (string) ( $post->post_title ?? '' ),
			'permalink'   => (string) get_permalink( $post->ID ),
		);

		// (a) intra-post duplication.
		foreach ( snt_corpus_integrity_find_duplicates( $rows ) as $dup ) {
			$fp_a = snt_block_fp_fingerprint( $dup['block_a'], (int) $post->ID, $dup['path_a'] );
			$fp_b = snt_block_fp_fingerprint( $dup['block_b'], (int) $post->ID, $dup['path_b'] );
			if ( in_array( 'intra_post_duplication:' . $fp_a, $dismissed, true ) ) {
				continue;
			}
			$candidates[] = $base + array(
				'check'               => 'intra_post_duplication',
				'severity'            => 'warning',
				'block_path_a'        => $dup['path_a'],
				'block_path_b'        => $dup['path_b'],
				'block_fingerprint'   => $fp_a,
				'block_fingerprint_b' => $fp_b,
				'text_a'              => $dup['text_a'],
				'text_b'              => $dup['text_b'],
				'ratio'               => $dup['ratio'],
			);
		}

		// (b) splice artifacts + (c) date coherence, per prose block.
		foreach ( $rows as $row ) {
			$fp = snt_block_fp_fingerprint( $row['block'], (int) $post->ID, $row['path'] );

			foreach ( snt_corpus_integrity_find_splices( $row['text'] ) as $splice ) {
				if ( in_array( 'splice_artifact:' . $fp, $dismissed, true ) ) {
					continue;
				}
				$candidates[] = $base + array(
					'check'             => 'splice_artifact',
					'severity'          => 'warning',
					'block_path'        => $row['path'],
					'block_fingerprint' => $fp,
					'token'             => $splice['token'],
					'sentence'          => $splice['sentence'],
				);
			}

			foreach ( snt_corpus_integrity_find_date_issues( $row['text'], (string) ( $post->post_date ?? '' ) ) as $issue ) {
				if ( in_array( 'date_coherence:' . $fp, $dismissed, true ) ) {
					continue;
				}
				$candidates[] = $base + array(
					'check'             => 'date_coherence',
					'severity'          => $issue['severity'],
					'block_path'        => $row['path'],
					'block_fingerprint' => $fp,
					'found_date'        => $issue['found_date'],
					'post_date'         => substr( (string) ( $post->post_date ?? '' ), 0, 10 ),
					'sentence'          => $issue['sentence'],
				);
			}
		}
	}

	return $candidates;
}

/**
 * Pure compute: detect + envelope, NO writes (the sn_scan read-only
 * contract, same split as the sibling scans).
 *
 * @return array{candidates:array,counts:array{intra_post_duplication:int,splice_artifact:int,date_coherence:int,posts_affected:int},scanned_at:int}
 */
function snt_corpus_integrity_compute() {
	$candidates = snt_corpus_integrity_detect_candidates();

	$counts   = array( 'intra_post_duplication' => 0, 'splice_artifact' => 0, 'date_coherence' => 0 );
	$post_ids = array();
	foreach ( $candidates as $c ) {
		if ( isset( $counts[ $c['check'] ] ) ) {
			$counts[ $c['check'] ]++;
		}
		$post_ids[ $c['post_id'] ] = true;
	}
	$counts['posts_affected'] = count( $post_ids );

	return array(
		'candidates' => $candidates,
		'counts'     => $counts,
		'scanned_at' => time(),
	);
}

/**
 * compute() + the user-scoped transient write (admin-UI cache).
 *
 * @return array Same envelope as snt_corpus_integrity_compute().
 */
function snt_corpus_integrity_run_scan() {
	$result = snt_corpus_integrity_compute();
	set_transient( 'snt_corpus_integrity_candidates_' . (int) get_current_user_id(), $result, SNT_CORPUS_INTEGRITY_TRANSIENT_TTL );
	return $result;
}

/**
 * Cached scan result for the current user, or null.
 *
 * @return array|null
 */
function snt_corpus_integrity_last_scan() {
	$val = get_transient( 'snt_corpus_integrity_candidates_' . (int) get_current_user_id() );
	return is_array( $val ) ? $val : null;
}

/**
 * Dismiss one corpus-integrity finding: append "<check>:<fingerprint>" to
 * the post's dismiss meta and drop the user's cached scan. Mirrors
 * snt_block_migrations_dismiss_impl() including the phantom-empty-entry
 * filter (get_post_meta returns '' when unset; (array)'' is array('')).
 *
 * @param int    $post_id
 * @param string $fingerprint Post+path-bound block fingerprint.
 * @param string $check       One of the three check slugs.
 * @return array{ok:bool,message:string}|WP_Error
 */
function snt_corpus_integrity_dismiss_impl( $post_id, $fingerprint, $check ) {
	$post_id     = (int) $post_id;
	$fingerprint = (string) $fingerprint;
	$check       = (string) $check;

	if ( ! $post_id || ! $fingerprint || ! $check ) {
		return new WP_Error( 'snt_corpus_integrity_invalid_input', __( 'post_id, block_fingerprint, and candidate_type are required.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( ! in_array( $check, array( 'intra_post_duplication', 'splice_artifact', 'date_coherence' ), true ) ) {
		return new WP_Error( 'snt_corpus_integrity_invalid_check', __( 'candidate_type must be one of: intra_post_duplication, splice_artifact, date_coherence.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}

	$existing = array_values( array_filter(
		(array) get_post_meta( $post_id, SNT_CORPUS_INTEGRITY_DISMISS_META, true ),
		'strlen'
	) );
	$key = $check . ':' . $fingerprint;
	if ( ! in_array( $key, $existing, true ) ) {
		$existing[] = $key;
		update_post_meta( $post_id, SNT_CORPUS_INTEGRITY_DISMISS_META, $existing );
	}

	delete_transient( 'snt_corpus_integrity_candidates_' . (int) get_current_user_id() );

	return array( 'ok' => true, 'message' => __( 'Finding dismissed.', 'signal-and-noise-tools' ) );
}
