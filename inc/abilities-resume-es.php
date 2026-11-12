<?php
/**
 * Signal & Noise Tools: signal-noise/resume-es-apply, the Spanish CV's write
 * (owner, 2026-11-12: "The Spanish CV shouldn't mean a plugin update. Ever
 * again."). The Spanish text is stored in an option (inc/resume-es.php); this
 * reads it, checks a replacement and, when asked, writes it and re-renders /cv
 * and its PDF. Dry run by default. A phone is refused anywhere in the document:
 * contact details always come from the English résumé.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The largest Spanish document the write takes, in bytes of JSON. */
const SN_RESUME_ES_APPLY_MAX = 200000;

/**
 * Why a Spanish document cannot be stored, or '' when it can. PURE but for
 * the render it tries.
 *
 * @param mixed $doc The decoded document.
 * @return string
 */
function snt_resume_es_refusal( $doc ) {
	if ( ! is_array( $doc ) || ! is_array( $doc['hero'] ?? null ) ) {
		return 'document must be an object with a hero, in the English document\'s shape';
	}
	$json = (string) wp_json_encode( $doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( strlen( $json ) > SN_RESUME_ES_APPLY_MAX ) {
		return 'document is over ' . SN_RESUME_ES_APPLY_MAX . ' bytes';
	}
	if ( preg_match( '/"phone(_public)?"\s*:\s*(?!""|false|null)/', $json ) || snt_resume_es_has_phone( wp_strip_all_tags( implode( ' ', snt_resume_es_strings( $doc ) ) ) ) ) {
		return 'a phone number is never stored in the Spanish CV (owner, 2026-11-02)';
	}
	$merged = sn_resume_es_merge( $doc, array(), '' );
	$body   = $merged ? (string) sn_resume_es_render( static fn() => sn_resume_page_body( $merged, '' ) ) : '';
	return '' === $body ? 'document does not render: it needs at least one experience entry or publication' : '';
}

/**
 * Whether text holds a phone-like run: ten or more digits joined by spaces,
 * dots, dashes or parentheses ("1997 - 2015" has eight, so a date range never
 * trips it). PURE.
 *
 * @param string $text
 * @return bool
 */
function snt_resume_es_has_phone( $text ) {
	preg_match_all( '/\+?\(?\d[\d\s().-]{6,}\d/', (string) $text, $m );
	foreach ( $m[0] as $run ) {
		if ( strlen( (string) preg_replace( '/\D/', '', $run ) ) >= 10 ) {
			return true;
		}
	}
	return false;
}

/**
 * Every string in a document, for the phone sweep. PURE.
 *
 * @param array $doc
 * @return string[]
 */
function snt_resume_es_strings( array $doc ) {
	$out = array();
	array_walk_recursive(
		$doc,
		static function ( $v, $k ) use ( &$out ) {
			if ( is_string( $v ) && ! in_array( $k, array( 'url', 'receipt_url', 'credential_url', 'linkedin', 'pdf_url', 'website' ), true ) && 0 !== strpos( $v, 'http' ) ) {
				$out[] = $v;
			}
		}
	);
	return $out;
}

/**
 * Execute: read, dry-run or write the Spanish document.
 *
 * @param array|null $input {document?: object, dry_run?: bool}.
 * @return array|WP_Error
 */
function snt_resume_es_apply( $input = null ) {
	$input   = is_array( $input ) ? $input : array();
	$stored  = get_option( SN_RESUME_ES_DOC_OPT );
	$current = json_decode( sn_resume_es_source_json(), true );
	$english = function_exists( 'sn_resume_shown_doc' ) ? sn_resume_lines( sn_resume_shown_doc() ) : array();
	$base    = array(
		'source'          => is_string( $stored ) && '' !== $stored ? 'stored' : 'bundled',
		'english_version' => sn_resume_lines_hash( $english ),
		'stale'           => function_exists( 'sn_resume_es_stale' ) ? sn_resume_es_stale() : null,
	);
	if ( ! array_key_exists( 'document', $input ) ) {
		return $base + array( 'document' => $current );
	}
	$doc = $input['document'];
	$why = snt_resume_es_refusal( $doc );
	if ( '' !== $why ) {
		return new WP_Error( 'snt_resume_es_refused', $why, array( 'status' => 422 ) );
	}
	$before = sn_resume_lines( sn_resume_es_merge( $current, array(), '' ) );
	$after  = sn_resume_lines( sn_resume_es_merge( $doc, array(), '' ) );
	$out    = $base + array(
		'dry_run' => false !== ( $input['dry_run'] ?? true ),
		'diff'    => sn_resume_es_diff( $before, $after ),
	);
	if ( $out['dry_run'] ) {
		return $out;
	}
	update_option( SN_RESUME_ES_DOC_OPT, (string) wp_json_encode( $doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), false );
	$out['pdf']  = sn_resume_es_pdf_generate(); // re-renders /cv too.
	$out['page'] = sn_resume_es_sync_page();
	update_option( SN_RESUME_ES_SYNC_OPT, sn_resume_es_sync_state( function_exists( 'sn_resume_doc_get' ) ? sn_resume_doc_get() : array(), SN_RESUME_ENGINE_REV ), false );
	return $out;
}

add_action(
	'wp_abilities_api_init',
	static function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'signal-noise/resume-es-apply',
			array(
				'label'               => __( 'Spanish CV apply', 'signal-and-noise-tools' ),
				'description'         => __( 'The Spanish CV (/cv/) as stored text, in the English résumé document\'s shape (hero, pdf, experience, earlier, education, affiliations, publications, skills, stats, built, variants.operations for the one-résumé layout, translated_from). Without document: returns the current document, its source (stored or the bundled seed), the English version code and the stale diff. With document: refuses a phone anywhere and a document that does not render, then returns the line diff; dry_run false stores it and re-renders /cv/ and JuanLentino_CV.pdf. Contact details always come from the English résumé. No plugin release is needed for a Spanish update.', 'signal-and-noise-tools' ),
				'category'            => 'content',
				'permission_callback' => 'snt_ability_perm_manage_options',
				'execute_callback'    => 'snt_resume_es_apply',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'document' => array( 'type' => 'object' ),
						'dry_run'  => array( 'type' => 'boolean', 'default' => true ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ),
				),
			)
		);
	}
);
