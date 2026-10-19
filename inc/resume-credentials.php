<?php
/**
 * Signal & Noise Tools: the résumé's credentials in the Person schema.
 *
 * schema.org `hasCredential` (EducationalOccupationalCredential) for each
 * degree and certificate on the résumé, read from the résumé document through
 * the JSON Resume parsers (inc/resume-jsonresume-parts.php), so the schema
 * says what the page says. Added through the existing
 * `sn_schema_person_credentials` filter; memberOf (the Recording Academy and
 * the Latin Recording Academy) is already there and is left alone. These are
 * self-asserted claims, not issuer-signed credentials: a verifiable degree or
 * membership has to come from the university or the Academy, so an entry the
 * owner gives a verification URL (the issuer's hosted page, e.g. the
 * Parchment diploma) carries it as `url`.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * hasCredential entries for a résumé document.
 *
 * @param array|null $doc Normalized résumé document.
 * @return array<int,array<string,mixed>>
 */
function sn_resume_credentials( $doc ) {
	if ( ! is_array( $doc ) ) {
		return array();
	}
	$out = array();
	foreach ( sn_jsonresume_education( $doc )[0] as $e ) {
		$out[] = array_filter(
			array(
				'@type'              => 'EducationalOccupationalCredential',
				'name'               => implode( ', ', array_filter( array( $e['studyType'] ?? '', $e['area'] ?? '' ) ) ),
				'credentialCategory' => 'degree',
				'recognizedBy'       => isset( $e['institution'] ) ? array( '@type' => 'CollegeOrUniversity', 'name' => $e['institution'] ) : null,
				'dateCreated'        => $e['endDate'] ?? '',
				'url'                => $e['x-credentialUrl'] ?? '',
			)
		);
	}
	list( $memberships, $certs ) = sn_jsonresume_affiliations( $doc );
	foreach ( $certs as $c ) {
		$out[] = array_filter(
			array(
				'@type'              => 'EducationalOccupationalCredential',
				'name'               => $c['name'],
				'credentialCategory' => 'certificate',
				'url'                => $c['url'] ?? '',
			)
		);
	}
	// A membership is memberOf already; it is a credential only once the
	// owner gives the issuer's verification page.
	foreach ( $memberships as $m ) {
		if ( isset( $m['x-credentialUrl'] ) ) {
			$out[] = array_filter(
				array(
					'@type'              => 'EducationalOccupationalCredential',
					'name'               => $m['title'],
					'credentialCategory' => 'membership',
					'recognizedBy'       => isset( $m['awarder'] ) ? array( '@type' => 'Organization', 'name' => $m['awarder'] ) : null,
					'url'                => $m['x-credentialUrl'],
				)
			);
		}
	}
	return $out;
}

/**
 * `sn_schema_person_credentials` filter: add hasCredential.
 *
 * @param array $creds
 * @return array
 */
function sn_resume_person_credentials( $creds ) {
	$list = sn_resume_credentials( function_exists( 'sn_resume_doc_get' ) ? sn_resume_doc_get() : null );
	if ( $list ) {
		$creds['hasCredential'] = $list;
	}
	return $creds;
}
