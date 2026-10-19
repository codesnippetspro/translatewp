<?php

namespace TranslateWP\Functionality\Endpoints;

use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\Options;
use TranslateWP\Components\SiteRequests;
use TranslateWP\Entities\CPTs\Lexicon;

/**
 * Lexicon candidate discovery: the site asks the service to scan
 * its stored translations, reads the proposals and acts on them. The service
 * decides on Pro; a refusal comes back as it is.
 */
class LexiconCandidates extends BaseApiEndpoints {

	public function __construct( $plugin_name, $plugin_version ) {
		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/admin' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

		$scan_start = new PostEndpoint( $this->namespace, 'lexicon-candidates/scan', [ $this, 'start_scan' ], [ $this, 'lexicon_write_permissions_check' ] );
		$scan_start->useMiddleware( $cors );

		$scan_latest = new GetEndpoint( $this->namespace, 'lexicon-candidates/scan', [ $this, 'latest_scan' ], [ $this, 'lexicon_permissions_check' ] );
		$scan_latest->useMiddleware( $cors );

		$list = new GetEndpoint( $this->namespace, 'lexicon-candidates', [ $this, 'list_candidates' ], [ $this, 'lexicon_permissions_check' ] );
		$list->useMiddleware( $cors );

		$action = new PostEndpoint( $this->namespace, 'lexicon-candidates/action', [ $this, 'act_on_candidate' ], [ $this, 'lexicon_write_permissions_check' ] );
		$action->useMiddleware( $cors );

		return [ $scan_start, $scan_latest, $list, $action ];
	}

	/**
	 * What the scan works with: the site's source locale, its target
	 * locales, the owner's context text and the terms already in the lexicon.
	 */
	public static function scan_request(): array {
		$languages = Options::get( 'languages.list', [] );
		$source    = '';
		$targets   = [];
		foreach ( is_array( $languages ) ? $languages : [] as $key => $language ) {
			if ( ! is_array( $language ) ) {
				continue;
			}
			$locale = (string) ( $language['locale'] ?? ( is_string( $key ) ? $key : '' ) );
			if ( '' === $locale ) {
				continue;
			}
			if ( ! empty( $language['default'] ) ) {
				$source = $locale;
				continue;
			}
			if ( ! isset( $language['active'] ) || $language['active'] ) {
				$targets[] = $locale;
			}
		}

		$context      = Options::get( 'translations.context', [] );
		$context_text = is_array( $context ) ? (string) ( $context['text_context'] ?? '' ) : '';

		$terms = [];
		foreach ( get_posts(
            [
				'post_type' => Lexicon::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 2000,
				'fields' => 'ids',
			]
        ) as $post_id ) {
			$title = \TranslateWP\Components\PlainText::title( (int) $post_id );
			if ( '' !== trim( (string) $title ) ) {
				$terms[] = (string) $title;
			}
		}

		return [
			'sourceLocale'  => $source,
			'targetLocales' => array_values( array_unique( $targets ) ),
			'context'       => $context_text,
			'lexiconTerms'  => $terms,
		];
	}

	public function start_scan( $request ) {
		unset( $request );
		$body = self::scan_request();
		if ( '' === $body['sourceLocale'] || empty( $body['targetLocales'] ) ) {
			return new \WP_Error( 'languages_missing', __( 'Choose a default language and at least one translation language first.', 'translate' ), [ 'status' => 409 ] );
		}
		return SiteRequests::post( '/v1/site/lexicon/scans', $body, 20 );
	}

	public function latest_scan( $request ) {
		unset( $request );
		return SiteRequests::get( '/v1/site/lexicon/scans/latest' );
	}

	public function list_candidates( $request ) {
		$query  = [];
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( in_array( $status, [ 'new', 'added', 'skipped', 'never' ], true ) ) {
			$query['status'] = $status;
		}
		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit > 0 ) {
			$query['limit'] = min( $limit, 500 );
		}
		return SiteRequests::get( '/v1/site/lexicon/candidates', $query );
	}

	public function act_on_candidate( $request ) {
		$id     = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $id ) || ! in_array( $action, [ 'add', 'skip', 'never', 'restore' ], true ) ) {
			return new \WP_Error( 'validation_error', __( 'The candidate or the action is not valid.', 'translate' ), [ 'status' => 400 ] );
		}
		$body     = [ 'action' => $action ];
		$language = sanitize_text_field( (string) $request->get_param( 'language' ) );
		if ( '' !== $language ) {
			$body['language'] = $language;
		}
		return SiteRequests::post( '/v1/site/lexicon/candidates/' . rawurlencode( $id ) . '/action', $body );
	}
}
