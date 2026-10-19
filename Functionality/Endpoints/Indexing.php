<?php

namespace TranslateWP\Functionality\Endpoints;

use TranslateWP\Components\PlainText;
use PluboRoutes\Endpoint\GetEndpoint;
use PluboRoutes\Endpoint\PostEndpoint;
use PluboRoutes\Middleware\Cors;
use TranslateWP\Components\IndexQueue;
use TranslateWP\Components\LoopbackKick;
use TranslateWP\Components\Sites;
use TranslateWP\Components\TranslationProxyDedupe;
use TranslateWP\Components\TranslationProxySettings;
use TranslateWP\Components\TranslationReadiness;
use TranslateWP\Components\TranslationStore;
use TranslateWP\Components\TranslationUrl;
use TranslateWP\Functionality\Indexer;
use TranslateWP\Functionality\ReadinessReconcile;

class Indexing extends BaseApiEndpoints {

	private const MAX_CLAIM_LIMIT = 10;
	private const MAX_ITEMS_PER_SUBMISSION = 200;
	private const RETRANSLATE_ALL_CONFIRM = 'RETRANSLATE';

	private Indexer $indexer;
	private ReadinessReconcile $readiness_reconcile;

	public function __construct( $plugin_name, $plugin_version, ?Indexer $indexer = null, ?ReadinessReconcile $readiness_reconcile = null ) {
		$this->indexer = $indexer ?? new Indexer();
		$this->readiness_reconcile = $readiness_reconcile ?? new ReadinessReconcile( $plugin_name, $plugin_version, $this->indexer, null, null, false );

		parent::__construct( $plugin_name, $plugin_version, 'translatewp/v1/indexing' );
	}

	public function define_endpoints() {
		$cors = new Cors( '*', [ 'GET', 'POST', 'OPTIONS' ], [ 'Content-Type', 'Authorization', 'X-Requested-With' ] );

		$endpoints = [
			new PostEndpoint( $this->namespace, 'enqueue', [ $this, 'enqueue' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'read-site', [ $this, 'read_site' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'reread-changed', [ $this, 'reread_changed' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'start-translation', [ $this, 'start_translation' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'drain-now', [ $this, 'drain_now' ], [ $this, 'drain_now_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'claim-extract', [ $this, 'claim_extract' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'submit-extract', [ $this, 'submit_extract' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'extract-failed', [ $this, 'extract_failed' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'reextract-page', [ $this, 'reextract_page' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'sync-now', [ $this, 'sync_now' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'retry-failed', [ $this, 'retry_failed' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'clear-failed', [ $this, 'clear_failed' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'pins', [ $this, 'set_pins' ], [ $this, 'admin_write_permissions_check' ] ),
			new GetEndpoint( $this->namespace, 'status', [ $this, 'status' ], [ $this, 'admin_permissions_check' ] ),
			new GetEndpoint( $this->namespace, 'pages', [ $this, 'pages' ], [ $this, 'admin_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'readiness', [ $this, 'readiness' ], [ $this, 'admin_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'retranslate-page', [ $this, 'retranslate_page' ], [ $this, 'retranslate_write_permissions_check' ] ),
			new PostEndpoint( $this->namespace, 'retranslate-all', [ $this, 'retranslate_all' ], [ $this, 'retranslate_write_permissions_check' ] ),
		];

		foreach ( $endpoints as $endpoint ) {
			$endpoint->useMiddleware( $cors );
		}

		return $endpoints;
	}

	/**
	 * Queue the spine for reading in the admin tab. No language is
	 * needed and nothing is sent to the service.
	 */
	public function read_site( $request ) {
		unset( $request );

		return [
			'queued' => $this->indexer->enqueue_read_rows(),
			'status' => $this->indexer->status(),
		];
	}

	/**
	 * Queue only the pages saved since they were read for a fresh reading.
	 * The admin tab extracts them and their new reading is translated.
	 */
	public function reread_changed( $request ) {
		unset( $request );

		return [
			'requeued' => $this->indexer->reread_changed(),
			'status'   => $this->indexer->status(),
		];
	}

	/**
	 * Fan the read pages out into the target languages and start
	 * translating. `languages` limits the fan-out; `force` translates every
	 * read page again (a fresh read of the site).
	 */
	public function start_translation( $request ) {
		$raw_languages = $request->get_param( 'languages' );
		$languages     = null;
		if ( null !== $raw_languages ) {
			if ( ! is_array( $raw_languages ) ) {
				return new \WP_Error( 'invalid_parameter', 'languages must be an array', [ 'status' => 400 ] );
			}
			$languages = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $raw_languages ) ) ) );
		}

		return [
			'queued' => $this->indexer->start_translation( $languages, rest_sanitize_boolean( $request->get_param( 'force' ) ) ),
			'status' => $this->indexer->status(),
		];
	}

	/** The site's own loopback kick; signature and freshness are the permission. */
	public function drain_now_permissions_check( $request ) {
		return LoopbackKick::verify( (string) $request->get_param( 'ts' ), (string) $request->get_param( 'sig' ) );
	}

	public function drain_now( $request ) {
		unset( $request );

		return $this->indexer->drain_now();
	}

	public function enqueue( $request ) {
		$raw_languages = $request->get_param( 'languages' );
		$languages     = null;

		if ( null !== $raw_languages ) {
			if ( ! is_array( $raw_languages ) ) {
				return new \WP_Error( 'invalid_parameter', 'languages must be an array', [ 'status' => 400 ] );
			}

			$languages = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $raw_languages ) ) ) );
		}

		$enqueued = $this->indexer->enqueue_for_languages( $languages );

		return [
			'enqueued' => $enqueued,
			'status'   => $this->indexer->status(),
		];
	}

	public function claim_extract( $request ) {
		$limit = (int) ( $request->get_param( 'limit' ) ?? self::MAX_CLAIM_LIMIT );
		$limit = max( 1, min( self::MAX_CLAIM_LIMIT, $limit ) );

		$rows = $this->indexer->claim_extract_batch( $limit );

		return [
			'rows' => array_map(
				static function ( array $row ): array {
					return [
						'id'         => $row['id'],
						'url'        => $row['url'],
						'language'   => $row['language'],
						'objectType' => $row['object_type'],
						'objectId'   => $row['object_id'],
					];
				},
				$rows
			),
		];
	}

	public function submit_extract( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new \WP_Error( 'invalid_parameter', 'id is required', [ 'status' => 400 ] );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new \WP_Error( 'invalid_parameter', 'items must be an array', [ 'status' => 400 ] );
		}

		if ( count( $items ) > self::MAX_ITEMS_PER_SUBMISSION ) {
			return new \WP_Error( 'invalid_parameter', 'Too many items in one submission', [ 'status' => 400 ] );
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item )
				|| '' === trim( (string) ( $item['groupId'] ?? '' ) )
				|| '' === trim( (string) ( $item['markerPayload'] ?? '' ) ) ) {
				return new \WP_Error( 'invalid_parameter', 'Each item needs groupId and markerPayload', [ 'status' => 400 ] );
			}
		}

		// A batch flagged `more` is buffered on the row; the row advances to the
		// push stage only on the final batch, so a page with more items than this
		// single-request cap is sent in several batches instead of failing.
		$is_final = ! rest_sanitize_boolean( $request->get_param( 'more' ) );

		if ( ! $this->indexer->submit_extraction( $id, $items, $is_final ) ) {
			return new \WP_Error( 'conflict', 'Row is not claimed for extraction', [ 'status' => 409 ] );
		}

		return [ 'stored' => true ];
	}

	/**
	 * Revive one dead page and claim it for a fresh extraction in the admin tab,
	 * returning the claimed row so the tab can extract and resubmit just that
	 * page. A row that is not a failed page cannot be reclaimed this way.
	 */
	public function reextract_page( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new \WP_Error( 'invalid_parameter', 'id is required', [ 'status' => 400 ] );
		}

		$row = $this->indexer->reextract_row( $id );
		if ( null === $row ) {
			return new \WP_Error( 'conflict', 'Row is not a failed page', [ 'status' => 409 ] );
		}

		return [
			'row' => [
				'id'         => (int) $row['id'],
				'url'        => (string) $row['url'],
				'language'   => (string) $row['language'],
				'objectType' => (string) $row['object_type'],
				'objectId'   => (int) $row['object_id'],
			],
		];
	}

	public function extract_failed( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new \WP_Error( 'invalid_parameter', 'id is required', [ 'status' => 400 ] );
		}

		$reason = sanitize_text_field( (string) ( $request->get_param( 'reason' ) ?? 'extract_failed' ) );

		if ( ! $this->indexer->mark_extract_failed( $id, '' !== $reason ? $reason : 'extract_failed' ) ) {
			return new \WP_Error( 'conflict', 'Row is not claimed for extraction', [ 'status' => 409 ] );
		}

		return [ 'recorded' => true ];
	}

	public function sync_now( $request ) {
		unset( $request );

		$drained = $this->indexer->drain_tick();

		return [
			'drained' => $drained,
			'status'  => $this->indexer->status(),
		];
	}

	public function retry_failed( $request ) {
		$language = $this->sanitize_language_param( $request );
		$retried  = $this->indexer->retry_failed( $language );

		return [
			'retried' => $retried,
			'status'  => $this->indexer->status(),
		];
	}

	public function clear_failed( $request ) {
		$language = $this->sanitize_language_param( $request );
		$cleared  = $this->indexer->clear_failed( $language );

		return [
			'cleared' => $cleared,
			'status'  => $this->indexer->status(),
		];
	}

	private function sanitize_language_param( $request ): ?string {
		$raw = $request->get_param( 'language' );
		if ( null === $raw ) {
			return null;
		}

		$language = sanitize_text_field( (string) $raw );

		return '' !== $language ? $language : null;
	}

	public function set_pins( $request ) {
		$raw_ids = $request->get_param( 'objectIds' );
		if ( ! is_array( $raw_ids ) ) {
			return new \WP_Error( 'invalid_parameter', 'objectIds must be an array', [ 'status' => 400 ] );
		}

		if ( count( $raw_ids ) > 100 ) {
			return new \WP_Error( 'invalid_parameter', 'Too many pinned objects', [ 'status' => 400 ] );
		}

		$this->indexer->set_priority_pins( array_map( 'intval', $raw_ids ) );

		return [ 'pins' => $this->pin_details() ];
	}

	public function status( $request ) {
		unset( $request );

		LoopbackKick::fire();

		return $this->indexer->status() + [ 'pins' => $this->pin_details() ];
	}

	public function readiness( $request ) {
		$raw_cursor = $request->get_param( 'cursor' );
		if ( null !== $raw_cursor && ( ! is_numeric( $raw_cursor ) || 0 > (int) $raw_cursor || (float) (int) $raw_cursor !== (float) $raw_cursor ) ) {
			return new \WP_Error( 'invalid_parameter', 'cursor must be a non-negative integer', [ 'status' => 400 ] );
		}
		$result = $this->readiness_reconcile->reconcile( absint( $raw_cursor ) );
		$result['subscription'] = \TranslateWP\Components\TranslationSubscription::mint( $result['language'], $result['hashes'] );
		$result['pages'] = $this->pages( $request );
		$result['status'] = $this->indexer->status() + [ 'pins' => $this->pin_details() ];
		$result['translations'] = \TranslateWP\Components\DashboardTranslations::summary( new IndexQueue(), (array) \TranslateWP\Components\Options::get( 'languages.list', [] ), time() );
		if ( array_filter(
            $result['status']['languages'],
            static function ( array $count ): bool {
				return $count['pending_push'] > 0; }
        ) ) {
			LoopbackKick::fire();
		}
		return $result;
	}

	/**
	 * The indexed pages list for the admin UI: one entry per canonical page URL
	 * (TranslationUrl::canonicalize: scheme, trailing slash and the `lang`
	 * param ignored), each carrying a per-language map of status, stored
	 * segment count, failure reason and last-updated time. Stale duplicate rows
	 * for the same page and language are collapsed on read so the newest row
	 * wins. The totals drive the site-wide "Retranslate everything" cost guard.
	 */
	public function pages( $request ) {
		unset( $request );

		$this->indexer->collapse_stale_duplicates();

		$pages          = [];
		$order          = [];
		$total_segments = 0;
		$total_rows     = 0;

		foreach ( $this->indexer->page_rows( true ) as $row ) {
			$id            = (int) $row['id'];
			$object_type   = (string) $row['object_type'];
			$object_id     = (int) $row['object_id'];
			$raw_url       = (string) $row['url'];
			$canonical_url = TranslationUrl::canonicalize( $raw_url );
			$url           = '' !== $canonical_url ? $canonical_url : $raw_url;
			$language      = (string) $row['language'];
			$post_id       = 'miss' === $object_type ? 0 : $object_id;
			$segments      = TranslationStore::segment_count( $url, $language, $post_id );

			if ( '' !== $language ) {
				$total_segments += $segments;
				++$total_rows;
			}

			// A visitor miss row carries the visitor's document title, which may
			// already be translated, so only an indexed row names the page.
			$row_title = 'miss' === $object_type ? '' : PlainText::decode( (string) $row['page_title'] );

			if ( ! isset( $pages[ $url ] ) ) {
				$order[]        = $url;
				$pages[ $url ] = [
					'objectType' => $object_type,
					'objectId'   => $object_id,
					'url'        => $url,
					'title'      => $row_title,
					'indexed'    => 'miss' !== $object_type,
					'languages'  => [],
					'newest_id'  => $id,
				];
			} elseif ( $id > $pages[ $url ]['newest_id'] ) {
				// The newest row owns the page's object identity.
				$pages[ $url ]['objectType'] = $object_type;
				$pages[ $url ]['objectId']   = $object_id;
				$pages[ $url ]['newest_id']  = $id;
				if ( '' !== $row_title ) {
					$pages[ $url ]['title'] = $row_title;
				}
			} elseif ( '' === $pages[ $url ]['title'] && '' !== $row_title ) {
				$pages[ $url ]['title'] = $row_title;
			}

			// A page is indexed when indexing covers it; one only a visitor opened
			// has miss rows alone.
			if ( 'miss' !== $object_type ) {
				$pages[ $url ]['indexed'] = true;
			}

			if ( '' === $language ) {
				continue;
			}
			$existing = $pages[ $url ]['languages'][ $language ] ?? null;
			if ( null === $existing || $id > $existing['id'] ) {
				$queue_status = (string) $row['status'];
				if ( ! in_array( $queue_status, [ IndexQueue::STATUS_PENDING, IndexQueue::STATUS_CLAIMED, IndexQueue::STATUS_DONE, IndexQueue::STATUS_DEAD ], true ) ) {
					$queue_status = IndexQueue::STATUS_PENDING;
				}

				$pages[ $url ]['languages'][ $language ] = [
					'id'          => $id,
					'status'      => $this->page_status( $row ),
					'queueStatus' => $queue_status,
					'segments'    => $segments,
					'reason'      => (string) $row['reason'],
					'updated'     => (int) $row['updated'],
					'translatingSince' => $this->translating_since( $row ),
				];
			}
		}

		$list = [];
		foreach ( $order as $url ) {
			$page = $pages[ $url ];
			unset( $page['newest_id'] );

			// The page's own title is a server-only segment the visitor path never
			// translates, so surface its per-locale store state next to each
			// language: a body can read as indexed while the title is still
			// pending (or never caught up). A miss-owned entry carries no real
			// post id or title, so resolve the page from its URL to read both.
			$post_id = 'miss' === $page['objectType'] ? 0 : (int) $page['objectId'];
			if ( $post_id <= 0 ) {
				$post_id = TranslationStore::resolve_post_id( $url );
			}
			$title = '' !== $page['title'] ? $page['title'] : ( $post_id > 0 ? PlainText::title( $post_id ) : '' );
			foreach ( array_keys( $page['languages'] ) as $language ) {
				$page['languages'][ $language ]['title'] = TranslationStore::title_state( $url, $title, (string) $language, $post_id );
			}

			$list[] = $page;
		}

		return [
			'pages'  => $list,
			'totals' => [
				'rows'     => $total_rows,
				'segments' => $total_segments,
			],
		];
	}

	/**
	 * Re-queue one page for a forced retranslation, optionally scoped to a
	 * single language, and refresh its stored translations so the back-fill
	 * repopulates them from the freshly overwritten API cache.
	 */
	public function retranslate_page( $request ) {
		$object_type = sanitize_key( (string) $request->get_param( 'objectType' ) );
		$object_id   = (int) $request->get_param( 'objectId' );
		$url         = TranslationUrl::canonicalize( esc_url_raw( (string) $request->get_param( 'url' ) ) );

		if ( '' === $object_type || $object_id <= 0 || '' === $url ) {
			return new \WP_Error( 'invalid_parameter', 'objectType, objectId and url are required', [ 'status' => 400 ] );
		}

		// Users who are not administrators retranslate too, so the target must
		// be a page the site already indexes, in an active translation language.
		if ( ! $this->is_indexed_page( $object_type, $object_id, $url ) ) {
			return new \WP_Error( 'unknown_page', 'This page is not indexed', [ 'status' => 404 ] );
		}

		$languages = $this->resolve_retranslate_languages( $request );
		if ( ! $languages ) {
			return new \WP_Error( 'invalid_parameter', 'No target language to retranslate', [ 'status' => 400 ] );
		}

		$page_title = 'miss' === $object_type ? '' : PlainText::title( (int) $object_id );
		$post_id    = 'miss' === $object_type ? 0 : $object_id;

		$rows = [];
		foreach ( $languages as $language ) {
			$rows[] = [
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'url'         => $url,
				'page_title'  => $page_title,
				'language'    => $language,
				'priority'    => Indexer::PRIORITY_DEFAULT,
			];
		}

		$requeued = $this->indexer->retranslate( $rows );

		foreach ( $languages as $language ) {
			$this->purge_page_translations( $url, $post_id, $language );
		}

		TranslationStore::bump_cache_generation( $languages );

		return [
			'requeued' => $requeued,
			'status'   => $this->indexer->status(),
		];
	}

	/**
	 * Re-queue every indexed row for a forced retranslation. Gated behind the
	 * typed confirmation phrase the Danger zone requires, re-verified here.
	 */
	public function retranslate_all( $request ) {
		$confirm = sanitize_text_field( (string) $request->get_param( 'confirm' ) );
		if ( self::RETRANSLATE_ALL_CONFIRM !== $confirm ) {
			return new \WP_Error( 'invalid_confirmation', 'Confirmation phrase does not match', [ 'status' => 400 ] );
		}

		$result = $this->indexer->retranslate_everything();

		return [
			'requeued' => $result['requeued'],
			'status'   => $this->indexer->status(),
		];
	}

	/**
	 * The requested retranslation languages: the one language when the caller
	 * scopes the action to it, else every active target locale. The source
	 * (default) locale is never a target.
	 */
	/**
	 * Whether the site indexes this page: a queue or read row with the same
	 * object and address.
	 *
	 * @param string $object_type The row's object type.
	 * @param int    $object_id   The row's object id.
	 * @param string $url         The canonical address.
	 */
	private function is_indexed_page( string $object_type, int $object_id, string $url ): bool {
		foreach ( $this->indexer->page_rows( true ) as $row ) {
			if ( (string) ( $row['object_type'] ?? '' ) === $object_type
				&& (int) ( $row['object_id'] ?? 0 ) === $object_id
				&& TranslationUrl::canonicalize( (string) ( $row['url'] ?? '' ) ) === $url ) {
				return true;
			}
		}

		return false;
	}

	private function resolve_retranslate_languages( $request ): array {
		$language = sanitize_text_field( (string) ( $request->get_param( 'language' ) ?? '' ) );
		$active   = TranslationProxySettings::active_lookup_languages();

		// One language only if it is one of the site's active languages.
		$languages = '' !== $language
			? ( in_array( $language, $active, true ) ? [ $language ] : [] )
			: $active;

		$languages = array_values( array_filter( array_map( 'trim', $languages ) ) );

		$default = TranslationProxySettings::default_locale();
		if ( '' !== $default && 'auto' !== $default ) {
			$languages = array_values(
				array_filter(
					$languages,
					static function ( string $candidate ) use ( $default ): bool {
						return $candidate !== $default;
					}
				)
			);
		}

		return $languages;
	}

	/**
	 * Clear one page+locale from the server-side translation store and release
	 * the proxy dedupe claims for the segment hashes it held, so neither a
	 * stored copy nor an in-flight claim can mask the refreshed translation.
	 */
	private function purge_page_translations( string $url, int $post_id, string $locale ): void {
		$hashes  = TranslationStore::clear_entry( $url, $post_id, $locale );
		$site_id = Sites::registered_id();

		if ( '' !== $site_id && $hashes ) {
			TranslationProxyDedupe::release_segments( $site_id, $locale, $hashes );
		}
	}

	/**
	 * When a language still on its way started this round, while a worker has
	 * it or once sent and awaiting its translation; 0 while queued or failed.
	 * A row from before the start time was kept falls back to its last claim
	 * or hand-over.
	 *
	 * @param array $row The queue row.
	 */
	private function translating_since( array $row ): int {
		$status  = (string) $row['status'];
		$started = (int) ( $row['started_at'] ?? 0 );
		if ( IndexQueue::STATUS_CLAIMED === $status ) {
			return $started > 0 ? $started : (int) ( $row['claimed_at'] ?? 0 );
		}

		if ( IndexQueue::STATUS_DONE === $status && empty( $row['translated'] ) ) {
			return $started > 0 ? $started : (int) $row['updated'];
		}

		return 0;
	}

	private function page_status( array $row ): string {
		$status = (string) $row['status'];
		if ( IndexQueue::STATUS_DONE === $status ) {
			return ! empty( $row['translated'] ) ? 'indexed' : 'pending';
		}

		if ( IndexQueue::STATUS_DEAD === $status ) {
			return 'failed';
		}

		if ( ! empty( $row['retranslate'] ) ) {
			return 'retranslating';
		}

		return 'pending';
	}

	private function pin_details(): array {
		return array_map(
			static function ( int $post_id ): array {
				return [
					'id'    => $post_id,
					'title' => PlainText::title( (int) $post_id ),
					'type'  => (string) get_post_type( $post_id ),
				];
			},
			$this->indexer->priority_pins()
		);
	}
}
