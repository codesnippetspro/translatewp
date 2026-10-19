<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\PluginCoexistence as CoexistenceComponent;

class PluginCoexistence {

	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;

		add_action( 'admin_notices', [ CoexistenceComponent::class, 'render_admin_notice' ] );
		add_action( 'wp_ajax_' . CoexistenceComponent::DISMISS_ACTION, [ CoexistenceComponent::class, 'handle_ajax_dismiss' ] );
		add_action( 'admin_post_' . CoexistenceComponent::DEACTIVATE_ACTION, [ $this, 'deactivate_other_plugin' ] );
	}

	public function deactivate_other_plugin(): void {
		\TranslateWP\Components\ActionLog::record( 'Another translation plugin was deactivated from the conflict notice', 'warning' );
		CoexistenceComponent::handle_deactivate();
		exit;
	}
}
