<?php

namespace TranslateWP\Components;

class NetworkAdmin {

	public static function page_data(): array {
		$site_ids = MultisiteSettings::site_ids();
		$hosts    = [];
		foreach ( SiteConnection::network_subsites() as $subsite ) {
			$hosts[ (int) $subsite['blog_id'] ] = (string) $subsite['host'];
		}

		return [
			'connection' => self::connection_data(),
			'subsites'   => array_map(
				static function ( int $site_id ) use ( $hosts ): array {
					return self::subsite_data( $site_id, $hosts[ $site_id ] ?? '' );
				},
				$site_ids
			),
			'scope'      => NetworkScope::page_data() + [ 'status' => self::status() ],
			'actions'    => [
				'url'        => admin_url( 'admin-post.php' ),
				'connect'    => 'translatewp_begin_network_connect',
				'scope'      => 'translatewp_save_settings_scope',
				'nonces'     => [
					'connect'    => wp_create_nonce( 'translatewp_begin_network_connect' ),
					'scope'      => wp_create_nonce( 'translatewp_save_settings_scope' ),
				],
			],
			'upgradeUrl' => ProEligibility::upgrade_url_with_return(),
		];
	}

	private static function connection_data(): array {
		return [
			'connected'    => SiteConnection::is_connected(),
			'accountLabel' => (string) Options::get( 'site.account_label', '' ),
			'pro'          => ProEligibility::panel_state(),
			'outcome'      => self::connect_outcome(),
		];
	}

	private static function connect_outcome(): array {
		$names = [];
		foreach ( MultisiteSettings::site_ids() as $site_id ) {
			$site              = get_blog_details( $site_id );
			$names[ $site_id ] = $site ? PlainText::blog_name( $site ) : '';
		}

		$outcome = [];
		foreach ( SiteConnection::take_network_connect_outcome() as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$blog_id   = (int) ( $result['blog_id'] ?? 0 );
			$host      = (string) ( $result['host'] ?? '' );
			$ok        = ! empty( $result['ok'] );
			$outcome[] = [
				'host'        => $host,
				'name'        => '' !== ( $names[ $blog_id ] ?? '' ) ? $names[ $blog_id ] : $host,
				'ok'          => $ok,
				'reconnected' => $ok && ! empty( $result['reconnected'] ),
				'reason'      => $ok ? '' : self::failure_reason( (string) ( $result['error'] ?? '' ) ),
				'retry'       => ! $ok && $blog_id > 0,
			];
		}

		return $outcome;
	}

	private static function failure_reason( string $error ): string {
		if ( 'unknown_host' === $error ) {
			return __( 'This address is not a subsite of this network, so it was not connected.', 'translate' );
		}
		if ( 'invalid_connect_code' === $error ) {
			return __( 'The connection code expired or was already used.', 'translate' );
		}

		return __( 'The service could not finish connecting this subsite.', 'translate' );
	}

	private static function subsite_data( int $site_id, string $host ): array {
		$site = get_blog_details( $site_id );
		switch_to_blog( $site_id );
		try {
			$languages = Options::get( 'languages.list', [] );
			$active    = [];
			foreach ( is_array( $languages ) ? $languages : [] as $language ) {
				if ( is_array( $language ) && ! empty( $language['active'] ) && ! empty( $language['locale'] ) ) {
					$active[] = (string) $language['locale'];
				}
			}

			return [
				'id'          => $site_id,
				'name'        => $site ? PlainText::blog_name( $site ) : '#' . $site_id,
				'url'         => $site ? (string) $site->siteurl : '',
				'host'        => $host,
				'siteId'      => Sites::registered_id(),
				'connected'   => SiteConnection::is_connected(),
				'setupEnded'  => (bool) Options::get( 'setup.ended', false ),
				'languages'   => $active,
				'dashboardUrl' => get_admin_url( $site_id, 'admin.php?page=translatewp' ),
				'seoUrl'       => get_admin_url( $site_id, 'admin.php?page=translatewp-seo' ),
				'lexiconUrl'   => get_admin_url( $site_id, 'admin.php?page=translatewp-lexicon' ),
			];
		} finally {
			restore_current_blog();
		}
	}

	private static function status(): string {
		return isset( $_GET['translatewp_multisite_status'] ) ? sanitize_key( wp_unslash( $_GET['translatewp_multisite_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after a redirect.
	}
}
