<?php
/**
 * Signal & Noise Tools — the HOST's write pipelines: how a form submitted
 * inside a window reaches the same handler the classic page would have run.
 *
 * WHY A SECOND FILE. inc/openstation-host.php is the PAINT — capture, rewrite,
 * assets. This is the WRITE, and the two answer different questions. The paint
 * asks "does this HTML still say what the leaf said"; the write asks "did the
 * estate's own handler run, and if it did not, WHICH gate closed". Keeping
 * them apart is what stops a refusal text from being written by whoever last
 * touched the rewrite.
 *
 * FOUR PIPELINES, because the estate has four and not one. The port's first
 * cut knew only the shared one and refused the other three with "the form
 * expired" — a cause that was never measured, on forms whose nonce was never
 * `sn_theme_options_nonce` (measured 2026-09-06: five Provenance forms, three
 * RSS forms, one inline audit-log form; all dead in the window, none of them
 * expired):
 *
 *   shared      `sn_action` + `sn_theme_options_nonce`, dispatched through
 *               `sn_admin_post_handlers()`. 45 of the 57 forms.
 *   admin-post  A `<form action="admin-post.php">` with its own `action` field
 *               and its OWN nonce (`sn_prov_reanchor`, `sn_prov_runsweep`,
 *               `sn_prov_stage_key`, `sn_prov_rotate_key`,
 *               `sn_prov_chain_backfill`). The handler is on `admin_post_<a>`.
 *   rss         `sn_rss_action` + `SN_RSS_TRACKER_NONCE`, dispatched by
 *               `sn_rss_tracker_handle_form()` on `admin_init`.
 *   inline      A form the LEAF handles itself, inside its own render function,
 *               out of `$_POST` (`audit_prune_now`). Nothing runs here: the
 *               values are handed to the next paint, which is exactly what the
 *               classic page does when the dispatcher finds no handler — it
 *               returns without redirecting and the page renders with `$_POST`
 *               still standing.
 *
 * THE INTERCEPTOR. `admin-post` and `rss` handlers end in
 * `wp_safe_redirect() + exit`, and refuse by `wp_die()`. A window can do
 * neither. So both run under two filters that turn each into a private
 * exception, and the caught exception IS the readout: a redirect's query is
 * the flash, the destination and the `sn_*` params the classic page would have
 * read out of its own URL; a die's message is the refusal text, word for word,
 * instead of a guess about what went wrong.
 *
 * Spec: docs/proposals/2026-09-06-openstation-hosts.md.
 *
 * @package SignalNoiseTools
 * @since 13.104.0
 */

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

if ( ! class_exists( 'SNT_OS_Host_Redirect_Signal' ) ) {
	/**
	 * A handler called `wp_redirect()`. Private to this file: it exists only to
	 * unwind the stack before the handler's own `exit` runs, carrying the
	 * Location it wanted as its message.
	 */
	class SNT_OS_Host_Redirect_Signal extends \Exception {}
}

if ( ! class_exists( 'SNT_OS_Host_Die_Signal' ) ) {
	/**
	 * A handler called `wp_die()`. Its message is the refusal the reader gets:
	 * a `check_admin_referer()` failure says what it measured, and the window
	 * must not paraphrase it.
	 */
	class SNT_OS_Host_Die_Signal extends \Exception {}
}

/**
 * Rebuild PHP's own request parsing over a FormData bag.
 *
 * THE BUG THIS EXISTS FOR. The runtime ships a form as
 * `new FormData( form ).forEach( ( v, k ) => o[k] = v )`
 * (desktop-mode assets/js/app-runtime.min.js, `jt()`), so every key is the
 * LITERAL `name` attribute — `social_same_as[]`, `now[groups][0][items]`,
 * `sn_exclude_roles[]`, `sn_tag_from[]` — and a repeated name becomes a JS
 * array. A real POST never reaches a handler in that shape: PHP's own body
 * parser expands the brackets before any WordPress code runs. Handing the raw
 * bag to a handler therefore makes every nested key ABSENT, and this estate's
 * handlers read absent as "the owner emptied this field": the /now override
 * deleted, the résumé wiped, `social.same_as` cleared, a webhook stored with
 * zero events.
 *
 * So the pairs are rebuilt and `parse_str()` — the same parser PHP uses for a
 * query string, brackets, later-wins duplicates, the `.`→`_` key mangling and
 * all — produces the array the handler would have seen. `rawurlencode()` on
 * both halves so a value containing `&`, `=` or `[` cannot forge a key.
 *
 * @param array<string,mixed> $values The dispatch's `values` bag.
 * @return array<string,mixed> The `$_POST` PHP itself would have built.
 */
function snt_os_host_expand( array $values ) {
	$pairs = array();
	foreach ( $values as $key => $value ) {
		$key  = rawurlencode( (string) $key );
		$list = is_array( $value ) ? $value : array( $value );
		foreach ( $list as $item ) {
			if ( is_scalar( $item ) || null === $item ) {
				$pairs[] = $key . '=' . rawurlencode( (string) $item );
			}
		}
	}
	$out = array();
	parse_str( implode( '&', $pairs ), $out );
	return $out;
}

/**
 * The last value PHP would have kept for a field name.
 *
 * A duplicate name in a urlencoded body is later-wins; the runtime turns the
 * same duplicate into an ARRAY (`sn_action` appears twice on the AI leaf: a
 * hidden `ai_settings_save` and the `ml_embed_compare` button). `expand()`
 * already collapses the wire shape, and this is the belt for a bag that
 * arrived as an array by any other route — never a `is_scalar()` refusal,
 * which is what "Run comparison" got.
 *
 * @param mixed $value Scalar, array or null.
 * @return string
 */
function snt_os_host_last( $value ) {
	if ( is_array( $value ) ) {
		$value = array() === $value ? '' : end( $value );
	}
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * The empty result. Every refusal and every success is this shape.
 *
 * `reason` and `detail` are separate because a refusal has to name the cause
 * that was MEASURED: `reason` is which gate closed, `detail` is the particular
 * it closed on (the action nobody handles, the nonce action that was checked,
 * the words a `wp_die()` used).
 *
 * @param array<string,mixed> $overrides Fields to set.
 * @return array{ok:bool,flash:string,target:?array,reason:string,detail:string,pipeline:string,params:array,post:array}
 */
function snt_os_host_result( array $overrides = array() ) {
	return array_merge(
		array(
			'ok'       => false,
			'flash'    => '',
			'target'   => null,
			'reason'   => '',
			'detail'   => '',
			'pipeline' => '',
			'params'   => array(),
			'post'     => array(),
		),
		$overrides
	);
}

/**
 * Which pipeline a submission belongs to, decided on what it CARRIES.
 *
 * Order matters and each step is a measurement: the form's own `action`
 * attribute (which the rewrite read before dropping it, and passed back as
 * `os-arg-pipeline`) beats everything, because that attribute is where the
 * classic browser would have posted; then the RSS leaf's own field; then the
 * shared handler table; and last, a `sn_action` the table does not know but
 * whose SHARED nonce verifies — which is an inline form, not an error.
 *
 * @param array<string,mixed> $values   Expanded values.
 * @param string              $declared `os-arg-pipeline` from the form, if any.
 * @return array{pipeline:string,action:string,reason:string}
 */
function snt_os_host_pipeline_for( array $values, $declared ) {
	$found = static function ( $pipeline, $action, $reason = '' ) {
		return array(
			'pipeline' => $pipeline,
			'action'   => $action,
			'reason'   => $reason,
		);
	};

	$wp_action = snt_os_host_last( isset( $values['action'] ) ? $values['action'] : null );
	if ( 'admin-post' === (string) $declared && '' !== $wp_action ) {
		return $found( 'admin-post', snt_os_host_slug( $wp_action ) );
	}

	if ( isset( $values['sn_rss_action'] ) && '' !== snt_os_host_last( $values['sn_rss_action'] ) ) {
		return $found( 'rss', snt_os_host_slug( snt_os_host_last( $values['sn_rss_action'] ) ) );
	}

	$action   = snt_os_host_slug( snt_os_host_last( isset( $values['sn_action'] ) ? $values['sn_action'] : null ) );
	$handlers = function_exists( 'sn_admin_post_handlers' ) ? sn_admin_post_handlers() : array();
	if ( '' !== $action && isset( $handlers[ $action ] ) && is_callable( $handlers[ $action ] ) ) {
		return $found( 'shared', $action );
	}
	if ( '' !== $action ) {
		// Not in the table. The classic dispatcher checks the shared nonce
		// BEFORE it looks the action up, so a bad nonce is a nonce refusal
		// here too — and a good one means the leaf handles this itself.
		$nonce = snt_os_host_last( isset( $values['_wpnonce'] ) ? $values['_wpnonce'] : null );
		if ( function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $nonce, SNT_OS_HOST_NONCE ) ) {
			return $found( 'inline', $action );
		}
		return $found( '', $action, 'nonce' );
	}

	return $found( '', $wp_action, 'unknown' );
}

/**
 * An action name, reduced to what an action name may be.
 *
 * NOT lowercased: `admin_post_<action>` is a hook name and hook names are
 * case-sensitive, so folding the case here would invent a transformation and
 * miss a hook that really exists. The character filter is the guard.
 *
 * @param string $action Raw.
 * @return string
 */
function snt_os_host_slug( $action ) {
	return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( (string) $action ) );
}

/**
 * Replay one form submission through whichever pipeline the estate owns it.
 *
 * `sn_handle_admin_post()` and the two `admin-post` / `admin_init` handlers all
 * end in a redirect and an exit, which a window cannot do. Everything BEFORE
 * that is reproduced exactly — capability, nonce, page allowlist, handler
 * table, flash code, redirect target — and the exit itself becomes a value.
 *
 * @param array<string,mixed> $values    The submitted fields (`$args['values']`).
 * @param string              $page_slug The `page=` slug the window stands for.
 * @param array<string,mixed> $get       The query the window would have had (tab, sub, `sn_*`).
 * @param string              $pipeline  `$args['pipeline']` — the rewrite's reading of the form's own action attribute.
 * @return array{ok:bool,flash:string,target:?array,reason:string,detail:string,pipeline:string,params:array,post:array}
 */
function snt_os_host_replay( array $values, $page_slug, array $get = array(), $pipeline = '' ) {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return snt_os_host_result( array( 'reason' => 'capability' ) );
	}

	// ONE expansion, at the entry, for every pipeline: the bag is the wire's
	// shape until this line and PHP's shape after it.
	$values    = snt_os_host_expand( $values );
	$page_slug = (string) $page_slug;
	$chosen    = snt_os_host_pipeline_for( $values, (string) $pipeline );

	switch ( $chosen['pipeline'] ) {
		case 'shared':
			return snt_os_host_after_write( snt_os_host_replay_shared( $values, $chosen['action'], $get, $page_slug ) );
		case 'admin-post':
			return snt_os_host_after_write( snt_os_host_replay_admin_post( $values, $chosen['action'], $get, $page_slug ) );
		case 'rss':
			return snt_os_host_after_write( snt_os_host_replay_rss( $values, $chosen['action'], $get, $page_slug ) );
		case 'inline':
			return snt_os_host_replay_inline( $values, $chosen['action'], $page_slug );
	}

	return snt_os_host_result(
		array(
			'reason' => '' !== $chosen['reason'] ? $chosen['reason'] : 'unknown',
			'detail' => 'nonce' === $chosen['reason'] ? SNT_OS_HOST_NONCE : $chosen['action'],
		)
	);
}

/**
 * After a write that succeeded: reset the per-request caches a leaf reads.
 *
 * On the classic page a save is followed by a redirect, so the leaf that
 * paints next runs in a NEW request and every request-static memo starts
 * empty. In a window the replay and the repaint share one request, and a
 * memo filled before the write answers after it: measured 2026-09-06, the
 * Identity & SEO save persisted `social_same_as[]` and the same response
 * painted the field empty, because sn_setting() memoises the merged
 * settings once per request and sn_settings_save() never resets it. The
 * resetters the estate already exposes are called by name; any other owner
 * of a request memo that a write can invalidate hooks `snt_os_host_wrote`.
 *
 * @param array<string,mixed> $result A pipeline's result.
 * @return array<string,mixed> The same result.
 */
function snt_os_host_after_write( array $result ) {
	if ( empty( $result['ok'] ) ) {
		return $result;
	}
	foreach ( array( 'sn_setting_reset_cache', 'snt_ai_reset_availability_cache' ) as $reset ) {
		if ( function_exists( $reset ) ) {
			$reset();
		}
	}
	if ( function_exists( 'do_action' ) ) {
		do_action( 'snt_os_host_wrote', $result );
	}
	return $result;
}

/**
 * The shared pipeline: `sn_action` + the shared nonce + the handler table.
 *
 * The values are `wp_slash()`ed into `$_POST` because every handler
 * `wp_unslash()`es what it reads — an unslashed replay silently eats a quote
 * in a saved title.
 *
 * @param array<string,mixed> $values    Expanded values.
 * @param string              $action    The `sn_action` slug.
 * @param array<string,mixed> $get       The window's query.
 * @param string              $page_slug The window's page slug.
 * @return array<string,mixed>
 */
function snt_os_host_replay_shared( array $values, $action, array $get, $page_slug ) {
	$nonce = snt_os_host_last( isset( $values['_wpnonce'] ) ? $values['_wpnonce'] : null );
	if ( ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, SNT_OS_HOST_NONCE ) ) {
		return snt_os_host_result(
			array(
				'reason'   => 'nonce',
				'detail'   => SNT_OS_HOST_NONCE,
				'pipeline' => 'shared',
			)
		);
	}
	if ( function_exists( 'sn_admin_post_allowed_pages' ) && ! in_array( $page_slug, sn_admin_post_allowed_pages(), true ) ) {
		return snt_os_host_result(
			array(
				'reason'   => 'page',
				'detail'   => $page_slug,
				'pipeline' => 'shared',
			)
		);
	}
	$handlers = function_exists( 'sn_admin_post_handlers' ) ? sn_admin_post_handlers() : array();

	$flash  = '';
	$target = null;
	// Lent, NOT intercepted: a shared handler returns a flash code and never
	// redirects, so wrapping it would only give a `wp_die()` somewhere inside
	// one a quiet place to disappear into.
	snt_os_host_lend(
		$values,
		$get,
		$page_slug,
		static function () use ( $handlers, $action, &$flash, &$target ) {
			// phpcs:ignore WordPress.Security.NonceVerification -- The lent superglobals; the shared nonce was verified above.
			$flash = (string) call_user_func( $handlers[ $action ], $_POST );

			// The classic dispatcher only resolves a target when the request
			// carried a tab; without one it redirects to the bare page.
			// Mirrored, not re-decided.
			// phpcs:ignore WordPress.Security.NonceVerification -- Same lent request.
			if ( isset( $_REQUEST['tab'] ) && function_exists( 'sn_admin_post_redirect_target' ) ) {
				// phpcs:disable WordPress.Security.NonceVerification -- Same lent request.
				$requested_tab = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
				$requested_sub = isset( $_REQUEST['sub'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sub'] ) ) : '';
				// phpcs:enable WordPress.Security.NonceVerification
				$target = sn_admin_post_redirect_target( $requested_tab, $requested_sub );
			}
		}
	);

	return snt_os_host_result(
		array(
			'ok'       => true,
			'flash'    => $flash,
			'target'   => $target,
			'pipeline' => 'shared',
		)
	);
}

/**
 * The `admin-post.php` pipeline: the form's own action hook and its own nonce.
 *
 * Nothing here re-implements a handler's nonce check — each one calls
 * `check_admin_referer()` against ITS action, and that call dying is a fact
 * this window reports rather than pre-empts. What is checked first is that
 * something is actually listening: `do_action()` on an unhooked name is a
 * silent no-op, and "nothing happened" must never paint as a save.
 *
 * @param array<string,mixed> $values    Expanded values.
 * @param string              $action    The `action` field.
 * @param array<string,mixed> $get       The window's query.
 * @param string              $page_slug The window's page slug.
 * @return array<string,mixed>
 */
function snt_os_host_replay_admin_post( array $values, $action, array $get, $page_slug ) {
	$hook = 'admin_post_' . $action;
	if ( ! function_exists( 'do_action' ) || ! function_exists( 'has_action' ) || ! has_action( $hook ) ) {
		return snt_os_host_result(
			array(
				'reason'   => 'unknown',
				'detail'   => $action,
				'pipeline' => 'admin-post',
			)
		);
	}
	$outcome = snt_os_host_borrowed(
		$values,
		$get,
		$page_slug,
		static function () use ( $hook ) {
			do_action( $hook );
		}
	);
	return snt_os_host_outcome_result( $outcome, 'admin-post' );
}

/**
 * The RSS pipeline: `sn_rss_action` + `SN_RSS_TRACKER_NONCE`, handled by
 * `sn_rss_tracker_handle_form()` on `admin_init`.
 *
 * The function verifies its own nonce and SILENTLY returns on a stale one (its
 * own recorded decision: a bare `check_admin_referer()` would wall-of-text the
 * reader). A silent return is therefore reported as exactly that — a run with
 * nothing to say — never as a save.
 *
 * @param array<string,mixed> $values    Expanded values.
 * @param string              $action    The `sn_rss_action` slug (for the refusal detail).
 * @param array<string,mixed> $get       The window's query.
 * @param string              $page_slug The window's page slug.
 * @return array<string,mixed>
 */
function snt_os_host_replay_rss( array $values, $action, array $get, $page_slug ) {
	if ( ! function_exists( 'sn_rss_tracker_handle_form' ) ) {
		return snt_os_host_result(
			array(
				'reason'   => 'unknown',
				'detail'   => $action,
				'pipeline' => 'rss',
			)
		);
	}
	$outcome = snt_os_host_borrowed( $values, $get, $page_slug, 'sn_rss_tracker_handle_form' );
	return snt_os_host_outcome_result( $outcome, 'rss' );
}

/**
 * The inline pipeline: a form its own leaf handles, out of `$_POST`.
 *
 * Nothing runs here on purpose. The classic page does not redirect for one of
 * these — `sn_handle_admin_post()` returns at the handler lookup and the page
 * renders with `$_POST` still standing, which is how `audit_prune_now` prunes
 * and prints its counts from inside `snt_audit_log_render_tab()`. So the
 * window keeps the values for exactly one paint and lends them to the capture.
 *
 * @param array<string,mixed> $values    Expanded values.
 * @param string              $action    The `sn_action` the leaf will look for.
 * @param string              $page_slug The window's page slug.
 * @return array<string,mixed>
 */
function snt_os_host_replay_inline( array $values, $action, $page_slug ) {
	if ( function_exists( 'sn_admin_post_allowed_pages' ) && ! in_array( (string) $page_slug, sn_admin_post_allowed_pages(), true ) ) {
		return snt_os_host_result(
			array(
				'reason'   => 'page',
				'detail'   => (string) $page_slug,
				'pipeline' => 'inline',
			)
		);
	}
	return snt_os_host_result(
		array(
			'ok'       => true,
			'detail'   => $action,
			'pipeline' => 'inline',
			// Slashed for the same reason the shared pipeline slashes: a leaf
			// reading $_POST wp_unslash()es it, and a real POST arrives slashed.
			'post'     => function_exists( 'wp_slash' ) ? wp_slash( $values ) : $values,
		)
	);
}

/**
 * Run a callable with the request the classic page would have had, and give
 * the request back.
 *
 * `$_REQUEST` is `$get` under `$_POST` plus the window's page — the shape
 * `sn_handle_admin_post()` reads (`$_REQUEST['page']`, `['tab']`) and the shape
 * `check_admin_referer()` reads its nonce out of. The restore is in `finally`:
 * a handler that throws must not leave the rest of the dispatch wearing a
 * form's `$_POST`.
 *
 * @param array<string,mixed> $values    Expanded values.
 * @param array<string,mixed> $get       The window's query.
 * @param string              $page_slug The window's page slug.
 * @param callable            $run       What to run under them.
 * @return mixed Whatever `$run` returned.
 */
function snt_os_host_lend( array $values, array $get, $page_slug, callable $run ) {
	// phpcs:disable WordPress.Security.NonceVerification -- Snapshotting the superglobals to restore them; each pipeline's own gate ran above.
	$prev_get     = $_GET;
	$prev_post    = $_POST;
	$prev_request = $_REQUEST;
	// phpcs:enable WordPress.Security.NonceVerification
	try {
		$_GET     = $get;
		$_POST    = function_exists( 'wp_slash' ) ? wp_slash( $values ) : $values;
		$_REQUEST = array_merge( $get, $_POST, array( 'page' => (string) $page_slug ) );
		return call_user_func( $run );
	} finally {
		$_GET     = $prev_get;
		$_POST    = $prev_post;
		$_REQUEST = $prev_request;
	}
}

/**
 * `snt_os_host_lend()` with the redirect/die interceptor around the run — what
 * the two pipelines whose handlers END in a redirect need.
 *
 * @param array<string,mixed> $values    Expanded values.
 * @param array<string,mixed> $get       The window's query.
 * @param string              $page_slug The window's page slug.
 * @param callable            $run       What to run under them.
 * @return array{outcome:string,location:string,message:string}
 */
function snt_os_host_borrowed( array $values, array $get, $page_slug, callable $run ) {
	return (array) snt_os_host_lend(
		$values,
		$get,
		$page_slug,
		static function () use ( $run ) {
			return snt_os_host_intercept( $run );
		}
	);
}

/**
 * Run a handler with `wp_redirect()` and `wp_die()` turned into values.
 *
 * Both filters are added at `PHP_INT_MAX` so every other listener has already
 * had the location (or the die handler) — what is caught is the FINAL one, the
 * same string the browser would have been sent. Every `wp_die` handler variant
 * is filtered, not just the default: a window's dispatch is a REST request and
 * `wp_die()` picks the JSON handler for one, so filtering only `wp_die_handler`
 * would have caught a die on the classic page and missed it in the window.
 *
 * @param callable $run The handler.
 * @return array{outcome:string,location:string,message:string} outcome: redirect|died|returned.
 */
function snt_os_host_intercept( callable $run ) {
	if ( ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) ) {
		call_user_func( $run );
		return array(
			'outcome'  => 'returned',
			'location' => '',
			'message'  => '',
		);
	}

	// Both messages are DATA, never output: each signal is caught below, in this
	// same function, and its message becomes a value in the result array. The
	// escaping sniff assumes an exception message reaches a reader as HTML; the
	// notice that eventually paints one is escaped where it paints.
	$catch_redirect = static function ( $location ) {
		throw new SNT_OS_Host_Redirect_Signal( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught below; the location is parsed, never echoed.
	};
	$catch_die = static function () {
		return static function ( $message = '', $title = '', $args = array() ) {
			unset( $args );
			throw new SNT_OS_Host_Die_Signal( snt_os_host_die_text( $message, $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught below; the text is already stripped of tags and is escaped by the view that paints it.
		};
	};
	$die_filters = array( 'wp_die_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_ajax_handler', 'wp_die_xml_handler', 'wp_die_xmlrpc_handler' );

	add_filter( 'wp_redirect', $catch_redirect, PHP_INT_MAX );
	foreach ( $die_filters as $filter ) {
		add_filter( $filter, $catch_die, PHP_INT_MAX );
	}
	try {
		call_user_func( $run );
		return array(
			'outcome'  => 'returned',
			'location' => '',
			'message'  => '',
		);
	} catch ( SNT_OS_Host_Redirect_Signal $signal ) {
		return array(
			'outcome'  => 'redirect',
			'location' => $signal->getMessage(),
			'message'  => '',
		);
	} catch ( SNT_OS_Host_Die_Signal $signal ) {
		return array(
			'outcome'  => 'died',
			'location' => '',
			'message'  => $signal->getMessage(),
		);
	} finally {
		remove_filter( 'wp_redirect', $catch_redirect, PHP_INT_MAX );
		foreach ( $die_filters as $filter ) {
			remove_filter( $filter, $catch_die, PHP_INT_MAX );
		}
	}
}

/**
 * A `wp_die()` argument as one line of readable text.
 *
 * The message is the refusal the reader is owed; the title only stands in when
 * the caller gave no message at all, so the window never invents a cause.
 *
 * @param string|\WP_Error $message wp_die's first argument.
 * @param string           $title   wp_die's second argument.
 * @return string
 */
function snt_os_host_die_text( $message, $title = '' ) {
	if ( is_object( $message ) && method_exists( $message, 'get_error_message' ) ) {
		$message = $message->get_error_message();
	}
	$strip = static function ( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		// strip_tags() only where WordPress is absent (a standalone host, a
		// suite): wp_strip_all_tags() is the same call plus script/style
		// removal, and pretending it exists would be the wrong-guess class.
		$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	};
	$text = $strip( $message );
	return '' !== $text ? $text : $strip( $title );
}

/**
 * A caught redirect/die/return, as the result shape.
 *
 * @param array{outcome:string,location:string,message:string} $outcome  From `snt_os_host_intercept()`.
 * @param string                                               $pipeline Which pipeline ran.
 * @return array<string,mixed>
 */
function snt_os_host_outcome_result( array $outcome, $pipeline ) {
	if ( 'died' === $outcome['outcome'] ) {
		return snt_os_host_result(
			array(
				'reason'   => 'died',
				'detail'   => (string) $outcome['message'],
				'pipeline' => $pipeline,
			)
		);
	}
	if ( 'redirect' === $outcome['outcome'] ) {
		$landing = snt_os_host_landing( (string) $outcome['location'] );
		return snt_os_host_result(
			array(
				'ok'       => true,
				'flash'    => $landing['flash'],
				'target'   => $landing['target'],
				'params'   => $landing['params'],
				'pipeline' => $pipeline,
			)
		);
	}
	// The handler returned. It wrote whatever it wrote and said nothing; a
	// notice invented here would be a readout of something never measured.
	return snt_os_host_result(
		array(
			'ok'       => true,
			'pipeline' => $pipeline,
		)
	);
}

/**
 * What a redirect the window swallowed was going to show.
 *
 * The classic page reads its own post-save state out of that URL — `sn_flash`
 * for the notice, `tab`/`sub` for where it lands, and every other `sn_*` param
 * for the leaf itself (`sn_prov_swept`, `sn_prov_rotate`, `sn_rss_ok`). So the
 * window reads the same query and puts it in state.
 *
 * @param string $location The Location the handler asked for.
 * @return array{flash:string,target:?array,params:array<string,mixed>}
 */
function snt_os_host_landing( $location ) {
	$query = array();
	$raw   = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $location, PHP_URL_QUERY ) : parse_url( (string) $location, PHP_URL_QUERY );
	parse_str( (string) $raw, $query );

	$flash = isset( $query['sn_flash'] ) && is_scalar( $query['sn_flash'] ) ? (string) $query['sn_flash'] : '';
	// Carried as `flash`; leaving it in the params too would lend the leaf the
	// same fact twice and outlive the one paint a notice is good for.
	unset( $query['sn_flash'] );

	$target = null;
	if ( isset( $query['tab'] ) && is_scalar( $query['tab'] ) && function_exists( 'sn_admin_post_redirect_target' ) ) {
		$target = sn_admin_post_redirect_target(
			sanitize_text_field( (string) $query['tab'] ),
			isset( $query['sub'] ) && is_scalar( $query['sub'] ) ? sanitize_text_field( (string) $query['sub'] ) : ''
		);
	}

	return array(
		'flash'  => $flash,
		'target' => $target,
		'params' => snt_os_host_params( $query ),
	);
}
