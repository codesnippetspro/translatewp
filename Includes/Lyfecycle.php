<?php

namespace TranslateWP\Includes;

use TranslateWP\Components\CleanupInventory;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\SettingsScope;
use TranslateWP\Functionality\TranslatedEmails;

class Lyfecycle {

	private const REWRITE_VERSION_OPTION = 'translatewp_rewrite_version';
	private const REWRITE_FLUSH_OPTION   = 'translatewp_flush_rewrite_rules';
	private const ACTIVATION_SCOPE_OPTION = 'translatewp_activation_scope';
	private const SCOPE_NETWORK = 'network';
	private const SCOPE_SITE    = 'site';
	private const UNINSTALL_REQUEST_TIMEOUT_SECONDS = 5;
	// All the disconnects of a network together; what is left of the delete is local cleanup.
	private const UNINSTALL_DISCONNECT_BUDGET_SECONDS = 12;

	/**
	 * The clock the disconnect budget is measured on. Replaced in tests.
	 *
	 * @var callable|null
	 */
	private static $clock = null;

	/** Replaces the clock of the disconnect budget; null restores the real one. For tests. */
	public static function set_clock( ?callable $clock ): void {
		self::$clock = $clock;
	}

    public static function activate( $network_wide ) {
		update_option( self::REWRITE_FLUSH_OPTION, TRANSLATEWP_VERSION );
		\TranslateWP\Components\Migrations::seed_for_fresh_install();
		// At uninstall the plugin is inactive, so whether it was network active is read from here.
		if ( is_multisite() ) {
			update_site_option( self::ACTIVATION_SCOPE_OPTION, $network_wide ? self::SCOPE_NETWORK : self::SCOPE_SITE );
		}
        \TranslateWP\Components\ActionLog::record( $network_wide ? 'Plugin activated for the network' : 'Plugin activated' );
        do_action( 'TranslateWP/setup', $network_wide );
    }

    public static function deactivate( $network_wide ) {
        \TranslateWP\Components\ActionLog::record( $network_wide ? 'Plugin deactivated for the network' : 'Plugin deactivated', 'warning' );
		$release = self::held_email_release( true );
		if ( $network_wide && is_multisite() ) {
			CleanupInventory::each_site(
				static function () use ( $release ): void {
					self::stop_schedules( $release );
				}
			);
		} else {
			self::stop_schedules( $release );
		}
        do_action( 'TranslateWP/deactivation', $network_wide );
    }

	/**
	 * Every site loses its schedules and index queue posts and, when connected,
	 * is disconnected. The rest of the data goes only when "Delete all data on
	 * uninstall" is on, read as the running plugin reads it: on a network where
	 * the plugin is network active the setting belongs to the network, so a
	 * subsite row never decides; where it is active on single sites, each
	 * site's own setting decides for that site and the network options stay.
	 */
    public static function uninstall() {
		$keep_pro_addon_rows = \TranslateWP\Components\ProEligibility::is_installed();

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			self::disconnect_site();
			$wipe = self::site_delete_flag();
			TranslatedEmails::release_held( self::held_email_release( ! $wipe ) );
			self::clean_site( $wipe, $keep_pro_addon_rows );
			if ( $wipe ) {
				CleanupInventory::wipe_user_meta();
			}
			do_action( 'TranslateWP/cleanup' );
			return;
		}

		self::disconnect_network();

		$network_active = self::network_active();
		$network_wipe   = $network_active && self::network_delete_flag();
		$release        = self::held_email_release( true );
		CleanupInventory::each_site(
			static function () use ( $network_active, $network_wipe, $keep_pro_addon_rows, $release ): void {
				$wipe                   = $network_active ? $network_wipe : self::site_delete_flag();
				$release['keep_unsent'] = ! $wipe;
				TranslatedEmails::release_held( $release );
				self::clean_site( $wipe, $keep_pro_addon_rows );
			}
		);

		if ( $network_wipe ) {
			CleanupInventory::wipe_network_options_by_prefix( $keep_pro_addon_rows );
			CleanupInventory::wipe_user_meta();
		}

        do_action( 'TranslateWP/cleanup' );
    }

	/** The time budget covers the disconnects only, so it runs before any local cleanup. */
	private static function disconnect_network(): void {
		$now      = self::$clock ?? static fn(): float => microtime( true );
		$deadline = (float) $now() + self::UNINSTALL_DISCONNECT_BUDGET_SECONDS;

		CleanupInventory::each_site(
			static function ( int $blog_id ) use ( $now, $deadline ): void {
				$left = (int) floor( $deadline - (float) $now() );
				if ( $left >= 1 ) {
					self::disconnect_site( min( self::UNINSTALL_REQUEST_TIMEOUT_SECONDS, $left ), $blog_id );
				} elseif ( \TranslateWP\Components\SiteConnection::has_credentials() ) {
					$message = 'TranslateWP uninstall: blog %d skipped, the time for disconnects is spent;'
						. ' disconnect the site from the account portal.';
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( $message, $blog_id ) );
				}
			}
		);
	}

	private static function clean_site( bool $wipe, bool $keep_pro_addon_rows ): void {
		if ( $wipe ) {
			CleanupInventory::wipe_site_by_prefix( $keep_pro_addon_rows );
			return;
		}

		CleanupInventory::wipe_scheduled_hooks();
		IndexQueue::delete_all();
	}

	/** Held emails are sent before their delivery schedule goes; nothing would send them after. */
	private static function stop_schedules( array $release ): void {
		TranslatedEmails::release_held( $release );
		CleanupInventory::wipe_scheduled_hooks();
	}

	/** One time limit for sending held emails, shared by every site the request reaches. */
	private static function held_email_release( bool $keep_unsent ): array {
		$now = self::$clock ?? static fn(): float => microtime( true );

		return [
			'now'         => $now,
			'deadline'    => (float) $now() + TranslatedEmails::RELEASE_SECONDS,
			'keep_unsent' => $keep_unsent,
		];
	}

	private static function site_delete_flag(): bool {
		$advanced = get_option( 'translatewp_advanced', [] );

		return is_array( $advanced ) && ! empty( $advanced['delete_on_uninstall'] );
	}

	/** The setting's group is network only, so the booted plugin reads it from the network defaults. */
	private static function network_delete_flag(): bool {
		$advanced = SettingsScope::network_values()['translatewp_advanced'] ?? [];

		return is_array( $advanced ) && ! empty( $advanced['delete_on_uninstall'] );
	}

	/**
	 * An install activated before the scope was recorded counts as network
	 * active: then only the network default, off when missing, can wipe.
	 */
	private static function network_active(): bool {
		return self::SCOPE_SITE !== get_site_option( self::ACTIVATION_SCOPE_OPTION, '' );
	}

	/**
	 * Deleting the plugin counts as a disconnect: each connected site tells
	 * the service, which then starts its 30-day removal of the site's data
	 * (reconnecting inside that time restores it). The delete never waits
	 * long for the service and never fails because of it: on a network the
	 * disconnects share one time budget, and a site it does not reach stays
	 * connected for its owner to disconnect from the account portal.
	 */
	private static function disconnect_site(
		int $timeout_seconds = self::UNINSTALL_REQUEST_TIMEOUT_SECONDS,
		int $blog_id = 0
	): void {
		if ( ! \TranslateWP\Components\SiteConnection::has_credentials() ) {
			return;
		}

		$remote = \TranslateWP\Components\SiteRequests::post( '/v1/site/disconnect', [], $timeout_seconds );
		$status = is_wp_error( $remote ) ? (int) ( ( (array) $remote->get_error_data() )['status'] ?? 0 ) : 200;

		// Credentials the service revoked, or no longer accepts, are dropped.
		// After a failure they are kept: the owner can still disconnect from
		// a reinstall or from the account portal.
		if ( ! is_wp_error( $remote ) || 401 === $status ) {
			\TranslateWP\Components\SiteConnection::disconnect();
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'TranslateWP uninstall: %sthe disconnect failed (status %d: %s);'
					. ' disconnect the site from the account portal.',
				$blog_id > 0 ? 'blog ' . $blog_id . ': ' : '',
				$status,
				$remote->get_error_message()
			)
		);
	}

	public static function maybe_flush_rewrite_rules(): void {
		$pending_version = get_option( self::REWRITE_FLUSH_OPTION, '' );
		$stored_version  = get_option( self::REWRITE_VERSION_OPTION, '' );

		if ( '' === $pending_version && TRANSLATEWP_VERSION === $stored_version ) {
			return;
		}

		flush_rewrite_rules();
		update_option( self::REWRITE_VERSION_OPTION, TRANSLATEWP_VERSION );
		delete_option( self::REWRITE_FLUSH_OPTION );
	}
}
