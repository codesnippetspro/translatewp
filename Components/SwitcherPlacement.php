<?php

namespace TranslateWP\Components;

/**
 * Where the switcher sits inside the document when it is placed inline: an
 * anchor, a CSS path plus the side of that element the switcher sits on.
 * Stored as a reproducible description, never as viewport coordinates, so it
 * survives across page loads. A page placement wins over the site-wide one
 * while it exists. Zones and the prepend and append positions are read for
 * placements stored by earlier versions.
 */
class SwitcherPlacement {

	public const PAGE_META_KEY = '_translatewp_switcher_placement';

	public const ZONES = [ 'header', 'before_content', 'after_content', 'sidebar', 'footer' ];

	public const ANCHOR_POSITIONS = [ 'before', 'after', 'left', 'right', 'prepend', 'append' ];

	public const ANCHOR_FALLBACKS = [ 'floating', 'none' ];

	public const ANCHOR_FALLBACK_KEY = 'anchor_fallback';

	private const MAX_SELECTOR_LENGTH = 1024;

	public const TYPES = [ 'dropdown', 'native_select', 'side_tab' ];

	public const SIDE_TAB = 'side_tab';

	public const SIDE_TAB_EDGES = [ 'top', 'right', 'bottom', 'left' ];

	/** A phone-width screen keeps the top edge for the site's own header. */
	public const SIDE_TAB_PHONE_EDGES = [ 'right', 'bottom', 'left' ];

	/** The stored places each type is dragged to, wide screen first. */
	public const POSITION_KEYS = [
		'floating' => [ 'floating_position', 'floating_position_mobile' ],
		'side_tab' => [ 'side_tab_position', 'side_tab_position_mobile' ],
	];

	/**
	 * The type the owner chose, whether or not the plan serves it now; the
	 * dropdown for anything unknown.
	 *
	 * @param mixed $switcher The stored switcher settings.
	 */
	public static function selected_type( $switcher ): string {
		$type = is_array( $switcher ) ? ( $switcher['type'] ?? 'dropdown' ) : 'dropdown';

		return in_array( $type, self::TYPES, true ) ? $type : 'dropdown';
	}

	/**
	 * The switcher type the site renders: a stored Side tab is the dropdown
	 * unless Pro unlocks it, and the stored choice is kept for a renewal.
	 *
	 * @param mixed $switcher The stored switcher settings.
	 */
	public static function effective_type( $switcher ): string {
		$type = self::selected_type( $switcher );
		if ( self::SIDE_TAB === $type && ! ProEligibility::unlocks_pro_features() ) {
			return 'dropdown';
		}

		return $type;
	}

	/**
	 * A Side tab place from a request: an edge and the share of its length
	 * at the tab's centre, 0 to 100; null when it is not one.
	 *
	 * @param mixed $value Raw request value.
	 * @param bool  $phone Whether it is the phone-width place.
	 */
	public static function sanitize_side_tab_position( $value, bool $phone ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['edge'] ?? null ) ) {
			return null;
		}
		$edge = sanitize_key( $value['edge'] );
		if ( ! in_array( $edge, $phone ? self::SIDE_TAB_PHONE_EDGES : self::SIDE_TAB_EDGES, true ) ) {
			return null;
		}
		$raw = $value['offset'] ?? null;
		if ( ! is_scalar( $raw ) || is_bool( $raw ) || ! is_numeric( $raw ) || ! is_finite( (float) $raw ) ) {
			return null;
		}

		// A tenth of a percent, stored as a whole number when it is one.
		$offset = round( min( 100, max( 0, (float) $raw ) ), 1 );

		return [
			'edge'   => $edge,
			'offset' => floor( $offset ) === $offset ? (int) $offset : $offset,
		];
	}

	/**
	 * Accepts a placement from a request; null when it is not a placement.
	 *
	 * @param mixed $value Raw request value.
	 */
	public static function sanitize( $value ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['kind'] ?? null ) ) {
			return null;
		}
		$kind = sanitize_key( $value['kind'] );
		if ( 'zone' === $kind ) {
			$zone = sanitize_key( (string) ( $value['zone'] ?? '' ) );
			return in_array( $zone, self::ZONES, true ) ? [
				'kind' => 'zone',
				'zone' => $zone,
			] : null;
		}
		if ( 'anchor' === $kind ) {
			$selector = is_string( $value['selector'] ?? null ) ? trim( $value['selector'] ) : '';
			$position = sanitize_key( (string) ( $value['position'] ?? '' ) );
			if ( '' === $selector || mb_strlen( $selector ) > self::MAX_SELECTOR_LENGTH || ! in_array( $position, self::ANCHOR_POSITIONS, true ) ) {
				return null;
			}
			// A selector is a path of tags, ids, classes and nth-of-type steps;
			// anything else (quotes, brackets, parentheses beyond nth) is refused.
			if ( ! preg_match( '/^[A-Za-z0-9_\-#.:>()\s]+$/', $selector ) ) {
				return null;
			}
			return [
				'kind' => 'anchor',
				'selector' => $selector,
				'position' => $position,
			];
		}
		return null;
	}

	public static function site_placement(): ?array {
		$switcher = Options::get( 'languages.switcher', [] );
		$placement = is_array( $switcher ) ? ( $switcher['inline_placement'] ?? null ) : null;
		return self::sanitize( $placement );
	}

	public static function save_site_placement( ?array $placement ): void {
		$switcher = Options::get( 'languages.switcher', [] );
		$switcher = is_array( $switcher ) ? $switcher : [];
		if ( null === $placement ) {
			unset( $switcher['inline_placement'] );
		} else {
			$switcher['inline_placement'] = $placement;
		}
		Options::update( 'languages.switcher', $switcher );
	}

	/** What a page shows when the stored anchor matches none of its elements. */
	public static function anchor_fallback(): string {
		$switcher = Options::get( 'languages.switcher', [] );
		$value    = is_array( $switcher ) ? sanitize_key( (string) ( $switcher[ self::ANCHOR_FALLBACK_KEY ] ?? '' ) ) : '';

		return in_array( $value, self::ANCHOR_FALLBACKS, true ) ? $value : 'floating';
	}

	public static function save_anchor_fallback( string $value ): void {
		$value = sanitize_key( $value );
		if ( ! in_array( $value, self::ANCHOR_FALLBACKS, true ) ) {
			return;
		}
		$switcher = Options::get( 'languages.switcher', [] );
		$switcher = is_array( $switcher ) ? $switcher : [];
		$switcher[ self::ANCHOR_FALLBACK_KEY ] = $value;
		Options::update( 'languages.switcher', $switcher );
	}

	public static function page_placement( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}
		return self::sanitize( get_post_meta( $post_id, self::PAGE_META_KEY, true ) );
	}

	public static function save_page_placement( int $post_id, ?array $placement ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		if ( null === $placement ) {
			delete_post_meta( $post_id, self::PAGE_META_KEY );
			return;
		}
		update_post_meta( $post_id, self::PAGE_META_KEY, $placement );
	}
}
