<?php

namespace TranslateWP\Functionality;

use TranslateWP\Entities\CPTs\Lexicon;
use TranslateWP\Entities\CPTs\Metrics;

class CustomPostTypes {


	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name = $plugin_name;
		$this->plugin_version = $plugin_version;

		add_action( 'init', [ $this, 'register_post_types' ] );
	}

	public function register_post_types() {
		$lexicon = new Lexicon();
		$lexicon->register();

		$metrics = new Metrics();
		$metrics->register();
	}
}
