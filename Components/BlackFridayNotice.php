<?php

namespace TranslateWP\Components;

class BlackFridayNotice {

	public const DISMISS_ACTION = 'translatewp_dismiss_black_friday_notice';
	public const DISMISSED_META = 'translatewp_black_friday_2026_dismissed';
	private const ENDS_AT = '2026-12-07T22:59:00Z';
	private const CODE_SNIPPETS_STARTS_AT = '2026-11-23T00:00:00Z';
	private const CODE_SNIPPETS_ENDS_AT = '2026-12-01T00:00:00Z';
	private const OFFER_URL = 'https://translatewp.ai/black-friday/';
	private const CODE_SNIPPETS_OFFER_URL = 'https://codesnippets.pro/black-friday/';

	private static function can_manage(): bool {
		return is_network_admin() ? current_user_can( 'manage_network_options' ) : Access::can_administer();
	}

	private static function has_orbital(): bool {
		return SiteConnection::has_credentials() && 'invalidated' !== SiteConnection::connection_status()
			&& ! empty( ProEligibility::cached()['entitled'] );
	}

	private static function button( string $url, string $label ): string {
		return sprintf(
			'<a class="button button-secondary" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ], 10, 0 );
		add_action( 'network_admin_notices', [ self::class, 'render' ], 10, 0 );
	}

	public static function render( ?int $now = null ): void {
		$now = $now ?? time();
		if ( ! AdminNotices::on_plugin_screen() || ! self::can_manage() || AdminNotices::has_action_required()
			|| get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) ) {
			return;
		}

		$lines   = [];
		$buttons = [];
		if ( $now < strtotime( self::ENDS_AT ) && ! self::has_orbital() ) {
			$lines[]   = esc_html__( 'Lock in Orbital at $59 a year (instead of $119), or Lifetime at $249. Ends 7 December.', 'translate' );
			$buttons[] = self::button( self::OFFER_URL, __( 'See the TranslateWP offer', 'translate' ) );
		}
		if ( $now >= strtotime( self::CODE_SNIPPETS_STARTS_AT ) && $now < strtotime( self::CODE_SNIPPETS_ENDS_AT )
			&& ! ( defined( 'CODE_SNIPPETS_PRO' ) && constant( 'CODE_SNIPPETS_PRO' ) ) ) {
			$lines[]   = esc_html__( 'Code Snippets Pro: save up to 40% this Black Friday, until 1 December.', 'translate' );
			$buttons[] = self::button( self::CODE_SNIPPETS_OFFER_URL, __( 'See the Code Snippets offer', 'translate' ) );
		}
		if ( ! $lines ) {
			return;
		}

		printf(
			'%s<p><strong>%s</strong> %s</p><p>%s</p></div>',
			AdminNotices::open( 'notice-info', self::DISMISS_ACTION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in AdminNotices::open().
			esc_html__( 'Black Friday', 'translate' ),
			implode( '<br>', $lines ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line escaped above.
			implode( ' ', $buttons ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each button escaped in button().
		);
	}

	public static function handle_ajax_dismiss(): void {
		check_ajax_referer( self::DISMISS_ACTION );
		if ( ! self::can_manage() ) {
			wp_send_json_error( null, 403 );
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );
		wp_send_json_success();
	}
}
