<?php

namespace TranslateWP\Components;

class TranslationReadiness {

	public const META = '_twp_delivery_manifest';
	public const COMPLETED_SCOPES_OPTION = 'translatewp_completed_readiness_scopes';
	private const MAX_COMPLETED_SCOPES = 128;
	// Long enough for one full check cycle of a larger site under the service's
	// look-up rate limit; settings and cache generation changes still withdraw
	// readiness at once through the record scope.
	private const PROOF_TTL = 600;
	private const PROOF_GRACE_SECONDS = 1800;
	private const BACKOFF   = 'twp_ready_backoff';
	private const BACKOFF_DEFAULT_SECONDS = 30;
	private const BATCH_SIZE = 100;

	public static function complete_refill( string $language ): bool {
		return MutationLock::run(
            'cache_versions',
            static function () use ( $language ): void {
				$scope = self::scope( $language );
				$proofs = self::proofs( $language );
				$swept = self::swept( $language );
				$aliases = (array) get_option( self::COMPLETED_SCOPES_OPTION, [] );
				$previous = $aliases[ $language ] ?? [];
				$accepted = ( $previous['current'] ?? '' ) === $scope ? (array) ( $previous['accepted'] ?? [] ) : [];
				$accepted[] = $scope;
				TranslationStore::bump_cache_generation( [ $language ] );
				$next_scope = self::scope( $language );
				$aliases[ $language ] = [
					'current' => $next_scope,
					'accepted' => array_slice( array_values( array_unique( $accepted ) ), -self::MAX_COMPLETED_SCOPES ),
				];
				update_option( self::COMPLETED_SCOPES_OPTION, $aliases, false );
				set_transient( 'twp_ready_' . $next_scope, $proofs, self::PROOF_GRACE_SECONDS );
				set_transient( 'twp_swept_' . $next_scope, $swept, self::PROOF_GRACE_SECONDS );
			}
        );
	}

	public static function record( int $id, string $language, array $items, bool $full_page = true ): void {
		update_post_meta( $id, self::META, self::manifest_for( compact( 'id', 'language', 'items', 'full_page' ) ) );
	}

	public static function manifest_for( array $input ): array {
		$id = (int) $input['id'];
		$language = (string) $input['language'];
		$items = (array) $input['items'];
		$full_page = (bool) $input['full_page'];
		$groups = [];
		$valid = true;
		$title = false;
		foreach ( $items as $item ) {
			$hash = (string) ( $item['cache']['hash'] ?? '' );
			$entries = TranslationMarkerPayload::parse( (string) ( $item['markerPayload'] ?? '' ) );
			if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) && $entries ) {
				$groups[ $hash ] = array_keys( $entries );
				$title = $title || 'seo:seo:title' === (string) ( $item['groupId'] ?? '' );
			} else {
				$valid = false;
			}
		}
		if ( ! $full_page ) {
			$url = TranslationUrl::canonicalize( (string) get_post_meta( $id, '_twp_url', true ) );
			foreach ( ( new IndexQueue() )->rows_for_url( (string) get_post_meta( $id, '_twp_url', true ), $language ) as $row ) {
				if ( $row['language'] === $language && '' !== $url && TranslationUrl::canonicalize( $row['url'] ) === $url ) {
					$previous = self::groups( (int) $row['id'], $language );
					if ( $previous ) {
						$groups += $previous;
						$title = $title || self::has_title( (int) $row['id'], $language );
						$full_page = true;
					}
				}
			}
		}
		return [
			'scope' => self::scope( $language ),
			'groups' => $valid && $full_page ? $groups : [],
			'title' => $title,
		];
	}

	public static function is_ready( array $row ): bool {
		if ( IndexQueue::STATUS_DONE !== ( $row['status'] ?? '' ) ) {
			return false;
		}
		$language = (string) $row['language'];
		$groups = self::groups( (int) $row['id'], $language );
		if ( ! $groups ) {
			return false;
		}
		$proofs = self::proofs( $language );
		// A proof is withdrawn by a look-up that reports the segment missing or
		// by a scope change, never by its age.
		foreach ( $groups as $hash => $markers ) {
			if ( ( $proofs[ $hash ]['markers'] ?? '' ) !== self::marker_signature( $markers ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 'none' when the row never recorded a manifest, 'stale' when it recorded
	 * one under another scope (brief, model, cache generation), 'current'
	 * when the manifest belongs to the scope in force.
	 */
	public static function manifest_state( int $id, string $language ): string {
		$manifest = get_post_meta( $id, self::META, true );
		if ( ! is_array( $manifest ) || ! is_array( $manifest['groups'] ?? null ) ) {
			return 'none';
		}
		return self::matching_scope( $manifest, $language ) ? 'current' : 'stale';
	}

	public static function sweep_incomplete( array $row ): bool {
		$language = (string) ( $row['language'] ?? '' );
		$groups   = self::groups( (int) ( $row['id'] ?? 0 ), $language );
		if ( ! $groups ) {
			return false;
		}
		$proofs = self::proofs( $language );
		$swept  = self::swept( $language );
		$cutoff = time() - self::PROOF_GRACE_SECONDS;
		foreach ( $groups as $hash => $markers ) {
			if ( ( $proofs[ $hash ]['checked'] ?? 0 ) <= $cutoff && ( $swept[ $hash ] ?? 0 ) <= $cutoff ) {
				return true;
			}
		}
		return false;
	}

	public static function refresh( array $rows, int $cursor = 0, ?callable $lookup = null, ?ReadinessBudget $work_budget = null, bool $all_batches = false ): array {
		$by_language = [];
		$hash_rows = [];
		$legacy_rows = [];
		$proofs_by_language = [];
		foreach ( $rows as $row ) {
			if ( IndexQueue::STATUS_DONE !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			$language = (string) $row['language'];
			$state    = self::manifest_state( (int) $row['id'], $language );
			// A row translated under another scope waits for an explicit
			// retranslate; it is neither looked up nor repaired here.
			if ( 'stale' === $state ) {
				continue;
			}
			$groups = self::groups( (int) $row['id'], $language );
			if ( ! $groups ) {
				$legacy_rows[] = (int) $row['id'];
			}
			// Opt-in: a segment already proven warm within the proof window is
			// left out of the next look-up instead of being asked for again.
			// Off by default because re-asking is also how an evicted cache
			// entry is noticed.
			$skip_proven = (bool) apply_filters( 'translatewp_readiness_skip_proven', false );
			if ( $skip_proven && ! isset( $proofs_by_language[ $language ] ) ) {
				$proofs_by_language[ $language ] = self::proofs( $language );
			}
			foreach ( $groups as $hash => $markers ) {
				$proof = $skip_proven ? ( $proofs_by_language[ $language ][ $hash ] ?? [] ) : [];
				if ( $skip_proven
					&& ( $proof['markers'] ?? '' ) === self::marker_signature( $markers )
					&& ( $proof['checked'] ?? 0 ) > time() - self::PROOF_TTL ) {
					continue;
				}
				$by_language[ $language ][ $hash ] = $markers;
				$hash_rows[ $language ][ $hash ][] = (int) $row['id'];
			}
		}
		$batches = [];
		foreach ( $by_language as $language => $groups ) {
			foreach ( array_chunk( $groups, self::BATCH_SIZE, true ) as $chunk ) {
				$batches[] = [
					'language' => $language,
					'groups' => $chunk,
				];
			}
		}
		if ( ! $batches ) {
			return [
				'cursor' => 0,
				'language' => '',
				'hashes' => [],
				'rowIds' => [],
				'legacyRowIds' => array_values( array_unique( $legacy_rows ) ),
			];
		}
		$index = max( 0, $cursor ) % count( $batches );
		// Nothing is judged while look-ups are paused, so no row is reported
		// for self-heal either.
		$paused = [
			'lookupUnavailable' => true,
			'cursor' => $index,
			'language' => '',
			'hashes' => [],
			'rowIds' => [],
			'legacyRowIds' => [],
		];
		if ( null === $lookup && false !== get_transient( self::BACKOFF ) ) {
			return $paused;
		}
		// Opt-in: prove several batches in one pass. One batch per pass means a
		// site with thousands of segments needs dozens of passes before its
		// pages can be reported ready, which is what makes the last stretch of
		// a first pass take longer than the rest of it.
		$per_pass = $all_batches ? count( $batches ) : max( 1, (int) apply_filters( 'translatewp_readiness_batches_per_pass', 1 ) );
		// A pass also stops once it has spent its time budget, so a scheduled
		// run never outlasts its slot whatever the site's size.
		$budget  = (float) apply_filters( 'translatewp_readiness_pass_seconds', 0.0 );
		$started = microtime( true );
		$answers = [];
		$missing_all = [];
		$row_ids_all = [];
		$last_language = '';
		for ( $pass = 0; $pass < $per_pass; $pass++ ) {
			if ( $pass > 0 && $budget > 0 && microtime( true ) - $started >= $budget ) {
				break;
			}
			$index = ( $index + ( $pass > 0 ? 1 : 0 ) ) % count( $batches );
			$batch = $batches[ $index ];
			$language = $batch['language'];
			$timeout = null === $work_budget ? SiteRequests::DEFAULT_TIMEOUT_SECONDS : $work_budget->remaining_seconds();
			if ( $timeout <= 0 || QueueExecution::expired() ) {
				$index = ( $index + count( $batches ) - 1 ) % count( $batches );
				break;
			}
			$items = null === $lookup
			? TranslationStore::remote_lookup_result( $language, array_keys( $batch['groups'] ), $timeout )
			: $lookup( $language, array_keys( $batch['groups'] ) );
			if ( is_wp_error( $items ) ) {
				$data = (array) $items->get_error_data();
				$wait = (int) ( $data['payload']['retryAfterSeconds'] ?? self::BACKOFF_DEFAULT_SECONDS );
				set_transient( self::BACKOFF, 1, max( 1, min( self::PROOF_TTL, $wait ) ) );
				return $paused;
			}
			$answers[ $language ] = (array) ( $answers[ $language ] ?? [] ) + (array) $items;
			$missing = [];
			$row_ids = [];
			$proofs = self::proofs( $language );
			$swept  = self::swept( $language );
			foreach ( $batch['groups'] as $hash => $markers ) {
				$row_ids = array_merge( $row_ids, $hash_rows[ $language ][ $hash ] ?? [] );
				$payload = $items[ $hash ] ?? null;
				$translated = is_string( $payload ) ? TranslationMarkerPayload::parse( $payload ) : [];
				$complete = is_string( $payload ) && ! is_wp_error( TranslationMarkerPayload::validate( $payload ) );
				foreach ( $markers as $marker ) {
					$complete = $complete && TranslationWhitespace::has_translatable_text( (string) ( $translated[ $marker ] ?? '' ) );
				}
				if ( $complete ) {
					$proofs[ $hash ] = [
						'markers' => self::marker_signature( $markers ),
						'checked' => time(),
					];
					unset( $swept[ $hash ] );
				} else {
					unset( $proofs[ $hash ] );
					$swept[ $hash ] = time();
					$missing[] = $hash;
				}
			}
			$scope = self::scope( $language );
			set_transient( 'twp_ready_' . $scope, array_slice( $proofs, -10000, null, true ), self::PROOF_GRACE_SECONDS );
			set_transient( 'twp_swept_' . $scope, array_slice( $swept, -10000, null, true ), self::PROOF_GRACE_SECONDS );
			$missing_all = array_merge( $missing_all, $missing );
			$row_ids_all = array_merge( $row_ids_all, $row_ids );
			$last_language = $language;
			if ( $pass + 1 >= count( $batches ) ) {
				break;
			}
		}

		return [
			'cursor' => ( $index + 1 ) % count( $batches ),
			'language' => $last_language,
			'hashes' => $missing_all,
			'rowIds' => array_values( array_unique( $row_ids_all ) ),
			'legacyRowIds' => 0 === $index ? array_values( array_unique( $legacy_rows ) ) : [],
			'answers' => $answers,
		];
	}

	/** How many look-up batches a full sweep of these rows takes. */
	public static function batch_count( array $rows ): int {
		$groups = 0;
		foreach ( $rows as $row ) {
			if ( IndexQueue::STATUS_DONE !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			$language = (string) $row['language'];
			if ( 'stale' === self::manifest_state( (int) $row['id'], $language ) ) {
				continue;
			}
			$groups += count( self::groups( (int) $row['id'], $language ) );
		}
		return (int) ceil( $groups / self::BATCH_SIZE );
	}

	private static function groups( int $id, string $language ): array {
		$manifest = get_post_meta( $id, self::META, true );
		$title_required = '' !== trim( (string) get_post_meta( $id, '_twp_page_title', true ) );
		return is_array( $manifest ) && self::matching_scope( $manifest, $language ) && is_array( $manifest['groups'] ?? null )
			&& ( ! $title_required || true === ( $manifest['title'] ?? false ) )
			? $manifest['groups'] : [];
	}

	private static function has_title( int $id, string $language ): bool {
		$manifest = get_post_meta( $id, self::META, true );
		return is_array( $manifest ) && self::matching_scope( $manifest, $language ) && true === ( $manifest['title'] ?? false );
	}

	private static function matching_scope( array $manifest, string $language ): bool {
		$current = self::scope( $language );
		if ( ( $manifest['scope'] ?? '' ) === $current ) {
			return true;
		}
		$aliases = (array) get_option( self::COMPLETED_SCOPES_OPTION, [] );
		$completed = $aliases[ $language ] ?? [];
		return ( $completed['current'] ?? '' ) === $current
			&& in_array( $manifest['scope'] ?? '', $completed['accepted'] ?? [], true );
	}

	private static function scope( string $language ): string {
		return hash(
            'sha256',
            TranslationFingerprint::stable_stringify(
                [
					Sites::registered_id(),
					TranslationProxySettings::default_locale(),
					$language,
					TranslationFingerprint::model_profile( TranslationProxySettings::ai_settings( $language ) ),
					TranslationStore::cache_generation_for( $language ),
					CacheVersions::settings_version(),
					CacheVersions::lexicon_version_for( $language ),
					CacheVersions::localizer_version(),
				]
            )
        );
	}

	private static function proofs( string $language ): array {
		$proofs = get_transient( 'twp_ready_' . self::scope( $language ) );
		return is_array( $proofs ) ? $proofs : [];
	}

	private static function swept( string $language ): array {
		$swept = get_transient( 'twp_swept_' . self::scope( $language ) );
		return is_array( $swept ) ? $swept : [];
	}

	private static function marker_signature( array $markers ): string {
		return hash( 'sha256', wp_json_encode( $markers ) );
	}
}
