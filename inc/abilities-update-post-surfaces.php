<?php
/**
 * Signal & Noise Tools — Abilities API: update-post-surfaces (reviewed-text write).
 *
 * One ability closing the reviewed-text gap on the rw door: before v10.7.0
 * none of the three metadata surfaces accepted text a human had already
 * approved — ai-generate-og-card-title writes only its own AI output,
 * ai-generate-meta-description returns text and relies on the editor JS to
 * save it, and nothing writes post_excerpt at all. Agent workflows that
 * draft → review → apply had no apply step.
 *
 *   signal-noise/update-post-surfaces
 *     input:  post_id + any of { excerpt, meta_description, og_card_title }
 *     writes: post_excerpt (wp_update_post → revision), _sn_meta_description,
 *             _sn_og_card_title (+ regenerates the card PNG when possible)
 *
 * Each written surface also deletes its _sn_autogen_* sentinel — text that
 * arrived through this door was reviewed by a person, which is exactly what
 * the "auto-generated at publish" notice exists to flag the absence of.
 *
 * rw door only (edit_post-gated on top of the door's own auth + kill switch).
 * No AI call anywhere — category 'tools'.
 *
 * @package SignalNoiseTools
 * @since 10.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v10.9.0 hardening: per-post write throttle (the rw door rate-limits per
// credential; this bounds churn on a single TARGET) and impl-level length
// caps mirroring the input_schema — the schema validates the wire path, but
// the impl must hold on its own for any caller that reaches it directly.
const SNT_SURFACES_THROTTLE_MAX    = 5;    // successful writes per post…
const SNT_SURFACES_THROTTLE_WINDOW = 600;  // …per rolling 10-minute window.
const SNT_SURFACES_FIELD_CAPS      = array(
	'excerpt'          => 1000,
	'meta_description' => 300,
	'og_card_title'    => 150,
	'seo_title'        => 150,
	'focus_keyword'    => 80,
);

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/update-post-surfaces', array(
		'label'               => 'Write reviewed excerpt / meta description / OG card title to a post',
		'description'         => 'Sets any combination of post_excerpt, _sn_meta_description, and _sn_og_card_title to caller-supplied (human-reviewed) text. NO AI — this is the apply step after a draft → review workflow. Writing the OG card title also regenerates the card PNG. Each written surface clears its _sn_autogen_* sentinel. The excerpt write goes through wp_update_post, so a revision is created.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_update_post_surfaces',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
				'excerpt'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ),
				'meta_description' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 300 ),
				'og_card_title'    => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 150 ),
				'seo_title'        => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 150 ),
				'focus_keyword'    => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'               => array( 'type' => 'boolean' ),
				'post_id'          => array( 'type' => 'integer' ),
				'updated'          => array( 'type' => 'array' ),
				'card_regenerated' => array( 'type' => 'boolean' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

} );

/**
 * Ability execute callback: signal-noise/update-post-surfaces.
 *
 * @param array $input Validated against the input_schema above.
 * @return array{ok:bool,post_id:int,updated:array,card_regenerated:bool}|WP_Error
 *
 * @since 10.7.0
 */
function snt_ability_update_post_surfaces( $input ) {
	$post_id = (int) ( $input['post_id'] ?? 0 );

	$post = get_post( $post_id );
	// v10.8.0 hardening: same target contract as the corpus read tools —
	// corpus statuses only (no trash, no revisions' 'inherit') and public
	// post types only (no attachments/internal CPTs). One definition of a
	// valid target across both doors, via the shared corpus-inspect gates.
	$status_ok = $post && in_array( (string) $post->post_status, SNT_CORPUS_STATUSES, true );
	$type_ok   = $post && function_exists( 'snt_corpus_post_type_allowed' )
		&& snt_corpus_post_type_allowed( (string) $post->post_type );
	if ( ! $status_ok || ! $type_ok ) {
		return new WP_Error(
			'snt_surfaces_post_not_found',
			__( 'Post not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$excerpt   = isset( $input['excerpt'] ) ? sanitize_textarea_field( (string) $input['excerpt'] ) : null;
	$meta_desc = isset( $input['meta_description'] ) ? sanitize_textarea_field( (string) $input['meta_description'] ) : null;
	$og_title  = isset( $input['og_card_title'] ) ? sanitize_text_field( (string) $input['og_card_title'] ) : null;
	$seo_title = isset( $input['seo_title'] ) ? sanitize_text_field( (string) $input['seo_title'] ) : null;
	$focus_kw  = isset( $input['focus_keyword'] ) ? sanitize_text_field( (string) $input['focus_keyword'] ) : null;

	if ( null === $excerpt && null === $meta_desc && null === $og_title && null === $seo_title && null === $focus_kw ) {
		return new WP_Error(
			'snt_surfaces_nothing_to_write',
			__( 'Provide at least one of: excerpt, meta_description, og_card_title, seo_title, focus_keyword.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	// Impl-level length caps (v10.9.0) — REJECT, never truncate: this is
	// reviewed text, and silently altering it would defeat the review.
	$fields = array(
		'excerpt'          => $excerpt,
		'meta_description' => $meta_desc,
		'og_card_title'    => $og_title,
		'seo_title'        => $seo_title,
		'focus_keyword'    => $focus_kw,
	);
	foreach ( $fields as $field => $value ) {
		if ( null !== $value && mb_strlen( $value ) > SNT_SURFACES_FIELD_CAPS[ $field ] ) {
			return new WP_Error(
				'snt_surfaces_too_long',
				sprintf(
					/* translators: 1: field name, 2: maximum length. */
					__( 'Field "%1$s" exceeds its %2$d-character cap. Nothing was written.', 'signal-and-noise-tools' ),
					$field,
					SNT_SURFACES_FIELD_CAPS[ $field ]
				),
				array( 'status' => 422 )
			);
		}
	}

	// Per-post throttle (v10.9.0) — counts SUCCESSFUL writes only (a rejected
	// call must not consume quota); the window slides forward on each write.
	$throttle_key = 'snt_surfaces_writes_' . $post_id;
	$write_count  = (int) get_transient( $throttle_key );
	$write_cap    = (int) apply_filters( 'snt_surfaces_per_post_write_cap', SNT_SURFACES_THROTTLE_MAX, $post_id );
	if ( $write_count >= $write_cap ) {
		return new WP_Error(
			'snt_surfaces_throttled',
			sprintf(
				/* translators: 1: writes allowed, 2: window in minutes. */
				__( 'This post has reached its surface-write limit (%1$d writes per %2$d minutes). Nothing was written; retry after the window.', 'signal-and-noise-tools' ),
				$write_cap,
				(int) ( SNT_SURFACES_THROTTLE_WINDOW / 60 )
			),
			array( 'status' => 429 )
		);
	}

	$updated          = array();
	$card_regenerated = false;

	if ( null !== $excerpt ) {
		$res = wp_update_post(
			array( 'ID' => $post_id, 'post_excerpt' => $excerpt ),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		delete_post_meta( $post_id, '_sn_autogen_excerpt' );
		$updated[] = 'excerpt';
	}

	if ( null !== $meta_desc ) {
		update_post_meta( $post_id, '_sn_meta_description', $meta_desc );
		delete_post_meta( $post_id, '_sn_autogen_meta_description' );
		$updated[] = 'meta_description';
	}

	if ( null !== $og_title ) {
		update_post_meta( $post_id, '_sn_og_card_title', $og_title );
		delete_post_meta( $post_id, '_sn_autogen_og_card_title' );
		// Same immediate-PNG-refresh behavior as the AI path in
		// inc/ai-og-card-title.php — quiet on failure, reported honestly.
		$card_regenerated = function_exists( 'sn_generate_og_card' )
			? (bool) sn_generate_og_card( $post_id )
			: false;
		$updated[] = 'og_card_title';
	}

	if ( null !== $seo_title ) {
		update_post_meta( $post_id, '_sn_seo_title', $seo_title );
		$updated[] = 'seo_title';
	}

	if ( null !== $focus_kw ) {
		// v10.7.0: new meta key. The meta-description generator reads it as
		// its keyword fallback (inc/ai-meta-description.php) so the SEO
		// grading loop closes without a per-call parameter.
		update_post_meta( $post_id, '_sn_focus_keyword', $focus_kw );
		$updated[] = 'focus_keyword';
	}

	set_transient( $throttle_key, $write_count + 1, SNT_SURFACES_THROTTLE_WINDOW );

	return array(
		'ok'               => true,
		'post_id'          => $post_id,
		'updated'          => $updated,
		'card_regenerated' => $card_regenerated,
	);
}
