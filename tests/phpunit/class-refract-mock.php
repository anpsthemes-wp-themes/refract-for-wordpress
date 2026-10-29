<?php
/**
 * A fake Refract for the tests.
 *
 * @package Refract
 */

namespace Refract\WP\Tests;

use WP_Error;

/**
 * Answers HTTP requests to https://refract.test through `pre_http_request` and records them.
 */
final class Refract_Mock {

	/**
	 * Every request sent to Refract.
	 *
	 * @var list<array{method: string, url: string, path: string, headers: array<string, string>, body: mixed, args: array<string, mixed>}>
	 */
	public array $requests = array();

	/**
	 * Routes: method, path regex, and the answer (a response array, a WP_Error, or a callable returning one).
	 *
	 * @var list<array{method: string, pattern: string, answer: mixed, times: int|null}>
	 */
	private array $routes = array();

	/**
	 * Starts answering.
	 */
	public function __construct() {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Stops answering.
	 */
	public function stop(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/**
	 * Answers a route with JSON.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $pattern Path regex (without delimiters), matched against the URL path.
	 * @param int                  $status  HTTP status.
	 * @param array<mixed>         $body    JSON body.
	 * @param int|null             $times   How many times this answer is used (null = always).
	 * @param array<string,string> $headers Response headers.
	 */
	public function on( string $method, string $pattern, int $status, array $body = array(), ?int $times = null, array $headers = array() ): self {
		return $this->answer(
			$method,
			$pattern,
			array(
				'headers'  => $headers,
				'body'     => array() === $body ? '' : (string) wp_json_encode( $body ),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			),
			$times
		);
	}

	/**
	 * Answers a route with anything `pre_http_request` accepts, or a callable building it.
	 *
	 * @param string   $method  HTTP method.
	 * @param string   $pattern Path regex.
	 * @param mixed    $answer  The answer.
	 * @param int|null $times   How many times (null = always).
	 */
	public function answer( string $method, string $pattern, mixed $answer, ?int $times = null ): self {
		array_unshift(
			$this->routes,
			array(
				'method'  => $method,
				'pattern' => $pattern,
				'answer'  => $answer,
				'times'   => $times,
			)
		);

		return $this;
	}

	/**
	 * Fails every request to a route as if Refract couldn't be reached.
	 *
	 * @param string $method  HTTP method.
	 * @param string $pattern Path regex.
	 */
	public function unreachable( string $method, string $pattern ): self {
		return $this->answer( $method, $pattern, new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	}

	/**
	 * The `pre_http_request` filter.
	 *
	 * @param mixed                $pre  The short-circuit value.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  The URL.
	 * @return mixed
	 */
	public function handle( mixed $pre, array $args, string $url ): mixed {
		if ( ! str_starts_with( $url, 'https://refract.test' ) ) {
			return $pre;
		}

		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body    = $args['body'] ?? null;
		$decoded = is_string( $body ) ? json_decode( $body, true ) : $body;

		$this->requests[] = array(
			'method'  => (string) $args['method'],
			'url'     => $url,
			'path'    => $path,
			'headers' => (array) $args['headers'],
			'body'    => $decoded,
			'args'    => $args,
		);

		foreach ( $this->routes as $index => $route ) {
			if ( $route['method'] !== $args['method'] || 1 !== preg_match( '#^' . $route['pattern'] . '$#', $path ) ) {
				continue;
			}

			if ( null !== $route['times'] ) {
				if ( $route['times'] <= 0 ) {
					continue;
				}

				--$this->routes[ $index ]['times'];
			}

			return is_callable( $route['answer'] ) ? call_user_func( $route['answer'], $args, $url ) : $route['answer'];
		}

		return new WP_Error( 'http_request_failed', 'No mock for ' . $args['method'] . ' ' . $path );
	}

	/**
	 * The requests to a path, optionally by method.
	 *
	 * @param string      $pattern Path regex.
	 * @param string|null $method  HTTP method.
	 * @return list<array{method: string, url: string, path: string, headers: array<string, string>, body: mixed, args: array<string, mixed>}>
	 */
	public function sent( string $pattern, ?string $method = null ): array {
		return array_values(
			array_filter(
				$this->requests,
				static fn ( array $request ): bool => 1 === preg_match( '#^' . $pattern . '$#', $request['path'] ) && ( null === $method || $method === $request['method'] )
			)
		);
	}
}
