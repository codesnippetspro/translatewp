<?php

namespace TranslateWP\Components;

/**
 * Who may manage a site's translations.
 *
 * Administrators always may, and only they administer the plugin: the
 * account connection, their own AI key, billing, languages, SEO and the
 * delete-data rule. A site admin can also grant chosen users who can edit
 * pages (an editor, say) the right to manage translations: the translation
 * settings, the Lexicon and the switcher settings, and nothing else. The
 * grant is kept per site, so on a network it is made by each site's admin
 * and does not travel to another site. It can be withdrawn at any time.
 */
class Access {

	public const CAPABILITY    = 'translatewp_manage_translations';
	public const GRANTS_OPTION = 'translatewp_translation_managers';

	public const EDIT        = 'translatewp_edit_translations';
	public const RETRANSLATE = 'translatewp_retranslate';
	public const SETTINGS    = 'translatewp_manage_settings';
	public const LEXICON     = 'translatewp_manage_lexicon';

	public const CAPABILITIES = [ self::EDIT, self::RETRANSLATE, self::SETTINGS, self::LEXICON ];

	/** The role map's keys, as stored in `translatewp_access`, and the capability each gives. */
	public const ROLE_MAP_KEYS = [
		'edit_translations' => self::EDIT,
		'retranslate'       => self::RETRANSLATE,
		'manage_settings'   => self::SETTINGS,
		'manage_lexicon'    => self::LEXICON,
	];

	public const ROLE_MAP_OPTION = 'translatewp_access';

	/** What the per-user grant gives: the translation and switcher settings, and the Lexicon. */
	private const NAMED_GRANT_CAPABILITIES = [ self::SETTINGS, self::LEXICON ];

	/** What an older addon bundle opens for `canManage`: the editor, placement and retranslation. */
	private const FULL_MANAGER = [ self::EDIT, self::SETTINGS, self::RETRANSLATE ];

	/** The settings a granted user may change. */
	private const DELEGATED_SETTINGS = [
		'translations.settings',
		'translations.context',
		'translations.style',
		'languages.switcher',
	];

	/** What a granted user may read besides: the languages the settings are shown for. */
	private const READ_ONLY_SETTINGS = [
		'languages.list',
	];

	/** Whether the current user administers the plugin. */
	public static function can_administer(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user is the full manager an older addon bundle
	 * expects behind `canManage`: one who edits translations, manages the
	 * settings and retranslates.
	 */
	public static function can_manage_translations(): bool {
		foreach ( self::FULL_MANAGER as $capability ) {
			if ( ! self::can( $capability ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a user holds one of the four capabilities on this site:
	 * administrators always; otherwise through a role in the site's
	 * resolved role map, or, for the settings and the Lexicon, the
	 * per-user grant.
	 *
	 * @param string $capability One of the CAPABILITIES.
	 * @param int    $user_id    The user, the current one when 0.
	 */
	public static function can( string $capability, int $user_id = 0 ): bool {
		if ( ! in_array( $capability, self::CAPABILITIES, true ) || ! function_exists( 'user_can' ) ) {
			return false;
		}
		$user_id = $user_id > 0 ? $user_id : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		if ( $user_id < 1 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return self::holds( $capability, self::roles_of( $user_id ), user_can( $user_id, 'edit_pages' ), $user_id );
	}

	/**
	 * Whether the current user is not an administrator and has an admin page
	 * of the plugin: the settings or the Lexicon.
	 */
	public static function is_delegate(): bool {
		return ! self::can_administer() && ( self::can( self::SETTINGS ) || self::can( self::LEXICON ) );
	}

	/**
	 * The admin page the current user opens TranslateWP on for this site:
	 * the Dashboard for an administrator; for anyone else, once the site is
	 * set up, the Settings when held, else the Lexicon. Null when the user
	 * has no admin page here, as with edit or retranslate alone.
	 */
	public static function admin_page(): ?string {
		if ( self::can_administer() ) {
			return 'translatewp';
		}
		if ( SiteConnection::needs_onboarding() ) {
			return null;
		}
		if ( self::can( self::SETTINGS ) ) {
			return 'translatewp-settings';
		}

		return self::can( self::LEXICON ) ? 'translatewp-lexicon' : null;
	}

	/**
	 * The site's role map as it applies here: the network default, the
	 * site's own value or the network's lock.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function role_map(): array {
		$stored = SettingsScope::current( self::ROLE_MAP_OPTION );

		return self::sanitize_role_map( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Replaces this site's own role map.
	 *
	 * @param mixed $map The map from the role card.
	 * @return array<string, array<string, bool>> The map kept.
	 */
	public static function set_role_map( $map ): array {
		$kept = self::sanitize_role_map( $map );
		update_option( self::ROLE_MAP_OPTION, $kept, false );

		return $kept;
	}

	/**
	 * Known keys and known roles only, each role true or false; the
	 * administrator is never stored, it holds everything.
	 *
	 * @param mixed $raw A role map.
	 * @return array<string, array<string, bool>>
	 */
	public static function sanitize_role_map( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$known = array_keys( Audience::role_names() );
		$out   = [];
		foreach ( array_keys( self::ROLE_MAP_KEYS ) as $key ) {
			if ( ! is_array( $raw[ $key ] ?? null ) ) {
				continue;
			}
			foreach ( $raw[ $key ] as $role => $allowed ) {
				$role = sanitize_key( (string) $role );
				if ( 'administrator' !== $role && in_array( $role, $known, true ) ) {
					$out[ $key ][ $role ] = (bool) $allowed;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether these roles, or the per-user grant, give the capability.
	 *
	 * @param string   $capability One of the CAPABILITIES.
	 * @param string[] $roles      The user's roles.
	 * @param bool     $edits_pages Whether the user can edit pages.
	 * @param int      $user_id    The user.
	 */
	private static function holds( string $capability, array $roles, bool $edits_pages, int $user_id ): bool {
		$key = array_search( $capability, self::ROLE_MAP_KEYS, true );
		$map = self::role_map();
		foreach ( $roles as $role ) {
			if ( true === ( $map[ $key ][ (string) $role ] ?? false ) ) {
				return true;
			}
		}

		return in_array( $capability, self::NAMED_GRANT_CAPABILITIES, true ) && $edits_pages && self::is_granted( $user_id );
	}

	/**
	 * The roles a user holds on this site.
	 *
	 * @param int $user_id The user.
	 * @return string[]
	 */
	private static function roles_of( int $user_id ): array {
		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() === $user_id && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
		} else {
			$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		}

		return is_object( $user ) ? array_map( 'strval', (array) ( $user->roles ?? [] ) ) : [];
	}

	public static function is_delegated_setting( string $path ): bool {
		return in_array( $path, self::DELEGATED_SETTINGS, true );
	}

	public static function is_readable_setting( string $path ): bool {
		return self::is_delegated_setting( $path ) || in_array( $path, self::READ_ONLY_SETTINGS, true );
	}

	/**
	 * Whether the current user may read a settings path: an administrator
	 * any; Manage settings the delegated settings; Manage settings or the
	 * Lexicon the language list.
	 *
	 * @param string $path The option path.
	 */
	public static function may_read_setting( string $path ): bool {
		if ( self::can_administer() ) {
			return true;
		}
		if ( self::is_delegated_setting( $path ) ) {
			return self::can( self::SETTINGS );
		}

		return in_array( $path, self::READ_ONLY_SETTINGS, true ) && ( self::can( self::SETTINGS ) || self::can( self::LEXICON ) );
	}

	/**
	 * Whether the current user may change a settings path: an administrator
	 * any; Manage settings the delegated settings only.
	 *
	 * @param string $path The option path.
	 */
	public static function may_write_setting( string $path ): bool {
		return self::can_administer() || ( self::is_delegated_setting( $path ) && self::can( self::SETTINGS ) );
	}

	public static function is_granted( int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, self::granted_ids(), true );
	}

	/**
	 * The users granted on this site.
	 *
	 * @return int[]
	 */
	public static function granted_ids(): array {
		$stored = get_option( self::GRANTS_OPTION, [] );

		return array_values( array_unique( array_filter( array_map( 'intval', is_array( $stored ) ? $stored : [] ) ) ) );
	}

	/**
	 * Replaces the users granted on this site. Only users who can edit pages
	 * and are not administrators are kept.
	 *
	 * @param array $user_ids The users to hold the grant.
	 * @return int[] The users kept.
	 */
	public static function set_granted( array $user_ids ): array {
		$kept = [];
		foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
			if ( $user_id > 0 && self::is_eligible( $user_id ) ) {
				$kept[] = $user_id;
			}
		}
		update_option( self::GRANTS_OPTION, $kept, false );

		return $kept;
	}

	/**
	 * The users of this site a site admin can choose from.
	 *
	 * @return array<int, array{id: int, name: string, email: string, role: string, granted: bool}>
	 */
	public static function eligible_users(): array {
		$granted = self::granted_ids();
		$list    = [];
		foreach ( get_users( [ 'blog_id' => get_current_blog_id() ] ) as $user ) {
			$user_id = (int) ( $user->ID ?? 0 );
			if ( $user_id < 1 || ! self::is_eligible( $user_id ) ) {
				continue;
			}
			$roles  = (array) ( $user->roles ?? [] );
			$list[] = [
				'id'      => $user_id,
				'name'    => (string) ( $user->display_name ?? '' ),
				'email'   => (string) ( $user->user_email ?? '' ),
				'role'    => (string) reset( $roles ),
				'granted' => in_array( $user_id, $granted, true ),
			];
		}

		return $list;
	}

	/**
	 * Gives WordPress the capability for administrators and granted users, so
	 * menus and any other code asking current_user_can() see it. Hooked to
	 * `user_has_cap`.
	 *
	 * @param array    $allcaps The user's capabilities.
	 * @param string[] $caps    The primitive capabilities being checked.
	 * @param array    $args    The requested capability, the user id, and any object id.
	 * @param object   $user    The user.
	 */
	public static function filter_user_caps( $allcaps, $caps, $args, $user = null ) {
		$asked = array_intersect( array_merge( [ self::CAPABILITY ], self::CAPABILITIES ), (array) $caps );
		if ( ! is_array( $allcaps ) || [] === $asked ) {
			return $allcaps;
		}

		$user_id = (int) ( is_object( $user ) ? ( $user->ID ?? 0 ) : ( $args[1] ?? 0 ) );
		$admin   = ! empty( $allcaps['manage_options'] );
		$roles   = is_object( $user ) ? array_map( 'strval', (array) ( $user->roles ?? [] ) ) : self::roles_of( $user_id );
		$held    = [];
		foreach ( self::CAPABILITIES as $capability ) {
			$held[ $capability ] = $admin || self::holds( $capability, $roles, ! empty( $allcaps['edit_pages'] ), $user_id );
			if ( $held[ $capability ] ) {
				$allcaps[ $capability ] = true;
			}
		}
		// The full manager an older addon bundle expects.
		if ( $admin || ( $held[ self::EDIT ] && $held[ self::SETTINGS ] && $held[ self::RETRANSLATE ] ) ) {
			$allcaps[ self::CAPABILITY ] = true;
		}

		return $allcaps;
	}

	private static function is_eligible( int $user_id ): bool {
		return user_can( $user_id, 'edit_pages' ) && ! user_can( $user_id, 'manage_options' );
	}
}
