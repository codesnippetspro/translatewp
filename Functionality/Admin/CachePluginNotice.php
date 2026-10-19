<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\CachePluginNotice as CachePluginNoticeComponent;

class CachePluginNotice {

	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;

		add_action( 'admin_notices', [ CachePluginNoticeComponent::class, 'render_admin_notice' ] );
		add_action( 'admin_post_' . CachePluginNoticeComponent::DISMISS_ACTION, [ CachePluginNoticeComponent::class, 'handle_dismiss' ] );
	}
}
