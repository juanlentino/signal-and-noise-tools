<?php
/**
 * Signal & Noise Tools — /resume structured editor (data layer).
 *
 * Owner direction 2026-08-03: the resume should be editable from wp-admin as a
 * STRUCTURED form (real fields and repeatable rows), not a plain-text box like
 * /now and /uses and not hand-edited blocks (whose delimiters had already
 * drifted into editor-recovery territory on the live page). Same architecture
 * as the /now editor otherwise: one durable autoload=no OPTION holds the
 * canonical document, and every save regenerates the /resume Page body via the
 * sync engine (inc/resume-sync-engine.php) — so post_content keeps real,
 * machine-readable text and the generated markup can never drift.
 *
 * Document shape (canonical, enforced by sn_resume_doc_normalize()):
 *   hero{summary,chips[],contact_line,linkedin,pdf_url,pdf_label}
 *   stats[]{n,label} · experience[]{org,dates,location,roles[]{title,bullets[]}}
 *   earlier{label,entries[]{org,roles[]{title,bullets[]}}}
 *   education[]{title,lines[]} · affiliations[]{title,lines[]}
 *   publications[]{meta,title,url} · skills[]{category,items}
 *
 * Bullets are the ONE field that carries HTML (<strong>/<em>/<a> via wp_kses
 * at normalize time); every other string is plain text, escaped at the render
 * sink. A document with neither experience nor publications is refused — a
 * bad save can never blank the live page.
 *
 * Admin surface: Content → Resume Page (inc/admin-forms/resume-page.php);
 * POST action `resume_save` (inc/admin-post-actions.php).
 *
 * @package SignalNoiseTools
 * @since 10.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_RESUME_DOC_OPTION', 'sn_resume_doc' );

/**
 * The kses allowlist for bullet fragments: emphasis + links only.
 *
 * @return array<string,array<string,bool>>
 */
function sn_resume_bullet_kses() {
	return array(
		'strong' => array(),
		'em'     => array(),
		'a'      => array( 'href' => true, 'rel' => true ),
	);
}

/**
 * Trim a plain-text field. @param mixed $v @return string */
function sn_resume_text( $v ) {
	return is_string( $v ) ? trim( $v ) : '';
}

/**
 * An http(s)-only URL, '' otherwise. @param mixed $v @return string */
function sn_resume_url( $v ) {
	$v = sn_resume_text( $v );
	return preg_match( '~^https?://~i', $v ) ? $v : '';
}

/**
 * Normalize a bullet fragment: kses to strong/em/a, trimmed. @param mixed $v @return string */
function sn_resume_bullet( $v ) {
	$v = is_string( $v ) ? trim( $v ) : '';
	if ( '' === $v ) {
		return '';
	}
	return function_exists( 'wp_kses' ) ? trim( wp_kses( $v, sn_resume_bullet_kses() ) ) : trim( strip_tags( $v, '<strong><em><a>' ) );
}

/**
 * Normalize a list of {title,bullets[]} roles: title-less roles and blank
 * bullets are dropped, survivors reindexed.
 *
 * @param mixed $roles
 * @return array<int,array{title:string,bullets:array<int,string>}>
 */
function sn_resume_normalize_roles( $roles ) {
	$out = array();
	foreach ( (array) $roles as $role ) {
		if ( ! is_array( $role ) ) {
			continue;
		}
		$title = sn_resume_text( $role['title'] ?? '' );
		if ( '' === $title ) {
			continue; // A title-less role never carries its bullets in.
		}
		$bullets = array();
		foreach ( (array) ( $role['bullets'] ?? array() ) as $b ) {
			$b = sn_resume_bullet( $b );
			if ( '' !== $b ) {
				$bullets[] = $b;
			}
		}
		$out[] = array( 'title' => $title, 'bullets' => $bullets );
	}
	return $out;
}

/**
 * Normalize a list of {title,lines[]} entries (education / affiliations).
 *
 * @param mixed $entries
 * @return array<int,array{title:string,lines:array<int,string>}>
 */
function sn_resume_normalize_titled_lines( $entries ) {
	$out = array();
	foreach ( (array) $entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$title = sn_resume_text( $entry['title'] ?? '' );
		if ( '' === $title ) {
			continue;
		}
		$lines = array();
		foreach ( (array) ( $entry['lines'] ?? array() ) as $line ) {
			$line = sn_resume_text( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		$out[] = array( 'title' => $title, 'lines' => $lines );
	}
	return $out;
}

/**
 * Canonicalise a resume document, or refuse it.
 *
 * Unknown keys are dropped, every string trimmed, blank rows pruned and
 * reindexed, bullet HTML allowlisted, URLs restricted to http(s). Absent
 * sections normalize to empty arrays — absent and empty stay distinguishable
 * from hostile, which returns null. A document with neither experience nor
 * publications is refused (null): those two sections are what make it a
 * resume, and refusing here is what lets sn_resume_doc_save() guarantee the
 * live page is never blanked by a bad save.
 *
 * @param mixed $doc
 * @return array|null Canonical document, or null when unusable.
 */
function sn_resume_doc_normalize( $doc ) {
	if ( ! is_array( $doc ) ) {
		return null;
	}

	$hero_in = is_array( $doc['hero'] ?? null ) ? $doc['hero'] : array();
	$chips   = array();
	foreach ( (array) ( $hero_in['chips'] ?? array() ) as $chip ) {
		$chip = sn_resume_text( $chip );
		if ( '' !== $chip ) {
			$chips[] = $chip;
		}
	}
	$hero = array(
		'summary'      => sn_resume_text( $hero_in['summary'] ?? '' ),
		'chips'        => $chips,
		'contact_line' => sn_resume_text( $hero_in['contact_line'] ?? '' ),
		'linkedin'     => sn_resume_url( $hero_in['linkedin'] ?? '' ),
		'pdf_url'      => sn_resume_url( $hero_in['pdf_url'] ?? '' ),
		'pdf_label'    => sn_resume_text( $hero_in['pdf_label'] ?? '' ),
	);

	$stats = array();
	foreach ( (array) ( $doc['stats'] ?? array() ) as $stat ) {
		if ( ! is_array( $stat ) ) {
			continue;
		}
		$n     = sn_resume_text( $stat['n'] ?? '' );
		$label = sn_resume_text( $stat['label'] ?? '' );
		if ( '' !== $n && '' !== $label ) {
			$stats[] = array( 'n' => $n, 'label' => $label );
		}
	}

	$experience = array();
	foreach ( (array) ( $doc['experience'] ?? array() ) as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$org = sn_resume_text( $entry['org'] ?? '' );
		if ( '' === $org ) {
			continue;
		}
		$experience[] = array(
			'org'      => $org,
			'dates'    => sn_resume_text( $entry['dates'] ?? '' ),
			'location' => sn_resume_text( $entry['location'] ?? '' ),
			'roles'    => sn_resume_normalize_roles( $entry['roles'] ?? array() ),
		);
	}

	$earlier_in      = is_array( $doc['earlier'] ?? null ) ? $doc['earlier'] : array();
	$earlier_entries = array();
	foreach ( (array) ( $earlier_in['entries'] ?? array() ) as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$org = sn_resume_text( $entry['org'] ?? '' );
		if ( '' === $org ) {
			continue;
		}
		$earlier_entries[] = array(
			'org'   => $org,
			'roles' => sn_resume_normalize_roles( $entry['roles'] ?? array() ),
		);
	}
	$earlier = array(
		'label'   => sn_resume_text( $earlier_in['label'] ?? '' ),
		'entries' => $earlier_entries,
	);

	$publications = array();
	foreach ( (array) ( $doc['publications'] ?? array() ) as $pub ) {
		if ( ! is_array( $pub ) ) {
			continue;
		}
		$title = sn_resume_text( $pub['title'] ?? '' );
		if ( '' === $title ) {
			continue;
		}
		$publications[] = array(
			'meta'  => sn_resume_text( $pub['meta'] ?? '' ),
			'title' => $title,
			'url'   => sn_resume_url( $pub['url'] ?? '' ),
		);
	}

	$skills = array();
	foreach ( (array) ( $doc['skills'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$category = sn_resume_text( $row['category'] ?? '' );
		$items    = sn_resume_text( $row['items'] ?? '' );
		if ( '' !== $category && '' !== $items ) {
			$skills[] = array( 'category' => $category, 'items' => $items );
		}
	}

	if ( empty( $experience ) && empty( $publications ) ) {
		return null; // Not a resume — refuse rather than save something blankable.
	}

	return array(
		'hero'         => $hero,
		'stats'        => $stats,
		'experience'   => $experience,
		'earlier'      => $earlier,
		'education'    => sn_resume_normalize_titled_lines( $doc['education'] ?? array() ),
		'affiliations' => sn_resume_normalize_titled_lines( $doc['affiliations'] ?? array() ),
		'publications' => $publications,
		'skills'       => $skills,
	);
}

/**
 * The shipped seed document (the live page content as of v10.33.0), used to
 * prefill the editor before the first save. Null when the file is unreadable.
 *
 * @return array|null
 */
function sn_resume_seed_doc() {
	$file = __DIR__ . '/seed-content/resume-data.json';
	if ( ! is_readable( $file ) ) {
		return null;
	}
	$decoded = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $decoded ) ? $decoded : null;
}

/**
 * The current resume document: the stored option when valid, else the seed.
 * Always normalized; null only if both the option and the seed are unusable.
 *
 * @return array|null
 */
function sn_resume_doc_get() {
	$stored = get_option( SN_RESUME_DOC_OPTION );
	if ( is_array( $stored ) ) {
		$doc = sn_resume_doc_normalize( $stored );
		if ( null !== $doc ) {
			$doc['updated'] = sn_resume_text( $stored['updated'] ?? '' );
			return $doc;
		}
	}
	$seed = sn_resume_doc_normalize( sn_resume_seed_doc() );
	if ( null !== $seed ) {
		$seed['updated'] = '';
	}
	return $seed;
}

/**
 * Save a resume document. Refused documents (normalize → null) are NOT
 * stored — the previous document, and therefore the live page, stand.
 * On a real change the /resume Page body is regenerated by the sync engine
 * (guarded so the data-layer tests run in isolation).
 *
 * @param mixed $doc Candidate document (e.g. rebuilt from the admin form POST).
 * @return bool True on a real change; false when refused or identical.
 */
function sn_resume_doc_save( $doc ) {
	$doc = sn_resume_doc_normalize( $doc );
	if ( null === $doc ) {
		return false;
	}
	// Stamp AFTER normalize (normalize drops unknown keys, 'updated' included).
	$doc['updated'] = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );

	$stored   = get_option( SN_RESUME_DOC_OPTION );
	$prev     = is_array( $stored ) ? $stored : array();
	unset( $prev['updated'] );
	$next     = $doc;
	unset( $next['updated'] );
	if ( $prev === $next ) {
		return false; // Identical content — keep the old stamp, skip the sync.
	}

	$result = update_option( SN_RESUME_DOC_OPTION, $doc, false ); // autoload=no: admin + sync reads only.

	// Regenerate the /resume Page body on every real save (engine loaded after
	// this file on a live request; absent in the isolated data-layer harness).
	if ( $result && function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
	}

	return $result;
}
