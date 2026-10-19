<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\Options;
use TranslateWP\Components\PageExclusion;

class Translations {

    protected $plugin_name;
    protected $plugin_version;

    public function __construct( $plugin_name, $plugin_version ) {
        $this->plugin_name = $plugin_name;
        $this->plugin_version = $plugin_version;

        add_action( 'init', [ PageExclusion::class, 'register_meta' ] );
        add_action( 'wp_body_open', [ $this, 'mark_non_translatable_content' ] );
        add_action( 'wp_footer', [ $this, 'close_non_translatable_content' ] );
    }

    public function should_be_excluded() {
        $translation_settings = (array) Options::get( 'translations.settings' );

        if ( (bool) ( $translation_settings['translate_all_content'] ?? false ) ) {
			return false;
        }

        $excluded_post_types = array_values( array_filter( (array) ( $translation_settings['post_types'] ?? [] ), 'is_string' ) );
        $excluded_category_ids = array_map( 'intval', (array) ( $translation_settings['categories'] ?? [] ) );
        $excluded_category_ids = array_values( array_filter( $excluded_category_ids ) );

        if ( in_array( get_post_type(), $excluded_post_types, true ) ) {
			return true;
        }

        if ( ! empty( $excluded_category_ids ) ) {
            if ( is_category() && in_array( (int) get_queried_object_id(), $excluded_category_ids, true ) ) {
                return true;
            }

            if ( is_singular() && has_category( $excluded_category_ids ) ) {
                return true;
            }
        }

        return false;
    }

    public function mark_non_translatable_content() {
        if ( ! $this->should_be_excluded() ) {
			return;
        }

        echo "<div class='translatewp-no-translate'>";
    }

    public function close_non_translatable_content() {
        if ( ! $this->should_be_excluded() ) {
			return;
        }

        echo '</div>';
    }
}
