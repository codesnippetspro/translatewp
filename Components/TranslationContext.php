<?php

namespace TranslateWP\Components;

/**
 * Composes the automatic translation context block sent on every job and batch
 * body. It rides in the request metadata only, never inside the fingerprinted
 * ai_settings, so adding or changing it leaves every cached segment matching.
 * The block gives the hosted model the site's own subject so ambiguous words
 * resolve correctly (a dance studio's "zāle" is a hall, not grass).
 */
class TranslationContext {

	// Mirrors the API's TRANSLATION_CONTEXT_MAX_LENGTH.
	public const MAX_CONTEXT_LENGTH = 2000;

	/**
	 * Build the context block for a job or batch. Deterministic: same inputs
	 * always produce the same string, in a fixed order, with the user's own
	 * context text last.
	 *
	 * @param string[] $page_titles Titles of the pages in this batch, if known.
	 */
	public static function build( array $page_titles = [] ): string {
		$lines = [];

		$site_title = sanitize_text_field( PlainText::site_name() );
		if ( '' !== $site_title ) {
			$lines[] = 'Site: ' . $site_title;
		}

		$tagline = sanitize_text_field( (string) get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = 'Tagline: ' . $tagline;
		}

		$category = self::primary_category();
		if ( '' !== $category ) {
			$lines[] = 'Category: ' . $category;
		}

		$titles = self::clean_page_titles( $page_titles );
		if ( 1 === count( $titles ) ) {
			$lines[] = 'Page: ' . $titles[0];
		} elseif ( count( $titles ) > 1 ) {
			$lines[] = 'Pages: ' . implode( '; ', $titles );
		}

		$user_text = self::user_context_text();
		if ( '' !== $user_text ) {
			$lines[] = $user_text;
		}

		return self::truncate( implode( "\n", $lines ) );
	}

	/**
	 * The site's primary topic. Defaults to the site's default post category
	 * name when the owner has moved it off the built-in one, and stays
	 * filterable so an explicit setting can supply it later.
	 */
	private static function primary_category(): string {
		$category = apply_filters( 'translatewp_context_primary_category', self::default_category_name() );

		return is_string( $category ) ? sanitize_text_field( $category ) : '';
	}

	private static function default_category_name(): string {
		$default_category_id = (int) get_option( 'default_category', 0 );
		if ( $default_category_id <= 0 ) {
			return '';
		}

		$name = (string) get_cat_name( $default_category_id );
		if ( '' === $name || 0 === strcasecmp( $name, 'Uncategorized' ) ) {
			return '';
		}

		return $name;
	}

	private static function user_context_text(): string {
		$context = Options::get( 'translations.context' );
		if ( ! is_array( $context ) ) {
			return '';
		}

		return trim( (string) ( $context['text_context'] ?? '' ) );
	}

	/**
	 * De-duplicate and sanitize the page titles collected for the batch.
	 *
	 * @param string[] $page_titles Raw page titles gathered from the batch rows.
	 *
	 * @return string[]
	 */
	private static function clean_page_titles( array $page_titles ): array {
		$titles = [];
		foreach ( $page_titles as $page_title ) {
			$page_title = sanitize_text_field( (string) $page_title );
			if ( '' !== $page_title && ! in_array( $page_title, $titles, true ) ) {
				$titles[] = $page_title;
			}
		}

		return $titles;
	}

	private static function truncate( string $block ): string {
		if ( mb_strlen( $block ) <= self::MAX_CONTEXT_LENGTH ) {
			return $block;
		}

		$block     = mb_substr( $block, 0, self::MAX_CONTEXT_LENGTH );
		$space     = mb_strrpos( $block, ' ' );
		$newline   = mb_strrpos( $block, "\n" );
		$break     = max( false === $space ? 0 : $space, false === $newline ? 0 : $newline );
		if ( $break > 0 ) {
			$block = mb_substr( $block, 0, $break );
		}

		return rtrim( $block );
	}
}
