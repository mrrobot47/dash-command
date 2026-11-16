<?php

namespace EE\Tests\Dash;

use WpOrg\Requests\Exception;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Transport;

/**
 * Stands in for cURL behind WpOrg\Requests (and so EE\Utils\http_request()): records each request and answers from URL rules.
 *
 * Installed by replacing Requests' registered transports and clearing its transport cache; no product change needed.
 */
class FakeHttpTransport implements Transport {

	/** @var array[] Each: url, method, headers, data, json (decoded data). */
	public static $requests = [];

	private static $rules  = [];
	private static $backup = null;

	public static function install() {
		self::$requests = [];
		self::$rules    = [];
		self::$backup   = [ Requests::$transport, \EE\Tests\TestCase::get_static( Requests::class, 'transports' ) ];
		Requests::$transport = [];
		\EE\Tests\TestCase::set_static( Requests::class, 'transports', [ self::class => self::class ] );
	}

	public static function uninstall() {
		if ( null !== self::$backup ) {
			list( Requests::$transport, $transports ) = self::$backup;
			\EE\Tests\TestCase::set_static( Requests::class, 'transports', $transports );
			self::$backup = null;
		}
	}

	/**
	 * Answer requests whose URL matches $pattern; the latest matching rule wins. Unmatched requests get a 404.
	 *
	 * @param int|null $times Stop matching after this many uses; null = unlimited.
	 */
	public static function on( $pattern, $status, $body = '{}', $times = null ) {
		self::$rules[] = [ 'pattern' => $pattern, 'status' => (int) $status, 'body' => $body, 'error' => null, 'times' => $times ];
	}

	/** Make requests whose URL matches $pattern fail in the transport, like an unreachable host. */
	public static function fail( $pattern, $message = 'Could not resolve host' ) {
		self::$rules[] = [ 'pattern' => $pattern, 'status' => 0, 'body' => '', 'error' => $message, 'times' => null ];
	}

	/** @return array[] Recorded requests whose URL matches $pattern. */
	public static function matching( $pattern ) {
		return array_values(
			array_filter(
				self::$requests,
				function ( $request ) use ( $pattern ) {
					return (bool) preg_match( $pattern, $request['url'] );
				}
			)
		);
	}

	public function request( $url, $headers = [], $data = [], $options = [] ) {
		self::$requests[] = [
			'url'     => $url,
			'method'  => $options['type'],
			'headers' => $headers,
			'data'    => $data,
			'json'    => is_string( $data ) ? json_decode( $data, true ) : null,
		];
		for ( $i = count( self::$rules ) - 1; $i >= 0; $i-- ) {
			$rule = &self::$rules[ $i ];
			if ( 0 === $rule['times'] || ! preg_match( $rule['pattern'], $url ) ) {
				continue;
			}
			if ( null !== $rule['times'] ) {
				$rule['times']--;
			}
			if ( null !== $rule['error'] ) {
				throw new Exception( 'cURL error 6: ' . $rule['error'], 'curlerror' );
			}

			return "HTTP/1.1 {$rule['status']} Fake\r\nContent-Type: application/json\r\n\r\n{$rule['body']}";
		}

		return "HTTP/1.1 404 Not Found\r\nContent-Type: application/json\r\n\r\n{}";
	}

	public function request_multiple( $requests, $options ) {
		throw new \LogicException( 'FakeHttpTransport does not support parallel requests.' );
	}

	public static function test( $capabilities = [] ) {
		return true;
	}
}
