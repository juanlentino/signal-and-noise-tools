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
 * LAYOUT (#1055). The leaf is registered `wide`, so it lays itself out on a bare
 * .sn-section: a glance hero with one card per tier (the ladder's order, every
 * tier printed), then one wide fieldset holding the sentence, the inbox, a
 * folded legend and the claims table. The first cut wrapped all of it in
 * .sn-card — the 260px stat card — so a 1,400px window showed a strip down
 * the left edge with a table overflowing its own border.
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
 * Human wording for a GMT timestamp: "3 hours ago". NULL renders as "never"
 * and never as a date, because a column's whole purpose is to keep the two
 * apart.
 *
 * @param string|null $gmt
 * @return string
 */
function sn_cit_ago_label( $gmt ) {
	if ( null === $gmt || '' === (string) $gmt ) {
		return __( 'never', 'signal-and-noise-tools' );
	}
	return sprintf(
		/* translators: %s human time difference */
		__( '%s ago', 'signal-and-noise-tools' ),
		human_time_diff( strtotime( $gmt . ' UTC' ), time() )
	);
}

/**
 * The last-check column's wording. Kept as its own name because the column is
 * the one place "never" is load-bearing; it reads through sn_cit_ago_label().
 *
 * @param string|null $gmt
 * @return string
 */
function sn_cit_last_checked_label( $gmt ) {
	return sn_cit_ago_label( $gmt );
}

/**
 * A tier's pill tone: the kit's .sn-pill modifiers, from an allowlist so the
 * class fragment can never be anything but one of these four.
 *
 * verified and unattributed both stand on evidence — the link is there — so
 * both read calm; asserted is the tier whose evidence has GONE, and that is
 * the one worth a warning tone; unverified is missing evidence, muted.
 *
 * @param string $tier One of SN_CIT_TIERS.
 * @return string '' | 'ok' | 'warn' | 'muted'
 */
function sn_cit_tier_pill_kind( $tier ) {
	switch ( $tier ) {
		case 'verified':
			return 'ok';
		case 'asserted':
			return 'warn';
		case 'unverified':
			return 'muted';
		default:
			return '';
	}
}

/**
 * A tier in five words, for the glance card under its number. The full
 * sentence (sn_cit_tier_sentence) stays in the legend and on the pill.
 *
 * @param string $tier One of SN_CIT_TIERS.
 * @return string
 */
function sn_cit_tier_gloss( $tier ) {
	switch ( $tier ) {
		case 'verified':
			return __( 'Link present, publisher named. Public.', 'signal-and-noise-tools' );
		case 'unattributed':
			return __( 'Link present, no identity found. Public.', 'signal-and-noise-tools' );
		case 'asserted':
			return __( 'Link gone. Kept here, shown to nobody.', 'signal-and-noise-tools' );
		case 'unverified':
			return __( 'Not checked yet, or unreachable.', 'signal-and-noise-tools' );
		default:
			return '';
	}
}

/**
 * The glance hero: one card per tier, the ladder's order, every tier printed.
 *
 * @param array<string,int> $counts
 * @return array<int,array<string,mixed>> Cards for sn_admin_glance_grid().
 */
function sn_cit_glance_cards( $counts ) {
	$cards = array();
	$never = (int) ( $counts['never_checked'] ?? 0 );
	foreach ( SN_CIT_TIERS as $tier ) {
		$n    = (int) ( $counts[ $tier ] ?? 0 );
		$card = array(
			'label'     => $tier,
			'value'     => (string) $n,
			'meta_html' => '<span class="description">' . esc_html( sn_cit_tier_gloss( $tier ) ) . '</span>',
		);
		// A pill only where there is something to act on: a claim whose
		// evidence has gone, or rows the checker has not reached yet.
		if ( 'asserted' === $tier && $n > 0 ) {
			$card['pill'] = array( 'kind' => 'warn', 'text' => __( 'evidence gone', 'signal-and-noise-tools' ) );
		} elseif ( 'unverified' === $tier && $never > 0 ) {
			$card['pill'] = array(
				'kind' => 'warn',
				'text' => sprintf(
					/* translators: %d rows never checked */
					_n( '%d never checked', '%d never checked', $never, 'signal-and-noise-tools' ),
					$never
				),
			);
		}
		$cards[] = $card;
	}
	return $cards;
}

/**
 * One claims row.
 *
 * @param object $r A row of the citations table.
 * @return void
 */
function sn_cit_render_row( $r ) {
	$tier = in_array( (string) $r->tier, SN_CIT_TIERS, true ) ? (string) $r->tier : 'unverified';
	$kind = sn_cit_tier_pill_kind( $tier );
	$host = (string) wp_parse_url( (string) $r->source_url, PHP_URL_HOST );
	$path = (string) wp_parse_url( (string) $r->target_url, PHP_URL_PATH );
	$name = '' !== (string) $r->source_title ? (string) $r->source_title : ( '' !== $host ? $host : (string) $r->source_url );

	// The cited page by its title when the row knows the post; the path is
	// always printed, so a renamed Note still reads as the URL that was cited.
	$cited = '';
	if ( (int) $r->target_post_id > 0 && function_exists( 'get_the_title' ) ) {
		$cited = (string) get_the_title( (int) $r->target_post_id );
	}

	echo '<tr>';
	echo '<td><span class="sn-pill' . ( '' !== $kind ? ' sn-pill--' . esc_attr( $kind ) : '' ) . '" title="' . esc_attr( sn_cit_tier_sentence( $tier ) ) . '">' . esc_html( $tier ) . '</span></td>';
	echo '<td><a href="' . esc_url( $r->source_url ) . '" rel="noopener nofollow ugc">' . esc_html( $name ) . '</a>';
	if ( '' !== $host && $name !== $host ) {
		echo '<br><span class="description">' . esc_html( $host ) . '</span>';
	}
	echo '</td>';
	echo '<td><a href="' . esc_url( $r->target_url ) . '">' . esc_html( '' !== $cited ? $cited : $path ) . '</a>';
	if ( '' !== $cited ) {
		echo '<br><span class="description">' . esc_html( $path ) . '</span>';
	}
	echo '</td>';
	echo '<td>' . esc_html( sn_cit_ago_label( $r->first_seen_gmt ) ) . '</td>';
	echo '<td>' . esc_html( sn_cit_last_checked_label( $r->last_checked_gmt ) ) . '</td>';
	// 0 means no response was received at all — distinct from a 200 or a 404.
	echo '<td>' . ( (int) $r->last_status ? esc_html( (string) (int) $r->last_status ) : '—' ) . '</td>';
	echo '</tr>';
}

/** Render the Integrity → Citations leaf. */
function sn_admin_render_citations_section() {
	global $wpdb;
	$counts = sn_cit_counts();
	$table  = sn_cit_table();
	$rows   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY first_seen_gmt DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

	// The hero: the four tiers at a glance, every one printed.
	echo '<section aria-label="' . esc_attr__( 'Citations at a glance', 'signal-and-noise-tools' ) . '">';
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		sn_admin_glance_grid( sn_cit_glance_cards( $counts ) );
	}
	echo '</section>';

	echo '<div class="sn-fieldset sn-fieldset--wide">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Citations', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro"><strong>' . esc_html( sn_cit_summary_sentence( $counts ) ) . '</strong></p>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'A webmention is a claim that someone cited you. Each claim is re-fetched and sorted by what can be checked: verified and unattributed citations appear on the site; a claim whose link has gone, or that could not be reached, is kept here and shown to nobody else.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'Inbox:', 'signal-and-noise-tools' ) . ' <code>' . esc_html( sn_cit_endpoint_url() ) . '</code></p>';

	echo '<details><summary>' . esc_html__( 'What the four tiers mean', 'signal-and-noise-tools' ) . '</summary><ul>';
	foreach ( SN_CIT_TIERS as $tier ) {
		echo '<li><strong>' . esc_html( $tier ) . '</strong> — ' . esc_html( sn_cit_tier_sentence( $tier ) ) . '</li>';
	}
	echo '</ul></details>';

	if ( empty( $rows ) ) {
		echo '<p class="sn-fieldset-intro">' . esc_html__( 'Nothing to list yet.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}

	echo '<div class="snt-scroll-table"><table class="widefat striped"><thead><tr>'
		. '<th scope="col">' . esc_html__( 'Tier', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Source', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Cites', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'First seen', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Last checked', 'signal-and-noise-tools' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'HTTP', 'signal-and-noise-tools' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		sn_cit_render_row( $r );
	}
	echo '</tbody></table></div>';
	if ( 100 === count( $rows ) ) {
		echo '<p class="description">' . esc_html__( 'The newest 100 claims are listed.', 'signal-and-noise-tools' ) . '</p>';
	}
	echo '</div>';
}
