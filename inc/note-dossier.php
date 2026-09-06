<?php
/**
 * Signal & Noise Tools — the note dossier: the vocabulary four builders share.
 *
 * A dossier is everything the estate knows about ONE note, as a list of
 * BLOCKS the Signal & Noise app paints beside its list. Each block names its
 * `group` (trust | numbers | state | editorial), its `kind`, and, when it was
 * fetched from somewhere, its `source`; a stats tile names its own `window`,
 * so three sources with three windows never read as one number. A block may
 * carry a `door`: a
 * label and a URL into the app that owns the view (S&N Dashboard, S&N
 * Analytics); this app shows a glance and a door, not a second home.
 *
 * Kinds (the client paints exactly these):
 *   stats  { heading, tiles: [ { label, value, window, note? } ] }
 *   status { heading, tone, text, meta? }        tone ∈ success|warning|danger|info|neutral
 *   text   { heading, text }
 *   table  { heading, columns: [ { key, label } ], rows: [ { key: string | { text, tone } | { code, title } } ] }
 *
 * Builders are pure functions of a post id (and a window); each guards every
 * plugin reader with function_exists so its standalone test runs alone. The
 * composer runs each builder in its own try: a source that fails becomes a
 * warning block naming the source, and the other three still paint.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The windows the numbers block answers; anything else is the default. */
const SN_NOTE_DOSSIER_WINDOWS = array( 7, 30, 90 );
const SN_NOTE_DOSSIER_DEFAULT_DAYS = 30;

/**
 * @param mixed $raw The requested window (an int, or the string a GET delivers).
 * @return int One of SN_NOTE_DOSSIER_WINDOWS.
 */
function sn_note_dossier_days( $raw ) {
	$d = (int) $raw;
	return in_array( $d, SN_NOTE_DOSSIER_WINDOWS, true ) ? $d : SN_NOTE_DOSSIER_DEFAULT_DAYS;
}

/** The post types a dossier can be built for: the provenance subject types. */
const SN_NOTE_DOSSIER_POST_TYPES = array( 'post', 'page' );

/**
 * The dossier's subject, or null. A `post` or a `page` -- the two post types
 * that can hold a provenance subject (inc/provenance-core.php). Every builder
 * resolves the post through here, so a missing or foreign id is one answer
 * everywhere, and widening this one gate widened all four builders.
 *
 * A page is admitted whether or not it opted into signing: the trust builder
 * already resolves the subject kind itself and says "not a provenance
 * subject" for an unsigned one, which is a truer answer than no dossier at
 * all. The gate here is about the SHAPE of the subject, not its signature.
 *
 * @param int $post_id
 * @return WP_Post|null
 */
function sn_note_dossier_post( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post instanceof WP_Post || ! in_array( (string) $post->post_type, SN_NOTE_DOSSIER_POST_TYPES, true ) ) {
		return null;
	}
	return $post;
}

/**
 * Whether a reader can reach the note: published and not password-protected.
 * Numbers and operating state exist only for such a note.
 *
 * @param WP_Post|null $post
 * @return bool
 */
function sn_note_dossier_is_public( $post ) {
	return $post instanceof WP_Post && 'publish' === $post->post_status && '' === (string) $post->post_password;
}

/** The kit's badge tones, the only vocabulary the client paints. */
const SN_NOTE_DOSSIER_TONES = array( 'success', 'warning', 'danger', 'info', 'neutral' );

/**
 * The admin kit's pill kinds ('' | ok | warn | err | muted, e.g. from
 * sn_cit_tier_pill_kind()) onto the OpenStation badge tones.
 *
 * @param string $kind
 * @return string
 */
function sn_note_dossier_tone( $kind ) {
	switch ( (string) $kind ) {
		case 'ok':
			return 'success';
		case 'warn':
			return 'warning';
		case 'err':
			return 'danger';
		case 'muted':
			return 'neutral';
		case '':
			return 'info';
		default:
			return 'neutral';
	}
}

/**
 * A door: the label and URL of the view that owns a fact.
 *
 * @param string $label
 * @param string $url
 * @return array{label:string,url:string}
 */
function sn_note_dossier_door( $label, $url ) {
	return array( 'label' => (string) $label, 'url' => (string) $url );
}

/**
 * @param string     $group   trust | numbers | state | editorial
 * @param string     $heading
 * @param array[]    $tiles   Each { label, value, window, note? }.
 * @param string     $source  Where the numbers came from.
 * @param array|null $door
 * @return array<string,mixed>
 */
function sn_note_dossier_stats( $group, $heading, array $tiles, $source, $door = null ) {
	$block = array(
		'group'   => (string) $group,
		'kind'    => 'stats',
		'heading' => (string) $heading,
		'tiles'   => array_values( $tiles ),
		'source'  => (string) $source,
	);
	if ( is_array( $door ) ) {
		$block['door'] = $door;
	}
	return $block;
}

/**
 * @param string     $group
 * @param string     $heading
 * @param string     $tone    A kit tone; anything else reads neutral.
 * @param string     $text    The sentence on the badge.
 * @param string     $meta    A second line, optional.
 * @param string     $source  Optional.
 * @param array|null $door    Optional.
 * @return array<string,mixed>
 */
function sn_note_dossier_status( $group, $heading, $tone, $text, $meta = '', $source = '', $door = null ) {
	$block = array(
		'group'   => (string) $group,
		'kind'    => 'status',
		'heading' => (string) $heading,
		'tone'    => in_array( (string) $tone, SN_NOTE_DOSSIER_TONES, true ) ? (string) $tone : 'neutral',
		'text'    => (string) $text,
	);
	if ( '' !== (string) $meta ) {
		$block['meta'] = (string) $meta;
	}
	if ( '' !== (string) $source ) {
		$block['source'] = (string) $source;
	}
	if ( is_array( $door ) ) {
		$block['door'] = $door;
	}
	return $block;
}

/**
 * @param string $group
 * @param string $heading
 * @param string $text
 * @param string $source
 * @return array<string,mixed>
 */
function sn_note_dossier_text( $group, $heading, $text, $source = '' ) {
	$block = array( 'group' => (string) $group, 'kind' => 'text', 'heading' => (string) $heading, 'text' => (string) $text );
	if ( '' !== (string) $source ) {
		$block['source'] = (string) $source;
	}
	return $block;
}

/**
 * @param string     $group
 * @param string     $heading
 * @param array[]    $columns Each { key, label }.
 * @param array[]    $rows    Keyed by column key; a cell is a string, { text, tone } or { code, title }.
 * @param string     $source
 * @param array|null $door
 * @return array<string,mixed>
 */
function sn_note_dossier_table( $group, $heading, array $columns, array $rows, $source = '', $door = null ) {
	$block = array( 'group' => (string) $group, 'kind' => 'table', 'heading' => (string) $heading, 'columns' => array_values( $columns ), 'rows' => array_values( $rows ) );
	if ( '' !== (string) $source ) {
		$block['source'] = (string) $source;
	}
	if ( is_array( $door ) ) {
		$block['door'] = $door;
	}
	return $block;
}

/**
 * The block a source that could not be read becomes: a warning that names
 * the source, so the reader knows which door to try, and never a zero.
 *
 * @param string $group
 * @param string $heading
 * @param string $source_name E.g. 'the analytics table'.
 * @return array<string,mixed>
 */
function sn_note_dossier_unreadable( $group, $heading, $source_name ) {
	return sn_note_dossier_status(
		$group,
		$heading,
		'warning',
		__( 'This source could not be read.', 'signal-and-noise-tools' ),
		sprintf(
			/* translators: %s: the source that failed. */
			__( 'A gap in evidence, not a verdict: %s did not answer.', 'signal-and-noise-tools' ),
			(string) $source_name
		)
	);
}

/**
 * "2 hours ago" for a unix time; '' for no time.
 *
 * @param int $ts
 * @return string
 */
function sn_note_dossier_ago( $ts ) {
	$ts = (int) $ts;
	if ( $ts <= 0 ) {
		return '';
	}
	return sprintf(
		/* translators: %s: human time difference. */
		__( '%s ago', 'signal-and-noise-tools' ),
		human_time_diff( $ts, time() )
	);
}

/**
 * The whole dossier for one note: the four builders in the owner's order,
 * each in its own try. Null when the id is not a note.
 *
 * @param int           $post_id
 * @param int           $days    A window from SN_NOTE_DOSSIER_WINDOWS.
 * @param callable|null $fetcher HTTP seam for the trust builder (tests).
 * @return array<string,mixed>|null { ok, post_id, days, is_public, blocks, fetched_at }
 */
function sn_note_dossier_compose( $post_id, $days, $fetcher = null ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return null;
	}
	$days   = sn_note_dossier_days( $days );
	$blocks = array();
	$runs   = array(
		'trust'     => array( 'sn_note_dossier_trust', array( $post->ID, $fetcher ), __( 'Trust', 'signal-and-noise-tools' ), __( 'the ledger or the citation graph', 'signal-and-noise-tools' ) ),
		'numbers'   => array( 'sn_note_dossier_numbers', array( $post->ID, $days ), __( 'Numbers', 'signal-and-noise-tools' ), __( 'the analytics table, the Search Console sync or the snapshot', 'signal-and-noise-tools' ) ),
		'state'     => array( 'sn_note_dossier_state', array( $post->ID ), __( 'Operating state', 'signal-and-noise-tools' ), __( 'the probe log, the coverage map or the schedule', 'signal-and-noise-tools' ) ),
		'editorial' => array( 'sn_note_dossier_editorial', array( $post->ID ), __( 'Editorial', 'signal-and-noise-tools' ), __( 'the post itself', 'signal-and-noise-tools' ) ),
	);
	foreach ( $runs as $group => $run ) {
		list( $fn, $args, $heading, $source_name ) = $run;
		if ( ! function_exists( $fn ) ) {
			continue;
		}
		try {
			$out = call_user_func_array( $fn, $args );
			foreach ( (array) $out as $block ) {
				if ( is_array( $block ) && isset( $block['kind'] ) ) {
					$blocks[] = $block;
				}
			}
		} catch ( \Throwable $e ) {
			$blocks[] = sn_note_dossier_unreadable( $group, $heading, $source_name . ' (' . $group . ')' );
		}
	}
	return array(
		'ok'         => true,
		'post_id'    => (int) $post->ID,
		'days'       => $days,
		'is_public'  => sn_note_dossier_is_public( $post ),
		'blocks'     => $blocks,
		'fetched_at' => time(),
	);
}
