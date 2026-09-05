<?php
/**
 * Signal & Noise Tools — the note dossier: the editorial block.
 *
 * Tags, reading time, word count, the excerpt as agents receive it, and
 * related notes -- from the plugin's own kernel, which answers "none"
 * honestly; the theme's related-notes query backfills with recent posts and
 * would present recency as relation.
 *
 * Reading time is READ from its cached meta, never through the getter: the
 * getter computes and WRITES on a miss, and a dossier is a read.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param int $post_id
 * @return array<int,array<string,mixed>>
 */
function sn_note_dossier_editorial( $post_id ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$dash  = '—';
	$tags  = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
	$terr  = is_wp_error( $tags );
	$tags  = $terr ? array() : array_values( array_map( 'strval', (array) $tags ) );
	$mins  = defined( 'SN_READING_TIME_META_KEY' ) ? (string) get_post_meta( $post->ID, SN_READING_TIME_META_KEY, true ) : '';
	$words = function_exists( 'snt_corpus_word_count' ) ? (int) snt_corpus_word_count( (string) $post->post_content ) : null;
	// The live pace, not the default: the site can filter sn_reading_time_wpm.
	$wpm   = (int) apply_filters( 'sn_reading_time_wpm', defined( 'SN_READING_TIME_DEFAULT_WPM' ) ? SN_READING_TIME_DEFAULT_WPM : 225, $post );
	$wpm   = $wpm > 0 ? $wpm : 225;

	$tiles   = array();
	$tiles[] = '' === $mins
		? array( 'label' => __( 'Reading time', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => '', 'note' => __( 'not computed yet', 'signal-and-noise-tools' ) )
		: array( 'label' => __( 'Reading time', 'signal-and-noise-tools' ), 'value' => sprintf( /* translators: %d: minutes. */ __( '%d min', 'signal-and-noise-tools' ), (int) $mins ), 'window' => '', 'note' => sprintf( /* translators: %d: words per minute. */ __( 'at %d words a minute', 'signal-and-noise-tools' ), $wpm ) );
	$tiles[] = null === $words
		? array( 'label' => __( 'Words', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => '', 'note' => __( 'counter not loaded', 'signal-and-noise-tools' ) )
		: array( 'label' => __( 'Words', 'signal-and-noise-tools' ), 'value' => number_format_i18n( $words ), 'window' => '', 'note' => __( 'whitespace count, as agents receive it', 'signal-and-noise-tools' ) );
	$tiles[] = array( 'label' => __( 'Tags', 'signal-and-noise-tools' ), 'value' => $terr ? $dash : (string) count( $tags ), 'window' => '', 'note' => '' );

	$blocks   = array();
	$blocks[] = sn_note_dossier_stats( 'editorial', __( 'Editorial', 'signal-and-noise-tools' ), $tiles, __( 'the post', 'signal-and-noise-tools' ) );
	$blocks[] = sn_note_dossier_text(
		'editorial',
		__( 'Tags', 'signal-and-noise-tools' ),
		$terr ? __( 'The tags could not be read.', 'signal-and-noise-tools' ) : ( $tags ? implode( ', ', $tags ) : __( 'Untagged.', 'signal-and-noise-tools' ) )
	);
	if ( function_exists( 'snt_corpus_excerpt' ) ) {
		$excerpt = (string) snt_corpus_excerpt( $post );
		$blocks[] = sn_note_dossier_text( 'editorial', __( 'Excerpt served to agents', 'signal-and-noise-tools' ), '' !== $excerpt ? $excerpt : __( 'Empty.', 'signal-and-noise-tools' ), __( 'the sn-posts ability', 'signal-and-noise-tools' ) );
	}
	if ( function_exists( 'snt_ml_related_for_post' ) ) {
		$related = snt_ml_related_for_post( $post->ID, 3 );
		if ( is_array( $related ) ) {
			if ( array() === $related ) {
				$blocks[] = sn_note_dossier_text( 'editorial', __( 'Related notes', 'signal-and-noise-tools' ), __( 'None related in the kernel.', 'signal-and-noise-tools' ), __( 'the ML kernel', 'signal-and-noise-tools' ) );
			} else {
				$rows = array();
				foreach ( $related as $r ) {
					$rid    = (int) ( $r['post_id'] ?? 0 );
					$rows[] = array( 'title' => (string) get_the_title( $rid ), 'score' => number_format_i18n( (float) ( $r['score'] ?? 0 ), 2 ) );
				}
				$blocks[] = sn_note_dossier_table( 'editorial', __( 'Related notes', 'signal-and-noise-tools' ), array( array( 'key' => 'title', 'label' => __( 'Note', 'signal-and-noise-tools' ) ), array( 'key' => 'score', 'label' => __( 'Score', 'signal-and-noise-tools' ) ) ), $rows, __( 'the ML kernel', 'signal-and-noise-tools' ) );
			}
		}
		// null: the kernel was never built -- omitted, not faked.
	}
	return $blocks;
}
