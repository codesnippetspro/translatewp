<?php

namespace TranslateWP\Components;

/**
 * The site's roles, for the settings that name them. Every visitor and every
 * role is served translations.
 */
class Audience {

	public const SETTING_KEY = 'visible_roles';

	/** The roles of this site as slug => display name. */
	public static function role_names(): array {
		$roles = function_exists( 'wp_roles' ) ? wp_roles() : null;
		$names = $roles && method_exists( $roles, 'get_names' ) ? $roles->get_names() : [];
		$out   = [];
		foreach ( (array) $names as $slug => $name ) {
			$out[ (string) $slug ] = function_exists( 'translate_user_role' ) ? translate_user_role( (string) $name ) : (string) $name;
		}

		return $out;
	}

	/** Known role slugs in the given order; anything else is dropped; empty input is an empty list. */
	public static function sanitize_roles( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$known = array_keys( self::role_names() );
		$out   = [];
		foreach ( $raw as $role ) {
			$slug = sanitize_key( (string) $role );
			if ( in_array( $slug, $known, true ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	public static function visible_roles(): array {
		$settings = Options::get( 'translations.settings' );
		$roles    = is_array( $settings ) ? ( $settings[ self::SETTING_KEY ] ?? [] ) : [];

		return self::sanitize_roles( $roles );
	}

	/**
	 * Whether the current request is served translations: always. Seeing
	 * translations is not a role matter; the stored role list is ignored.
	 */
	public static function allows_current_user(): bool {
		return true;
	}
}
