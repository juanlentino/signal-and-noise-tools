<?php
/**
 * Signal & Noise Tools: the résumé editor's receipt fields (classic form; the
 * native twin is resume_receipt() in
 * apps/sn-dashboard/parts/leaves/content-resume-variants.php). Data rules in
 * inc/resume-receipts.php. Résumé field names only, like the variant controls.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The type choices, value => label. */
const SN_RSM_RECEIPT_CHOICES = array(
	'verification' => 'Verification (mark: verify)',
	'publication'  => 'Publication (mark: paper)',
	'repository'   => 'Repository (mark: code)',
	'membership'   => 'Membership (mark: verify)',
);

/**
 * A receipt: its URL and type, under $prefix.
 *
 * @param string $prefix Field prefix (an entry, or a bullet's slot in bullet_receipts).
 * @param array  $row    {receipt_url?, receipt_type?}.
 */
function sn_rsm_receipt( $prefix, $row ) {
	if ( ! sn_rsm_is_resume( $prefix ) ) {
		return;
	}
	echo '<div class="sn-rsm-pair sn-rsm-receipt">';
	sn_rsm_input( $prefix . '[receipt_url]', (string) ( $row['receipt_url'] ?? '' ), 'Receipt URL', 'https://…', 'Optional. The page that backs this (https only); /resume shows a small mark that links it. Not in the PDFs.' );
	echo '<label class="sn-rsm-field"><span class="sn-rsm-label">Receipt type</span><select name="' . esc_attr( $prefix . '[receipt_type]' ) . '">';
	foreach ( SN_RSM_RECEIPT_CHOICES as $v => $label ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $row['receipt_type'] ?? 'verification' ), $v, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></label></div>';
}
