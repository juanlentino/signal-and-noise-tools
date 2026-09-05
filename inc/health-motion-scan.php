<?php
/**
 * Signal & Noise Tools — Motion that asks first: the reduced-motion scan
 * (check #20, REPORT ONLY — the Accessibility planned row's report-first
 * landing).
 *
 * The row: "every animation paired with its reduced-motion counterpart,
 * verified by a report-first scan — respecting a visitor's motion setting
 * checked, not assumed." This tier reads the declared stylesheets — the SAME
 * sheet population and rule parser as the contrast usage tier — and asks one
 * question per motion declaration. A declaration is covered two ways, both
 * already idioms in these sheets:
 *
 *   GATED       — declared inside `@media (prefers-reduced-motion:
 *                 no-preference)`. Motion that literally asks first; it needs
 *                 no counterpart because it never runs for a reduced-motion
 *                 visitor.
 *   NEUTRALIZED — a rule under `prefers-reduced-motion: reduce` sets the SAME
 *                 KIND to none for that selector (exact selector, a member of
 *                 a selector list, or the universal `*`), in the same sheet or
 *                 ANY scanned sheet — a global reset in one sheet honestly
 *                 covers the fleet, and modelling it per-sheet would flag
 *                 motion a reduced-motion visitor never sees.
 *
 * The kinds are SEPARATE claims: `transition: none` silences no keyframe
 * animation, and vice versa — a reset of the wrong kind is not a counterpart.
 *
 * Declared-tier limits, stated rather than hidden (the coverage sentence in
 * the report carries them): script-driven motion (Web Animations, scroll
 * handlers, JS class toggles) is invisible to a stylesheet reader; longhand
 * `transition-property` without the shorthand is watched as a limit, not
 * flagged (the shorthand is the house idiom); durations are not parsed, so a
 * declared 0s "motion" would count — none exists in these sheets today.
 *
 * REPORT ONLY: the packed check carries zero findings by design. The report
 * is the deliverable; fixes are a later, separate step taken against motion a
 * reduced-motion visitor would actually meet.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which motion kinds a rule body declares. `none` (with or without
 * !important) is a reset, not motion.
 *
 * @param string $body Rule body (between the braces).
 * @return string[] Subset of ['transition','animation'], in that order.
 */
function sn_health_motion_kinds( $body ) {
	$body  = (string) $body;
	$kinds = array();
	if ( preg_match( '/(?:^|;)\s*transition\s*:\s*([^;}]+)/i', $body, $m )
		&& ! preg_match( '/^\s*none\s*(?:!important)?\s*$/i', $m[1] ) ) {
		$kinds[] = 'transition';
	}
	if ( preg_match( '/(?:^|;)\s*animation(?:-name)?\s*:\s*([^;}]+)/i', $body, $m )
		&& ! preg_match( '/^\s*none\s*(?:!important)?\s*$/i', $m[1] ) ) {
		$kinds[] = 'animation';
	}
	return $kinds;
}

/**
 * Does a reduce-block body neutralize one motion kind? Only `none` counts —
 * declaring different motion under reduce is not a counterpart.
 *
 * @param string $body Rule body.
 * @param string $kind 'transition' or 'animation'.
 * @return bool
 */
function sn_health_motion_neutralizes( $body, $kind ) {
	$prop = 'animation' === $kind ? 'animation(?:-name)?' : 'transition';
	return 1 === preg_match( '/(?:^|;)\s*' . $prop . '\s*:\s*none\s*(?:!important)?\s*(?:;|$)/i', (string) $body );
}

/**
 * Is this at-rule context a reduced-motion GATE (no-preference) or a REDUCE
 * block? Context strings come from the contrast parser, which carries each
 * rule's innermost at-rule prelude verbatim.
 *
 * @param string $context At-rule context ('' when unconditional).
 * @return string 'gate' | 'reduce' | ''
 */
function sn_health_motion_context_kind( $context ) {
	$context = strtolower( (string) $context );
	if ( false === strpos( $context, 'prefers-reduced-motion' ) ) {
		return '';
	}
	return false !== strpos( $context, 'no-preference' ) ? 'gate' : 'reduce';
}

/**
 * The report, computed over label => css STRINGS — pure, so the fixture
 * tests and the live path run identical logic. The live wrapper reads the
 * files and calls this.
 *
 * @param array<string,string> $sheets label => raw CSS.
 * @return array{scanned:int,motion_total:int,gated:int,neutralized:int,uncovered:array<int,array{sheet:string,selector:string,kind:string}>}
 */
function sn_health_motion_report_from_sheets( $sheets ) {
	$motion      = array(); // Each: sheet, selector, kind.
	$neutralizers = array(); // Each: selectors[], kind-coverage via body.
	$scanned     = 0;

	foreach ( (array) $sheets as $label => $css ) {
		$css = (string) $css;
		if ( '' === $css ) {
			continue;
		}
		$scanned++;
		foreach ( sn_health_contrast_usage_rules( $css ) as $rule ) {
			$ctx = sn_health_motion_context_kind( (string) ( $rule['at'] ?? '' ) );
			if ( 'reduce' === $ctx ) {
				$neutralizers[] = array(
					'selectors' => array_map( 'trim', explode( ',', (string) $rule['sel'] ) ),
					'body'      => (string) $rule['body'],
				);
				continue;
			}
			foreach ( sn_health_motion_kinds( (string) $rule['body'] ) as $kind ) {
				$motion[] = array(
					'sheet'    => (string) $label,
					'selector' => trim( (string) $rule['sel'] ),
					'kind'     => $kind,
					'gated'    => 'gate' === $ctx,
				);
			}
		}
	}

	$gated       = 0;
	$neutralized = 0;
	$uncovered   = array();
	foreach ( $motion as $m ) {
		if ( $m['gated'] ) {
			$gated++;
			continue;
		}
		$covered = false;
		foreach ( $neutralizers as $n ) {
			if ( ! sn_health_motion_neutralizes( $n['body'], $m['kind'] ) ) {
				continue;
			}
			if ( in_array( '*', $n['selectors'], true ) || in_array( $m['selector'], $n['selectors'], true ) ) {
				$covered = true;
				break;
			}
		}
		if ( $covered ) {
			$neutralized++;
		} else {
			$uncovered[] = array( 'sheet' => $m['sheet'], 'selector' => $m['selector'], 'kind' => $m['kind'] );
		}
	}

	return array(
		'scanned'      => $scanned,
		'motion_total' => count( $motion ),
		'gated'        => $gated,
		'neutralized'  => $neutralized,
		'uncovered'    => $uncovered,
	);
}

/**
 * The live report: same sheet population as the contrast usage tier (front
 * sheets only; admin sheets excluded by their enqueue hook, the v10.92.6
 * lesson inherited rather than re-derived).
 *
 * @return array
 */
function sn_health_motion_report() {
	if ( ! function_exists( 'sn_health_contrast_usage_sources' ) || ! function_exists( 'sn_health_contrast_usage_rules' ) ) {
		return sn_health_motion_report_from_sheets( array() );
	}
	$sheets = array();
	foreach ( sn_health_contrast_usage_sources() as $label => $path ) {
		$sheets[ $label ] = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme/plugin asset, not a remote fetch.
	}
	return sn_health_motion_report_from_sheets( $sheets );
}

/**
 * Check #20 — REPORT ONLY, the contrast-tokens shape: zero findings by
 * design, the payload rides the open pack as a `report` key.
 *
 * @return array
 */
function sn_health_check_motion_scan() {
	$report = sn_health_motion_report();

	$packed = sn_health_pack_check(
		__( 'Motion asks first (reduced-motion, report only)', 'signal-and-noise-tools' ),
		array(), // Report-first: findings are the report's job, fixes a later step.
		__( 'Report only — no action from this check. Each uncovered row is a declared animation or transition with no reduced-motion counterpart; fixes land as a later, separate step.', 'signal-and-noise-tools' ), null
	);

	$packed['report'] = array_merge( $report, array(
		'coverage' => __( 'Declared tier: every animation and transition in the scanned front stylesheets, checked for a reduced-motion counterpart — gated behind no-preference, or set to none under reduce (same sheet or a global reset; the kinds are separate claims). Script-driven motion (JS class toggles, Web Animations, scroll handlers) is invisible to a stylesheet reader, so a clean sweep here is not proof a reduced-motion visitor sees no motion.', 'signal-and-noise-tools' ),
	) );

	return $packed;
}
