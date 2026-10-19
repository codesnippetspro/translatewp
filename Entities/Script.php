<?php

namespace TranslateWP\Entities;

abstract class Script {

    protected string $type          = 'frontend';
    protected string $name          = 'script';
    protected array $settings       = [];
    protected mixed $inline_content = false;

    protected string $plugin_name;
    protected string $plugin_version;

    public function __construct( $plugin_name, $plugin_version, $inline_content = false ) {
        $this->plugin_name = $plugin_name;
        $this->plugin_version = $plugin_version;
    }

    private function get_hook(): string {
        switch ( $this->type ) {
            case 'editor':
                return 'enqueue_block_editor_assets';
            case 'frontend':
                return 'wp_enqueue_scripts';
            case 'admin':
                return 'admin_enqueue_scripts';
            default:
                return 'wp_enqueue_scripts';
        }
    }

    abstract public function conditions(): bool;

    public function enqueue(): void {
        $asset_file = include TRANSLATEWP_PATH . 'build/' . $this->name . '.asset.php';
        $dependencies = $asset_file['dependencies'] ?? [];
        $version = $asset_file['version'] ?? $this->plugin_version;

        add_action(
            $this->get_hook(),
            function () use ( $dependencies, $version ) {
				if ( ! $this->conditions() ) {
					return;
				}

				// Enqueue JavaScript.
				call_user_func_array(
                    'wp_enqueue_script',
                    [
						$this->plugin_name . '-' . $this->name,
						TRANSLATEWP_URL . 'build/' . $this->name . '.js',
						$dependencies,
						$version,
						$this->settings,
                    ]
				);

				// React strings resolve through @wordpress/i18n only when the
				// handle carries its translations (languages/*.json via
				// `yarn translate`).
				if ( function_exists( 'wp_set_script_translations' ) ) {
					wp_set_script_translations(
                        $this->plugin_name . '-' . $this->name,
                        'translate',
                        TRANSLATEWP_PATH . 'languages'
					);
				}

				// Enqueue CSS styles if exists.
				$css_path = TRANSLATEWP_PATH . 'build/' . $this->name . '.css';
				if ( file_exists( $css_path ) ) {
					wp_enqueue_style(
                        $this->plugin_name . '-' . $this->name,
                        TRANSLATEWP_URL . 'build/' . $this->name . '.css',
                        [],
                        $version
					);
				}

				$css_path = TRANSLATEWP_PATH . 'build/style-' . $this->name . '.css';
				if ( file_exists( $css_path ) ) {
					wp_enqueue_style(
                        $this->plugin_name . '-style-' . $this->name,
                        TRANSLATEWP_URL . 'build/style-' . $this->name . '.css',
                        [],
                        $version
					);
				}

				$inline_content = $this->inline_content;
				if ( is_callable( $inline_content ) ) {
					$inline_content = $inline_content();
				}

				if ( $inline_content ) {
					wp_add_inline_script(
                        $this->plugin_name . '-' . $this->name,
                        $inline_content,
                        'before'
					);
				}
			}
        );
    }
}
