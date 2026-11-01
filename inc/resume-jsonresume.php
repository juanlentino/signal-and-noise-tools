<?php
/**
 * Signal & Noise Tools: the résumé as JSON Resume v1.0.0 (jsonresume.org).
 *
 * A pure mapper over the SAME document /resume, its PDF and /cv/ render
 * (sn_resume_doc_get(), sn_resume_es_doc()), so it can never say something
 * the page does not. Nothing is invented: a value that does not parse is
 * omitted, a date never gains a day it did not have, and a blank field is
 * left out rather than sent empty. The route, the head link and the WordPress
 * context live in inc/resume-jsonresume-route.php.
 *
 * JSON Resume has no memberships section: a dated-range affiliation (the
 * Recording Academy memberships) goes to `awards`, a single-dated one to
 * `certificates` (whose `date` must be a full day, so it is left out).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/resume-jsonresume-parts.php';

const SN_JSONRESUME_SCHEMA = 'https://raw.githubusercontent.com/jsonresume/resume-schema/v1.0.0/schema.json';

/**
 * The basics block: identity, contact already public, location, profiles.
 *
 * @param array $doc Normalized résumé document.
 * @param array $ctx See sn_jsonresume_build().
 * @return array
 */
function sn_jsonresume_basics( array $doc, array $ctx ) {
	$pdf   = $doc['pdf'] ?? array();
	$hero  = $doc['hero'] ?? array();
	$basic = array(
		'name'    => (string) ( $ctx['name'] ?? '' ),
		'label'   => (string) ( $pdf['headline'] ?? '' ),
		'email'   => (string) ( $pdf['email'] ?? '' ),
		'phone'   => ! empty( $pdf['phone_public'] ) ? (string) ( $pdf['phone'] ?? '' ) : '',
		'url'     => (string) ( $ctx['url'] ?? '' ),
		'summary' => (string) ( $hero['summary'] ?? '' ),
	);
	$basic = array_filter( $basic, 'strlen' );
	$where = '' !== (string) ( $pdf['location'] ?? '' ) ? (string) $pdf['location'] : (string) ( $hero['contact_line'] ?? '' );
	$parts = array_map( 'trim', explode( ',', $where ) );
	if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
		$basic['location'] = array( 'city' => $parts[0], 'region' => $parts[1] );
	} elseif ( '' !== trim( $where ) ) {
		$basic['location'] = array( 'address' => trim( $where ) );
	}
	$basic['profiles'] = sn_jsonresume_profiles( array_merge( (array) ( $ctx['profiles'] ?? array() ), array( (string) ( $hero['linkedin'] ?? '' ) ) ) );
	return $basic;
}

/**
 * Work: one entry per role, the current orgs then the earlier career.
 *
 * @param array $doc
 * @return array<int,array>
 */
function sn_jsonresume_work( array $doc ) {
	$out    = array();
	$groups = array_merge( $doc['experience'] ?? array(), $doc['earlier']['entries'] ?? array() );
	foreach ( $groups as $entry ) {
		$org      = explode( ' · ', (string) $entry['org'], 2 );
		$location = '' !== (string) ( $entry['location'] ?? '' ) ? (string) $entry['location'] : trim( $org[1] ?? '' );
		foreach ( $entry['roles'] as $role ) {
			list( $title, $dates ) = sn_jsonresume_split_dated( $role['title'] );
			list( $start, $end )   = sn_jsonresume_range( '' !== $dates ? $dates : (string) ( $entry['dates'] ?? '' ) );
			$out[] = array_filter(
				array(
					'name'       => trim( $org[0] ),
					'position'   => $title,
					'location'   => $location,
					'startDate'  => $start,
					'endDate'    => $end,
					'highlights' => array_map( 'sn_jsonresume_plain', $role['bullets'] ),
				)
			);
		}
	}
	return $out;
}

/**
 * Projects: "What I've Built" (the operations lane), name, url, description.
 *
 * @param array $items {title,url,text}.
 * @return array<int,array>
 */
function sn_jsonresume_projects( array $items ) {
	$out = array();
	foreach ( $items as $item ) {
		$out[] = array_filter( array( 'name' => (string) ( $item['title'] ?? '' ), 'url' => (string) ( $item['url'] ?? '' ), 'description' => (string) ( $item['text'] ?? '' ) ) );
	}
	return $out;
}

/**
 * Build the JSON Resume document.
 *
 * @param array|null $doc A normalized résumé document (English or Spanish).
 * @param array      $ctx name, url, canonical, last_modified, version,
 *                        profiles (URLs), provenance {verify_url,note_uid}|null,
 *                        projects ({title,url,text}), lane ('' when /resume has one).
 * @return array|null Null when there is no document.
 */
function sn_jsonresume_build( $doc, array $ctx ) {
	if ( ! is_array( $doc ) ) {
		return null;
	}
	list( $education, $honors ) = sn_jsonresume_education( $doc );
	list( $memberships, $certs ) = sn_jsonresume_affiliations( $doc );
	list( $skills, $languages ) = sn_jsonresume_skills( $doc );

	$meta = array_filter(
		array(
			'canonical'    => (string) ( $ctx['canonical'] ?? '' ),
			'version'      => (string) ( $ctx['version'] ?? '' ),
			'lastModified' => (string) ( $ctx['last_modified'] ?? '' ),
		)
	);
	// The page's stats strip, so the JSON carries every fact the page shows.
	$highlights = array();
	foreach ( (array) ( $doc['stats'] ?? array() ) as $stat ) {
		$n = trim( (string) ( $stat['n'] ?? '' ) );
		$l = trim( (string) ( $stat['label'] ?? '' ) );
		if ( '' !== $n && '' !== $l ) {
			$highlights[] = array( 'n' => $n, 'label' => $l );
		}
	}
	if ( $highlights ) {
		$meta['x-highlights'] = $highlights;
	}
	if ( '' !== (string) ( $ctx['lane'] ?? '' ) ) {
		$meta['x-lane'] = (string) $ctx['lane']; // which of /resume's two résumés this is.
	}
	$prov = $ctx['provenance'] ?? null;
	if ( is_array( $prov ) && '' !== (string) ( $prov['note_uid'] ?? '' ) ) {
		$meta['x-provenance'] = array( 'verify_url' => (string) ( $prov['verify_url'] ?? '' ), 'note_uid' => (string) $prov['note_uid'] );
	}

	return array_filter(
		array(
			'$schema'      => SN_JSONRESUME_SCHEMA,
			'basics'       => sn_jsonresume_basics( $doc, $ctx ),
			'work'         => sn_jsonresume_work( $doc ),
			'education'    => $education,
			'awards'       => array_merge( $honors, $memberships ),
			'certificates' => $certs,
			'publications' => sn_jsonresume_publications( $doc ),
			'projects'     => sn_jsonresume_projects( (array) ( $ctx['projects'] ?? array() ) ),
			'skills'       => $skills,
			'languages'    => $languages,
			'meta'         => $meta,
		)
	);
}
