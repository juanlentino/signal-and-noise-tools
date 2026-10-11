<?php
/**
 * S&N Dashboard, Site → Redirects: the rule ledger and its two forms.
 *
 * 2026-10-11 (owner rule: no collapsible sections). Every rule used to be a
 * fold holding its own edit form and delete form, 39 of them live. Now the
 * leaf reads top-down: tiles, one finding, ONE kit table with visible row
 * actions (Edit sets `sn_edit`; Delete is a tiny confirmed form in the cell),
 * then ONE edit region below the table for the rule `sn_edit` names. With no
 * rule open, the add form sits there instead. Required by site-redirects.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A rule's status code as the store means it: 302, or 301 for anything else.
 *
 * @param array<string,mixed> $r to, status, created_at.
 * @return int
 */
function redirects_code( array $r ) {
	return 302 === (int) ( $r['status'] ?? 301 ) ? 302 : 301;
}

/**
 * When a rule was added; never the epoch (#1228, the classic says the same).
 *
 * @param array<string,mixed> $r       The rule.
 * @param string              $unknown What to say when the store has no date.
 * @return string
 */
function redirects_added( array $r, $unknown ) {
	$ts = (int) ( $r['created_at'] ?? 0 );
	return $ts > 0 ? wp_date( 'Y-m-d', $ts ) : (string) $unknown;
}

/**
 * The reading (rules, permanent, temporary) and the finding (how many leave
 * the site). The store keeps no hit count, and a rule's source leaves the 404
 * log when the rule is made, so no traffic figure is offered.
 *
 * @param array<string,array<string,mixed>> $rules Every rule, newest first.
 * @return string
 */
function redirects_reading_html( array $rules ) {
	$total = count( $rules );
	$temp  = count( array_filter( $rules, static function ( $r ) { return 302 === redirects_code( (array) $r ); } ) );
	$off   = count( array_filter( $rules, static function ( $r ) { return (bool) preg_match( '#^https?://#i', (string) ( $r['to'] ?? '' ) ); } ) );
	$tiles = \snt_kit_grid(
		array(
			\snt_kit_stat( number_format_i18n( $total ), _n( 'Rule', 'Rules', $total, 'signal-and-noise-tools' ) ),
			\snt_kit_stat( number_format_i18n( $total - $temp ), __( 'Permanent', 'signal-and-noise-tools' ), '301' ),
			\snt_kit_stat( number_format_i18n( $temp ), __( 'Temporary', 'signal-and-noise-tools' ), '302' ),
		),
		160,
		10
	);
	if ( 0 === $off ) {
		$finding = __( 'Every rule sends readers to a path on this site.', 'signal-and-noise-tools' );
	} elseif ( $off === $total ) {
		$finding = __( 'Every rule sends readers to another site.', 'signal-and-noise-tools' );
	} else {
		/* translators: 1: rules with an off-site target, 2: rules in total. */
		$finding = sprintf( _n( '%1$s of the %2$s rules sends readers to another site; the rest stay on this one.', '%1$s of the %2$s rules send readers to another site; the rest stay on this one.', $off, 'signal-and-noise-tools' ), number_format_i18n( $off ), number_format_i18n( $total ) );
	}
	return $tiles . '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>';
}

/**
 * One table row: the rule's scannable facts, and its two row actions.
 *
 * @param string              $source Source path.
 * @param array<string,mixed> $r      to, status, created_at.
 * @return array<string,mixed>
 */
function redirects_rule_row( $source, array $r ) {
	$delete = redirects_form(
		'redirect_delete',
		\snt_kit_field( 'hidden', 'source', '', $source ),
		array(
			'submit'        => __( 'Delete', 'signal-and-noise-tools' ),
			'confirm'       => __( 'This redirect will stop working immediately.', 'signal-and-noise-tools' ),
			'confirm_title' => __( 'Delete this redirect?', 'signal-and-noise-tools' ),
			'confirm_label' => __( 'Delete', 'signal-and-noise-tools' ),
			'danger'        => true,
		)
	);
	$edit   = \snt_kit_button( __( 'Edit', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => 'redirects', 'sn_edit' => $source ) ) );
	return array(
		'_key'    => $source,
		'from'    => $source,
		'to'      => (string) ( $r['to'] ?? '' ),
		'type'    => 302 === redirects_code( $r ) ? __( '302 temporary', 'signal-and-noise-tools' ) : __( '301 permanent', 'signal-and-noise-tools' ),
		'added'   => redirects_added( $r, __( 'unknown', 'signal-and-noise-tools' ) ),
		'actions' => array(
			'html' => '<os-cluster gap="8">' . $edit . $delete . '</os-cluster>',
			'text' => '',
		),
	);
}

/**
 * The rules section: reading, finding, the capped ledger. Newest first, so
 * the cap drops the settled tail and the "+N more" line says so.
 *
 * @param array<string,array<string,mixed>> $rules Every rule, newest first.
 * @return string
 */
function redirects_rules_html( array $rules ) {
	$heading = __( 'Redirect rules', 'signal-and-noise-tools' );
	$total   = count( $rules );
	if ( 0 === $total ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( __( 'No redirect rules yet; add the first one below.', 'signal-and-noise-tools' ) ) . '</p>' );
	}
	$rows = array();
	foreach ( array_slice( $rules, 0, SN_REDIRECT_LIST_CAP, true ) as $source => $r ) {
		$rows[] = redirects_rule_row( (string) $source, (array) $r );
	}
	$columns = array(
		array( 'key' => 'from', 'label' => __( 'From', 'signal-and-noise-tools' ) ),
		array( 'key' => 'to', 'label' => __( 'To', 'signal-and-noise-tools' ) ),
		array( 'key' => 'type', 'label' => __( 'Type', 'signal-and-noise-tools' ) ),
		array( 'key' => 'added', 'label' => __( 'Added', 'signal-and-noise-tools' ) ),
		array( 'key' => 'actions', 'label' => __( 'Actions', 'signal-and-noise-tools' ) ),
	);
	/* translators: 1: rules listed, 2: rules in total. */
	$showing = sprintf( __( 'Showing the %1$s most recent of %2$s redirects.', 'signal-and-noise-tools' ), number_format_i18n( SN_REDIRECT_LIST_CAP ), number_format_i18n( $total ) );
	/* translators: %s: rules not listed. */
	$more = __( '+%s older rules are not listed.', 'signal-and-noise-tools' ) . ' ' . str_replace( '%', '%%', $showing );
	return \snt_kit_section(
		$heading,
		redirects_reading_html( $rules )
			. \snt_kit_table( $columns, $rows, array( 'stack_on_phone' => true ) )
			. \snt_kit_more( $total - count( $rows ), $more ),
		__( 'Newest first. Edit opens the rule in one form below the table.', 'signal-and-noise-tools' )
	);
}
