<?php

namespace TranslateWP\Components;

class TranslationWhitespace {

	public static function trim( string $value ): string {
		$trimmed = preg_replace( '/^\p{Z}+|\p{Z}+$/u', '', $value );

		return is_string( $trimmed ) ? $trimmed : '';
	}

	public static function has_translatable_text( string $value ): bool {
		return '' !== self::trim( $value );
	}

	/**
	 * A value split into the separator printed at its edges (a breadcrumb's
	 * " / Product") and the words between, as the browser runtime sends them.
	 *
	 * @return array{0: string, 1: string, 2: string} Prefix, words, suffix.
	 */
	public static function separator_edges( string $value ): array {
		if ( ! preg_match( '~^([\s\p{Z}]*[/|›»·•\x{2013}\x{2014}][\s\p{Z}]+)?(.*?)([\s\p{Z}]+[/|·•\x{2013}\x{2014}][\s\p{Z}]*)?$~su', $value, $match )
			|| ! PlainText::has_letters( $match[2] ) ) {
			return [ '', $value, '' ];
		}

		return [ $match[1], $match[2], $match[3] ?? '' ];
	}
}
