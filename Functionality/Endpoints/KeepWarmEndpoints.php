<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Functionality\KeepWarm;

class KeepWarmEndpoints extends BaseApiEndpoints {

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/admin' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

		$apply_lexicon = new PostEndpoint(
			$this->namespace,
			'apply-lexicon-now',
			[ $this, 'apply_lexicon_now' ],
			[ $this, 'retranslate_write_permissions_check' ]
		);
		$apply_lexicon->useMiddleware( $cors );

		$apply_localizer = new PostEndpoint(
			$this->namespace,
			'apply-localizer-now',
			[ $this, 'apply_localizer_now' ],
			[ $this, 'retranslate_write_permissions_check' ]
		);
		$apply_localizer->useMiddleware( $cors );

		$stale = new GetEndpoint(
			$this->namespace,
			'stale-translations',
			[ $this, 'stale_translations' ],
			[ $this, 'settings_data_permissions_check' ]
		);
		$stale->useMiddleware( $cors );

		return [
			$apply_lexicon,
			$apply_localizer,
			$stale,
		];
	}

	/**
	 * Queue the pages translated under an older lexicon for a forced
	 * retranslation with the current terms.
	 */
	public function apply_lexicon_now( $request ) {
		unset( $request );

		return [
			'success'  => true,
			'requeued' => KeepWarm::apply_lexicon_now(),
		];
	}

	/**
	 * Queue the pages translated under older date, time and unit
	 * settings for a forced retranslation.
	 */
	public function apply_localizer_now( $request ) {
		unset( $request );

		return [
			'success'  => true,
			'requeued' => KeepWarm::apply_localizer_now(),
		];
	}

	/**
	 * How many finished pages per language wait for a retranslation
	 * after a lexicon or localizer change.
	 */
	public function stale_translations( $request ) {
		unset( $request );

		return ( new IndexQueue() )->stale_counts();
	}
}
