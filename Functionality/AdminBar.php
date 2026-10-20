<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\Access;

/**
 * TranslateWP in the toolbar: under the site name on the site itself (not in
 * wp-admin, where the sidebar menu has it), and under each site in My Sites on
 * a network, opening the admin page the user has there. A user without an
 * admin page on a site gets no entry for it.
 */
class AdminBar {

	public const SITE_NAME_GROUP = 'site-name-translatewp-group';

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		// After WordPress has built My Sites and the site name menu with its Appearance group (priority 20 to 40).
		add_action( 'admin_bar_menu', [ $this, 'add_links' ], 41 );
	}

	/**
	 * Adds the entries the toolbar has room for.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The toolbar being built.
	 */
	public function add_links( \WP_Admin_Bar $wp_admin_bar ): void {
		$this->add_site_name_link( $wp_admin_bar );
		if ( is_multisite() ) {
			$this->add_my_sites_links( $wp_admin_bar );
		}
	}

	/**
	 * One entry under the site name on the front end, beneath the site's own
	 * links: a group of its own, since the menu's loose entries sit above its groups.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The toolbar being built.
	 */
	private function add_site_name_link( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( is_admin() || ! $wp_admin_bar->get_node( 'site-name' ) ) {
			return;
		}
		$page = Access::admin_page();
		if ( null === $page ) {
			return;
		}

		$wp_admin_bar->add_group(
			[
				'parent' => 'site-name',
				'id'     => self::SITE_NAME_GROUP,
			]
		);
		$wp_admin_bar->add_node(
			[
				'parent' => self::SITE_NAME_GROUP,
				'id'     => 'site-name-translatewp',
				'title'  => esc_html__( 'TranslateWP', 'translate' ),
				'href'   => admin_url( 'admin.php?page=' . $page ),
			]
		);
	}

	/**
	 * Network Admin and each of the user's sites in My Sites.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The toolbar being built.
	 */
	private function add_my_sites_links( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( $wp_admin_bar->get_node( 'network-admin' ) && current_user_can( 'manage_network_options' ) ) {
			$wp_admin_bar->add_node(
				[
					'parent' => 'network-admin',
					'id'     => 'network-admin-translatewp',
					'title'  => esc_html__( 'TranslateWP', 'translate' ),
					'href'   => network_admin_url( 'admin.php?page=' . Admin\MultisiteSettingsPage::PAGE_SLUG ),
				]
			);
		}

		foreach ( (array) ( $wp_admin_bar->user->blogs ?? [] ) as $blog ) {
			$blog_id = (int) ( $blog->userblog_id ?? 0 );
			if ( $blog_id < 1 || ! $wp_admin_bar->get_node( 'blog-' . $blog_id ) ) {
				continue;
			}

			// Roles, the role map and setup are each site's own.
			switch_to_blog( $blog_id );
			try {
				$page = Access::admin_page();
			} finally {
				restore_current_blog();
			}
			if ( null === $page ) {
				continue;
			}

			$wp_admin_bar->add_node(
				[
					'parent' => 'blog-' . $blog_id,
					'id'     => 'blog-' . $blog_id . '-translatewp',
					'title'  => esc_html__( 'TranslateWP', 'translate' ),
					'href'   => get_admin_url( $blog_id, 'admin.php?page=' . $page ),
				]
			);
		}
	}
}
