<?php
/**
 * What the Settings and Activity screens show.
 *
 * @package Refract
 */

namespace Refract\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the AdminState document the admin app reads.
 */
final class Admin_State {

	/**
	 * The whole state: connection, links, settings and the choices the screens offer.
	 *
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$connection = Connection::get();
		$base       = 'disconnected' === $connection['status'] ? Connection::default_url() : Connection::refract_url();

		return array(
			'connection'     => array(
				'status'       => $connection['status'],
				'kind'         => $connection['kind'],
				'email'        => $connection['email'],
				'name'         => $connection['name'],
				'organization' => $connection['organization'],
				'plan'         => $connection['plan'],
				'workspace'    => $connection['workspace'],
				'workspaces'   => $connection['workspaces'],
				'credits'      => Refract_Data::credits(),
				'error'        => $connection['error'],
				'refract_url'  => $base,
			),
			'links'          => array(
				'signup'    => $base . '/signup',
				'tokens'    => $base . '/docs/api',
				'directory' => 'https://wordpress.org/plugins/refract/',
			),
			'settings'       => Settings::get(),
			'after_versions' => Settings::WAIT_FOR_APPROVAL,
			'accounts'       => Refract_Data::accounts(),
			'post_types'     => self::post_types(),
			'categories'     => self::categories(),
			'users'          => self::users(),
			'seo_plugin'     => Seo_Plugins::active(),
		);
	}

	/**
	 * Public post types, attachments excluded.
	 *
	 * @return list<array{name: string, label: string}>
	 */
	private static function post_types(): array {
		$types = array();

		foreach ( Settings::public_post_types() as $name ) {
			$object  = get_post_type_object( $name );
			$types[] = array(
				'name'  => $name,
				'label' => null === $object ? $name : (string) $object->labels->name,
			);
		}

		return $types;
	}

	/**
	 * Every category.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public static function categories(): array {
		return self::terms( 'category', 0 );
	}

	/**
	 * Terms of a taxonomy as `{id, name}`.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $limit    How many (0 = all), most used first when limited.
	 * @return list<array{id: int, name: string}>
	 */
	public static function terms( string $taxonomy, int $limit ): array {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);

		if ( $limit > 0 ) {
			$args['number']  = $limit;
			$args['orderby'] = 'count';
			$args['order']   = 'DESC';
		}

		$terms = get_terms( $args );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$list = array();

		foreach ( $terms as $term ) {
			$list[] = array(
				'id'   => (int) $term->term_id,
				'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			);
		}

		return $list;
	}

	/**
	 * People who can publish posts (the "Publish as" choices).
	 *
	 * @return list<array{id: int, name: string}>
	 */
	private static function users(): array {
		$users = get_users(
			array(
				'capability' => 'publish_posts',
				'fields'     => array( 'ID', 'display_name' ),
				'orderby'    => 'display_name',
				'number'     => 500,
			)
		);
		$list  = array();

		foreach ( $users as $user ) {
			if ( is_object( $user ) && isset( $user->ID, $user->display_name ) ) {
				$list[] = array(
					'id'   => (int) $user->ID,
					'name' => (string) $user->display_name,
				);
			}
		}

		return $list;
	}
}
