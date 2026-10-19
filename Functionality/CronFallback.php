<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\AdminNotices;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\SiteConnection;
use TranslateWP\Components\TranslationStore;

/**
 * Keeps the warm-up pipeline moving on hosts whose WP-Cron never fires.
 *
 * The index-queue push drain (Indexer::DRAIN_HOOK) and the translation-store
 * back-fill (TranslationStoreSync::BACKFILL_HOOK) are both scheduled as
 * single WP-Cron events. On a site whose loopback cron is broken - or that
 * simply gets no admin visits - those events sit forever: the store records
 * segments as pending and never resolves them, so a server-rendered SEO
 * title stays in the source language even though the browser (visitor) path
 * translates the body in seconds.
 *
 * This wires the same drain and back-fill into ordinary page requests. When
 * one of our own scheduled events is overdue (its next-run time is in the
 * past by more than a minute) or store segments are waiting with no back-fill
 * scheduled, an ordinary front-end request runs one drain batch and one
 * back-fill batch - at most once per minute per site, after the response has
 * flushed so the visitor never waits - and an admin page load does the same
 * inline and shows a notice explaining that scheduled tasks are late and
 * indexing is now happening on page views.
 *
 * It never runs during the visitor miss-proxy REST call itself (those already
 * schedule and inline-drain their own rows), during WP-Cron, or during Ajax.
 * It reuses the drain and back-fill handlers the pipeline already registered
 * on their hooks, so nothing is drained or back-filled twice.
 */
class CronFallback {

	public const DISMISS_ACTION = 'translatewp_dismiss_cron_late';

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
		add_action( 'admin_notices', [ $this, 'render_cron_late_notice' ] );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss' ] );
	}

	/**
	 * Front-end page view: run one drain and one back-fill batch after the
	 * response is flushed (FPM), else inline. Never on background requests.
	 */
	public function maybe_run_on_front_end(): void {
		if ( $this->is_background_request() || ! $this->should_kick() ) {
			return;
		}

		$this->throttle();
		$this->announced_late = true;

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			register_shutdown_function( [ $this, 'run_batch_after_flush' ] );

			return;
		}

		$this->run_batch();
	}

	/**
	 * Admin page load: an operator is present, so run the batch inline and let
	 * the notice explain why indexing is happening on page views.
	 */
	public function maybe_run_in_admin(): void {
		if ( ! $this->should_kick() ) {
			return;
		}

		$this->throttle();
		$this->announced_late = true;
		$this->run_batch();
	}

	public function run_batch_after_flush(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$this->run_batch();
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

		\TranslateWP\Components\ActionLog::record( 'WP-Cron is late; the plugin ran its queue from a page request', 'warning' );
		$this->clear_if_late( Indexer::DRAIN_HOOK );
		do_action( Indexer::DRAIN_HOOK );

		if ( $this->scheduled_is_late( ReadinessReconcile::HOOK ) ) {
			$this->clear_if_late( ReadinessReconcile::HOOK );
			do_action( ReadinessReconcile::HOOK );
		}

		if ( TranslationStore::has_pending_work() ) {
			$this->clear_if_late( TranslationStoreSync::BACKFILL_HOOK );
			do_action( TranslationStoreSync::BACKFILL_HOOK );
		}
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

		if ( ! $this->announced_late && ! $this->is_behind() ) {
			return;
		}

		printf(
			'%s<p>%s</p></div>',
			AdminNotices::open( 'notice-warning', self::DISMISS_ACTION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in AdminNotices::open().
			esc_html__(
				'TranslateWP: scheduled tasks are running late on this site, so translations are being indexed as pages are viewed. Indexing keeps progressing this way, just more slowly than usual.',
				'translate'
			)
		);
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
		foreach ( ( new IndexQueue() )->counts() as $count ) {
			if ( (int) ( $count['pending_push'] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
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
