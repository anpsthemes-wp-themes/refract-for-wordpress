<?php
/**
 * A response from Refract.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Status, decoded JSON body and headers of one Refract response.
 */
final class Refract_Response {

	/**
	 * The keys of Refract's documents that hold links the admin screens use as an href or an image src.
	 */
	private const URL_KEYS = array( 'url', 'thumb_url', 'edit_url', 'permalink' );

	/**
	 * Builds a response.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<mixed>          $body    Decoded JSON body ([] when empty or not JSON).
	 * @param array<string, string> $headers Response headers, lowercase names.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body,
		public readonly array $headers = array()
	) {
	}

	/**
	 * Whether the status is 2xx.
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * The first JSON:API error code, the OAuth `error`, or ''.
	 */
	public function error_code(): string {
		$first = $this->first_error();

		if ( isset( $first['code'] ) && is_string( $first['code'] ) ) {
			return $first['code'];
		}

		return isset( $this->body['error'] ) && is_string( $this->body['error'] ) ? $this->body['error'] : '';
	}

	/**
	 * What to show the person: Refract's error detail, its title, the OAuth description, or a plain fallback.
	 */
	public function error_message(): string {
		$first = $this->first_error();

		foreach ( array( $first['detail'] ?? null, $first['title'] ?? null, $this->body['error_description'] ?? null, $this->body['message'] ?? null ) as $message ) {
			if ( is_string( $message ) && '' !== $message ) {
				return $message;
			}
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Refract couldn\'t do that (error %d). Try again in a minute.', 'refract' ), $this->status );
	}

	/**
	 * The error as a WP_Error for the admin REST routes, keeping Refract's status for 4xx (so 422 stays 422).
	 */
	public function to_error(): WP_Error {
		$code = $this->error_code();

		return new WP_Error(
			'' === $code ? 'refract_error' : 'refract_' . $code,
			$this->error_message(),
			array(
				'status'  => $this->status >= 400 && $this->status < 500 ? $this->status : 502,
				'refract' => $this->body,
			)
		);
	}

	/**
	 * One header, or ''.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}

	/**
	 * A link Refract sent, only when it's an http(s) URL: anything else (a javascript: link, a bad value) is null, so it
	 * never reaches an href or a src.
	 *
	 * @param mixed $value The link.
	 */
	public static function safe_url( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$url = esc_url_raw( $value, array( 'http', 'https' ) );

		return '' === $url ? null : $url;
	}

	/**
	 * Refract's document with every link in it (URL_KEYS, at any depth) passed through safe_url().
	 *
	 * @param array<mixed> $data The document.
	 * @return array<mixed>
	 */
	public static function with_safe_urls( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::with_safe_urls( $value );
			} elseif ( is_string( $key ) && in_array( $key, self::URL_KEYS, true ) ) {
				$data[ $key ] = self::safe_url( $value );
			}
		}

		return $data;
	}

	/**
	 * The first JSON:API error object.
	 *
	 * @return array<mixed>
	 */
	private function first_error(): array {
		$errors = $this->body['errors'] ?? null;

		return is_array( $errors ) && isset( $errors[0] ) && is_array( $errors[0] ) ? $errors[0] : array();
	}
}
