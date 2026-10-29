<?php
/**
 * Plugin Name: Refract mock (e2e)
 * Description: A fake Refract for the Playwright tests. Only mapped into wp-env's tests environment.
 *
 * Points the plugin at `{tests site}/refract-mock` and answers its server-side calls through `pre_http_request`,
 * serves a consent page at `/refract-mock/oauth/authorize`, and keeps everything in options the tests reset through
 * the `refract-mock/v1` REST routes. Calls from "Refract" into the plugin (posts, webhook events) are signed with the
 * signing secret the plugin sent when it registered the site, and handed to the tests to deliver over HTTP.
 *
 * @package Refract
 */

// The PHPUnit suite runs in the same container (its test library defines tests_add_filter()) and has its own fake.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

define( 'REFRACT_MOCK_PATH', '/refract-mock' );

if ( ! defined( 'REFRACT_URL' ) ) {
	define( 'REFRACT_URL', 'http://localhost:8889' . REFRACT_MOCK_PATH );
}

/**
 * The fake Refract.
 */
final class Refract_Mock {

	/**
	 * Option with the mock's state: OAuth clients, codes, tokens, the registered site, the posts.
	 */
	const STATE = 'refract_mock_state';

	/**
	 * Option with the requests the plugin sent (kept apart from the state, so a logged GET never overwrites a change).
	 */
	const LOG = 'refract_mock_log';

	/**
	 * Option with signed requests for the tests to deliver to the plugin.
	 */
	const OUTBOX = 'refract_mock_outbox';

	/**
	 * Host the images of posts "from Refract" come from.
	 */
	const IMAGE_HOST = 'https://media.refract.test';

	/**
	 * The workspace.
	 */
	const WORKSPACE = array(
		'id'   => 'ws-kolo',
		'name' => 'Kolo Coffee',
		'slug' => 'kolo',
	);

	/**
	 * The site's account id in Refract.
	 */
	const SITE_ACCOUNT = 'acc-wp-kolo';

	/**
	 * Starts answering.
	 */
	public static function boot(): void {
		add_filter( 'pre_http_request', array( self::class, 'http' ), 1, 3 );
		add_action( 'init', array( self::class, 'pages' ), 0 );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * The accounts of the workspace, the way Refract lists them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function accounts(): array {
		return array(
			array( 'acc-ig-kolo', 'instagram', 'Instagram', 'Kolo Coffee', '@kolocoffee', true, 'available' ),
			array( 'acc-ig-roastery', 'instagram', 'Instagram', 'Kolo Roastery', '@kolo.roastery', false, 'available' ),
			array( 'acc-fb-kolo', 'facebook', 'Facebook', 'Kolo Coffee', 'kolocoffee', false, 'available' ),
			array( 'acc-li-kolo', 'linkedin', 'LinkedIn', 'Kolo Coffee Roasters', 'kolo-coffee', false, 'available' ),
			array( 'acc-bsky-kolo', 'bluesky', 'Bluesky', 'Kolo Coffee', '@kolo.coffee', false, 'available' ),
			array( 'acc-threads-kolo', 'threads', 'Threads', 'Kolo Coffee', '@kolocoffee', false, 'beta' ),
			array( 'acc-gbp-kolo', 'google_business', 'Google Business Profile', 'Kolo Coffee Ljubljana', '', false, 'soon' ),
			array( self::SITE_ACCOUNT, 'wordpress', 'WordPress', 'Kolo Coffee', 'localhost:8889', false, 'available' ),
		);
	}

	/**
	 * One account, as in the versions document.
	 *
	 * @param string $id Account id.
	 * @return array<string, mixed>|null
	 */
	public static function account( string $id ): ?array {
		foreach ( self::accounts() as $row ) {
			if ( $row[0] === $id ) {
				return array(
					'id'            => $row[0],
					'network'       => $row[1],
					'network_label' => $row[2],
					'name'          => $row[3],
					'handle'        => $row[4],
					'main'          => $row[5],
					'availability'  => $row[6],
				);
			}
		}

		return null;
	}

	/**
	 * The state.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		wp_cache_delete( self::STATE, 'options' );
		$state = get_option( self::STATE );

		return is_array( $state ) ? $state : array(
			'clients' => array(),
			'codes'   => array(),
			'tokens'  => array(),
			'site'    => null,
			'posts'   => array(),
			'keys'    => array(),
			'options' => array(),
		);
	}

	/**
	 * Saves the state.
	 *
	 * @param array<string, mixed> $state The state.
	 */
	public static function save( array $state ): void {
		update_option( self::STATE, $state, false );
	}

	/**
	 * Remembers a request.
	 *
	 * @param array<string, mixed> $entry The request.
	 */
	public static function log( array $entry ): void {
		wp_cache_delete( self::LOG, 'options' );
		$log   = get_option( self::LOG );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		update_option( self::LOG, array_slice( $log, -200 ), false );
	}

	/**
	 * The `pre_http_request` filter: answers calls to the mock Refract and to its image host.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  The URL.
	 * @return mixed
	 */
	public static function http( $pre, $args, $url ) {
		if ( str_starts_with( $url, self::IMAGE_HOST . '/' ) ) {
			return self::image( $args, $url );
		}

		if ( ! str_starts_with( $url, REFRACT_URL . '/' ) ) {
			return $pre;
		}

		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$path    = substr( (string) wp_parse_url( $url, PHP_URL_PATH ), strlen( (string) wp_parse_url( REFRACT_URL, PHP_URL_PATH ) ) );
		$headers = array_change_key_case( (array) ( $args['headers'] ?? array() ), CASE_LOWER );
		$raw     = $args['body'] ?? null;
		$body    = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$body    = is_array( $body ) ? $body : array();

		if ( 'GET' !== $method ) {
			self::log(
				array(
					'method'          => $method,
					'path'            => $path,
					'body'            => $body,
					'idempotency_key' => $headers['idempotency-key'] ?? null,
					'time'            => time(),
				)
			);
		}

		if ( str_starts_with( $path, '/oauth/' ) ) {
			return self::oauth( $method, $path, $body );
		}

		if ( ! str_starts_with( $path, '/api/v1/' ) ) {
			return self::json( 404, self::error( 404, 'not_found', 'Not found.' ) );
		}

		$token = preg_replace( '/^Bearer\s+/i', '', (string) ( $headers['authorization'] ?? '' ) );
		$state = self::state();

		if ( ! str_starts_with( $token, 'rf_live_' ) && ! isset( $state['tokens'][ $token ] ) ) {
			return self::json( 401, self::error( 401, 'unauthenticated', 'Unauthenticated.' ) );
		}

		return self::api( $method, substr( $path, strlen( '/api/v1/' ) ), $body, $headers );
	}

	/**
	 * `/oauth/register` and `/oauth/token`.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $body   Body.
	 * @return array<string, mixed>
	 */
	private static function oauth( string $method, string $path, array $body ): array {
		$state = self::state();

		if ( 'POST' === $method && '/oauth/register' === $path ) {
			$id                      = 'client-' . wp_generate_password( 12, false );
			$state['clients'][ $id ] = array(
				'name'          => (string) ( $body['client_name'] ?? '' ),
				'redirect_uris' => (array) ( $body['redirect_uris'] ?? array() ),
			);
			self::save( $state );

			return self::json(
				201,
				array(
					'client_id'     => $id,
					'client_name'   => $state['clients'][ $id ]['name'],
					'redirect_uris' => $state['clients'][ $id ]['redirect_uris'],
				)
			);
		}

		if ( 'POST' !== $method || '/oauth/token' !== $path ) {
			return self::json( 404, array( 'error' => 'not_found' ) );
		}

		$grant = (string) ( $body['grant_type'] ?? '' );

		if ( 'authorization_code' === $grant ) {
			$code = $state['codes'][ (string) ( $body['code'] ?? '' ) ] ?? null;
			unset( $state['codes'][ (string) ( $body['code'] ?? '' ) ] );
			$challenge = rtrim( strtr( base64_encode( hash( 'sha256', (string) ( $body['code_verifier'] ?? '' ), true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			if ( ! is_array( $code ) || $code['client_id'] !== ( $body['client_id'] ?? '' ) || $code['redirect_uri'] !== ( $body['redirect_uri'] ?? '' ) || $code['challenge'] !== $challenge ) {
				self::save( $state );

				return self::json( 400, array( 'error' => 'invalid_grant' ) );
			}
		} elseif ( 'refresh_token' === $grant ) {
			if ( ! in_array( (string) ( $body['refresh_token'] ?? '' ), $state['refresh'] ?? array(), true ) ) {
				return self::json( 400, array( 'error' => 'invalid_grant' ) );
			}
		} else {
			return self::json( 400, array( 'error' => 'unsupported_grant_type' ) );
		}

		$access                      = 'mock-access-' . wp_generate_password( 24, false );
		$refresh                     = 'mock-refresh-' . wp_generate_password( 24, false );
		$state['tokens'][ $access ]  = true;
		$state['refresh']            = array_merge( $state['refresh'] ?? array(), array( $refresh ) );
		self::save( $state );

		return self::json(
			200,
			array(
				'token_type'    => 'Bearer',
				'expires_in'    => 86400,
				'access_token'  => $access,
				'refresh_token' => $refresh,
			)
		);
	}

	/**
	 * The API.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path under /api/v1/.
	 * @param array<string, mixed> $body    Body.
	 * @param array<string, mixed> $headers Request headers, lowercase.
	 * @return array<string, mixed>
	 */
	private static function api( string $method, string $path, array $body, array $headers ): array {
		$state = self::state();
		$ws    = self::WORKSPACE['id'];

		if ( 'GET' === $method && 'me' === $path ) {
			return self::json(
				200,
				array(
					'data' => array(
						'id'         => 'usr-ana',
						'type'       => 'users',
						'attributes' => array(
							'name'  => 'Ana Kovač',
							'email' => 'ana@kolo.coffee',
						),
					),
					'meta' => array(
						'access'  => array(
							'organization' => array(
								'name' => 'Kolo Coffee',
								'plan' => 'Business',
							),
						),
						'credits' => array(
							'left'      => 184,
							'refill_on' => gmdate( 'Y-m-01', strtotime( 'first day of next month' ) ),
							'own_key'   => false,
							'buy_url'   => REFRACT_URL . '/billing/credits',
						),
					),
				)
			);
		}

		if ( 'DELETE' === $method && 'me/token' === $path ) {
			return self::json( 204, array() );
		}

		if ( 'GET' === $method && 'workspaces' === $path ) {
			return self::json(
				200,
				array(
					'data' => array(
						array(
							'id'         => $ws,
							'type'       => 'workspaces',
							'attributes' => array(
								'name'                => self::WORKSPACE['name'],
								'slug'                => self::WORKSPACE['slug'],
								'timezone'            => 'Europe/Ljubljana',
								'role'                => 'admin',
								'posts_need_approval' => false,
							),
						),
					),
				)
			);
		}

		if ( 1 !== preg_match( '#^workspaces/' . preg_quote( $ws, '#' ) . '/(.+)$#', $path, $match ) ) {
			return self::json( 404, self::error( 404, 'not_found', 'That workspace doesn’t exist.' ) );
		}

		$rest = $match[1];

		if ( 'GET' === $method && 'accounts' === $rest ) {
			$data = array();

			foreach ( self::accounts() as $row ) {
				$data[] = array(
					'id'         => $row[0],
					'type'       => 'accounts',
					'attributes' => array(
						'platform'     => $row[1],
						'label'        => $row[2],
						'name'         => $row[3],
						'handle'       => $row[4],
						'main'         => $row[5],
						'availability' => $row[6],
						'avatar_url'   => null,
						'health'       => 'ok',
					),
				);
			}

			return self::json( 200, array( 'data' => $data ) );
		}

		if ( 'POST' === $method && 'accounts/wordpress' === $rest ) {
			$is_new        = null === $state['site'];
			$state['site'] = array(
				'account_id'     => self::SITE_ACCOUNT,
				'signing_secret' => (string) ( $body['signing_secret'] ?? '' ),
				'rest_url'       => (string) ( $body['rest_url'] ?? '' ),
				'events_url'     => (string) ( $body['events_url'] ?? '' ),
				'site_name'      => (string) ( $body['site_name'] ?? '' ),
			);
			self::save( $state );

			return self::json(
				$is_new ? 201 : 200,
				array(
					'account_id' => self::SITE_ACCOUNT,
					'handle'     => (string) wp_parse_url( (string) ( $body['site_url'] ?? '' ), PHP_URL_HOST ) . ( wp_parse_url( (string) ( $body['site_url'] ?? '' ), PHP_URL_PORT ) ? ':' . wp_parse_url( (string) ( $body['site_url'] ?? '' ), PHP_URL_PORT ) : '' ),
					'workspace'  => self::WORKSPACE,
				)
			);
		}

		if ( 'DELETE' === $method && 'accounts/wordpress/' . self::SITE_ACCOUNT === $rest ) {
			$state['site'] = null;
			self::save( $state );

			return self::json( 204, array() );
		}

		if ( 'POST' === $method && 'notes' === $rest ) {
			$key = (string) ( $headers['idempotency-key'] ?? '' );

			if ( '' === $key ) {
				return self::json( 422, self::error( 422, 'invalid', 'The Idempotency-Key header is required.' ) );
			}

			if ( isset( $state['keys'][ $key ] ) ) {
				return self::json( 202, $state['keys'][ $key ], array( 'idempotent-replayed' => 'true' ) );
			}

			$response               = self::create_post( $state, $body );
			$state['keys'][ $key ]  = $response;
			self::save( $state );

			return self::json( 202, $response );
		}

		if ( 1 === preg_match( '#^notes/([^/]+)$#', $rest, $note ) && 'PATCH' === $method ) {
			if ( ! isset( $state['posts'][ $note[1] ] ) ) {
				return self::json( 404, self::error( 404, 'not_found', 'That post doesn’t exist.' ) );
			}

			$state['posts'][ $note[1] ]['source'] = array_merge( $state['posts'][ $note[1] ]['source'], (array) ( $body['source'] ?? array() ) );
			self::save( $state );

			return self::json( 202, self::created( $state['posts'][ $note[1] ] ) );
		}

		if ( 1 !== preg_match( '#^posts/([^/]+)/(versions|approve-all|versions/([^/]+)/approve)$#', $rest, $post_match ) ) {
			return self::json( 404, self::error( 404, 'not_found', 'Not found.' ) );
		}

		$id = $post_match[1];

		if ( ! isset( $state['posts'][ $id ] ) ) {
			return self::json( 404, self::error( 404, 'not_found', 'That post doesn’t exist.' ) );
		}

		$post = $state['posts'][ $id ];

		if ( 'GET' === $method && 'versions' === $post_match[2] ) {
			return self::json( 200, self::document( $post ) );
		}

		if ( 'POST' === $method && 'approve-all' === $post_match[2] ) {
			$when = gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * HOUR_IN_SECONDS );

			foreach ( $post['versions'] as $account_id => $version ) {
				if ( in_array( $version['status'], array( 'needs_ok', 'edited', 'approved' ), true ) ) {
					$post['versions'][ $account_id ]['status']        = 'scheduled';
					$post['versions'][ $account_id ]['scheduled_for'] = $when;
				}
			}

			$post['status']           = 'scheduled';
			$state['posts'][ $id ]    = $post;
			self::save( $state );
			self::events_after_approval( $post, $when );

			return self::json(
				200,
				self::document( $post ) + array(
					'queued'      => true,
					'waiting_for' => null,
					'skipped'     => array(),
				)
			);
		}

		if ( 'POST' === $method && isset( $post_match[3] ) ) {
			$found = false;

			foreach ( $post['versions'] as $account_id => $version ) {
				if ( $version['id'] !== $post_match[3] ) {
					continue;
				}

				if ( in_array( $version['status'], array( 'writing', 'failed' ), true ) ) {
					return self::json( 409, self::error( 409, 'not_approvable', 'This version is still being written.' ) );
				}

				$post['versions'][ $account_id ]['status'] = 'approved';
				$found                                     = true;
			}

			if ( ! $found ) {
				return self::json( 404, self::error( 404, 'not_found', 'That version doesn’t exist.' ) );
			}

			$state['posts'][ $id ] = $post;
			self::save( $state );

			return self::json( 200, self::document( $post ) );
		}

		return self::json( 405, self::error( 405, 'method_not_allowed', 'Method not allowed.' ) );
	}

	/**
	 * Creates a draft with a version per account (writing), from a note request.
	 *
	 * @param array<string, mixed> $state The state, changed in place.
	 * @param array<string, mixed> $body  The note request.
	 * @return array<string, mixed> The 202 body.
	 */
	private static function create_post( array &$state, array $body ): array {
		$id         = 'post-' . strtolower( wp_generate_password( 10, false ) );
		$start_from = 'finished' === ( $body['start_from'] ?? '' ) ? 'finished' : 'note';
		$versions   = array();

		foreach ( (array) ( $body['accounts'] ?? array() ) as $account_id ) {
			$account = self::account( (string) $account_id );

			if ( null === $account ) {
				continue;
			}

			$versions[ $account['id'] ] = array(
				'id'            => 'finished' === $start_from ? null : 'ver-' . substr( $id, 5 ) . '-' . $account['network'],
				'status'        => 'finished' === $start_from ? 'needs_ok' : 'writing',
				'scheduled_for' => null,
				'permalink'     => null,
				'clicks'        => 0,
			);
		}

		$post                  = array(
			'id'         => $id,
			'status'     => 'draft',
			'start_from' => $start_from,
			'source'     => (array) ( $body['source'] ?? array() ),
			'media'      => (array) ( $body['media'] ?? array() ),
			'versions'   => $versions,
		);
		$state['posts'][ $id ] = $post;

		return self::created( $post );
	}

	/**
	 * The 202 body for a post.
	 *
	 * @param array<string, mixed> $post The post.
	 * @return array<string, mixed>
	 */
	private static function created( array $post ): array {
		$summary  = array();
		$networks = array();

		foreach ( $post['versions'] as $account_id => $version ) {
			$account                         = self::account( $account_id );
			$networks[ $account['network'] ] = true;
			$summary[]                       = array(
				'id'         => $version['id'],
				'account_id' => $account_id,
				'network'    => $account['network'],
				'status'     => $version['status'],
			);
		}

		return array(
			'post_id'       => $post['id'],
			'status'        => 'draft',
			'start_from'    => $post['start_from'],
			'versions'      => $summary,
			'credits_held'  => 'finished' === $post['start_from'] ? 0 : count( $networks ),
			'media_credits' => 0,
			'media_skipped' => array(),
			'edit_url'      => REFRACT_URL . '/w/kolo/posts/' . $post['id'],
		);
	}

	/**
	 * The versions document of a post.
	 *
	 * @param array<string, mixed> $post The post.
	 * @return array<string, mixed>
	 */
	private static function document( array $post ): array {
		$versions = array();
		$title    = (string) ( $post['source']['title'] ?? '' );
		$url      = (string) ( $post['source']['url'] ?? '' );

		foreach ( $post['versions'] as $account_id => $version ) {
			$account = self::account( $account_id );
			$written = 'writing' !== $version['status'];
			$media   = array();

			foreach ( array_slice( $post['media'], 0, 'instagram' === $account['network'] ? 3 : 1 ) as $item ) {
				$media[] = array(
					'url'       => (string) ( $item['url'] ?? '' ),
					'thumb_url' => (string) ( $item['url'] ?? '' ),
					'width'     => 'instagram' === $account['network'] ? 1080 : 1200,
					'height'    => 'instagram' === $account['network'] ? 1350 : 630,
					'alt'       => (string) ( $item['alt'] ?? '' ),
					'type'      => 'image',
				);
			}

			$versions[] = array(
				'id'             => $version['id'],
				'account'        => $account,
				'status'         => $version['status'],
				'text'           => $written ? self::text( $account['network'], $title, $url, 'finished' === $post['start_from'] ) : '',
				'title'          => null,
				'media'          => $written ? $media : array(),
				'checks'         => $written ? array(
					array(
						'level'   => 'pass',
						'code'    => 'length',
						'message' => 'Fits the length limit.',
					),
				) : array(),
				'rules_followed' => array(),
				'stale'          => null,
				'scheduled_for'  => $version['scheduled_for'],
				'permalink'      => $version['permalink'],
				'short_link'     => 'posted' === $version['status'] ? 'https://rfr.ac/' . substr( (string) $version['id'], -8 ) : null,
				'clicks'         => $version['clicks'],
				'error'          => null,
				'approvable'     => $written && null !== $version['id'],
				'edit_url'       => REFRACT_URL . '/w/kolo/posts/' . $post['id'] . '?account=' . $account_id,
			);
		}

		return array(
			'post_id'     => $post['id'],
			'post_status' => $post['status'],
			'start_from'  => $post['start_from'],
			'source'      => array(
				'type'        => 'wordpress',
				'account_id'  => (string) ( $post['source']['account_id'] ?? '' ),
				'external_id' => (string) ( $post['source']['external_id'] ?? '' ),
				'url'         => $url,
				'title'       => $title,
			),
			'can_publish' => true,
			'approvers'   => array(),
			'edit_url'    => REFRACT_URL . '/w/kolo/posts/' . $post['id'],
		) + array( 'versions' => $versions );
	}

	/**
	 * A version's text, per network.
	 *
	 * @param string $network  The network.
	 * @param string $title    The post's title.
	 * @param string $url      The post's link.
	 * @param bool   $finished The same post for every account.
	 */
	private static function text( string $network, string $title, string $url, bool $finished ): string {
		if ( $finished ) {
			return $title . ' ' . $url;
		}

		$texts = array(
			'instagram' => "Our autumn Huila just landed ☕\n\nRed apple, panela and a soft orange finish. We roasted it light on Tuesday, so it's at its best as a pour-over this week.\n\nIn the shop and online from today. Link in bio.",
			'facebook'  => "The autumn Huila is here. It comes from the Gómez family's farm near Pitalito, and we roasted it light so the red apple and panela come through. Try it as a pour-over: 15 g to 250 g of water at 94 °C.\n\nThe tasting notes and brew guide: " . $url,
			'linkedin'  => "Every autumn we buy from the same three farms in Huila, Colombia. This year's lot is the sweetest we've had: red apple, panela, orange.\n\nPaying above the market price for six years has meant better coffee every year. Here's what changed: " . $url,
			'bluesky'   => 'The autumn Huila is in. Red apple, panela, a soft orange finish. Light roast, best as a pour-over. ' . $url,
			'threads'   => "New coffee day ☕ The autumn Huila is in: red apple, panela and orange. What's your go-to brew for a light roast?",
		);

		return $texts[ $network ] ?? $title . ' ' . $url;
	}

	/**
	 * Queues the webhook events Refract sends after "Approve all": one approval and the queue.
	 *
	 * @param array<string, mixed> $post The post.
	 * @param string               $when When the versions go out.
	 */
	private static function events_after_approval( array $post, string $when ): void {
		$accounts = array();

		foreach ( array_keys( $post['versions'] ) as $account_id ) {
			$account    = self::account( $account_id );
			$accounts[] = array(
				'account'       => array(
					'id'       => $account['id'],
					'platform' => $account['network'],
					'handle'   => $account['handle'],
					'name'     => $account['name'],
				),
				'scheduled_for' => $when,
			);
		}

		self::event(
			'post.version_approved',
			$post,
			array(
				'version'     => array(
					'id'          => reset( $post['versions'] )['id'],
					'network'     => self::account( (string) array_key_first( $post['versions'] ) )['network'],
					'status'      => 'approved',
					'text'        => '',
					'account_ids' => array_keys( $post['versions'] ),
				),
				'approved_by' => array(
					'id'   => 'usr-ana',
					'name' => 'Ana Kovač',
				),
			)
		);
		self::event( 'post.queued', $post, array( 'accounts' => $accounts ) );
	}

	/**
	 * Queues one webhook event for the tests to deliver.
	 *
	 * @param string               $type The event type.
	 * @param array<string, mixed> $post The post.
	 * @param array<string, mixed> $data The data besides `post`.
	 */
	private static function event( string $type, array $post, array $data ): void {
		self::queue(
			'events',
			array(
				'id'         => 'evt_' . wp_generate_password( 16, false ),
				'type'       => $type,
				'created_at' => gmdate( 'c' ),
				'workspace'  => self::WORKSPACE,
				'data'       => array(
					'post' => array(
						'id'           => $post['id'],
						'status'       => $post['status'],
						'text'         => '',
						'scheduled_at' => null,
						'url'          => REFRACT_URL . '/w/kolo/posts/' . $post['id'],
						'source'       => array(
							'type'        => 'wordpress',
							'account_id'  => (string) ( $post['source']['account_id'] ?? '' ),
							'external_id' => (string) ( $post['source']['external_id'] ?? '' ),
							'url'         => (string) ( $post['source']['url'] ?? '' ),
							'title'       => (string) ( $post['source']['title'] ?? '' ),
						),
					),
				) + $data,
			)
		);
	}

	/**
	 * Queues a request into the plugin (`refract/v1/{route}`).
	 *
	 * @param string               $route `posts` or `events`.
	 * @param array<string, mixed> $body  The JSON body.
	 */
	private static function queue( string $route, array $body ): void {
		wp_cache_delete( self::OUTBOX, 'options' );
		$outbox   = get_option( self::OUTBOX );
		$outbox   = is_array( $outbox ) ? $outbox : array();
		$outbox[] = array(
			'route' => $route,
			'body'  => (string) wp_json_encode( $body ),
		);
		update_option( self::OUTBOX, $outbox, false );
	}

	/**
	 * Answers a download from the image host with one of the test images.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  The URL.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function image( array $args, string $url ) {
		$file = WP_PLUGIN_DIR . '/refract/tests/e2e/fixtures/' . basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( ! is_file( $file ) ) {
			return self::json( 404, array() );
		}

		$bytes = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( (string) $args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$bytes = '';
		}

		return array(
			'headers'  => array( 'content-type' => 'image/jpeg' ),
			'body'     => $bytes,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}

	/**
	 * A JSON response for `pre_http_request`.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<mixed>         $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private static function json( int $status, array $body, array $headers = array() ): array {
		return array(
			'headers'  => $headers + array( 'content-type' => 'application/json' ),
			'body'     => 204 === $status ? '' : (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A JSON:API error document.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Code.
	 * @param string $detail Detail.
	 * @return array<string, mixed>
	 */
	private static function error( int $status, string $code, string $detail ): array {
		return array(
			'errors' => array(
				array(
					'status' => (string) $status,
					'code'   => $code,
					'title'  => $detail,
					'detail' => $detail,
				),
			),
		);
	}

	/**
	 * The consent page (`/refract-mock/oauth/authorize`) and its Allow / Cancel (`/refract-mock/oauth/decide`).
	 */
	public static function pages(): void {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );

		if ( ! str_starts_with( $path, REFRACT_MOCK_PATH . '/' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A fake third-party page.
		$get   = static fn ( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		$state = self::state();
		// phpcs:enable

		if ( ! in_array( $path, array( REFRACT_MOCK_PATH . '/oauth/authorize', REFRACT_MOCK_PATH . '/oauth/decide' ), true ) ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Refract</title></head><body><h1>Refract (mock)</h1></body></html>';
			exit;
		}

		$client_id = $get( 'client_id' );
		$client    = $state['clients'][ $client_id ] ?? null;
		$redirect  = $get( 'redirect_uri' );

		if ( ! is_array( $client ) || ! in_array( $redirect, $client['redirect_uris'], true ) ) {
			status_header( 400 );
			echo 'Unknown client or redirect URI.';
			exit;
		}

		if ( REFRACT_MOCK_PATH . '/oauth/decide' === $path ) {
			if ( 'allow' === $get( 'decision' ) ) {
				$code                    = 'code-' . wp_generate_password( 20, false );
				$state['codes'][ $code ] = array(
					'client_id'    => $client_id,
					'redirect_uri' => $redirect,
					'challenge'    => $get( 'code_challenge' ),
				);
				self::save( $state );
				$target = add_query_arg(
					array(
						'code'  => rawurlencode( $code ),
						'state' => rawurlencode( $get( 'state' ) ),
					),
					$redirect
				);
			} else {
				$target = add_query_arg(
					array(
						'error' => 'access_denied',
						'state' => rawurlencode( $get( 'state' ) ),
					),
					$redirect
				);
			}

			wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The client's registered redirect URI.
			exit;
		}

		$hidden = '';

		foreach ( array( 'client_id', 'redirect_uri', 'state', 'code_challenge' ) as $key ) {
			$hidden .= sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $key ), esc_attr( $get( $key ) ) );
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		printf(
			'<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Connect %1$s to Refract</title></head><body style="font-family:system-ui;max-width:32rem;margin:4rem auto">'
			. '<h1>Connect %1$s to Refract</h1><p>%2$s wants to send its posts to Refract and get posts back.</p>'
			. '<form method="get" action="%3$s">%4$s<fieldset><legend>Workspace</legend><label><input type="radio" name="workspace" value="%5$s" checked> %6$s</label></fieldset>'
			. '<p>It can publish posts (you are an Admin in this workspace).</p>'
			. '<button type="submit" name="decision" value="allow">Allow</button> <button type="submit" name="decision" value="deny">Cancel</button></form></body></html>',
			esc_html( $client['name'] ),
			esc_html( (string) wp_parse_url( $redirect, PHP_URL_HOST ) ),
			esc_url( home_url( REFRACT_MOCK_PATH . '/oauth/decide' ) ),
			$hidden, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( self::WORKSPACE['id'] ),
			esc_html( self::WORKSPACE['name'] )
		);
		exit;
	}

	/**
	 * Test-only REST routes.
	 */
	public static function routes(): void {
		$admin = static fn (): bool => current_user_can( 'manage_options' );
		$route = static function ( string $path, string $method, callable $callback ) use ( $admin ): void {
			register_rest_route(
				'refract-mock/v1',
				$path,
				array(
					'methods'             => $method,
					'callback'            => $callback,
					'permission_callback' => $admin,
				)
			);
		};

		$route( '/reset', 'POST', array( self::class, 'reset' ) );
		$route( '/connect', 'POST', array( self::class, 'connect' ) );
		$route( '/advance', 'POST', array( self::class, 'advance' ) );
		$route( '/outbox', 'POST', array( self::class, 'outbox' ) );
		$route( '/send-post', 'POST', array( self::class, 'send_post' ) );
		$route( '/log', 'GET', static fn () => rest_ensure_response( get_option( self::LOG, array() ) ) );
		$route( '/inspect/(?P<id>\d+)', 'GET', array( self::class, 'inspect' ) );
	}

	/**
	 * Forgets everything: the plugin's data (as on uninstall), the mock's state, and the site set up for Kolo Coffee.
	 */
	public static function reset(): WP_REST_Response {
		if ( class_exists( '\Refract\WP\Uninstaller' ) ) {
			\Refract\WP\Uninstaller::clean_site();
		}

		delete_option( self::STATE );
		delete_option( self::LOG );
		delete_option( self::OUTBOX );

		update_option( 'blogname', 'Kolo Coffee' );
		update_option( 'blogdescription', 'Small-batch coffee from Ljubljana' );
		update_option( 'timezone_string', 'Europe/Ljubljana' );

		if ( ! wp_get_theme()->exists() ) {
			switch_theme( 'twentytwentyfive' );
		}

		// Through $wp_rewrite, not update_option(): it still holds the structure it loaded at init (wp-env's date-based
		// one on a fresh site), so flushing after a bare option change would save rules for that structure and every
		// /%postname%/ link would 404.
		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			flush_rewrite_rules( false );
		}

		$admin = get_user_by( 'login', 'admin' );

		if ( $admin ) {
			wp_update_user(
				array(
					'ID'           => $admin->ID,
					'display_name' => 'Ana Kovač',
					'first_name'   => 'Ana',
					'last_name'    => 'Kovač',
				)
			);
		}

		if ( ! get_user_by( 'login', 'luka' ) ) {
			wp_insert_user(
				array(
					'user_login'   => 'luka',
					'user_pass'    => wp_generate_password(),
					'user_email'   => 'luka@kolo.coffee',
					'display_name' => 'Luka Novak',
					'role'         => 'editor',
				)
			);
		}

		$uncategorized = get_term_by( 'slug', 'uncategorized', 'category' );

		if ( $uncategorized ) {
			wp_update_term(
				$uncategorized->term_id,
				'category',
				array(
					'name' => 'Coffee',
					'slug' => 'coffee',
				)
			);
		}

		foreach ( array( 'Brewing', 'Events', 'Shop news' ) as $name ) {
			if ( ! term_exists( $name, 'category' ) ) {
				wp_insert_term( $name, 'category' );
			}
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Connects the site with a token (for specs that start connected), then saves the given settings.
	 *
	 * @param WP_REST_Request $request `{settings?: object}`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function connect( WP_REST_Request $request ) {
		$result = \Refract\WP\Connector::connect_token( 'rf_live_kolo_e2e_token' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$settings = (array) ( $request->get_param( 'settings' ) ?? array() );
		\Refract\WP\Settings::update(
			$settings + array(
				'accounts'   => array( 'acc-ig-kolo', 'acc-ig-roastery', 'acc-fb-kolo', 'acc-li-kolo', 'acc-bsky-kolo', 'acc-threads-kolo' ),
				'setup_done' => true,
			)
		);

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Moves posts along, as Refract would: `written` (writing → needs_ok, plus post.versions_ready) or `posted`
	 * (scheduled → posted with a link and clicks, plus post.published for each account).
	 *
	 * @param WP_REST_Request $request `{to: "written"|"posted"}`.
	 */
	public static function advance( WP_REST_Request $request ): WP_REST_Response {
		$to     = (string) $request->get_param( 'to' );
		$state  = self::state();
		$clicks = array( 48, 17, 9, 23, 31, 12 );

		foreach ( $state['posts'] as $id => $post ) {
			$changed = false;
			$index   = 0;

			foreach ( $post['versions'] as $account_id => $version ) {
				$account = self::account( $account_id );

				if ( 'written' === $to && 'writing' === $version['status'] ) {
					$post['versions'][ $account_id ]['status'] = 'needs_ok';
					$changed                                   = true;
				}

				if ( 'posted' === $to && in_array( $version['status'], array( 'scheduled', 'approved' ), true ) ) {
					$permalink = array(
						'instagram' => 'https://www.instagram.com/p/C' . substr( md5( $account_id . $id ), 0, 10 ) . '/',
						'facebook'  => 'https://www.facebook.com/kolocoffee/posts/' . hexdec( substr( md5( $id ), 0, 8 ) ),
						'linkedin'  => 'https://www.linkedin.com/feed/update/urn:li:activity:' . hexdec( substr( md5( $id ), 0, 10 ) ),
						'bluesky'   => 'https://bsky.app/profile/kolo.coffee/post/3l' . substr( md5( $id ), 0, 11 ),
						'threads'   => 'https://www.threads.net/@kolocoffee/post/D' . substr( md5( $id ), 0, 10 ),
					)[ $account['network'] ] ?? 'https://example.com/' . $account_id;

					$post['versions'][ $account_id ]['status']    = 'posted';
					$post['versions'][ $account_id ]['permalink'] = $permalink;
					$post['versions'][ $account_id ]['clicks']    = $clicks[ $index % count( $clicks ) ];
					$changed                                      = true;
					$post['status']                               = 'published';
					self::event(
						'post.published',
						$post,
						array(
							'account'      => array(
								'id'       => $account['id'],
								'platform' => $account['network'],
								'handle'   => $account['handle'],
								'name'     => $account['name'],
							),
							'permalink'    => $permalink,
							'short_link'   => 'https://rfr.ac/' . substr( md5( $account_id . $id ), 0, 6 ),
							'published_at' => gmdate( 'c' ),
						)
					);
				}

				++$index;
			}

			if ( 'written' === $to && $changed ) {
				self::event(
					'post.versions_ready',
					$post,
					array(
						'versions' => array_map(
							static fn ( string $account_id, array $version ): array => array(
								'id'         => $version['id'],
								'account_id' => $account_id,
								'network'    => self::account( $account_id )['network'],
								'status'     => $version['status'],
							),
							array_keys( $post['versions'] ),
							array_values( $post['versions'] )
						),
					)
				);
			}

			$state['posts'][ $id ] = $post;
		}

		self::save( $state );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Queues a post "from Refract" for `POST refract/v1/posts`.
	 *
	 * @param WP_REST_Request $request The post body (§4 of the contract).
	 */
	public static function send_post( WP_REST_Request $request ): WP_REST_Response {
		self::queue( 'posts', (array) $request->get_json_params() );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Hands the queued requests to the tests, signed now with the site's signing secret, and empties the queue.
	 *
	 * @param WP_REST_Request $request `{form?: "x-refract"|"webhook"}`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function outbox( WP_REST_Request $request ) {
		$state  = self::state();
		$secret = is_array( $state['site'] ) ? $state['site']['signing_secret'] : '';

		if ( '' === $secret ) {
			return new WP_Error( 'refract_mock_no_site', 'No site is registered with the mock Refract.', array( 'status' => 409 ) );
		}

		wp_cache_delete( self::OUTBOX, 'options' );
		$outbox = get_option( self::OUTBOX );
		delete_option( self::OUTBOX );
		$out = array();

		foreach ( is_array( $outbox ) ? $outbox : array() as $item ) {
			$time      = (string) time();
			$signature = hash_hmac( 'sha256', $time . '.' . $item['body'], $secret );
			$headers   = 'events' === $item['route'] ? array( 'Refract-Signature' => 't=' . $time . ',v1=' . $signature ) : array(
				'X-Refract-Timestamp' => $time,
				'X-Refract-Signature' => $signature,
			);
			$out[]     = array(
				'url'     => rest_url( 'refract/v1/' . $item['route'] ),
				'body'    => $item['body'],
				'headers' => $headers + array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Refract/1.0 (+https://refract.social)',
				),
			);
		}

		return rest_ensure_response( $out );
	}

	/**
	 * A post's Refract meta and images, for assertions.
	 *
	 * @param WP_REST_Request $request `id`.
	 */
	public static function inspect( WP_REST_Request $request ): WP_REST_Response {
		$id    = (int) $request['id'];
		$thumb = (int) get_post_thumbnail_id( $id );

		return rest_ensure_response(
			array(
				'status'         => get_post_status( $id ),
				'content'        => (string) get_post_field( 'post_content', $id ),
				'source'         => (string) get_post_meta( $id, '_refract_source', true ),
				'refract_status' => (string) get_post_meta( $id, '_refract_status', true ),
				'refract_id'     => (string) get_post_meta( $id, '_refract_post_id', true ),
				'scheduled'      => false !== wp_next_scheduled( 'refract_send_post', array( $id, '' ) ),
				'thumbnail'      => $thumb > 0 ? (string) wp_get_attachment_url( $thumb ) : null,
				'thumbnail_from' => $thumb > 0 ? (string) get_post_meta( $thumb, '_refract_source_url', true ) : null,
			)
		);
	}
}

Refract_Mock::boot();
