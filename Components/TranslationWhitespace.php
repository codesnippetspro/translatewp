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
}
