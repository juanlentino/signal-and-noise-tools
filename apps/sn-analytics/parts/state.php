<?php
/**
 * S&N Analytics host — the state, and the `$_GET` it lends the page.
 *
 * THE URL WAS THE STATE. The classic Analytics screen changes nothing: every
 * control on it is a GET link or a GET form, and the nine `sn_*` params in the
 * address bar are the whole of what a reader has chosen. A window has no
 * address bar, so those nine params ARE the window's state — named as the page
 * names them, minus the `sn_` prefix — and a bookmarkable classic URL and a
 * window state stay the same fact.
 *
 * EVERY VALUE GOES THROUGH THE PAGE'S OWN RESOLVER. `snt_analytics_resolve_view`,
 * `snt_analytics_resolve_window`, `snt_analytics_resolve_class`,
 * `snt_analytics_resolve_compare`, `sn_analytics_drilldown_parse` and
 * `sn_login_defense_resolve_days` are CALLED, never reimplemented: a second
 * whitelist here would be a second answer to "is this a real view", and the two
 * would drift the first time a view was added. The defaults are the same
 * resolvers asked with nothing, for the same reason.
 *
 * These functions live in the GLOBAL namespace, like inc/openstation-host.php:
 * they are the host's half of a page that is itself global, and the suite
 * drives them by the names the plan gave them.
 *
 * @package SignalNoiseTools
 */

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The one `sn_action` whose form must never become a dispatch.
 *
 * `sn_handle_analytics_export()` sets `Content-Disposition`, echoes a raw
 * CSV/JSON body and `exit`s — it returns no flash code and renders no HTML, so
 * there is nothing for a window to paint and an `exit` a window cannot survive.
 * The rewrite therefore leaves its form real (see `snt_os_host_keep_forms()`),
 * and the `post` action refuses it by name if it ever arrives anyway.
 *
 * A literal because nothing in the estate marks a handler as "streams a file":
 * the handler table maps every action to a callable and says nothing about what
 * the callable does with the response. The suite pins the name against BOTH
 * `sn_admin_post_handlers()` and the form that emits it, so a rename goes red
 * rather than quiet.
 *
 * @return string[]
 */
function snt_os_analytics_keep_actions() {
	return array( 'analytics_export' );
}

/**
 * The window's state, with every default asked of the page's own resolver.
 *
 * Asked with an empty value, each resolver answers exactly what the classic
 * page falls back to when its param is absent — which is what a fresh window
 * is. A literal table here would be a copy of five whitelists.
 *
 * @return array<string,mixed>
 */
function snt_os_analytics_defaults() {
	return array(
		// The landing view. `snt_analytics_resolve_view()` sends every unknown
		// and every retired slug here too.
		'view'       => function_exists( 'snt_analytics_resolve_view' ) ? snt_analytics_resolve_view( '' ) : 'overview',
		// A STRING, deliberately: the framework's State coerces every write onto
		// the declared default's type and falls back to the default when the
		// shapes disagree (desktop-mode app/class-state.php, accept()). Declared
		// as the integer 7, 'custom' and the seven calendar presets silently
		// became 7 -- measured in the sandbox on the custom-date form.
		'range'      => (string) snt_os_analytics_window( '', '', '' )[0],
		'from'       => '',
		'to'         => '',
		'class'      => function_exists( 'snt_analytics_resolve_class' ) ? snt_analytics_resolve_class( '' ) : 'human',
		'compare'    => function_exists( 'snt_analytics_resolve_compare' ) ? snt_analytics_resolve_compare( '' ) : 'off',
		'drill'      => '',
		'event_prop' => '',
		'lg_range'   => snt_os_analytics_lg_range( '' ),
		// The classic page's `?sn_flash` notice, as `[ severity, html ]`.
		'notice'     => null,
	);
}

/**
 * The window params a tab switch resets, as STATE keys.
 *
 * `snt_analytics_view_reset_params()` is the estate's one source for this list
 * — the tab strip and the Overview doorway builder both consume it — and it is
 * read here rather than retyped, so a param added there is reset here too.
 * `sn_compare` is deliberately absent from it: the active compare mode rides
 * along across a tab switch, and nothing in this file may put it back.
 *
 * `view` is dropped from the mapping because the switch itself sets it.
 *
 * @return string[]
 */
function snt_os_analytics_reset_keys() {
	if ( ! function_exists( 'snt_analytics_view_reset_params' ) ) {
		return array();
	}
	$keys = array();
	foreach ( (array) snt_analytics_view_reset_params() as $param ) {
		$key = preg_replace( '/^sn_/', '', (string) $param );
		if ( '' !== $key && 'view' !== $key ) {
			$keys[] = $key;
		}
	}
	return $keys;
}

/**
 * The window [ token, from, to ] for a raw range/from/to triple.
 *
 * `from`/`to` are kept ONLY for a resolved `custom` window, exactly as
 * `snt_analytics_window_args()` carries them only for custom: for every other
 * token the dates are re-derived from the token alone, and storing a stale pair
 * beside a rolling range is how a window says one thing and shows another.
 *
 * @param mixed $range_raw Range token.
 * @param mixed $from_raw  Y-m-d.
 * @param mixed $to_raw    Y-m-d.
 * @return array{0:int|string,1:string,2:string}
 */
function snt_os_analytics_window( $range_raw, $from_raw, $to_raw ) {
	if ( ! function_exists( 'snt_analytics_resolve_window' ) ) {
		return array( 7, '', '' );
	}
	list( $range, $from, $to ) = snt_analytics_resolve_window(
		snt_os_host_last( $range_raw ),
		snt_os_host_last( $from_raw ),
		snt_os_host_last( $to_raw )
	);
	if ( 'custom' !== (string) $range ) {
		return array( $range, '', '' );
	}
	return array( $range, (string) $from, (string) $to );
}

/**
 * A `sn_drill` value, kept only when the page can parse it.
 *
 * `sn_analytics_drilldown_parse()` is the page's own reader: a value with no
 * `:`, an empty side, or a dim outside `SN_ANALYTICS_DIM_COLUMNS` is null there
 * and empty here. The RAW string is what state keeps, because that is what the
 * URL kept and what the view re-parses; the view also gates it on the dims the
 * active view owns, which is the page's job and stays the page's job.
 *
 * @param mixed $raw Raw value.
 * @return string
 */
function snt_os_analytics_drill( $raw ) {
	$raw = snt_os_host_last( $raw );
	if ( '' === $raw || ! function_exists( 'sn_analytics_drilldown_parse' ) ) {
		return '';
	}
	return null === sn_analytics_drilldown_parse( $raw ) ? '' : $raw;
}

/**
 * The login-defense range, through login defense's OWN clamp.
 *
 * `sn_login_defense_resolve_days()` reads `$_GET` — it is the header's and the
 * body's shared clamp and was never written to take an argument — so the value
 * is LENT to it the way the capture lends a leaf its query, and taken back in a
 * `finally`. That is one borrowed superglobal against retyping `array( 7, 30,
 * 90 )` into a second place that could disagree with the pills on screen.
 *
 * @param mixed $raw Raw value.
 * @return int 7, 30 or 90.
 */
function snt_os_analytics_lg_range( $raw ) {
	if ( ! function_exists( 'sn_login_defense_resolve_days' ) ) {
		return 7;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- Reading the CURRENT superglobal only to restore it; nothing here is input.
	$prev = isset( $_GET ) ? $_GET : array();
	// phpcs:enable WordPress.Security.NonceVerification
	$_GET = array( 'sn_lg_range' => snt_os_host_last( $raw ) );
	try {
		return (int) sn_login_defense_resolve_days();
	} finally {
		$_GET = $prev;
	}
}

/**
 * Apply one navigation's args to the state.
 *
 * The args are the classic URL's own vocabulary — `sn_view`, `sn_range`, … —
 * because that is what BOTH sources ship: a rewritten link carries the query it
 * had as `os-arg-sn_*`, and the custom-date `<form method="get">` carries
 * fields named `sn_from` / `sn_to`. One vocabulary, so nothing has to guess
 * which spelling a dispatch used.
 *
 * A NAVIGATION IS THE WHOLE NEXT URL. On the classic page every control is a
 * link or a GET form whose query IS the next state: a link that carries a
 * param sets it, and a link that omits one -- the Compare `Off` pill, `Clear
 * drill-down`, the Events property `Clear`, the movers' bare deep link -- is
 * the page saying "default". So a `go` (a rewritten own-page link, the
 * custom-date form, the brush's carrier, an open-time param set) is applied
 * WHOLESALE: every one of the nine params absent from the args takes the
 * page's own default, exactly as the classic dispatcher reads an absent
 * `$_GET` key. The first build merged instead ("absent keeps the value it
 * had"), and every clear/off control on the page was dead in the window --
 * the review measured all three. The view-switch reset list the tab strip
 * applies (`snt_analytics_view_reset_params()`) is therefore already in the
 * link the strip prints; nothing re-applies it here.
 *
 * @param \OpenStation\App\State $state Window state.
 * @param array<string,mixed>    $args  Dispatch args (`os-arg-*` plus a GET form's fields).
 * @return void
 */
function snt_os_analytics_apply( $state, array $args ) {
	$defaults = snt_os_analytics_defaults();

	// The page's own default for anything the navigation did not carry.
	$read = static function ( $key ) use ( $args, $defaults ) {
		return array_key_exists( $key, $args ) ? $args[ $key ] : ( $defaults[ substr( $key, 3 ) ] ?? '' );
	};

	$state->set( 'view', function_exists( 'snt_analytics_resolve_view' ) ? snt_analytics_resolve_view( snt_os_host_last( $read( 'sn_view' ) ) ) : (string) $read( 'sn_view' ) );

	list( $range, $from, $to ) = snt_os_analytics_window( $read( 'sn_range' ), $read( 'sn_from' ), $read( 'sn_to' ) );
	$state->set( 'range', (string) $range )->set( 'from', (string) $from )->set( 'to', (string) $to );

	if ( function_exists( 'snt_analytics_resolve_class' ) ) {
		$state->set( 'class', snt_analytics_resolve_class( snt_os_host_last( $read( 'sn_class' ) ) ) );
	}
	if ( function_exists( 'snt_analytics_resolve_compare' ) ) {
		$state->set( 'compare', snt_analytics_resolve_compare( snt_os_host_last( $read( 'sn_compare' ) ) ) );
	}
	$state->set( 'drill', snt_os_analytics_drill( $read( 'sn_drill' ) ) );
	$prop = snt_os_host_last( $read( 'sn_event_prop' ) );
	// The Events view reads its own filter with sanitize_text_field(); the same
	// call, at the same boundary.
	$state->set( 'event_prop', function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $prop ) : $prop );
	$state->set( 'lg_range', snt_os_analytics_lg_range( $read( 'sn_lg_range' ) ) );
}

/**
 * The `$_GET` this state lends the page.
 *
 * EVERY key the dispatcher reads is emitted, empty when the state has no value
 * for it, because an empty string and an absent key are the same answer to
 * every one of those readers (`resolve_window('')` is the default window,
 * `sn_analytics_drilldown_parse('')` is null, the Events filter is '') — while
 * a MISSING key is the shape in which a param silently stops arriving.
 *
 * @param \OpenStation\App\State $state Window state.
 * @return array<string,string>
 */
function snt_os_analytics_get( $state ) {
	$range = $state->get( 'range' );
	return array(
		'page'          => defined( 'SNT_ANALYTICS_PAGE_SLUG' ) ? SNT_ANALYTICS_PAGE_SLUG : 'sn-analytics',
		'sn_view'       => (string) $state->get( 'view' ),
		'sn_range'      => (string) $range,
		'sn_from'       => (string) $state->get( 'from' ),
		'sn_to'         => (string) $state->get( 'to' ),
		'sn_class'      => (string) $state->get( 'class' ),
		'sn_compare'    => (string) $state->get( 'compare' ),
		'sn_drill'      => (string) $state->get( 'drill' ),
		'sn_event_prop' => (string) $state->get( 'event_prop' ),
		'sn_lg_range'   => (string) $state->get( 'lg_range' ),
	);
}

/**
 * The request URI the page's own link builders read.
 *
 * `snt_analytics_render_view_tabs()`, `snt_analytics_render_controls()` and
 * login defense's pill row all build their hrefs on `add_query_arg( array() )`
 * / `remove_query_arg()` with no URL — which is `$_SERVER['REQUEST_URI']`. A
 * dispatch's REQUEST_URI is the REST route, so without this every tab, every
 * range pill and the custom-date form's action would point at
 * `/wp-json/…`: not an admin URL, so the rewrite would leave each one an
 * EXTERNAL link with its href intact, and clicking a tab would open the JSON
 * endpoint in a new browser tab.
 *
 * Built from the page's own URL accessor, so it follows the page if it moves
 * again (it has moved twice). Empty values are dropped: the classic URL does
 * not carry `sn_from=` for a rolling window, and every builder here copies the
 * query it is given.
 *
 * @param array<string,string> $get The `$_GET` from `snt_os_analytics_get()`.
 * @return string A root-relative `/wp-admin/admin.php?…`, or '' when unbuildable.
 */
function snt_os_analytics_request_uri( array $get ) {
	if ( ! function_exists( 'snt_analytics_page_url' ) || ! function_exists( 'wp_parse_url' ) ) {
		return '';
	}
	$args = array();
	foreach ( $get as $key => $value ) {
		if ( 'page' !== $key && '' !== (string) $value ) {
			// add_query_arg() does NOT encode the values it is handed (its
			// build_query() runs with urlencode off), so a state value carrying
			// `&` would inject a parameter into every link the report prints.
			$args[ $key ] = rawurlencode( (string) $value );
		}
	}
	$url  = (string) snt_analytics_page_url( $args );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( '' === $path ) {
		return '';
	}
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	return '' !== $query ? $path . '?' . $query : $path;
}

/**
 * The current navigation as a query string, for a control that moves the
 * window by script (the brush): on the classic page it merges its range into
 * `location.href`; in a window there is no such URL, so the view paints this
 * on the wrap and the brush merges into it, then dispatches the whole.
 *
 * @param \OpenStation\App\State $state Window state.
 * @return string `sn_view=…&sn_range=…`, empty values dropped, values encoded.
 */
function snt_os_analytics_query( $state ) {
	$pairs = array();
	foreach ( snt_os_analytics_get( $state ) as $key => $value ) {
		if ( 'page' !== $key && '' !== (string) $value ) {
			$pairs[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}
	}
	return implode( '&', $pairs );
}
