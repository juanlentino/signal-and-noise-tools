<?php
/**
 * S&N Dashboard, Measurement > Search Console: the Setup card.
 *
 * 2026-10-16 leaf anatomy (docs/kit-leaf-anatomy.md): the form that sets the
 * service-account key is setup, used once per key, so once a key parses it
 * is a closed fold of one Setup card (snt_kit_setup_card(),
 * inc/openstation-kit-anatomy.php) whose line says a key is stored. The fold
 * opens itself while no key is stored or the stored one does not parse; the
 * onboarding steps and the "no longer parses" warning stay open in the
 * readout box beside it. Same field, same action as the box it replaces.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Setup card: the credential form, folded once a key parses.
 *
 * @param array<string,mixed> $s From search_console_state().
 * @return string
 */
function search_console_setup_html( array $s ) {
	if ( null !== $s['identity'] ) {
		$summary = __( 'Stored; paste a new key file to replace it', 'signal-and-noise-tools' );
	} elseif ( $s['stored'] ) {
		$summary = __( 'Stored, but it does not parse', 'signal-and-noise-tools' );
	} else {
		$summary = __( 'Not set', 'signal-and-noise-tools' );
	}
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array(
			array(
				'title'   => __( 'Set the credential', 'signal-and-noise-tools' ),
				'summary' => $summary,
				'body'    => search_console_credential_form_html( $s ),
				'open'    => null === $s['identity'],
			),
		)
	);
}
