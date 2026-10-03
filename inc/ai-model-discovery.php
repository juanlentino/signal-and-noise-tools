<?php
/**
 * Signal & Noise Tools: the daily read of what the AI providers serve (20.10.0).
 *
 * The WordPress AI Client keeps a provider registry; the Anthropic and Google
 * provider plugins answer its model-metadata directory from the vendors' own
 * model-list APIs, with the site's connector keys. This file asks once a day,
 * in cron, and stores the answer for inc/ai-model-catalog.php to read. A
 * provider that is not connected, or could not be read, keeps its last good
 * rows and the reason is recorded.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One model's metadata (ModelMetadata::toArray()) as the row the pickers use. PURE.
 * Text: it lists the text_generation capability. Vision: its input_modalities
 * option accepts an image.
 *
 * @param array $meta { id, name, supportedCapabilities: string[], supportedOptions: { name, supportedValues }[] }.
 * @return array{id:string,name:string,text:bool,vision:bool}
 */
function sn_ai_models_row( array $meta ) {
	$vision = false;
	foreach ( (array) ( $meta['supportedOptions'] ?? array() ) as $opt ) {
		if ( is_array( $opt ) && 'input_modalities' === ( $opt['name'] ?? '' ) ) {
			$vision = $vision || false !== strpos( (string) wp_json_encode( $opt['supportedValues'] ?? array() ), '"image"' );
		}
	}
	return array(
		'id'     => (string) ( $meta['id'] ?? '' ),
		'name'   => (string) ( $meta['name'] ?? '' ),
		'text'   => in_array( 'text_generation', (array) ( $meta['supportedCapabilities'] ?? array() ), true ),
		'vision' => $vision,
	);
}

/**
 * Ask the AI Client's registry what one provider serves. The one seam that
 * leaves the site: the provider plugin calls its vendor's model-list API.
 *
 * @param string $provider 'anthropic' or 'google'.
 * @return array<int,array{id:string,name:string,text:bool,vision:bool}>|string Rows, or why not.
 */
function sn_ai_models_ask( $provider ) {
	$client = '\\WordPress\\AiClient\\AiClient';
	if ( ! class_exists( $client ) ) {
		return 'the WordPress AI Client is not loaded';
	}
	try {
		$registry = $client::defaultRegistry();
		if ( ! $registry->hasProvider( $provider ) || ! $registry->isProviderConfigured( $provider ) ) {
			return 'not connected';
		}
		// The registry's own public path to a provider's list (the one its
		// model matching uses), asked for text-generation models.
		$requirements = '\\WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements';
		$capability   = '\\WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum';
		$rows         = array();
		foreach ( $registry->findProviderModelsMetadataForSupport( $provider, new $requirements( array( $capability::textGeneration() ), array() ) ) as $m ) {
			$rows[] = sn_ai_models_row( (array) $m->toArray() );
		}
		return $rows;
	} catch ( \Throwable $e ) {
		return substr( sanitize_text_field( $e->getMessage() ), 0, 200 );
	}
}

/**
 * The daily read: both providers, stored. A provider that could not be read
 * keeps its last good rows and records why.
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return array The stored state.
 */
function sn_ai_models_refresh( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$state = get_option( SN_AI_MODELS_OPT, array() );
	$state = array( 'fetched' => (int) ( $state['fetched'] ?? 0 ), 'providers' => (array) ( $state['providers'] ?? array() ), 'errors' => array() );
	foreach ( array( 'anthropic', 'google' ) as $provider ) {
		$rows = sn_ai_models_ask( $provider );
		if ( is_array( $rows ) && array() !== $rows ) {
			$state['providers'][ $provider ] = $rows;
			$state['fetched']                = $now;
		} else {
			$state['errors'][ $provider ] = is_string( $rows ) ? $rows : 'the provider listed no models';
		}
	}
	update_option( SN_AI_MODELS_OPT, $state, false );
	return $state;
}

/**
 * One line for the settings screens: where the lists came from.
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return string
 */
function sn_ai_models_status_line( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$state = get_option( SN_AI_MODELS_OPT, array() );
	$at    = is_array( $state ) ? (int) ( $state['fetched'] ?? 0 ) : 0;
	$bad   = is_array( $state ) ? array_filter( (array) ( $state['errors'] ?? array() ) ) : array();
	$why   = array();
	foreach ( $bad as $provider => $text ) {
		$why[] = $provider . ': ' . $text;
	}
	if ( $at < 1 || $at < $now - SN_AI_MODELS_STALE ) {
		return 'Model lists: the built-in ones (the providers have not been read' . ( $why ? '; ' . implode( '; ', $why ) : ' yet' ) . '). Read daily.';
	}
	return sprintf( 'Model lists: read from the providers %s UTC, refreshed daily.', gmdate( 'Y-m-d H:i', $at ) ) . ( $why ? ' Not read last time: ' . implode( '; ', $why ) . '.' : '' );
}

/** Keep the daily read scheduled; the first one runs a minute after install. */
function sn_ai_models_schedule() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_AI_MODELS_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', SN_AI_MODELS_HOOK );
	}
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'sn_ai_models_schedule' );
	add_action( SN_AI_MODELS_HOOK, 'sn_ai_models_refresh', 10, 0 );
}
