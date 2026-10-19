<?php

namespace TranslateWP\Entities\Scripts;

use TranslateWP\Components\NetworkAdmin as NetworkAdminData;
use TranslateWP\Entities\Script;

class NetworkAdmin extends Script {

	protected string $type = 'admin';
	protected string $name = 'network-admin';
	protected array $settings = [ 'in_footer' => true ];

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version );
		$this->inline_content = [ $this, 'network_data' ];
	}

	public function network_data(): string {
		return 'window.TranslateWPNetworkAdmin = ' . wp_json_encode(
			NetworkAdminData::page_data(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		) . ';';
	}

	public function conditions(): bool {
		$screen = get_current_screen();

		return is_multisite()
			&& is_network_admin()
			&& current_user_can( 'manage_network_options' )
			&& $screen
			&& 'toplevel_page_translatewp-network-settings-network' === (string) $screen->id;
	}
}
