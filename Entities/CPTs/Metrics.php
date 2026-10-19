<?php

namespace TranslateWP\Entities\CPTs;

/**
 * Daily figures an older version stored on the site. Nothing writes or reads
 * them any more: the dashboard answers from the service's usage and the index
 * queue. The post type stays registered so the uninstall inventory still
 * finds and removes what is stored.
 */
class Metrics {

	public const POST_TYPE = 'translatewp_metric';

	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'public'       => false,
				'show_ui'      => false,
				'show_in_menu' => false,
				'show_in_rest' => false,
				'has_archive'  => false,
				'rewrite'      => false,
				'supports'     => [ 'title' ],
			]
		);
	}
}
