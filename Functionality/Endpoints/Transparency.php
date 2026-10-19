<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\ActionLog;
use TranslateWP\Components\DebugInfo;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\SiteRequests;

class Transparency extends BaseApiEndpoints {

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/admin' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

		$job_events = new GetEndpoint(
			$this->namespace,
			'job-events',
			[ $this, 'get_job_events' ],
			[ $this, 'admin_permissions_check' ]
		);
		$job_events->useMiddleware( $cors );

		$feedback = new PostEndpoint(
			$this->namespace,
			'feedback',
			[ $this, 'submit_feedback' ],
			[ $this, 'admin_write_permissions_check' ]
		);
		$feedback->useMiddleware( $cors );

		$debug = new GetEndpoint(
			$this->namespace,
			'debug-info',
			[ $this, 'get_debug_info' ],
			[ $this, 'admin_permissions_check' ]
		);
		$debug->useMiddleware( $cors );

		$ticket = new PostEndpoint(
			$this->namespace,
			'support-ticket',
			[ $this, 'open_support_ticket' ],
			[ $this, 'admin_write_permissions_check' ]
		);
		$ticket->useMiddleware( $cors );

		return [
			$job_events,
			$feedback,
			$debug,
			$ticket,
		];
	}

	public function get_debug_info( $request ) {
		unset( $request );

		return [ 'text' => DebugInfo::text() ];
	}

	public const SUPPORT_ADDRESS = 'team@translatewp.ai';

	public function open_support_ticket( $request ) {
		if ( ! ProEligibility::unlocks_pro_features() ) {
			return new \WP_Error( 'pro_required', 'Support tickets are part of the Orbital plan', [ 'status' => 403 ] );
		}

		$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( '' === $name || '' === $message ) {
			return new \WP_Error( 'missing_parameter', 'Name and message are required', [ 'status' => 400 ] );
		}
		if ( strlen( $message ) > 10000 ) {
			return new \WP_Error( 'invalid_parameter', 'Message is too long', [ 'status' => 400 ] );
		}

		// The user may give another address to be answered at; the account's otherwise.
		$given = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' !== trim( (string) $request->get_param( 'email' ) ) && ! is_email( $given ) ) {
			return new \WP_Error( 'invalid_reply_address', 'The reply address is not an email address', [ 'status' => 400 ] );
		}
		$reply_to = '' !== $given ? $given : (string) wp_get_current_user()->user_email;

		$subject = sprintf( '[TranslateWP] Support request from %s', wp_parse_url( home_url(), PHP_URL_HOST ) );
		$body    = sprintf( "From: %s\nReply to: %s\n\n%s\n\n\n%s", $name, $reply_to, $message, DebugInfo::text() );
		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $name . ' <' . $reply_to . '>';
		}

		$sent = (bool) wp_mail( self::SUPPORT_ADDRESS, $subject, $body, $headers );
		ActionLog::record( $sent ? 'Support ticket sent by email' : 'Support ticket could not be sent by email; mail client fallback offered', $sent ? 'info' : 'warning' );

		return [
			'sent' => $sent,
			'address' => self::SUPPORT_ADDRESS,
			'subject' => $subject,
		];
	}

	public function get_job_events( $request ) {
		$query = [
			'limit' => SiteRequests::sanitize_job_events_limit( $request->get_param( 'limit' ) ),
		];

		$before = SiteRequests::sanitize_job_events_before( $request->get_param( 'before' ) );
		if ( '' !== $before ) {
			$query['before'] = $before;
		}

		$response = SiteRequests::get( '/v1/site/job-events', $query );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$events = [];
		if ( isset( $response['events'] ) && is_array( $response['events'] ) ) {
			foreach ( $response['events'] as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}

				$normalized = $this->normalize_job_event( $event );
				if ( null === $normalized ) {
					continue;
				}

				$events[] = $normalized;
			}
		}

		return [
			'events' => $events,
		];
	}

	private function normalize_job_event( array $event ): ?array {
		$id = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
		if ( '' === $id ) {
			return null;
		}

		$allowed_kinds = [
			'quota_block',
			'provider_error',
			'timeout',
			'cancelled',
			'state_persistence_error',
		];
		$raw_kind = sanitize_key( (string) ( $event['kind'] ?? '' ) );
		$kind     = in_array( $raw_kind, $allowed_kinds, true ) ? $raw_kind : 'unknown';

		$occurred_at = sanitize_text_field( (string) ( $event['occurredAt'] ?? '' ) );
		if ( '' === $occurred_at || false === strtotime( $occurred_at ) ) {
			return null;
		}

		// The service's own detail stays with the service: the admin sees the
		// fixed reason, the page, the language and whether it recovered.
		return [
			'id'         => $id,
			'siteId'     => sanitize_text_field( (string) ( $event['siteId'] ?? '' ) ),
			'accountId'  => sanitize_text_field( (string) ( $event['accountId'] ?? '' ) ),
			'kind'       => $kind,
			'occurredAt' => gmdate( 'c', (int) strtotime( $occurred_at ) ),
			'reasonCode' => $this->optional_string( $event['reasonCode'] ?? null, 'sanitize_key' ),
			'message'    => $this->optional_string( $event['message'] ?? null, 'sanitize_text_field' ),
			'pageUrl'    => $this->optional_string( $event['pageUrl'] ?? null, 'esc_url_raw' ),
			'targetLang' => $this->optional_string( $event['targetLang'] ?? null, 'sanitize_text_field' ),
			'resolved'   => true === ( $event['resolved'] ?? null ),
		];
	}

	private function optional_string( $value, callable $sanitize ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$clean = (string) $sanitize( $value );

		return '' === $clean ? null : $clean;
	}

	public function submit_feedback( $request ) {
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return new \WP_Error(
				'missing_parameter',
				'Message is required',
				[ 'status' => 400 ]
			);
		}

		if ( strlen( $message ) > 5000 ) {
			return new \WP_Error(
				'invalid_parameter',
				'Message is too long',
				[ 'status' => 400 ]
			);
		}

		$page = sanitize_text_field( (string) $request->get_param( 'page' ) );
		$context = [
			'pluginVersion' => defined( 'TRANSLATEWP_VERSION' ) ? (string) TRANSLATEWP_VERSION : '',
		];

		if ( '' !== $page ) {
			$context['page'] = substr( $page, 0, 500 );
		}

		$response = SiteRequests::post(
			'/v1/feedback',
			[
				'message' => $message,
				'context' => $context,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'id'      => sanitize_text_field( (string) ( $response['id'] ?? '' ) ),
			'success' => true,
		];
	}
}
