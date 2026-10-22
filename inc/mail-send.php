<?php
/**
 * Signal & Noise Tools: the one way the plugin sends an email.
 *
 * Every plugin email goes out as HTML with a plain-text alternative, through
 * core's wp_mail(). Core has no alt-body argument, so sn_mail_send_html() sets
 * PHPMailer's AltBody from phpmailer_init for that one send and removes the
 * hook in a finally block, before any other email can pick it up.
 *
 * The HTML builders below are pure: a document shell (lang="en", inline
 * styles, since mail clients drop stylesheets), a labeled-rows table, and a
 * reader that turns the plugin's structured plain text ("Label: value" lines,
 * indented rows under a heading, "- " items) into the same facts as HTML, so
 * the two parts cannot say different things.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/mail-text.php';

/**
 * Send one email as HTML with a plain-text alternative.
 *
 * @param string $to      Recipient.
 * @param string $subject Subject.
 * @param string $html    HTML document.
 * @param string $text    Plain-text part.
 * @return bool What wp_mail() returned. A throwing mailer still propagates.
 */
function sn_mail_send_html( $to, $subject, $html, $text ) {
	$alt = static function ( $mailer ) use ( $text ) {
		$mailer->AltBody = (string) $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
	};
	add_action( 'phpmailer_init', $alt );
	try {
		return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	} finally {
		remove_action( 'phpmailer_init', $alt );
	}
}

/**
 * Send structured plain text, with its HTML read from the same text.
 *
 * @param string $to      Recipient.
 * @param string $subject Subject, also the document title.
 * @param string $text    Plain-text body.
 * @return bool
 */
function sn_mail_send_text( $to, $subject, $text ) {
	return sn_mail_send_html( $to, $subject, sn_mail_html_doc( $subject, sn_mail_text_to_html( $text ) ), $text );
}

/**
 * The document shell every HTML part shares.
 *
 * @param string $title Plain-text title.
 * @param string $body  Body HTML, already escaped.
 * @return string
 */
function sn_mail_html_doc( $title, $body ) {
	return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title></head>'
		. '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:40em;">'
		. $body . "</body></html>\n";
}

/** A link with a readable label, never the raw URL as its text. */
function sn_mail_link_html( $label, $url ) {
	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
}

/**
 * Labeled lines as a two-column table: the label is a row header.
 *
 * @param array<int,array{0:string,1:string}> $rows Label, value HTML (already escaped).
 * @return string '' for no rows.
 */
function sn_mail_rows_html( array $rows ) {
	if ( array() === $rows ) {
		return '';
	}
	$out = '<table style="border-collapse:collapse;margin:0 0 16px;">';
	foreach ( $rows as $r ) {
		$out .= '<tr><th scope="row" style="text-align:left;vertical-align:top;padding:2px 16px 2px 0;font-weight:600;white-space:nowrap;">' . esc_html( $r[0] ) . '</th>'
			. '<td style="vertical-align:top;padding:2px 0;">' . $r[1] . '</td></tr>';
	}
	return $out . '</table>';
}

/**
 * The plugin's structured plain text as HTML. A line underlined with "=" is
 * the title; a plain line followed by indented lines is a heading; "Label:
 * value" lines are table rows, and a row whose value is only a URL becomes a
 * link named by its label; "- " lines are list items; anything else is a
 * paragraph with its URLs as short links.
 *
 * @param string $text Plain text.
 * @return string Escaped HTML.
 */
function sn_mail_text_to_html( $text ) {
	$lines = explode( "\n", str_replace( "\r", '', (string) $text ) );
	$out   = '';
	$rows  = array();
	$items = array();
	$flush = static function () use ( &$out, &$rows, &$items ) {
		$out  .= sn_mail_rows_html( $rows ) . ( $items ? '<ul>' . implode( '', $items ) . '</ul>' : '' );
		$rows  = array();
		$items = array();
	};
	$count = count( $lines );
	for ( $i = 0; $i < $count; $i++ ) {
		$line = rtrim( $lines[ $i ] );
		$bare = trim( $line );
		$next = $lines[ $i + 1 ] ?? '';
		if ( '' === $bare ) {
			$flush();
			continue;
		}
		if ( preg_match( '/^=+$/', trim( $next ) ) ) {
			$flush();
			$out .= '<h1 style="font-size:20px;margin:0 0 12px;">' . esc_html( $bare ) . '</h1>';
			++$i;
			continue;
		}
		if ( preg_match( '/^-\s+(.+)$/', $bare, $m ) ) {
			$items[] = '<li>' . sn_mail_html_links( $m[1] ) . '</li>';
			continue;
		}
		if ( preg_match( '/^([^:.!?]{1,48}):\s+(\S.*)$/', $bare, $m ) && ! preg_match( '~^https?$~i', $m[1] ) ) {
			if ( preg_match( '~^https?://\S+$~i', $m[2] ) ) {
				$flush();
				$out .= '<p>' . sn_mail_link_html( $m[1], $m[2] ) . '</p>';
				continue;
			}
			$rows[] = array( $m[1], sn_mail_html_links( $m[2] ) );
			continue;
		}
		$flush();
		if ( $line === $bare && preg_match( '/^\s+\S/', $next ) ) {
			$out .= '<h2 style="font-size:17px;margin:20px 0 8px;">' . esc_html( rtrim( $bare, ':' ) ) . '</h2>';
			continue;
		}
		$out .= '<p>' . sn_mail_html_links( $bare ) . '</p>';
	}
	$flush();
	return $out;
}
