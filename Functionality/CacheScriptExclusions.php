<?php

namespace TranslateWP\Functionality;

/**
 * Keeps the visitor runtime out of the "delay" and "defer" JavaScript
 * optimisations of the common cache plugins. A delayed runtime draws the
 * switcher only after the visitor's first click or scroll; a deferred
 * dependency loads after the runtime that needs it. The dependencies are
 * listed with the runtime because an excluded script still fails when what
 * it imports is held back.
 */
class CacheScriptExclusions {

	private const FILTERS = [
		'rocket_delay_js_exclusions',
		'rocket_exclude_defer_js',
		'litespeed_optm_js_defer_exc',
		'litespeed_optimize_js_excludes',
		'perfmatters_delay_js_exclusions',
		'perfmatters_defer_js_exclusions',
	];

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		foreach ( self::FILTERS as $filter ) {
			add_filter( $filter, [ self::class, 'add_patterns' ] );
		}
	}

	/** The script URL fragments and inline markers a cache plugin must not delay or defer. */
	public static function patterns(): array {
		$folder = defined( 'TRANSLATEWP_BASENAME' ) ? dirname( TRANSLATEWP_BASENAME ) : 'translate';

		return [
			'/plugins/' . $folder . '/build/',
			'window.TranslateWP',
			'wp-includes/js/dist/vendor/wp-polyfill',
			'wp-includes/js/dist/vendor/react',
			'wp-includes/js/dist/i18n',
			'wp-includes/js/dist/hooks',
			'wp-includes/js/dist/api-fetch',
			'wp-includes/js/dist/dom-ready',
			'wp-includes/js/dist/element',
			'wp-includes/js/dist/url',
		];
	}

	public static function add_patterns( $exclusions ): array {
		$exclusions = is_array( $exclusions ) ? $exclusions : [];

		return array_values( array_unique( array_merge( $exclusions, self::patterns() ) ) );
	}
}
