<?php

namespace TranslateWP\Components;

/**
 * Writes the private metadata of up to MAX_ROWS index queue rows in a fixed
 * number of statements instead of one update_post_meta() call per key.
 *
 * Stored values match update_post_meta(): arrays and objects through
 * maybe_serialize(), true as '1', false as '', other scalars as strings.
 * Values are stored as given, so callers must not wp_slash() them; null is
 * rejected. An existing key is set on every row it already has (duplicates
 * included), a missing key gets exactly one row. No meta hooks fire.
 */
class QueueMetadata {

	public const MAX_ROWS = 50;
	private const STATUS  = 'private';
	// Escaping can double a value, so this keeps one statement near 1 MB.
	private const STATEMENT_BYTES = 400000;

	/**
	 * Sets the given keys on each queue row and deletes the removed keys.
	 *
	 * @param array<int, array<string, mixed>> $rows   Queue post ID => [ meta key => value ].
	 * @param string[]                         $remove Meta keys deleted from the same IDs.
	 */
	public static function write( array $rows, array $remove = [] ): bool {
		if ( count( $rows ) > self::MAX_ROWS ) {
			return false;
		}
		$pairs   = [];
		$written = [];
		foreach ( $rows as $id => $meta ) {
			if ( ! is_int( $id ) || $id <= 0 || ! is_array( $meta ) ) {
				return false;
			}
			foreach ( $meta as $key => $value ) {
				$stored = self::stored( $value );
				if ( ! self::valid_key( $key ) || null === $stored ) {
					return false;
				}
				$pairs[ $id ][ $key ] = $stored;
				$written[ $key ]      = true;
			}
		}
		foreach ( $remove as $key ) {
			if ( ! self::valid_key( $key ) || isset( $written[ $key ] ) ) {
				return false;
			}
		}
		$remove = array_values( array_unique( $remove ) );
		if ( ! $pairs && ! ( $rows && $remove ) ) {
			return true;
		}

		global $wpdb;
		$ids = array_map( 'intval', array_keys( $rows ) );
		// The concatenated SQL holds only $wpdb table names and placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . self::placeholders( '%d', count( $ids ) ) . ') AND post_type = %s AND post_status = %s',
				array_merge( $ids, [ IndexQueue::POST_TYPE, self::STATUS ] )
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		$ids   = array_values( array_intersect( $ids, array_map( 'intval', (array) $found ) ) );
		$pairs = array_intersect_key( $pairs, array_flip( $ids ) );
		if ( ! $ids || ( ! $pairs && ! $remove ) ) {
			return true;
		}

		$scope = [ IndexQueue::POST_TYPE, self::STATUS ];
		$ok    = true;
		try {
			if ( $remove ) {
				$ok = false !== $wpdb->query(
					$wpdb->prepare(
						"DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.post_id IN (" . self::placeholders( '%d', count( $ids ) ) . ') AND pm.meta_key IN (' . self::placeholders( '%s', count( $remove ) ) . ') AND p.post_type = %s AND p.post_status = %s',
						array_merge( $ids, $remove, $scope )
					)
				);
			}
			$chunks = self::chunks( $pairs );
			foreach ( $chunks as $chunk ) {
				if ( ! $ok ) {
					break;
				}
				$selects = [];
				$args    = [];
				foreach ( $chunk as $pair ) {
					$selects[] = "SELECT p.ID, %s, %s FROM {$wpdb->posts} p WHERE p.ID = %d AND p.post_type = %s AND p.post_status = %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = %d AND pm.meta_key = %s)";
					array_push( $args, $pair[1], $pair[2], $pair[0], $scope[0], $scope[1], $pair[0], $pair[1] );
				}
				$ok = false !== $wpdb->query(
					$wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) " . implode( ' UNION ALL ', $selects ), $args )
				);
			}
			foreach ( $chunks as $chunk ) {
				if ( ! $ok ) {
					break;
				}
				$cases = str_repeat( ' WHEN pm.post_id = %d AND pm.meta_key = %s THEN %s', count( $chunk ) );
				$args  = array_merge( ...$chunk );
				$keys  = array_values( array_unique( array_column( $chunk, 1 ) ) );
				$in    = array_values( array_unique( array_column( $chunk, 0 ) ) );
				$ok    = false !== $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id SET pm.meta_value = CASE{$cases} ELSE pm.meta_value END WHERE pm.post_id IN (" . self::placeholders( '%d', count( $in ) ) . ') AND pm.meta_key IN (' . self::placeholders( '%s', count( $keys ) ) . ') AND p.post_type = %s AND p.post_status = %s',
						array_merge( $args, $in, $keys, $scope )
					)
				);
			}
		} finally {
			foreach ( $ids as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}
			// Core bumps this on every meta write so cached post queries refresh.
			wp_cache_set_posts_last_changed();
		}
		// phpcs:enable

		return $ok;
	}

	private static function valid_key( $key ): bool {
		return is_string( $key ) && strlen( $key ) <= 255 && 1 === preg_match( '/^_twp_[a-z0-9_]+$/', $key );
	}

	private static function stored( $value ): ?string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_array( $value ) || is_object( $value ) || is_string( $value ) ) {
			return (string) maybe_serialize( $value );
		}
		return is_int( $value ) || is_float( $value ) ? (string) $value : null;
	}

	/**
	 * Splits [ id, key, value ] triples into statements of at most STATEMENT_BYTES;
	 * one oversized value still gets its own statement.
	 *
	 * @param array<int, array<string, string>> $pairs Queue post ID => [ meta key => stored value ].
	 * @return array<int, array<int, array{0:int,1:string,2:string}>>
	 */
	private static function chunks( array $pairs ): array {
		$chunks = [];
		$chunk  = [];
		$bytes  = 0;
		foreach ( $pairs as $id => $meta ) {
			foreach ( $meta as $key => $value ) {
				$size = strlen( $key ) + strlen( $value );
				if ( $chunk && $bytes + $size > self::STATEMENT_BYTES ) {
					$chunks[] = $chunk;
					$chunk    = [];
					$bytes    = 0;
				}
				$chunk[] = [ (int) $id, (string) $key, $value ];
				$bytes  += $size;
			}
		}
		if ( $chunk ) {
			$chunks[] = $chunk;
		}
		return $chunks;
	}

	private static function placeholders( string $placeholder, int $count ): string {
		return implode( ',', array_fill( 0, $count, $placeholder ) );
	}
}
