<?php
/**
 * S&N Dashboard — Content → Pattern Adoption, painted from the kit.
 *
 * The classic leaf (inc/pattern-adoption-admin.php,
 * `snt_pattern_adoption_render_opportunities_section()`) paints a heading with
 * an opportunity-count pill, the intro, one scan form, then one of three
 * states: nothing before the first scan, the empty note, or a collapsed
 * review queue — one row per candidate with the post, the pattern, and the
 * Suggest + Dismiss pair the enqueued assets/health-suggest-actions.js drives.
 * Same reader, same form, same data attributes; the kit's parts.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The count pill the classic heading carries: warn when there is work, ok at zero.
 *
 * @param array<string,mixed> $scan From snt_pattern_adoption_last_scan().
 * @return string
 */
function pattern_adoption_count_badge( array $scan ) {
	$total = (int) ( $scan['counts']['pull_quote'] ?? 0 ) + (int) ( $scan['counts']['steps_enumerated'] ?? 0 );
	return \snt_kit_badge(
		$total > 0 ? 'warn' : 'ok',
		sprintf(
			/* translators: %d is the count of pattern-adoption opportunities found */
			_n( '%d opportunity', '%d opportunities', $total, 'signal-and-noise-tools' ),
			$total
		)
	);
}

/**
 * One candidate: the classic table row (Post · Pattern · Action) as a compact
 * card of labelled values, the two buttons carrying the data attributes the
 * classic row carries. `<os-card>` / `<os-button>`: kit-help os-card (compact),
 * os-button (variant, disabled).
 *
 * @param array<string,mixed> $c A candidate from the scan.
 * @return string
 */
function pattern_adoption_candidate_html( array $c ) {
	$type   = (string) ( $c['pattern_type'] ?? '' );
	$raw    = (string) ( $c['permalink'] ?? '' );
	// esc_url() (classic: inc/pattern-adoption-admin.php) blanks a disallowed
	// scheme but still prints the text; snt_kit_link() only
	// htmlspecialchars-escapes the href, so a rejected scheme is painted
	// unlinked here (via snt_kit_esc()) instead of dropping the line.
	$safe_link = preg_match( '#^https?://#i', $raw ) ? \snt_kit_link( $raw, $raw ) : \snt_kit_esc( $raw );
	$common = array(
		'variant'           => 'secondary',
		'data-post-id'      => (string) (int) ( $c['post_id'] ?? 0 ),
		'data-fingerprint'  => (string) ( $c['block_fingerprint'] ?? '' ),
		'data-pattern-type' => $type,
	);
	$post   = \snt_kit_code( (string) ( $c['post_title'] ?? '' ), false )
		. ( '' !== $raw ? '<p class="snt-hint">' . $safe_link . '</p>' : '' );
	// Suggest replaces the classic table CELL with its editor (`closest( 'td,th' )`
	// in health-suggest-actions.js); a window paints no cell, so the button is
	// present, disabled, and the queue's hint says where it runs.
	$suggest = \snt_kit_tag(
		'os-button',
		$common + array(
			'data-snt-suggest' => '1',
			'data-check'       => 'pull-quote' === $type ? 'pattern_adoption_pull_quote' : 'pattern_adoption_steps_enumerated',
			'disabled'         => true,
			'title'            => __( 'Suggest runs on the classic Content → Pattern Adoption page.', 'signal-and-noise-tools' ),
		),
		\snt_kit_esc( __( 'Suggest', 'signal-and-noise-tools' ) )
	);
	$dismiss = \snt_kit_tag( 'os-button', $common + array( 'data-snt-dismiss' => '1' ), \snt_kit_esc( __( 'Dismiss', 'signal-and-noise-tools' ) ) );
	return \snt_kit_tag(
		'os-card',
		array( 'compact' => true ),
		\snt_kit_kv(
			array(
				array( 'label' => __( 'Post', 'signal-and-noise-tools' ), 'value' => $post, 'html' => true ),
				array( 'label' => __( 'Pattern', 'signal-and-noise-tools' ), 'value' => \snt_kit_badge( 'warn', $type ), 'html' => true ),
				array( 'label' => __( 'Action', 'signal-and-noise-tools' ), 'value' => $suggest . ' ' . $dismiss, 'html' => true ),
			)
		)
	);
}

/**
 * The review queue, collapsed by default as the classic `<details>` is.
 * `<os-disclosure>` (heading; closed when `open` is absent) and `<os-stack>`
 * (gap): kit-help os-disclosure, os-stack.
 *
 * @param array<int,array<string,mixed>> $candidates From the scan.
 * @return string
 */
function pattern_adoption_queue_html( array $candidates ) {
	$rows = '';
	foreach ( $candidates as $c ) {
		if ( is_array( $c ) ) {
			$rows .= pattern_adoption_candidate_html( $c );
		}
	}
	$heading = sprintf(
		/* translators: %d is the count of pattern-adoption candidates to review */
		_n( 'Review %d candidate', 'Review %d candidates', count( $candidates ), 'signal-and-noise-tools' ),
		count( $candidates )
	);
	return \snt_kit_tag(
		'os-disclosure',
		array( 'heading' => $heading ),
		'<p class="snt-hint">' . \snt_kit_esc( __( 'Suggest opens its editor inside the classic table cell, which this window does not paint: run Suggest and Apply from the classic Content → Pattern Adoption page. A dismissed candidate stays on screen showing "Dismissing…" until the next scan — the dismissal itself is already written.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_tag( 'os-stack', array( 'gap' => '8' ), $rows )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_pattern_adoption( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$scan    = \snt_pattern_adoption_last_scan();
	// Classic: inc/pattern-adoption-admin.php tests truthiness (`if ( $last_scan )`),
	// not is_array() — a transient holding array() must read as "never scanned".
	$scanned = ! empty( $scan );
	$body    = ( $scanned ? pattern_adoption_count_badge( $scan ) : '' )
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'Scans existing /notes posts for blockquote and ordered-list blocks that could be upgraded to the v9.2.0 pull-quote and steps-enumerated patterns. Pure structural detection: no AI calls. Editorial: every upgrade is reviewed before apply.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_form(
			'pattern_adoption_scan',
			'',
			array( 'submit' => $scanned ? __( 'Re-scan opportunities', 'signal-and-noise-tools' ) : __( 'Scan for opportunities', 'signal-and-noise-tools' ) )
		);
	if ( $scanned ) {
		$candidates = (array) ( $scan['candidates'] ?? array() );
		$body      .= empty( $candidates )
			? \snt_kit_empty( __( 'No opportunities found.', 'signal-and-noise-tools' ), __( 'All eligible blocks are either already pattern-upgraded or have been dismissed.', 'signal-and-noise-tools' ) )
			: pattern_adoption_queue_html( $candidates );
	}
	return \snt_kit_section( __( 'Pattern adoption', 'signal-and-noise-tools' ), $body );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/pattern-adoption'] = __NAMESPACE__ . '\\paint_content_pattern_adoption';
		return $painters;
	}
);
