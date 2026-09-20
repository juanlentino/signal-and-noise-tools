<?php
/**
 * S&N Dashboard, Security > Login defense: the "Breached passwords" box.
 *
 * The breached-password check (inc/breached-credentials-*.php) has two
 * classic readers and neither is a plugin leaf: the viewer's own memo is an
 * `admin_notices` banner (inc/breached-credentials-login.php), which the
 * desktop never fires, and the site-wide posture is a Site Health row
 * (inc/breached-credentials-surface.php), which the digest repeats. Here the
 * two land in one box beside "Login guard status": the viewer's memo as the
 * notice on top (the same sentence, the profile link as a kit door), else the
 * posture summary as the notice when the verdict is not good, then the three
 * figures the digest prints as facts rows. One record, one derivation
 * (`sn_hibp_health()` and `$sd['set']`, exactly the digest's reads), painted
 * a third time; nothing is computed here.
 *
 * @package SignalNoiseTools
 * @since 17.4.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The viewer's own breached-password memo as the classic banner says it,
 * '' when there is nothing to say. Only the user whose password it is sees it.
 *
 * @return string
 */
function login_defense_memo_html() {
	if ( ! function_exists( 'sn_hibp_login_notice_html' ) || ! function_exists( 'sn_hibp_login_memo' ) ) {
		return '';
	}
	$url = function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#password' : '';
	return \sn_hibp_login_notice_html(
		\sn_hibp_login_memo( (int) get_current_user_id() ),
		$url,
		\snt_kit_link( __( 'open your profile', 'signal-and-noise-tools' ), $url )
	);
}

/**
 * The "Breached passwords" box, or '' when the module is absent (the status
 * box then keeps the row's full width; absent is not zero).
 *
 * @return string
 */
function login_defense_breach_html() {
	if ( ! function_exists( 'sn_hibp_surface_data' ) || ! function_exists( 'sn_hibp_health' ) ) {
		return '';
	}
	// ponytail: sn_hibp_surface_data() walks every user (get_users number=-1, one meta read each) per paint, as Site Health does; the ceiling is the user count. A short transient if the site ever grows users.
	$sd      = \sn_hibp_surface_data();
	$hv      = \sn_hibp_health( $sd, time() );
	$set     = is_array( $sd['set'] ?? null ) ? $sd['set'] : array();
	$summary = '<p class="snt-hint">' . \snt_kit_esc( (string) $hv['summary'] ) . '</p>';
	$recent  = ! empty( $hv['unavailable_recent'] );

	// One notice slot per box. The viewer's memo carries the action (change
	// it), so it wins and the posture summary drops to the hint line; a
	// verdict that is not good is otherwise the notice, and a good one the hint.
	$memo = login_defense_memo_html();
	if ( '' !== $memo ) {
		$top = \snt_kit_notice( 'warn', $memo ) . $summary;
	} elseif ( 'good' === (string) $hv['status'] ) {
		$top = $summary;
	} else {
		$top = \snt_kit_notice( 'warn', \snt_kit_esc( (string) $hv['summary'] ) );
	}

	$rows = array(
		array(
			'label' => __( 'Accounts flagged at login', 'signal-and-noise-tools' ),
			'value' => sprintf(
				/* translators: 1: accounts flagged, 2: accounts checked */
				__( '%1$s of %2$s checked', 'signal-and-noise-tools' ),
				number_format_i18n( (int) $hv['flagged'] ),
				number_format_i18n( (int) $hv['checked'] )
			),
			'tone'  => (int) $hv['flagged'] > 0 ? 'warn' : null,
		),
		array(
			'label' => __( 'Breached passwords refused at set-time', 'signal-and-noise-tools' ),
			'value' => number_format_i18n( (int) ( $set['breached_count'] ?? 0 ) ),
		),
		array(
			'label' => __( 'Fail-closed rejections (API unreachable)', 'signal-and-noise-tools' ),
			'value' => number_format_i18n( (int) ( $set['unavailable_count'] ?? 0 ) )
				. ( $recent ? __( ', recent: the API may be degrading', 'signal-and-noise-tools' ) : '' ),
			'tone'  => $recent ? 'warn' : null,
		),
	);
	return \snt_kit_section( __( 'Breached passwords', 'signal-and-noise-tools' ), $top . \snt_kit_kv( $rows ) );
}
