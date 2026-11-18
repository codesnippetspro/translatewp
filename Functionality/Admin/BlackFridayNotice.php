<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\BlackFridayNotice as NoticeComponent;

class BlackFridayNotice {

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );
		add_action( 'admin_notices', [ NoticeComponent::class, 'render' ], PHP_INT_MAX );
		add_action( 'network_admin_notices', [ NoticeComponent::class, 'render' ], PHP_INT_MAX );
		add_action( 'wp_ajax_' . NoticeComponent::DISMISS_ACTION, [ NoticeComponent::class, 'handle_ajax_dismiss' ] );
	}
}
