<?php

namespace TranslateWP\Components;

/**
 * Shared markup and scoping for TranslateWP admin notices. A dismissible
 * notice carries the WordPress X plus the ajax action and nonce that
 * remember the dismissal on the server (Functionality\Admin\NoticeDismiss).
 */
class AdminNotices {

	/** True on TranslateWP's own admin screens (page=translatewp*). */
	public static function on_plugin_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page scoping for a notice.

		return 0 === strpos( $page, 'translatewp' );
	}

	/** Opening tag of a dismissible notice whose X posts $action to admin-ajax. */
	public static function open( string $class_name, string $action ): string {
		return sprintf(
			'<div class="notice %s is-dismissible" data-translatewp-dismiss="%s" data-nonce="%s">',
			esc_attr( $class_name ),
			esc_attr( $action ),
			esc_attr( wp_create_nonce( $action ) )
		);
	}
}
