<?php

namespace TranslateWP\Components;

/**
 * Whether a request comes from a crawler: a search engine, a link preview
 * fetcher or an AI crawler, judged by what its user agent says about itself.
 */
class CrawlerDetection {

	/**
	 * Lower-case words a crawler's user agent carries. The general words
	 * cover the long tail; the named ones cover crawlers that use none of them.
	 */
	private const SIGNATURES = [
		'bot',
		'crawl',
		'spider',
		'slurp',
		'facebookexternalhit',
		'mediapartners-google',
		'google-inspectiontool',
		'bingpreview',
		'embedly',
		'quora link preview',
		'whatsapp',
		'telegram',
	];

	public static function is_crawler( string $user_agent ): bool {
		$user_agent = strtolower( trim( $user_agent ) );
		$is_crawler = false;

		if ( '' !== $user_agent ) {
			foreach ( self::SIGNATURES as $signature ) {
				if ( false !== strpos( $user_agent, $signature ) ) {
					$is_crawler = true;
					break;
				}
			}
		}

		return (bool) apply_filters( 'translatewp_is_crawler', $is_crawler, $user_agent );
	}

	public static function is_crawler_request(): bool {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return self::is_crawler( $user_agent );
	}
}
