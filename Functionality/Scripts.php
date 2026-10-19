<?php

namespace Translatewp\Functionality;

use TranslateWP\Entities\Script;

class Scripts {


	protected $plugin_name;
	protected $plugin_version;
	protected $scripts_count = 0;

	public function __construct( $plugin_name, $plugin_version ) {
		$this->plugin_name = $plugin_name;
		$this->plugin_version = $plugin_version;

		$this->load_scripts();
	}

	protected function load_scripts() {
		$scripts_dir = TRANSLATEWP_PATH . 'Entities/Scripts/';

		if ( ! is_dir( $scripts_dir ) ) {
			return;
		}

		$script_files = glob( $scripts_dir . '*.php' );

		if ( empty( $script_files ) ) {
			return;
		}

		foreach ( $script_files as $filename ) {
			require_once $filename;

			$class_name = '\\TranslateWP\\Entities\\Scripts\\' . basename( $filename, '.php' );

			if ( class_exists( $class_name ) && is_subclass_of( $class_name, Script::class ) ) {
				try {
					$script_instance = new $class_name( $this->plugin_name, $this->plugin_version );
					$script_instance->enqueue();
					++$this->scripts_count;
				} catch ( \Throwable $e ) {
					// Log error if translatewp_log_info exists, otherwise continue silently.
					if ( function_exists( 'translatewp_log_info' ) ) {
						translatewp_log_info( $e );
					}
					continue;
				}
			}
		}
	}

	public function get_scripts_count() {
		return $this->scripts_count;
	}
}
