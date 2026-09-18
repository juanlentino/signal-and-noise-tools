<?php
/**
 * A stub of Connector for TypeSafe Jev (juanlentino/jev-connector) for the
 * plugin's tests: the key reads a test switch, ask() records the call and
 * answers from $GLOBALS['__j']['answer'] the way the connector would (a
 * WP_Error carrying the HTTP status in its data, or a Response).
 */
namespace JevConnector;

final class Connector {
	const SETTING_NAME = 'connectors_typesafe_api_key';
	public static function get_api_key(): string {
		return (string) ( $GLOBALS['__j']['opt']['sn_typesafe_api_key'] ?? '' );
	}
}

final class Response {
	private array $data;
	public function __construct( array $data ) { $this->data = $data; }
	public function to_array(): array { return $this->data; }
}

/** The cache: a hit when the test sets $GLOBALS['__j']['cache']. */
final class Cache {
	public static function get( array $payload ): ?Response {
		return ! empty( $GLOBALS['__j']['cache'] ) ? new Response( array() ) : null;
	}
}

function ask( $state, array $questions, array $args = array() ) {
	$GLOBALS['__j']['calls'][] = array( 'state' => $state, 'questions' => $questions, 'model' => (string) ( $args['model'] ?? '' ) );
	$a = $GLOBALS['__j']['answer'];
	$a = is_callable( $a ) ? $a( array( 'state' => $state, 'questions' => $questions ) ) : $a;
	$code = (int) ( $a['response']['code'] ?? 0 );
	if ( 200 !== $code ) {
		return new \WP_Error( 'jevc_http_' . $code, 'TypeSafe returned HTTP ' . $code . '.', array( 'status' => $code, 'detail' => json_decode( (string) ( $a['body'] ?? '' ), true ) ) );
	}
	$d = json_decode( (string) ( $a['body'] ?? '' ), true );
	return new Response( is_array( $d ) ? $d : array() );
}
