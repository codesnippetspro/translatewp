<?php

namespace TranslateWP\Components;

class NetworkScope {

	private const PIXEL_MAX  = 500;
	private const TEXT_MAX  = 200;
	private const APPLY_TO   = [ 'all', 'chosen' ];
	// The same keys a single site locks behind Pro.
	private const PRO_KEYS   = [
		'languages.seo.ai_crawlers.preset_answer_engines',
		'languages.seo.sitemaps',
		'languages.seo.exclude_disabled_languages',
		'languages.seo.force_lowercase',
		'languages.seo.include_x_default',
		'languages.seo.remember_last_choice',
		'languages.seo.cookie_ttl',
		'languages.seo.redirect_after_switch',
		'translations.settings.translate_image_alt_tags',
		'translations.settings.visible_roles',
		'translations.style.audience',
		'translations.style.length',
		'translations.style.cultural_adaptation',
	];
	private const EXCEPTIONS = [ SettingsScope::STATE_LOCKED, SettingsScope::STATE_LOCAL ];
	// Single choices of a Free field that only Pro unlocks: the addon active on an entitled plan.
	private const PRO_VALUES = [
		'languages.switcher.type' => [ SwitcherPlacement::SIDE_TAB ],
	];

	public static function fields(): array {
		return [
			'uninstall'             => [
				self::field( 'advanced.delete_on_uninstall', __( 'Delete TranslateWP data when the plugin is deleted', 'translate' ), 'boolean', false ),
			],
			'ai_crawlers'           => [
				self::field( 'languages.seo.ai_crawlers.preset_answer_engines', __( 'Let answer engines index my translations', 'translate' ), 'boolean', true ),
			],
			'switcher_appearance'   => [
				self::field(
					'languages.switcher.type',
					__( 'Switcher type', 'translate' ),
					'select',
					'dropdown',
					[
						'dropdown'      => __( 'Dropdown', 'translate' ),
						'native_select' => __( 'Native dropdown', 'translate' ),
						'side_tab'      => __( 'Side tab', 'translate' ),
					]
				),
				self::field(
					'languages.switcher.trigger',
					__( 'Show selected language', 'translate' ),
					'select',
					'name_flag',
					[
						'name_flag' => __( 'Flag and name', 'translate' ),
						'flag'      => __( 'Flag only', 'translate' ),
						'icon'      => __( 'Icon only', 'translate' ),
					]
				),
				self::field( 'languages.switcher.show_flag', __( 'Show flag icon', 'translate' ), 'boolean', true ),
				self::field( 'languages.switcher.native_names', __( 'Name each language in its own language', 'translate' ), 'boolean', true ),
				self::field(
					'languages.switcher.regional_names',
					__( 'Regional language names', 'translate' ),
					'select',
					'full',
					[
						'full'  => __( 'Full name', 'translate' ),
						'short' => __( 'Short', 'translate' ),
					]
				),
				self::field(
					'languages.switcher.icon_style',
					__( 'Flag icon style', 'translate' ),
					'select',
					'rectangle',
					[
						'rectangle' => __( 'Rectangle', 'translate' ),
						'square'    => __( 'Square', 'translate' ),
						'circle'    => __( 'Circle', 'translate' ),
					]
				),
				self::field( 'languages.switcher.text_color', __( 'Text color', 'translate' ), 'color', '#393535' ),
				self::field( 'languages.switcher.background_color', __( 'Background color', 'translate' ), 'color', '#FFFFFF' ),
				self::field(
					'languages.switcher.background_style',
					__( 'Background', 'translate' ),
					'select',
					'solid',
					[
						'solid'       => __( 'Solid', 'translate' ),
						'transparent' => __( 'Transparent', 'translate' ),
						'glass'       => __( 'Matte glass', 'translate' ),
					]
				),
				self::field( 'languages.switcher.highlight_text', __( 'Highlight text color', 'translate' ), 'color', '#FFFFFF' ),
				self::field( 'languages.switcher.highlight_bg', __( 'Highlight background', 'translate' ), 'color', '#0e3ecd' ),
				self::field( 'languages.switcher.icon_size', __( 'Switcher icon size', 'translate' ), 'number', 24 ),
				self::field( 'languages.switcher.padding', __( 'Switcher padding', 'translate' ), 'number', 12 ),
				self::field( 'languages.switcher.offset', __( 'Switcher offset', 'translate' ), 'number', 24 ),
				self::field(
					'languages.switcher.anchor_fallback',
					__( 'When the anchor is not on a page', 'translate' ),
					'select',
					'floating',
					[
						'floating' => __( 'Show the floating switcher', 'translate' ),
						'none'     => __( 'Show no switcher', 'translate' ),
					]
				),
				self::field(
					'languages.switcher.pending_message',
					__( 'Translating message', 'translate' ),
					'text',
					'',
					[],
					__( 'Shown to a visitor when a page takes longer than a few seconds to translate. Leave empty for the default line.', 'translate' ),
					__( 'Translating this page for you, a few more seconds.', 'translate' )
				),
			],
			'seo_technical'         => [
				self::field( 'languages.seo.force_lowercase', __( 'Lowercase language slugs in URLs', 'translate' ), 'boolean', true ),
				self::field( 'languages.seo.output_hreflang', __( 'Output hreflang tags', 'translate' ), 'boolean', true ),
				self::field( 'languages.seo.include_x_default', __( 'Include x-default pointing to the default language', 'translate' ), 'boolean', true ),
				self::field( 'languages.seo.sitemaps', __( 'Per-language sitemaps', 'translate' ), 'boolean', true ),
				self::field( 'languages.seo.exclude_disabled_languages', __( 'Exclude disabled languages from sitemaps', 'translate' ), 'boolean', true ),
			],
			'translation_behaviour' => [
				self::field( 'translations.settings.preserve_html_attributes', __( 'Preserve HTML attributes', 'translate' ), 'boolean', false ),
				self::field( 'translations.settings.translate_image_alt_tags', __( 'Translate image alt tags', 'translate' ), 'boolean', true ),
				self::field( 'translations.settings.preserve_branded_terms', __( 'Preserve branded terms', 'translate' ), 'boolean', true ),
				self::field( 'translations.settings.localized_date_time_formats', __( 'Localized date and time formats', 'translate' ), 'boolean', true ),
				self::field( 'translations.settings.enable_units_conversion', __( 'Enable units conversion', 'translate' ), 'boolean', true ),
			],
			'visitor_language'      => [
				self::field( 'translations.settings.auto_translate', __( 'Auto-detect browser language', 'translate' ), 'boolean', true ),
				self::field( 'languages.seo.remember_last_choice', __( 'Remember the visitor\'s language', 'translate' ), 'boolean', true ),
				self::field( 'languages.seo.cookie_ttl', __( 'Keep the choice for (days)', 'translate' ), 'number', 30 ),
				self::field(
					'languages.seo.redirect_after_switch',
					__( 'After a switch', 'translate' ),
					'select',
					'0',
					[
						'0' => __( 'Stay on the same page', 'translate' ),
						'1' => __( 'Go to the home page', 'translate' ),
					]
				),
			],
			'content_areas'         => [
				self::field( 'translations.settings.translate_comments', __( 'Translate comments', 'translate' ), 'boolean', true ),
				self::field( 'translations.settings.translate_menus', __( 'Translate menus', 'translate' ), 'boolean', true ),
				self::field( 'translations.settings.translate_widgets', __( 'Translate widgets', 'translate' ), 'boolean', true ),
			],
			'role_permissions'      => [
				self::field( 'access.edit_translations', __( 'Edit translations', 'translate' ), 'role_map', [], self::delegable_roles(), __( 'Switcher edit mode, the editor and Add to Lexicon.', 'translate' ) ),
				self::field( 'access.retranslate', __( 'Retranslate', 'translate' ), 'role_map', [], self::delegable_roles(), __( 'Retranslate a page or the site, which uses quota.', 'translate' ) ),
				self::field( 'access.manage_settings', __( 'Manage settings', 'translate' ), 'role_map', [], self::delegable_roles(), __( 'The translation and switcher settings.', 'translate' ) ),
				self::field( 'access.manage_lexicon', __( 'Manage the Lexicon', 'translate' ), 'role_map', [], self::delegable_roles(), __( 'The Lexicon and its suggestions.', 'translate' ) ),
			],
			'style_tone'            => [
				self::field(
					'translations.style.type',
					__( 'Preset style', 'translate' ),
					'select',
					'neutral',
					[
						'marketing' => __( 'Marketing', 'translate' ),
						'technical' => __( 'Technical', 'translate' ),
						'neutral'   => __( 'Neutral', 'translate' ),
						'legal'     => __( 'Legal', 'translate' ),
						'medical'   => __( 'Medical', 'translate' ),
					]
				),
				self::field(
					'translations.style.tone',
					__( 'Preset tone', 'translate' ),
					'select',
					'friendly',
					[
						'friendly'     => __( 'Friendly', 'translate' ),
						'professional' => __( 'Professional', 'translate' ),
						'casual'       => __( 'Casual', 'translate' ),
						'playful'      => __( 'Playful', 'translate' ),
						'luxury'       => __( 'Luxury', 'translate' ),
						'formal'       => __( 'Formal', 'translate' ),
					]
				),
				self::field( 'translations.style.formality', __( 'Formality', 'translate' ), 'range', 5 ),
				self::field( 'translations.style.creativity', __( 'Precision vs fluency', 'translate' ), 'range', 5 ),
				self::field(
					'translations.style.audience',
					__( 'Audience', 'translate' ),
					'text',
					'',
					[],
					__( 'Who reads the translations. The vocabulary and the level of explanation follow it.', 'translate' ),
					__( 'For example: first-time buyers, hospital staff, developers', 'translate' )
				),
				self::field(
					'translations.style.length',
					__( 'Length', 'translate' ),
					'select',
					'match',
					[
						'match'   => __( 'Match the original', 'translate' ),
						'shorter' => __( 'Shorter', 'translate' ),
						'longer'  => __( 'Longer', 'translate' ),
					]
				),
				self::field(
					'translations.style.cultural_adaptation',
					__( 'Cultural adaptation', 'translate' ),
					'select',
					'keep',
					[
						'keep'  => __( 'Keep as written', 'translate' ),
						'adapt' => __( 'Adapt', 'translate' ),
					]
				),
			],
		];
	}

	public static function page_data(): array {
		$groups = [];
		foreach ( SettingsScope::groups() as $group_id => $group ) {
			if ( SettingsScope::TIER_SUBSITE === $group['tier'] ) {
				continue;
			}

			$groups[] = [
				'id'         => $group_id,
				'label'      => $group['label'],
				'tier'       => $group['tier'],
				'switchable' => $group['switchable'],
				'state'      => SettingsScope::group_state( $group_id ),
				'exceptions' => self::exceptions( $group_id ),
				'fields'     => array_map(
					static function ( array $field ): array {
						$field['value'] = self::field_value( $field );
						return $field;
					},
					self::fields()[ $group_id ] ?? []
				),
			];
		}

		return [
			'groups'   => $groups,
			'subsites' => array_map(
				static function ( int $site_id ): array {
					$site = get_blog_details( $site_id );
					return [
						'id'   => $site_id,
						'name' => $site ? PlainText::blog_name( $site ) : '#' . $site_id,
					];
				},
				MultisiteSettings::site_ids()
			),
		];
	}

	public static function apply_post( array $post ): string {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			return 'denied';
		}

		$group_id = sanitize_key( (string) ( $post['group'] ?? '' ) );
		$op       = sanitize_key( (string) ( $post['op'] ?? '' ) );

		ActionLog::record( sprintf( 'Network settings: %s on %s', $op, '' !== $group_id ? $group_id : 'switcher position' ) );
		if ( 'state' === $op ) {
			return SettingsScope::set_group_state( $group_id, sanitize_key( (string) ( $post['state'] ?? '' ) ) ) ? 'scope-saved' : 'failed';
		}

		$sites = self::site_list( $post['sites'] ?? [] );

		if ( 'exceptions' === $op ) {
			$exception = sanitize_key( (string) ( $post['exception'] ?? '' ) );
			if ( [] === $sites || ( 'clear' !== $exception && ! in_array( $exception, self::EXCEPTIONS, true ) ) ) {
				return 'failed';
			}
			foreach ( $sites as $site_id ) {
				if ( ! SettingsScope::set_subsite_exception( $group_id, $site_id, 'clear' === $exception ? null : $exception ) ) {
					return 'failed';
				}
			}

			return 'scope-saved';
		}

		if ( 'value' === $op ) {
			return self::set_value( $group_id, is_array( $post['values'] ?? null ) ? $post['values'] : [], sanitize_key( (string) ( $post['apply_to'] ?? '' ) ), $sites ) ? 'scope-saved' : 'failed';
		}

		if ( 'switcher_position' === $op ) {
			return self::apply_switcher_position();
		}

		return 'failed';
	}

	public static function apply_switcher_position(): string {
		if ( ! ProEligibility::unlocks_pro_features() ) {
			return 'pro-required';
		}

		$main = get_main_site_id();
		switch_to_blog( $main );
		try {
			$languages = get_option( 'translatewp_languages', [] );
		} finally {
			restore_current_blog();
		}
		$switcher = is_array( $languages ) && is_array( $languages['switcher'] ?? null ) ? $languages['switcher'] : [];
		// The places of the type the main site uses, both screen widths.
		$kind   = SwitcherPlacement::SIDE_TAB === ( $switcher['type'] ?? '' ) ? 'side_tab' : 'floating';
		$values = [];
		foreach ( SwitcherPlacement::POSITION_KEYS[ $kind ] as $key ) {
			if ( is_array( $switcher[ $key ] ?? null ) ) {
				$values[ 'languages.switcher.' . $key ] = $switcher[ $key ];
			}
		}
		if ( [] === $values ) {
			return 'no-position';
		}

		$sites = array_values( array_diff( MultisiteSettings::site_ids(), [ $main ] ) );
		self::write_to_sites( $values, $sites );

		return 'position-applied';
	}

	private static function set_value( string $group_id, array $raw_values, string $apply_to, array $sites ): bool {
		$fields = self::fields()[ $group_id ] ?? [];
		if ( [] === $fields || ! in_array( $apply_to, self::APPLY_TO, true ) || ( 'chosen' === $apply_to && [] === $sites ) ) {
			return false;
		}

		$values     = [];
		$pro_active = ProEligibility::is_active();
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field['key'], $raw_values ) || ( $field['pro'] && ! $pro_active ) ) {
				continue;
			}
			$value = self::sanitize( $field, $raw_values[ $field['key'] ] );
			if ( in_array( $value, $field['pro_values'], true ) && ! ProEligibility::unlocks_pro_features() ) {
				continue;
			}
			if ( null !== $value ) {
				$values[ $field['key'] ] = $value;
			}
		}
		if ( [] === $values ) {
			return false;
		}

		if ( 'all' === $apply_to ) {
			$network = SettingsScope::network_values();
			foreach ( $values as $key => $value ) {
				$network = self::set_path( $network, $key, $value );
			}
			update_site_option( 'translatewp_network_settings_defaults', $network );
			$sites = MultisiteSettings::site_ids();
		}

		self::write_to_sites( $values, $sites );

		return true;
	}

	private static function write_to_sites( array $values, array $sites ): void {
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$options = [];
				foreach ( $values as $key => $value ) {
					$option_name             = 'translatewp_' . explode( '.', $key )[0];
					$options[ $option_name ] = $options[ $option_name ] ?? get_option( $option_name, [] );
					$options[ $option_name ] = self::set_path( [ $option_name => is_array( $options[ $option_name ] ) ? $options[ $option_name ] : [] ], $key, $value )[ $option_name ];
				}
				foreach ( $options as $option_name => $option_value ) {
					update_option( $option_name, $option_value );
				}
			} finally {
				restore_current_blog();
			}
		}
	}

	private static function sanitize( array $field, $raw ) {
		if ( 'roles' === $field['type'] ) {
			return Audience::sanitize_roles( $raw );
		}
		if ( 'role_map' === $field['type'] ) {
			// Every role written, ticked true and the rest false, so a site's own
			// value and the network default both say what they mean.
			$ticked = Audience::sanitize_roles( $raw );
			$map    = [];
			foreach ( array_keys( self::delegable_roles() ) as $role ) {
				$map[ $role ] = in_array( $role, $ticked, true );
			}

			return $map;
		}
		if ( is_array( $raw ) ) {
			return null;
		}

		switch ( $field['type'] ) {
			case 'boolean':
				return in_array( $raw, [ true, 1, '1', 'true', 'on' ], true );
			case 'number':
				return is_numeric( $raw ) ? min( self::PIXEL_MAX, max( 0, (int) $raw ) ) : null;
			case 'range':
				return is_numeric( $raw ) ? min( 10, max( 0, (int) $raw ) ) : null;
			case 'color':
				return 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $raw ) ? (string) $raw : null;
			case 'text':
				$text = sanitize_text_field( (string) $raw );
				return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::TEXT_MAX ) : substr( $text, 0, self::TEXT_MAX );
			case 'select':
				$choice = (string) $raw;
				if ( ! array_key_exists( $choice, $field['options'] ) ) {
					return null;
				}
				return 'languages.seo.redirect_after_switch' === $field['key'] ? '1' === $choice : $choice;
		}

		return null;
	}

	private static function field_value( array $field ) {
		$network = self::get_path( SettingsScope::network_values(), $field['key'] );
		if ( null === $network ) {
			switch_to_blog( self::main_site_id() );
			try {
				$option_name = 'translatewp_' . explode( '.', $field['key'] )[0];
				$network     = self::get_path( [ $option_name => SettingsScope::current( $option_name ) ], $field['key'] );
			} finally {
				restore_current_blog();
			}
		}

		$value = $network ?? $field['default'];
		// The page ticks the roles a map gives the capability to.
		if ( 'role_map' === $field['type'] ) {
			return is_array( $value ) ? array_keys( array_filter( $value ) ) : [];
		}
		if ( 'languages.seo.redirect_after_switch' === $field['key'] ) {
			return $value ? '1' : '0';
		}

		return $value;
	}

	private static function exceptions( string $group_id ): array {
		$exceptions = [];
		foreach ( MultisiteSettings::site_ids() as $site_id ) {
			$state = SettingsScope::exception( $group_id, $site_id );
			if ( null !== $state ) {
				$exceptions[ (string) $site_id ] = $state;
			}
		}

		return $exceptions;
	}

	private static function site_list( $raw ): array {
		$sites = [];
		foreach ( is_array( $raw ) ? $raw : [] as $site_id ) {
			$site_id = absint( $site_id );
			if ( $site_id > 0 && null !== get_site( $site_id ) ) {
				$sites[] = $site_id;
			}
		}

		return array_values( array_unique( $sites ) );
	}

	private static function main_site_id(): int {
		return function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;
	}

	private static function set_path( array $values, string $key, $value ): array {
		$segments    = explode( '.', $key );
		$segments[0] = 'translatewp_' . $segments[0];
		$ref         = &$values;
		foreach ( array_slice( $segments, 0, -1 ) as $segment ) {
			if ( ! is_array( $ref[ $segment ] ?? null ) ) {
				$ref[ $segment ] = [];
			}
			$ref = &$ref[ $segment ];
		}
		$ref[ end( $segments ) ] = $value;

		return $values;
	}

	private static function get_path( array $values, string $key ) {
		$segments    = explode( '.', $key );
		$segments[0] = 'translatewp_' . $segments[0];
		$current     = $values;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	private static function field( string $key, string $label, string $type, $default, array $options = [], string $help = '', string $placeholder = '' ): array {
		return [
			'key'         => $key,
			'label'       => $label,
			'type'        => $type,
			'default'     => $default,
			'options'     => $options,
			'pro'         => in_array( $key, self::PRO_KEYS, true ),
			'pro_values'  => self::PRO_VALUES[ $key ] ?? [],
			'help'        => $help,
			'placeholder' => $placeholder,
		];
	}

	/** The roles a site admin can give capabilities to: every role but the administrator, who holds them all. */
	private static function delegable_roles(): array {
		$roles = Audience::role_names();
		unset( $roles['administrator'] );

		return $roles;
	}
}
