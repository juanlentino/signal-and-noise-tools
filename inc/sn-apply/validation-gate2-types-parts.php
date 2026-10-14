<?php
/**
 * Signal & Noise Tools: sn_apply gates 1 and 2, the per-type gate 2 checks (merge_tags, schedule_cron_event, dismiss).
 *
 * Split from inc/sn-apply/validation.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate 2 for `merge_tags`.
 *
 * Mirrors the ability's OWN required input (`from_slugs`, `into_slug`, read from
 * inc/abilities-content.php) rather than anything inferred from the change
 * type's name — the mistake that produced two wrong `dismiss` designs before
 * this one.
 *
 * @param array $change
 * @return array
 */
function snt_sn_apply_gate2_merge_tags( $change ) {
	$payload  = isset( $change['payload'] ) && is_array( $change['payload'] ) ? $change['payload'] : array();
	$findings = array();
	$identity = 'merge_tags|' . (string) ( $payload['into_slug'] ?? '' );

	$from = $payload['from_slugs'] ?? null;
	if ( ! is_array( $from ) || empty( $from ) ) {
		$findings[] = snt_sn_validate_finding(
			'merge_tags',
			'payload_complete',
			'error',
			__( 'merge_tags requires payload.from_slugs (a non-empty array of term slugs).', 'signal-and-noise-tools' ),
			null,
			'from_slugs',
			array(),
			$identity
		);
	}
	if ( '' === (string) ( $payload['into_slug'] ?? '' ) ) {
		$findings[] = snt_sn_validate_finding(
			'merge_tags',
			'payload_complete',
			'error',
			__( 'merge_tags requires payload.into_slug.', 'signal-and-noise-tools' ),
			null,
			'into_slug',
			array(),
			$identity
		);
	}
	// Merging a term into ITSELF is a no-op that would still delete the source:
	// refuse rather than reassign-then-delete the same term.
	if ( is_array( $from ) && in_array( (string) ( $payload['into_slug'] ?? '' ), array_map( 'strval', $from ), true ) ) {
		$findings[] = snt_sn_validate_finding(
			'merge_tags',
			'no_self_merge',
			'error',
			__( 'into_slug appears in from_slugs: merging a term into itself would delete it.', 'signal-and-noise-tools' ),
			(string) ( $payload['into_slug'] ?? '' ),
			'a slug not present in from_slugs',
			array(),
			$identity
		);
	}

	return array( 'passed' => empty( $findings ), 'findings' => $findings );
}

/**
 * Gate 2 for `schedule_cron_event`.
 *
 * Mirrors the ability's OWN input (`hook`, optional `args`, optional `delay`,
 * read from inc/abilities-cron.php) rather than anything inferred from the
 * change type's name. The SN-owned bound itself is NOT re-checked here — that
 * lives in snt_cron_schedule_event_impl() and would drift if copied. Gate 2
 * fences the payload's SHAPE; the impl owns which hooks are schedulable.
 *
 * @since 13.49.0
 * @param array $change
 * @return array
 */
function snt_sn_apply_gate2_schedule_cron_event( $change ) {
	$payload  = isset( $change['payload'] ) && is_array( $change['payload'] ) ? $change['payload'] : array();
	$findings = array();
	$identity = 'schedule_cron_event|' . (string) ( $payload['hook'] ?? '' );

	if ( '' === (string) ( $payload['hook'] ?? '' ) ) {
		$findings[] = snt_sn_validate_finding(
			'schedule_cron_event',
			'payload_complete',
			'error',
			__( 'schedule_cron_event requires payload.hook.', 'signal-and-noise-tools' ),
			null,
			'hook',
			array(),
			$identity
		);
	}
	if ( isset( $payload['args'] ) && ! is_array( $payload['args'] ) ) {
		$findings[] = snt_sn_validate_finding(
			'schedule_cron_event',
			'payload_shape',
			'error',
			__( 'payload.args must be an array when supplied — cron matches events by exact args signature.', 'signal-and-noise-tools' ),
			null,
			'args',
			array(),
			$identity
		);
	}
	// Bound the delay HERE as well as in the impl, because a caller reading the
	// dry run must see the refusal rather than a silently clamped booking.
	if ( isset( $payload['delay'] ) ) {
		$delay = $payload['delay'];
		if ( ! is_numeric( $delay ) || (int) $delay < 0 || (int) $delay > SNT_SN_APPLY_CRON_DELAY_MAX ) {
			$findings[] = snt_sn_validate_finding(
				'schedule_cron_event',
				'delay_in_range',
				'error',
				__( 'payload.delay must be between 0 and 3600 seconds.', 'signal-and-noise-tools' ),
				is_scalar( $delay ) ? (string) $delay : null,
				'0-3600',
				array(),
				$identity
			);
		}
	}

	return array( 'passed' => empty( $findings ), 'findings' => $findings );
}

/**
 * Gate 2 for `dismiss`.
 *
 * THE CONTRACT IS THE ABILITY'S, NOT THE TOOL'S. dismiss-candidate
 * (inc/abilities-dismiss.php) requires surface + post_id + block_fingerprint +
 * candidate_type. It does NOT take sn-scan's candidate_id — an earlier design
 * assumed it did, and assumed the candidate_id was therefore the staleness
 * binding. Both were wrong; the payload mirrors the real input_schema.
 *
 * THE SURFACE ENUM IS EXACTLY THREE. sn-scan has NINE scan_types, so six of
 * them have no dismissal store at all (sn-scan's own description says so). A
 * surface with no store must refuse BY NAME: a silent no-op would report a
 * dismissal that was never recorded, and the caller would keep re-seeing the
 * candidate with no way to tell why.
 *
 * @since 13.47.0
 * @param array $change
 * @return array|WP_Error
 */
function snt_sn_apply_gate2_dismiss( $change ) {
	$payload  = isset( $change['payload'] ) && is_array( $change['payload'] ) ? $change['payload'] : array();
	$findings = array();
	$identity = 'dismiss|' . (string) ( $payload['block_fingerprint'] ?? '' );

	foreach ( array( 'surface', 'block_fingerprint', 'candidate_type' ) as $key ) {
		if ( '' === (string) ( $payload[ $key ] ?? '' ) ) {
			$findings[] = snt_sn_validate_finding(
				'dismiss',
				'payload_complete',
				'error',
				sprintf(
					/* translators: %s: the missing payload key. */
					__( 'dismiss requires payload.%s.', 'signal-and-noise-tools' ),
					$key
				),
				null,
				$key,
				array(),
				$identity
			);
		}
	}

	// THE SURFACE ENUM IS EXACTLY THREE, while sn-scan has NINE scan_types — so
	// six of them have no dismissal store at all (sn-scan's own description says
	// so). Refuse BY NAME: a silent no-op would report a dismissal that was
	// never recorded, and the caller would keep re-seeing the candidate with no
	// way to tell why.
	$surfaces = array( 'block-migrations', 'pattern-adoption', 'corpus-integrity' );
	$surface  = (string) ( $payload['surface'] ?? '' );
	if ( '' !== $surface && ! in_array( $surface, $surfaces, true ) ) {
		$findings[] = snt_sn_validate_finding(
			'dismiss',
			'surface_has_store',
			'error',
			sprintf(
				/* translators: 1: the requested surface, 2: the surfaces that have a store. */
				__( 'No dismissal store exists for surface "%1$s". Dismissible surfaces are: %2$s.', 'signal-and-noise-tools' ),
				$surface,
				implode( ', ', $surfaces )
			),
			$surface,
			implode( ', ', $surfaces ),
			array(),
			$identity
		);
	}

	return array( 'passed' => empty( $findings ), 'findings' => $findings );
}

