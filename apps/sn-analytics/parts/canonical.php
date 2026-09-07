<?php
/**
 * Canonical Analytics reports inside the native window.
 *
 * @package SignalNoiseTools
 */
namespace SignalNoise\OpenStationHost\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Reuse report renderers; the shell owns navigation, not metric definitions.
 *
 * @param string $key Piece name.
 * @param array  $ctx Resolved frame context.
 * @return array|null Null lets partial hosts use their registered painter.
 */
function canonical_piece( $key, array $ctx ) {
	$paint = null;
	$facts = array();
	if ( 0 === strpos( $key, 'view/' ) && function_exists( 'snt_analytics_render_view_body' ) ) {
		$paint = static function () use ( $ctx ) {
			\snt_analytics_render_view_body( $ctx['view'], $ctx['from'], $ctx['to'], $ctx['class'], $ctx['granularity'], $ctx['range'], $ctx['compare'] );
		};
	} elseif ( 'chrome/insights' === $key && function_exists( 'snt_analytics_render_insights_band' ) ) {
		$paint = static function () use ( $ctx ) {
			\snt_analytics_render_insights_band( $ctx['from'], $ctx['to'], $ctx['class'], $ctx['granularity'] );
		};
	} elseif ( 'chrome/header' === $key && function_exists( 'snt_analytics_render_header_region' ) ) {
		$paint = static function () use ( $ctx, &$facts ) {
			$facts['totals'] = \snt_analytics_render_header_region( $ctx['view'], $ctx['range'], $ctx['class'], $ctx['from'], $ctx['to'], $ctx['granularity'], $ctx['compare'], false );
		};
	} elseif ( 'chrome/login-header' === $key && function_exists( 'sn_login_defense_render_header' ) ) {
		$paint = 'sn_login_defense_render_header';
	}
	if ( null === $paint ) {
		return null;
	}
	$html = capture( $ctx['get'], $paint );
	$html = \snt_os_host_keep_forms( $html, \snt_os_analytics_keep_actions(), function_exists( 'snt_analytics_page_url' ) ? \snt_analytics_page_url() : '' );
	$html = \snt_os_host_rewrite( $html, array( page_slug() ) );
	// Report doorways cross native tab sessions; filters stay on this tab.
	if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'A' ) ) {
			$view = $tags->get_attribute( 'os-arg-sn_view' );
			if ( 'go' !== $tags->get_attribute( 'os-action' ) || ! is_string( $view ) || $view === $ctx['view'] ) {
				continue;
			}
			$args = array();
			foreach ( (array) $tags->get_attribute_names_with_prefix( 'os-arg-' ) as $name ) {
				$args[ substr( $name, 7 ) ] = $tags->get_attribute( $name );
			}
			$tags->add_class( 'snt-go' );
			$tags->set_attribute( 'data-snt-tab', $view );
			$tags->set_attribute( 'data-snt-args', wp_json_encode( $args ) );
			$tags->remove_attribute( 'os-action' );
		}
		$html = $tags->get_updated_html();
	}
	return array( 'html' => $html, 'facts' => $facts );
}
