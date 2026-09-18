<?php
/**
 * Signal & Noise Tools -- Zenodo records (15.11.0).
 *
 * What a document's Zenodo record IS (the metadata, pure), what goes in it
 * (the bundle: the Markdown, the signed ledger record, the Bitcoin proof),
 * and the flow that deposits it after the anchor confirms. The DOI flows
 * back through sn_zenodo_doi_for() into the schema, the citation tool, the
 * site map and llms-full.txt. The papers stay with SSRN (owner, 2026-09-17).
 * See docs/zenodo-doi-design.md.
 *
 * @package SignalNoiseTools
 * @since 15.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ZENODO_ORCID     = '0009-0006-8151-5920';
const SN_ZENODO_LICENSE   = 'cc-by-nd-4.0';
const SN_ZENODO_HOOK      = 'sn_zenodo_deposit_one';
const SN_ZENODO_PASS_HOOK = 'sn_zenodo_backfill_pass';
const SN_ZENODO_PASS_MAX  = 5;

/**
 * The module's enable predicate: a token for the current environment. The
 * cron opt-in map reads it, so an unconfigured install never reports the
 * backfill pass as missing.
 *
 * @since 15.11.0
 * @return bool
 */
function sn_zenodo_is_enabled() {
	return '' !== sn_zenodo_token();
}

/**
 * The record's metadata. PURE: plain inputs in, Zenodo's `metadata` object out.
 *
 * @since 15.11.0
 * @param array $doc {
 *   title, description, url, date (YYYY-MM-DD), keywords (string[]),
 *   version (int), pillar_url (string|''), pubkey_id (string),
 *   bitcoin_block (int|null), verify_url (string), ledger_doi (string|'')
 * }
 * @return array
 */
function sn_zenodo_metadata_for( array $doc ) {
	$title = trim( (string) ( $doc['title'] ?? '' ) );
	$url   = (string) ( $doc['url'] ?? '' );
	$related = array();
	if ( '' !== $url ) {
		$related[] = array( 'identifier' => $url, 'relation' => 'isIdenticalTo', 'resource_type' => 'publication-other' );
	}
	if ( '' !== (string) ( $doc['pillar_url'] ?? '' ) ) {
		$related[] = array( 'identifier' => (string) $doc['pillar_url'], 'relation' => 'isPartOf', 'resource_type' => 'publication-other' );
	}
	if ( '' !== (string) ( $doc['ledger_doi'] ?? '' ) ) {
		// 16.1.1: the ledger itself has a concept DOI (a monthly snapshot
		// release on the ledger repository); every document is part of it.
		$related[] = array( 'identifier' => (string) $doc['ledger_doi'], 'relation' => 'isPartOf', 'resource_type' => 'dataset' );
	}
	$block = isset( $doc['bitcoin_block'] ) && (int) $doc['bitcoin_block'] > 0 ? (int) $doc['bitcoin_block'] : 0;
	$notes = sprintf(
		'Signed at publication (Ed25519, key %s) and anchored on Bitcoin through OpenTimestamps%s. Verify at %s.',
		'' !== (string) ( $doc['pubkey_id'] ?? '' ) ? (string) $doc['pubkey_id'] : 'unknown',
		$block ? ' (block ' . $block . ')' : '',
		(string) ( $doc['verify_url'] ?? 'https://juanlentino.com/verify' )
	);
	$meta = array(
		'upload_type'      => 'publication',
		'publication_type' => 'other',
		'title'            => $title,
		'description'      => '' !== trim( (string) ( $doc['description'] ?? '' ) ) ? trim( (string) $doc['description'] ) : $title,
		'creators'         => array( array( 'name' => 'Lentino, Juan', 'orcid' => SN_ZENODO_ORCID ) ),
		'publication_date' => (string) ( $doc['date'] ?? gmdate( 'Y-m-d' ) ),
		'language'         => 'eng',
		'license'          => SN_ZENODO_LICENSE,
		'access_right'     => 'open',
		'version'          => 'v' . max( 1, (int) ( $doc['version'] ?? 1 ) ),
		'notes'            => $notes,
	);
	$keywords = array_values( array_filter( array_map( 'strval', (array) ( $doc['keywords'] ?? array() ) ), 'strlen' ) );
	if ( array() !== $keywords ) {
		$meta['keywords'] = $keywords;
	}
	if ( array() !== $related ) {
		$meta['related_identifiers'] = $related;
	}
	return $meta;
}

/**
 * The head commit of a subject's chain, or null.
 *
 * @since 15.11.0
 */
function sn_zenodo_head_commit( $post_id ) {
	$chain = function_exists( 'sn_prov_get_chain' ) ? sn_prov_get_chain( (int) $post_id ) : array();
	if ( array() === $chain ) {
		return null;
	}
	$last = end( $chain );
	return is_array( $last ) ? $last : null;
}

/**
 * Is this post a deposit candidate: a signed subject whose head commit is
 * Bitcoin-confirmed? PURE apart from the meta reads.
 *
 * @since 15.11.0
 * @return array{ready:bool,reason:string,commit:?array,kind:string}
 */
function sn_zenodo_readiness( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return array( 'ready' => false, 'reason' => 'not-published', 'commit' => null, 'kind' => '' );
	}
	$kind = function_exists( 'sn_prov_subject_kind' ) ? (string) sn_prov_subject_kind( $post ) : '';
	if ( '' === $kind ) {
		return array( 'ready' => false, 'reason' => 'not-a-subject', 'commit' => null, 'kind' => '' );
	}
	$head = sn_zenodo_head_commit( $post->ID );
	if ( null === $head ) {
		return array( 'ready' => false, 'reason' => 'no-commit', 'commit' => null, 'kind' => $kind );
	}
	if ( 'confirmed' !== (string) ( $head['status'] ?? '' ) ) {
		return array( 'ready' => false, 'reason' => 'anchor-pending', 'commit' => $head, 'kind' => $kind );
	}
	return array( 'ready' => true, 'reason' => '', 'commit' => $head, 'kind' => $kind );
}

/**
 * The document's inputs for the metadata builder, from WordPress.
 *
 * @since 15.11.0
 */
function sn_zenodo_document( $post_id, array $commit ) {
	$post   = get_post( (int) $post_id );
	$tags   = function_exists( 'wp_get_post_tags' ) ? (array) wp_get_post_tags( (int) $post_id, array( 'fields' => 'names' ) ) : array();
	$desc   = function_exists( 'sn_seo_resolve_singular_description' ) ? (string) sn_seo_resolve_singular_description( $post ) : (string) $post->post_excerpt;
	$pillar = '';
	$map    = get_option( defined( 'SN_SITE_MAP_OPT' ) ? SN_SITE_MAP_OPT : 'sn_site_map', array() );
	if ( is_array( $map ) && isset( $map['notes'] ) ) {
		foreach ( (array) $map['notes'] as $n ) {
			if ( (int) ( $n['id'] ?? 0 ) === (int) $post_id && '' !== (string) ( $n['pillar'] ?? '' ) ) {
				foreach ( (array) ( $map['pillars'] ?? array() ) as $pl ) {
					if ( (string) ( $pl['designation'] ?? '' ) === (string) $n['pillar'] || (string) ( $pl['tag'] ?? '' ) === (string) $n['pillar'] ) {
						$pillar = (string) ( $pl['url'] ?? '' );
					}
				}
			}
		}
	}
	return array(
		'title'         => wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ),
		'description'   => wp_strip_all_tags( html_entity_decode( $desc, ENT_QUOTES, 'UTF-8' ) ),
		'url'           => get_permalink( $post ),
		'date'          => get_post_time( 'Y-m-d', true, $post ),
		'keywords'      => array_map( 'strval', $tags ),
		'version'       => (int) ( $commit['version'] ?? 1 ),
		'pillar_url'    => $pillar,
		'pubkey_id'     => (string) ( $commit['pubkey_id'] ?? '' ),
		'bitcoin_block' => isset( $commit['bitcoin_block'] ) ? (int) $commit['bitcoin_block'] : null,
		'verify_url'    => home_url( '/verify' ),
		'ledger_doi'    => sn_zenodo_ledger_doi(),
	);
}

/**
 * The files: the Markdown the site itself serves, the signed ledger record
 * and the proof from the public ledger. Any missing part aborts the deposit:
 * a record without its proof is not the record this design promises.
 *
 * @since 15.11.0
 * @return array{ok:bool,error:string,files:array<string,array{bytes:string,type:string}>}
 */
function sn_zenodo_bundle( $post_id, array $commit, $kind ) {
	$post = get_post( (int) $post_id );
	$uid  = function_exists( 'sn_prov_note_uid' ) ? (string) sn_prov_note_uid( (int) $post_id ) : '';
	$dir  = function_exists( 'sn_prov_ledger_dir' ) ? (string) sn_prov_ledger_dir( $kind ) : 'notes';
	$ends = function_exists( 'sn_prov_verify_endpoints' ) ? sn_prov_verify_endpoints() : array( 'ledger_base' => 'https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/' );
	if ( '' === $uid || '' === $dir ) {
		return array( 'ok' => false, 'error' => 'no-uid', 'files' => array() );
	}
	$version = (int) ( $commit['version'] ?? 1 );
	$base    = rtrim( (string) $ends['ledger_base'], '/' ) . '/' . $dir . '/' . rawurlencode( $uid ) . '/v' . $version;
	$fetch   = static function ( $url, $accept ) {
		$resp = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 2, 'sslverify' => true, 'headers' => array( 'Accept' => $accept, 'User-Agent' => 'signal-and-noise-tools' ) ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		return '' === $body ? null : $body;
	};
	$md     = $fetch( get_permalink( $post ), 'text/markdown' );
	$record = $fetch( $base . '.json', 'application/json' );
	$proof  = $fetch( $base . '.ots', 'application/octet-stream' );
	if ( null === $md ) {
		return array( 'ok' => false, 'error' => 'markdown-unavailable', 'files' => array() );
	}
	if ( null === $record || null === $proof ) {
		return array( 'ok' => false, 'error' => 'ledger-unavailable', 'files' => array() );
	}
	$slug = (string) $post->post_name;
	return array(
		'ok'    => true,
		'error' => '',
		'files' => array(
			$slug . '.md'              => array( 'bytes' => $md, 'type' => 'text/markdown' ),
			$slug . '.provenance.json' => array( 'bytes' => $record, 'type' => 'application/json' ),
			$slug . '.ots'             => array( 'bytes' => $proof, 'type' => 'application/octet-stream' ),
		),
	);
}

/**
 * Deposit one document. Idempotent: a post with a DOI in this environment is
 * returned as done; an interrupted flow resumes from its draft id.
 *
 * @since 15.11.0
 * @return array{ok:bool,state:string,doi:string,error:string}
 */
function sn_zenodo_deposit( $post_id ) {
	$post_id = (int) $post_id;
	$env     = sn_zenodo_env();
	$have    = (string) get_post_meta( $post_id, SN_ZENODO_DOI_META, true );
	if ( '' !== $have && (string) get_post_meta( $post_id, SN_ZENODO_ENV_META, true ) === $env ) {
		return array( 'ok' => true, 'state' => 'already', 'doi' => $have, 'error' => '' );
	}
	if ( '' === sn_zenodo_token( $env ) ) {
		return array( 'ok' => false, 'state' => 'no-token', 'doi' => '', 'error' => 'no-token' );
	}
	$ready = sn_zenodo_readiness( $post_id );
	if ( ! $ready['ready'] ) {
		return array( 'ok' => false, 'state' => $ready['reason'], 'doi' => '', 'error' => '' );
	}
	$fail = static function ( $state, $error ) use ( $post_id ) {
		update_post_meta( $post_id, SN_ZENODO_ERROR_META, gmdate( 'c' ) . ' ' . $state . ': ' . $error );
		return array( 'ok' => false, 'state' => $state, 'doi' => '', 'error' => $error );
	};

	$bundle = sn_zenodo_bundle( $post_id, $ready['commit'], $ready['kind'] );
	if ( ! $bundle['ok'] ) {
		return $fail( 'bundle', $bundle['error'] );
	}

	// Resume an interrupted flow rather than minting a duplicate.
	$draft = (string) get_post_meta( $post_id, SN_ZENODO_DRAFT_META, true );
	$dep   = '' !== $draft ? sn_zenodo_get_deposition( $draft, $env ) : sn_zenodo_create_deposition( $env );
	if ( ! $dep['ok'] || ! is_array( $dep['body'] ) || empty( $dep['body']['id'] ) ) {
		if ( '' !== $draft ) {
			delete_post_meta( $post_id, SN_ZENODO_DRAFT_META ); // a dead draft; next pass starts clean
		}
		return $fail( 'create', $dep['error'] ?: 'no-id' );
	}
	$id     = (string) $dep['body']['id'];
	$bucket = (string) ( $dep['body']['links']['bucket'] ?? '' );
	update_post_meta( $post_id, SN_ZENODO_DRAFT_META, $id );
	if ( '' === $bucket ) {
		return $fail( 'create', 'no-bucket' );
	}

	foreach ( $bundle['files'] as $name => $file ) {
		$up = sn_zenodo_upload_file( $bucket, $name, $file['bytes'], $env, $file['type'] );
		if ( ! $up['ok'] ) {
			return $fail( 'upload', $name . ': ' . $up['error'] );
		}
	}
	$meta = sn_zenodo_set_metadata( $id, sn_zenodo_metadata_for( sn_zenodo_document( $post_id, $ready['commit'] ) ), $env );
	if ( ! $meta['ok'] ) {
		return $fail( 'metadata', $meta['error'] );
	}
	$pub = sn_zenodo_publish( $id, $env );
	if ( ! $pub['ok'] || ! is_array( $pub['body'] ) ) {
		return $fail( 'publish', $pub['error'] ?: 'no-body' );
	}
	$doi     = (string) ( $pub['body']['doi'] ?? ( $pub['body']['metadata']['doi'] ?? '' ) );
	$concept = (string) ( $pub['body']['conceptdoi'] ?? '' );
	if ( '' === $doi ) {
		return $fail( 'publish', 'no-doi' );
	}
	update_post_meta( $post_id, SN_ZENODO_DOI_META, $doi );
	update_post_meta( $post_id, SN_ZENODO_CONCEPT_DOI_META, $concept );
	update_post_meta( $post_id, SN_ZENODO_RECORD_META, (string) ( $pub['body']['id'] ?? $id ) );
	update_post_meta( $post_id, SN_ZENODO_ENV_META, $env );
	update_post_meta( $post_id, SN_ZENODO_AT_META, gmdate( 'c' ) );
	delete_post_meta( $post_id, SN_ZENODO_DRAFT_META );
	delete_post_meta( $post_id, SN_ZENODO_ERROR_META );
	return array( 'ok' => true, 'state' => 'published', 'doi' => $doi, 'error' => '' );
}

/**
 * The PRODUCTION DOI of a post, or '' (a sandbox DOI never reaches a public
 * surface). This is the one accessor every flow-back reads.
 *
 * @since 15.11.0
 * @return string
 */
function sn_zenodo_doi_for( $post_id ) {
	$doi = (string) get_post_meta( (int) $post_id, SN_ZENODO_DOI_META, true );
	if ( '' === $doi || sn_zenodo_doi_is_sandbox( $doi ) ) {
		return '';
	}
	return 'production' === (string) get_post_meta( (int) $post_id, SN_ZENODO_ENV_META, true ) ? $doi : '';
}

/**
 * Every subject that could carry a DOI: published notes and signed pages.
 *
 * @since 15.11.0
 * @return int[]
 */
function sn_zenodo_subject_ids() {
	$ids = array();
	foreach ( array( 'post', 'page' ) as $type ) {
		$q = get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids', 'suppress_filters' => false ) );
		foreach ( (array) $q as $id ) {
			if ( function_exists( 'sn_prov_subject_kind' ) && '' !== (string) sn_prov_subject_kind( get_post( (int) $id ) ) ) {
				$ids[] = (int) $id;
			}
		}
	}
	return $ids;
}

/**
 * The ledger: one row per subject with its state.
 *
 * @since 15.11.0
 * @return array<int,array{id:int,title:string,kind:string,state:string,doi:string,env:string,at:string,error:string}>
 */
function sn_zenodo_ledger() {
	$rows = array();
	foreach ( sn_zenodo_subject_ids() as $id ) {
		$ready = sn_zenodo_readiness( $id );
		$doi   = (string) get_post_meta( $id, SN_ZENODO_DOI_META, true );
		$env   = (string) get_post_meta( $id, SN_ZENODO_ENV_META, true );
		if ( '' !== $doi ) {
			$state = sn_zenodo_doi_is_sandbox( $doi ) || 'production' !== $env ? 'sandbox' : 'minted';
		} else {
			$state = $ready['ready'] ? 'ready' : $ready['reason'];
		}
		$rows[] = array(
			'id'    => $id,
			'title' => wp_strip_all_tags( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ),
			'kind'  => $ready['kind'],
			'state' => $state,
			'doi'   => $doi,
			'env'   => $env,
			'at'    => (string) get_post_meta( $id, SN_ZENODO_AT_META, true ),
			'error' => (string) get_post_meta( $id, SN_ZENODO_ERROR_META, true ),
		);
	}
	return $rows;
}

/**
 * The trigger: the provenance sweep's confirmation. Books a single event so
 * the deposit runs off the request that carried the callback.
 *
 * @since 15.11.0
 */
function sn_zenodo_on_confirmed( $post_id ) {
	if ( '' === sn_zenodo_token() ) {
		return;
	}
	if ( ! wp_next_scheduled( SN_ZENODO_HOOK, array( (int) $post_id ) ) ) {
		wp_schedule_single_event( time() + 60, SN_ZENODO_HOOK, array( (int) $post_id ) );
	}
}
add_action( 'sn_prov_confirmed', 'sn_zenodo_on_confirmed', 10, 1 );
add_action( SN_ZENODO_HOOK, 'sn_zenodo_deposit', 10, 1 );

/**
 * The backfill pass: up to SN_ZENODO_PASS_MAX ready documents per run. Runs
 * hourly while a token is set; leaves nothing looping inside a run.
 *
 * @since 15.11.0
 * @return array{attempted:int,published:int,failed:int}
 */
function sn_zenodo_backfill_pass() {
	$out = array( 'attempted' => 0, 'published' => 0, 'failed' => 0 );
	if ( '' === sn_zenodo_token() ) {
		return $out;
	}
	foreach ( sn_zenodo_ledger() as $row ) {
		if ( $out['attempted'] >= SN_ZENODO_PASS_MAX ) {
			break;
		}
		if ( 'ready' !== $row['state'] && ! ( 'sandbox' === $row['state'] && 'production' === sn_zenodo_env() ) ) {
			continue;
		}
		$out['attempted']++;
		$r = sn_zenodo_deposit( $row['id'] );
		if ( $r['ok'] ) {
			$out['published']++;
		} else {
			$out['failed']++;
			if ( 'no-token' === $r['state'] ) {
				break;
			}
		}
	}
	return $out;
}
add_action( SN_ZENODO_PASS_HOOK, 'sn_zenodo_backfill_pass' );
add_action( 'init', function () {
	if ( '' !== sn_zenodo_token() && ! wp_next_scheduled( SN_ZENODO_PASS_HOOK ) ) {
		wp_schedule_event( time() + 300, 'hourly', SN_ZENODO_PASS_HOOK );
	}
}, 20 );
