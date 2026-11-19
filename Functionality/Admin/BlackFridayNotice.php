<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\BlackFridayNotice as NoticeComponent;

class BlackFridayNotice {

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );
		// Hooked once every TranslateWP notice is hooked, at their priority: it prints last among them
		// and right after them, and it sees whether one of them asked for action.
		add_action( 'translatewp_loaded', [ NoticeComponent::class, 'register' ] );
		add_action( 'wp_ajax_' . NoticeComponent::DISMISS_ACTION, [ NoticeComponent::class, 'handle_ajax_dismiss' ] );
	}
}
