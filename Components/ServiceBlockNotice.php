<?php

namespace TranslateWP\Components;

/**
 * A Content-Security-Policy on the site that leaves the service origin out of
 * connect-src stops every visitor translation without an error anyone sees.
 * The front end reports it when a user who administers the plugin views a
 * page; the record is shown as a notice on TranslateWP screens until it is
 * cleared or a week passes without a new report.
 */
class ServiceBlockNotice {

	public const OPTION             = 'translatewp_service_blocked';
	public const CHECK_AGAIN_ACTION = 'translatewp_service_block_check_again';

	private const REPORT_INTERVAL_SECONDS = 3600;
	private const LIFETIME_SECONDS        = 7 * 86400;
	private const PATH_MAX_LENGTH         = 200;
	private const DEFAULT_PORTS           = [
		'https' => 443,
		'http'  => 80,
	];

	/**
	 * The scheme, host and port the front end requests translations from,
	 * written as the browser writes an origin: lowercase, no default port.
	 */
	public static function service_origin(): string {
		$parts = wp_parse_url( RemoteApi::base_url() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) && ( self::DEFAULT_PORTS[ $scheme ] ?? null ) !== (int) $parts['port'] ? ':' . $parts['port'] : '';

		return $scheme . '://' . strtolower( $parts['host'] ) . $port;
	}

	/**
	 * Stores a report of the blocked service, at most once an hour. Only the
	 * path of the page is kept; a report naming any other origin is refused.
	 *
	 * @return array{recorded: bool}|\WP_Error
	 */
	public static function record( string $origin, string $page ) {
		if ( '' === $origin || self::service_origin() !== $origin ) {
			return new \WP_Error( 'invalid_origin', 'The origin is not the translation service.', [ 'status' => 400 ] );
		}

		$current = self::current();
		if ( null !== $current && $current['time'] > time() - self::REPORT_INTERVAL_SECONDS ) {
			return [ 'recorded' => false ];
		}

		$path = (string) wp_parse_url( $page, PHP_URL_PATH );
		update_option(
			self::OPTION,
			[
				'time'   => time(),
				'origin' => $origin,
				'path'   => 0 === strpos( $path, '/' ) ? substr( $path, 0, self::PATH_MAX_LENGTH ) : '/',
			],
			false
		);

		return [ 'recorded' => true ];
	}

	/**
	 * The stored report, or null when there is none or it is older than a week.
	 *
	 * @return array{time: int, origin: string, path: string}|null
	 */
	public static function current(): ?array {
		$record = get_option( self::OPTION, null );
		if ( ! is_array( $record ) || ! isset( $record['time'], $record['origin'], $record['path'] ) ) {
			return null;
		}

		if ( (int) $record['time'] <= time() - self::LIFETIME_SECONDS ) {
			self::clear();
			return null;
		}

		return [
			'time'   => (int) $record['time'],
			'origin' => (string) $record['origin'],
			'path'   => (string) $record['path'],
		];
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! AdminNotices::on_plugin_screen() || null === self::current() ) {
			return;
		}

		AdminNotices::mark_action_required();
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><form method="post" action="%s"><p><input type="hidden" name="action" value="%s">%s<button type="submit" class="button">%s</button></p></form></div>',
			esc_html__( 'Your site blocks TranslateWP.', 'translate' ),
			esc_html(
				sprintf(
					/* translators: %1$s: address of the translation service, such as https://api.translatewp.ai */
					__( 'A security header on this site (Content-Security-Policy) blocks %1$s, so visitors see the original language. Add %1$s to connect-src where the header is set (a security plugin, a code snippet or the server configuration).', 'translate' ),
					self::service_origin()
				)
			),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::CHECK_AGAIN_ACTION ),
			wp_nonce_field( self::CHECK_AGAIN_ACTION, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built nonce fields.
			esc_html__( 'Check again', 'translate' )
		);
	}

	/** Clears the report; the next blocked page view an administrator makes reports it again. */
	public static function handle_check_again(): void {
		check_admin_referer( self::CHECK_AGAIN_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to dismiss this notice.', 'translate' ), '', [ 'response' => 403 ] );
		}

		self::clear();
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=translatewp' ) );
		exit;
	}
}
