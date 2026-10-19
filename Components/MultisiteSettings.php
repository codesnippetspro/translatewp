<?php

namespace TranslateWP\Components;

class MultisiteSettings {

	private const NETWORK_DEFAULTS_OPTION = 'translatewp_network_settings_defaults';
	private const OPTION_NAMES = [
		'translatewp_languages',
		'translatewp_translations',
		'translatewp_advanced',
	];

	public static function bootstrap(): void {
		if ( ! is_multisite() ) {
			return;
		}

		foreach ( self::OPTION_NAMES as $option_name ) {
			add_filter( 'option_' . $option_name, [ self::class, 'merge_network_defaults' ], 10, 2 );
			add_filter( 'default_option_' . $option_name, [ self::class, 'merge_network_defaults' ], 10, 2 );
		}
	}

	public static function merge_network_defaults( $value, string $option_name ) {
		return SettingsScope::resolve( $option_name, $value );
	}

	public static function network_defaults(): array {
		$defaults = get_site_option( self::NETWORK_DEFAULTS_OPTION, [] );

		return is_array( $defaults ) ? $defaults : [];
	}

	public static function site_ids(): array {
		if ( ! is_multisite() ) {
			return [];
		}

		return array_values(
			array_map(
				static function ( $site_id ): int {
					return absint( $site_id );
				},
				get_sites(
					[
						'fields' => 'ids',
						'number' => 0,
					]
				)
			)
		);
	}
}
