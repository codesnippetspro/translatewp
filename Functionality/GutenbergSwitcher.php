<?php

namespace TranslateWP\Functionality;

class GutenbergSwitcher {

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'init', [ $this, 'register_block' ] );
	}

	public function register_block(): void {
		register_block_type_from_metadata(
			TRANSLATEWP_PATH . 'blocks/language-switcher',
			[
				'render_callback' => [ $this, 'render_block' ],
			]
		);
	}

	public function render_block( $attributes = [], $content = '', $block = null ): string {
		unset( $attributes, $content, $block );

		return SwitcherSurfaces::PLACEHOLDER;
	}
}
