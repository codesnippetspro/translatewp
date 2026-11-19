<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\ServiceBlockNotice as ServiceBlockNoticeComponent;

class ServiceBlockNotice {

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'admin_notices', [ ServiceBlockNoticeComponent::class, 'render_admin_notice' ] );
		add_action( 'admin_post_' . ServiceBlockNoticeComponent::CHECK_AGAIN_ACTION, [ ServiceBlockNoticeComponent::class, 'handle_check_again' ] );
	}
}
