<?php

namespace TranslateWP\Components;

/**
 * Which pages are left out of translation: a page its editor excluded, and,
 * unless the site switches them on, every password-protected and private
 * page. Every path that reads or sends page text asks here, so the text of
 * such a page never reaches the service.
 */
class PageExclusion {

	public const META_KEY          = '_translatewp_exclude';
	public const PROTECTED_SETTING = 'translate_protected_pages';

	public static function protected_pages_translated(): bool {
		$settings = Options::get( 'translations.settings', [] );

		return is_array( $settings ) && ! empty( $settings[ self::PROTECTED_SETTING ] );
	}

	public static function is_excluded( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( ! empty( get_post_meta( $post_id, self::META_KEY, true ) ) ) {
			return true;
		}

		if ( self::protected_pages_translated() ) {
			return false;
		}

		return 'private' === get_post_status( $post_id )
			|| '' !== (string) get_post_field( 'post_password', $post_id );
	}

	/** The single page a front-end request shows is one that is left out. */
	public static function is_current_page_excluded(): bool {
		return is_singular() && self::is_excluded( (int) get_queried_object_id() );
	}

	public static function register_meta(): void {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		unset( $post_types['attachment'] );

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				(string) $post_type,
				self::META_KEY,
				[
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => true,
					// A protected (underscored) key is writable only through this callback.
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ): bool {
						unset( $allowed, $meta_key );
						return current_user_can( 'edit_post', (int) $post_id );
					},
				]
			);
		}
	}
}
