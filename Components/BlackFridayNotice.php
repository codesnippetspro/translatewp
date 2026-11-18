<?php

namespace TranslateWP\Components;

class BlackFridayNotice {

	public const DISMISS_ACTION = 'translatewp_dismiss_black_friday_notice';
	public const DISMISSED_META = 'translatewp_black_friday_2026_dismissed';
	private const ENDS_AT = '2026-12-07T22:59:00Z';
	private const OFFER_URL = 'https://translatewp.ai/black-friday/';

	private static function can_manage(): bool {
		if ( Access::can_administer() ) {
			return true;
		}
		foreach ( Access::CAPABILITIES as $capability ) {
			if ( Access::can( $capability ) ) {
				return true;
			}
		}
		return false;
	}

	public static function render( ?int $now = null ): void {
		if ( ! AdminNotices::on_plugin_screen() || ! self::can_manage()
			|| ( $now ?? time() ) >= strtotime( self::ENDS_AT ) || AdminNotices::has_action_required()
			|| get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) ) {
			return;
		}
		if ( SiteConnection::has_credentials() && 'invalidated' !== SiteConnection::connection_status()
			&& ! empty( ProEligibility::cached()['entitled'] ) ) {
			return;
		}

		printf(
			'%s<p>%s <a class="button button-secondary" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
			AdminNotices::open( 'notice-info', self::DISMISS_ACTION ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped by AdminNotices::open().
			esc_html__( 'Black Friday: lock in Orbital at $59 a year (instead of $119), or Lifetime at $249. Ends 7 December.', 'translate' ),
			esc_url( self::OFFER_URL ),
			esc_html__( 'See the offer', 'translate' )
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
