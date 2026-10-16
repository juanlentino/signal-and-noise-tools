<?php
/**
 * wp_get_admin_notice() / wp_admin_notice() as core 7.1 has them
 * (wp-includes/functions.php:9196 and :9307), for suites that render a
 * classic notice standalone. The markup is core's byte for byte: `notice`,
 * `notice-<type>`, `is-dismissible`, then `additional_classes` in order, one
 * `<p>` wrap unless `paragraph_wrap` is false, attributes esc_attr'd. Two
 * departures, both named: wp_parse_args() is array_merge() here (the same
 * thing for an array, which is all the plugin passes), and where core
 * _doing_it_wrong()s a `type` with a space this throws, so a suite goes red
 * on the line production would warn on rather than painting a class list
 * core refuses.
 *
 * The echo runs core's own wp_kses_post() when a WordPress checkout is on
 * this machine (the SNT_WP_HTML_API seam the host suites use, or a normal
 * plugin install; CI fetches wp-includes/kses.php beside the HTML API) and a
 * pass-through otherwise. SNT_KSES_IS_CORE says which, so a suite whose
 * claim IS the kses pass can refuse the pass-through where it matters. A
 * suite that defines wp_kses_post() before requiring this keeps its own.
 *
 * Require it AFTER the suite's own hook and escaping stubs; every dependency
 * here is function_exists-guarded to a pass-through.
 *
 * @since 17.4.4
 */
if ( ! function_exists( 'wp_kses_post' ) ) {
	require_once __DIR__ . '/wp-core-kses.php';
	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $s ) { return (string) $s; }
	}
}
if ( ! defined( 'SNT_KSES_IS_CORE' ) ) {
	define( 'SNT_KSES_IS_CORE', false ); // The suite brought its own wp_kses_post().
}
if ( ! function_exists( 'wp_get_admin_notice' ) ) {
	function wp_get_admin_notice( $message, $args = array() ) {
		$args = array_merge(
			array(
				'type'               => '',
				'dismissible'        => false,
				'id'                 => '',
				'additional_classes' => array(),
				'attributes'         => array(),
				'paragraph_wrap'     => true,
			),
			(array) $args
		);
		if ( function_exists( 'apply_filters' ) ) {
			$args = apply_filters( 'wp_admin_notice_args', $args, $message );
		}
		$id         = '';
		$classes    = 'notice';
		$attributes = '';
		if ( is_string( $args['id'] ) && '' !== trim( $args['id'] ) ) {
			$id = 'id="' . trim( $args['id'] ) . '" ';
		}
		if ( is_string( $args['type'] ) ) {
			$type = trim( $args['type'] );
			if ( str_contains( $type, ' ' ) ) {
				// Core: _doing_it_wrong( __FUNCTION__, 'The type key must be a string without spaces.', '6.4.0' ).
				throw new RuntimeException( 'wp_get_admin_notice: the type key must be a string without spaces, got "' . $type . '"' );
			}
			if ( '' !== $type ) {
				$classes .= ' notice-' . $type;
			}
		}
		if ( true === $args['dismissible'] ) {
			$classes .= ' is-dismissible';
		}
		if ( is_array( $args['additional_classes'] ) && ! empty( $args['additional_classes'] ) ) {
			$classes .= ' ' . implode( ' ', $args['additional_classes'] );
		}
		if ( is_array( $args['attributes'] ) && ! empty( $args['attributes'] ) ) {
			foreach ( $args['attributes'] as $attr => $val ) {
				$esc = function_exists( 'esc_attr' ) ? esc_attr( trim( (string) $val ) ) : htmlspecialchars( trim( (string) $val ), ENT_QUOTES );
				if ( is_bool( $val ) ) {
					$attributes .= $val ? ' ' . $attr : '';
				} elseif ( is_int( $attr ) ) {
					$attributes .= ' ' . $esc;
				} elseif ( $val ) {
					$attributes .= ' ' . $attr . '="' . $esc . '"';
				}
			}
		}
		if ( false !== $args['paragraph_wrap'] ) {
			$message = "<p>$message</p>";
		}
		$markup = sprintf( '<div %1$sclass="%2$s"%3$s>%4$s</div>', $id, $classes, $attributes, $message );
		if ( function_exists( 'apply_filters' ) ) {
			$markup = apply_filters( 'wp_admin_notice_markup', $markup, $message, $args );
		}
		return $markup;
	}
}
if ( ! function_exists( 'wp_admin_notice' ) ) {
	function wp_admin_notice( $message, $args = array() ) {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wp_admin_notice', $message, $args );
		}
		echo wp_kses_post( wp_get_admin_notice( $message, $args ) );
	}
}
