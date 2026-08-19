<?php
/**
 * Signal & Noise — the verified citation graph: the admin leaf.
 *
 * The readout is deliberately THREE-WAY, not a fraction. "4 citations" would
 * hide that one of them is a claim whose evidence has gone and two have never
 * been checked at all — the same collapse the Health tally made before v11.13.0.
 * Every declared tier is printed even at zero, because a tier missing from a
 * readout is indistinguishable from a tier nobody measured.
 *
 * @package SignalNoiseTools
 * @since 11.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The headline sentence. Names every bucket, so what it prints always accounts
 * for the whole table.
 *
 * @param array<string,int> $counts
 * @return string
 */
function sn_cit_summary_sentence( $counts ) {
	$total = 0;
	foreach ( SN_CIT_TIERS as $t ) {
		$total += (int) ( $counts[ $t ] ?? 0 );
	}
	if ( 0 === $total ) {
		return __( 'No citations recorded. Nobody has sent one — this is a measured zero, not an unread inbox.', 'signal-and-noise-tools' );
	}
	$parts = array();
	foreach ( SN_CIT_TIERS as $t ) {
		$parts[] = sprintf( '%d %s', (int) ( $counts[ $t ] ?? 0 ), $t );
	}
	$line = sprintf(
		/* translators: %1$d total claims, %2$s per-tier breakdown */
		__( '%1$d claims: %2$s.', 'signal-and-noise-tools' ),
		$total,
		implode( ' · ', $parts )
	);
	$never = (int) ( $counts['never_checked'] ?? 0 );
	if ( $never > 0 ) {
		$line .= ' ' . sprintf(
			/* translators: %d rows never checked */
			_n( '%d has never been checked.', '%d have never been checked.', $never, 'signal-and-noise-tools' ),
			$never
		);
	}
	return $line;
}

/**
 * Human wording for a last-check time. NULL is rendered as "never" and never as
 * a date, because the column's whole purpose is to keep the two apart.
 *
 * @param string|null $gmt
 * @return string
 */
function sn_cit_last_checked_label( $gmt ) {
	if ( null === $gmt || '' === (string) $gmt ) {
		return __( 'never', 'signal-and-noise-tools' );
	}
	return sprintf(
		/* translators: %s human time difference */
		__( '%s ago', 'signal-and-noise-tools' ),
		human_time_diff( strtotime( $gmt . ' UTC' ), time() )
	);
}

/** Render the Integrity → Citations leaf. */
function sn_admin_render_citations_section() {
	global $wpdb;
	$counts = sn_cit_counts();
	$table  = sn_cit_table();
	$rows   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY first_seen_gmt DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

	echo '<div class="sn-card">';
	echo '<h2>' . esc_html__( 'Citations', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'A webmention is an unverified claim that someone cited you. Each claim is re-fetched and sorted by what can actually be checked. Only verified and unattributed citations appear publicly; a claim whose link has gone is kept here and shown to nobody else.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p><strong>' . esc_html( sn_cit_summary_sentence( $counts ) ) . '</strong></p>';

	echo '<p class="description">' . esc_html__( 'Inbox:', 'signal-and-noise-tools' ) . ' <code>' . esc_html( sn_cit_endpoint_url() ) . '</code></p>';

	echo '<ul class="sn-cit-legend">';
	foreach ( SN_CIT_TIERS as $tier ) {
		echo '<li><strong>' . esc_html( $tier ) . '</strong> — ' . esc_html( sn_cit_tier_sentence( $tier ) ) . '</li>';
	}
	echo '</ul>';

	if ( empty( $rows ) ) {
		echo '<p>' . esc_html__( 'Nothing to list yet.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>'
		. '<th>' . esc_html__( 'Tier', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Source', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Cites', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'Last checked', 'signal-and-noise-tools' ) . '</th>'
		. '<th>' . esc_html__( 'HTTP', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$title = '' !== (string) $r->source_title ? $r->source_title : $r->source_url;
		echo '<tr class="sn-cit-row--' . esc_attr( $r->tier ) . '">';
		echo '<td>' . esc_html( $r->tier ) . '</td>';
		echo '<td><a href="' . esc_url( $r->source_url ) . '" rel="noopener nofollow ugc">' . esc_html( $title ) . '</a></td>';
		echo '<td><a href="' . esc_url( $r->target_url ) . '">' . esc_html( wp_parse_url( $r->target_url, PHP_URL_PATH ) ) . '</a></td>';
		echo '<td>' . esc_html( sn_cit_last_checked_label( $r->last_checked_gmt ) ) . '</td>';
		// 0 means no response was received at all — distinct from a 200 or a 404.
		echo '<td>' . ( (int) $r->last_status ? esc_html( (string) (int) $r->last_status ) : '—' ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}
