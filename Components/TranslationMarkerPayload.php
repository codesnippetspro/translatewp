<?php

namespace TranslateWP\Components;

class TranslationMarkerPayload {

	private const MARKER_PATTERN = '/⟪([^⟫]+)⟫/u';

	/**
	 * Split a marker payload into [ id => text ] entries. Byte offsets from the
	 * marker matches index the same string strlen/substr use, so multibyte
	 * marker glyphs and multibyte text both survive the slice.
	 *
	 * @return array<string,string>
	 */
	public static function parse( string $marker_payload ): array {
		if ( '' === $marker_payload
			|| ! preg_match_all( self::MARKER_PATTERN, $marker_payload, $matches, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$markers      = $matches[0];
		$ids          = $matches[1];
		$marker_count = count( $markers );
		$entries      = [];

		for ( $index = 0; $index < $marker_count; $index++ ) {
			$id = (string) ( $ids[ $index ][0] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$marker_text  = (string) $markers[ $index ][0];
			$marker_start = (int) $markers[ $index ][1];
			$value_start  = $marker_start + strlen( $marker_text );
			$value_end    = ( $index + 1 < $marker_count )
				? (int) $markers[ $index + 1 ][1]
				: strlen( $marker_payload );

			$entries[ $id ] = substr( $marker_payload, $value_start, $value_end - $value_start );
		}

		return $entries;
	}

	public static function validate( string $marker_payload ) {
		if ( '' === $marker_payload ) {
			return new \WP_Error(
				'invalid_marker_payload',
				'Translation group must contain marker-prefixed entries.',
				[ 'status' => 400 ]
			);
		}

		if ( ! preg_match_all( self::MARKER_PATTERN, $marker_payload, $matches, PREG_OFFSET_CAPTURE ) ) {
			return new \WP_Error(
				'invalid_marker_payload',
				'Translation group must contain marker-prefixed entries.',
				[ 'status' => 400 ]
			);
		}

		$markers = $matches[0];
		$ids     = $matches[1];

		if ( ! isset( $markers[0][1] ) || 0 !== (int) $markers[0][1] ) {
			return new \WP_Error(
				'invalid_marker_payload',
				'Translation group must contain marker-prefixed entries.',
				[ 'status' => 400 ]
			);
		}

		$seen_ids     = [];
		$marker_count = count( $markers );

		for ( $index = 0; $index < $marker_count; $index++ ) {
			$id = (string) ( $ids[ $index ][0] ?? '' );
			if ( '' === $id ) {
				return new \WP_Error(
					'invalid_marker_payload',
					'Translation group must contain marker-prefixed entries.',
					[ 'status' => 400 ]
				);
			}

			if ( isset( $seen_ids[ $id ] ) ) {
				return new \WP_Error(
					'invalid_marker_payload',
					sprintf( 'Translation group contains duplicate ID "%s".', $id ),
					[ 'status' => 400 ]
				);
			}
			$seen_ids[ $id ] = true;

			$marker_text  = (string) $markers[ $index ][0];
			$marker_start = (int) $markers[ $index ][1];
			$value_start  = $marker_start + strlen( $marker_text );
			$value_end    = ( $index + 1 < $marker_count )
				? (int) $markers[ $index + 1 ][1]
				: strlen( $marker_payload );
			$entry_text   = substr( $marker_payload, $value_start, $value_end - $value_start );

			if ( ! TranslationWhitespace::has_translatable_text( $entry_text ) ) {
				return new \WP_Error(
					'invalid_marker_payload',
					sprintf( 'Translation group entry "%s" is empty.', $id ),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}
}
