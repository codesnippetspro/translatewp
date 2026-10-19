<?php

namespace TranslateWP\Components;

/**
 * CPT-backed store for the warm-up index queue (spec C3 pinned shape: queue
 * table or CPT rows; this is the CPT variant, core APIs only, except the
 * batched bulk delete in delete_all(), see CleanupInventory).
 * Rows are unique per (object_type, object_id, language) and move
 * extract → push → done, with a retry cap that dead-letters instead of
 * looping forever. Claim transitions are read-modify-write through the
 * posts API; the rare double-claim is harmless because the proxy dedupe
 * and the remote API both dedupe pushed segments.
 */
class IndexQueue {

	public const POST_TYPE = 'twp_index_item';

	public const STAGE_EXTRACT = 'extract';
	public const STAGE_PUSH    = 'push';
	// A page read from the site and held until languages are chosen.
	public const STAGE_READ    = 'read';
	public const READ_LANGUAGE = '';

	public const STATUS_PENDING = 'pending';
	public const STATUS_CLAIMED = 'claimed';
	public const STATUS_DONE    = 'done';
	public const STATUS_DEAD    = 'dead';

	public const MAX_ATTEMPTS = 3;
	public const STALE_CLAIM_SECONDS = 300;

	private const META_OBJECT_TYPE = '_twp_object_type';
	private const META_OBJECT_ID   = '_twp_object_id';
	private const META_URL         = '_twp_url';
	private const META_PAGE_TITLE  = '_twp_page_title';

	/** The stored title as plain text, whatever a past version wrote. */
	private static function stored_title( int $post_id ): string {
		return PlainText::decode( (string) get_post_meta( $post_id, self::META_PAGE_TITLE, true ) );
	}
	private const META_LANGUAGE    = '_twp_language';
	private const META_STAGE       = '_twp_stage';
	private const META_STATUS      = '_twp_status';
	private const META_PRIORITY    = '_twp_priority';
	private const META_ATTEMPTS    = '_twp_attempts';
	private const META_FAIL_REASON = '_twp_fail_reason';
	private const META_CLAIMED_AT  = '_twp_claimed_at';
	private const META_STARTED_AT  = '_twp_started_at';
	private const META_RETRANSLATE = '_twp_retranslate';
	private const META_UPDATED_AT  = '_twp_updated_at';
	private const META_COLD_RETRIES = '_twp_cold_retries';
	private const META_COLD_EXHAUSTED = '_twp_cold_exhausted';
	private const META_DEAD_RETRIES = '_twp_dead_retries';
	private const META_JOB_ID = '_twp_job_id';
	private const META_TRANSLATED_AT = '_twp_translated_at';
	private const META_RETRANSLATING = '_twp_retranslating';
	// A read row re-queued because its page changed; its next reading is translated.
	private const META_TRANSLATE_ON_READ = '_twp_translate_on_read';
	// The lexicon and localizer versions a finished row was translated under.
	private const META_LEXICON_VERSION   = '_twp_lexicon_version';
	private const META_LOCALIZER_VERSION = '_twp_localizer_version';

	public const STALE_GROUP_LEXICON   = 'lexicon';
	public const STALE_GROUP_LOCALIZER = 'localizer';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => [],
				'rewrite'             => false,
			]
		);
	}

	public static function delete_all_network_wide(): void {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			self::delete_all();
			return;
		}

		$site_ids = get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::delete_all();
			restore_current_blog();
		}
	}

	public static function delete_all(): void {
		CleanupInventory::delete_posts_of_type( self::POST_TYPE );
	}

	/**
	 * Delete every queue row for one language and return how many were removed.
	 * Used to retire rows the pipeline can never resolve, such as the source
	 * locale, whose source->source job never completes.
	 */
	public function delete_language_rows( string $language ): int {
		if ( '' === $language ) {
			return 0;
		}

		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded private queue, admin/cron context.
				'meta_query'     => [
					[
						'key'   => self::META_LANGUAGE,
						'value' => $language,
					],
				],
			]
		);

		$deleted = 0;
		foreach ( $ids as $post_id ) {
			if ( wp_delete_post( (int) $post_id, true ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Delete queue rows whose stored URL is a WordPress preview/builder or
	 * admin URL (TranslationUrl::is_indexable_url false), left by the
	 * indexer or the miss-report path before either enforced the guard.
	 * Returns how many rows were removed.
	 */
	public function delete_unindexable_rows(): int {
		$deleted = 0;

		foreach ( $this->all_row_ids() as $post_id ) {
			$url = (string) get_post_meta( $post_id, self::META_URL, true );
			if ( '' === $url || TranslationUrl::is_indexable_url( $url ) ) {
				continue;
			}

			if ( wp_delete_post( $post_id, true ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	protected function now(): int {
		return time();
	}

	/**
	 * Marks when this round of work on a row began, once: later claims of the
	 * same round (batches, the push stage, retries) keep it.
	 *
	 * @param int $id The queue row.
	 */
	private function mark_started( int $id ): void {
		if ( (int) get_post_meta( $id, self::META_STARTED_AT, true ) <= 0 ) {
			update_post_meta( $id, self::META_STARTED_AT, $this->now() );
		}
	}

	/**
	 * Insert missing rows; revive dead rows for re-enumerated objects.
	 * Rows already live (pending/claimed/done) are left untouched.
	 */
	public function enqueue( array $rows ): int {
		$inserted = 0;

		foreach ( $rows as $row ) {
			$object_type = (string) $row['object_type'];
			$object_id   = (int) $row['object_id'];
			$language    = (string) $row['language'];

			$existing_id = $this->find_row( $object_type, $object_id, $language );

			if ( null === $existing_id ) {
				$post_id = wp_insert_post(
					[
						'post_type'   => self::POST_TYPE,
						'post_status' => 'private',
						'post_title'  => $object_type . ':' . $object_id . ':' . $language,
						'meta_input'  => [
							self::META_OBJECT_TYPE => $object_type,
							self::META_OBJECT_ID   => $object_id,
							self::META_URL         => (string) $row['url'],
							self::META_PAGE_TITLE  => (string) ( $row['page_title'] ?? '' ),
							self::META_LANGUAGE    => $language,
							self::META_STAGE       => self::STAGE_EXTRACT,
							self::META_STATUS      => self::STATUS_PENDING,
							self::META_PRIORITY    => (int) $row['priority'],
							self::META_ATTEMPTS    => 0,
							self::META_FAIL_REASON => '',
							self::META_RETRANSLATE => 0,
						],
					],
					false
				);

				if ( $post_id && ! is_wp_error( $post_id ) ) {
					$inserted++;
				}
				continue;
			}

			if ( self::STATUS_DEAD === $this->row_status( $existing_id ) ) {
				update_post_meta( $existing_id, self::META_STAGE, self::STAGE_EXTRACT );
				update_post_meta( $existing_id, self::META_STATUS, self::STATUS_PENDING );
				update_post_meta( $existing_id, self::META_PRIORITY, (int) $row['priority'] );
				update_post_meta( $existing_id, self::META_ATTEMPTS, 0 );
				update_post_meta( $existing_id, self::META_FAIL_REASON, '' );
				update_post_meta( $existing_id, self::META_RETRANSLATE, 0 );
				update_post_meta( $existing_id, self::META_URL, (string) $row['url'] );
				update_post_meta( $existing_id, self::META_PAGE_TITLE, (string) ( $row['page_title'] ?? '' ) );
				delete_post_meta( $existing_id, self::META_CLAIMED_AT );
				delete_post_meta( $existing_id, self::META_STARTED_AT );
				wp_update_post(
					[
						'ID'           => $existing_id,
						'post_content' => '',
					]
				);
				$inserted++;
			}
		}

		return $inserted;
	}

	/**
	 * Rewarm path (save_post): revive done/dead rows for a changed object.
	 * Rows that never existed stay absent — the miss path serves that
	 * content on first visit. Live pending/claimed rows stay untouched, and so
	 * do rows last indexed at or after $modified_at (the object's last save).
	 */
	public function enqueue_rewarm( array $rows, int $modified_at = 0 ): int {
		$inserted = 0;

		foreach ( $rows as $row ) {
			$object_type = (string) $row['object_type'];
			$object_id   = (int) $row['object_id'];
			$language    = (string) $row['language'];

			$existing_id = $this->find_row( $object_type, $object_id, $language );
			if ( null === $existing_id ) {
				continue;
			}

			$status = $this->row_status( $existing_id );
			if ( self::STATUS_DEAD !== $status && self::STATUS_DONE !== $status ) {
				continue;
			}

			if ( $modified_at > 0 && (int) get_post_meta( $existing_id, self::META_UPDATED_AT, true ) >= $modified_at ) {
				continue;
			}

			update_post_meta( $existing_id, self::META_STAGE, self::STAGE_EXTRACT );
			update_post_meta( $existing_id, self::META_STATUS, self::STATUS_PENDING );
			update_post_meta( $existing_id, self::META_PRIORITY, (int) $row['priority'] );
			update_post_meta( $existing_id, self::META_ATTEMPTS, 0 );
			update_post_meta( $existing_id, self::META_FAIL_REASON, '' );
			update_post_meta( $existing_id, self::META_RETRANSLATE, 0 );
			update_post_meta( $existing_id, self::META_URL, (string) $row['url'] );
			delete_post_meta( $existing_id, self::META_CLAIMED_AT );
			delete_post_meta( $existing_id, self::META_STARTED_AT );
			wp_update_post(
				[
					'ID'           => $existing_id,
					'post_content' => '',
				]
			);
			$inserted++;
		}

		return $inserted;
	}

	/**
	 * Retranslate path: re-queue the given objects for a fresh pass that
	 * overwrites the server cache. New rows and every existing row not claimed
	 * right now (re)enter the extract stage with the retranslate flag and a
	 * dropped payload, so the tab re-extracts fresh neighbours, title and
	 * context instead of the drain resending a stored payload. A row claimed
	 * right now is mid-extract or mid-push and cannot be interrupted: it keeps
	 * its pass but is marked so that pass still sends the flag. A non-empty page
	 * title on the request is carried onto the row. Returns how many rows were
	 * (re)queued.
	 */
	public function enqueue_retranslate( array $rows ): int {
		$queued = 0;

		foreach ( $rows as $row ) {
			$object_type = (string) $row['object_type'];
			$object_id   = (int) $row['object_id'];
			$language    = (string) $row['language'];

			$existing_id = $this->find_row( $object_type, $object_id, $language );

			if ( null === $existing_id ) {
				$post_id = wp_insert_post(
					[
						'post_type'   => self::POST_TYPE,
						'post_status' => 'private',
						'post_title'  => $object_type . ':' . $object_id . ':' . $language,
						'meta_input'  => [
							self::META_OBJECT_TYPE => $object_type,
							self::META_OBJECT_ID   => $object_id,
							self::META_URL         => (string) ( $row['url'] ?? '' ),
							self::META_PAGE_TITLE  => (string) ( $row['page_title'] ?? '' ),
							self::META_LANGUAGE    => $language,
							self::META_STAGE       => self::STAGE_EXTRACT,
							self::META_STATUS      => self::STATUS_PENDING,
							self::META_PRIORITY    => (int) $row['priority'],
							self::META_ATTEMPTS    => 0,
							self::META_FAIL_REASON => '',
							self::META_RETRANSLATE => 1,
							self::META_RETRANSLATING => 1,
						],
					],
					false
				);

				if ( $post_id && ! is_wp_error( $post_id ) ) {
					$queued++;
				}
				continue;
			}

			update_post_meta( $existing_id, self::META_RETRANSLATE, 1 );
			update_post_meta( $existing_id, self::META_RETRANSLATING, 1 );
			if ( empty( $row['self_heal'] ) ) {
				delete_post_meta( $existing_id, self::META_COLD_RETRIES );
				delete_post_meta( $existing_id, self::META_COLD_EXHAUSTED );
			}

			$row_title = (string) ( $row['page_title'] ?? '' );
			if ( '' !== $row_title ) {
				update_post_meta( $existing_id, self::META_PAGE_TITLE, $row_title );
			}

			// A row claimed right now is mid-extract or mid-push and cannot be
			// safely interrupted: it keeps its current pass and the flag it now
			// carries. Every other row (pending, finished or dead) returns to the
			// extract stage with its stored payload dropped, so the tab
			// re-extracts fresh neighbours, title and context instead of the
			// drain resending the stored payload.
			if ( self::STATUS_CLAIMED === $this->row_status( $existing_id ) ) {
				$queued++;
				continue;
			}

			update_post_meta( $existing_id, self::META_STAGE, self::STAGE_EXTRACT );
			update_post_meta( $existing_id, self::META_STATUS, self::STATUS_PENDING );
			update_post_meta( $existing_id, self::META_PRIORITY, (int) $row['priority'] );
			update_post_meta( $existing_id, self::META_ATTEMPTS, 0 );
			update_post_meta( $existing_id, self::META_FAIL_REASON, '' );
			update_post_meta( $existing_id, self::META_URL, (string) ( $row['url'] ?? '' ) );
			delete_post_meta( $existing_id, self::META_CLAIMED_AT );
			delete_post_meta( $existing_id, self::META_STARTED_AT );
			wp_update_post(
				[
					'ID'           => $existing_id,
					'post_content' => '',
				]
			);
			$queued++;
		}

		return $queued;
	}

	/**
	 * Every queue row shaped for the admin page list: object identity, page
	 * url/title, language, its stage/status, the retranslate flag, the failure
	 * reason, and the time its status last changed.
	 */
	public function list_rows(): array {
		$rows = [];

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::READ_LANGUAGE === (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) ) {
				continue;
			}
			$rows[] = [
				'id'          => (int) $post_id,
				'object_type' => (string) get_post_meta( $post_id, self::META_OBJECT_TYPE, true ),
				'object_id'   => (int) get_post_meta( $post_id, self::META_OBJECT_ID, true ),
				'url'         => TranslationUrl::rebase_to_home( (string) get_post_meta( $post_id, self::META_URL, true ) ),
				'page_title'  => self::stored_title( $post_id ),
				'language'    => (string) get_post_meta( $post_id, self::META_LANGUAGE, true ),
				'stage'       => (string) get_post_meta( $post_id, self::META_STAGE, true ),
				'status'      => $this->row_status( $post_id ),
				'retranslate' => (int) get_post_meta( $post_id, self::META_RETRANSLATE, true ) > 0,
				'reason'      => (string) get_post_meta( $post_id, self::META_FAIL_REASON, true ),
				'updated'     => (int) get_post_meta( $post_id, self::META_UPDATED_AT, true ),
				'claimed_at'  => (int) get_post_meta( $post_id, self::META_CLAIMED_AT, true ),
				'started_at'  => (int) get_post_meta( $post_id, self::META_STARTED_AT, true ),
				'translated'  => $this->is_translated( $post_id ),
				'cold_retries' => (int) get_post_meta( $post_id, self::META_COLD_RETRIES, true ),
			];
		}

		return $rows;
	}

	/**
	 * A done row is translated once its job was seen finished or every segment
	 * of it was proven present; the flag is only meaningful while the row is
	 * done, so it is cleared on completion and set again by the reconcile.
	 */
	public function is_translated( int $id ): bool {
		return self::STATUS_DONE === $this->row_status( $id )
			&& (int) get_post_meta( $id, self::META_TRANSLATED_AT, true ) > 0;
	}

	public function mark_translated( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( self::STATUS_DONE !== $this->row_status( (int) $id ) ) {
				continue;
			}
			delete_post_meta( (int) $id, self::META_RETRANSLATING );
			if ( (int) get_post_meta( (int) $id, self::META_TRANSLATED_AT, true ) <= 0 ) {
				update_post_meta( (int) $id, self::META_TRANSLATED_AT, $this->now() );
			}
		}
	}

	/**
	 * Every finished (done) queue row with its page identity, language and the
	 * time it completed. The source for the dashboard's translation widgets:
	 * rows are unique per object and language, so a done row is one translated
	 * page in one language.
	 */
	public function completed_rows(): array {
		$rows = [];

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::STATUS_DONE !== $this->row_status( $post_id )
				|| self::READ_LANGUAGE === (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) ) {
				continue;
			}

			$rows[] = [
				'object_type' => (string) get_post_meta( $post_id, self::META_OBJECT_TYPE, true ),
				'object_id'   => (int) get_post_meta( $post_id, self::META_OBJECT_ID, true ),
				'url'         => TranslationUrl::rebase_to_home( (string) get_post_meta( $post_id, self::META_URL, true ) ),
				'page_title'  => self::stored_title( $post_id ),
				'language'    => (string) get_post_meta( $post_id, self::META_LANGUAGE, true ),
				'updated'     => (int) get_post_meta( $post_id, self::META_UPDATED_AT, true ),
			];
		}

		return $rows;
	}

	/**
	 * Collapse duplicate queue rows for the same page and language: an earlier
	 * warm-up run and the visitor miss path can each leave a row for one page
	 * URL in one language, so the admin list shows the same page several times.
	 * Rows are keyed by TranslationUrl::canonicalize() so a scheme, trailing
	 * slash or `lang` query param difference still collapses to one page. Keep
	 * the newest row (highest post id) per canonical URL and language and
	 * delete the rest. Returns how many stale rows were removed.
	 */
	public function collapse_stale_duplicates(): int {
		$by_key = [];

		foreach ( $this->all_row_ids() as $post_id ) {
			$url       = (string) get_post_meta( $post_id, self::META_URL, true );
			$language  = (string) get_post_meta( $post_id, self::META_LANGUAGE, true );
			$canonical = TranslationUrl::canonicalize( $url );
			$key       = ( '' !== $canonical ? $canonical : $url ) . "\n" . $language;

			$by_key[ $key ][] = (int) $post_id;
		}

		$deleted = 0;

		foreach ( $by_key as $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}

			sort( $ids, SORT_NUMERIC );
			array_pop( $ids );

			foreach ( $ids as $stale_id ) {
				if ( wp_delete_post( $stale_id, true ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Upsert a ready-to-push row (miss-proxy path): new and finished rows
	 * (re)enter the push stage with a fresh payload; in-flight rows dedupe.
	 */
	public function enqueue_push( array $row ) {
		$object_type = (string) $row['object_type'];
		$object_id   = (int) $row['object_id'];
		$language    = (string) $row['language'];
		$payload     = (string) $row['payload'];
		if ( '' === trim( $payload ) ) {
			return false;
		}

		$existing_id = $this->find_row( $object_type, $object_id, $language );

		if ( null === $existing_id ) {
			$post_id = wp_insert_post(
				[
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'private',
					'post_title'   => $object_type . ':' . $object_id . ':' . $language,
					'post_content' => wp_slash( self::encode_payload( $payload ) ),
					'meta_input'   => [
						self::META_OBJECT_TYPE => $object_type,
						self::META_OBJECT_ID   => $object_id,
						self::META_URL         => (string) ( $row['url'] ?? '' ),
						self::META_PAGE_TITLE  => (string) ( $row['page_title'] ?? '' ),
						self::META_LANGUAGE    => $language,
						self::META_STAGE       => self::STAGE_PUSH,
						self::META_STATUS      => self::STATUS_PENDING,
						self::META_PRIORITY    => (int) $row['priority'],
						self::META_ATTEMPTS    => 0,
						self::META_FAIL_REASON => '',
						self::META_RETRANSLATE => 0,
					],
				],
				false
			);

			return ( $post_id && ! is_wp_error( $post_id ) ) ? (int) $post_id : false;
		}

		$status = $this->row_status( $existing_id );
		if ( self::STATUS_PENDING === $status || self::STATUS_CLAIMED === $status ) {
			return false;
		}

		$updated = wp_update_post(
			[
				'ID'           => $existing_id,
				'post_content' => wp_slash( self::encode_payload( $payload ) ),
			],
			true
		);
		if ( is_wp_error( $updated ) ) {
			return false;
		}

		update_post_meta( $existing_id, self::META_STAGE, self::STAGE_PUSH );
		update_post_meta( $existing_id, self::META_STATUS, self::STATUS_PENDING );
		update_post_meta( $existing_id, self::META_PRIORITY, (int) $row['priority'] );
		update_post_meta( $existing_id, self::META_ATTEMPTS, 0 );
		update_post_meta( $existing_id, self::META_FAIL_REASON, '' );
		update_post_meta( $existing_id, self::META_RETRANSLATE, 0 );
		update_post_meta( $existing_id, self::META_PAGE_TITLE, (string) ( $row['page_title'] ?? '' ) );
		delete_post_meta( $existing_id, self::META_CLAIMED_AT );
		delete_post_meta( $existing_id, self::META_STARTED_AT );

		return (int) $existing_id;
	}

	/**
	 * Queue every group a visitor missed on one page and language as ONE row.
	 * A pending or claimed row for that page takes the new groups into its
	 * payload (merged by group id); a finished or dead row re-enters the push
	 * stage carrying only the new groups, so nothing already translated is
	 * sent again; a page without a row gets a new one. Returns the row id.
	 */
	public function enqueue_miss_page( array $row, array $items ) {
		$object_type = (string) $row['object_type'];
		$object_id   = (int) $row['object_id'];
		$language    = (string) $row['language'];
		$items       = self::encodable_items( $items );
		if ( ! $items ) {
			return false;
		}
		$existing_id = $this->find_row( $object_type, $object_id, $language );

		if ( null === $existing_id ) {
			$row['payload'] = (string) wp_json_encode( array_values( $items ) );

			return $this->enqueue_push( $row );
		}

		$status = $this->row_status( $existing_id );

		if ( self::STATUS_PENDING !== $status && self::STATUS_CLAIMED !== $status ) {
			$row['payload'] = (string) wp_json_encode( array_values( $items ) );

			return $this->enqueue_push( $row );
		}

		$stored = json_decode( self::decode_payload( (string) get_post_field( 'post_content', $existing_id ) ), true );
		$merged = [];
		foreach ( is_array( $stored ) ? $stored : [] as $item ) {
			if ( is_array( $item ) && isset( $item['groupId'] ) ) {
				$merged[ (string) $item['groupId'] ] = $item;
			}
		}
		foreach ( $items as $item ) {
			$merged[ (string) $item['groupId'] ] = $item;
		}

		$encoded = wp_json_encode( array_values( $merged ) );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		$updated = wp_update_post(
			[
				'ID'           => $existing_id,
				'post_content' => wp_slash( self::encode_payload( $encoded ) ),
			],
			true
		);

		return is_wp_error( $updated ) ? false : (int) $existing_id;
	}

	/**
	 * The items that can be stored: every string field made valid UTF-8, and
	 * any item that still cannot be JSON-encoded dropped, so a row never holds
	 * an empty payload that no attempt can translate.
	 */
	public static function encodable_items( array $items ): array {
		$kept = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item = self::valid_utf8_deep( $item );
			if ( false === wp_json_encode( $item ) ) {
				continue;
			}
			$kept[] = $item;
		}

		return $kept;
	}

	private static function valid_utf8_deep( $value ) {
		if ( is_string( $value ) ) {
			return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $value, true ) : $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $inner ) {
				$value[ $key ] = self::valid_utf8_deep( $inner );
			}
		}

		return $value;
	}

	/**
	 * Claim exactly the given pending rows (D61 inline fast-path: only the
	 * requesting visitor's own segments, never a full tick).
	 */
	public function claim_rows( array $ids ): array {
		$claimed = [];
		foreach ( array_map( 'intval', $ids ) as $post_id ) {
			if ( self::STATUS_PENDING !== $this->row_status( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, self::META_STATUS, self::STATUS_CLAIMED );
			update_post_meta( $post_id, self::META_CLAIMED_AT, $this->now() );
			$this->mark_started( (int) $post_id );

			$claimed[] = [
				'id'          => $post_id,
				'object_type' => (string) get_post_meta( $post_id, self::META_OBJECT_TYPE, true ),
				'object_id'   => (int) get_post_meta( $post_id, self::META_OBJECT_ID, true ),
				'url'         => TranslationUrl::rebase_to_home( (string) get_post_meta( $post_id, self::META_URL, true ) ),
				'page_title'  => self::stored_title( $post_id ),
				'language'    => (string) get_post_meta( $post_id, self::META_LANGUAGE, true ),
				'stage'       => (string) get_post_meta( $post_id, self::META_STAGE, true ),
				'priority'    => (int) get_post_meta( $post_id, self::META_PRIORITY, true ),
				'payload'     => self::decode_payload( (string) get_post_field( 'post_content', $post_id ) ),
				'attempts'    => (int) get_post_meta( $post_id, self::META_ATTEMPTS, true ),
				'retranslate' => (int) get_post_meta( $post_id, self::META_RETRANSLATE, true ) > 0,
			];
		}

		return $claimed;
	}

	/**
	 * Claim up to $limit pending rows of a stage. Stale claims (an extraction
	 * tab that closed, a crashed drain) are reclaimed first.
	 */
	public function claim_batch( string $stage, int $limit ): array {
		$this->reclaim_stale();

		$candidates = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => max( 1, $limit ),
				'fields'         => 'ids',
				'meta_key'       => self::META_PRIORITY,
				'orderby'        => [
					'meta_value_num' => 'ASC',
					'ID'             => 'ASC',
				],
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded private queue, admin/cron context.
				'meta_query'     => [
					[
						'key'   => self::META_STATUS,
						'value' => self::STATUS_PENDING,
					],
					[
						'key'   => self::META_STAGE,
						'value' => $stage,
					],
				],
			]
		);

		$claimed = [];
		foreach ( $candidates as $post_id ) {
			$post_id = (int) $post_id;
			if ( self::STATUS_PENDING !== $this->row_status( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, self::META_STATUS, self::STATUS_CLAIMED );
			update_post_meta( $post_id, self::META_CLAIMED_AT, $this->now() );
			$this->mark_started( (int) $post_id );

			$claimed[] = [
				'id'          => $post_id,
				'object_type' => (string) get_post_meta( $post_id, self::META_OBJECT_TYPE, true ),
				'object_id'   => (int) get_post_meta( $post_id, self::META_OBJECT_ID, true ),
				'url'         => TranslationUrl::rebase_to_home( (string) get_post_meta( $post_id, self::META_URL, true ) ),
				'page_title'  => self::stored_title( $post_id ),
				'language'    => (string) get_post_meta( $post_id, self::META_LANGUAGE, true ),
				'stage'       => (string) get_post_meta( $post_id, self::META_STAGE, true ),
				'priority'    => (int) get_post_meta( $post_id, self::META_PRIORITY, true ),
				'payload'     => self::decode_payload( (string) get_post_field( 'post_content', $post_id ) ),
				'attempts'    => (int) get_post_meta( $post_id, self::META_ATTEMPTS, true ),
				'retranslate' => (int) get_post_meta( $post_id, self::META_RETRANSLATE, true ) > 0,
			];
		}

		return $claimed;
	}

	/**
	 * Queued payloads are JSON that may carry text looking like markup; the
	 * content filters WordPress applies on save rewrite such text and break the
	 * JSON, so the payload is stored as base64 with a marker prefix, which no
	 * filter touches.
	 */
	private const PAYLOAD_PREFIX = 'b64:';

	private static function encode_payload( string $payload ): string {
		return self::PAYLOAD_PREFIX . base64_encode( $payload );
	}

	/**
	 * Reads a stored payload; a row written before the encoding holds plain
	 * JSON and is returned as it is.
	 */
	public static function decode_payload( string $stored ): string {
		if ( 0 !== strpos( $stored, self::PAYLOAD_PREFIX ) ) {
			return $stored;
		}
		$decoded = base64_decode( substr( $stored, strlen( self::PAYLOAD_PREFIX ) ), true );

		return false === $decoded ? '' : $decoded;
	}

	public function store_payload( int $id, string $payload ): bool {
		if ( self::STATUS_CLAIMED !== $this->row_status( $id )
			|| self::STAGE_EXTRACT !== (string) get_post_meta( $id, self::META_STAGE, true ) ) {
			return false;
		}

		$updated = wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( self::encode_payload( $payload ) ),
			],
			true
		);
		if ( is_wp_error( $updated ) ) {
			return false;
		}

		if ( self::READ_LANGUAGE === (string) get_post_meta( $id, self::META_LANGUAGE, true ) ) {
			// A read row keeps its payload and rests in the read stage; the
			// fan-out copies it into per-language push rows later.
			update_post_meta( $id, self::META_STAGE, self::STAGE_READ );
			update_post_meta( $id, self::META_STATUS, self::STATUS_DONE );
			update_post_meta( $id, self::META_ATTEMPTS, 0 );
			update_post_meta( $id, self::META_FAIL_REASON, '' );
			update_post_meta( $id, self::META_UPDATED_AT, $this->now() );
			delete_post_meta( $id, self::META_CLAIMED_AT );

			return true;
		}

		update_post_meta( $id, self::META_STAGE, self::STAGE_PUSH );
		update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );
		update_post_meta( $id, self::META_ATTEMPTS, 0 );
		update_post_meta( $id, self::META_FAIL_REASON, '' );
		delete_post_meta( $id, self::META_CLAIMED_AT );

		return true;
	}

	/**
	 * Queue the site's pages for reading only, one row per page with
	 * no language. A page already read is read again: its row returns to the
	 * extract stage with its payload dropped.
	 */
	public function enqueue_read( array $rows ): int {
		$queued = 0;

		foreach ( $rows as $row ) {
			$object_type = (string) $row['object_type'];
			$object_id   = (int) $row['object_id'];
			$existing_id = $this->find_row( $object_type, $object_id, self::READ_LANGUAGE );

			if ( null === $existing_id ) {
				$row['language'] = self::READ_LANGUAGE;
				$queued         += $this->enqueue( [ $row ] );
				continue;
			}

			if ( self::STATUS_CLAIMED === $this->row_status( $existing_id ) ) {
				continue;
			}

			update_post_meta( $existing_id, self::META_STAGE, self::STAGE_EXTRACT );
			update_post_meta( $existing_id, self::META_STATUS, self::STATUS_PENDING );
			update_post_meta( $existing_id, self::META_PRIORITY, (int) $row['priority'] );
			update_post_meta( $existing_id, self::META_ATTEMPTS, 0 );
			update_post_meta( $existing_id, self::META_FAIL_REASON, '' );
			update_post_meta( $existing_id, self::META_URL, (string) $row['url'] );
			update_post_meta( $existing_id, self::META_PAGE_TITLE, (string) ( $row['page_title'] ?? '' ) );
			delete_post_meta( $existing_id, self::META_CLAIMED_AT );
			delete_post_meta( $existing_id, self::META_STARTED_AT );
			wp_update_post(
				[
					'ID'           => $existing_id,
					'post_content' => '',
				]
			);
			$queued++;
		}

		return $queued;
	}

	/**
	 * The pages read so far, with their extracted items.
	 *
	 * @return array<int, array{id:int,object_type:string,object_id:int,url:string,page_title:string,priority:int,items:array}>
	 */
	public function read_rows(): array {
		$rows = [];

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( $this->is_finished_read_row( $post_id ) ) {
				$rows[] = $this->read_row( $post_id );
			}
		}

		return $rows;
	}

	/**
	 * Rewarm path for a site that was read: the changed page is
	 * read again, once, while its translated rows keep serving and stay done;
	 * the new reading is translated when it lands. Returns null when the page
	 * has no read row, false when the page was read at or after $modified_at
	 * (its last save), and true when a new reading is queued or already under
	 * way.
	 */
	public function reread_for_rewarm( string $object_type, int $object_id, string $url, string $page_title, int $modified_at ): ?bool {
		$read_id = $this->find_row( $object_type, $object_id, self::READ_LANGUAGE );
		if ( null === $read_id ) {
			return null;
		}

		if ( $this->is_finished_read_row( $read_id ) && $modified_at > 0
			&& (int) get_post_meta( $read_id, self::META_UPDATED_AT, true ) >= $modified_at ) {
			return false;
		}

		if ( $this->is_finished_read_row( $read_id ) || self::STATUS_DEAD === $this->row_status( $read_id ) ) {
			$this->enqueue_read(
				[
					[
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'url'         => $url,
						'page_title'  => $page_title,
						'priority'    => (int) get_post_meta( $read_id, self::META_PRIORITY, true ),
					],
				]
			);
		}

		update_post_meta( $read_id, self::META_TRANSLATE_ON_READ, 1 );

		return true;
	}

	/**
	 * The read row just finished reading when its page was queued by a
	 * rewarm, with the rewarm mark cleared; null for any other row.
	 */
	public function take_translate_on_read( int $id ): ?array {
		if ( '' === (string) get_post_meta( $id, self::META_TRANSLATE_ON_READ, true )
			|| self::STATUS_DONE !== $this->row_status( $id ) ) {
			return null;
		}

		delete_post_meta( $id, self::META_TRANSLATE_ON_READ );

		return $this->is_finished_read_row( $id ) ? $this->read_row( $id ) : null;
	}

	/**
	 * The source segments last read from one page, every text of the page
	 * with the site's header and footer included. Empty when the page was
	 * never read or its reading is not finished.
	 */
	public function read_items( string $object_type, int $object_id ): array {
		$row_id = $this->find_row( $object_type, $object_id, self::READ_LANGUAGE );
		if ( null === $row_id || ! $this->is_finished_read_row( $row_id ) ) {
			return [];
		}

		return $this->read_row( $row_id )['items'];
	}

	private function is_finished_read_row( int $post_id ): bool {
		return self::READ_LANGUAGE === (string) get_post_meta( $post_id, self::META_LANGUAGE, true )
			&& self::STAGE_READ === (string) get_post_meta( $post_id, self::META_STAGE, true )
			&& self::STATUS_DONE === $this->row_status( $post_id );
	}

	private function read_row( int $post_id ): array {
		$items = json_decode( self::decode_payload( (string) get_post_field( 'post_content', $post_id ) ), true );

		return [
			'id'          => $post_id,
			'object_type' => (string) get_post_meta( $post_id, self::META_OBJECT_TYPE, true ),
			'object_id'   => (int) get_post_meta( $post_id, self::META_OBJECT_ID, true ),
			'url'         => TranslationUrl::rebase_to_home( (string) get_post_meta( $post_id, self::META_URL, true ) ),
			'page_title'  => self::stored_title( $post_id ),
			'priority'    => (int) get_post_meta( $post_id, self::META_PRIORITY, true ),
			'items'       => is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : [],
		];
	}

	/**
	 * How many read pages were saved after they were read, using the same
	 * comparison as the rewarm path (a read at or after the last save is
	 * up to date). Read-only: no row is touched and no page is re-read.
	 */
	public function changed_since_read_count(): int {
		$changed = 0;

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( ! $this->is_finished_read_row( $post_id ) ) {
				continue;
			}

			$object_id  = (int) get_post_meta( $post_id, self::META_OBJECT_ID, true );
			$updated_at = (int) get_post_meta( $post_id, self::META_UPDATED_AT, true );
			if ( $object_id < 1 || $updated_at < 1 ) {
				continue;
			}

			$modified_at = $this->object_modified_at( $object_id );
			if ( $modified_at > $updated_at ) {
				$changed++;
			}
		}

		return $changed;
	}

	/**
	 * Re-read only the pages saved since they were read, reusing the rewarm
	 * path: each changed page is read again once and its new reading is
	 * translated when it lands. Returns how many pages were queued.
	 */
	public function reread_changed(): int {
		$requeued = 0;

		foreach ( $this->read_rows() as $row ) {
			$object_id   = (int) $row['object_id'];
			$modified_at = $this->object_modified_at( $object_id );
			if ( $object_id < 1 || $modified_at < 1 ) {
				continue;
			}

			$result = $this->reread_for_rewarm(
				(string) $row['object_type'],
				$object_id,
				(string) $row['url'],
				(string) $row['page_title'],
				$modified_at
			);
			if ( true === $result ) {
				$requeued++;
			}
		}

		return $requeued;
	}

	/** The object's last save as a Unix timestamp, 0 when unknown. */
	private function object_modified_at( int $object_id ): int {
		$modified_gmt = (string) get_post_field( 'post_modified_gmt', $object_id );
		if ( '' === $modified_gmt || 0 === strpos( $modified_gmt, '0000-00-00' ) ) {
			return 0;
		}

		$modified = strtotime( $modified_gmt . ' UTC' );

		return false !== $modified && $modified > 0 ? $modified : 0;
	}

	/**
	 * How the reading stands: pages read, pages still to read, pages
	 * that could not be read, and the segments found so far.
	 */
	public function read_counts(): array {
		$counts = [
			'pages'    => 0,
			'pending'  => 0,
			'failed'   => 0,
			'segments' => 0,
		];

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::READ_LANGUAGE !== (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) ) {
				continue;
			}

			$status = $this->row_status( $post_id );
			if ( self::STATUS_DEAD === $status ) {
				$counts['failed']++;
			} elseif ( self::STATUS_DONE === $status ) {
				$counts['pages']++;
				$items              = json_decode( self::decode_payload( (string) get_post_field( 'post_content', $post_id ) ), true );
				$counts['segments'] += is_array( $items ) ? count( $items ) : 0;
			} else {
				$counts['pending']++;
			}
		}

		return $counts;
	}

	/**
	 * The page-level progress of a running read: how many pages the read
	 * covers, how many are done, and the page being read right now (the first
	 * claimed read row; the newest finished row when nothing is claimed, so
	 * the line never names a stale page once the last claim completes).
	 */
	public function read_scan_progress(): array {
		$total = 0;
		$done  = 0;
		$claimed_row = null;
		$last_done   = null;

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::READ_LANGUAGE !== (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) ) {
				continue;
			}

			$status = $this->row_status( $post_id );
			if ( self::STATUS_DEAD === $status || self::STATUS_DONE === $status ) {
				if ( self::STATUS_DONE === $status ) {
					$done++;
					if ( null === $last_done || $post_id > $last_done ) {
						$last_done = $post_id;
					}
				}
				continue;
			}

			$total++;
			if ( null === $claimed_row && self::STATUS_CLAIMED === $status ) {
				$claimed_row = $post_id;
			}
		}

		$current_id = $claimed_row ?? $last_done;
		$current    = null;
		if ( null !== $current_id ) {
			$title = self::stored_title( $current_id );
			$path  = (string) wp_parse_url( TranslationUrl::rebase_to_home( (string) get_post_meta( $current_id, self::META_URL, true ) ), PHP_URL_PATH );
			if ( '' === $title ) {
				$object_id = (int) get_post_meta( $current_id, self::META_OBJECT_ID, true );
				if ( $object_id > 0 ) {
					$title = PlainText::title( $object_id );
				}
			}
			$current = [
				'title' => $title,
				'path'  => false !== $path ? (string) $path : '',
			];
		}

		return [
			'pages_total' => $total + $done,
			'pages_done'  => $done,
			'current_page' => $current,
		];
	}

	/**
	 * Whether a live push row (pending, claimed or finished) exists for the
	 * page and language.
	 */
	public function has_live_row( string $object_type, int $object_id, string $language ): bool {
		$existing_id = $this->find_row( $object_type, $object_id, $language );
		if ( null === $existing_id ) {
			return false;
		}

		return self::STATUS_DEAD !== $this->row_status( $existing_id );
	}

	/**
	 * Accumulate one extraction batch on a claimed row. A non-final batch is
	 * buffered on the row so a page with more items than a single submit-extract
	 * request allows can be sent in several batches; the final batch stores the
	 * whole payload and advances the row to the push stage. An empty final batch
	 * with nothing buffered completes the row as a finished, translation-free
	 * page.
	 */
	public function append_extraction( int $id, array $items, bool $is_final ): bool {
		if ( self::STATUS_CLAIMED !== $this->row_status( $id )
			|| self::STAGE_EXTRACT !== (string) get_post_meta( $id, self::META_STAGE, true ) ) {
			return false;
		}

		$buffered = json_decode( self::decode_payload( (string) get_post_field( 'post_content', $id ) ), true );
		$buffered = is_array( $buffered ) ? $buffered : [];
		$merged   = array_merge( $buffered, array_values( $items ) );

		if ( $is_final ) {
			if ( ! $merged ) {
				return $this->complete( $id );
			}

			return $this->store_payload( $id, (string) wp_json_encode( array_values( $merged ) ) );
		}

		$stored = wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( self::encode_payload( (string) wp_json_encode( array_values( $merged ) ) ) ),
			],
			true
		);
		if ( is_wp_error( $stored ) ) {
			return false;
		}

		// Keep the claim fresh so a multi-batch upload is not reclaimed as stale
		// between batches.
		update_post_meta( $id, self::META_CLAIMED_AT, $this->now() );

		return true;
	}

	public function complete( int $id ): bool {
		if ( self::STATUS_CLAIMED !== $this->row_status( $id ) ) {
			return false;
		}

		update_post_meta( $id, self::META_STATUS, self::STATUS_DONE );
		update_post_meta( $id, self::META_FAIL_REASON, '' );
		update_post_meta( $id, self::META_UPDATED_AT, $this->now() );
		delete_post_meta( $id, self::META_TRANSLATED_AT );
		update_post_meta( $id, self::META_LEXICON_VERSION, CacheVersions::lexicon_version() );
		update_post_meta( $id, self::META_LOCALIZER_VERSION, CacheVersions::localizer_version() );
		delete_post_meta( $id, self::META_RETRANSLATE );
		delete_post_meta( $id, self::META_CLAIMED_AT );
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '',
			]
		);

		return true;
	}

	/**
	 * Finished rows translated under an older lexicon or localizer version than
	 * the site holds now, in the shape enqueue_retranslate() takes.
	 *
	 * @param string $group STALE_GROUP_LEXICON or STALE_GROUP_LOCALIZER.
	 */
	public function stale_rows( string $group ): array {
		$meta_key = self::STALE_GROUP_LOCALIZER === $group ? self::META_LOCALIZER_VERSION : self::META_LEXICON_VERSION;
		$current  = self::STALE_GROUP_LOCALIZER === $group ? CacheVersions::localizer_version() : CacheVersions::lexicon_version();
		$rows     = [];

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::STATUS_DONE !== $this->row_status( $post_id ) || ! $this->is_target_row( $post_id ) ) {
				continue;
			}
			if ( (int) get_post_meta( $post_id, $meta_key, true ) >= $current ) {
				continue;
			}

			$rows[] = [
				'object_type' => (string) get_post_meta( $post_id, self::META_OBJECT_TYPE, true ),
				'object_id'   => (int) get_post_meta( $post_id, self::META_OBJECT_ID, true ),
				'url'         => TranslationUrl::rebase_to_home( (string) get_post_meta( $post_id, self::META_URL, true ) ),
				'page_title'  => self::stored_title( $post_id ),
				'language'    => (string) get_post_meta( $post_id, self::META_LANGUAGE, true ),
				'priority'    => 0,
			];
		}

		return $rows;
	}

	/**
	 * How many finished pages per language wait for a retranslation in each
	 * group: [ 'lexicon' => [ 'de' => 3 ], 'localizer' => [] ].
	 */
	public function stale_counts(): array {
		$counts = [
			self::STALE_GROUP_LEXICON   => [],
			self::STALE_GROUP_LOCALIZER => [],
		];

		foreach ( array_keys( $counts ) as $group ) {
			foreach ( $this->stale_rows( $group ) as $row ) {
				$language = $row['language'];
				$counts[ $group ][ $language ] = ( $counts[ $group ][ $language ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Return an unprocessed claimed row to the queue without charging an
	 * attempt (used when a drain runs out of its time budget).
	 */
	public function release( int $id ): bool {
		if ( self::STATUS_CLAIMED !== $this->row_status( $id ) ) {
			return false;
		}

		update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );
		delete_post_meta( $id, self::META_CLAIMED_AT );

		return true;
	}

	/**
	 * Record a failure: retry (back to pending) until the attempt cap, then
	 * dead-letter with the reason kept for the ops log.
	 */
	public function fail( int $id, string $reason ): bool {
		if ( self::STATUS_CLAIMED !== $this->row_status( $id ) ) {
			return false;
		}

		$attempts = (int) get_post_meta( $id, self::META_ATTEMPTS, true ) + 1;

		update_post_meta( $id, self::META_ATTEMPTS, $attempts );
		update_post_meta( $id, self::META_STATUS, $attempts >= self::MAX_ATTEMPTS ? self::STATUS_DEAD : self::STATUS_PENDING );
		update_post_meta( $id, self::META_FAIL_REASON, substr( $reason, 0, 255 ) );
		update_post_meta( $id, self::META_UPDATED_AT, $this->now() );
		delete_post_meta( $id, self::META_CLAIMED_AT );

		return true;
	}

	/**
	 * Dead-lettered rows, optionally scoped to one language, each with the page
	 * url and the stored failure reason for the Indexing tab.
	 */
	/**
	 * Rows of the site's own language or of a language no longer in the
	 * list stay in the queue but are not worked on, listed or retried.
	 */
	private function is_target_row( int $post_id ): bool {
		return self::is_target_row_language( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) );
	}

	public static function is_target_row_language( string $language ): bool {
		if ( self::READ_LANGUAGE === $language ) {
			return false;
		}
		// Until setup names the languages there is nothing to hide; once it has,
		// an empty list means no translation language at all.
		if ( ! TranslationProxySettings::active_lookup_languages() && 'done' !== (string) Options::get( 'setup.step', '' ) ) {
			return true;
		}
		return TranslationProxySettings::is_target_language( $language );
	}

	public function failed_items( ?string $language = null ): array {
		$items = [];

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::STATUS_DEAD !== $this->row_status( $post_id ) || ! $this->is_target_row( $post_id ) ) {
				continue;
			}

			$row_language = (string) get_post_meta( $post_id, self::META_LANGUAGE, true );
			if ( null !== $language && $row_language !== $language ) {
				continue;
			}

			$items[] = [
				'id'          => (int) $post_id,
				'language'    => $row_language,
				'url'         => TranslationUrl::rebase_to_home( (string) get_post_meta( $post_id, self::META_URL, true ) ),
				'object_type' => (string) get_post_meta( $post_id, self::META_OBJECT_TYPE, true ),
				'object_id'   => (int) get_post_meta( $post_id, self::META_OBJECT_ID, true ),
				'reason'      => (string) get_post_meta( $post_id, self::META_FAIL_REASON, true ),
				'attempts'    => (int) get_post_meta( $post_id, self::META_ATTEMPTS, true ),
			];
		}

		return $items;
	}

	/**
	 * Re-queue dead rows with the attempt count and failure reason reset. A
	 * row that still holds its extracted payload goes back to the push stage
	 * with that payload, so the drain resends it; a row without a payload
	 * goes back to the extract stage. Returns how many were revived.
	 */
	public function retry_failed( ?string $language = null ): int {
		$revived = 0;

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::STATUS_DEAD !== $this->row_status( $post_id ) || ! $this->is_target_row( $post_id ) ) {
				continue;
			}

			if ( null !== $language && (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) !== $language ) {
				continue;
			}

			$payload      = self::decode_payload( (string) get_post_field( 'post_content', $post_id ) );
			$has_payload  = '' !== trim( $payload );
			update_post_meta( $post_id, self::META_STAGE, $has_payload ? self::STAGE_PUSH : self::STAGE_EXTRACT );
			update_post_meta( $post_id, self::META_STATUS, self::STATUS_PENDING );
			update_post_meta( $post_id, self::META_ATTEMPTS, 0 );
			update_post_meta( $post_id, self::META_FAIL_REASON, '' );
			delete_post_meta( $post_id, self::META_CLAIMED_AT );
			delete_post_meta( $post_id, self::META_STARTED_AT );
			delete_post_meta( $post_id, self::META_DEAD_RETRIES );
			delete_post_meta( $post_id, self::META_COLD_RETRIES );
			delete_post_meta( $post_id, self::META_COLD_EXHAUSTED );
			$revived++;
		}

		return $revived;
	}

	public function requeue_cold( array $rows, int $max_attempts ): array {
		$requeued = 0;
		$failed   = 0;
		foreach ( $rows as $row ) {
			if ( ! $this->charge_cold_retry( (int) $row['id'], $max_attempts ) ) {
				++$failed;
				continue;
			}
			$requeued += $this->enqueue_retranslate(
                [
					$row + [
						'priority' => 100,
						'self_heal' => true,
					],
				]
            );
		}
		return [
			'requeued' => $requeued,
			'failed' => $failed,
		];
	}

	/**
	 * Count one automatic heal attempt for a cold row. Returns false, and leaves
	 * the row failed with its reason, once the attempts are used up.
	 */
	public function charge_cold_retry( int $id, int $max_attempts ): bool {
		$attempts = (int) get_post_meta( $id, self::META_COLD_RETRIES, true );
		if ( $attempts >= $max_attempts ) {
			update_post_meta( $id, self::META_STATUS, self::STATUS_DEAD );
			update_post_meta( $id, self::META_FAIL_REASON, 'Translation did not reach the warm cache after automatic retries.' );
			update_post_meta( $id, self::META_COLD_EXHAUSTED, 1 );
			update_post_meta( $id, self::META_UPDATED_AT, $this->now() );
			return false;
		}
		update_post_meta( $id, self::META_COLD_RETRIES, $attempts + 1 );
		return true;
	}

	public function remember_job( int $id, string $job_id ): void {
		update_post_meta( $id, self::META_JOB_ID, $job_id );
	}

	public function job_id( int $id ): string {
		return (string) get_post_meta( $id, self::META_JOB_ID, true );
	}

	public function reset_cold_retries( array $ids ): void {
		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, self::META_COLD_RETRIES );
			delete_post_meta( (int) $id, self::META_COLD_EXHAUSTED );
		}
	}

	public function retry_dead_automatically( int $max_attempts, int $backoff_seconds, int $now ): int {
		$revived = 0;
		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::STATUS_DEAD !== $this->row_status( $post_id ) || ! $this->is_target_row( $post_id )
				|| 1 === (int) get_post_meta( $post_id, self::META_COLD_EXHAUSTED, true ) ) {
				continue;
			}
			$attempts = (int) get_post_meta( $post_id, self::META_DEAD_RETRIES, true );
			$updated  = (int) get_post_meta( $post_id, self::META_UPDATED_AT, true );
			if ( $max_attempts <= $attempts || $now - $backoff_seconds < $updated ) {
				continue;
			}
			update_post_meta( $post_id, self::META_DEAD_RETRIES, $attempts + 1 );
			$payload = self::decode_payload( (string) get_post_field( 'post_content', $post_id ) );
			update_post_meta( $post_id, self::META_STAGE, 0 < strlen( trim( $payload ) ) ? self::STAGE_PUSH : self::STAGE_EXTRACT );
			update_post_meta( $post_id, self::META_STATUS, self::STATUS_PENDING );
			update_post_meta( $post_id, self::META_ATTEMPTS, 0 );
			update_post_meta( $post_id, self::META_FAIL_REASON, '' );
			delete_post_meta( $post_id, self::META_CLAIMED_AT );
			delete_post_meta( $post_id, self::META_STARTED_AT );
			++$revived;
		}
		return $revived;
	}

	/**
	 * Revive one dead row and hand it back as a claimed extract row so the admin
	 * tab can retry just that page. Returns the claimed row shape, or null when
	 * the row is not dead.
	 */
	public function revive_failed_row( int $id ): ?array {
		if ( self::STATUS_DEAD !== $this->row_status( $id ) ) {
			return null;
		}

		update_post_meta( $id, self::META_STAGE, self::STAGE_EXTRACT );
		update_post_meta( $id, self::META_STATUS, self::STATUS_CLAIMED );
		update_post_meta( $id, self::META_ATTEMPTS, 0 );
		update_post_meta( $id, self::META_FAIL_REASON, '' );
		update_post_meta( $id, self::META_CLAIMED_AT, $this->now() );
		delete_post_meta( $id, self::META_STARTED_AT );
		$this->mark_started( $id );
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '',
			]
		);

		return [
			'id'          => $id,
			'object_type' => (string) get_post_meta( $id, self::META_OBJECT_TYPE, true ),
			'object_id'   => (int) get_post_meta( $id, self::META_OBJECT_ID, true ),
			'url'         => (string) get_post_meta( $id, self::META_URL, true ),
			'language'    => (string) get_post_meta( $id, self::META_LANGUAGE, true ),
		];
	}

	/**
	 * Delete dead rows, optionally scoped to one language. Returns how many
	 * were removed.
	 */
	public function clear_failed( ?string $language = null ): int {
		$cleared = 0;

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::STATUS_DEAD !== $this->row_status( $post_id ) ) {
				continue;
			}

			if ( null !== $language && (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) !== $language ) {
				continue;
			}

			if ( wp_delete_post( (int) $post_id, true ) ) {
				$cleared++;
			}
		}

		return $cleared;
	}

	/**
	 * Re-apply manual pins to queued work: pinned objects get the pinned
	 * priority, every other still-pending row returns to the default.
	 */
	public function reprioritize( array $pinned_object_ids, int $pinned_priority, int $default_priority ): void {
		$pinned = array_map( 'intval', $pinned_object_ids );

		foreach ( $this->all_row_ids() as $post_id ) {
			if ( self::STATUS_PENDING !== $this->row_status( $post_id ) ) {
				continue;
			}

			$object_id = (int) get_post_meta( $post_id, self::META_OBJECT_ID, true );
			update_post_meta(
				$post_id,
				self::META_PRIORITY,
				in_array( $object_id, $pinned, true ) ? $pinned_priority : $default_priority
			);
		}
	}

	/**
	 * Per-language aggregate: [ language => [ total, done, dead, pending_extract, pending_push ] ].
	 */
	public function counts(): array {
		$counts = [];

		foreach ( $this->all_row_ids() as $post_id ) {
			$language = (string) get_post_meta( $post_id, self::META_LANGUAGE, true );
			if ( ! self::is_target_row_language( $language ) ) {
				continue;
			}
			if ( ! isset( $counts[ $language ] ) ) {
				$counts[ $language ] = [
					'total'           => 0,
					'done'            => 0,
					'dead'            => 0,
					'pending_extract' => 0,
					'pending_push'    => 0,
					'translated'      => 0,
					'confirming'      => 0,
					'repairing'       => 0,
					'retranslating'   => 0,
				];
			}

			$counts[ $language ]['total']++;

			$status = $this->row_status( $post_id );
			if ( self::STATUS_DONE === $status ) {
				$counts[ $language ]['done']++;
				if ( (int) get_post_meta( $post_id, self::META_TRANSLATED_AT, true ) > 0 ) {
					$counts[ $language ]['translated']++;
				} elseif ( (int) get_post_meta( $post_id, self::META_RETRANSLATING, true ) > 0 ) {
					// Pushed again but not yet confirmed: still the retranslation.
					$counts[ $language ]['retranslating']++;
				} else {
					$counts[ $language ]['confirming']++;
				}
			} elseif ( self::STATUS_DEAD === $status ) {
				$counts[ $language ]['dead']++;
			} else {
				if ( self::STAGE_EXTRACT === (string) get_post_meta( $post_id, self::META_STAGE, true ) ) {
					$counts[ $language ]['pending_extract']++;
				} else {
					$counts[ $language ]['pending_push']++;
				}
				if ( (int) get_post_meta( $post_id, self::META_COLD_RETRIES, true ) > 0 ) {
					$counts[ $language ]['repairing']++;
				} elseif ( (int) get_post_meta( $post_id, self::META_RETRANSLATE, true ) > 0 ) {
					$counts[ $language ]['retranslating']++;
				}
			}
		}

		return $counts;
	}

	private function reclaim_stale(): void {
		$cutoff = $this->now() - self::STALE_CLAIM_SECONDS;

		$claimed_ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded private queue, admin/cron context.
				'meta_query'     => [
					[
						'key'   => self::META_STATUS,
						'value' => self::STATUS_CLAIMED,
					],
				],
			]
		);

		foreach ( $claimed_ids as $post_id ) {
			$post_id    = (int) $post_id;
			$claimed_at = (int) get_post_meta( $post_id, self::META_CLAIMED_AT, true );
			if ( $claimed_at > 0 && $claimed_at <= $cutoff ) {
				update_post_meta( $post_id, self::META_STATUS, self::STATUS_PENDING );
				delete_post_meta( $post_id, self::META_CLAIMED_AT );
			}
		}
	}

	private function find_row( string $object_type, int $object_id, string $language ): ?int {
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded private queue, admin/cron context.
				'meta_query'     => [
					[
						'key'   => self::META_OBJECT_TYPE,
						'value' => $object_type,
					],
					[
						'key'   => self::META_OBJECT_ID,
						'value' => $object_id,
					],
					[
						'key'   => self::META_LANGUAGE,
						'value' => $language,
					],
				],
			]
		);

		return $ids ? (int) $ids[0] : null;
	}

	private function all_row_ids(): array {
		$ids = array_map(
			'intval',
			get_posts(
				[
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				]
			)
		);

		// One batched meta query instead of N+1 lookups on every scan.
		if ( $ids && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}

		return $ids;
	}

	private function row_status( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_STATUS, true );
	}
}
