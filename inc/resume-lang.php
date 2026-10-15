<?php
/**
 * Signal & Noise Tools: the résumé's render language. The page builder
 * (inc/resume-sync-engine.php) and the PDF template ask it for their fixed
 * headings; inc/resume-es.php switches it to Spanish around the /cv/ and
 * JuanLentino_CV.pdf renders and back. English is the default, so /resume and
 * the English PDF render exactly as before.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sn_resume_lang' ) ) {
	/**
	 * The language being rendered: 'en' (default) or 'es'.
	 *
	 * @param string|null $set Pass 'es' or 'en' to switch.
	 * @return string
	 */
	function sn_resume_lang( $set = null ) {
		static $lang = 'en';
		if ( null !== $set ) {
			$lang = 'es' === $set ? 'es' : 'en';
		}
		return $lang;
	}

	/**
	 * A fixed heading in the render language.
	 *
	 * @param string $en English.
	 * @param string $es Spanish (Argentine; owner, 2026-10-15).
	 * @return string
	 */
	function sn_resume_l( $en, $es ) {
		return 'es' === sn_resume_lang() ? $es : $en;
	}
}
