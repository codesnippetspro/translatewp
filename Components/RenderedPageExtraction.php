<?php

namespace TranslateWP\Components;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM properties use camelCase.

class RenderedPageExtraction {

	private const BLOCK_TAGS = [ 'address', 'article', 'aside', 'blockquote', 'details', 'dialog', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'td', 'th', 'ul' ];
	private const SKIP_TAGS = [ 'script', 'style', 'noscript', 'template', 'code', 'kbd', 'samp', 'var', 'svg', 'math' ];
	private const ATTRIBUTES = [ 'title', 'placeholder', 'aria-label', 'aria-description', 'aria-roledescription', 'aria-valuetext', 'label', 'caption', 'summary', 'value' ];
	private const ADMIN_IDS = [ 'wpadminbar', 'query-monitor-main', 'query-monitor', 'debug-bar', 'querylist', 'error-page' ];
	private const ADMIN_CLASSES = [ 'post-edit-link', 'comment-edit-link', 'qm', 'xdebug-error', 'php-error', 'wp-die-message', 'translatewp-no-translate' ];
	private const MAX_PAYLOAD_LENGTH = 60000;
	private const MAX_CONTEXT_LENGTH = 1024;
	private const DIAGNOSTIC = '~^\s*(?:PHP\s+)?(?:Deprecated|Warning|Notice|Fatal error|Parse error|Strict Standards|Recoverable fatal error):[^\r\n]*\s+in\s+(?:/|[A-Z]:[\\\\/])[^\r\n]+\s+on line\s+\d+[.!]?\s*$~i';
	private const SEVERITY = '/^(?:PHP\s+)?(?:Deprecated|Warning|Notice|Fatal error|Parse error|Strict Standards|Recoverable fatal error)$/i';

	public static function extract( string $html ): array {
		if ( ! class_exists( '\DOMDocument' ) ) {
			throw new \RuntimeException( 'page_parser_unavailable' );
		}
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		try {
			$loaded = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded ) {
			throw new \RuntimeException( 'page_not_html' );
		}
		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return [];
		}
		$settings = Options::get( 'translations.settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$excluded = PageAreas::excluded( $settings );
		$xpath = new \DOMXPath( $document );
		$groups = [];
		$values = [];
		$context = '';
		$diagnostics = [];
		foreach ( $xpath->query( './/text()', $body ) as $node ) {
			$value = $node->nodeValue;
			if ( ! PlainText::has_letters( $value ) || self::excluded( $node, $excluded, true )
				|| self::diagnostic_node( $node, $diagnostics ) || preg_match( self::DIAGNOSTIC, $value ) ) {
				continue;
			}
			$key = self::context_key( $node->parentNode );
			$previous_value = $values ? $values[ count( $values ) - 1 ] : '';
			$separate = $context !== $key || preg_match( '/\n{2,}/', $previous_value . "\0" . $value )
				|| ( preg_match( '/[.!?]["\')\]]?$/', rtrim( $previous_value ) ) && preg_match( '/^[A-Z0-9]/', ltrim( $value ) ) );
			if ( $values && $separate ) {
				self::append_groups( $groups, $values, $context );
				$values = [];
			}
			$values[] = $value;
			$context = $key;
		}
		self::append_groups( $groups, $values, $context );
		if ( ( $settings['translate_image_alt_tags'] ?? true ) && ProEligibility::unlocks_pro_features() ) {
			foreach ( $xpath->query( './/img[@alt]', $body ) as $element ) {
				self::append_attribute( $groups, $element, 'alt', $excluded, $diagnostics );
			}
		}
		foreach ( empty( $settings['preserve_html_attributes'] ) ? $xpath->query( './/*', $body ) : [] as $element ) {
			foreach ( self::ATTRIBUTES as $attribute ) {
				if ( $element->hasAttribute( $attribute ) ) {
					self::append_attribute( $groups, $element, $attribute, $excluded, $diagnostics );
				}
			}
		}
		$title = $document->getElementsByTagName( 'title' )->item( 0 );
		if ( $title && PlainText::has_letters( $title->textContent ) ) {
			$groups[] = [
				'groupId' => 'seo:seo:title',
				'markerPayload' => '⟪item_0001⟫' . $title->textContent,
				'contextKey' => 'seo:title',
				'contextKind' => 'attribute',
			];
		}
		$meta_contexts = [ 'og:title' => 'seo:title', 'twitter:title' => 'seo:title' ];
		if ( ProEligibility::unlocks_pro_features() ) {
			$meta_contexts += [ 'description' => 'seo:meta_description', 'og:description' => 'seo:og_description', 'twitter:description' => 'seo:twitter_description' ];
		}
		foreach ( $document->getElementsByTagName( 'meta' ) as $meta ) {
			$name = strtolower( $meta->getAttribute( 'name' ) );
			if ( '' === $name ) {
				$name = strtolower( $meta->getAttribute( 'property' ) );
			}
			$value = trim( $meta->getAttribute( 'content' ) );
			if ( isset( $meta_contexts[ $name ] ) && PlainText::has_letters( $value ) ) {
				$groups[] = [
					'groupId' => 'seo:' . $meta_contexts[ $name ],
					'markerPayload' => '⟪item_0001⟫' . $value,
					'contextKey' => $meta_contexts[ $name ],
					'contextKind' => 'attribute',
				];
			}
		}
		return $groups;
	}

	private static function append_groups( array &$groups, array $values, string $context ): void {
		if ( ! $values ) {
			return;
		}
		$collapsed = array_map( static fn( string $value ): string => trim( (string) preg_replace( '/\s+/u', ' ', $value ) ), $values );
		if ( count( $values ) > 1 && 1 === count( array_unique( $collapsed ) ) ) {
			$values = $collapsed;
		}
		$payload = '';
		$seen = [];
		foreach ( $values as $value ) {
			if ( isset( $seen[ $value ] ) ) {
				continue;
			}
			$part = '⟪item_' . str_pad( (string) ( count( $seen ) + 1 ), 4, '0', STR_PAD_LEFT ) . '⟫' . $value;
			if ( '' !== $payload && strlen( mb_convert_encoding( $payload . $part, 'UTF-16LE', 'UTF-8' ) ) / 2 > self::MAX_PAYLOAD_LENGTH ) {
				$groups[] = self::group( $payload, $context, 'text-flow', count( $groups ) );
				$payload = '';
				$seen = [];
				$part = '⟪item_0001⟫' . $value;
			}
			$seen[ $value ] = true;
			$payload .= $part;
		}
		if ( '' !== $payload ) {
			$groups[] = self::group( $payload, $context, 'text-flow', count( $groups ) );
		}
	}

	private static function append_attribute( array &$groups, \DOMElement $element, string $attribute, array $excluded, array &$diagnostics ): void {
		$value = $element->getAttribute( $attribute );
		if ( ! PlainText::has_letters( $value ) || self::excluded( $element, $excluded, false ) || self::diagnostic_node( $element, $diagnostics ) ) {
			return;
		}
		if ( 'value' === $attribute && ( 'input' !== $element->tagName || ! in_array( strtolower( $element->getAttribute( 'type' ) ), [ 'submit', 'button', 'reset' ], true ) ) ) {
			return;
		}
		$groups[] = self::group( '⟪item_0001⟫' . $value, self::context_key( $element ) . '@' . $attribute, 'alt' === $attribute ? 'alt' : 'attribute', count( $groups ) );
	}

	private static function group( string $payload, string $context, string $kind, int $index ): array {
		return [
			'groupId' => 'grp_' . str_pad( (string) ( $index + 1 ), 8, '0', STR_PAD_LEFT ),
			'markerPayload' => $payload,
			'contextKey' => mb_substr( $context, 0, self::MAX_CONTEXT_LENGTH ),
			'contextKind' => $kind,
		];
	}

	private static function context_key( \DOMNode $node ): string {
		while ( $node->parentNode && ! in_array( strtolower( $node->nodeName ), self::BLOCK_TAGS, true ) && 'body' !== $node->nodeName ) {
			$node = $node->parentNode;
		}
		$parts = [];
		while ( $node instanceof \DOMElement && 'body' !== $node->tagName && $node->parentNode ) {
			$index = 1;
			for ( $sibling = $node->previousSibling; $sibling; $sibling = $sibling->previousSibling ) {
				if ( $sibling->nodeName === $node->nodeName ) {
					$index++;
				}
			}
			array_unshift( $parts, $node->tagName . ':nth-of-type(' . $index . ')' );
			$node = $node->parentNode;
		}
		return $parts ? implode( ' > ', $parts ) : 'body';
	}

	private static function excluded( \DOMNode $node, array $areas, bool $text ): bool {
		for ( $element = $node instanceof \DOMElement ? $node : $node->parentNode; $element instanceof \DOMElement; $element = $element->parentNode ) {
			$tag = strtolower( $element->tagName );
			$classes = preg_split( '/\s+/', $element->getAttribute( 'class' ) );
			$id = $element->getAttribute( 'id' );
			if ( ( $text && in_array( $tag, self::SKIP_TAGS, true ) )
				|| 'no' === $element->getAttribute( 'translate' ) || $element->hasAttribute( 'data-no-translate' )
				|| in_array( $id, self::ADMIN_IDS, true ) || array_intersect( self::ADMIN_CLASSES, $classes ) ) {
				return true;
			}
			if ( in_array( 'menus', $areas, true ) && ( 'nav' === $tag || 'navigation' === $element->getAttribute( 'role' ) || 'site-navigation' === $id || array_intersect( [ 'wp-block-navigation', 'menu', 'nav-menu', 'main-navigation' ], $classes ) ) ) {
				return true;
			}
			if ( in_array( 'comments', $areas, true ) && ( in_array( $id, [ 'comments', 'respond' ], true ) || array_intersect( [ 'comments-area', 'comment-list', 'commentlist', 'wp-block-comments', 'wp-block-post-comments', 'comment-respond', 'wp-block-post-comments-form' ], $classes ) ) ) {
				return true;
			}
			if ( in_array( 'widgets', $areas, true ) && ( 'secondary' === $id || array_intersect( [ 'widget', 'widget-area', 'wp-block-widget-area', 'sidebar', 'wp-block-legacy-widget' ], $classes ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function diagnostic_node( \DOMNode $node, array &$parents ): bool {
		for ( $child = $node; $child->parentNode; $child = $child->parentNode ) {
			$parent = $child->parentNode;
			$key = $parent->getNodePath();
			if ( ! isset( $parents[ $key ] ) ) {
				$marked = [];
				$run = [];
				$finish = static function () use ( &$run, &$marked ): void {
					$text = implode( '', array_map( static fn( \DOMNode $item ): string => $item->textContent, $run ) );
					if ( preg_match( self::DIAGNOSTIC, (string) preg_replace( '/\s+/u', ' ', $text ) ) ) {
						foreach ( $run as $item ) {
							$marked[ $item->getNodePath() ] = true;
						}
					}
					$run = [];
				};
				$active = false;
				foreach ( $parent->childNodes as $item ) {
					if ( 'br' === $item->nodeName ) {
						$finish();
						$active = false;
						continue;
					}
					if ( in_array( $item->nodeName, [ 'b', 'strong' ], true ) && preg_match( self::SEVERITY, trim( $item->textContent ) )
						&& $item->nextSibling instanceof \DOMText && preg_match( '/^\s*:/', $item->nextSibling->textContent ) ) {
						$finish();
						$active = true;
					}
					if ( $active ) {
						$run[] = $item;
					}
				}
				$finish();
				$parents[ $key ] = $marked;
			}
			if ( isset( $parents[ $key ][ $child->getNodePath() ] ) ) {
				return true;
			}
		}
		return false;
	}
}
