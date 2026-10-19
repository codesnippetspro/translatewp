<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\TranslationSwitchNotice as NoticeComponent;

class TranslationSwitchNotice {

	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;

		add_action( 'admin_notices', [ NoticeComponent::class, 'render_admin_notice' ] );
		add_action( 'network_admin_notices', [ NoticeComponent::class, 'render_network_notice' ] );
		add_action( 'admin_post_' . NoticeComponent::DISMISS_ACTION, [ NoticeComponent::class, 'handle_dismiss' ] );
	}
}
