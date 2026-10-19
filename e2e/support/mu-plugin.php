<?php
/**
 * End-to-end test sites only: no request leaves the machine. The service is
 * configured at an address that does not exist (.wp-env.json), and every call
 * to it is answered here with an empty success, so the plugin behaves as on a
 * connected site whose translations are not ready yet.
 */

add_filter(
	'pre_http_request',
	static function ( $short, $args, $url ) {
		if ( false !== $short || 0 !== strpos( (string) $url, 'http://translatewp-e2e.invalid' ) ) {
			return $short;
		}

		return [
			'headers'  => [],
			'body'     => '{}',
			'response' => [
				'code' => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	},
	1,
	3
);
