<?php
/**
 * Signal & Noise Tools: model prices, read daily (21.0.0).
 *
 * Neither vendor's API returns a price, so a list that updates itself needs
 * a machine-readable price source. This reads LiteLLM's public price file (a
 * community-maintained JSON keyed by the vendors' own model ids, checked
 * against both vendors' pricing pages on 2026-10-03: every row compared
 * agreed) once a day, in the same cron as the model lists, and keeps only
 * Anthropic and Gemini chat models.
 *
 * It is a third party's file, so nothing in it is trusted on sight: an id
 * must be well-formed, a price must be a positive number under a ceiling, a
 * read with too few rows is discarded, and a price that moved more than four
 * times against the stored one is HELD (the old price stays and the held id
 * is named on the settings screen) because the monthly budget cap pauses AI
 * features on this estimate. The table in inc/ai-bootstrap/pricing.php is
 * the seed and the fallback.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AI_PRICES_OPT   = 'sn_ai_model_prices'; // { fetched, prices: id => {in,out}, held: id[], error }.
const SN_AI_PRICES_URL   = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';
const SN_AI_PRICES_STALE = 14 * 86400;           // older than this, the code table alone is used.
const SN_AI_PRICES_MIN   = 5;                    // fewer rows than this is not a price list.
const SN_AI_PRICES_CEIL  = 500.0;                // USD per million tokens; above it is a typo, not a price.
const SN_AI_PRICES_JUMP  = 4.0;                  // a change beyond this factor is held, not applied.

/**
 * From the price file, the rows this site can use. PURE.
 *
 * @param mixed $doc The decoded file: id => { litellm_provider, mode, input_cost_per_token, output_cost_per_token }.
 * @return array<string,array{in:float,out:float}> USD per million tokens.
 */
function sn_ai_prices_parse( $doc ) {
	$out = array();
	foreach ( (array) $doc as $key => $row ) {
		if ( ! is_array( $row ) || 'chat' !== ( $row['mode'] ?? '' ) || ! in_array( $row['litellm_provider'] ?? '', array( 'anthropic', 'gemini' ), true ) ) {
			continue;
		}
		// A model priced in tiers by prompt length (Gemini 2.5 Pro: double above
		// 200k tokens) has no single honest rate. It stays unpriced, as the
		// table always left it, and its calls are counted as unpriced.
		if ( isset( $row['input_cost_per_token_above_200k_tokens'] ) || isset( $row['output_cost_per_token_above_200k_tokens'] ) ) {
			continue;
		}
		$id  = 'gemini' === $row['litellm_provider'] ? (string) preg_replace( '#^gemini/#', '', (string) $key ) : (string) $key;
		$in  = is_numeric( $row['input_cost_per_token'] ?? null ) ? round( (float) $row['input_cost_per_token'] * 1e6, 4 ) : 0.0;
		$ot  = is_numeric( $row['output_cost_per_token'] ?? null ) ? round( (float) $row['output_cost_per_token'] * 1e6, 4 ) : 0.0;
		$ok  = 1 === preg_match( '/^[a-z0-9][a-z0-9.\-]{2,63}$/', $id );
		if ( $ok && $in > 0 && $ot > 0 && $in <= SN_AI_PRICES_CEIL && $ot <= SN_AI_PRICES_CEIL ) {
			$out[ $id ] = array( 'in' => $in, 'out' => $ot );
		}
	}
	return $out;
}

/**
 * Fold a new read into the known prices. PURE. A price that moved beyond the
 * jump factor in either direction keeps its old value and is named in `held`.
 *
 * @param array<string,array{in:float,out:float}> $known What is trusted now (the code table, then earlier reads).
 * @param array<string,array{in:float,out:float}> $read  The new read.
 * @return array{prices:array<string,array{in:float,out:float}>,held:string[]}
 */
function sn_ai_prices_merge( array $known, array $read ) {
	$held = array();
	foreach ( $read as $id => $p ) {
		$old = $known[ $id ] ?? null;
		if ( is_array( $old ) ) {
			foreach ( array( 'in', 'out' ) as $k ) {
				$ratio = (float) $old[ $k ] > 0 ? (float) $p[ $k ] / (float) $old[ $k ] : 1.0;
				if ( $ratio > SN_AI_PRICES_JUMP || $ratio < 1 / SN_AI_PRICES_JUMP ) {
					$held[] = (string) $id;
					continue 2;
				}
			}
		}
		$known[ $id ] = $p;
	}
	return array( 'prices' => $known, 'held' => $held );
}

/**
 * The daily read. A failed or implausible read changes nothing but the error.
 *
 * @param int|null   $now  Unix time; null reads the clock.
 * @param array|null $seed The code table (inc/ai-bootstrap/pricing.php) to compare a first read against.
 * @return array The stored state.
 */
function sn_ai_prices_refresh( $now = null, $seed = null ) {
	$now   = null === $now ? time() : (int) $now;
	$state = get_option( SN_AI_PRICES_OPT, array() );
	$state = array( 'fetched' => (int) ( $state['fetched'] ?? 0 ), 'prices' => (array) ( $state['prices'] ?? array() ), 'held' => array(), 'error' => '' );
	$res   = wp_remote_get( SN_AI_PRICES_URL, array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'signal-and-noise-tools' ) ) );
	$read  = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? array() : sn_ai_prices_parse( json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
	if ( count( $read ) < SN_AI_PRICES_MIN ) {
		$state['error'] = 'the price file could not be read, or held too few usable rows';
	} else {
		$merged           = sn_ai_prices_merge( $state['prices'] + (array) $seed, $read );
		$state['prices']  = array_intersect_key( $merged['prices'], $read ); // only what the file prices; the code table stays in code.
		$state['held']    = $merged['held'];
		$state['fetched'] = $now;
	}
	update_option( SN_AI_PRICES_OPT, $state, false );
	return $state;
}

/**
 * The read prices while they are fresh, else array().
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return array<string,array{in:float,out:float}>
 */
function sn_ai_prices_read( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$state = function_exists( 'get_option' ) ? get_option( SN_AI_PRICES_OPT, array() ) : array();
	return is_array( $state ) && (int) ( $state['fetched'] ?? 0 ) >= $now - SN_AI_PRICES_STALE ? (array) ( $state['prices'] ?? array() ) : array();
}

/**
 * One line for the settings screens.
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return string
 */
function sn_ai_prices_status_line( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$state = function_exists( 'get_option' ) ? get_option( SN_AI_PRICES_OPT, array() ) : array();
	$at    = is_array( $state ) ? (int) ( $state['fetched'] ?? 0 ) : 0;
	if ( $at < $now - SN_AI_PRICES_STALE ) {
		return 'Prices: the built-in table (the public price list has not been read' . ( ! empty( $state['error'] ) ? ': ' . (string) $state['error'] : ' yet' ) . '). Read daily.';
	}
	$held = array_filter( (array) ( $state['held'] ?? array() ), 'is_string' );
	return sprintf( 'Prices: read %s UTC from LiteLLM\'s public price list (%d models), refreshed daily.', gmdate( 'Y-m-d H:i', $at ), count( (array) ( $state['prices'] ?? array() ) ) )
		. ( $held ? ' Held at the old price because the new one moved more than 4x: ' . implode( ', ', array_slice( $held, 0, 5 ) ) . '.' : '' );
}

/**
 * The cron callback: a new read is compared with what is trusted now, which
 * is the earlier reads and, under them, the table in code.
 *
 * @return void
 */
function sn_ai_prices_cron() {
	sn_ai_prices_refresh( null, function_exists( 'snt_ai_model_pricing' ) ? snt_ai_model_pricing() : array() );
}
