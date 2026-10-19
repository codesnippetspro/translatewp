<?php

namespace TranslateWP\Components;

/**
 * Resolves the failure reason shown on the Indexing tab for a failed job or
 * batch request. A bring-your-own-key provider failure is answered by the API
 * with a typed, key-free code (pro_required, byok_invalid_key,
 * byok_model_unsupported or byok_provider_error); this surfaces that code so
 * the admin can act on it. Anything else keeps the transport code, so a raw
 * upstream message can never reach the admin screen and a key can never leak
 * through the reason.
 */
class TranslationProviderFailure {

	private const SAFE_CODES = [
		'pro_required',
		'byok_invalid_key',
		'byok_model_unsupported',
		'byok_provider_error',
		'own_providers_missing',
	];

	private const QUOTA_CODES = [
		'limit_exceeded' => 'quota_exceeded',
		'rate_limited'   => 'rate_limited',
	];

	public static function reason( \WP_Error $error, string $fallback ): string {
		$data = $error->get_error_data();
		$code = is_array( $data ) ? sanitize_key( (string) ( $data['payload']['error'] ?? '' ) ) : '';
		if ( in_array( $code, self::SAFE_CODES, true ) ) {
			return $code;
		}
		$quota = is_array( $data ) ? sanitize_key( (string) ( $data['payload']['code'] ?? '' ) ) : '';

		return self::QUOTA_CODES[ $quota ] ?? $fallback;
	}
}
