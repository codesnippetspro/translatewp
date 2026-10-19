<?php

namespace TranslateWP\Components;

/**
 * Translates the text of a rendered page from a source -> translation map
 * and counts the text it had no translation for. It reads the markup as a
 * stream of tags and text, never as a tree, so the page comes back byte for
 * byte except for the text it replaced.
 *
 * It leaves alone what the browser runtime leaves alone: scripts, styles,
 * code, comments, and any element that opts out of translation.
 */
class PageTextTranslator {

	// Elements whose content is not text to translate.
	private const SKIPPED_ELEMENTS = [ 'script', 'style', 'noscript', 'template', 'code', 'kbd', 'samp', 'var', 'svg', 'math', 'textarea' ];

	// Elements that never have content, so they never open a nesting level.
	private const VOID_ELEMENTS = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ];

	private const NO_TRANSLATE_CLASS = 'translatewp-no-translate';

	// Attributes whose value is shown or read out to the visitor.
	public const TRANSLATED_ATTRIBUTES = [ 'alt', 'title', 'placeholder', 'aria-label' ];

	// A comment, a doctype or declaration, or a tag; attribute values may hold ">".
	private const TOKEN = '/(<!--.*?-->|<![^>]*>|<\/?[a-zA-Z][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>)/s';

	/**
	 * Translates the text of the page and reports what it covered.
	 *
	 * @param string $html       The rendered page.
	 * @param array  $map        Source text => translated text.
	 * @param array  $attributes The attributes to translate; alt text is left out on a site that is not entitled to it.
	 * @return array{html: string, translated: int, missing: int, missing_texts: string[]}
	 */
	public static function translate( string $html, array $map, array $attributes = self::TRANSLATED_ATTRIBUTES ): array {
		$normalized = [];
		foreach ( $map as $source => $translation ) {
			$normalized[ self::normalize( (string) $source ) ] = trim( (string) $translation );
		}

		$tokens     = preg_split( self::TOKEN, $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$tokens     = is_array( $tokens ) ? $tokens : [ $html ];
		$output     = '';
		$translated = 0;
		$missing    = 0;
		$unknown    = [];
		$open       = [];
		$skip_from  = null;
		$in_title   = false;

		foreach ( $tokens as $index => $token ) {
			// Odd positions are the captured tags and comments.
			if ( 1 === $index % 2 ) {
				$opted_out = null !== $skip_from;
				self::track( $token, $open, $skip_from, $in_title );
				// An element that opts out covers its own attributes too.
				$output .= ( $opted_out || ( null !== $skip_from && self::opts_out( $token ) ) )
					? $token
					: self::translate_attributes( $token, $normalized, $attributes );
				continue;
			}

			if ( null !== $skip_from || ! preg_match( '/\p{L}/u', $token ) || self::needs_no_translation( $token ) ) {
				$output .= $token;
				continue;
			}

			$replacement = self::translate_text( $token, $normalized );
			if ( null === $replacement ) {
				$output .= $token;
				if ( ! $in_title ) {
					++$missing;
					$unknown[] = self::normalize( $token );
				}
				continue;
			}

			$output .= $replacement;
			++$translated;
		}

		return [
			'html'       => $output,
			'translated' => $translated,
			'missing'    => $missing,
			// The texts with no translation, for a site owner asking why a
			// crawler was not served the translation.
			'missing_texts' => array_values( array_unique( $unknown ) ),
		];
	}

	/** The tag with the attributes a reader sees or hears translated, where a translation is known. */
	private static function translate_attributes( string $tag, array $normalized, array $attributes ): string {
		if ( ! $normalized || ! $attributes || '<' !== ( $tag[0] ?? '' ) || in_array( $tag[1] ?? '', [ '/', '!' ], true ) ) {
			return $tag;
		}

		$replaced = preg_replace_callback(
			'/(\s(?:' . implode( '|', array_map( 'preg_quote', $attributes ) ) . ')\s*=\s*)(["\'])(.*?)\2/is',
			static function ( array $match ) use ( $normalized ): string {
				$key = self::normalize( html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( ! isset( $normalized[ $key ] ) ) {
					return $match[0];
				}

				return $match[1] . $match[2] . htmlspecialchars( $normalized[ $key ], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . $match[2];
			},
			$tag
		);

		return is_string( $replaced ) ? $replaced : $tag;
	}

	/** The text with its translation swapped in and its own edge spaces kept, null when none is known. */
	private static function translate_text( string $text, array $normalized ): ?string {
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$key     = self::normalize( $decoded );
		if ( ! isset( $normalized[ $key ] ) ) {
			return null;
		}

		preg_match( '/^(\s*).*?(\s*)$/su', $text, $edges );

		return ( $edges[1] ?? '' ) . htmlspecialchars( $normalized[ $key ], ENT_NOQUOTES | ENT_HTML5, 'UTF-8' ) . ( $edges[2] ?? '' );
	}

	/**
	 * Follows the open elements so text inside a skipped or opted-out element
	 * is left alone until that element closes.
	 */
	private static function track( string $tag, array &$open, ?int &$skip_from, bool &$in_title ): void {
		if ( ! preg_match( '/^<(\/?)([a-zA-Z][a-zA-Z0-9-]*)/', $tag, $parts ) ) {
			return;
		}

		$name = strtolower( $parts[2] );
		if ( 'title' === $name ) {
			$in_title = '' === $parts[1];
		}

		if ( '/' === $parts[1] ) {
			$position = array_search( $name, array_reverse( $open, true ), true );
			if ( false === $position ) {
				return;
			}
			$open = array_slice( $open, 0, (int) $position );
			if ( null !== $skip_from && count( $open ) <= $skip_from ) {
				$skip_from = null;
			}
			return;
		}

		if ( in_array( $name, self::VOID_ELEMENTS, true ) || '/>' === substr( $tag, -2 ) ) {
			return;
		}

		if ( null === $skip_from && ( in_array( $name, self::SKIPPED_ELEMENTS, true ) || self::opts_out( $tag ) ) ) {
			$skip_from = count( $open );
		}
		$open[] = $name;
	}

	private static function opts_out( string $tag ): bool {
		if ( preg_match( '/\stranslate\s*=\s*["\']?no["\'\s>]/i', $tag ) || preg_match( '/\sdata-no-translate[\s=>\/]/i', $tag ) ) {
			return true;
		}

		return 1 === preg_match( '/\sclass\s*=\s*["\'][^"\']*\b' . preg_quote( self::NO_TRANSLATE_CLASS, '/' ) . '\b/i', $tag );
	}

	/** An email address or a web address reads the same in every language. */
	private static function needs_no_translation( string $text ): bool {
		$text = trim( $text );

		return 1 === preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $text )
			|| 1 === preg_match( '#^(https?://|www\.)\S+$#i', $text );
	}

	private static function normalize( string $text ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $collapsed ) ? $collapsed : $text );
	}
}
