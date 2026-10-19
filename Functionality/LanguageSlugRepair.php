<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\LanguageSlugs;

class LanguageSlugRepair {

	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;

		add_action( 'init', [ LanguageSlugs::class, 'repair_stored' ] );
	}
}
