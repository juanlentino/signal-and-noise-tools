<?php
/**
 * Signal & Noise Tools — semantic embeddings, SHADOW MODE (item 8, slice 1).
 *
 * WHAT THIS IS NOT, YET. Nothing here changes what the site serves or what the
 * ML maturity page claims. The kernel still ranks with TF-IDF; this computes a
 * second opinion beside it so the premise behind item 8 can be MEASURED before
 * it is adopted.
 *
 * THE PREMISE UNDER TEST: the corpus is deliberately one argument restated many
 * ways, so two notes making the same point in different vocabulary score as
 * unrelated under lexical cosine. That is a stated ceiling of the method — and
 * a testable claim, not a settled fact. `snt_ml_embedding_compare()` is the
 * instrument.
 *
 * WHY THERE IS NO VECTORIZE, despite the ticket naming it. Vectorize is an
 * APPROXIMATE nearest-neighbour index for large vector sets. The corpus is 55
 * notes: exact cosine over 55 vectors is microseconds in PHP, and the whole set
 * is under a megabyte of post meta. Adding Vectorize would buy an index to keep
 * in sync, a new service dependency, and approximate answers where exact ones
 * are free. The scale does not justify it and may never.
 *
 * WHAT ADOPTING THIS WOULD COST, recorded here because it is easy to forget at
 * swap time: the maturity page states "No neural network, no training run, no
 * weights file… computed exactly" and "Compute: inside the site". An embedding
 * model breaks all four clauses. That is an owner decision about what the site
 * PUBLICLY CLAIMS, not a refactor.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Model + storage identifiers. The model is part of the cache key: a different model is a different vector space. */
define( 'SNT_ML_EMBED_MODEL', '@cf/baai/bge-base-en-v1.5' );
define( 'SNT_ML_EMBED_META', '_snt_ml_embedding' );
define( 'SNT_ML_EMBED_STAMP_META', '_snt_ml_embedding_stamp' );

/** The Workers AI token. Account id is shared with analytics — one account, one id. */
function snt_ml_embed_account_id() {
	if ( defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID ) {
		return (string) SN_CF_ACCOUNT_ID;
	}
	return (string) get_option( defined( 'SN_CF_ACCOUNT_ID_OPT' ) ? SN_CF_ACCOUNT_ID_OPT : 'sn_cf_account_id', '' );
}

function snt_ml_embed_token() {
	return (string) sn_setting( 'ml.embeddings_token', '' );
}

function snt_ml_embed_configured() {
	return '' !== snt_ml_embed_account_id() && '' !== snt_ml_embed_token();
}

/**
 * Embed one or more texts.
 *
 * Batched: the API accepts an array and returns vectors in the SAME ORDER, so a
 * 55-note rebuild is a handful of calls rather than 55. The order guarantee is
 * asserted below rather than trusted — a silently reordered response would
 * attach every note's vector to the wrong note, and every downstream number
 * would still look plausible.
 *
 * @param string[] $texts
 * @return array|WP_Error List of float[] in input order.
 */
function snt_ml_embed( $texts ) {
	$texts = array_values( array_filter( array_map( 'strval', (array) $texts ), static function ( $t ) {
		return '' !== trim( $t );
	} ) );
	if ( ! $texts ) {
		return array();
	}
	if ( ! snt_ml_embed_configured() ) {
		return new WP_Error( 'snt_ml_embed_unconfigured', __( 'Workers AI is not configured: set the account ID and an API token with Workers AI permission.', 'signal-and-noise-tools' ) );
	}
	$url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( snt_ml_embed_account_id() ) . '/ai/run/' . SNT_ML_EMBED_MODEL;
	$res = wp_remote_post( $url, array(
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . snt_ml_embed_token(),
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( array( 'text' => $texts ) ),
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'snt_ml_embed_transport', $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $body ) ) {
		$detail = is_array( $body ) ? (string) ( $body['errors'][0]['message'] ?? '' ) : '';
		return new WP_Error( 'snt_ml_embed_api', sprintf(
			/* translators: 1: HTTP status, 2: API message. */
			__( 'Workers AI returned HTTP %1$d%2$s', 'signal-and-noise-tools' ), $code, '' !== $detail ? ': ' . $detail : '.'
		) );
	}
	$vectors = $body['result']['data'] ?? null;
	if ( ! is_array( $vectors ) || count( $vectors ) !== count( $texts ) ) {
		// A count mismatch is the one failure that would corrupt every result
		// SILENTLY — vectors would shift onto the wrong notes and every score
		// downstream would remain plausible. Refuse rather than align by hope.
		return new WP_Error( 'snt_ml_embed_shape', sprintf(
			/* translators: 1: vectors returned, 2: texts sent. */
			__( 'Workers AI returned %1$d vectors for %2$d texts; refusing to pair them.', 'signal-and-noise-tools' ),
			is_array( $vectors ) ? count( $vectors ) : 0,
			count( $texts )
		) );
	}
	return array_map( static function ( $v ) {
		return array_map( 'floatval', (array) $v );
	}, $vectors );
}

/**
 * Cosine similarity between two vectors. PURE — no WP calls, like the kernel.
 *
 * Does NOT assume unit vectors. bge output is normalised in practice, but a
 * model swap that changed that would silently inflate every score, and the
 * denominator costs one pass.
 *
 * @return float 0.0 when either vector is empty or zero-magnitude.
 */
function snt_ml_vec_cosine( $a, $b ) {
	$a = (array) $a;
	$b = (array) $b;
	$n = min( count( $a ), count( $b ) );
	if ( 0 === $n || count( $a ) !== count( $b ) ) {
		return 0.0;
	}
	$dot = 0.0; $ma = 0.0; $mb = 0.0;
	for ( $i = 0; $i < $n; $i++ ) {
		$x = (float) $a[ $i ]; $y = (float) $b[ $i ];
		$dot += $x * $y; $ma += $x * $x; $mb += $y * $y;
	}
	if ( $ma <= 0.0 || $mb <= 0.0 ) {
		return 0.0;
	}
	return $dot / ( sqrt( $ma ) * sqrt( $mb ) );
}

/** The text a note is embedded from: title carries the argument, body carries the vocabulary. */
function snt_ml_embed_text_for_post( $post ) {
	$title = (string) get_the_title( $post );
	$body  = (string) wp_strip_all_tags( (string) get_post_field( 'post_content', $post ) );
	// bge truncates around 512 tokens; sending a whole 600-word note wastes the
	// tail. The opening carries the claim on this corpus — every note states its
	// argument in the first paragraphs — so the head is the honest sample.
	return trim( $title . "\n\n" . mb_substr( $body, 0, 2000 ) );
}

/**
 * The stored vector for a post, embedding it if the content changed.
 *
 * Cache key is the CONTENT HASH plus the model id. Either changing invalidates:
 * an edited note must be re-embedded, and a different model is a different
 * vector space in which the old numbers are meaningless rather than merely old.
 *
 * @return array|WP_Error|null float[]; null when the post has no text.
 */
function snt_ml_embedding_for_post( $post_id, $content_hash ) {
	$post_id = (int) $post_id;
	$stamp   = SNT_ML_EMBED_MODEL . ':' . (string) $content_hash;
	$have    = (string) get_post_meta( $post_id, SNT_ML_EMBED_STAMP_META, true );
	if ( $have === $stamp ) {
		$cached = get_post_meta( $post_id, SNT_ML_EMBED_META, true );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
	}
	$text = snt_ml_embed_text_for_post( $post_id );
	if ( '' === $text ) {
		return null;
	}
	$vectors = snt_ml_embed( array( $text ) );
	if ( is_wp_error( $vectors ) ) {
		return $vectors;
	}
	if ( ! isset( $vectors[0] ) ) {
		return null;
	}
	update_post_meta( $post_id, SNT_ML_EMBED_META, $vectors[0] );
	update_post_meta( $post_id, SNT_ML_EMBED_STAMP_META, $stamp );
	return $vectors[0];
}

/**
 * The corpus centroid — the average direction of every vector.
 *
 * PURE. No WP calls, like the kernel it sits beside.
 *
 * @param array $vectors id => float[]
 * @return array float[] (empty when there is nothing to average)
 */
function snt_ml_vec_centroid( $vectors ) {
	$vectors = (array) $vectors;
	if ( ! $vectors ) {
		return array();
	}
	$sum = null;
	$n   = 0;
	foreach ( $vectors as $v ) {
		$v = (array) $v;
		if ( ! $v ) {
			continue;
		}
		if ( null === $sum ) {
			$sum = array_fill( 0, count( $v ), 0.0 );
		}
		if ( count( $v ) !== count( $sum ) ) {
			// Mixed dimensions mean mixed vector spaces — averaging them would
			// produce a centroid describing neither.
			continue;
		}
		foreach ( $v as $i => $x ) {
			$sum[ $i ] += (float) $x;
		}
		++$n;
	}
	if ( null === $sum || 0 === $n ) {
		return array();
	}
	foreach ( $sum as $i => $x ) {
		$sum[ $i ] = $x / $n;
	}
	return $sum;
}

/**
 * Subtract the centroid from every vector.
 *
 * WHY: on a corpus that is one argument restated, every vector shares a large
 * common component — the subject itself. Raw cosine therefore reports that a
 * note near the centre of that shared mass is the nearest neighbour of almost
 * everything, which is HUBNESS: measured live at 17 of 33 rows pairing with the
 * same note. Removing the common component leaves what actually distinguishes
 * one note from another, which is the question "related" is asking.
 *
 * @param array $vectors id => float[]
 * @return array id => float[]
 */
function snt_ml_vec_center_all( $vectors ) {
	$centroid = snt_ml_vec_centroid( $vectors );
	if ( ! $centroid ) {
		return (array) $vectors;
	}
	$out = array();
	foreach ( (array) $vectors as $id => $v ) {
		$v = (array) $v;
		if ( count( $v ) !== count( $centroid ) ) {
			$out[ $id ] = $v; // Leave a foreign-dimension vector untouched rather than corrupt it.
			continue;
		}
		foreach ( $v as $i => $x ) {
			$v[ $i ] = (float) $x - $centroid[ $i ];
		}
		$out[ $id ] = $v;
	}
	return $out;
}
