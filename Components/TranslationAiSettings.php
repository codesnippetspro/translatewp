<?php

namespace TranslateWP\Components;

use TranslateWP\Entities\CPTs\Lexicon;

class TranslationAiSettings {

	private const FREE_STYLES = [ 'neutral', 'marketing', 'technical' ];
	private const FREE_TONES  = [ 'friendly', 'professional', 'casual', 'formal' ];

	public const LENGTHS             = [ 'match', 'shorter', 'longer' ];
	public const CULTURAL_ADAPTATION = [ 'keep', 'adapt' ];
	public const AUDIENCE_MAX_LENGTH = 200;

	/**
	 * A preset beyond the free ones is Orbital; without Pro the default goes out instead.
	 */
	private static function allowed_preset( string $value, array $free, string $fallback ): string {
		if ( in_array( $value, $free, true ) || ProEligibility::unlocks_pro_features() ) {
			return $value;
		}

		return $fallback;
	}

	/**
	 * The translation settings sent with every job.
	 *
	 * @param string $language The target language the settings are for; empty for
	 *                         the browser-bound copy that carries every term with its scope.
	 */
	public static function build( string $language = '' ): array {
		$translation_settings = array_merge(
			[
				'auto_translate'              => true,
				'preserve_html_attributes'    => false,
				'translate_image_alt_tags'    => true,
				'preserve_branded_terms'      => true,
				'localized_date_time_formats' => true,
				'enable_units_conversion'     => true,
			],
			is_array( Options::get( 'translations.settings' ) ) ? Options::get( 'translations.settings' ) : []
		);

		$context_settings = array_merge(
			[
				'text_context' => '',
			],
			is_array( Options::get( 'translations.context' ) ) ? Options::get( 'translations.context' ) : []
		);

		$style_settings = array_merge(
			[
				'type'       => 'neutral',
				'tone'       => 'friendly',
				'formality'  => 5,
				'creativity' => 5,
			],
			is_array( Options::get( 'translations.style' ) ) ? Options::get( 'translations.style' ) : []
		);

		$settings = [
			'preserve_branded_terms'      => (bool) $translation_settings['preserve_branded_terms'],
			'localized_date_time_formats' => (bool) $translation_settings['localized_date_time_formats'],
			'enable_units_conversion'     => (bool) $translation_settings['enable_units_conversion'],
			'context'                     => (string) $context_settings['text_context'],
			'writing_style'               => self::allowed_preset( (string) $style_settings['type'], self::FREE_STYLES, 'neutral' ),
			'tone'                        => self::allowed_preset( (string) $style_settings['tone'], self::FREE_TONES, 'friendly' ),
			'formality'                   => (int) $style_settings['formality'],
			'creativity'                  => (int) $style_settings['creativity'],
			'lexicon_terms'               => '' === $language ? Lexicon::get_all() : Lexicon::terms_for_language( $language ),
		];

		return $settings + self::advanced_controls( $style_settings );
	}

	/**
	 * Audience, length and cultural adaptation are Orbital; without Pro, or at
	 * their defaults, they are left out so an untouched site fingerprints as before.
	 */
	private static function advanced_controls( array $style ): array {
		if ( ! ProEligibility::unlocks_pro_features() ) {
			return [];
		}

		$controls = [];
		$audience = sanitize_text_field( (string) ( $style['audience'] ?? '' ) );
		if ( '' !== $audience ) {
			$controls['audience'] = function_exists( 'mb_substr' )
				? mb_substr( $audience, 0, self::AUDIENCE_MAX_LENGTH )
				: substr( $audience, 0, self::AUDIENCE_MAX_LENGTH );
		}

		$length = (string) ( $style['length'] ?? 'match' );
		if ( 'match' !== $length && in_array( $length, self::LENGTHS, true ) ) {
			$controls['length'] = $length;
		}

		$adaptation = (string) ( $style['cultural_adaptation'] ?? 'keep' );
		if ( 'keep' !== $adaptation && in_array( $adaptation, self::CULTURAL_ADAPTATION, true ) ) {
			$controls['cultural_adaptation'] = $adaptation;
		}

		return $controls;
	}
}
