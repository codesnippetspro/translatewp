<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\MultisiteSettings;

class Multisite {

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		MultisiteSettings::bootstrap();
	}
}
