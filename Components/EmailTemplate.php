<?php

namespace TranslateWP\Components;

/**
 * The fixed wording of an email text with the values of one message taken
 * out: "Hi Ana, your order #4987" and "Hi Bob, your order #5120" are both
 * "Hi {{1}}, your order #{{2}}". The wording is translated once and every
 * later message reuses it; the values are put back untranslated.
 *
 * Values are the ones the message names (a customer name, an address line),
 * and every number, link and email address.
 */
class EmailTemplate {

	private const TOKEN = '/\{\{(\d+)\}\}/';

	/**
	 * Takes the values out of a text.
	 *
	 * @param string   $text   The text as the email holds it.
	 * @param string[] $values Values of this message: names, address lines.
	 * @return array{template: string, values: string[]} The values in the order of their tokens.
	 */
	public static function mask( string $text, array $values ): array {
		$named = [];
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( strlen( $value ) > 1 ) {
				$named[ $value ] = strlen( $value );
			}
		}
		arsort( $named );

		$parts = [
			'\{\{\d+\}\}',
			'https?:\/\/[^\s<>"]+',
			'[\w.+-]+@[\w-]+(?:\.[\w-]+)+',
		];
		foreach ( array_keys( $named ) as $value ) {
			$parts[] = '(?<![\p{L}\p{N}])' . preg_quote( (string) $value, '/' ) . '(?![\p{L}\p{N}])';
		}
		$parts[] = '(?<![\p{L}\p{N}])\d+(?:[.,:]\d+)*(?![\p{L}])';

		$found    = [];
		$template = preg_replace_callback(
			'/' . implode( '|', $parts ) . '/u',
			static function ( array $match ) use ( &$found ): string {
				$found[] = $match[0];
				return '{{' . count( $found ) . '}}';
			},
			$text
		);

		return [
			'template' => is_string( $template ) ? $template : $text,
			'values'   => is_string( $template ) ? $found : [],
		];
	}

	/** Whether a template holds anything to translate besides its values. */
	public static function has_wording( string $template ): bool {
		return 1 === preg_match( '/\p{L}/u', (string) preg_replace( self::TOKEN, '', $template ) );
	}

	/**
	 * Puts the values back into a translated template. A translation that
	 * lost a value, or holds one the template never had, is not used.
	 *
	 * @param string   $translated The translated template.
	 * @param string[] $values     The values, in the order of their tokens.
	 */
	public static function fill( string $translated, array $values ): ?string {
		preg_match_all( self::TOKEN, $translated, $tokens );
		$used = array_map( 'intval', array_unique( $tokens[1] ) );
		sort( $used );
		if ( ( $values ? range( 1, count( $values ) ) : [] ) !== $used ) {
			return null;
		}

		return (string) preg_replace_callback(
			self::TOKEN,
			static fn( array $token ): string => (string) $values[ (int) $token[1] - 1 ],
			$translated
		);
	}
}
