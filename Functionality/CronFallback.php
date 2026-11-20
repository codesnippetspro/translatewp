<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\AdminNotices;
use TranslateWP\Components\BackgroundEngine;
use TranslateWP\Components\ProviderHold;
use TranslateWP\Components\BackgroundSwitch;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\MutationLock;
use TranslateWP\Components\QueueExecution;
use TranslateWP\Components\SiteConnection;

class CronFallback {

	public const DISMISS_ACTION = 'translatewp_dismiss_cron_late';
	private const CHECK_PROVIDER = 'translatewp_check_provider';
	private const DISMISSED_TRANSIENT = 'translatewp_cron_late_dismissed_';

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );
		add_action( 'translatewp_pro_providers_saved', [ ProviderHold::class, 'settings_saved' ] );
		add_action( 'admin_post_' . self::CHECK_PROVIDER, [ $this, 'handle_provider_check' ] );
		add_action( 'admin_notices', [ $this, 'render_cron_late_notice' ] );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss' ] );
	}

	public static function run_refill_maintenance(): bool {
		if ( BackgroundSwitch::off() || ! SiteConnection::is_connected() || QueueExecution::expired() ) {
			return false;
		}
		if ( false !== get_option( IndexQueue::NORMALIZE_CURSOR_OPTION, false ) ) {
			return ( new IndexQueue() )->normalize_legacy_rows();
		}
		if ( false !== get_option( IndexQueue::DEFERRED_REFILLS_OPTION, false ) ) {
			( new IndexQueue() )->resume_deferred_refills();
			return true;
		}
		if ( false !== get_option( LexiconChanges::SEED_CURSOR_OPTION, false ) ) {
			LexiconChanges::run_seed_batch();
			return true;
		}
		if ( BackgroundEngine::run_deferred_task() ) {
			return true;
		}
		foreach ( BackgroundEngine::scheduled_tasks() as $task ) {
			if ( $task['at'] <= time() ) {
				wp_unschedule_event( $task['at'], $task['hook'], $task['args'] );
				do_action( $task['hook'], ...$task['args'] );
				return true;
			}
		}
		return false;
	}

	public function render_cron_late_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! AdminNotices::on_plugin_screen() ) {
			return;
		}
		ProviderHold::refresh();
		$hold = ProviderHold::state();
		if ( $hold ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p>',
				esc_html( 'model_unavailable' === $hold['reason'] ? __( 'Your AI provider cannot use the selected model. Translation is waiting.', 'translate' ) : __( 'Your AI provider did not accept the key. Translation is waiting.', 'translate' ) ),
				esc_url( admin_url( 'admin.php?page=translatewp-settings#ai-model' ) ),
				esc_html__( 'AI models', 'translate' )
			);
			if ( empty( $hold['probe'] ) && empty( $hold['manual'] ) ) {
				$token = ProviderHold::check_token( $hold );
				printf( '<form method="post" action="%s"><input type="hidden" name="action" value="%s"><input type="hidden" name="hold" value="%s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( self::CHECK_PROVIDER ), esc_attr( $token ) );
				wp_nonce_field( self::CHECK_PROVIDER . $token );
				printf( '<p><button type="submit" class="button">%s</button></p></form>', esc_html__( 'Check again now', 'translate' ) );
			}
			echo '</div>';
			return;
		}
		if ( get_transient( self::DISMISSED_TRANSIENT . get_current_user_id() ) ) {
			return;
		}
		if ( ! BackgroundSwitch::off() && ! $this->is_behind() && ! get_option( Indexer::READER_FAILURE_OPTION, false ) ) {
			return;
		}
		printf(
			'%s<p>%s <a href="%s">%s</a></p></div>',
			AdminNotices::open( 'notice-warning', self::DISMISS_ACTION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by AdminNotices.
			esc_html( self::notice_message() ),
			esc_url( admin_url( 'admin.php?page=translatewp#indexing' ) ),
			esc_html__( 'Open Indexing', 'translate' )
		);
	}

	public static function notice_message(): string {
		if ( BackgroundSwitch::off() ) {
			return __( 'Background translation is paused. No background work runs while it is paused.', 'translate' );
		}
		if ( get_transient( MutationLock::FAILURE_TRANSIENT ) ) {
			return __( 'Background translation is waiting because its database lock could not be stored. Ask your host to check database write access.', 'translate' );
		}
		if ( ! empty( get_option( BackgroundEngine::STATE_OPTION, [] )['stalled'] ) ) {
			return __( 'Background translation could not advance the same queue rows in three attempts. Open Indexing to review them.', 'translate' );
		}
		$reason = (string) get_option( Indexer::READER_FAILURE_OPTION, '' );
		if ( 'page_tls_failed' === $reason ) {
			return __( 'Background reading could not verify the site HTTPS certificate. Fix the certificate or open Indexing and keep it open to read pages in your browser.', 'translate' );
		}
		if ( in_array( $reason, [ 'page_fetch_401', 'page_fetch_403' ], true ) ) {
			return __( 'The site blocked its background page reader. Check HTTP authentication and security rules, or open Indexing and keep it open.', 'translate' );
		}
		if ( 'page_parser_unavailable' === $reason ) {
			return __( 'Background reading needs the PHP DOM extension. Enable it or open Indexing and keep it open to read pages in your browser.', 'translate' );
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return __( 'The translation service has not reached this site and WP-Cron is disabled. Open Indexing and keep it open while translation finishes.', 'translate' );
		}
		return __( 'Background translation is waiting: the service has not reached this site and scheduled tasks have not kept up. Check WP-Cron or open Indexing and keep it open while translation finishes.', 'translate' );
	}

	public function is_behind(): bool {
		if ( ! SiteConnection::is_connected() || BackgroundSwitch::off() ) {
			return false;
		}
		return BackgroundEngine::stalled();
	}

	public function handle_provider_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'translate' ), '', [ 'response' => 403 ] );
			return;
		}
		$token = sanitize_key( wp_unslash( $_POST['hold'] ?? '' ) );
		check_admin_referer( self::CHECK_PROVIDER . $token );
		ProviderHold::check_now( $token );
		wp_safe_redirect( admin_url( 'admin.php?page=translatewp' ) );
		exit;
	}

	public function handle_dismiss(): void {
		check_ajax_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
			return;
		}
		set_transient( self::DISMISSED_TRANSIENT . get_current_user_id(), 1, DAY_IN_SECONDS );
		wp_send_json_success();
	}
}
