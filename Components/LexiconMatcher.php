<?php

namespace TranslateWP\Components;

/**
 * Decides whether a source text contains any of a list of Lexicon terms.
 *
 * Matching is case and width insensitive (NFKC plus full case folding) and
 * tolerant of whitespace and inline markup. A term edge that is a letter or
 * digit must start a word, and may be followed by up to three more letters
 * when the term has four or more letters ("plugins", "Kunden"). Terms in
 * scripts written without spaces match anywhere. Compounds and prefix forms
 * ("Kundenplugin" for "plugin") are not detected.
 */
class LexiconMatcher {

	/**
	 * The compiled form that matches every non-blank source.
	 */
	const EVERYTHING = '//u';

	const BOUNDARY_BEFORE = '(?<![\p{L}\p{N}\p{M}])';
	const BOUNDARY_AFTER  = '(?![\p{L}\p{N}\p{M}])';
	const ENDING          = '[\p{L}\p{M}]{0,3}';
	const NO_SPACE_SCRIPT = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}\p{Tibetan}]/u';

	/**
	 * One matcher for a list of term texts, or null when the list matches nothing.
	 *
	 * @param string[] $texts Term texts.
	 */
	public static function compile( array $texts ): ?string {
		$alternatives = [];
		foreach ( $texts as $text ) {
			$term = self::normalize( (string) $text );
			if ( '' === $term ) {
				continue;
			}
			if ( null === $term || ( static::degraded() && 1 === preg_match( '/[^\x00-\x7F]/', PlainText::decode( (string) $text ) ) ) ) {
				return self::EVERYTHING;
			}
			$alternatives[ $term ] = self::alternative( $term );
		}
		return [] === $alternatives ? null : '/' . implode( '|', $alternatives ) . '/u';
	}

	/**
	 * Whether a source text contains one of the compiled terms.
	 *
	 * @param string|null $compiled From compile().
	 * @param string      $source   Source text; marker tokens and HTML entities are allowed.
	 */
	public static function matches( ?string $compiled, string $source ): bool {
		if ( null === $compiled || '' === trim( $source ) ) {
			return false;
		}
		if ( self::EVERYTHING === $compiled ) {
			return true;
		}
		$text = self::normalize( $source );
		if ( null === $text ) {
			return true;
		}
		// A regex failure must never hide an affected page.
		return 0 !== preg_match( $compiled, $text );
	}

	/**
	 * True when mbstring or intl is missing; a term with non-ASCII characters then matches every source.
	 */
	public static function degraded(): bool {
		return ! static::capable();
	}

	protected static function capable(): bool {
		return function_exists( 'mb_convert_case' ) && class_exists( 'Normalizer' );
	}

	private static function alternative( string $term ): string {
		$quoted = preg_quote( $term, '/' );
		if ( 1 === preg_match( self::NO_SPACE_SCRIPT, $term ) ) {
			return $quoted;
		}
		$before = 1 === preg_match( '/^[\p{L}\p{N}\p{M}]/u', $term ) ? self::BOUNDARY_BEFORE : '';
		$after  = '';
		if ( 1 === preg_match( '/[\p{L}\p{N}\p{M}]$/u', $term ) ) {
			$after = ( preg_match_all( '/\p{L}/u', $term ) >= 4 ? self::ENDING : '' ) . self::BOUNDARY_AFTER;
		}
		return $before . $quoted . $after;
	}

	/**
	 * Visible, folded text with one space for every whitespace run, or null when it is not valid UTF-8.
	 */
	private static function normalize( string $text ): ?string {
		$text = PlainText::decode( $text );
		// Markers stand for inline markup; soft hyphens and zero-width spaces are invisible.
		$text = preg_replace( '/⟪[^⟪⟫]{1,64}⟫|[\x{00AD}\x{200B}\x{2060}\x{FEFF}]/u', '', $text );
		if ( null === $text ) {
			return null;
		}
		if ( class_exists( 'Normalizer' ) ) {
			$text = \Normalizer::normalize( $text, \Normalizer::FORM_KC );
			if ( false === $text ) {
				return null;
			}
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			// Full folding turns a dotted capital I into "i" plus a combining dot.
			$text = str_replace( "i\u{0307}", 'i', mb_convert_case( $text, MB_CASE_FOLD, 'UTF-8' ) );
		} else {
			$text = strtolower( $text );
		}
		$text = preg_replace( '/[\s\p{Z}]+/u', ' ', $text );
		return null === $text ? null : trim( $text );
	}
}
