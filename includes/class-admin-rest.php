<?php
/**
 * REST routes for the plugin's own screens.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `refract-admin/v1`: cookie auth with the REST nonce. Settings routes need `manage_options`, post routes `edit_post`
 * on that post. The browser never sees a Refract token; these routes call Refract from PHP.
 */
final class Admin_Rest {

	/**
	 * The namespace.
	 */
	public const NAMESPACE = 'refract-admin/v1';

	/**
	 * Adds the route registration.
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers every route.
	 */
	public static function register_routes(): void {
		$manage = array( self::class, 'can_manage' );
		$edit   = array( self::class, 'can_edit_post' );
		$id     = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		$routes = array(
			'/state'                         => array( WP_REST_Server::READABLE, 'state', $manage, array() ),
			'/settings'                      => array(
				WP_REST_Server::CREATABLE,
				'settings',
				$manage,
				array(
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			),
			'/connect'                       => array( WP_REST_Server::CREATABLE, 'connect', $manage, array() ),
			'/connect-token'                 => array(
				WP_REST_Server::CREATABLE,
				'connect_token',
				$manage,
				array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
			'/workspace'                     => array(
				WP_REST_Server::CREATABLE,
				'workspace',
				$manage,
				array(
					'workspace_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
			'/disconnect'                    => array(
				WP_REST_Server::CREATABLE,
				'disconnect',
				$manage,
				array(
					'keep_settings' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'/activity'                      => array(
				WP_REST_Server::READABLE,
				'activity',
				$manage,
				array(
					'problems' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'/posts/(?P<id>\d+)'             => array( WP_REST_Server::READABLE, 'post_panel', $edit, $id ),
			'/posts/(?P<id>\d+)/send'        => array(
				WP_REST_Server::CREATABLE,
				'send',
				$edit,
				$id + array(
					'start_from' => array(
						'type' => 'string',
						'enum' => array( 'note', 'finished' ),
					),
				),
			),
			'/posts/(?P<id>\d+)/approve'     => array(
				WP_REST_Server::CREATABLE,
				'approve',
				$edit,
				$id + array(
					'version_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
			'/posts/(?P<id>\d+)/approve-all' => array( WP_REST_Server::CREATABLE, 'approve_all', $edit, $id ),
		);

		foreach ( $routes as $route => $config ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $config[0],
					'callback'            => array( self::class, $config[1] ),
					'permission_callback' => $config[2],
					'args'                => $config[3],
				)
			);
		}
	}

	/**
	 * Settings routes: people who manage options.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Post routes: people who can edit that post.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function can_edit_post( WP_REST_Request $request ): bool {
		$post_id = (int) $request['id'];

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * `GET /state`.
	 */
	public static function state(): WP_REST_Response {
		return rest_ensure_response( Admin_State::build() );
	}

	/**
	 * `POST /settings`: saves some settings.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$changes = $request['settings'];

		if ( ! is_array( $changes ) ) {
			return new WP_Error( 'refract_invalid', __( 'Those settings couldn\'t be saved.', 'refract' ), array( 'status' => 422 ) );
		}

		$next = Settings::sanitize( $changes );

		if ( true === $next['refract_publish'] && 0 === $next['publish_as'] ) {
			return new WP_Error( 'refract_invalid', __( 'Choose who Refract publishes as. It must be someone who can publish posts.', 'refract' ), array( 'status' => 422 ) );
		}

		update_option( Settings::OPTION, $next );
		update_option( Settings::AFTER_VERSIONS, Settings::WAIT_FOR_APPROVAL, false );
		delete_transient( Refract_Data::ACCOUNTS );

		return self::state();
	}

	/**
	 * `POST /connect`: the URL to send the person to.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function connect(): WP_REST_Response|WP_Error {
		$url = Connector::start();

		return is_wp_error( $url ) ? $url : rest_ensure_response( array( 'authorize_url' => $url ) );
	}

	/**
	 * `POST /connect-token`.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function connect_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Connector::connect_token( (string) $request['token'] );

		return is_wp_error( $result ) ? $result : self::state();
	}

	/**
	 * `POST /workspace`: registers the site in that workspace (also "Try again").
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function workspace( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Connector::register( (string) $request['workspace_id'] );

		return is_wp_error( $result ) ? $result : self::state();
	}

	/**
	 * `POST /disconnect`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function disconnect( WP_REST_Request $request ): WP_REST_Response {
		Connector::disconnect( (bool) $request['keep_settings'] );

		return self::state();
	}

	/**
	 * `GET /activity`: the last 100 events.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function activity( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( array( 'events' => Activity::entries( (bool) $request['problems'], 100 ) ) );
	}

	/**
	 * `GET /posts/{id}`.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_panel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::post( $request );

		return $post instanceof WP_Post ? rest_ensure_response( Post_Panel::build( $post ) ) : $post;
	}

	/**
	 * `POST /posts/{id}/send`: "Send to Refract", or "Send the same post to every account instead (free)".
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function send( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::post( $request );

		if ( ! $post instanceof WP_Post ) {
			return $post;
		}

		if ( '0' === (string) get_post_meta( $post->ID, '_refract_send', true ) ) {
			return new WP_Error( 'refract_switched_off', __( 'Sending is switched off for this post. Tick "Send to Refract" first.', 'refract' ), array( 'status' => 409 ) );
		}

		$start_from = is_string( $request['start_from'] ) ? $request['start_from'] : '';
		$lock       = Sender::lock_key( $post->ID );

		if ( 'running' === get_transient( $lock ) ) {
			return new WP_Error( 'refract_busy', __( 'Refract is already getting this post. Wait a few seconds.', 'refract' ), array( 'status' => 409 ) );
		}

		// A send scheduled by publishing would duplicate this one.
		wp_clear_scheduled_hook( Plugin::CRON_SEND, array( $post->ID, '' ) );

		if ( 'finished' === $start_from && 'out_of_credits' === get_post_meta( $post->ID, '_refract_status', true ) ) {
			update_post_meta( $post->ID, '_refract_fallback', '1' );
		}

		set_transient( $lock, 'running', Sender::LOCK_SECONDS );

		try {
			$result = Sender::send( $post, $start_from, true );
		} finally {
			delete_transient( $lock );
		}

		return is_wp_error( $result ) ? $result : rest_ensure_response( Post_Panel::build( $post ) );
	}

	/**
	 * `POST /posts/{id}/approve`: approves one version.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::versions_action( $request, 'versions/' . rawurlencode( (string) $request['version_id'] ) . '/approve', __( 'Approved a version.', 'refract' ) );
	}

	/**
	 * `POST /posts/{id}/approve-all`: approves every version without a failing check and adds the post to the queue
	 * (or sends it for approval).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function approve_all( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::versions_action( $request, 'approve-all', __( 'Approved the versions.', 'refract' ) );
	}

	/**
	 * Runs an approve call and returns the panel with Refract's new versions document.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $path    Path under the Refract post.
	 * @param string          $message Activity text.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function versions_action( WP_REST_Request $request, string $path, string $message ): WP_REST_Response|WP_Error {
		$post = self::post( $request );

		if ( ! $post instanceof WP_Post ) {
			return $post;
		}

		$remote_id = (string) get_post_meta( $post->ID, '_refract_post_id', true );

		if ( ! Connection::is_connected() || '' === $remote_id ) {
			return new WP_Error( 'refract_not_sent', __( 'Send the post to Refract first.', 'refract' ), array( 'status' => 409 ) );
		}

		$response = Refract_Client::api( 'POST', 'workspaces/' . rawurlencode( (string) Connection::workspace_id() ) . '/posts/' . rawurlencode( $remote_id ) . '/' . $path );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $response->ok() ) {
			return $response->to_error();
		}

		Activity::log(
			'approved',
			array(
				'post_id' => $post->ID,
				'message' => $message,
				'link'    => is_string( $response->body['edit_url'] ?? null ) ? $response->body['edit_url'] : null,
			)
		);

		return rest_ensure_response( Post_Panel::build( $post, $response->body ) );
	}

	/**
	 * The post a post route is about.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_Post|WP_Error
	 */
	private static function post( WP_REST_Request $request ): WP_Post|WP_Error {
		$post = get_post( (int) $request['id'] );

		return $post instanceof WP_Post ? $post : new WP_Error( 'rest_post_invalid_id', __( 'That post doesn\'t exist.', 'refract' ), array( 'status' => 404 ) );
	}
}
