<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\AdminNotices;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\LoopbackKick;
use TranslateWP\Components\QueueExecution;
use TranslateWP\Components\MutationLock;
use TranslateWP\Components\CrawlerDetection;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\TranslationStore;

class CronFallback {

	public const DISMISS_ACTION = 'translatewp_dismiss_cron_late';
	public const MAINTENANCE_HOOK = 'translatewp_background_queue_maintenance';
	private const REFILL_HOOKS = [ KeepWarm::LEXICON_DEBOUNCE_HOOK, KeepWarm::SETTINGS_DEBOUNCE_HOOK, KeepWarm::VERSIONED_REFILL_HOOK ];

	/** Per-user flag, suffixed with the user id, that hides the late-cron notice for a day after its X. */
	private const DISMISSED_TRANSIENT = 'translatewp_cron_late_dismissed_';

	/** Once-per-minute-per-site throttle for the request-time kick. */
	private const KICK_TRANSIENT = 'translatewp_cron_fallback_kick';

	private const KICK_THROTTLE_SECONDS = 60;

	/** A scheduled event this far in the past counts as a cron that is not firing. */
	private const OVERDUE_SECONDS = 60;

	/**
	 * True once this request has detected a late-cron backlog, so the admin
	 * notice still renders after the same request has drained the backlog away.
	 *
	 * @var bool
	 */
	private bool $announced_late = false;

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'template_redirect', [ $this, 'maybe_run_on_front_end' ] );
		add_action( 'admin_init', [ $this, 'maybe_run_in_admin' ] );
		add_action( self::MAINTENANCE_HOOK, [ $this, 'run_maintenance' ] );
		add_action( 'admin_notices', [ $this, 'render_cron_late_notice' ] );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss' ] );
	}

	public function maybe_run_on_front_end(): void {
		if ( $this->is_background_request() || is_admin() || CrawlerDetection::is_crawler_request() || ! $this->should_kick() ) {
			return;
		}

		$this->throttle();
		$this->announced_late = true;

		LoopbackKick::fire();
		if ( LoopbackKick::unhealthy() && self::can_finish_response() ) {
			add_action( 'shutdown', [ $this, 'run_after_flush' ], PHP_INT_MAX, 0 );
		}
	}

	public static function can_finish_response(): bool {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	public function run_after_flush(): void {
		if ( $this->is_background_request() || is_admin() || CrawlerDetection::is_crawler_request() || ! LoopbackKick::unhealthy() ) {
			return;
		}
		$finished = function_exists( 'fastcgi_finish_request' ) ? fastcgi_finish_request()
			: ( function_exists( 'litespeed_finish_request' ) && litespeed_finish_request() );
		if ( true === $finished ) {
			QueueExecution::run_reduced();
		}
	}

	public function maybe_run_in_admin(): void {
		if ( $this->is_background_request() || ! $this->should_kick() ) {
			return;
		}

		$this->throttle();
		$this->announced_late = true;
		LoopbackKick::probe();
		QueueExecution::run( [ $this, 'run_batch' ] );
	}

	/**
	 * One push-drain batch and one back-fill batch through the handlers the
	 * pipeline already registered. Overdue events are cleared first so a queue
	 * that has caught up stops being seen as late and the handlers reschedule
	 * to a fresh time only while work remains.
	 */
	public function run_batch(): void {
		if ( ! SiteConnection::is_connected() ) {
			return;
		}

		\TranslateWP\Components\ActionLog::record( 'WP-Cron is late; the plugin ran its queue from an admin request', 'warning' );
		$this->clear_if_late( Indexer::DRAIN_HOOK );
		do_action( Indexer::DRAIN_HOOK );
		$this->run_maintenance();
	}

	public function run_maintenance(): void {
		if ( ! SiteConnection::is_connected() ) {
			return;
		}

		self::run_refill_maintenance();
		if ( QueueExecution::expired() ) {
			return;
		}

		if ( $this->scheduled_is_late( ReadinessReconcile::HOOK ) ) {
			$this->clear_if_late( ReadinessReconcile::HOOK );
			do_action( ReadinessReconcile::HOOK );
		}

		if ( TranslationStore::has_pending_work() ) {
			$this->clear_if_late( TranslationStoreSync::BACKFILL_HOOK );
			do_action( TranslationStoreSync::BACKFILL_HOOK );
		}
	}

	public static function run_refill_maintenance(): bool {
		if ( ! SiteConnection::is_connected() || QueueExecution::expired() ) {
			return false;
		}
		if ( false !== get_option( IndexQueue::DEFERRED_REFILLS_OPTION, false ) ) {
			( new IndexQueue() )->resume_deferred_refills();
			return true;
		}
		if ( false !== get_option( LexiconChanges::SEED_CURSOR_OPTION, false ) ) {
			LexiconChanges::run_seed_batch();
			return true;
		}
		$ran = false;
		foreach ( self::REFILL_HOOKS as $hook ) {
			$next = wp_next_scheduled( $hook );
			if ( false !== $next && (int) $next <= time() - self::OVERDUE_SECONDS ) {
				wp_unschedule_event( (int) $next, $hook );
				do_action( $hook );
				$ran = true;
				if ( QueueExecution::is_reduced() ) {
					break;
				}
			}
		}
		return $ran;
	}

	/**
	 * Shown on TranslateWP screens to users who manage the plugin, with an X
	 * that hides it for that user for a day. The Indexing tab keeps its own
	 * persistent signal.
	 */
	public function render_cron_late_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! AdminNotices::on_plugin_screen() ) {
			return;
		}

		if ( get_transient( self::DISMISSED_TRANSIENT . get_current_user_id() ) ) {
			return;
		}

		if ( ! $this->announced_late && ! $this->is_behind() && ! get_option( Indexer::READER_FAILURE_OPTION, false ) && ! get_transient( MutationLock::FAILURE_TRANSIENT ) ) {
			return;
		}

		printf(
			'%s<p>%s</p></div>',
			AdminNotices::open( 'notice-warning', self::DISMISS_ACTION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in AdminNotices::open().
			esc_html( self::notice_message() )
		);
	}

	public static function notice_message(): string {

		if ( get_transient( MutationLock::FAILURE_TRANSIENT ) ) {
			return __( 'Background translation is waiting because the database does not support its worker lock. Ask your host to enable named database locks.', 'translate' );
		}
		$reader_error = (string) get_option( Indexer::READER_FAILURE_OPTION, '' );
		if ( 'page_tls_failed' === $reader_error ) {
			return __( 'Background reading could not verify the site HTTPS certificate. Fix the certificate or the site local DNS. Translation work for unread pages is waiting.', 'translate' );
		}
		if ( in_array( $reader_error, [ 'page_fetch_401', 'page_fetch_403' ], true ) ) {
			return __( 'The site blocked its background page reader. Check HTTP authentication and security rules. Translation work for unread pages is waiting.', 'translate' );
		}
		if ( 'page_parser_unavailable' === $reader_error ) {
			return __( 'Background reading needs the PHP DOM extension. Enable it on the server so unread pages can be translated.', 'translate' );
		}
		if ( ! LoopbackKick::unhealthy() ) {
			return __( 'TranslateWP: scheduled tasks are running late. Translation work is waiting for a background request. Check WP-Cron and loopback requests if it does not resume.', 'translate' );
		}
		$reason = (string) get_transient( LoopbackKick::FAILURE_TRANSIENT );
		if ( 'certificate' === $reason ) {
			$cause = __( 'The site cannot call itself because its security certificate could not be verified.', 'translate' );
		} elseif ( 'http_auth' === $reason ) {
			$cause = __( 'The site cannot call itself because HTTP authentication is required.', 'translate' );
		} else {
			$cause = __( 'The site could not confirm its background worker. A security rule or hosting restriction may be blocking the request.', 'translate' );
		}
		$mode = self::can_finish_response()
			? __( 'Background translation is running in reduced mode after page responses. Check the site loopback requests to restore normal background processing.', 'translate' )
			: __( 'Translation work is waiting because this server cannot finish a page response before doing background work. Enable WP-Cron or fix the site loopback requests.', 'translate' );
		return $cause . ' ' . $mode;
	}

	/** The notice X, posted by Functionality\Admin\NoticeDismiss. */
	public function handle_dismiss(): void {
		check_ajax_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
			return;
		}

		set_transient( self::DISMISSED_TRANSIENT . get_current_user_id(), 1, DAY_IN_SECONDS );
		wp_send_json_success();
	}

	/**
	 * True when a scheduled runner has not kept up: the push drain is overdue,
	 * or store segments are waiting on a back-fill that is overdue or was never
	 * left scheduled.
	 */
	public function is_behind(): bool {
		if ( ! SiteConnection::is_connected() ) {
			return false;
		}

		if ( false !== get_option( LexiconChanges::SEED_CURSOR_OPTION, false ) || false !== get_option( IndexQueue::DEFERRED_REFILLS_OPTION, false ) ) {
			$next = wp_next_scheduled( Indexer::DRAIN_HOOK );
			if ( false === $next || (int) $next <= time() - self::OVERDUE_SECONDS ) {
				return true;
			}
		}
		foreach ( self::REFILL_HOOKS as $hook ) {
			if ( $this->scheduled_is_late( $hook ) ) {
				return true;
			}
		}

		// An overdue drain event counts only while push work waits in the
		// queue; a stale event on a queue the loopback kick already emptied
		// is cleared instead.
		if ( $this->scheduled_is_late( Indexer::DRAIN_HOOK ) ) {
			if ( self::queue_has_push_work() ) {
				return true;
			}
			$this->clear_if_late( Indexer::DRAIN_HOOK );
		}

		if ( TranslationStore::has_pending_work() ) {
			$next = wp_next_scheduled( TranslationStoreSync::BACKFILL_HOOK );
			if ( false === $next || (int) $next <= time() - self::OVERDUE_SECONDS ) {
				return true;
			}
		}

		if ( $this->scheduled_is_late( ReadinessReconcile::HOOK ) ) {
			return true;
		}

		return false;
	}

	private static function queue_has_push_work(): bool {
		$next = ( new IndexQueue() )->next_due_at();
		return null !== $next && $next <= time();
	}

	private function should_kick(): bool {
		if ( ! $this->is_behind() ) {
			return false;
		}

		return false === get_transient( self::KICK_TRANSIENT );
	}

	private function throttle(): void {
		set_transient( self::KICK_TRANSIENT, time(), self::KICK_THROTTLE_SECONDS );
	}

	private function scheduled_is_late( string $hook ): bool {
		$next = wp_next_scheduled( $hook );

		return false !== $next && (int) $next <= time() - self::OVERDUE_SECONDS;
	}

	private function clear_if_late( string $hook ): void {
		$next = wp_next_scheduled( $hook );
		if ( false !== $next && (int) $next <= time() - self::OVERDUE_SECONDS ) {
			wp_unschedule_event( (int) $next, $hook );
		}
	}

	private function is_background_request(): bool {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}
