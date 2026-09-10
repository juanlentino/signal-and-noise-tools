<?php
/**
 * S&N Dashboard — Site → Redirects: the pieces the leaf paints.
 *
 * Required by site-redirects.php. Every function is prefixed `redirects_`.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** How many probed paths the fold lists before "…and N more" (the classic's 25). */
const REDIRECTS_PROBE_LIST_MAX = 25;



/**
 * v13.109.7: moved here from site-redirects.php. BOTH leaves read it now —
 * Redirects paints the rules, Broken links paints the 404s — so it belongs in
 * the shared parts file rather than inside one of its two consumers.
 */
/**
 * The leaf's readings, the way the classic renderer reads them: the redirect
 * map newest first; the actionable 404 log split into broken paths (busiest
 * first, each with its slug suggestion) and automated probes (busiest first).
 *
 * @return array{redirects:array,broken:array,probes:array,probe_hits:int}
 */
function redirects_data() {
	$redirects  = \sn_redirects_all();
	$log        = \sn_404_log_actionable();
	$candidates = function_exists( 'sn_404_published_paths' ) ? \sn_404_published_paths() : array();
	$host       = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$part       = function_exists( 'sn_404_log_partition' )
		? \sn_404_log_partition( $log, $candidates, $host )
		: array( 'actionable' => $log, 'probes' => array() );
	$busiest    = static function ( $a, $b ) {
		return (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
	};
	$broken = (array) $part['actionable'];
	$probes = (array) $part['probes'];
	uasort( $broken, $busiest );
	uasort( $probes, $busiest );
	$rows = array();
	foreach ( $broken as $path => $entry ) {
		$rows[ $path ] = array(
			'entry'     => (array) $entry,
			'suggested' => function_exists( 'sn_404_suggest_target' ) ? (string) \sn_404_suggest_target( (string) $path, $candidates ) : '',
		);
	}
	$hits = 0;
	foreach ( $probes as $entry ) {
		$hits += (int) ( $entry['count'] ?? 0 );
	}
	return array(
		'redirects'  => array_reverse( $redirects, true ),
		'broken'     => $rows,
		'probes'     => $probes,
		'probe_hits' => $hits,
	);
}

/**
 * `snt_kit_form()` plus the two confirm attributes the helper does not take —
 * `os-confirm-title` and `os-confirm-label`, both in the framework's trigger
 * vocabulary — which the classic Delete button carries.
 *
 * @param string              $sn_action Handler action.
 * @param string              $inner     Painted fields.
 * @param array<string,mixed> $opts      As snt_kit_form(), plus confirm_title, confirm_label.
 * @return string
 */
function redirects_form( $sn_action, $inner, array $opts = array() ) {
	$html  = \snt_kit_form( $sn_action, $inner, $opts );
	$extra = \snt_kit_attr(
		array(
			'os-confirm-title' => isset( $opts['confirm_title'] ) ? (string) $opts['confirm_title'] : null,
			'os-confirm-label' => isset( $opts['confirm_label'] ) ? (string) $opts['confirm_label'] : null,
		)
	);
	if ( '' === $extra || 0 !== strpos( $html, '<os-form' ) ) {
		return $html; // Nothing to splice in, or snt_kit_form()'s shape changed underneath us — never inject blind.
	}
	return substr_replace( $html, $extra, strlen( '<os-form' ), 0 );
}

/**
 * The 301/302 choice, as the classic select offers it.
 *
 * @return array<string,string>
 */
function redirects_status_options() {
	return array(
		'301' => __( '301. Permanent', 'signal-and-noise-tools' ),
		'302' => __( '302. Temporary', 'signal-and-noise-tools' ),
	);
}

/**
 * One existing redirect: its edit form and its delete form.
 *
 * @param string              $source Source path.
 * @param array<string,mixed> $r      to, status, created_at.
 * @return string
 */
function redirects_row_html( $source, array $r ) {
	$status = ( 302 === (int) ( $r['status'] ?? 301 ) ) ? 302 : 301;
	$fields = \snt_kit_field( 'hidden', 'source', '', $source )
		. \snt_kit_field( 'text', 'target', __( 'Redirects to', 'signal-and-noise-tools' ), (string) ( $r['to'] ?? '' ) )
		. \snt_kit_field( 'select', 'status', __( 'Type', 'signal-and-noise-tools' ), $status, array( 'options' => redirects_status_options() ) );
	$inner  = \snt_kit_form( 'redirect_update', $fields, array( 'submit' => __( 'Save changes', 'signal-and-noise-tools' ) ) )
		. redirects_form(
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
	// One LINE per rule, matching Site -> Broken links exactly. Measured live
	// 2026-09-10 AFTER the 404 log moved out: this leaf was still 9,101px --
	// nine screens, 39 rules at 233px each, an edit form open on every one with
	// 988px of empty space beside a 760px field. The cap did not help because 39
	// is under it; the FOLD is what fixes an open form per row.
	//
	// The heading carries the whole rule so the list is scannable without opening
	// anything: source, arrow, target. The hint carries the parts you compare
	// between rules rather than read -- the status code and when it was added.
	$target = (string) ( $r['to'] ?? '' );
	$hint   = sprintf(
		/* translators: 1: HTTP status (301/302), 2: the date the redirect was created (Y-m-d). */
		__( '%1$d · added %2$s', 'signal-and-noise-tools' ),
		$status,
		wp_date( 'Y-m-d', (int) ( $r['created_at'] ?? 0 ) )
	);
	return \snt_kit_tag(
		'os-disclosure',
		array( 'heading' => $source . ' → ' . $target, 'hint' => $hint ),
		$inner
	);
}

/**
 * The add form.
 *
 * @return string
 */
function redirects_add_html() {
	$fields = \snt_kit_field( 'text', 'source', __( 'From (path on this site)', 'signal-and-noise-tools' ), '', array( 'placeholder' => '/old-page', 'hint' => __( 'The path to match, e.g. /old-page. Trailing slash and query string are ignored.', 'signal-and-noise-tools' ) ) )
		. \snt_kit_field( 'text', 'target', __( 'To (path or full URL)', 'signal-and-noise-tools' ), '', array( 'placeholder' => '/new-page  or  https://example.com/page' ) )
		. \snt_kit_field( 'select', 'status', __( 'Type', 'signal-and-noise-tools' ), 301, array( 'options' => redirects_status_options() ) );
	return \snt_kit_section(
		__( 'Add a redirect', 'signal-and-noise-tools' ),
		\snt_kit_form( 'redirect_add', $fields, array( 'submit' => __( 'Add redirect', 'signal-and-noise-tools' ) ) )
	);
}

/**
 * The broken-links status: clean, or N broken paths.
 *
 * @param int $total Broken paths.
 * @return string
 */
function redirects_status_html( $total ) {
	if ( 0 === (int) $total ) {
		return \snt_kit_notice(
			'ok',
			'<b>' . \snt_kit_esc( __( 'No broken links', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_badge( 'ok', __( 'Clean', 'signal-and-noise-tools' ) )
			. '<br>' . \snt_kit_esc( __( 'Paths that resemble a published URL, or that something on this site links to, appear here. Automated probes are counted separately.', 'signal-and-noise-tools' ) )
		);
	}
	/* translators: %d: broken paths in the 404 log. */
	$title = sprintf( _n( '%d broken path', '%d broken paths', (int) $total, 'signal-and-noise-tools' ), (int) $total );
	return \snt_kit_notice(
		'warn',
		'<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_badge( 'warn', __( 'Attention', 'signal-and-noise-tools' ) )
		. '<br>' . \snt_kit_esc( __( 'Add a target below to redirect it: doing so also clears it from this list.', 'signal-and-noise-tools' ) )
	);
}

/**
 * The probe bucket: one line, one number, one fold with the paths and one
 * bulk dismiss — weather, not a task, so no attention tone.
 *
 * @param array<string,array<string,mixed>> $probes Busiest first.
 * @param int                               $hits   Their hit total.
 * @return string
 */
function redirects_probes_html( array $probes, $hits ) {
	$n     = count( $probes );
	$items = '';
	foreach ( array_slice( $probes, 0, REDIRECTS_PROBE_LIST_MAX, true ) as $path => $entry ) {
		$items .= '<li>' . \snt_kit_code( (string) $path, false ) . ' <span class="snt-hint">' . \snt_kit_esc( (int) ( $entry['count'] ?? 0 ) ) . '×</span></li>';
	}
	if ( $n > REDIRECTS_PROBE_LIST_MAX ) {
		/* translators: %d: how many further probed paths are not listed. */
		$items .= '<li class="snt-hint">' . \snt_kit_esc( sprintf( __( '…and %d more', 'signal-and-noise-tools' ), $n - REDIRECTS_PROBE_LIST_MAX ) ) . '</li>';
	}
	/* translators: %d: automated probes in the 404 log. */
	$title = sprintf( _n( '%d automated probe', '%d automated probes', $n, 'signal-and-noise-tools' ), $n );
	/* translators: %d: hits those probes made. */
	$body  = sprintf( _n( '%d hit on paths that match nothing published here and that nothing here links to: scanner traffic, not broken links. No action needed.', '%d hits on paths that match nothing published here and that nothing here links to: scanner traffic, not broken links. No action needed.', (int) $hits, 'signal-and-noise-tools' ), (int) $hits );
	$fold  = \snt_kit_tag(
		'os-disclosure',
		array( 'heading' => __( 'Show the probed paths', 'signal-and-noise-tools' ) ),
		'<ul class="snt-plain">' . $items . '</ul>'
		. \snt_kit_action_button(
			__( 'Dismiss all probes', 'signal-and-noise-tools' ),
			'redirect_404_clear_probes',
			array(
				'confirm'       => __( 'Dismiss every automated probe from the log? Genuinely broken paths are kept.', 'signal-and-noise-tools' ),
				'confirm_label' => __( 'Dismiss probes', 'signal-and-noise-tools' ),
			)
		)
	);
	return \snt_kit_notice( 'neutral', '<b>' . \snt_kit_esc( $title ) . '</b><br>' . \snt_kit_esc( $body ) ) . $fold;
}

/**
 * One broken path: its hits, its create form (prefilled with the slug
 * suggestion) and its dismiss form.
 *
 * @param string              $path      The 404'd path.
 * @param array<string,mixed> $e         count, last_seen, referer.
 * @param string              $suggested Suggested target ('' when none).
 * @return string
 */
function redirects_404_row_html( $path, array $e, $suggested ) {
	$count = (int) ( $e['count'] ?? 0 );
	/* translators: %d: hits on this path. */
	$meta = sprintf( _n( '%d hit', '%d hits', $count, 'signal-and-noise-tools' ), $count )
		/* translators: %s: the date of the latest hit (Y-m-d). */
		. ' · ' . sprintf( __( 'last %s', 'signal-and-noise-tools' ), wp_date( 'Y-m-d', (int) ( $e['last_seen'] ?? 0 ) ) );
	$meta = \snt_kit_esc( $meta );
	if ( ! empty( $e['referer'] ) ) {
		$meta .= \snt_kit_esc( ' · ' . __( 'from', 'signal-and-noise-tools' ) . ' ' ) . \snt_kit_code( (string) wp_parse_url( (string) $e['referer'], PHP_URL_HOST ), false );
	}
	$opts = array( 'placeholder' => '/new-page  or  https://…' );
	if ( '' !== $suggested ) {
		$opts['hint'] = __( 'Suggested from your published slugs (closest match) — review before creating.', 'signal-and-noise-tools' );
	}
	$create  = \snt_kit_form(
		'redirect_add',
		\snt_kit_field( 'hidden', 'source', '', $path ) . \snt_kit_field( 'text', 'target', __( 'Redirect to', 'signal-and-noise-tools' ), $suggested, $opts ),
		array( 'submit' => __( 'Create redirect', 'signal-and-noise-tools' ) )
	);
	$dismiss = \snt_kit_form( 'redirect_404_delete', \snt_kit_field( 'hidden', 'source', '', $path ), array( 'submit' => __( 'Dismiss', 'signal-and-noise-tools' ) ) );

	// One LINE per path, not one section. A section per path is the right shape
	// for one broken link and the wrong shape for twenty: measured live
	// 2026-09-10, 20 paths x ~450px painted a 9,101px leaf -- nine screens of
	// scrolling to reach a Clear button. The form is what costs the height, and
	// you only ever fill in one at a time, so it folds away until it is wanted.
	// Same primitive and same reasoning as audit_log_logins_html().
	//
	// The hint is a plain-text attribute, so the referer chip stays in the body
	// where it can carry markup; the scannable half (hits, last seen) is what
	// goes in the fold's own line.
	$fold_hint = sprintf(
		/* translators: 1: hit count, 2: date of the latest hit (Y-m-d). */
		__( '%1$s · last %2$s', 'signal-and-noise-tools' ),
		sprintf( _n( '%d hit', '%d hits', $count, 'signal-and-noise-tools' ), $count ),
		wp_date( 'Y-m-d', (int) ( $e['last_seen'] ?? 0 ) )
	);
	return \snt_kit_tag(
		'os-disclosure',
		array( 'heading' => (string) $path, 'hint' => $fold_hint ),
		'<p class="snt-hint">' . $meta . '</p>' . $create . $dismiss
	);
}
