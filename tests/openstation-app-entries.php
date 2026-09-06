<?php
/**
 * Standalone test: the Signal & Noise app's two ENTRY sections -- Citations
 * and Scheduled fragments (#1068, phase three) -- and the two store/engine
 * reads they were given.
 *
 * The stores are REAL here. `inc/citations-store.php` and
 * `inc/schedule-engine.php` are required and driven against an in-memory
 * `$wpdb` that parses the SQL it is handed: the LIMIT is sliced, the ORDER BY
 * column and direction are honoured, and a COUNT's WHERE value is matched
 * against the fixture rows. A wrong ORDER BY column, a wrong bound or a wrong
 * WHERE therefore makes an assertion FAIL rather than passing on a canned
 * return. The label helpers (`sn_cit_tier_pill_kind`, `sn_cit_tier_gloss`,
 * `sn_cit_ago_label`, `sn_note_dossier_tone`) are real too, so the tone bridge
 * is measured end to end and not against a stub of itself.
 *
 * WordPress is stubbed flat. The two sections register through the same
 * `snt_os_app_sections` filter everything else uses, so the descriptors are
 * read back through `snt_os_app_sections()` -- the registry's own gate --
 * rather than out of the array literal.
 * Run: php tests/openstation-app-entries.php
 *
 * @since 13.102.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// ── WordPress, flat ──────────────────────────────────────────────────
$GLOBALS['__filters'] = array();

/**
 * @param string   $hook  Hook name.
 * @param callable $cb    Callback.
 * @param int      $prio  Priority.
 * @param int      $args  Accepted args.
 * @return bool
 */
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $hook ][] = $cb;
	return true;
}

/**
 * @param string $hook  Hook name.
 * @param mixed  $value Filtered value.
 * @param mixed  ...$rest Extra args.
 * @return mixed
 */
function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) {
		$value = call_user_func( $cb, $value, ...$rest );
	}
	return $value;
}

/**
 * @param string   $hook Hook name.
 * @param callable $cb   Callback.
 * @param int      $prio Priority.
 * @param int      $args Accepted args.
 * @return bool
 */
function add_action( $hook, $cb = null, $prio = 10, $args = 1 ) {
	return true;
}

/**
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function __( $s, $d = null ) {
	return $s;
}

/**
 * @param string $a Singular.
 * @param string $b Plural.
 * @param int    $n Count.
 * @param string $d Domain.
 * @return string
 */
function _n( $a, $b, $n, $d = null ) {
	return 1 === (int) $n ? $a : $b;
}

$GLOBALS['__caps'] = array( 'manage_options' => true, 'edit_posts' => true );

/**
 * @param string $cap  Capability.
 * @param mixed  ...$a Object args.
 * @return bool
 */
function current_user_can( $cap, ...$a ) {
	return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false );
}

/**
 * @param string $k Option.
 * @param mixed  $d Default.
 * @return mixed
 */
function get_option( $k, $d = false ) {
	return $GLOBALS['__options'][ $k ] ?? $d;
}

/**
 * @param string $k Option.
 * @param mixed  $v Value.
 * @param mixed  $a Autoload.
 * @return bool
 */
function update_option( $k, $v, $a = false ) {
	$GLOBALS['__options'][ $k ] = $v;
	return true;
}

// ── The post table, able to say all THREE things about a target ───────
// A citation's target is in one of three states, and a stub that cannot tell
// them apart cannot fail on a reader that conflates them: a post that exists
// and has a title, a post that EXISTS with an empty title, and an id no post
// has. So `get_post()` is the resolver here (not a title map), and an unknown
// id yields neither a title nor a URL -- exactly what WordPress does.
/**
 * @param int|object $post   Post id or object.
 * @param string     $output Output shape.
 * @param string     $filter Filter context.
 * @return object|null
 */
function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	return $GLOBALS['__posts'][ $id ] ?? null;
}

/**
 * @param int|object $post Post id or object.
 * @return string
 */
function get_the_title( $post = 0 ) {
	$row = get_post( $post );
	return $row ? (string) $row->post_title : '';
}

/**
 * @param int|object $post      Post id or object.
 * @param bool       $leavename Leave the name placeholder.
 * @return string
 */
function get_permalink( $post = 0, $leavename = false ) {
	$row = get_post( $post );
	return $row ? 'https://example.test/?p=' . (int) $row->ID : '';
}

/**
 * @param string $url       URL.
 * @param int    $component Component constant.
 * @return mixed
 */
function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? wp_parse_url_all( $url ) : parse_url( (string) $url, $component );
}

/**
 * @param string $url URL.
 * @return array<string,mixed>|false
 */
function wp_parse_url_all( $url ) {
	return parse_url( (string) $url );
}

/**
 * Fixed, so the wording is pinnable. The real one is relative to now.
 *
 * @param int $from From timestamp.
 * @param int $to   To timestamp.
 * @return string
 */
function human_time_diff( $from, $to = 0 ) {
	return '3 days';
}

/**
 * @param string $gmt    UTC datetime.
 * @param string $format Format.
 * @return string
 */
function get_date_from_gmt( $gmt, $format = 'Y-m-d H:i:s' ) {
	return substr( (string) $gmt, 0, 16 );
}

/**
 * @param string $sql SQL.
 * @return array<int,mixed>
 */
function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return array();
}

// ── The two doors, recorded exactly as the parts ask for them ─────────
$GLOBALS['__door_calls'] = array();

/**
 * @param string $slug Top-level slug.
 * @param string $sub  Sub-tab.
 * @return string
 */
function snt_desktop_admin_url( $slug, $sub = '' ) {
	$GLOBALS['__door_calls'][] = array( $slug, $sub );
	return 'https://example.test/wp-admin/admin.php?page=' . $slug . '&sub=' . $sub;
}

// ── $wpdb: an in-memory store that READS the SQL it is handed ─────────
/** Two fixture tables, driven through the real store and engine reads. */
class SNE_Stub_wpdb {
	public $prefix = 'wp_';
	public $rows   = array();

	/** @return string */
	public function get_charset_collate() {
		return '';
	}

	/**
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return (string) preg_replace_callback(
			'/%[sdf]/',
			static function ( $m ) use ( &$i, $args ) {
				$a = $args[ $i ] ?? '';
				++$i;
				if ( '%d' === $m[0] ) {
					return (string) (int) $a;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $a;
				}
				return "'" . addslashes( (string) $a ) . "'";
			},
			$query
		);
	}

	/**
	 * @param string $query  SQL.
	 * @param string $output Output constant.
	 * @return array<int,mixed>
	 */
	public function get_results( $query, $output = 'OBJECT' ) {
		$GLOBALS['__sql'][] = $query;
		if ( false !== strpos( $query, 'GROUP BY tier' ) ) {
			$by = array();
			foreach ( $this->rows['wp_sn_citations'] as $r ) {
				$by[ $r['tier'] ] = ( $by[ $r['tier'] ] ?? 0 ) + 1;
			}
			$out = array();
			foreach ( $by as $tier => $n ) {
				$out[] = (object) array( 'tier' => $tier, 'n' => $n );
			}
			return $out;
		}
		$table = false !== strpos( $query, 'wp_sn_citations' ) ? 'wp_sn_citations' : 'wp_sn_schedules';
		$rows  = $this->rows[ $table ];
		if ( preg_match( '/ORDER BY (\w+) (ASC|DESC)/', $query, $m ) ) {
			$col = $m[1];
			$dir = 'DESC' === $m[2] ? -1 : 1;
			usort(
				$rows,
				static function ( $a, $b ) use ( $col, $dir ) {
					return $dir * strcmp( (string) ( $a[ $col ] ?? '' ), (string) ( $b[ $col ] ?? '' ) );
				}
			);
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $m ) ) {
			$rows = array_slice( $rows, 0, (int) $m[1] );
		}
		if ( ARRAY_A === $output ) {
			return $rows;
		}
		return array_map( static function ( $r ) { return (object) $r; }, $rows );
	}

	/**
	 * @param string $query SQL.
	 * @return string
	 */
	public function get_var( $query ) {
		$GLOBALS['__sql'][] = $query;
		if ( false !== strpos( $query, 'wp_sn_schedules' ) ) {
			$want = preg_match( "/target_type = '([^']*)'/", $query, $m ) ? $m[1] : null;
			$n    = 0;
			foreach ( $this->rows['wp_sn_schedules'] as $r ) {
				if ( null === $want || (string) $r['target_type'] === $want ) {
					++$n;
				}
			}
			return (string) $n;
		}
		$n = 0;
		foreach ( $this->rows['wp_sn_citations'] as $r ) {
			if ( null === $r['last_checked_gmt'] ) {
				++$n;
			}
		}
		return (string) $n;
	}
}

$GLOBALS['__sql'] = array();
$wpdb             = new SNE_Stub_wpdb();

// Deliberately NOT in first_seen order: the store's ORDER BY has to do the work.
// Seven rows, chosen so every state the reader distinguishes is present ONCE:
//   c7  resolved target, checked, 200
//   c9  resolved target, checked, 404 (a response, and not a good one)
//   c10 resolved target, checked, 200
//   c8  target_post_id 0, NEVER checked  -> 'not checked'
//   c11 resolved target, CHECKED with last_status 0 -> 'no response'
//   c12 target that EXISTS with an empty title -> '(no title)', and a door to it
//   c13 target id no post has -> 'unresolved target', and NO door
$wpdb->rows['wp_sn_citations'] = array(
	array( 'id' => 7, 'pair_hash' => 'h7', 'source_url' => 'https://Example.org/a', 'target_url' => 'https://example.test/notes/one/', 'target_post_id' => 11, 'source_title' => 'The Example Post', 'tier' => 'verified', 'first_seen_gmt' => '2026-08-01 09:00:00', 'last_checked_gmt' => '2026-09-01 09:00:00', 'last_status' => 200 ),
	array( 'id' => 9, 'pair_hash' => 'h9', 'source_url' => 'https://third.example/c', 'target_url' => 'https://example.test/notes/three/', 'target_post_id' => 12, 'source_title' => '', 'tier' => 'unverified', 'first_seen_gmt' => '2026-08-10 09:00:00', 'last_checked_gmt' => '2026-08-30 12:00:00', 'last_status' => 404 ),
	array( 'id' => 10, 'pair_hash' => 'hA', 'source_url' => 'https://fourth.example/d', 'target_url' => 'https://example.test/notes/four/', 'target_post_id' => 11, 'source_title' => '', 'tier' => 'unattributed', 'first_seen_gmt' => '2026-08-25 09:00:00', 'last_checked_gmt' => '2026-08-31 09:00:00', 'last_status' => 200 ),
	array( 'id' => 8, 'pair_hash' => 'h8', 'source_url' => 'https://another.example/b', 'target_url' => 'https://example.test/notes/two/', 'target_post_id' => 0, 'source_title' => '', 'tier' => 'asserted', 'first_seen_gmt' => '2026-08-20 09:00:00', 'last_checked_gmt' => null, 'last_status' => 0 ),
	array( 'id' => 11, 'pair_hash' => 'hB', 'source_url' => 'https://fifth.example/e', 'target_url' => 'https://example.test/notes/five/', 'target_post_id' => 11, 'source_title' => 'A fetch that timed out', 'tier' => 'verified', 'first_seen_gmt' => '2026-08-05 09:00:00', 'last_checked_gmt' => '2026-08-29 09:00:00', 'last_status' => 0 ),
	array( 'id' => 12, 'pair_hash' => 'hC', 'source_url' => 'https://sixth.example/f', 'target_url' => 'https://example.test/notes/six/', 'target_post_id' => 13, 'source_title' => 'Cites the untitled one', 'tier' => 'unverified', 'first_seen_gmt' => '2026-08-15 09:00:00', 'last_checked_gmt' => '2026-08-28 09:00:00', 'last_status' => 200 ),
	array( 'id' => 13, 'pair_hash' => 'hD', 'source_url' => 'https://seventh.example/g', 'target_url' => 'https://example.test/notes/seven/', 'target_post_id' => 99, 'source_title' => 'Cites a note that is gone', 'tier' => 'asserted', 'first_seen_gmt' => '2026-08-28 09:00:00', 'last_checked_gmt' => '2026-08-29 09:00:00', 'last_status' => 500 ),
);

$wpdb->rows['wp_sn_schedules'] = array(
	array( 'id' => 1, 'schedule_id' => 'u1', 'target_type' => 'fragment', 'target_ref' => '11', 'action' => 'reveal', 'starts_at' => '2026-10-01 08:00:00', 'ends_at' => '2026-10-05 08:00:00', 'recurrence' => null, 'payload' => null, 'status' => 'active', 'last_run' => '2026-09-01 00:00:00', 'purge_urls' => '["https://example.test/a","https://example.test/b"]', 'updated' => null ),
	array( 'id' => 2, 'schedule_id' => 'u2', 'target_type' => 'fragment', 'target_ref' => '', 'action' => 'hide', 'starts_at' => null, 'ends_at' => null, 'recurrence' => null, 'payload' => null, 'status' => 'queued', 'last_run' => null, 'purge_urls' => null, 'updated' => null ),
	array( 'id' => 3, 'schedule_id' => 'u3', 'target_type' => 'fragment', 'target_ref' => '12', 'action' => 'hide', 'starts_at' => '2026-09-15 08:00:00', 'ends_at' => null, 'recurrence' => null, 'payload' => null, 'status' => 'error', 'last_run' => '2026-09-02 00:00:00', 'purge_urls' => '[]', 'updated' => null ),
	array( 'id' => 4, 'schedule_id' => '', 'target_type' => 'page', 'target_ref' => '11', 'action' => 'reveal', 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => null, 'recurrence' => null, 'payload' => null, 'status' => 'done', 'last_run' => null, 'purge_urls' => null, 'updated' => null ),
	array( 'id' => 5, 'schedule_id' => 'u5', 'target_type' => 'fragment', 'target_ref' => '11', 'action' => 'reveal', 'starts_at' => '2026-09-15 08:00:00', 'ends_at' => '2026-09-20 08:00:00', 'recurrence' => null, 'payload' => null, 'status' => 'done', 'last_run' => null, 'purge_urls' => null, 'updated' => null ),
);

// Post 13 EXISTS and has no title; there is no post 99. Those two rows are the
// only way a suite can tell "untitled" apart from "gone".
$GLOBALS['__posts'] = array(
	11 => (object) array( 'ID' => 11, 'post_title' => 'The signer keeps moving' ),
	12 => (object) array( 'ID' => 12, 'post_title' => 'A draft' ),
	13 => (object) array( 'ID' => 13, 'post_title' => '' ),
);

require_once __DIR__ . '/../inc/openstation-app.php';
require_once __DIR__ . '/../inc/citations-core.php';
require_once __DIR__ . '/../inc/citations-store.php';
require_once __DIR__ . '/../inc/citations-admin.php';
require_once __DIR__ . '/../inc/note-dossier.php';
require_once __DIR__ . '/../inc/schedule-engine.php';
require_once __DIR__ . '/../inc/schedule-admin.php';
require_once __DIR__ . '/../apps/signal-noise/parts/citations.php';
require_once __DIR__ . '/../apps/signal-noise/parts/schedules.php';

$pass = 0;
$fail = 0;
/**
 * Record one assertion.
 *
 * @param bool   $c Condition.
 * @param string $m What it means.
 * @return void
 */
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "PASS: $m\n";
	} else {
		++$fail;
		echo "FAIL: $m\n";
	}
}

/**
 * A registered descriptor by id, through the registry's own gate.
 *
 * @param string $id Section id.
 * @return array<string,mixed>
 */
function section( $id ) {
	return (array) snt_os_app_section( $id );
}

echo "openstation-app-entries -- Citations and Scheduled fragments (#1068)\n\nGroup 1: the two store reads that did not exist\n";
$all = sn_cit_all( 3 );
ok( 3 === count( $all ), 'sn_cit_all() honours its bound (3 of 7 rows)' );
ok( array( 13, 10, 8 ) === array_map( static function ( $r ) { return (int) $r->id; }, $all ), '   ...newest first_seen_gmt first -- the store orders, the caller does not' );
ok( is_object( $all[0] ) && 'asserted' === $all[0]->tier, '   ...rows come back as objects, the shape every other sn_cit_ reader returns' );
ok( 7 === count( sn_cit_all( 400 ) ), 'a bound larger than the table returns the whole table' );
ok( array( 'queued', 'active', 'done', 'error' ) === SN_SCHEDULE_STATUSES, 'SN_SCHEDULE_STATUSES names the four states the engine writes, in the order it walks them' );
ok( 4 === sn_schedule_count( 'fragment' ), 'sn_schedule_count() counts one target_type only: four fragments, not the five rows' );
ok( 1 === sn_schedule_count( 'page' ), '   ...and answers for another target_type without a second query shape' );
ok( 0 === sn_schedule_count( 'nothing-writes-this' ), '   ...an unwritten target_type is a measured zero, not an error' );

echo "\nGroup 2: both sections register, gated as the leaves they open\n";
ok( array( 'citations', 'schedules' ) === array_column( snt_os_app_sections(), 'id' ), 'both sections register, in position order (30, 40)' );
$GLOBALS['__caps']['manage_options'] = false;
ok( array() === snt_os_app_sections(), 'both are hidden without manage_options -- the capability of the leaves they are a door to' );
$GLOBALS['__caps']['manage_options'] = true;
$cit = section( 'citations' );
$sch = section( 'schedules' );
ok( 'Citations' === $cit['label'] && 'dashicons-admin-links' === $cit['icon'] && 'entry' === $cit['kind'] && 30 === (int) $cit['position'], 'the Citations descriptor: label, icon, kind entry, position 30' );
ok( 'Scheduled fragments' === $sch['label'] && 'dashicons-clock' === $sch['icon'] && 'entry' === $sch['kind'] && 40 === (int) $sch['position'], 'the Scheduled descriptor: label, icon, kind entry, position 40' );
ok( ! isset( $cit['restPath'] ) && ! isset( $cit['edit_url'] ) && ! isset( $cit['hasDossier'] ), 'Citations carries no restPath, no edit_url and no hasDossier: the client\'s three opt-ins, all declined' );
ok( ! isset( $sch['restPath'] ) && ! isset( $sch['edit_url'] ) && ! isset( $sch['hasDossier'] ), 'Scheduled carries none of the three either -- no drag, no menu, no dossier fetch' );
ok( '' === (string) $cit['default_status'] && '' === (string) $sch['default_status'], 'both open on All: an entry section has no natural default pill' );
ok( array( 'verified', 'unattributed', 'asserted', 'unverified' ) === array_column( $cit['statuses'], 'value' ), 'the Citations pills are the tier ladder, in the ladder\'s order' );
ok( array( 'Verified', 'Unattributed', 'Asserted', 'Unverified' ) === array_column( $cit['statuses'], 'label' ), '   ...each labelled with the tier\'s own word' );
ok( SN_SCHEDULE_STATUSES === array_column( $sch['statuses'], 'value' ), 'the Scheduled pills are SN_SCHEDULE_STATUSES, not a sixth copy of the four literals' );
// The list view already paints Status and Date from statusLabel and dateLabel.
// A column that repeats one of those is a second copy of the same cell, so the
// descriptors declare only what those two do not carry.
ok( array( 'target', 'code' ) === array_column( $cit['columns'], 'key' ), 'Citations list columns are target and code -- NOT tier (that is the status pill\'s own word) and NOT checked (that is dateLabel)' );
ok( array( 'Target', 'Last status' ) === array_column( $cit['columns'], 'label' ), '   ...and the code column is labelled Last status, the fact it actually holds' );
ok( array( 'action', 'ends' ) === array_column( $sch['columns'], 'key' ), 'Scheduled list columns are action and ends -- not starts (dateLabel) and not status (statusLabel)' );

echo "\nGroup 3: the counts, so items() never runs at the root\n";
ok( is_callable( $cit['count'] ) && is_callable( $sch['count'] ), 'both descriptors supply a count callable -- without one the payload calls items() at the ROOT on every paint' );
ok( 7 === (int) call_user_func( $cit['count'] ), 'the Citations count sums the four tiers -- all seven rows, once each (never_checked is a second reading of the same rows, not a fifth tier)' );
ok( 4 === (int) call_user_func( $sch['count'] ), 'the Scheduled count is the fragment count, so the page row is not counted into a fragment folder' );

echo "\nGroup 4: Citations items\n";
$items = array_values( (array) call_user_func( $cit['items'] ) );
ok( array( 'c13', 'c10', 'c8', 'c12', 'c9', 'c11', 'c7' ) === array_column( $items, 'id' ), 'ids are the row id behind a c, and the order is the store\'s: newest claim first' );
// By id, not by offset: a fixture added in the middle of the ladder must not
// silently re-point a pin at another row.
$by = array();
foreach ( $items as $one ) {
	$by[ $one['id'] ] = $one;
}
$c7  = $by['c7'];
$c8  = $by['c8'];
$c9  = $by['c9'];
$c11 = $by['c11'];
$c12 = $by['c12'];
$c13 = $by['c13'];
ok( 'The Example Post' === $c7['title'] && 'The signer keeps moving' === $c7['subtitle'], 'a resolved row: the source title, and the cited note as the subtitle' );
ok( 'third.example' === $c9['title'], 'with no source title the row reads as the source HOST, not a bare URL' );
ok( 'unresolved target' === $c8['subtitle'], 'a row whose target_post_id is 0 says so; it does not print an empty subtitle' );
ok( 'dashicons-admin-links' === $c7['icon'] && '' === $c7['thumbnail'], 'a citation has no image: the link icon, an empty thumbnail' );
ok( 'verified' === $c7['status'] && 'Verified' === $c7['statusLabel'], 'the tier IS the status the pills filter on' );
ok( '2026-09-01 09:00:00' === $c7['date'] && '3 days ago' === $c7['dateLabel'], 'the date is the last check; the label is the store\'s own ago wording' );
ok( '' === $c8['date'] && 'never' === $c8['dateLabel'], 'never checked is never, not a date -- the nullable column\'s whole point, carried through' );
ok( array( 'text' => 'verified', 'tone' => 'success', 'title' => 'Link present, publisher named. Public.' ) === $c7['badge'], 'the badge is the tier, its tone through sn_note_dossier_tone( sn_cit_tier_pill_kind() ), its gloss as the title' );
$tones = array();
foreach ( $items as $it ) {
	$tones[ $it['status'] ] = $it['badge']['tone'];
}
ok( array( 'verified' => 'success', 'unattributed' => 'info', 'asserted' => 'warning', 'unverified' => 'neutral' ) === array( 'verified' => $tones['verified'], 'unattributed' => $tones['unattributed'], 'asserted' => $tones['asserted'], 'unverified' => $tones['unverified'] ), 'every tier bridges to its own tone: ok->success, \'\'->info, warn->warning, muted->neutral' );
ok( array( 'target', 'code' ) === array_keys( $c7['columns'] ), 'the item carries a cell for each declared column and NO cell the descriptor never declared -- a key with no header paints nowhere' );
ok( array_column( $cit['columns'], 'key' ) === array_keys( $c7['columns'] ), '   ...and the two lists are the SAME list, in the same order: the descriptor and the item cannot drift apart' );
ok( 'The signer keeps moving' === $c7['columns']['target'] && '200' === $c7['columns']['code'], 'the cells read the row, not the descriptor' );
// The three states of the Last status cell, said apart. Two of them were one
// sentence before v13.102.1: a row that was never fetched read "no response",
// which is what a row that WAS fetched and got nothing says.
ok( 'not checked' === $c8['columns']['code'] && 'not checked' === $c8['detail']['facts'][4][1], 'a row that was NEVER checked says not checked -- there was no fetch, so there is no response to report' );
ok( 'no response' === $c11['columns']['code'] && 'no response' === $c11['detail']['facts'][4][1], 'a row that WAS checked and got status 0 says no response: the fetch happened and nothing came back' );
ok( '404' === $c9['columns']['code'], '   ...and a real code is the code: a 404 is a response, and distinct from both of the above' );
$d7 = $c7['detail'];
ok( '' === $d7['hero'] && array() === $d7['blocks'], 'no hero image and no blocks: everything a citation knows fits in its facts' );
ok( array( 'Source', 'Target', 'Tier', 'Last checked', 'Last status' ) === array_column( $d7['facts'], 0 ), 'the five facts, in the order the row is adjudicated' );
ok( 'https://Example.org/a' === $d7['facts'][0][1] && 'The signer keeps moving' === $d7['facts'][1][1], 'Source is the URL as cited; Target is the note by name when the row resolved one' );
ok( 'https://example.test/notes/two/' === $c8['detail']['facts'][1][1], '   ...and the target URL when it did not: the path is never lost' );
ok( false !== strpos( $d7['facts'][2][1], 'the link is still there' ), 'Tier is the full sentence, not the one-word verdict twice' );
ok( '404' === $c9['detail']['facts'][4][1], 'Last status: the code when there was one' );
ok( array( 'Open Citations in S&N Dashboard', 'View the note' ) === array_column( $d7['actions'], 'label' ), 'a resolved row offers the leaf and the note' );
ok( 'https://example.test/wp-admin/admin.php?page=sn-tools&sub=citations' === $d7['actions'][0]['url'], 'the door is Integrity -> Citations, built from the slug and its sub-tab' );
ok( 'https://example.test/?p=11' === $d7['actions'][1]['url'], '   ...and the note is its permalink' );
ok( array( 'Open Citations in S&N Dashboard' ) === array_column( $c8['detail']['actions'], 'label' ), 'an unresolved row offers the door alone -- there is no note to open' );

// EXISTENCE and TITLEDNESS are two facts, and one resolution answers both.
// Before v13.102.1 an untitled target read "unresolved target" AND was handed
// a "View the note" link -- the row denied and offered in the same breath.
ok( '(no title)' === $c12['subtitle'] && '(no title)' === $c12['columns']['target'], 'a target that EXISTS with an empty title is (no title): it resolved, it just has no name' );
ok( '(no title)' === $c12['detail']['facts'][1][1], '   ...and the Target fact says the same thing the cell does' );
ok( array( 'Open Citations in S&N Dashboard', 'View the note' ) === array_column( $c12['detail']['actions'], 'label' ), '   ...and the door to it IS offered: a post with no title is still a post you can open' );
ok( 'https://example.test/?p=13' === $c12['detail']['actions'][1]['url'], '   ...at that post\'s own permalink' );
ok( 'unresolved target' === $c13['subtitle'], 'a target_post_id no post has is unresolved -- the id is set, and there is nothing behind it' );
ok( 'https://example.test/notes/seven/' === $c13['columns']['target'] && 'https://example.test/notes/seven/' === $c13['detail']['facts'][1][1], '   ...and the cell falls back to the stored URL, so the path is never lost' );
ok( array( 'Open Citations in S&N Dashboard' ) === array_column( $c13['detail']['actions'], 'label' ), '   ...and NO note door is offered: the link and the sentence come from ONE resolution, so a row can never deny and offer at once' );

echo "\nGroup 5: Scheduled fragments items\n";
$sitems = array_values( (array) call_user_func( $sch['items'] ) );
ok( array( 's3', 's5', 's1', 's2' ) === array_column( $sitems, 'id' ), 'soonest first, a tie broken by id, and the row with no start LAST -- not first, which is where an empty string sorts' );
ok( ! in_array( 's4', array_column( $sitems, 'id' ), true ) && 4 === count( $sitems ), 'the page-target row is not here: this section lists FRAGMENTS, and says so in its label' );
$s1 = $sitems[2];
$s2 = $sitems[3];
ok( 'The signer keeps moving' === $s1['title'], 'a fragment is named by the post it lives in' );
ok( '(unlinked fragment)' === $s2['title'], 'a fragment whose host is gone says so, rather than printing an empty title' );
ok( 'dashicons-clock' === $s1['icon'] && '' === $s1['thumbnail'], 'the clock icon, no thumbnail' );
ok( 'reveal · 2026-10-01 08:00 → 2026-10-05 08:00' === $s1['subtitle'], 'the subtitle is the action and the window, both boundaries in the site timezone' );
// An absent START and an absent END are opposite facts. A fragment with no
// start is open from the beginning; only the end can be never. Both read
// "never" before v13.102.1, which said the window had not begun and would not.
ok( 'hide · always → never' === $s2['subtitle'], 'an open-START window reads always on the left and never on the right: a fragment with no start is showing already, not waiting forever' );
ok( '2026-09-15 08:00 → never' === $sitems[0]['badge']['title'], '   ...while a row that HAS a start keeps it, so "always" is a reading of the absence and not a constant' );
ok( 'active' === $s1['status'] && 'Active' === $s1['statusLabel'], 'the row status IS the status the pills filter on' );
ok( '2026-10-01 08:00:00' === $s1['date'] && '2026-10-01 08:00' === $s1['dateLabel'], 'the date is the start; the label is the formatted start' );
ok( '' === $s2['date'] && '' === $s2['dateLabel'], 'no start, no date: the tile carries nothing rather than today' );
$stones = array();
foreach ( $sitems as $it ) {
	$stones[ $it['status'] ] = $it['badge']['tone'];
}
ok( array( 'active' => 'success', 'error' => 'warning', 'queued' => 'neutral', 'done' => 'neutral' ) === array( 'active' => $stones['active'], 'error' => $stones['error'], 'queued' => $stones['queued'], 'done' => $stones['done'] ), 'every status has a tone: active reads success, error reads warning, the two resting states read neutral' );
ok( 'active' === $s1['badge']['text'], 'the badge says the status in the row\'s own word' );
ok( array( 'action', 'ends' ) === array_keys( $s1['columns'] ), 'a cell for each declared column, and no cell the descriptor never declared' );
ok( array_column( $sch['columns'], 'key' ) === array_keys( $s1['columns'] ), '   ...the descriptor\'s list and the item\'s list are the SAME list, in the same order' );
ok( 'reveal' === $s1['columns']['action'] && '2026-10-05 08:00' === $s1['columns']['ends'], 'the cells read the row' );
ok( 'never' === $sitems[0]['columns']['ends'], 'an open-ended end reads never in the list too' );
$sd1 = $s1['detail'];
ok( array( 'Target', 'Action', 'Starts', 'Ends', 'Status', 'Last run', 'Purge URLs' ) === array_column( $sd1['facts'], 0 ), 'the seven facts a schedule row knows about itself' );
ok( 'The signer keeps moving' === $sd1['facts'][0][1] && 'reveal' === $sd1['facts'][1][1] && '2026-09-01 00:00' === $sd1['facts'][5][1] && '2' === $sd1['facts'][6][1], 'Target, Action, Last run (formatted) and the purge-URL count' );
ok( 'never' === $s2['detail']['facts'][5][1], 'a row that has never run says never, not an empty cell' );
ok( 1 === count( $sd1['blocks'] ) && 'table' === $sd1['blocks'][0]['kind'] && 'https://example.test/a' === $sd1['blocks'][0]['rows'][0]['url'] && 'https://example.test/b' === $sd1['blocks'][0]['rows'][1]['url'], 'the purge URLs are a table, decoded from the stored JSON' );
ok( array() === $sitems[0]['detail']['blocks'] && array() === $s2['detail']['blocks'], 'an empty list and a null column both mean no table, not an empty one' );
ok( array( 'Open Scheduled in S&N Dashboard', 'View the note' ) === array_column( $sd1['actions'], 'label' ), 'a linked fragment offers the leaf and the note' );
ok( 'https://example.test/wp-admin/admin.php?page=sn-connections&sub=scheduled-content' === $sd1['actions'][0]['url'], 'the door is Connections -> Scheduled content' );
ok( array( 'Open Scheduled in S&N Dashboard' ) === array_column( $s2['detail']['actions'], 'label' ), 'an unlinked fragment offers the door alone' );
ok( in_array( array( 'sn-tools', 'citations' ), $GLOBALS['__door_calls'], true ) && in_array( array( 'sn-connections', 'scheduled-content' ), $GLOBALS['__door_calls'], true ), 'both doors go through the slug resolver, never through a literal admin URL' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
