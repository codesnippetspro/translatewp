<?php

namespace TranslateWP\Functionality;

use TranslateWP\Functionality\Endpoints\Scanning;
use TranslateWP\Functionality\Endpoints\Management;
use TranslateWP\Functionality\Endpoints\Admin;
use TranslateWP\Functionality\Endpoints\Transparency;
use TranslateWP\Functionality\Endpoints\LexiconCandidates;
use TranslateWP\Functionality\Endpoints\Indexing;
use TranslateWP\Functionality\Endpoints\KeepWarmEndpoints;
use TranslateWP\Functionality\Endpoints\Connect as ConnectEndpoints;
use TranslateWP\Functionality\Endpoints\TranslationJobs as TranslationJobEndpoints;
use TranslateWP\Functionality\Endpoints\TranslationPageMap;

class ApiEndpoints {


	protected $plugin_name;
	protected $plugin_version;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name = $plugin_name;
		$this->plugin_version = $plugin_version;

		$this->initialize_endpoint_groups();
	}

	public function initialize_endpoint_groups() {
		// Scanning endpoints.
		new Scanning( $this->plugin_name, $this->plugin_version );

		// Management endpoints.
		new Management( $this->plugin_name, $this->plugin_version );

		// Admin endpoints.
		new Admin( $this->plugin_name, $this->plugin_version );

		// Transparency endpoints (job events + feedback).
		new Transparency( $this->plugin_name, $this->plugin_version );
		new LexiconCandidates( $this->plugin_name, $this->plugin_version );

		// Keep-warm endpoints (lexicon apply-now).
		new KeepWarmEndpoints( $this->plugin_name, $this->plugin_version );

		// Warm-up indexer endpoints.
		new Indexing( $this->plugin_name, $this->plugin_version );

		// Portal connect endpoints.
		new ConnectEndpoints( $this->plugin_name, $this->plugin_version );

		// Visitor translation job proxy endpoints.
		new TranslationJobEndpoints( $this->plugin_name, $this->plugin_version );

		// Server-side translation store read (Pro seam + debugging).
		new TranslationPageMap( $this->plugin_name, $this->plugin_version );
		new Endpoints\Quota( $this->plugin_name, $this->plugin_version );
	}
}
