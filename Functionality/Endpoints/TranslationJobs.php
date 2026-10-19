<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use TranslateWP\Components\TranslationSubscription;
use TranslateWP\Components\SiteRequests;
use TranslateWP\Components\ServedTelemetry;
use TranslateWP\Components\Sites;
use TranslateWP\Components\TranslationContext;
use TranslateWP\Components\TranslationFingerprint;
use TranslateWP\Components\TranslationMarkerPayload;
use TranslateWP\Components\TranslationProRetry;
use TranslateWP\Components\TranslationProxyClientIp;
use TranslateWP\Components\TranslationProxyDedupe;
use TranslateWP\Components\TranslationProxyRateLimit;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Components\TranslationProxyStorage;
use TranslateWP\Components\TranslationUrl;

class TranslationJobs extends BaseApiEndpoints {

	public const PROXY_ROUTE_CREATE_JOB = 'create-job';
	public const PROXY_ROUTE_PUSH_BATCH = 'push-batch';
	public const PROXY_ROUTE_CANCEL      = 'cancel';
	public const PROXY_ROUTE_STATUS      = 'status';

	private const MAX_BATCH_ITEMS            = 24;
	private const MAX_MARKER_PAYLOAD_LENGTH  = 65536;
	private const MAX_BATCH_ID_LENGTH        = 128;
	private const MAX_GROUP_ID_LENGTH        = 128;
	private const MAX_CONTEXT_KEY_LENGTH     = 1024;
	private const MAX_LANG_LENGTH            = 35;
	private const MAX_LANGUAGE_NAME_LENGTH   = 100;
	private const MAX_HASH_LENGTH            = 256;
	private const MAX_REQUEST_BODY_BYTES     = 524288;
	private const JOB_POST_TIMEOUT_SECONDS   = 60;
	private const JOB_LANG_SECONDS = 130;

	private const MISS_MAX_ITEMS    = 40;
	private const MISS_MAX_TOTAL_BYTES = 262144;
	// A miss item is capped at the size the visitor bundle never exceeds for
	// one group; push-batch items keep their own, larger cap.
	private const MISS_MAX_ITEM_BYTES = 16384;
	private const MISS_PRIORITY     = 10;
	private const MISS_OBJECT_TYPE  = 'miss';
	private const MAX_PAGE_URL_LENGTH = 2048;
	private const MAX_PAGE_TITLE_LENGTH = 300;
	private const MAX_NEIGHBOUR_LENGTH = 120;

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/translation' );

		add_action( 'admin_notices', [ TranslationProRetry::class, 'render_notice' ] );
	}

	public function define_endpoints() {
		$create_job = new PostEndpoint(
			$this->namespace,
			self::PROXY_ROUTE_CREATE_JOB,
			[ $this, 'create_job' ],
			[ $this, 'public_permissions_check' ]
		);

		$push_batch = new PostEndpoint(
			$this->namespace,
			self::PROXY_ROUTE_PUSH_BATCH,
			[ $this, 'push_batch' ],
			[ $this, 'public_permissions_check' ]
		);

		$cancel = new PostEndpoint(
			$this->namespace,
			self::PROXY_ROUTE_CANCEL,
			[ $this, 'cancel_job' ],
			[ $this, 'public_permissions_check' ]
		);

		$status = new GetEndpoint(
			$this->namespace,
			self::PROXY_ROUTE_STATUS,
			[ $this, 'job_status' ],
			[ $this, 'public_permissions_check' ]
		);

		$misses = new PostEndpoint(
			$this->namespace,
			'miss',
			[ $this, 'report_misses' ],
			[ $this, 'public_permissions_check' ]
		);

		$anchor_missing = new PostEndpoint(
			$this->namespace,
			'anchor-missing',
			[ $this, 'report_anchor_missing' ],
			[ $this, 'public_permissions_check' ]
		);

		$served_timing = new PostEndpoint(
			$this->namespace,
			'telemetry/served',
			[ $this, 'report_served_timing' ],
			[ $this, 'public_permissions_check' ]
		);

		return [
			$served_timing,
			$create_job,
			$push_batch,
			$cancel,
			$status,
			$misses,
			$anchor_missing,
		];
	}

	public function report_served_timing( $request ) {
		return ServedTelemetry::record( $request );
	}

	/**
	 * A visitor's page that holds an anchored placement whose element it does
	 * not have, so the Dashboard can name it. Takes the page address only.
	 */
	public function report_anchor_missing( $request ) {
		$url = (string) $request->get_param( 'url' );
		if ( ! $this->is_site_page_url( $url ) ) {
			return new \WP_Error( 'invalid_parameter', 'url must be a page of this site', [ 'status' => 400 ] );
		}
		\TranslateWP\Components\AnchorMisses::record( $url );

		return [ 'recorded' => true ];
	}

	/**
	 * Public miss-proxy (C5, sanctioned public-endpoint exception — see the
	 * plugin AGENTS.md): a visitor reports uncached segment groups; the
	 * server recomputes each fingerprint from the submitted content with the
	 * site's own settings (the client never supplies a hash), dedupes per
	 * segment×language, throttles per IP, and enqueues server-side re-warm
	 * through the index queue.
	 */
	public function report_misses( $request ) {
		$body_limit = $this->enforce_body_size_limit( $request );
		if ( is_wp_error( $body_limit ) ) {
			return $body_limit;
		}

		$rate_limit = $this->enforce_rate_limit( TranslationProxyRateLimit::BUCKET_MUTATION );
		if ( is_wp_error( $rate_limit ) ) {
			return self::with_retry_after( $rate_limit );
		}

		$language = $this->sanitize_language( (string) $request->get_param( 'language' ), '' );
		if ( '' === $language || ! TranslationProxySettings::is_target_language( $language ) ) {
			return new \WP_Error(
				'language_not_configured',
				'Language is not configured for this site',
				[ 'status' => 400 ]
			);
		}

		$raw_items = $request->get_param( 'items' );
		if ( ! is_array( $raw_items ) || ! $raw_items || count( $raw_items ) > self::MISS_MAX_ITEMS ) {
			return new \WP_Error(
				'invalid_parameter',
				'items must be a non-empty array of at most ' . self::MISS_MAX_ITEMS,
				[ 'status' => 400 ]
			);
		}

		$site_id = Sites::registered_id();
		if ( '' === $site_id ) {
			return new \WP_Error(
				'not_connected',
				'Site is not connected',
				[ 'status' => 409 ]
			);
		}

		// A report names a page of this site; any other host is refused.
		if ( ! $this->is_site_page_url( (string) $request->get_param( 'url' ) ) ) {
			return new \WP_Error(
				'invalid_page_url',
				'url must be a page of this site',
				[ 'status' => 400 ]
			);
		}

		$queue       = new \TranslateWP\Components\IndexQueue();
		$source_lang = TranslationProxySettings::default_locale();
		$ai_settings = TranslationProxySettings::ai_settings( $language );
		$page_url    = $this->sanitize_page_url( (string) $request->get_param( 'url' ) );
		$page_title  = $this->sanitize_page_title( (string) $request->get_param( 'pageTitle' ) );
		$accepted    = 0;
		$deduped     = 0;
		$skipped     = 0;
		$queued_ids  = [];
		$total_bytes = 0;
		$items       = [];
		$hashes      = [];
		$subscription_hashes = [];

		foreach ( $raw_items as $raw_item ) {
			if ( ! is_array( $raw_item ) ) {
				$skipped++;
				continue;
			}

			$group_id       = sanitize_text_field( (string) ( $raw_item['groupId'] ?? '' ) );
			$marker_payload = (string) ( $raw_item['markerPayload'] ?? '' );
			$context_key    = sanitize_text_field( (string) ( $raw_item['contextKey'] ?? '' ) );
			$context_kind   = sanitize_key( (string) ( $raw_item['contextKind'] ?? '' ) );
			$neighbours     = $this->sanitize_neighbours( $raw_item['neighbours'] ?? null );

			if ( '' === $group_id || strlen( $group_id ) > self::MAX_GROUP_ID_LENGTH
				|| '' === trim( $marker_payload ) || strlen( $marker_payload ) > self::MISS_MAX_ITEM_BYTES
				|| strlen( $context_key ) > self::MAX_CONTEXT_KEY_LENGTH
				|| ! TranslationFingerprint::is_allowed_context_kind( $context_kind ) ) {
				$skipped++;
				continue;
			}

			// Cap the TOTAL stored payload, not just per item — 40 items each
			// near the per-item max would otherwise store ~2.5MB of queue rows.
			// Once the cap is reached, skip the remaining items instead of
			// failing a batch that already has other valid items in it.
			if ( $total_bytes + strlen( $marker_payload ) > self::MISS_MAX_TOTAL_BYTES ) {
				$skipped++;
				continue;
			}
			$total_bytes += strlen( $marker_payload );

			// The fingerprint is recomputed server-side from the submitted
			// content and the site's own settings — never taken from the client.
			// contextKey rides along as translation metadata but is kept out of
			// the fingerprint so identical text shares one cache entry.
			$hash = TranslationFingerprint::compute_hash(
				$marker_payload,
				$source_lang,
				$language,
				$context_kind,
				$ai_settings
			);
			$subscription_hashes[] = $hash;

			$claim = TranslationProxyDedupe::claim_segment( $site_id, $language, $hash );
			if ( is_wp_error( $claim ) ) {
				$deduped++;
				continue;
			}

			$items[]  = array_merge(
				[
					'groupId'       => $group_id,
					'markerPayload' => $marker_payload,
					'contextKey'    => $context_key,
					'contextKind'   => $context_kind,
				],
				null === $neighbours ? [] : [ 'neighbours' => $neighbours ],
				[
					'cache' => [
						'siteId' => $site_id,
						'hash'   => $hash,
					],
				]
			);
			$hashes[] = $hash;
		}
		if ( $items ) {
			// Only text newly claimed for translation spends the client's item
			// budget; a report of text already queued is free.
			$budget = TranslationProxyRateLimit::charge_miss_items( $site_id, TranslationProxyClientIp::resolve(), count( $items ) );
			if ( is_wp_error( $budget ) ) {
				TranslationProxyDedupe::release_segments( $site_id, $language, $hashes );
				return self::with_retry_after( $budget );
			}

			// One queue row per page and language carries every missed group,
			// so a miss costs one row, not one row per segment.
			$queued_row = $queue->enqueue_miss_page(
				[
					'object_type' => self::MISS_OBJECT_TYPE,
					'object_id'   => self::miss_page_id( $page_url ),
					'url'         => $page_url,
					'page_title'  => $page_title,
					'language'    => $language,
					'priority'    => self::MISS_PRIORITY,
				],
				$items
			);
			if ( false !== $queued_row ) {
				$accepted     = count( $items );
				$queued_ids[] = (int) $queued_row;
			} else {
				TranslationProxyDedupe::release_segments( $site_id, $language, $hashes );
				$deduped += count( $items );
				$subscription_hashes = array_values( array_diff( $subscription_hashes, $hashes ) );
			}
		}
		if ( count( $raw_items ) === $skipped ) {
			return new \WP_Error( 'invalid_parameter', 'Item fields are invalid', [ 'status' => 400 ] );
		}

		if ( $accepted > 0 ) {
			\TranslateWP\Functionality\Indexer::schedule_drain();
			// The inline drain serves the visitor's own row right after the
			// response; the cron kick is the fallback when that path is not
			// available on the host.
			$mode = \TranslateWP\Functionality\Indexer::schedule_inline_drain( $queued_ids );
			if ( 'inline' !== $mode ) {
				if ( function_exists( 'spawn_cron' ) ) {
					spawn_cron();
				}
				// Cron does not start while another scheduled run holds its
				// lock, and a visitor waiting on the page makes no further
				// request to start it later: ask the site to drain now too.
				\TranslateWP\Components\LoopbackKick::fire();
			}
		}

		$response = [
			'accepted' => $accepted,
			'deduped'  => $deduped,
			'skipped'  => $skipped,
		];
		$subscription = TranslationSubscription::mint( $language, array_values( array_unique( $subscription_hashes ) ) );
		if ( null !== $subscription ) {
			$response['subscription'] = $subscription;
		}

		return $response;
	}

	/**
	 * A rate-limit refusal as a response telling the client when its window
	 * ends; any other error is returned unchanged.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	private static function with_retry_after( \WP_Error $error ) {
		$data = $error->get_error_data();
		if ( 'rate_limited' !== $error->get_error_code() || ! is_array( $data ) || empty( $data['retry_after'] ) ) {
			return $error;
		}

		return new \WP_REST_Response(
			[
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => [ 'status' => 429 ],
			],
			429,
			[ 'Retry-After' => (string) (int) $data['retry_after'] ]
		);
	}

	/**
	 * The queue object id of a page's miss row, derived from the page URL so
	 * every miss on that page lands in the same row.
	 */
	private static function miss_page_id( string $page_url ): int {
		return (int) hexdec( substr( hash( 'sha256', $page_url ), 0, 15 ) );
	}

	public function create_job( $request ) {
		$body_limit = $this->enforce_body_size_limit( $request );
		if ( is_wp_error( $body_limit ) ) {
			return $body_limit;
		}

		$rate_limit = $this->enforce_rate_limit( TranslationProxyRateLimit::BUCKET_MUTATION );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$source_lang = $this->sanitize_language( (string) $request->get_param( 'sourceLang' ), 'auto' );
		$target_lang = $this->sanitize_language( (string) $request->get_param( 'targetLang' ), '' );
		if ( '' === $target_lang ) {
			return new \WP_Error( 'missing_parameter', 'targetLang is required', [ 'status' => 400 ] );
		}

		if ( ! TranslationProxySettings::is_target_language( $target_lang ) ) {
			return new \WP_Error(
				'language_not_configured',
				'Language is not configured for this site',
				[ 'status' => 400 ]
			);
		}

		$body = [
			'sourceLang' => $source_lang,
			'targetLang' => $target_lang,
		];

		$source_language_name = $this->sanitize_language_name( (string) $request->get_param( 'sourceLanguageName' ) );
		if ( '' !== $source_language_name ) {
			$body['sourceLanguageName'] = $source_language_name;
		}

		$target_language_name = $this->sanitize_language_name( (string) $request->get_param( 'targetLanguageName' ) );
		if ( '' !== $target_language_name ) {
			$body['targetLanguageName'] = $target_language_name;
		}

		$body['context']    = TranslationContext::build();
		$body['aiSettings'] = apply_filters( 'translatewp_translation_ai_settings', TranslationProxySettings::ai_settings( $target_lang ) );
		$body               = apply_filters( 'translatewp_translation_job_request_body', $body );

		$response = TranslationProRetry::post( '/v1/translation/jobs', $body, self::JOB_POST_TIMEOUT_SECONDS );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$job_id = sanitize_text_field( (string) ( $response['jobId'] ?? '' ) );
		if ( '' !== $job_id ) {
			$remembered = $this->remember_job_languages(
				$job_id,
				sanitize_text_field( (string) ( $response['sourceLang'] ?? $source_lang ) ),
				sanitize_text_field( (string) ( $response['targetLang'] ?? $target_lang ) )
			);
			if ( is_wp_error( $remembered ) ) {
				return $remembered;
			}
		}

		return [
			'jobId'              => $job_id,
			'sourceLang'         => sanitize_text_field( (string) ( $response['sourceLang'] ?? $source_lang ) ),
			'sourceLanguageName' => isset( $response['sourceLanguageName'] )
				? sanitize_text_field( (string) $response['sourceLanguageName'] )
				: null,
			'targetLang'         => sanitize_text_field( (string) ( $response['targetLang'] ?? $target_lang ) ),
			'targetLanguageName' => isset( $response['targetLanguageName'] )
				? sanitize_text_field( (string) $response['targetLanguageName'] )
				: null,
		];
	}

	public function public_permissions_check( $request ) {
		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' !== $origin && ! $this->is_same_site_origin( $origin ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Cross-origin requests are not allowed',
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function push_batch( $request ) {
		$body_limit = $this->enforce_body_size_limit( $request );
		if ( is_wp_error( $body_limit ) ) {
			return $body_limit;
		}

		$rate_limit = $this->enforce_rate_limit( TranslationProxyRateLimit::BUCKET_MUTATION );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$job_id = SiteRequests::sanitize_job_id( (string) $request->get_param( 'jobId' ) );
		if ( '' === $job_id ) {
			return new \WP_Error( 'invalid_parameter', 'jobId is invalid', [ 'status' => 400 ] );
		}

		$raw_items = $request->get_param( 'items' );
		$close_input_param = $request->get_param( 'closeInput' );
		if ( null !== $close_input_param && ! is_bool( $close_input_param ) ) {
			return new \WP_Error(
				'invalid_parameter',
				'closeInput must be a boolean',
				[ 'status' => 400 ]
			);
		}
		$close_input = (bool) $close_input_param;

		if ( ! is_array( $raw_items ) ) {
			return new \WP_Error( 'invalid_parameter', 'items must be an array', [ 'status' => 400 ] );
		}

		if ( ! $raw_items && ! $close_input ) {
			return new \WP_Error(
				'invalid_parameter',
				'At least one item or closeInput=true is required',
				[ 'status' => 400 ]
			);
		}

		$validated = $this->validate_batch_items( $raw_items, $job_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$items = $validated;

		$job_languages  = $this->job_languages( $job_id );
		$site_id        = Sites::registered_id();
		$target_lang    = is_array( $job_languages ) ? (string) ( $job_languages['targetLang'] ?? '' ) : '';
		$page_url       = $this->sanitize_page_url( (string) $request->get_param( 'url' ) );
		$claimed_hashes = [];

		foreach ( $items as $item ) {
			$claim = TranslationProxyDedupe::claim_segment(
				$site_id,
				$target_lang,
				(string) ( $item['cache']['hash'] ?? '' )
			);
			if ( is_wp_error( $claim ) ) {
				TranslationProxyDedupe::release_segments( $site_id, $target_lang, $claimed_hashes );
				return $claim;
			}

			$claimed_hashes[] = (string) $item['cache']['hash'];
		}

		$forward_items = [];
		foreach ( $items as $item ) {
			$forward_item = [
				'groupId'       => $item['groupId'],
				'markerPayload' => $item['markerPayload'],
			];

			if ( '' !== $item['contextKey'] ) {
				$forward_item['contextKey'] = $item['contextKey'];
			}

			$forward_item['contextKind'] = $item['contextKind'];
			if ( isset( $item['neighbours'] ) ) {
				$forward_item['neighbours'] = $item['neighbours'];
			}
			$forward_item['cache'] = $item['cache'];
			if ( '' !== $page_url ) {
				$forward_item['pageUrl'] = $page_url;
			}
			$forward_items[] = $forward_item;
		}

		if ( $close_input && ! $forward_items ) {
			$batch_id = $this->resolve_batch_id( $request );
			if ( is_wp_error( $batch_id ) ) {
				return $batch_id;
			}

			$body = $this->build_batch_request_body( $job_id, $batch_id, true, [] );

			$response = TranslationProRetry::post(
				'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/batches',
				$body,
				self::JOB_POST_TIMEOUT_SECONDS,
				$target_lang
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return [
				'ok'         => ! empty( $response['ok'] ),
				'jobId'      => $job_id,
				'batchId'    => sanitize_text_field( (string) ( $response['batchId'] ?? $body['batchId'] ) ),
				'accepted'   => 0,
				'closeInput' => true,
			];
		}

		if ( ! $forward_items ) {
			TranslationProxyDedupe::release_segments( $site_id, $target_lang, $claimed_hashes );
			return new \WP_Error(
				'invalid_parameter',
				'At least one valid item is required unless closeInput closes an empty batch',
				[ 'status' => 400 ]
			);
		}

		$batch_id = $this->resolve_batch_id( $request );
		if ( is_wp_error( $batch_id ) ) {
			TranslationProxyDedupe::release_segments( $site_id, $target_lang, $claimed_hashes );
			return $batch_id;
		}

		$body = $this->build_batch_request_body( $job_id, $batch_id, $close_input, $forward_items );

		$response = TranslationProRetry::post(
			'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/batches',
			$body,
			self::JOB_POST_TIMEOUT_SECONDS,
			$target_lang
		);
		if ( is_wp_error( $response ) ) {
			TranslationProxyDedupe::release_segments( $site_id, $target_lang, $claimed_hashes );
			return $response;
		}

		return [
			'ok'         => ! empty( $response['ok'] ),
			'jobId'      => $job_id,
			'batchId'    => sanitize_text_field( (string) ( $response['batchId'] ?? $batch_id ) ),
			'accepted'   => (int) ( $response['accepted'] ?? count( $forward_items ) ),
			'closeInput' => $close_input,
		];
	}

	public function cancel_job( $request ) {
		$body_limit = $this->enforce_body_size_limit( $request );
		if ( is_wp_error( $body_limit ) ) {
			return $body_limit;
		}

		$rate_limit = $this->enforce_rate_limit( TranslationProxyRateLimit::BUCKET_MUTATION );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$job_id = SiteRequests::sanitize_job_id( (string) $request->get_param( 'jobId' ) );
		if ( '' === $job_id ) {
			return new \WP_Error( 'invalid_parameter', 'jobId is invalid', [ 'status' => 400 ] );
		}

		$response = SiteRequests::post(
			'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/cancel',
			[],
			self::JOB_POST_TIMEOUT_SECONDS
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->forget_job_languages( $job_id );
		\TranslateWP\Components\QuotaHandoff::release_job( $job_id );

		return [ 'success' => true ];
	}

	public function job_status( $request ) {
		$rate_limit = $this->enforce_rate_limit( TranslationProxyRateLimit::BUCKET_STATUS );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$job_id = SiteRequests::sanitize_job_id( (string) $request->get_param( 'jobId' ) );
		if ( '' === $job_id ) {
			return new \WP_Error( 'invalid_parameter', 'jobId is invalid', [ 'status' => 400 ] );
		}

		$response = SiteRequests::get(
			'/v1/translation/jobs/' . rawurlencode( $job_id ) . '/status',
			[],
			self::JOB_POST_TIMEOUT_SECONDS
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$state = sanitize_key( (string) ( $response['state'] ?? '' ) );
		$allowed_states = [ 'open', 'processing', 'done', 'cancelled' ];
		if ( ! in_array( $state, $allowed_states, true ) ) {
			return new \WP_Error( 'remote_error', 'Job status unavailable', [ 'status' => 502 ] );
		}

		if ( in_array( $state, [ 'done', 'cancelled' ], true ) ) {
			$this->forget_job_languages( $job_id );
		}

		return [
			'jobId'     => $job_id,
			'state'     => $state,
			'createdAt' => sanitize_text_field( (string) ( $response['createdAt'] ?? '' ) ),
		];
	}

	private function enforce_rate_limit( string $bucket ) {
		$site_id = Sites::registered_id();
		if ( '' === $site_id ) {
			return new \WP_Error(
				'site_not_connected',
				'Site is not connected',
				[ 'status' => 503 ]
			);
		}

		return TranslationProxyRateLimit::check( $bucket, $site_id, TranslationProxyClientIp::resolve() );
	}

	private function enforce_body_size_limit( $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_REQUEST_BODY_BYTES ) {
			return new \WP_Error(
				'payload_too_large',
				'Request body exceeds the allowed size',
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	private function is_same_site_origin( string $origin ): bool {
		$home_url = home_url();
		$scheme   = (string) wp_parse_url( $home_url, PHP_URL_SCHEME );
		$host     = (string) wp_parse_url( $home_url, PHP_URL_HOST );
		$port     = wp_parse_url( $home_url, PHP_URL_PORT );

		if ( '' === $scheme || '' === $host ) {
			return false;
		}

		$expected = strtolower( $scheme . '://' . $host );
		if ( $port ) {
			$expected .= ':' . (int) $port;
		}

		return untrailingslashit( strtolower( $origin ) ) === untrailingslashit( $expected );
	}

	private function sanitize_language( string $value, string $fallback ): string {
		$lang = sanitize_text_field( trim( $value ) );
		if ( '' === $lang ) {
			return $fallback;
		}

		return substr( $lang, 0, self::MAX_LANG_LENGTH );
	}

	/**
	 * Whether the reported page URL points at this site's own host.
	 */
	private function is_site_page_url( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > self::MAX_PAGE_URL_LENGTH ) {
			return false;
		}

		$host      = strtolower( (string) wp_parse_url( esc_url_raw( $value ), PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' !== $host && $host === $site_host && ! TranslationUrl::is_file_path( (string) wp_parse_url( $value, PHP_URL_PATH ) );
	}

	/**
	 * The canonical form of the reported page URL, or an empty string when
	 * the URL is not https, not indexable or over the length cap; an item
	 * then carries no known page.
	 */
	private function sanitize_page_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > self::MAX_PAGE_URL_LENGTH ) {
			return '';
		}

		$value  = esc_url_raw( $value );
		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( 'https' !== $scheme || '' === $host || $host !== $site_host ) {
			return '';
		}

		if ( ! TranslationUrl::is_indexable_url( $value ) ) {
			return '';
		}

		return TranslationUrl::canonicalize( $value );
	}

	/**
	 * The visitor-supplied page title for the miss's context block. Sanitized
	 * and length-capped; it is metadata only and never enters the fingerprint.
	 */
	private function sanitize_page_title( string $value ): string {
		$value = sanitize_text_field( trim( $value ) );
		if ( '' === $value ) {
			return '';
		}

		return mb_substr( $value, 0, self::MAX_PAGE_TITLE_LENGTH );
	}

	private function sanitize_language_name( string $value ): string {
		$name = sanitize_text_field( trim( $value ) );
		if ( '' === $name ) {
			return '';
		}

		return substr( $name, 0, self::MAX_LANGUAGE_NAME_LENGTH );
	}

	/**
	 * Surrounding text that rides with a short segment as request metadata only.
	 * Each side is sanitized and capped; the result never enters the fingerprint.
	 * Returns null when neither side carries usable text.
	 *
	 * @param mixed $raw The submitted neighbours value.
	 *
	 * @return array{before:string,after:string}|null
	 */
	private function sanitize_neighbours( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$before = $this->sanitize_neighbour_side( $raw['before'] ?? '' );
		$after  = $this->sanitize_neighbour_side( $raw['after'] ?? '' );
		if ( '' === $before && '' === $after ) {
			return null;
		}

		return [
			'before' => $before,
			'after'  => $after,
		];
	}

	private function sanitize_neighbour_side( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( $value );

		return mb_substr( $value, 0, self::MAX_NEIGHBOUR_LENGTH );
	}

	private function resolve_batch_id( $request ) {
		$batch_id = sanitize_text_field( (string) $request->get_param( 'batchId' ) );
		if ( '' === $batch_id ) {
			return wp_generate_uuid4();
		}

		if ( strlen( $batch_id ) > self::MAX_BATCH_ID_LENGTH ) {
			return new \WP_Error( 'invalid_parameter', 'batchId is too long', [ 'status' => 400 ] );
		}

		return $batch_id;
	}

	private function reject_unknown_keys( array $payload, array $allowed_keys, string $label ) {
		$unknown = array_diff( array_keys( $payload ), $allowed_keys );
		if ( $unknown ) {
			return new \WP_Error(
				'invalid_parameter',
				$label . ' contains unknown fields',
				[ 'status' => 400 ]
			);
		}

		return null;
	}

	private function validate_batch_items( $raw_items, string $job_id ) {
		if ( ! is_array( $raw_items ) ) {
			return new \WP_Error( 'invalid_parameter', 'items must be an array', [ 'status' => 400 ] );
		}

		if ( count( $raw_items ) > self::MAX_BATCH_ITEMS ) {
			return new \WP_Error( 'invalid_parameter', 'Too many batch items', [ 'status' => 400 ] );
		}

		$job_languages = $this->job_languages( $job_id );
		if ( null === $job_languages ) {
			return new \WP_Error( 'invalid_parameter', 'Unknown translation job', [ 'status' => 400 ] );
		}

		if ( ! TranslationProxySettings::is_configured_language( $job_languages['targetLang'] ) ) {
			return new \WP_Error(
				'language_not_configured',
				'Language is not configured for this site',
				[ 'status' => 400 ]
			);
		}

		$site_id = Sites::registered_id();
		if ( '' === $site_id ) {
			return new \WP_Error(
				'not_connected',
				'Site is not connected',
				[ 'status' => 409 ]
			);
		}

		$queue       = new \TranslateWP\Components\IndexQueue();
		$ai_settings = TranslationProxySettings::ai_settings( (string) ( $job_languages['targetLang'] ?? '' ) );
		$normalized  = [];
		$seen_hashes = [];

		foreach ( $raw_items as $raw_item ) {
			if ( ! is_array( $raw_item ) ) {
				return new \WP_Error( 'invalid_parameter', 'Each batch item must be an object', [ 'status' => 400 ] );
			}

			$unknown_item_keys = $this->reject_unknown_keys(
				$raw_item,
				[ 'groupId', 'markerPayload', 'contextKey', 'contextKind', 'neighbours', 'cache' ],
				'Batch item'
			);
			if ( is_wp_error( $unknown_item_keys ) ) {
				return $unknown_item_keys;
			}

			if ( ! is_string( $raw_item['groupId'] ?? null ) ) {
				return new \WP_Error( 'invalid_parameter', 'groupId must be a string', [ 'status' => 400 ] );
			}

			if ( ! is_string( $raw_item['markerPayload'] ?? null ) ) {
				return new \WP_Error( 'invalid_parameter', 'markerPayload must be a string', [ 'status' => 400 ] );
			}

			if ( array_key_exists( 'contextKey', $raw_item ) && ! is_string( $raw_item['contextKey'] ) ) {
				return new \WP_Error( 'invalid_parameter', 'contextKey must be a string', [ 'status' => 400 ] );
			}

			if ( ! is_string( $raw_item['contextKind'] ?? null ) ) {
				return new \WP_Error( 'invalid_parameter', 'contextKind must be a string', [ 'status' => 400 ] );
			}

			$group_id = sanitize_text_field( (string) ( $raw_item['groupId'] ?? '' ) );
			$marker_payload = (string) ( $raw_item['markerPayload'] ?? '' );
			$context_key = sanitize_text_field( (string) ( $raw_item['contextKey'] ?? '' ) );
			$context_kind = sanitize_key( (string) ( $raw_item['contextKind'] ?? '' ) );
			$neighbours = $this->sanitize_neighbours( $raw_item['neighbours'] ?? null );

			if ( ! TranslationFingerprint::is_allowed_context_kind( $context_kind ) ) {
				return new \WP_Error( 'invalid_parameter', 'contextKind is invalid', [ 'status' => 400 ] );
			}

			if (
				'' === $group_id
				|| strlen( $group_id ) > self::MAX_GROUP_ID_LENGTH
				|| '' === $marker_payload
				|| strlen( $marker_payload ) > self::MAX_MARKER_PAYLOAD_LENGTH
			) {
				return new \WP_Error( 'invalid_parameter', 'Batch item failed validation', [ 'status' => 400 ] );
			}

			if ( strlen( $context_key ) > self::MAX_CONTEXT_KEY_LENGTH ) {
				return new \WP_Error( 'invalid_parameter', 'contextKey is too long', [ 'status' => 400 ] );
			}

			$marker_validation = TranslationMarkerPayload::validate( $marker_payload );
			if ( is_wp_error( $marker_validation ) ) {
				return $marker_validation;
			}

			$cache = $this->validate_cache_reference( $raw_item['cache'] ?? null, $site_id );
			if ( is_wp_error( $cache ) ) {
				return $cache;
			}

			if ( ! TranslationFingerprint::verify_hash(
				$cache['hash'],
				$marker_payload,
				$job_languages['sourceLang'],
				$job_languages['targetLang'],
				$context_kind,
				$ai_settings
			) ) {
				return new \WP_Error(
					'fingerprint_mismatch',
					'Batch item fingerprint does not match submitted payload',
					[ 'status' => 400 ]
				);
			}

			if ( isset( $seen_hashes[ $cache['hash'] ] ) ) {
				return new \WP_Error(
					'duplicate_segment',
					'Duplicate segment in batch payload',
					[ 'status' => 400 ]
				);
			}
			$seen_hashes[ $cache['hash'] ] = true;

			$normalized[] = array_merge(
				[
					'groupId'       => $group_id,
					'markerPayload' => $marker_payload,
					'contextKey'    => $context_key,
					'contextKind'   => $context_kind,
				],
				null === $neighbours ? [] : [ 'neighbours' => $neighbours ],
				[ 'cache' => $cache ]
			);
		}

		return $normalized;
	}

	private function validate_cache_reference( $raw_cache, string $site_id ) {
		if ( ! is_array( $raw_cache ) ) {
			return new \WP_Error( 'invalid_parameter', 'cache reference is required', [ 'status' => 400 ] );
		}

		$unknown_cache_keys = $this->reject_unknown_keys(
			$raw_cache,
			[ 'hash', 'siteId' ],
			'cache reference'
		);
		if ( is_wp_error( $unknown_cache_keys ) ) {
			return $unknown_cache_keys;
		}

		if ( ! is_string( $raw_cache['hash'] ?? null ) ) {
			return new \WP_Error( 'invalid_parameter', 'cache hash must be a string', [ 'status' => 400 ] );
		}

		if ( ! is_string( $raw_cache['siteId'] ?? null ) ) {
			return new \WP_Error( 'invalid_parameter', 'cache siteId must be a string', [ 'status' => 400 ] );
		}

		$hash = sanitize_text_field( (string) ( $raw_cache['hash'] ?? '' ) );
		if ( '' === $hash || strlen( $hash ) > self::MAX_HASH_LENGTH ) {
			return new \WP_Error( 'invalid_parameter', 'cache hash is invalid', [ 'status' => 400 ] );
		}

		$submitted_site_id = sanitize_text_field( (string) ( $raw_cache['siteId'] ?? '' ) );
		if ( '' === $site_id || $submitted_site_id !== $site_id ) {
			return new \WP_Error( 'invalid_parameter', 'cache siteId is invalid', [ 'status' => 400 ] );
		}

		return [
			'hash'   => $hash,
			'siteId' => $site_id,
		];
	}

	private function build_batch_request_body( string $job_id, string $batch_id, bool $close_input, array $items ): array {
		$job_languages = $this->job_languages( $job_id );
		$target_lang   = is_array( $job_languages ) ? (string) ( $job_languages['targetLang'] ?? '' ) : '';
		$body = [
			'batchId'    => $batch_id,
			'closeInput' => $close_input,
			'items'      => $items,
			'context'    => TranslationContext::build(),
			'aiSettings' => apply_filters( 'translatewp_translation_ai_settings', TranslationProxySettings::ai_settings( $target_lang ) ),
		];

		return apply_filters( 'translatewp_translation_batch_request_body', $body, $job_id, $target_lang );
	}

	private function remember_job_languages( string $job_id, string $source_lang, string $target_lang ) {
		return TranslationProxyStorage::remember_job_languages( $job_id, $source_lang, $target_lang );
	}

	private function forget_job_languages( string $job_id ): void {
		TranslationProxyStorage::forget_job_languages( $job_id );
	}

	private function job_languages( string $job_id ): ?array {
		return TranslationProxyStorage::job_languages( $job_id );
	}
}
