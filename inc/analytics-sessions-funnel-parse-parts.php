<?php
/**
 * Signal & Noise Tools: Analytics Sessions, the funnels textarea parser and its round-trip back to text.
 *
 * Split from inc/analytics-sessions.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse the Measurement → Analytics "Session funnels" textarea into the funnel
 * shape sn_analytics_session_funnels() returns — one funnel per line:
 *
 *     Name: /entry > /step > /goal
 *
 * Every parsed step is an exact-match path step (array{match:'path',value,
 * prefix:false}) — the textarea format has no syntax for prefix matching or
 * custom-event ('ce') goals; those remain code-only via the
 * 'sn_analytics_session_funnels' filter.
 *
 * Rejections (each recorded as one errors[] entry naming the 1-based line
 * number, in the caller-facing text a flash notice can show verbatim, AND one
 * errors_detail[] entry — reason-surfacing task — carrying the same info
 * structured as {line,kind,message}):
 *   - a line with no ':' separator                              (kind: colon)
 *   - an empty funnel name                                      (kind: name)
 *   - a step that (after leading-slash normalization) contains whitespace or a
 *     ':' — a well-formed path step has neither, so this catches malformed
 *     shapes upstream (most commonly a double-colon line like "Name:: /a > /b",
 *     whose OWN extra ':' used to survive into the first step as "/: /a")
 *                                                                 (kind: step)
 *   - fewer than 2 steps                                         (kind: few)
 *   - more than SN_ANALYTICS_FUNNELS_MAX_STEPS steps             (kind: many)
 *   - more than SN_ANALYTICS_FUNNELS_MAX funnel lines            (kind: many)
 *   - name over 80 chars or steps segment over 200 chars         (kind: long)
 *
 * @since S2 (v9.42.0 arc); errors_detail added (reason-surfacing task).
 * @param string $raw Raw textarea content (already wp_unslash()ed by the caller).
 * @return array{funnels:array,errors:array<string>,errors_detail:array<array{line:int,kind:string,message:string}>}
 */
function sn_analytics_parse_funnels( $raw ) {
	$raw = (string) $raw;
	if ( '' === trim( $raw ) ) {
		return array(
			'funnels'       => array(),
			'errors'        => array(),
			'errors_detail' => array(),
		);
	}

	$funnels       = array();
	$errors        = array();
	$errors_detail = array();
	$lines_seen    = 0;
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $i => $raw_line ) {
		$line_num = $i + 1;
		$line     = trim( (string) $raw_line );
		if ( '' === $line ) {
			continue;
		}

		++$lines_seen;
		if ( $lines_seen > SN_ANALYTICS_FUNNELS_MAX ) {
			$err = sn_analytics_funnels_error(
				$line_num,
				'many',
				sprintf(
					/* translators: %d: max funnel count */
					__( 'too many funnels (max %d): this line was skipped.', 'signal-and-noise-tools' ),
					SN_ANALYTICS_FUNNELS_MAX
				)
			);
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		if ( false === strpos( $line, ':' ) ) {
			$err             = sn_analytics_funnels_error( $line_num, 'colon', sn_analytics_funnels_kind_message( 'colon' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		list( $name_raw, $steps_raw ) = explode( ':', $line, 2 );
		$name                         = trim( $name_raw );
		if ( '' === $name ) {
			$err             = sn_analytics_funnels_error( $line_num, 'name', sn_analytics_funnels_kind_message( 'name' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}
		// Length clamps (S2 §8, final review): admin-only + escaped everywhere,
		// so the risk is option bloat, not injection — but a 10k-char paste is
		// never a funnel. 80/200 comfortably exceed any real name/path.
		if ( strlen( $name ) > 80 || strlen( $steps_raw ) > 200 ) {
			$err             = sn_analytics_funnels_error( $line_num, 'long', sn_analytics_funnels_kind_message( 'long' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		$steps    = array();
		$bad_step = false;
		foreach ( explode( '>', $steps_raw ) as $step_raw ) {
			$step = trim( $step_raw );
			if ( '' === $step ) {
				continue;
			}
			if ( '/' !== substr( $step, 0, 1 ) ) {
				$step = '/' . $step;
			}
			// A well-formed path step has neither whitespace nor a ':' — either one
			// means the line's shape was wrong upstream (the double-colon case: the
			// first colon splits name/steps, so a SECOND colon rides along inside
			// what looks like the first step's value instead of erroring cleanly).
			if ( 1 === preg_match( '/[\s:]/', $step ) ) {
				$bad_step = true;
				break;
			}
			$steps[] = $step;
		}

		if ( $bad_step ) {
			$err             = sn_analytics_funnels_error( $line_num, 'step', sn_analytics_funnels_kind_message( 'step' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		if ( count( $steps ) < 2 ) {
			$err             = sn_analytics_funnels_error( $line_num, 'few', sn_analytics_funnels_kind_message( 'few' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}
		if ( count( $steps ) > SN_ANALYTICS_FUNNELS_MAX_STEPS ) {
			$err = sn_analytics_funnels_error(
				$line_num,
				'many',
				sprintf(
					/* translators: %d: max step count */
					__( 'too many steps (max %d).', 'signal-and-noise-tools' ),
					SN_ANALYTICS_FUNNELS_MAX_STEPS
				)
			);
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		$funnels[] = array(
			'title' => $name,
			'steps' => array_map(
				function ( $path ) {
					return array(
						'match'  => 'path',
						'value'  => $path,
						'prefix' => false,
					);
				},
				$steps
			),
		);
	}

	return array(
		'funnels'       => $funnels,
		'errors'        => $errors,
		'errors_detail' => $errors_detail,
	);
}

/**
 * Serialize a funnel list back into the "Name: /a > /b" textarea format
 * sn_analytics_parse_funnels() reads — the inverse operation, used to prefill
 * the Session funnels card with the CURRENTLY configured analytics.funnels
 * setting so the owner edits what is actually live, not a blank box.
 *
 * A funnel is REPRESENTABLE — and serializes as one line — only when its
 * emitted line would parse back to the SAME funnel:
 *   - every step is {match:'path', prefix:false} (the textarea format has no
 *     syntax for 'ce' goals or prefix matching),
 *   - every step value starts with '/' and contains no whitespace, '>' or ':'
 *     (a '>' would re-parse as EXTRA steps — silent corruption; ':' or
 *     whitespace would make the parser REJECT the line, wedging every future
 *     save on a line the owner never typed),
 *   - the title contains no ':' and no newline (a second colon would re-split
 *     name/steps at the wrong place; a newline would split into bogus lines).
 * Anything else is OMITTED entirely — inventing a comment syntax the parser
 * doesn't understand would be dishonest (it would look editable but silently
 * vanish on save). Such funnels stay active via the
 * 'sn_analytics_session_funnels' filter; the render layer's help text says so.
 *
 * ROUND-TRIP GUARANTEE: for any $funnels that is itself the ['funnels'] output
 * of sn_analytics_parse_funnels() (i.e. already normalized — trimmed titles,
 * leading-slash path values, exact-match steps, within the count/step caps),
 * parse( to_text( $funnels ) )['funnels'] === $funnels. Parser output always
 * satisfies the representability rules above, so nothing it produced is ever
 * omitted.
 *
 * @since S2 (v9.42.0 arc)
 * @param array $funnels List of array{title:string,steps:array}.
 * @return string Textarea-ready text, one funnel per line (possibly '').
 */
function sn_analytics_funnels_to_text( array $funnels ) {
	$lines = array();
	foreach ( $funnels as $funnel ) {
		if ( ! is_array( $funnel ) ) {
			continue;
		}
		$title = isset( $funnel['title'] ) ? trim( (string) $funnel['title'] ) : '';
		$steps = isset( $funnel['steps'] ) && is_array( $funnel['steps'] ) ? $funnel['steps'] : array();
		if ( '' === $title || 1 === preg_match( '/[:\r\n]/', $title ) || empty( $steps ) ) {
			continue;
		}

		$values        = array();
		$representable = true;
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || 'path' !== ( $step['match'] ?? '' ) || ! empty( $step['prefix'] ) ) {
				$representable = false;
				break;
			}
			$value = (string) ( $step['value'] ?? '' );
			// Round-trip guard: only a value the parser could itself have produced
			// re-parses to itself (leading '/', no whitespace/'>'/':').
			if ( '/' !== substr( $value, 0, 1 ) || 1 === preg_match( '/[\s>:]/', $value ) ) {
				$representable = false;
				break;
			}
			$values[] = $value;
		}

		if ( ! $representable || count( $values ) < 2 ) {
			continue;
		}
		$lines[] = $title . ': ' . implode( ' > ', $values );
	}
	return implode( "\n", $lines );
}

