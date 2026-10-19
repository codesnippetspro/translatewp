<?php

namespace TranslateWP\Components;

/**
 * Server-side handling of two refusals on every job and batch POST, neither
 * honoured from a client claim: a pro_required answer is retried once without
 * the Pro-only AI settings (the API is the only gate on them), and a
 * limit_exceeded answer is retried once with the site's own providers when
 * the quota handoff is on and the language has some.
 */
class TranslationProRetry {

	public const NOTICE_TRANSIENT = 'translatewp_pro_required_notice';

	private const ERROR_PRO_REQUIRED    = 'pro_required';
	private const HTTP_FORBIDDEN        = 403;
	private const NOTICE_TTL_SECONDS    = 300;

	/**
	 * Posts a job or batch body, retrying once on each refusal this class handles.
	 *
	 * @param string $path            The service path.
	 * @param array  $body            The request body.
	 * @param int    $timeout_seconds The request timeout.
	 * @param string $language        The target language of a batch, when the body does not name it.
	 * @return array|\WP_Error
	 */
	public static function post( string $path, array $body, int $timeout_seconds, string $language = '' ) {
		$job_id   = QuotaHandoff::job_id_of_path( $path );
		$language = '' !== $language ? $language : self::language_of( $body );

		// A request that must carry the own providers (a pinned job, or any job
		// while the quota is exhausted) never falls back to the TranslateWP
		// model: without a usable key it is refused as the quota was.
		if ( ! isset( $body['byok'] ) && QuotaHandoff::wants_own_providers( $job_id ) && [] === QuotaHandoff::providers_for( $language ) ) {
			return self::own_providers_missing( $language );
		}
		$body = self::with_own_providers_when_wanted( $body, $job_id, $language );

		$response = SiteRequests::post( $path, $body, $timeout_seconds );
		if ( self::is_pro_required( $response ) ) {
			self::record_notice();

			return SiteRequests::post( $path, self::without_pro_only_ai_settings( $body ), $timeout_seconds );
		}

		if ( QuotaHandoff::is_quota_refusal( $response ) && QuotaHandoff::enabled() && ! isset( $body['byok'] ) ) {
			$providers = QuotaHandoff::providers_for( $language );
			if ( [] === $providers ) {
				return $response;
			}
			QuotaHandoff::activate( QuotaHandoff::window_end_of( $response ) );
			ActionLog::record( sprintf( 'The plan\'s translation quota is exhausted; translations for %s continue with the site\'s own providers', $language ), 'warning' );
			$response = SiteRequests::post( $path, array_merge( $body, [ 'byok' => $providers ] ), $timeout_seconds );
			self::pin_from_response( $job_id, $response );
		}

		$response = self::remember_pinned_job( $job_id, $body, $response );
		if ( ! is_wp_error( $response ) && self::closes_job( $path, $body ) ) {
			QuotaHandoff::release_job( '' !== $job_id ? $job_id : (string) ( $response['jobId'] ?? '' ) );
		}

		return $response;
	}

	/** A batch that closes the job's input ends the job on the service; a cancel is released where it is sent. */
	private static function closes_job( string $path, array $body ): bool {
		return '' !== QuotaHandoff::job_id_of_path( $path ) && ! empty( $body['closeInput'] );
	}

	/** The refusal for a request that must carry the own providers but has none usable, shaped like the service's quota answer. */
	private static function own_providers_missing( string $language ): \WP_Error {
		ActionLog::record( sprintf( 'Translation for %s is paused: the plan\'s quota is exhausted and no own provider has a usable key', $language ), 'error' );

		return new \WP_Error(
			'remote_error',
			'The plan\'s translation quota is exhausted and no own provider has a usable key',
			[
				'status'  => 429,
				'payload' => [
					'error' => 'own_providers_missing',
					'code' => 'limit_exceeded',
				],
			]
		);
	}

	/**
	 * A pinned job carries the own providers on every request; a new job
	 * opens with them while the handoff is active. Nothing is added when the
	 * body already carries a key.
	 */
	private static function with_own_providers_when_wanted( array $body, string $job_id, string $language ): array {
		if ( isset( $body['byok'] ) || ! QuotaHandoff::wants_own_providers( $job_id ) ) {
			return $body;
		}
		$providers = QuotaHandoff::providers_for( $language );

		return [] === $providers ? $body : array_merge( $body, [ 'byok' => $providers ] );
	}

	private static function pin_from_response( string $job_id, $response ): void {
		if ( is_wp_error( $response ) ) {
			return;
		}
		QuotaHandoff::pin_job( '' !== $job_id ? $job_id : (string) ( $response['jobId'] ?? '' ) );
	}

	/** A job opened with the own providers while the handoff is active stays with them. */
	private static function remember_pinned_job( string $job_id, array $body, $response ) {
		if ( '' === $job_id && isset( $body['byok'] ) && ! is_wp_error( $response ) && QuotaHandoff::enabled() && QuotaHandoff::is_active() ) {
			QuotaHandoff::pin_job( (string) ( $response['jobId'] ?? '' ) );
		}

		return $response;
	}

	private static function language_of( array $body ): string {
		return (string) ( $body['targetLang'] ?? '' );
	}

	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( self::NOTICE_TRANSIENT ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'translatewp' ) ) {
			return;
		}

		delete_transient( self::NOTICE_TRANSIENT );

		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'A Pro setting was not applied because this account is not on Pro.', 'translate' )
			. '</p></div>';
	}

	private static function is_pro_required( $response ): bool {
		if ( ! is_wp_error( $response ) ) {
			return false;
		}

		$data = $response->get_error_data();

		return is_array( $data )
			&& self::HTTP_FORBIDDEN === (int) ( $data['status'] ?? 0 )
			&& self::ERROR_PRO_REQUIRED === (string) ( $data['payload']['error'] ?? '' );
	}

	private static function record_notice(): void {
		set_transient( self::NOTICE_TRANSIENT, true, self::NOTICE_TTL_SECONDS );
	}

	private static function without_pro_only_ai_settings( array $body ): array {
		if ( isset( $body['aiSettings'] ) && is_array( $body['aiSettings'] ) ) {
			unset( $body['aiSettings']['custom_style'], $body['aiSettings']['custom_tone'] );
		}

		return $body;
	}
}
