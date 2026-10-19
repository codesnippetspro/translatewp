<?php

namespace TranslateWP\Entities\CPTs;

class Lexicon {

	public const POST_TYPE = 'lexicon';
	public const CATEGORY_TAXONOMY = 'lexicon_category';
	public const LANGUAGES_META_KEY = '_translatewp_lexicon_languages';

	public static function get_all(): array {
		$all_posts = get_posts(
            [
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
        );

		return array_map(
            function ( $post ) {
				return [
					'id'          => $post->ID,
					'term'        => \TranslateWP\Components\PlainText::decode( (string) $post->post_title ),
					'description' => $post->post_content,
					'languages'   => self::languages_of( $post->ID ),
				];
			},
            $all_posts
        );
	}

	/**
	 * The locales a term is scoped to; an empty list means every language.
	 *
	 * @return string[]
	 */
	public static function languages_of( int $post_id ): array {
		$languages = get_post_meta( $post_id, self::LANGUAGES_META_KEY, true );

		return is_array( $languages ) ? array_values( array_filter( array_map( 'strval', $languages ) ) ) : [];
	}

	/**
	 * The terms the translator receives for one target language: every term
	 * scoped to that language or to no language, reduced to the two fields the
	 * translation service reads. The same reduction runs in the browser so the
	 * two sides fingerprint the same settings.
	 *
	 * @return array<int, array{term: string, description: string}>
	 */
	public static function terms_for_language( string $language ): array {
		$terms = [];
		foreach ( self::get_all() as $term ) {
			if ( '' !== $language && $term['languages'] && ! in_array( $language, $term['languages'], true ) ) {
				continue;
			}
			$terms[] = [
				'term'        => (string) $term['term'],
				'description' => (string) $term['description'],
			];
		}

		return $terms;
	}

	public static function from_language( string $language ): array {
		$posts = get_posts(
            [
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
        );

		$valid_posts = array_filter(
            $posts,
            function ( $post ) use ( $language ) {
				return in_array( $language, get_post_meta( $post->ID, self::LANGUAGES_META_KEY, true ) );
			}
        );

		return array_map(
            function ( $post ) {
				return [
					'id' => $post->ID,
					'term' => \TranslateWP\Components\PlainText::decode( (string) $post->post_title ),
					'description' => $post->post_content,
				];
			},
            $valid_posts
        );
	}

	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels' => [
					'name'               => __( 'Lexicon', 'translate' ),
					'singular_name'      => __( 'Lexicon', 'translate' ),
					'add_new'            => __( 'Add New', 'translate' ),
					'add_new_item'       => __( 'Add New Lexicon', 'translate' ),
					'edit_item'          => __( 'Edit Lexicon', 'translate' ),
					'new_item'           => __( 'New Lexicon', 'translate' ),
					'view_item'          => __( 'View Lexicon', 'translate' ),
					'search_items'       => __( 'Search Lexicon', 'translate' ),
					'not_found'          => __( 'No lexicon found.', 'translate' ),
					'not_found_in_trash' => __( 'No lexicon found in Trash.', 'translate' ),
					'all_items'          => __( 'Lexicon', 'translate' ),
				],
				'public'             => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => [ 'slug' => 'lexicon' ],
				'supports'           => [ 'title' ],
				'menu_icon'          => 'dashicons-book-alt',
				'taxonomies'         => [ self::CATEGORY_TAXONOMY ],
			]
		);

		register_taxonomy(
			self::CATEGORY_TAXONOMY,
			[ self::POST_TYPE ],
			[
				'labels' => [
					'name'              => __( 'Lexicon Categories', 'translate' ),
					'singular_name'     => __( 'Lexicon Category', 'translate' ),
					'search_items'      => __( 'Search Lexicon Categories', 'translate' ),
					'all_items'         => __( 'All Lexicon Categories', 'translate' ),
					'edit_item'         => __( 'Edit Lexicon Category', 'translate' ),
					'update_item'       => __( 'Update Lexicon Category', 'translate' ),
					'add_new_item'      => __( 'Add New Lexicon Category', 'translate' ),
					'new_item_name'     => __( 'New Lexicon Category Name', 'translate' ),
					'menu_name'         => __( 'Lexicon Categories', 'translate' ),
				],
				'public'            => false,
				'show_ui'           => false,
				'show_in_menu'      => false,
				'show_admin_column' => false,
				'show_tagcloud'     => false,
				'show_in_nav_menus' => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			]
		);
	}
}
