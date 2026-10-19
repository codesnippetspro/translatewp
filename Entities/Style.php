<?php

namespace TranslateWP\Entities;

abstract class Style {

    protected string $type          = 'frontend';
    protected string $name          = 'style';
    protected array $dependencies   = [];

    protected string $plugin_name;
    protected string $plugin_version;

    public function __construct( $plugin_name, $plugin_version ) {
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

    /**
     * The built file's modification time versions the stylesheet, so a new
     * build reaches browsers that hold the previous one.
     */
    public function version(): string {
        $path = TRANSLATEWP_PATH . 'build/style-' . $this->name . '.css';
        if ( file_exists( $path ) ) {
            return (string) filemtime( $path );
        }

        return (string) $this->plugin_version;
    }

    public function enqueue(): void {
        add_action(
            $this->get_hook(),
            function () {
				if ( ! $this->conditions() ) {
					return;
				}

				wp_enqueue_style(
                    $this->plugin_name . '-' . $this->name,
                    TRANSLATEWP_URL . 'build/style-' . $this->name . '.css',
                    $this->dependencies,
                    $this->version()
				);
			}
        );
    }
}
