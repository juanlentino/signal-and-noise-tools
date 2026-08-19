<?php
/**
 * Signal & Noise — the verified citation graph: pure core.
 *
 * A webmention is an UNVERIFIED CLAIM that someone cited you. Stock plugins
 * display that claim as though it were a fact. Adjudicating unverified claims of
 * authorship is this site's actual subject, so the claim is displayed with the
 * same epistemic discipline the rest of the site uses: state what was checked,
 * state what was found, and never let "not measured" render as "measured zero"
 * (the rule that produced the realtime zero-vs-null distinction).
 *
 * The tier is not a flat label. It falls out of two INDEPENDENT probes:
 *
 *   fetch_ok        did the source page load at all?
 *   link_present    does the source still contain a link to the target?
 *   identity_found  does the source ORIGIN publish a discoverable identity?
 *
 *   fetch failed                        → unverified  (evidence missing, not absent)
 *   fetched, no link                    → asserted    (claim made, evidence gone)
 *   fetched, link, no identity          → unattributed
 *   fetched, link, identity             → verified
 *
 * `unverified` is the state the original three-tier sketch did not name, and it
 * is load-bearing: without it the first network blip convicts a live citation of
 * having dropped its link.
 *
 * Pure functions only — no hooks, no I/O, no wpdb. Everything here is a value
 * transform, which is what makes the ladder testable without a network.
 *
 * @package SignalNoiseTools
 * @since 11.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The four states, in descending order of what the site can actually claim. */
const SN_CIT_TIERS = array( 'verified', 'unattributed', 'asserted', 'unverified' );

/**
 * Normalise a URL for comparison: lowercase scheme and host, drop a default
 * port, drop the fragment, drop a trailing slash on the path. The query is
 * KEPT — ?p=12 and ?p=13 are different targets. Pure.
 *
 * @param string $url
 * @return string '' when the input is not a usable absolute http(s) URL.
 */
function sn_cit_normalize_url( $url ) {
	$url   = trim( (string) $url );
	$parts = wp_parse_url( $url );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$scheme = strtolower( $parts['scheme'] );
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return '';
	}
	$host = strtolower( $parts['host'] );
	$port = '';
	if ( ! empty( $parts['port'] ) ) {
		$default = ( 'https' === $scheme ) ? 443 : 80;
		if ( (int) $parts['port'] !== $default ) {
			$port = ':' . (int) $parts['port'];
		}
	}
	$path = isset( $parts['path'] ) ? $parts['path'] : '/';
	if ( '' === $path ) {
		$path = '/';
	}
	if ( '/' !== $path ) {
		$path = rtrim( $path, '/' );
	}
	$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
	return $scheme . '://' . $host . $port . $path . $query;
}

/**
 * The origin (scheme://host[:port]) of a URL. Pure.
 *
 * @param string $url
 * @return string '' when unparseable.
 */
function sn_cit_origin( $url ) {
	$norm = sn_cit_normalize_url( $url );
	if ( '' === $norm ) {
		return '';
	}
	$parts = wp_parse_url( $norm );
	$port  = empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'];
	return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . $port;
}

/**
 * Does this HTML contain a link to the target? Compares NORMALISED hrefs so a
 * trailing slash or a mixed-case host is not a missing citation.
 *
 * Deliberately looks at href attributes only — a bare mention of the URL in
 * prose is not a link, and the webmention contract is about links. Pure.
 *
 * @param string $html
 * @param string $target
 * @return bool
 */
function sn_cit_html_links_to( $html, $target ) {
	$want = sn_cit_normalize_url( $target );
	if ( '' === $want || '' === (string) $html ) {
		return false;
	}
	if ( ! preg_match_all( '#<a\b[^>]*\bhref\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', (string) $html, $m ) ) {
		return false;
	}
	foreach ( $m[1] as $raw ) {
		$href = trim( $raw, "\"' \t\n\r" );
		$href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
		if ( sn_cit_normalize_url( $href ) === $want ) {
			return true;
		}
	}
	return false;
}

/**
 * Does this HTML advertise a discoverable identity for its author? Any ONE of
 * these is enough; they are the mechanisms this site itself publishes, which is
 * the point — the probe applies the same standard the site meets.
 *
 *   rel="me"        the IndieWeb identity convention
 *   rel="webfinger" / a webfinger link
 *   a did:web or /.well-known/did.json reference
 *
 * Pure — the origin-level probes that need network live in citations-verify.php.
 *
 * @param string $html
 * @return bool
 */
function sn_cit_html_has_identity( $html ) {
	$html = (string) $html;
	if ( '' === $html ) {
		return false;
	}
	if ( preg_match_all( '#\brel\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $html, $all ) ) {
		foreach ( $all[0] as $i => $_unused ) {
			$val = strtolower( trim( $all[2][ $i ] . $all[3][ $i ] . $all[4][ $i ] ) );
			$rels = preg_split( '#\s+#', $val, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $rels ) && ( in_array( 'me', $rels, true ) || in_array( 'webfinger', $rels, true ) ) ) {
				return true;
			}
		}
	}
	return ( false !== stripos( $html, 'did:web:' ) );
}

/**
 * The ladder. Pure, total, and the single place the tier is decided.
 *
 * @param bool $fetch_ok       The source page was retrieved.
 * @param bool $link_present   It still links to the target.
 * @param bool $identity_found The source origin publishes a discoverable identity.
 * @return string One of SN_CIT_TIERS.
 */
function sn_cit_tier( $fetch_ok, $link_present, $identity_found ) {
	if ( ! $fetch_ok ) {
		return 'unverified';
	}
	if ( ! $link_present ) {
		return 'asserted';
	}
	return $identity_found ? 'verified' : 'unattributed';
}

/**
 * What the site is willing to say about each tier, in its own voice. Kept beside
 * the ladder so a new tier cannot ship without a sentence explaining it.
 *
 * @param string $tier
 * @return string
 */
function sn_cit_tier_sentence( $tier ) {
	switch ( $tier ) {
		case 'verified':
			return __( 'Re-fetched: the link is still there, and the source names who publishes it.', 'signal-and-noise-tools' );
		case 'unattributed':
			return __( 'Re-fetched: the link is still there, but the source domain publishes no discoverable identity.', 'signal-and-noise-tools' );
		case 'asserted':
			return __( 'A citation was claimed, and the link is no longer on the page. The claim stands; the evidence does not.', 'signal-and-noise-tools' );
		case 'unverified':
			return __( 'Not checked yet, or the source could not be reached. This is missing evidence, not absent evidence.', 'signal-and-noise-tools' );
		default:
			return '';
	}
}

/**
 * Is a tier one the site will show publicly as a citation? `asserted` and
 * `unverified` are recorded and visible in the admin, but a public "cited by"
 * list that includes them would be doing exactly what stock webmention plugins
 * do: presenting a claim as a fact.
 *
 * @param string $tier
 * @return bool
 */
function sn_cit_tier_is_public( $tier ) {
	return in_array( $tier, array( 'verified', 'unattributed' ), true );
}
