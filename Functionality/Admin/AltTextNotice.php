<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\AltTextNotice as AltTextNoticeComponent;

class AltTextNotice {

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'admin_notices', [ AltTextNoticeComponent::class, 'render' ] );
		add_action( 'wp_ajax_' . AltTextNoticeComponent::DISMISS_ACTION, [ AltTextNoticeComponent::class, 'handle_ajax_dismiss' ] );
	}
}
