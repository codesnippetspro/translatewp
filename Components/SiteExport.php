<?php

namespace TranslateWP\Components;

/**
 * The export of this site's translations, run by an administrator from
 * wp-admin. The service builds the file and decides its shape; the plugin
 * asks for it with the site's own credentials and hands it to the browser
 * unchanged. Every plan may export.
 */
class SiteExport {

	public const ACTION        = 'translatewp_export_site';
	public const RESULT_PARAM  = 'translatewp_export';
	public const ROUTE         = '/v1/site/export';
	public const TIMEOUT_SECONDS = 120;

	/** The address of the export action for the current administrator. */
	public static function url(): string {
		// Built unescaped: the page hands it to a script as data.
		return add_query_arg(
			[
				'action'   => self::ACTION,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Fetches the export into a temporary file.
	 *
	 * @param callable|null $download fn( string $url, array $headers, string $file ): array{status:int,disposition:string,type:string}|\WP_Error;
	 *                                writes the response body to $file. The service by default.
	 * @return array{file:string,filename:string,type:string}|\WP_Error The caller removes the file.
	 */
	public static function fetch( ?callable $download = null ) {
		$headers = SiteRequests::auth_headers();
		if ( ! $headers ) {
			return self::error( 'not_connected' );
		}

		$file = self::temporary_file();
		if ( '' === $file ) {
			return self::error( 'export_failed' );
		}

		$answer = call_user_func(
			$download ?? [ self::class, 'download' ],
			RemoteApi::endpoint( self::ROUTE ),
			array_merge( [ 'Accept' => 'text/csv, application/json' ], $headers ),
			$file
		);
		if ( ! is_array( $answer ) ) {
			self::remove( $file );
			return self::error( 'export_failed' );
		}

		$status = (int) ( $answer['status'] ?? 0 );
		if ( 200 !== $status ) {
			$body = json_decode( (string) file_get_contents( $file ), true );
			self::remove( $file );
			return self::error( self::refusal( $status, is_array( $body ) ? sanitize_key( (string) ( $body['code'] ?? '' ) ) : '' ) );
		}

		// The file is the service's to shape and is handed on unchanged. A
		// transfer that ended early is reported by the request itself and
		// never reaches this point. What is refused here: nothing at all, a
		// kind of file that is not an export, and a JSON file that does not
		// parse (the one case where the file is read, to check it is whole).
		$type = self::file_type( (string) ( $answer['type'] ?? '' ) );
		$size = (int) filesize( $file );
		if ( '' === $type || $size < 1 || ( 'application/json' === strtok( $type, ';' ) && null === json_decode( (string) file_get_contents( $file ), true ) ) ) {
			self::remove( $file );
			return self::error( 'export_failed' );
		}

		return [
			'file'     => $file,
			'filename' => self::filename( (string) ( $answer['disposition'] ?? '' ), $type ),
			'type'     => $type,
		];
	}

	/** What to tell the administrator when the export did not arrive. */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'not_connected':
				return __( 'The export needs a connected site. Reconnect TranslateWP under Settings, Connection, and try again.', 'translate' );
			case 'update_required':
				return __( 'Update the TranslateWP plugin to its latest version, then run the export again.', 'translate' );
			case 'export_not_offered':
				return __( 'The export is not available yet. Try again later.', 'translate' );
			case 'export_unavailable':
				return __( 'Your data could not be read just now, so no file was made. Try again in a few minutes.', 'translate' );
			case 'site_not_found':
				return __( 'TranslateWP no longer knows this site. Reconnect it under Settings, Connection, and try again.', 'translate' );
			default:
				return __( 'The export did not finish, so no file was made. Try again.', 'translate' );
		}
	}

	/**
	 * Sends the request and writes the body to the file as it arrives.
	 *
	 * @return array{status:int,disposition:string,type:string}|\WP_Error
	 */
	public static function download( string $url, array $headers, string $file ) {
		$response = wp_remote_get(
			$url,
			[
				'timeout'  => self::TIMEOUT_SECONDS,
				'headers'  => $headers,
				'stream'   => true,
				'filename' => $file,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'disposition' => (string) wp_remote_retrieve_header( $response, 'content-disposition' ),
			'type'        => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		];
	}

	private static function refusal( int $status, string $code ): string {
		if ( 401 === $status ) {
			return 'not_connected';
		}
		if ( 403 === $status && 'upgrade_required' === $code ) {
			return 'update_required';
		}
		if ( 404 === $status ) {
			// The service answers a route it does not have yet without a code.
			return 'site_not_found' === $code ? 'site_not_found' : 'export_not_offered';
		}
		if ( 'export_unavailable' === $code ) {
			return 'export_unavailable';
		}

		return 'export_failed';
	}

	/** The file kinds an export may be, by the extension each is saved under. */
	private const FILE_TYPES = [
		'text/csv'         => 'csv',
		'application/json' => 'json',
	];

	/** The content type to hand on, '' when the answer is not an export file. */
	private static function file_type( string $header ): string {
		$type = strtolower( trim( (string) strtok( $header, ';' ) ) );

		return isset( self::FILE_TYPES[ $type ] ) ? $type . '; charset=utf-8' : '';
	}

	/**
	 * The name the service gave the file, when it is a plain file name of the
	 * file's kind; otherwise the name the export contract sets for this site.
	 */
	private static function filename( string $disposition, string $type ): string {
		$extension = self::FILE_TYPES[ (string) strtok( $type, ';' ) ] ?? 'csv';
		if ( preg_match( '/filename="?([A-Za-z0-9][A-Za-z0-9._-]*\.' . $extension . ')"?/', $disposition, $match ) ) {
			return $match[1];
		}

		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = (string) preg_replace( '/[^a-z0-9.-]/', '-', $host );

		return 'translatewp-export-' . ( '' !== $host ? $host : 'site' ) . '-' . gmdate( 'Y-m-d' ) . '.' . $extension;
	}

	private static function temporary_file(): string {
		if ( ! function_exists( 'wp_tempnam' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'translatewp-export' ) : tempnam( sys_get_temp_dir(), 'twp' );

		return is_string( $file ) ? $file : '';
	}

	private static function remove( string $file ): void {
		if ( '' !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	private static function error( string $code ): \WP_Error {
		return new \WP_Error( $code, self::message( $code ) );
	}
}
