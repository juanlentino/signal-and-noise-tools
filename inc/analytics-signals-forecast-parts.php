<?php
/**
 * Signal & Noise Tools: analytics-signals.php, the forecast builder, the day
 * alignment and the forecasts bundle.
 *
 * Split from inc/analytics-signals.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compose one subject's daily-views series into a forecast Signal, or null when
 * suppressed. Both models are backtested on the series; the median is used
 * unless Holt beats it by SN_ANALYTICS_FORECAST_HOLT_MARGIN (see
 * sn_analytics_forecast_point()). Honesty gates: below
 * SN_ANALYTICS_FORECAST_MIN_POINTS → null; zero median level → null; the
 * chosen model's skill <= 0 → forecast_withheld with its score. The 80% band
 * is the 10th to 90th percentile of the chosen model's past misses, and
 * confidence is how often that band held out of sample ('low', and said so,
 * while unmeasured). Bounds clamp at 0. Only a Holt forecast carries a
 * `direction`: a median states a level, not a trend.
 * @return array|null Signal
 */
function sn_analytics_forecast_of( $subject, $label, $series, $from, $to, $opts = array() ) {
	$min     = max( 4, (int) ( $opts['min_points'] ?? SN_ANALYTICS_FORECAST_MIN_POINTS ) );
	$horizon = max( 1, (int) ( $opts['horizon'] ?? SN_ANALYTICS_FORECAST_HORIZON ) );
	$ys      = array_map( static function ( $r ) { return (float) ( $r['views'] ?? 0 ); }, (array) $series );
	if ( count( $ys ) < $min ) { return null; }
	$level = sn_analytics_stat_median( $ys );
	if ( null === $level || $level <= 0 ) { return null; }
	$train  = min( SN_ANALYTICS_FORECAST_WINDOW, max( 3, (int) floor( count( $ys ) / 2 ) ) );
	$by_med = sn_analytics_forecast_backtest( $ys, $horizon, $train, 'median' );
	if ( null === $by_med ) { return null; }
	$by_hol = sn_analytics_forecast_backtest( $ys, $horizon, $train, 'holt' );
	// Holt only on a clear, measured win: both skills defined, Holt ahead by the margin.
	$holt     = is_array( $by_hol ) && null !== $by_hol['skill'] && null !== $by_med['skill']
		&& $by_hol['skill'] > $by_med['skill'] + SN_ANALYTICS_FORECAST_HOLT_MARGIN;
	$model    = $holt ? 'holt' : 'median';
	$backtest = $holt ? $by_hol : $by_med;
	$stat     = $holt ? 'holt_linear' : 'rolling_median';
	$named    = $holt ? 'Holt trend' : sprintf( '%d-day median', SN_ANALYTICS_FORECAST_WINDOW );
	// SKILL GATE (v13.75.0). Suppress WITH THE REASON rather than returning
	// null: a bare null renders as absence, and absence is indistinguishable
	// from "no data yet". null skill is NOT a failure: persistence was perfect,
	// so the comparison is undefined, and a rigid series stays forecastable.
	$skill = $backtest['skill'] ?? null;
	if ( null !== $skill && $skill <= 0.0 ) {
		return array(
			'id'            => 'forecast-withheld:' . $subject . ':' . (string) $to . '+' . $horizon . 'd',
			'tier'          => 'predictive',
			'kind'          => 'forecast_withheld',
			'subject'       => $subject,
			'subject_label' => (string) $label,
			'stat'          => $stat,
			'value'         => null,
			'confidence'    => 'none',
			'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $ys ) ),
			// Both scores, as measured: Holt can beat the baseline and still not
			// clear the lead it needs over the median, so "neither" would be false.
			'plain_label'   => null !== ( $by_hol['skill'] ?? null )
				? sprintf(
					'%s: no forecast: against a same-value baseline the %d-day median scores %.2f and a Holt trend %.2f over %d checks; a forecast needs a score above 0, and Holt a lead of %.1f over the median',
					(string) $label, SN_ANALYTICS_FORECAST_WINDOW, (float) $by_med['skill'], (float) $by_hol['skill'], (int) $backtest['checks'], SN_ANALYTICS_FORECAST_HOLT_MARGIN
				)
				: sprintf(
					'%s: no forecast: the %d-day median does not beat a same-value baseline on this history (skill %.2f over %d checks)',
					(string) $label, SN_ANALYTICS_FORECAST_WINDOW, (float) $skill, (int) $backtest['checks']
				),
			'severity'      => 0,
		);
	}
	$point = (float) sn_analytics_forecast_point( $ys, $horizon, $model );
	$lo    = (float) sn_analytics_stat_quantile( $backtest['errors'], SN_ANALYTICS_FORECAST_BAND_Q );
	$hi    = (float) sn_analytics_stat_quantile( $backtest['errors'], 1 - SN_ANALYTICS_FORECAST_BAND_Q );
	$shown = max( 0.0, $point );
	$low   = max( 0.0, $shown + $lo );
	$high  = max( 0.0, $shown + $hi );
	$cov     = $backtest['coverage'];
	$nominal = 1 - 2 * SN_ANALYTICS_FORECAST_BAND_Q;
	$conf    = sn_analytics_forecast_confidence( $cov, (int) $backtest['band_checks'] );
	// Plain English, as every engine label is (the narrator and MCP read them).
	$held    = null === $cov ? 'band not yet measured on this history' : sprintf( 'held on %d%% of %d past checks', (int) round( $cov * 100 ), (int) $backtest['band_checks'] );
	$signal = array(
		'id'            => 'forecast:' . $subject . ':' . (string) $to . '+' . $horizon . 'd',
		'tier'          => 'predictive',
		'kind'          => 'forecast',
		'subject'       => $subject,
		'subject_label' => (string) $label,
		'stat'          => $stat,
		'value'         => round( $shown, 1 ),
		'interval'      => array( 'low' => round( $low, 1 ), 'high' => round( $high, 1 ) ),
		'confidence'    => $conf,
		'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $ys ) ),
		'plain_label'   => sprintf(
			'%s: next %d days about %.1f/day (%s; %d%% band %.1f to %.1f; %s)',
			(string) $label, $horizon, $shown, $named, (int) round( $nominal * 100 ), $low, $high, $held
		),
		'severity'      => 1,
	);
	if ( $holt ) {
		// A trend the backtest chose over the median says which way it runs.
		$fit  = sn_analytics_stat_holt( $ys );
		$move = $horizon * (float) $fit['trend'];
		$signal['direction'] = ( abs( $move / $level ) < 0.05 ) ? 'flat' : ( $move > 0 ? 'up' : 'down' );
		$signal['severity']  = 'down' === $signal['direction'] ? 2 : 1;
	}
	return $signal;
}

/**
 * A subject's daily series over the site series' own days (2026-10-12 audit).
 *
 * A path or campaign series holds only the days it had views, so its
 * "14-day median" was the median of its last 14 days WITH views: a path read
 * every other day forecast twice its real level. Every day the site series
 * has becomes a row, 0 where the subject had none. A day the site series
 * lacks (a rollup gap, a failed read) is not a 0 and stays out; with no site
 * days at all the subject's own rows are used unchanged.
 *
 * @param array<int,array{day?:string,views?:int|float}> $series The subject's rows.
 * @param string[]                                       $days   The site series' days.
 * @return array<int,array{day:string,views:float}>
 */
function sn_analytics_series_on_days( array $series, array $days ) {
	if ( array() === $days ) {
		return $series;
	}
	$by = array();
	foreach ( $series as $r ) {
		$by[ (string) ( $r['day'] ?? '' ) ] = (float) ( $r['views'] ?? 0 );
	}
	$out = array();
	foreach ( $days as $day ) {
		$out[] = array( 'day' => $day, 'views' => $by[ $day ] ?? 0.0 );
	}
	return $out;
}

/**
 * Forecast signals for the window: site views+visits (trailing fit history ending
 * $to, decoupled from the display range), top campaigns, and the lifecycle
 * census's refresh candidates (spec §8 reuse — the census picks the subjects, the
 * existing per-path series supplies the data). Engagement metrics are deferred:
 * no per-day engagement series accessor exists yet, and the engine adds no new
 * queries. Every dependency is function_exists-guarded.
 * @return array Signal[]
 */
function sn_analytics_signal_forecasts( $from, $to, $class = 'human', $opts = array() ) {
	$out       = array();
	$history   = max( 1, (int) ( $opts['history_days'] ?? SN_ANALYTICS_FORECAST_HISTORY_DAYS ) );
	$hist_from = gmdate( 'Y-m-d', strtotime( (string) $to . ' -' . ( $history - 1 ) . ' days' ) );
	$site_days = array();
	if ( function_exists( 'sn_analytics_daily_series' ) ) {
		$series    = sn_analytics_daily_series( $hist_from, (string) $to, $class, 'day' );
		$site_days = array_values( array_filter( array_map( static function ( $r ) { return (string) ( $r['day'] ?? '' ); }, (array) $series ) ) );
		foreach ( array( 'views', 'visits' ) as $metric ) {
			$ms  = array_map( static function ( $r ) use ( $metric ) { return array( 'views' => (float) ( $r[ $metric ] ?? 0 ) ); }, (array) $series );
			$sig = sn_analytics_forecast_of( $metric, ucfirst( $metric ), $ms, $hist_from, $to, $opts );
			if ( $sig ) { $out[] = $sig; }
		}
	}
	if ( function_exists( 'sn_analytics_top_utm_campaigns' ) && function_exists( 'sn_analytics_utm_series' ) ) {
		$camps  = sn_analytics_top_utm_campaigns( (string) $from, (string) $to, $class, (int) ( $opts['campaigns'] ?? 3 ) );
		$values = array_map( static function ( $r ) { return (string) $r['value']; }, (array) $camps );
		$cser   = sn_analytics_utm_series( 'campaign', $values, $hist_from, (string) $to, $class, 'day' );
		foreach ( $values as $v ) {
			$sig = sn_analytics_forecast_of( 'campaign:' . $v, $v, sn_analytics_series_on_days( (array) ( $cser[ $v ] ?? array() ), $site_days ), $hist_from, $to, $opts );
			if ( $sig ) { $out[] = $sig; }
		}
	}
	if ( function_exists( 'sn_analytics_posts_lifecycle' ) && function_exists( 'sn_analytics_path_daily_series' ) && function_exists( 'wp_parse_url' ) ) {
		$bundle = sn_analytics_posts_lifecycle();
		$rows   = is_array( $bundle ) ? (array) ( $bundle['rows'] ?? array() ) : array();
		$done   = 0;
		$cap    = (int) ( $opts['decay_paths'] ?? 3 );
		foreach ( $rows as $r ) {
			if ( empty( $r['refresh_candidate'] ) ) { continue; }
			$path = (string) wp_parse_url( (string) ( $r['permalink'] ?? '' ), PHP_URL_PATH );
			if ( '' === $path ) { continue; }
			$sig = sn_analytics_forecast_of( 'path:' . $path, $path, sn_analytics_series_on_days( (array) sn_analytics_path_daily_series( $path, $hist_from, (string) $to ), $site_days ), $hist_from, $to, $opts );
			if ( $sig ) {
				$out[] = $sig;
				if ( ++$done >= $cap ) { break; }
			}
		}
	}
	return $out;
}
