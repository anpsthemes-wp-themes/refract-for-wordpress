<?php
/**
 * The editor integrations.
 *
 * @package Refract
 */

namespace Refract\WP;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the block editor sidebar, and adds the classic editor meta box (a server-rendered form plus a small script
 * that shows the versions).
 */
final class Editor {

	/**
	 * Nonce action and field name of the meta box.
	 */
	public const NONCE = 'refract_meta_box';

	/**
	 * Adds the hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_classic' ) );
		add_action( 'save_post', array( self::class, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Loads the sidebar for public post types.
	 */
	public static function enqueue_block_editor(): void {
		$screen = get_current_screen();

		if ( null === $screen || ! in_array( $screen->post_type, Settings::public_post_types(), true ) ) {
			return;
		}

		if ( Admin::enqueue_build( 'refract-editor', 'editor' ) ) {
			wp_add_inline_script( 'refract-editor', 'window.refractEditor = ' . wp_json_encode( self::globals() ) . ';', 'before' );
		}
	}

	/**
	 * Loads the meta box script on classic post screens.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_classic( string $hook_suffix ): void {
		$screen = get_current_screen();

		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || null === $screen || $screen->is_block_editor() || ! in_array( $screen->post_type, Settings::public_post_types(), true ) ) {
			return;
		}

		if ( Admin::enqueue_build( 'refract-classic', 'classic', array( 'wp-components' ) ) ) {
			wp_add_inline_script( 'refract-classic', 'window.refractEditor = ' . wp_json_encode( self::globals() ) . ';', 'before' );
		}
	}

	/**
	 * Adds the meta box for public post types; WordPress hides it in the block editor, which has the sidebar.
	 *
	 * @param string $post_type The post type.
	 */
	public static function add_meta_box( string $post_type ): void {
		if ( ! in_array( $post_type, Settings::public_post_types(), true ) ) {
			return;
		}

		add_meta_box(
			'refract',
			__( 'Refract', 'refract' ),
			array( self::class, 'render_meta_box' ),
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Prints the meta box: the switch, the accounts, the cost, the state, and the versions mount point.
	 *
	 * @param WP_Post $post The post.
	 */
	public static function render_meta_box( WP_Post $post ): void {
		$panel = Post_Panel::build( $post, array() );

		wp_nonce_field( self::NONCE, self::NONCE );

		if ( ! $panel['connected'] ) {
			printf(
				'<p>%1$s</p>%2$s',
				esc_html__( 'Connect your site to Refract to send this post.', 'refract' ),
				current_user_can( 'manage_options' ) ? sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( admin_url( 'admin.php?page=refract' ) ), esc_html__( 'Connect to Refract', 'refract' ) ) : ''
			);

			return;
		}

		if ( ! $panel['enabled'] ) {
			echo '<p>' . esc_html__( 'Refract isn\'t on for this type of post. Turn it on in Refract › Settings.', 'refract' ) . '</p>';

			return;
		}

		if ( 'from_refract' === $panel['state'] ) {
			echo '<p>' . esc_html__( 'This post came from Refract, so it isn\'t sent back.', 'refract' ) . '</p>';

			return;
		}

		$switch   = (string) get_post_meta( $post->ID, '_refract_send', true );
		$checked  = '' === $switch ? $panel['default_send'] : '1' === $switch;
		$selected = (array) $panel['selected'];

		if ( 'not_sent' === $panel['state'] ) {
			// Tells save_meta_box() the fields below were on the form, so missing checkboxes mean "unchecked".
			echo '<input type="hidden" name="refract_fields" value="1" />';

			if ( array() !== (array) $panel['accounts'] ) {
				echo '<input type="hidden" name="refract_accounts_listed" value="1" />';
			}

			printf(
				'<p><label><input type="checkbox" name="refract_send" value="1" %1$s /> %2$s</label></p>',
				checked( $checked, true, false ),
				esc_html__( 'Send to Refract', 'refract' )
			);

			$networks = array();
			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Accounts', 'refract' ) . '</legend>';

			foreach ( (array) $panel['accounts'] as $account ) {
				$is_selected = in_array( $account['id'], $selected, true );

				if ( $is_selected ) {
					$networks[ $account['network'] ] = true;
				}

				printf(
					'<label style="display:block"><input type="checkbox" name="refract_accounts[]" value="%1$s" %2$s /> %3$s</label>',
					esc_attr( $account['id'] ),
					checked( $is_selected, true, false ),
					esc_html( trim( $account['network_label'] . ' · ' . ( '' === $account['handle'] ? $account['name'] : $account['handle'] ) ) )
				);
			}

			echo '</fieldset>';
			echo '<p class="description">' . esc_html( self::cost( count( $networks ), (string) $panel['write_mode'], (bool) $panel['own_key'] ) ) . '</p>';

			if ( 'manual' === $panel['send_mode'] && ! $panel['can_send_now'] ) {
				echo '<p class="description">' . esc_html__( 'Publish the post first. Each version links to it.', 'refract' ) . '</p>';
			}
		} else {
			$labels = array(
				'queued'         => __( 'Sending to Refract…', 'refract' ),
				'sent'           => __( 'Sent to Refract.', 'refract' ),
				'retrying'       => __( 'Refract didn\'t answer. Trying again soon.', 'refract' ),
				'failed'         => __( 'Couldn\'t send to Refract.', 'refract' ),
				'out_of_credits' => __( 'Out of AI credits.', 'refract' ),
			);

			echo '<p><strong>' . esc_html( $labels[ $panel['state'] ] ?? '' ) . '</strong></p>';

			if ( is_string( $panel['error'] ) ) {
				echo '<p>' . esc_html( $panel['error'] ) . '</p>';
			}

			if ( is_string( $panel['buy_url'] ) && 'out_of_credits' === $panel['state'] ) {
				printf( '<p><a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>', esc_url( $panel['buy_url'] ), esc_html__( 'Buy credits', 'refract' ) );
			}

			if ( $panel['fallback'] ) {
				echo '<p>' . esc_html__( 'You were out of AI credits, so Refract got the same post for every account.', 'refract' ) . '</p>';
			}
		}

		printf( '<div id="refract-classic" data-post-id="%d"></div>', (int) $post->ID );
	}

	/**
	 * Saves the meta box: only with a valid nonce, by people who can edit the post, and only when the form showed the
	 * fields (a post already queued or sent shows none). Values equal to the site's defaults are stored as "use the
	 * default"; unchecking every account means "don't send this post".
	 *
	 * @param int     $post_id The post id.
	 * @param WP_Post $post    The post.
	 */
	public static function save_meta_box( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['refract_fields'] ) || Sender::from_refract( $post_id ) || Sender::was_sent( $post_id ) || ! Connection::is_connected() || ! Settings::post_type_enabled( $post->post_type ) ) {
			return;
		}

		$settings = Settings::get();
		$send     = isset( $_POST['refract_send'] );
		$accounts = isset( $_POST['refract_accounts'] ) && is_array( $_POST['refract_accounts'] )
			? Settings::sanitize_account_ids( array_map( 'sanitize_text_field', wp_unslash( $_POST['refract_accounts'] ) ) )
			: array();

		if ( isset( $_POST['refract_accounts_listed'] ) && array() === $accounts ) {
			update_post_meta( $post_id, '_refract_send', '0' );
			update_post_meta( $post_id, '_refract_accounts', array() );

			return;
		}

		update_post_meta( $post_id, '_refract_send', ( 'publish' === $settings['send_mode'] ) === $send ? '' : ( $send ? '1' : '0' ) );

		if ( isset( $_POST['refract_accounts_listed'] ) ) {
			$same = array() === array_diff( $accounts, $settings['accounts'] ) && array() === array_diff( $settings['accounts'], $accounts );
			update_post_meta( $post_id, '_refract_accounts', $same ? array() : $accounts );
		}
	}

	/**
	 * The cost line: "4 versions · 4 credits", free with the workspace's own AI key or for the same post everywhere.
	 *
	 * @param int    $versions   Versions to write (one per network).
	 * @param string $write_mode note|finished.
	 * @param bool   $own_key    Whether the workspace's own AI key pays.
	 */
	public static function cost( int $versions, string $write_mode, bool $own_key ): string {
		if ( 'finished' === $write_mode ) {
			return __( 'The same post for every account · free', 'refract' );
		}

		if ( $own_key ) {
			/* translators: %d: number of versions. */
			return sprintf( _n( '%d version · no credits (your own AI key)', '%d versions · no credits (your own AI key)', $versions, 'refract' ), $versions );
		}

		return 1 === $versions
			? __( '1 version · 1 credit', 'refract' )
			/* translators: 1: number of versions, 2: number of credits. */
			: sprintf( __( '%1$d versions · %2$d credits', 'refract' ), $versions, $versions );
	}

	/**
	 * What the editor scripts read from `window.refractEditor`.
	 *
	 * @return array{logo: string, settingsUrl: string, canManage: bool}
	 */
	private static function globals(): array {
		return array(
			'logo'        => Admin::logo_url(),
			'settingsUrl' => admin_url( 'admin.php?page=refract' ),
			'canManage'   => current_user_can( 'manage_options' ),
		);
	}
}
