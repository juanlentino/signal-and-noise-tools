<?php
/**
 * Signal & Noise: the rights evidence controls on the classic Machine Readers
 * leaf (Monitoring > Machine Readers). The native twin is
 * apps/sn-dashboard/parts/leaves/monitoring-machine-readers-evidence.php.
 *
 * A status line (held months, months in review, backlog, next pass), then
 * per month either composed and waiting out its review window or held: a
 * "View payloads" GET door that downloads what would post (the stored bytes,
 * else a dry run; nothing posted), and for a waiting month "Hold" and "Post
 * now" (the owner's bypass of the window), for a held month the reason a rule
 * gave and "Lift hold" (painted only when a dry run can compose). Every POST
 * sits behind a confirm; none runs the pass.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The View door for one month: admin-post.php GET with its nonce, the page,
 * tab and sub named outright (a native window paints without the classic
 * query). Built raw, like sn_resume_action_url().
 *
 * @param string $ym YYYY-MM.
 * @return string
 */
function sn_rights_evidence_view_url( $ym ) {
	return add_query_arg(
		array(
			'action'   => 'sn_rights_evidence_view',
			'page'     => 'sn-monitoring',
			'tab'      => 'monitoring',
			'sub'      => 'machine-readers',
			'month'    => (string) $ym,
			'_wpnonce' => wp_create_nonce( 'sn_rights_evidence_view' ),
		),
		admin_url( 'admin-post.php' )
	);
}

/** The one-at-a-time note both surfaces print above the retractions. */
const SN_RIGHTS_EVIDENCE_RETRACT_NOTE = 'Post one, wait for the ledger\'s checks, then the next.';

/** The published fields, in the order both surfaces show them. */
const SN_RIGHTS_EVIDENCE_RETRACT_LABELS = array(
	'claimed'        => 'Claimed',
	'what_was_wrong' => 'What was wrong',
	'root_cause'     => 'Root cause',
	'what_changed'   => 'What changed',
);

/**
 * The confirm question on a Retract button.
 *
 * @param string $label e.g. "August 2026, openai".
 * @return string
 */
function sn_rights_evidence_retract_confirm( $label ) {
	return sprintf( 'Retract the %s record? The worker signs this text and publishes it on the public, append-only ledger beside the record. It cannot be undone.', $label );
}

/**
 * The status line both surfaces print.
 *
 * @return string Plain text.
 */
function sn_rights_evidence_status_line() {
	$held    = sn_rights_evidence_held( false );
	$review  = array_keys( sn_rights_evidence_pending() );
	$backlog = sn_rights_evidence_backlog();
	$next    = wp_next_scheduled( SN_RIGHTS_EVIDENCE_HOOK );
	return sprintf(
		/* translators: 1: held months, 2: months in review, 3: backlog months, 4: next pass time */
		__( 'Held: %1$s. In review: %2$s. Backlog: %3$s. Next pass: %4$s.', 'signal-and-noise-tools' ),
		$held ? implode( ', ', $held ) : __( 'none', 'signal-and-noise-tools' ),
		$review ? implode( ', ', $review ) : __( 'none', 'signal-and-noise-tools' ),
		$backlog ? implode( ', ', $backlog ) : __( 'none', 'signal-and-noise-tools' ),
		$next ? gmdate( 'Y-m-d H:i', (int) $next ) . ' UTC' : __( 'not scheduled', 'signal-and-noise-tools' )
	);
}

/**
 * The months the controls are painted for: held, or composed and waiting.
 *
 * @return array<string,string> month => 'held' or 'review', oldest first.
 */
function sn_rights_evidence_control_months() {
	$out = array_fill_keys( array_keys( sn_rights_evidence_pending() ), 'review' );
	foreach ( sn_rights_evidence_held( false ) as $ym ) {
		$out[ $ym ] = 'held';
	}
	ksort( $out );
	return $out;
}

/**
 * The line under a month's name: its window, or the reason a rule held it.
 *
 * @param string $ym    YYYY-MM.
 * @param string $state 'held' or 'review'.
 * @return string Plain text; '' for an owner's hold.
 */
function sn_rights_evidence_month_note( $ym, $state ) {
	if ( 'review' === $state ) {
		return 'Composed, not posted: ' . sn_rights_evidence_window_words( (int) ( sn_rights_evidence_pending()[ $ym ] ?? 0 ), time() ) . ', unless held.';
	}
	$why = (array) ( sn_rights_evidence_hold_reasons()[ $ym ] ?? array() );
	return $why ? 'Held by a rule: ' . implode( '; ', $why ) . '.' : '';
}

/**
 * The confirm questions on the month buttons.
 *
 * @param string $what  lift, hold or post.
 * @param string $label e.g. "September 2026".
 * @return string
 */
function sn_rights_evidence_confirm( $what, $label ) {
	$q = array(
		'lift' => 'Lift the hold on %s? Its composed records post to the public, append-only ledger at the next daily pass once their review window has passed; a month with nothing composed is composed first and then waits out its window.',
		'hold' => 'Hold %s? Nothing of it posts until you lift the hold. Its composed bytes stay stored and viewable.',
		'post' => 'Post %s now, before its review window ends? The worker signs and anchors every composed record of the month on the public, append-only ledger. A posted record cannot be edited, only retracted.',
	);
	return sprintf( $q[ $what ], $label );
}

/**
 * The classic section.
 */
function sn_admin_render_rights_evidence() {
	if ( ! function_exists( 'sn_rights_evidence_held' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$can    = function_exists( 'sn_rights_evidence_can_compose' ) && sn_rights_evidence_can_compose();
	$months = sn_rights_evidence_control_months();
	echo '<div class="sn-fieldset" id="sn-rights-evidence">';
	echo '<h2 class="sn-fieldset-h">Rights evidence</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html( sn_rights_evidence_status_line() ) . '</p>';
	foreach ( $months as $ym => $state ) {
		$label = gmdate( 'F Y', (int) strtotime( $ym . '-01T00:00:00Z' ) );
		$note  = sn_rights_evidence_month_note( $ym, $state );
		echo '<div class="sn-fieldset-actions">';
		if ( '' !== $note ) {
			echo '<p><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $note ) . '</p>';
		}
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( sn_rights_evidence_view_url( $ym ) ) . '">' . esc_html( sprintf( 'View %s payloads', $label ) ) . '</a> ';
		if ( 'held' === $state && $can ) {
			sn_admin_render_rights_evidence_button( 'sn_rights_evidence_lift', $ym, sprintf( 'Lift %s hold', $label ), sn_rights_evidence_confirm( 'lift', $label ) );
		}
		if ( 'review' === $state ) {
			sn_admin_render_rights_evidence_button( 'sn_rights_evidence_hold', $ym, sprintf( 'Hold %s', $label ), sn_rights_evidence_confirm( 'hold', $label ) );
			if ( function_exists( 'sn_rights_evidence_can_retract' ) && sn_rights_evidence_can_retract() ) {
				sn_admin_render_rights_evidence_button( 'sn_rights_evidence_post_now', $ym, sprintf( 'Post %s now', $label ), sn_rights_evidence_confirm( 'post', $label ) );
			}
		}
		echo '</div>';
	}
	if ( ! $can && sn_rights_evidence_held( false ) ) {
		echo '<p class="sn-field-helper">Lift appears once the provenance worker is set up and the sensor answers: a month that cannot be composed stays held.</p>';
	}
	sn_admin_render_rights_evidence_retract();
	echo '</div>';
}

/**
 * One month button: a POST form with its own nonce, behind a confirm.
 *
 * @param string $action  The admin-post action (sn_ prefixed); also the nonce action.
 * @param string $ym      YYYY-MM.
 * @param string $text    Button text.
 * @param string $confirm The confirm question.
 */
function sn_admin_render_rights_evidence_button( $action, $ym, $text, $confirm ) {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '"><input type="hidden" name="tab" value="monitoring"><input type="hidden" name="sub" value="machine-readers">';
	echo '<input type="hidden" name="month" value="' . esc_attr( $ym ) . '">';
	wp_nonce_field( $action );
	echo '<button type="submit" name="action" value="' . esc_attr( $action ) . '" class="button" data-snt-confirm="' . esc_attr( $confirm ) . '">' . esc_html( $text ) . '</button>';
	echo '</form>';
}

/**
 * Per retractable record: the exact text that will be published, then a
 * one-button Retract form behind a confirm. Nothing when none is eligible.
 */
function sn_admin_render_rights_evidence_retract() {
	$rows = function_exists( 'sn_rights_evidence_can_retract' ) && sn_rights_evidence_can_retract() ? sn_rights_evidence_retractable() : array();
	if ( ! $rows ) {
		return;
	}
	echo '<h3>Retractions</h3>';
	echo '<p class="sn-field-helper">' . esc_html( SN_RIGHTS_EVIDENCE_RETRACT_NOTE ) . '</p>';
	foreach ( $rows as $r ) {
		$label = gmdate( 'F Y', (int) strtotime( $r['month'] . '-01T00:00:00Z' ) ) . ', ' . $r['family'];
		echo '<div class="sn-fieldset-actions"><p><strong>' . esc_html( $label ) . '</strong>: <code>' . esc_html( (string) $r['entry']['ledger_path'] ) . '</code></p>';
		foreach ( SN_RIGHTS_EVIDENCE_RETRACT_LABELS as $key => $name ) {
			echo '<p><strong>' . esc_html( $name ) . ':</strong> ' . esc_html( (string) $r['text'][ $key ] ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '"><input type="hidden" name="tab" value="monitoring"><input type="hidden" name="sub" value="machine-readers">';
		echo '<input type="hidden" name="month" value="' . esc_attr( $r['month'] ) . '"><input type="hidden" name="family" value="' . esc_attr( $r['family'] ) . '">';
		wp_nonce_field( 'sn_rights_evidence_retract' );
		echo '<button type="submit" name="action" value="sn_rights_evidence_retract" class="button" data-snt-confirm="' . esc_attr( sn_rights_evidence_retract_confirm( $label ) ) . '">' . esc_html( 'Retract ' . $label ) . '</button>';
		echo '</form></div>';
	}
}
