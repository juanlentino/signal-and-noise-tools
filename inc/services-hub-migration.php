<?php
/**
 * Signal & Noise Tools: the /services split, one time, on the next admin load.
 *
 * Creates the four child pages (/services/music/, /services/consulting/ and
 * their Spanish pairs), turns /services and /servicios into hubs with two
 * doors, and adds the consulting topic to the contact form. The children go
 * first, so a door never opens on a 404.
 *
 * Nothing is overwritten: a child that already exists is kept, and a hub is
 * replaced only while its body still hashes to the text captured on
 * 2026-11-06. An edited hub is left alone, and the result is kept in the flag
 * option for reading. Own admin_init hook, because the master sentinel of
 * inc/content-migrations.php is spent on the live site.
 *
 * How the hub works and how to add a third door: docs/BILINGUAL.md.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SERVICES_HUB_OPT      = 'sn_services_hub_migrated_v1';
const SN_SERVICES_HUB_LOCK     = 'sn_services_hub_lock';
const SN_SERVICES_HUB_TEMPLATE = 'page-services';
const SN_SERVICES_HUB_FORM     = 2717; // The /contact form (AllTerrain Forms).

/**
 * The two hubs: page path => frozen md5 of the replaced body, seed, description.
 *
 * @return array<string,array{hash:string,seed:string,description:string}>
 */
function sn_services_hub_targets() {
	return array(
		'services'  => array(
			'hash'        => 'dff73667d225f85dbc7209e1529c8756',
			'seed'        => 'services-hub.html',
			'description' => 'Two ways to work with Juan Lentino: production, mixing and mastering for your record, or strategy, pricing and AI adoption for your business.',
		),
		'servicios' => array(
			'hash'        => '9d24ee8511aec28f28c7aa9c7a9d979c',
			'seed'        => 'servicios-hub.html',
			'description' => 'Dos formas de trabajar con Juan Lentino: producción, mezcla y masterización para tu disco, o estrategia, precios e IA para tu negocio.',
		),
	);
}

/**
 * The four children, English first so each Spanish page can pair with its own.
 *
 * @return array<string,array{title:string,seed:string,en:string,description:string}>
 */
function sn_services_hub_children() {
	return array(
		'services/music'         => array(
			'title'       => 'Music & Production',
			'seed'        => 'services-music.html',
			'en'          => '',
			'description' => 'Production, mixing, songwriting, mastering, and long-term artist and producer development with Juan Lentino, at Panacea in Buenos Aires or remotely.',
		),
		'services/consulting'    => array(
			'title'       => 'Consulting',
			'seed'        => 'services-consulting.html',
			'en'          => '',
			'description' => 'Strategy, pricing, operations and AI adoption for small businesses and creative companies, from 15+ years running creative businesses.',
		),
		'servicios/musica'       => array(
			'title'       => 'Música y producción',
			'seed'        => 'servicios-musica.html',
			'en'          => 'services/music',
			'description' => 'Producción, mezcla, composición, masterización y desarrollo de artistas y productores con Juan Lentino, en Panacea, Buenos Aires, o a distancia.',
		),
		'servicios/consultoria'  => array(
			'title'       => 'Consultoría',
			'seed'        => 'servicios-consultoria.html',
			'en'          => 'services/consulting',
			'description' => 'Estrategia, precios, operaciones y adopción de IA para pequeños negocios y empresas creativas, con más de 15 años al frente de negocios creativos.',
		),
	);
}

/**
 * A seed file's markup, or '' when it is missing.
 *
 * @param string $name File name under inc/seed-content/.
 * @return string
 */
function sn_services_hub_seed( $name ) {
	$file = __DIR__ . '/seed-content/' . $name;
	return file_exists( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
}

/**
 * Create one child unless its path is already taken.
 *
 * @param string $path Page path, e.g. "services/music".
 * @param array  $spec One sn_services_hub_children() row.
 * @return string 'created', 'kept' or the reason it could not be made.
 */
function sn_services_hub_ensure_child( $path, $spec ) {
	if ( get_page_by_path( $path ) ) {
		return 'kept';
	}
	$parent = get_page_by_path( strstr( $path, '/', true ) );
	$en     = '' === $spec['en'] ? null : get_page_by_path( $spec['en'] );
	$body   = sn_services_hub_seed( $spec['seed'] );
	if ( ! $parent || '' === $body || ( '' !== $spec['en'] && ! $en ) ) {
		return 'not ready';
	}
	$meta = array(
		'_wp_page_template'    => SN_SERVICES_HUB_TEMPLATE,
		'_sn_meta_description' => $spec['description'],
	);
	if ( $en ) {
		$meta[ SN_LANG_PAIR_META ] = (int) $en->ID;
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id(),
				'post_parent'    => (int) $parent->ID,
				'post_name'      => substr( strrchr( $path, '/' ), 1 ),
				'post_title'     => $spec['title'],
				'post_content'   => $body,
				'post_excerpt'   => $spec['description'],
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'meta_input'     => $meta,
			)
		),
		true
	);
	return is_wp_error( $id ) ? $id->get_error_code() : 'created';
}

/**
 * Turn one page into its hub while its body is still the captured one.
 *
 * @param string $path   Page path.
 * @param array  $target One sn_services_hub_targets() row.
 * @return string 'replaced', 'already' or why it was left alone.
 */
function sn_services_hub_swap( $path, $target ) {
	$page = get_page_by_path( $path );
	$new  = sn_services_hub_seed( $target['seed'] );
	if ( ! $page || '' === $new ) {
		return 'not ready';
	}
	$content = (string) $page->post_content;
	if ( trim( $content ) === $new ) {
		return 'already';
	}
	if ( md5( $content ) !== $target['hash'] ) {
		return 'edited, left alone';
	}
	$id = wp_update_post(
		wp_slash(
			array(
				'ID'           => (int) $page->ID,
				'post_content' => $new,
				'post_excerpt' => $target['description'],
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id->get_error_code();
	}
	update_post_meta( (int) $page->ID, '_sn_meta_description', wp_slash( $target['description'] ) );
	return 'replaced';
}

/**
 * The form schema with the consulting topic, preselected by ?topic=. Pure.
 * Returns the schema unchanged when the topic field is not there or is done.
 *
 * @param array $schema Form schema.
 * @return array
 */
function sn_services_hub_topic_schema( $schema ) {
	foreach ( (array) ( $schema['fields'] ?? array() ) as $i => $field ) {
		if ( 'intent' !== ( $field['id'] ?? '' ) ) {
			continue;
		}
		$choices = (array) ( $field['choices'] ?? array() );
		$values  = array_column( $choices, 'value' );
		if ( ! in_array( 'Consulting', $values, true ) ) {
			$at = array_search( 'Music', $values, true );
			$at = false === $at ? count( $choices ) : $at + 1;
			array_splice( $choices, $at, 0, array( array( 'label' => 'Business consulting', 'value' => 'Consulting' ) ) );
		}
		$schema['fields'][ $i ]['choices'] = $choices;
		$schema['fields'][ $i ]['prefill'] = 'query:topic';
		return $schema;
	}
	return $schema;
}

/**
 * Add the topic to the live contact form.
 *
 * @return string What happened.
 */
function sn_services_hub_add_topic() {
	if ( ! function_exists( 'alltfo_get_form_schema' ) || ! function_exists( 'alltfo_save_form_schema' ) ) {
		return 'forms plugin not active';
	}
	$form = get_post( SN_SERVICES_HUB_FORM );
	if ( ! $form ) {
		return 'form not found';
	}
	$schema = (array) alltfo_get_form_schema( $form );
	$new    = sn_services_hub_topic_schema( $schema );
	if ( $new === $schema ) {
		return 'unchanged';
	}
	alltfo_save_form_schema( SN_SERVICES_HUB_FORM, $new );
	return 'added';
}

/**
 * The one-shot. Retries on the next admin load while a child cannot be made.
 */
function sn_migrate_services_hub() {
	if ( get_option( SN_SERVICES_HUB_OPT ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// A lock first: two admin requests at once must not both insert. A lock
	// older than ten minutes is a crashed run and is taken over.
	$lock = (int) get_option( SN_SERVICES_HUB_LOCK, 0 );
	if ( $lock > time() - 600 ) {
		return;
	}
	delete_option( SN_SERVICES_HUB_LOCK );
	if ( ! add_option( SN_SERVICES_HUB_LOCK, time(), '', false ) ) {
		return;
	}
	$report = array();
	foreach ( sn_services_hub_children() as $path => $spec ) {
		$report[ $path ] = sn_services_hub_ensure_child( $path, $spec );
	}
	if ( ! array_diff( $report, array( 'created', 'kept' ) ) ) {
		foreach ( sn_services_hub_targets() as $path => $target ) {
			$report[ $path ] = sn_services_hub_swap( $path, $target );
		}
		$report['form'] = sn_services_hub_add_topic();
		update_option( SN_SERVICES_HUB_OPT, $report, false );
	}
	delete_option( SN_SERVICES_HUB_LOCK );
}
add_action( 'admin_init', 'sn_migrate_services_hub' );
