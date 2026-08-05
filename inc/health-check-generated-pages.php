<?php
/**
 * Health check: generated page bodies still carry their structure.
 *
 * THE GAP THIS CLOSES: /resume, /now and /uses are built by sync engines and
 * stored as post_content. The engines are pure builders and the test suite
 * pins what they BUILD — but nothing pinned what is actually STORED. Every
 * failure in this coupling happened in that gap, and each was caught only by
 * an owner screenshot:
 *
 *   v10.33.1  the /resume body shipped wrapped in wp:html, so WordPress
 *             enqueued none of the columns/file/separator block styles and the
 *             live page lost its layout.
 *   v10.33.2  an unchanged save skipped the sync, stranding the fix.
 *   v10.33.3  a band shipped at a superseded width.
 *
 * Deterministic and cheap: the engines are pure, so this reads the three
 * stored bodies and asserts the structural markers are present. It does NOT
 * diff against a fresh build — an owner edit through the editor is legitimate,
 * and a check that goes red on every deliberate edit gets ignored, which is
 * how the ledger CI went unread for three days.
 *
 * NOTE the deliberate asymmetry: /now and /uses ARE wp:html by design (their
 * builders wrap a raw <div>), while /resume must be real block markup. A
 * wp:html /resume is exactly the v10.33.1 regression, so the wp:html rule is
 * asserted for /resume ONLY.
 *
 * @package SignalNoiseTools
 * @since   10.44.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The structural contract per engine-owned page.
 *
 * @return array<string, array{markers:string[], block_markup:bool}>
 */
function snt_generated_pages_contract() {
	return array(
		'resume' => array(
			// sn-resume-hero-split is the hero columns wrapper the theme's
			// 900px breakpoint rule hangs off; 1320px is the uniform band.
			'markers'      => array( 'sn-resume-hero-split', '"contentSize":"1320px"' ),
			'block_markup' => true,
		),
		'now'    => array(
			'markers'      => array( 'sn-now-page', 'sn-now-hero' ),
			'block_markup' => false,
		),
		'uses'   => array(
			'markers'      => array( 'sn-uses-page', 'sn-uses-hero' ),
			'block_markup' => false,
		),
	);
}

/**
 * Pure evaluator: stored bodies in, per-page verdicts out.
 *
 * @param array<string,string> $bodies page key => stored post_content.
 * @return array<string, array{ok:bool, detail:string}>
 */
function snt_generated_pages_evaluate( $bodies ) {
	$out = array();

	foreach ( snt_generated_pages_contract() as $page => $contract ) {
		// A page absent from the set fails rather than disappearing from the
		// report — a missing page is a finding, not a non-answer.
		$body = isset( $bodies[ $page ] ) ? (string) $bodies[ $page ] : '';

		if ( '' === trim( $body ) ) {
			$out[ $page ] = array(
				'ok'     => false,
				'detail' => sprintf( '/%s has an empty stored body — the sync engine never wrote, or the body was cleared.', $page ),
			);
			continue;
		}

		// v10.33.1: a wp:html wrapper suppresses core block style enqueueing,
		// so the page renders unstyled. Only /resume depends on core block CSS.
		if ( ! empty( $contract['block_markup'] ) && 0 === strpos( ltrim( $body ), '<!-- wp:html' ) ) {
			$out[ $page ] = array(
				'ok'     => false,
				'detail' => sprintf( '/%s is wrapped in wp:html — WordPress enqueues no core block styles for it, so the page renders unstyled (the v10.33.1 regression).', $page ),
			);
			continue;
		}

		$missing = array();
		foreach ( $contract['markers'] as $marker ) {
			if ( false === strpos( $body, $marker ) ) {
				$missing[] = $marker;
			}
		}

		if ( $missing ) {
			$out[ $page ] = array(
				'ok'     => false,
				'detail' => sprintf(
					'/%s is missing %s in its stored body — the hero or its band width did not survive the last write.',
					$page,
					implode( ', ', $missing )
				),
			);
			continue;
		}

		$out[ $page ] = array(
			'ok'     => true,
			'detail' => sprintf( '/%s carries its hero and band markers.', $page ),
		);
	}

	return $out;
}

/**
 * The 19th health check: read the three stored bodies and evaluate them.
 *
 * @return array Standard health-check result.
 */
function snt_health_check_generated_pages() {
	$bodies = array();

	foreach ( array_keys( snt_generated_pages_contract() ) as $page ) {
		$post              = get_page_by_path( $page, OBJECT, 'page' );
		$bodies[ $page ] = ( $post && isset( $post->post_content ) ) ? (string) $post->post_content : '';
	}

	$verdicts = snt_generated_pages_evaluate( $bodies );
	$findings = array();

	foreach ( $verdicts as $page => $verdict ) {
		if ( empty( $verdict['ok'] ) ) {
			$findings[] = array(
				'label'  => sprintf( '/%s', $page ),
				'detail' => (string) $verdict['detail'],
			);
		}
	}

	return array(
		'id'       => 'generated_pages',
		'label'    => __( 'Generated page bodies', 'signal-and-noise-tools' ),
		'passed'   => empty( $findings ),
		'count'    => count( $findings ),
		'findings' => $findings,
		'fix_hint' => __( 'A sync-engine page lost its structure in the database. Open the page in Pages and re-save it, or run the matching sync (Resume / Now Page / Uses Page tab) to regenerate the body.', 'signal-and-noise-tools' ),
	);
}
