<?php

namespace TranslateWP\Components;

/**
 * WordPress hands titles and site names back HTML-encoded (`&amp;`), and the
 * admin app escapes once more on render. Every title the plugin stores or
 * sends to the admin goes through here, so it is plain text exactly once.
 */
class PlainText {

	/**
	 * The title of a post, plain.
	 *
	 * @param int|\WP_Post $post The post or its id.
	 */
	public static function title( $post ): string {
		return self::decode( (string) get_the_title( $post ) );
	}

	public static function site_name(): string {
		return self::decode( (string) get_bloginfo( 'name' ) );
	}

	public static function blog_name( $site ): string {
		return self::decode( (string) ( $site->blogname ?? '' ) );
	}

	public static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
