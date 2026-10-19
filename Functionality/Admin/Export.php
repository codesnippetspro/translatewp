<?php

namespace TranslateWP\Functionality\Admin;

use TranslateWP\Components\SiteExport;

/**
 * The wp-admin export action: an administrator follows the "Export your
 * data" link and the browser saves the file the service built. When no file
 * arrives the administrator is sent back to Settings with the reason.
 */
class Export {

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		add_action( 'admin_post_' . SiteExport::ACTION, [ $this, 'export' ] );
		add_action( 'admin_notices', [ $this, 'render_failure_notice' ] );
	}

	public function export(): void {
		check_admin_referer( SiteExport::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site\'s TranslateWP data.', 'translate' ), '', [ 'response' => 403 ] );
		}

		$export = SiteExport::fetch();
		\TranslateWP\Components\ActionLog::record( is_wp_error( $export ) ? 'Export failed: ' . $export->get_error_code() : 'Translations exported', is_wp_error( $export ) ? 'error' : 'info' );
		if ( is_wp_error( $export ) ) {
			wp_safe_redirect( self::settings_url( $export->get_error_code() ) );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: ' . $export['type'] );
		header( 'Content-Disposition: attachment; filename="' . $export['filename'] . '"' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		$size = filesize( $export['file'] );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the export file to the browser.
		readfile( $export['file'] );
		wp_delete_file( $export['file'] );
		exit;
	}

	/** Says on the plugin's pages why the last export gave no file. */
	public function render_failure_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a result code to show a message; changes nothing.
		$code = isset( $_GET[ SiteExport::RESULT_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ SiteExport::RESULT_PARAM ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $code || 0 !== strpos( $page, 'translatewp' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible" data-twp-export-failed><p>' . esc_html( SiteExport::message( $code ) ) . '</p></div>';
	}

	private static function settings_url( string $code ): string {
		return add_query_arg(
			[
				'page'                    => 'translatewp-settings',
				SiteExport::RESULT_PARAM => $code,
			],
			admin_url( 'admin.php' )
		) . '#advanced';
	}
}
