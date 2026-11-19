<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\BackgroundEngine;

class BackgroundWork {

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );
		add_action( BackgroundEngine::CRON_HOOK, [ BackgroundEngine::class, 'cron' ] );
		add_action( BackgroundEngine::WAKE_HOOK, [ BackgroundEngine::class, 'wake' ] );
		add_action( BackgroundEngine::ELIGIBILITY_HOOK, [ BackgroundEngine::class, 'refresh_eligibility' ] );
		add_action( 'admin_init', [ BackgroundEngine::class, 'install' ] );
	}
}
