<?php
/**
 * Signal & Noise Tools: the leaf anatomy, promoted from Measurement › Analytics.
 *
 * Owner, 2026-10-16, on the redesigned Analytics leaf: the structure goes
 * "throughout the plugin, in some form or shape". Three parts, each a
 * composition of kit elements that already exist:
 *   - a status line: one row of chips, the door on the right;
 *   - a Setup card: one card of earned folds, each closed summary the
 *     setting's current value;
 *   - a How this works fold: long explanatory prose, out of the way.
 * When to use each (and when not): docs/kit-leaf-anatomy.md. Every fold here
 * goes through snt_kit_fold() (inc/openstation-kit-fold.php), so this file is
 * listed in tests/sn-dashboard-no-folds.php with its reason, and so is every
 * leaf that calls a helper below. Required by inc/openstation-kit-display.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A status line: one row of chips (what the leaf's state is, at a glance) and
 * an optional door on the right, wrapping as one block on a narrow window.
 * Only for a leaf that has state; status is never folded.
 *
 * Each chip is `['tone' => ok|warn|bad|neutral, 'label' => string, 'title' => string]`.
 * A toned chip paints `<os-badge>`, a neutral one the plain `<os-chip>`;
 * `title` (optional) is the hover text, e.g. every check a count stands for.
 *
 * @param array<int,array<string,string>> $chips     The chips, in reading order.
 * @param string                          $door_html Painted door (snt_kit_door()), '' for none.
 * @return string
 */
function snt_kit_status_line( array $chips, $door_html = '' ) {
	$out = '';
	foreach ( $chips as $chip ) {
		$label = (string) ( $chip['label'] ?? '' );
		if ( '' === $label ) {
			continue;
		}
		$tone = (string) ( $chip['tone'] ?? 'neutral' );
		if ( 'neutral' === $tone || '' === $tone ) {
			$out .= snt_kit_chip( $label );
			continue;
		}
		$out .= snt_kit_tag(
			'os-badge',
			array(
				'tone'  => snt_kit_tone( 'bad' === $tone ? 'danger' : $tone ),
				'title' => isset( $chip['title'] ) && '' !== (string) $chip['title'] ? (string) $chip['title'] : null,
			),
			snt_kit_esc( $label )
		);
	}
	return '<div class="snt-status-line"><os-cluster gap="8">' . $out . '</os-cluster>' . (string) $door_html . '</div>';
}

/**
 * A Setup card: settings and reference, changed once in a while, one fold
 * each. Each fold is `['title', 'summary', 'body', 'open' => bool]`: the
 * closed summary states the current value, on the title's line, and `open`
 * is true when something inside needs the reader now (a setting unset, a
 * reading that failed). A fold with no summary paints open (snt_kit_fold()).
 *
 * @param string                           $title       The card's heading.
 * @param array<int,array<string,mixed>>   $folds       The folds, in order.
 * @param string                           $description One line under the heading, '' for none.
 * @return string
 */
function snt_kit_setup_card( $title, array $folds, $description = '' ) {
	$inner = '';
	foreach ( $folds as $fold ) {
		if ( ! is_array( $fold ) || '' === (string) ( $fold['title'] ?? '' ) ) {
			continue;
		}
		$inner .= snt_kit_fold( (string) $fold['title'], (string) ( $fold['summary'] ?? '' ), (string) ( $fold['body'] ?? '' ), ! empty( $fold['open'] ) );
	}
	return '' === $inner ? '' : snt_kit_section( $title, $inner, $description );
}

/**
 * The small "How this works" fold, for long explanatory prose a card would
 * otherwise lead with. Reference by definition, so it starts closed.
 *
 * @param string $summary What the closed fold says, one sentence.
 * @param string $body    The prose (painted HTML).
 * @return string
 */
function snt_kit_how_fold( $summary, $body ) {
	return snt_kit_fold( __( 'How this works', 'signal-and-noise-tools' ), $summary, $body );
}
