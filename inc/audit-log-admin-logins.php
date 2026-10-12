<?php
/**
 * Signal & Noise Tools: the Audit log's recent successful logins.
 *
 * Split out of inc/audit-log-admin.php (2026-10-11, no folds): the log used
 * to sit in a closed `<details>` whose summary carried the count. It is open
 * now, led by one finding sentence built from the rows. The sentence is
 * shared with the S&N Home leaf (apps/sn-dashboard/parts/leaves/
 * security-audit-log-logins.php), so the two surfaces cannot word it apart.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The finding over the logins log: how many logins, by how many distinct
 * users, and the newest one. Rows carry a timestamp and a username only (no
 * IP is stored), so the sentence names nothing else.
 *
 * @param array<int,array<string,mixed>> $sorted Every login in the window, newest first.
 * @return string '' when there are no logins.
 */
function snt_audit_log_logins_finding( array $sorted ) {
	if ( array() === $sorted ) {
		return '';
	}
	$total  = count( $sorted );
	$users  = count( array_unique( array_map( 'strval', array_column( $sorted, 'user' ) ) ) );
	$newest = (array) reset( $sorted );
	return sprintf(
		/* translators: 1: logins in the window, 2: "N users", 3: newest login time, 4: newest login's username. */
		_n( '%1$s successful login in the window, by %2$s; the newest was %3$s, by %4$s.', '%1$s successful logins in the window, by %2$s; the newest was %3$s, by %4$s.', $total, 'signal-and-noise-tools' ),
		number_format_i18n( $total ),
		/* translators: %s: distinct usernames among the logins. */
		sprintf( _n( '%s user', '%s users', $users, 'signal-and-noise-tools' ), number_format_i18n( $users ) ),
		(string) ( $newest['formatted'] ?? '' ),
		(string) ( $newest['user'] ?? '' )
	);
}

/**
 * Render the recent successful logins table: the finding, the newest
 * SN_AUDIT_LOGIN_DISPLAY_MAX rows, the "+N more" line, the store cap.
 *
 * AL1: SN_AUDIT_LOGIN_SUCCESS_CAP bounds what is kept (oldest dropped); the
 * display cap here trims the OLD end only, and the store's own ceiling is
 * still named underneath so a tighter presentation cap cannot imply the site
 * retains less than it does.
 *
 * @param array<int,array<string,mixed>> $logins snt_audit_get_login_successes_impl() result.
 * @return void
 */
function snt_audit_log_render_logins_table( $logins ) {
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">Recent successful logins (last 30 days)</h2>';
	if ( empty( $logins ) ) {
		echo '<p class="sn-prose">No successful logins recorded in this window.</p>';
		echo '</div>';  // .sn-fieldset
		return;
	}
	$total = count( $logins );

	// Newest-first on a COPY. The producer already sorts this way, but the
	// correctness of a cap must not depend on a distant function continuing to:
	// slicing an unsorted list would silently drop the most recent login, which
	// is the one a security readout exists to show.
	$sorted = $logins;
	usort(
		$sorted,
		static function ( $a, $b ) {
			return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 );
		}
	);

	$visible = array_slice( $sorted, 0, SN_AUDIT_LOGIN_DISPLAY_MAX );
	$hidden  = $total - count( $visible );

	echo '<p class="sn-prose">' . esc_html( snt_audit_log_logins_finding( $sorted ) ) . '</p>';
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat sn-audit-logins">';
	echo '<thead><tr><th scope="col">Timestamp</th><th scope="col">User</th></tr></thead>';
	echo '<tbody>';
	foreach ( $visible as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( $row['formatted'] ) . '</code></td>';
		echo '<td>' . esc_html( $row['user'] ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
	echo '</div>';   // .snt-scroll-table

	if ( $hidden > 0 ) {
		echo '<p class="sn-prose">' . esc_html(
			sprintf(
				/* translators: %s: number of logins not shown. */
				__( '+%s more logins; the list is capped, not complete. Newest first, so the tail is the oldest end.', 'signal-and-noise-tools' ),
				number_format_i18n( $hidden )
			)
		) . '</p>';
	}

	// The STORE's ceiling, named beside the display cap. Guarded rather than
	// assumed: this constant belongs to inc/audit-log.php, and a surface must
	// not invent a retention number if that module ever fails to load. Silence
	// is the honest degradation; a hardcoded fallback would be a claim.
	if ( defined( 'SN_AUDIT_LOGIN_SUCCESS_CAP' ) ) {
		echo '<p class="sn-prose">' . esc_html(
			sprintf(
				/* translators: %s: the storage cap on retained login rows. */
				__( 'The store keeps at most %s successful logins, dropping the oldest first, so a long-enough gap is absence of a record rather than absence of a login.', 'signal-and-noise-tools' ),
				number_format_i18n( SN_AUDIT_LOGIN_SUCCESS_CAP )
			)
		) . '</p>';
	}

	echo '</div>';   // .sn-fieldset
}
