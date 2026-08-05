<?php
/**
 * The structural contract for engine-generated page bodies, enforced at the
 * WRITE BOUNDARY.
 *
 * WHY NOT A HEALTH CHECK (this started as one, and that was the wrong shape):
 * the other 18 checks watch AMBIENT drift — external links rot, edge headers
 * change, the ledger's CI goes red. The world changes those underneath us, so
 * polling is the only way to see it. Generated page bodies are not ambient:
 * they change for exactly one reason, a write, which is an event we can
 * observe at the moment it happens. Polling up to 24h later for something
 * observable at source is strictly weaker, and a 19th entry on a list where
 * "18/18 passed" is already read as one glance costs attention that the
 * remaining checks need. So the contract is enforced where the write happens:
 * a structurally broken body is REFUSED rather than stored and reported later.
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
 * SCOPE, stated honestly: this guards the ENGINE writes, which is where the
 * v10.33.1 and v10.33.3 bugs were — the engine built a broken body and stored
 * it. It does NOT address v10.33.2 (an unchanged save skipped the sync
 * entirely); nothing was written there, so a write guard has nothing to catch.
 * That one belongs to the sync path's own idempotence and is not fixed here.
 *
 * Manual edits through the block editor are deliberately NOT blocked — an
 * owner editing their own page is legitimate, and refusing their save would be
 * hostile. The guard binds the engines, which have no business emitting markup
 * that loses the layout.
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
				'detail' => sprintf( '/%s has an empty stored body: the sync engine never wrote, or the body was cleared.', $page ),
			);
			continue;
		}

		// v10.33.1: a wp:html wrapper suppresses core block style enqueueing,
		// so the page renders unstyled. Only /resume depends on core block CSS.
		if ( ! empty( $contract['block_markup'] ) && 0 === strpos( ltrim( $body ), '<!-- wp:html' ) ) {
			$out[ $page ] = array(
				'ok'     => false,
				'detail' => sprintf( '/%s is wrapped in wp:html. WordPress enqueues no core block styles for it, so the page renders unstyled (the v10.33.1 regression).', $page ),
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
					'/%s is missing %s in its stored body: the hero or its band width did not survive the last write.',
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
 * Write guard. Returns true when $body may be stored for $page.
 *
 * Fail-closed by design: a body that has lost its structure is REFUSED rather
 * than written and reported a day later. The engines are the only callers, and
 * an engine emitting markup that loses the layout is a bug, never a valid
 * state — so refusing costs nothing real and preserves whatever correct body
 * is already stored.
 *
 * @param string $page Page key (resume|now|uses).
 * @param string $body Body about to be written.
 * @return bool
 */
function snt_generated_page_guard( $page, $body ) {
	$contract = snt_generated_pages_contract();
	if ( ! isset( $contract[ $page ] ) ) {
		return true; // Not an engine-owned page — nothing to assert.
	}

	$verdict = snt_generated_pages_evaluate( array( $page => $body ) );
	if ( ! empty( $verdict[ $page ]['ok'] ) ) {
		return true;
	}

	$detail = isset( $verdict[ $page ]['detail'] ) ? (string) $verdict[ $page ]['detail'] : 'structure lost';

	// Loud, because silence here is what let three of these ship. The existing
	// body stays untouched, so the page keeps rendering correctly while the
	// error names what the engine tried to store.
	if ( function_exists( 'error_log' ) ) {
		error_log( sprintf( '[signal-and-noise-tools] REFUSED write to /%s: %s', $page, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: a refused structural write must be visible in the host error log.
	}

	/**
	 * Fires when a generated-page write was refused for losing its structure.
	 *
	 * @since 10.44.0
	 * @param string $page   Page key.
	 * @param string $detail Human-readable reason.
	 * @param string $body   The rejected body.
	 */
	do_action( 'snt_generated_page_write_refused', $page, $detail, $body );

	return false;
}
