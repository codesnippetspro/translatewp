<?php

namespace TranslateWP\Components;

class SettingsScope {

	public const TIER_NETWORK_ONLY      = 'network_only';
	public const TIER_NETWORK_DELEGABLE = 'network_delegable';
	public const TIER_NETWORK_DEFAULT   = 'network_default';
	public const TIER_SUBSITE           = 'subsite';

	public const STATE_LOCKED  = 'locked';
	public const STATE_DEFAULT = 'default';
	public const STATE_LOCAL   = 'local';

	private const NETWORK_DEFAULTS_OPTION = 'translatewp_network_settings_defaults';
	private const NETWORK_SCOPE_OPTION    = 'translatewp_network_settings_scope';
	private const STATES                  = [ self::STATE_LOCKED, self::STATE_DEFAULT, self::STATE_LOCAL ];
	private const VISITOR_SEO_KEYS        = [ 'remember_last_choice', 'cookie_ttl', 'redirect_after_switch' ];
	private const CONTENT_KEYS            = [ 'translate_all_content', 'post_types', 'categories' ];
	private const AREA_KEYS               = [ 'translate_comments', 'translate_menus', 'translate_widgets' ];
	private const AUDIENCE_KEYS           = [ 'visible_roles' ];
	private const PLACEMENT_KEYS          = [ 'floating_position', 'floating_position_mobile', 'side_tab_position', 'side_tab_position_mobile', 'inline_placement' ];

	private static bool $raw_read = false;

	public static function groups(): array {
		return [
			'account'               => self::group( __( 'The account connection', 'translate' ), self::TIER_NETWORK_ONLY, [ self::part( 'translatewp_site', null ) ] ),
			'uninstall'             => self::group( __( 'Delete data on uninstall', 'translate' ), self::TIER_NETWORK_ONLY, [ self::part( 'translatewp_advanced', 'delete_on_uninstall' ) ] ),
			'ai_crawlers'           => self::group( __( 'AI crawler policy', 'translate' ), self::TIER_NETWORK_DELEGABLE, [ self::part( 'translatewp_languages', 'seo', [ 'ai_crawlers' ] ) ] ),
			'switcher_appearance'   => self::group( __( 'Switcher appearance', 'translate' ), self::TIER_NETWORK_DEFAULT, [ self::part( 'translatewp_languages', 'switcher', null, self::PLACEMENT_KEYS ) ] ),
			'seo_technical'         => self::group( __( 'Technical SEO', 'translate' ), self::TIER_NETWORK_DEFAULT, [ self::part( 'translatewp_languages', 'seo', null, array_merge( [ 'ai_crawlers' ], self::VISITOR_SEO_KEYS ) ) ] ),
			'translation_behaviour' => self::group( __( 'Translation behaviour', 'translate' ), self::TIER_NETWORK_DEFAULT, [ self::part( 'translatewp_translations', 'settings', null, array_merge( self::CONTENT_KEYS, self::AREA_KEYS, self::AUDIENCE_KEYS, [ 'auto_translate' ] ) ) ] ),
			'content_areas'         => self::group( __( 'Page areas', 'translate' ), self::TIER_NETWORK_DEFAULT, [ self::part( 'translatewp_translations', 'settings', self::AREA_KEYS ) ] ),
			'role_permissions'      => self::group( __( 'Who can manage translations', 'translate' ), self::TIER_NETWORK_DEFAULT, [ self::part( Access::ROLE_MAP_OPTION, null ) ] ),
			'visitor_language'      => self::group(
				__( 'Visitor language', 'translate' ),
				self::TIER_NETWORK_DEFAULT,
				[
					self::part( 'translatewp_languages', 'seo', self::VISITOR_SEO_KEYS ),
					self::part( 'translatewp_translations', 'settings', [ 'auto_translate' ] ),
				]
			),
			'style_tone'            => self::group( __( 'Style and tone', 'translate' ), self::TIER_NETWORK_DEFAULT, [ self::part( 'translatewp_translations', 'style' ) ] ),
			'target_languages'      => self::group( __( 'Languages', 'translate' ), self::TIER_SUBSITE, [ self::part( 'translatewp_languages', 'list' ) ] ),
			'content_selection'     => self::group( __( 'Content to translate', 'translate' ), self::TIER_SUBSITE, [ self::part( 'translatewp_translations', 'settings', self::CONTENT_KEYS ) ] ),
			'ai_context'            => self::group( __( 'AI context', 'translate' ), self::TIER_SUBSITE, [ self::part( 'translatewp_translations', 'context' ) ] ),
			'switcher_placement'    => self::group( __( 'Switcher placement', 'translate' ), self::TIER_SUBSITE, [ self::part( 'translatewp_languages', 'switcher', self::PLACEMENT_KEYS ) ] ),
		];
	}

	public static function group_for( string $option_name, ?string $path, ?string $key = null ): ?string {
		$fallback = null;
		foreach ( self::groups() as $group_id => $group ) {
			foreach ( $group['parts'] as $part ) {
				if ( $part['option'] !== $option_name || ( null !== $part['path'] && $part['path'] !== $path ) ) {
					continue;
				}
				if ( null === $part['path'] || ( null === $part['keys'] && [] === $part['except'] && null === $key ) ) {
					return $group_id;
				}
				if ( null === $key ) {
					continue;
				}
				if ( is_array( $part['keys'] ) && in_array( $key, $part['keys'], true ) ) {
					return $group_id;
				}
				if ( null === $part['keys'] && ! in_array( $key, $part['except'], true ) ) {
					$fallback = $fallback ?? $group_id;
				}
			}
		}

		return $fallback;
	}

	public static function group_state( string $group_id ): string {
		$group = self::groups()[ $group_id ] ?? null;
		if ( null === $group ) {
			return self::STATE_DEFAULT;
		}
		if ( self::TIER_NETWORK_ONLY === $group['tier'] ) {
			return self::STATE_LOCKED;
		}

		$state = self::scope_record()['groups'][ $group_id ]['state'] ?? null;

		return in_array( $state, self::STATES, true ) ? $state : self::tier_state( $group['tier'] );
	}

	public static function state( string $group_id, int $blog_id = 0 ): string {
		$group = self::groups()[ $group_id ] ?? null;
		if ( null === $group || self::TIER_NETWORK_ONLY === $group['tier'] ) {
			return self::group_state( $group_id );
		}

		$blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();

		return self::exception( $group_id, $blog_id ) ?? self::group_state( $group_id );
	}

	public static function exception( string $group_id, int $blog_id ): ?string {
		$state = self::scope_record()['groups'][ $group_id ]['exceptions'][ $blog_id ] ?? null;

		return in_array( $state, self::STATES, true ) ? $state : null;
	}

	public static function set_group_state( string $group_id, string $state ): bool {
		if ( ! self::allows( $group_id, $state ) ) {
			return false;
		}

		$record                                  = self::scope_record();
		$record['groups'][ $group_id ]['state']  = $state;
		$record['groups'][ $group_id ]['exceptions'] = $record['groups'][ $group_id ]['exceptions'] ?? [];

		return update_site_option( self::NETWORK_SCOPE_OPTION, $record );
	}

	public static function set_subsite_exception( string $group_id, int $blog_id, ?string $state ): bool {
		if ( $blog_id <= 0 || null === get_site( $blog_id ) ) {
			return false;
		}
		if ( null !== $state && ! self::allows( $group_id, $state ) ) {
			return false;
		}
		if ( null === $state && ! isset( self::groups()[ $group_id ] ) ) {
			return false;
		}
		if ( self::TIER_NETWORK_ONLY === self::groups()[ $group_id ]['tier'] ) {
			return false;
		}

		$record = self::scope_record();
		$entry  = $record['groups'][ $group_id ] ?? [ 'state' => self::tier_state( self::groups()[ $group_id ]['tier'] ) ];
		$entry['exceptions'] = $entry['exceptions'] ?? [];
		if ( null === $state ) {
			unset( $entry['exceptions'][ $blog_id ] );
		} else {
			$entry['exceptions'][ $blog_id ] = $state;
		}
		$record['groups'][ $group_id ] = $entry;

		return update_site_option( self::NETWORK_SCOPE_OPTION, $record );
	}

	public static function resolve( string $option_name, $value, int $blog_id = 0 ) {
		if ( self::$raw_read || ! is_multisite() || ! is_array( $value ) ) {
			return $value;
		}

		$network = self::network_values()[ $option_name ] ?? null;
		$network = is_array( $network ) ? $network : null;
		$blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
		$output  = null !== $network ? array_replace_recursive( $network, $value ) : $value;

		foreach ( self::groups() as $group_id => $group ) {
			foreach ( $group['parts'] as $part ) {
				if ( $part['option'] !== $option_name ) {
					continue;
				}
				$state = self::state( $group_id, $blog_id );
				if ( self::STATE_DEFAULT !== $state ) {
					$output = self::apply_part( $output, $value, $network ?? [], $part, $state );
				}
			}
		}

		return $output;
	}

	public static function resolve_group( string $group_id, int $blog_id = 0 ): ?array {
		$group = self::groups()[ $group_id ] ?? null;
		if ( null === $group ) {
			return null;
		}

		$blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
		$values  = [];
		foreach ( $group['parts'] as $part ) {
			$resolved = self::resolve( $part['option'], self::raw_option( $part['option'], $blog_id ), $blog_id );
			$values[ self::part_label( $part ) ] = self::extract_part( is_array( $resolved ) ? $resolved : [], $part );
		}

		$locked = is_multisite() && self::STATE_LOCKED === self::state( $group_id, $blog_id );

		return [
			'value'    => 1 === count( $values ) ? reset( $values ) : $values,
			'editable' => ! $locked,
			'source'   => $locked ? 'network' : 'site',
		];
	}

	public static function subsite_scope(): array {
		if ( ! is_multisite() ) {
			return [];
		}

		$scope = [];
		foreach ( self::groups() as $group_id => $group ) {
			if ( self::TIER_SUBSITE !== $group['tier'] ) {
				$scope[ $group_id ] = ! is_super_admin() && self::STATE_LOCKED === self::state( $group_id );
			}
		}

		return $scope;
	}

	public static function current( string $option_name, int $blog_id = 0 ) {
		$blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();

		return self::resolve( $option_name, self::raw_option( $option_name, $blog_id ), $blog_id );
	}

	public static function refusal_for_group( string $group_id ): ?\WP_Error {
		if ( ! self::write_is_guarded() || self::STATE_LOCKED !== self::state( $group_id ) ) {
			return null;
		}

		return self::refusal( $group_id );
	}

	public static function refusal_for_write( string $dot_path, $value ): ?\WP_Error {
		if ( ! self::write_is_guarded() ) {
			return null;
		}

		[ $option_name, $prospective, $current ] = self::prospective_write( $dot_path, $value );
		foreach ( self::groups() as $group_id => $group ) {
			foreach ( $group['parts'] as $part ) {
				if (
					$part['option'] === $option_name
					&& self::STATE_LOCKED === self::state( $group_id )
					&& self::part_changes( $current, $prospective, $part )
				) {
					return self::refusal( $group_id );
				}
			}
		}

		return null;
	}

	public static function keep_locked( string $dot_path, $value ) {
		if ( ! self::write_is_guarded() || ! is_array( $value ) ) {
			return $value;
		}

		[ $option_name, $prospective, $current ] = self::prospective_write( $dot_path, $value );
		foreach ( self::groups() as $group_id => $group ) {
			foreach ( $group['parts'] as $part ) {
				if ( $part['option'] === $option_name && self::STATE_LOCKED === self::state( $group_id ) ) {
					$prospective = self::restore_part( $prospective, $current, $part );
				}
			}
		}

		$path = self::sub_path( $dot_path );

		return null === $path ? $prospective : ( $prospective[ $path ] ?? $value );
	}

	public static function network_values(): array {
		$values = get_site_option( self::NETWORK_DEFAULTS_OPTION, [] );

		return is_array( $values ) ? $values : [];
	}

	private static function scope_record(): array {
		$record = get_site_option( self::NETWORK_SCOPE_OPTION, null );
		if ( is_array( $record ) ) {
			$record['groups'] = is_array( $record['groups'] ?? null ) ? $record['groups'] : [];
			return $record;
		}

		if ( [] === self::network_values() ) {
			return [ 'groups' => [] ];
		}

		$record = [ 'groups' => [] ];
		foreach ( array_keys( self::groups() ) as $group_id ) {
			$record['groups'][ $group_id ] = [
				'state'      => self::STATE_DEFAULT,
				'exceptions' => [],
			];
		}
		update_site_option( self::NETWORK_SCOPE_OPTION, $record );

		return $record;
	}

	private static function write_is_guarded(): bool {
		return is_multisite() && ! is_super_admin();
	}

	private static function refusal( string $group_id ): \WP_Error {
		return new \WP_Error(
			'settings_locked',
			sprintf(
				/* translators: %s: the name of a settings group, for example Switcher appearance */
				__( 'Your network admin manages %s for this site.', 'translate' ),
				self::groups()[ $group_id ]['label'] ?? $group_id
			),
			[
				'status' => 403,
				'group'  => $group_id,
			]
		);
	}

	private static function sub_path( string $dot_path ): ?string {
		$segments = explode( '.', $dot_path );

		return $segments[1] ?? null;
	}

	private static function prospective_write( string $dot_path, $value ): array {
		$segments    = explode( '.', $dot_path );
		$option_name = 'translatewp_' . $segments[0];
		$current     = self::current( $option_name );
		$current     = is_array( $current ) ? $current : [];
		$path        = $segments[1] ?? null;
		$prospective = $current;
		if ( null === $path ) {
			$prospective = is_array( $value ) ? $value : [];
		} else {
			$prospective[ $path ] = $value;
		}

		return [ $option_name, $prospective, $current ];
	}

	private static function part_changes( array $current, array $prospective, array $part ): bool {
		if ( null === $part['path'] ) {
			return $current != $prospective; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Key order does not make a change.
		}

		$path = $part['path'];
		if ( ! array_key_exists( $path, $prospective ) ) {
			return false;
		}
		if ( null === $part['keys'] && [] === $part['except'] ) {
			return ! array_key_exists( $path, $current ) || $current[ $path ] != $prospective[ $path ]; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Key order does not make a change.
		}

		$new_container = is_array( $prospective[ $path ] ) ? $prospective[ $path ] : [];
		$old_container = is_array( $current[ $path ] ?? null ) ? $current[ $path ] : [];
		$keys          = $part['keys'] ?? array_diff( array_keys( $new_container ), $part['except'] );
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $new_container ) && ( ! array_key_exists( $key, $old_container ) || $old_container[ $key ] != $new_container[ $key ] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Key order does not make a change.
				return true;
			}
		}

		return false;
	}

	private static function restore_part( array $prospective, array $current, array $part ): array {
		if ( null === $part['path'] ) {
			return $current;
		}

		$path = $part['path'];
		if ( null === $part['keys'] && [] === $part['except'] ) {
			if ( array_key_exists( $path, $current ) ) {
				$prospective[ $path ] = $current[ $path ];
			}

			return $prospective;
		}

		$old_container = is_array( $current[ $path ] ?? null ) ? $current[ $path ] : [];
		$new_container = is_array( $prospective[ $path ] ?? null ) ? $prospective[ $path ] : [];
		$keys          = $part['keys'] ?? array_diff( array_unique( array_merge( array_keys( $new_container ), array_keys( $old_container ) ) ), $part['except'] );
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $old_container ) ) {
				$new_container[ $key ] = $old_container[ $key ];
			} else {
				unset( $new_container[ $key ] );
			}
		}
		$prospective[ $path ] = $new_container;

		return $prospective;
	}

	private static function tier_state( string $tier ): string {
		if ( in_array( $tier, [ self::TIER_NETWORK_ONLY, self::TIER_NETWORK_DELEGABLE ], true ) ) {
			return self::STATE_LOCKED;
		}

		return self::TIER_SUBSITE === $tier ? self::STATE_LOCAL : self::STATE_DEFAULT;
	}

	private static function allows( string $group_id, string $state ): bool {
		$group = self::groups()[ $group_id ] ?? null;
		if ( null === $group || ! in_array( $state, self::STATES, true ) ) {
			return false;
		}

		return $group['switchable'] || self::tier_state( $group['tier'] ) === $state;
	}

	private static function group( string $label, string $tier, array $parts ): array {
		return [
			'label'      => $label,
			'tier'       => $tier,
			'switchable' => in_array( $tier, [ self::TIER_NETWORK_DELEGABLE, self::TIER_NETWORK_DEFAULT ], true ),
			'parts'      => $parts,
		];
	}

	private static function part( string $option_name, ?string $path, ?array $keys = null, array $except = [] ): array {
		return [
			'option' => $option_name,
			'path'   => $path,
			'keys'   => $keys,
			'except' => $except,
		];
	}

	private static function part_label( array $part ): string {
		$option = substr( $part['option'], strlen( 'translatewp_' ) );

		return null === $part['path'] ? $option : $option . '.' . $part['path'];
	}

	private static function apply_part( array $output, array $blog, array $network, array $part, string $state ): array {
		if ( null === $part['path'] ) {
			return self::STATE_LOCAL === $state ? $blog : array_replace( $blog, $network );
		}

		$path = $part['path'];
		if ( null === $part['keys'] && [] === $part['except'] ) {
			return self::settle( $output, $blog, $network, $path, $state );
		}

		$blog_container    = is_array( $blog[ $path ] ?? null ) ? $blog[ $path ] : [];
		$network_container = is_array( $network[ $path ] ?? null ) ? $network[ $path ] : [];
		$output_container  = is_array( $output[ $path ] ?? null ) ? $output[ $path ] : [];
		$keys              = $part['keys'] ?? array_diff( array_unique( array_merge( array_keys( $network_container ), array_keys( $blog_container ) ) ), $part['except'] );

		foreach ( $keys as $key ) {
			$output_container = self::settle( $output_container, $blog_container, $network_container, $key, $state );
		}

		if ( [] === $output_container && ! array_key_exists( $path, $blog ) && ! array_key_exists( $path, $network ) ) {
			unset( $output[ $path ] );
			return $output;
		}

		$output[ $path ] = $output_container;

		return $output;
	}

	private static function settle( array $output, array $blog, array $network, $key, string $state ): array {
		if ( self::STATE_LOCKED === $state && array_key_exists( $key, $network ) ) {
			$output[ $key ] = $network[ $key ];
		} elseif ( array_key_exists( $key, $blog ) ) {
			$output[ $key ] = $blog[ $key ];
		} else {
			unset( $output[ $key ] );
		}

		return $output;
	}

	private static function extract_part( array $value, array $part ) {
		if ( null === $part['path'] ) {
			return $value;
		}

		$container = $value[ $part['path'] ] ?? null;
		if ( ! is_array( $container ) || ( null === $part['keys'] && [] === $part['except'] ) ) {
			return $container;
		}

		$keys = $part['keys'] ?? array_diff( array_keys( $container ), $part['except'] );

		return array_intersect_key( $container, array_flip( $keys ) );
	}

	private static function raw_option( string $option_name, int $blog_id ) {
		$switched = is_multisite() && get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		self::$raw_read = true;
		try {
			return get_option( $option_name, [] );
		} finally {
			self::$raw_read = false;
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
