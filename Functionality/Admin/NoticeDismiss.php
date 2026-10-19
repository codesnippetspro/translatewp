<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\AdminNotices;
use TranslateWP\Components\AltTextNotice;

/**
 * Wires the X of TranslateWP's dismissible notices to their server-side
 * dismiss action, so a dismissal survives the next page load.
 */
class NoticeDismiss {

	public function __construct( $plugin_name = '', $plugin_version = '' ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_script' ] );
	}

	public static function enqueue_script(): void {
		// The update notice about alt text shows on every admin screen, so its X must work there too.
		if ( ! AdminNotices::on_plugin_screen() && ! AltTextNotice::is_pending() ) {
			return;
		}

		$script = 'document.addEventListener("click",function(e){'
			. 'var x=e.target.closest&&e.target.closest("[data-translatewp-dismiss] .notice-dismiss");if(!x){return;}'
			. 'var n=x.closest("[data-translatewp-dismiss]"),d=new FormData();'
			. 'd.append("action",n.getAttribute("data-translatewp-dismiss"));d.append("_ajax_nonce",n.getAttribute("data-nonce"));'
			. 'fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",body:d,credentials:"same-origin"});'
			. '});';

		wp_add_inline_script( 'common', $script );
	}
}
