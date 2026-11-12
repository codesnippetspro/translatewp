<?php

namespace TranslateWP\Components;

class TranslationFormatting {

	private const NUMERIC_TEXT = '/^[\s\x{00a0}\x{202f}0-9.,()%‰+\-−–\x{2014}€$£¥₩₽¢₴₺₹]*$/u';
	private const NUMBER_TOKEN = '/[0-9](?:[0-9.,\x{00a0}\x{202f} ]*[0-9])?/u';
	private const MAX_INTEGER_DIGITS = 15;
	private const LOCALE = '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D';

	public static function is_free( string $payload, string $source, string $target ): bool {
		if ( 'auto' === $source || ! preg_match( self::LOCALE, $source ) || ! preg_match( self::LOCALE, $target )
			|| is_wp_error( TranslationMarkerPayload::validate( $payload ) ) ) {
			return false;
		}
		$texts = TranslationMarkerPayload::parse( $payload );
		if ( ! $texts ) {
			return false;
		}
		$group = null;
		$decimal = null;
		if ( class_exists( '\NumberFormatter' ) ) {
			try {
				$formatter = new \NumberFormatter( str_replace( '-', '_', $source ), \NumberFormatter::DECIMAL );
				$group = $formatter->getSymbol( \NumberFormatter::GROUPING_SEPARATOR_SYMBOL );
				$decimal = $formatter->getSymbol( \NumberFormatter::DECIMAL_SEPARATOR_SYMBOL );
			} catch ( \Throwable $error ) {
				return false;
			}
		}
		$has_digits = false;
		foreach ( $texts as $text ) {
			if ( ! preg_match( self::NUMERIC_TEXT, $text ) ) {
				return false;
			}
			preg_match_all( self::NUMBER_TOKEN, $text, $matches );
			foreach ( $matches[0] as $token ) {
				$has_digits = true;
				if ( null === $group || null === $decimal ) {
					if ( preg_match( '/[.,]/', $token ) ) {
						return false;
					}
					$normalized = $token;
				} else {
					$normalized = str_replace( $decimal, '.', str_replace( $group, '', $token ) );
				}
				$normalized = preg_replace( '/[\s\x{00a0}\x{202f}]/u', '', $normalized );
				if ( ! preg_match( '/^[0-9]+(?:\.[0-9]+)?$/D', $normalized )
					|| strlen( explode( '.', $normalized )[0] ) > self::MAX_INTEGER_DIGITS
					|| ! is_finite( (float) $normalized ) ) {
					return false;
				}
			}
		}
		return $has_digits;
	}
}
