<?php

namespace TranslateWP\Functionality;

use TranslateWP\Components\Audience;
use TranslateWP\Components\CrawlerDetection;
use TranslateWP\Components\CrawlerTranslation;
use TranslateWP\Components\LanguageUrl;
use TranslateWP\Components\PageExclusion;
use TranslateWP\Components\PageTextTranslator;
use TranslateWP\Components\ProEligibility;
use TranslateWP\Components\TranslationStore;

class CrawlerResponse {

	public const RETRY_AFTER_SECONDS = 600;

	private static ?bool $applies = null;

	/**
	 * Headers this response sent, by name.
	 *
	 * @var array<string,string>
	 */
	private static array $sent_headers = [];

	public function __construct( $plugin_name, $plugin_version ) {
		unset( $plugin_name, $plugin_version );

		// After the routing redirects of priority 0, before any template output.
		add_action( 'template_redirect', [ $this, 'start' ], 1 );
	}

	/** Whether this request is a crawler asking for a page in a translation language. */
	public static function is_active(): bool {
		if ( null === self::$applies ) {
			$method        = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
			self::$applies = '' !== LanguageUrl::requested_locale()
				&& in_array( $method, [ 'GET', 'HEAD' ], true )
				&& CrawlerDetection::is_crawler_request()
				&& ! PageExclusion::is_current_page_excluded()
				&& Audience::allows_current_user();
		}

		return self::$applies;
	}

	public function applies(): bool {
		return self::is_active();
	}

	/** Forgets the verdict and the headers of the last request. For tests. */
	public static function reset(): void {
		self::$applies      = null;
		self::$sent_headers = [];
	}

	/**
	 * The headers this response sent, by name.
	 *
	 * @return array<string,string>
	 */
	public static function sent_headers(): array {
		return self::$sent_headers;
	}

	public function start(): void {
		if ( self::is_active() ) {
			ob_start( [ $this, 'render' ] );
		}
	}

	/** The finished page for the crawler, from the page WordPress rendered. */
	public function render( string $html ): string {
		$locale  = LanguageUrl::requested_locale();
		$is_404  = function_exists( 'is_404' ) && is_404();
		$post_id = $is_404 ? Indexer::NOT_FOUND_OBJECT_ID : ( ! function_exists( 'is_singular' ) || is_singular() ? (int) get_queried_object_id() : 0 );
		if ( 0 === $post_id && function_exists( 'is_home' ) && is_home() ) {
			$post_id = (int) get_option( 'page_for_posts', 0 );
		}
		if ( 0 === $post_id && function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
			$post_id = max( 0, (int) wc_get_page_id( 'shop' ) );
		}
		$source  = $post_id > 0
			? (string) get_permalink( $post_id )
			: ( $is_404 ? home_url( '/' . Indexer::NOT_FOUND_PATH . '/' ) : home_url( '/' ) );

		// First the whole page from the service's cache, the site's header and
		// footer included; failing that, what this page's own store holds.
		if ( 0 === $post_id ) {
			$home = wp_parse_url( home_url( '/' ) );
			$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$source = LanguageUrl::source( ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . '/' . ltrim( $path, '/' ) );
		}
		$map = CrawlerTranslation::map( $post_id, $locale, null, $source );
		$indexed = ! empty( $map );
		if ( ! $map ) {
			TranslationStore::resolve_pending( $source, $post_id, $locale );
			$map = 0 === TranslationStore::pending_count( $source, $locale, $post_id )
				? TranslationStore::for_url( $source, $locale, $post_id )
				: [];
		} else {
			// The head the SEO plugin prints now (title, description) can differ
			// from the one last read; what visitors reported since is in the store.
			$map += TranslationStore::for_url( $source, $locale, $post_id );
		}

		if ( $map ) {
			// Alt text and the description tags are Pro translations; a Terrestrial crawler page keeps them as written.
			$pro        = ProEligibility::unlocks_pro_features();
			$attributes = $pro
				? PageTextTranslator::TRANSLATED_ATTRIBUTES
				: array_values( array_diff( PageTextTranslator::TRANSLATED_ATTRIBUTES, [ 'alt' ] ) );
			$meta       = $pro ? PageTextTranslator::TRANSLATED_META : [ 'og:title', 'twitter:title' ];
			$translated = PageTextTranslator::translate( $html, $map, $attributes, $meta );
			if ( ( $indexed || 0 === $translated['missing'] ) && $translated['translated'] > 0 ) {
				return $translated['html'];
			}
		}

		return $this->original_page( $html, $source );
	}

	/**
	 * The page as written, saying so: its canonical is the source address,
	 * its language is the default one, and the crawler is asked to come back.
	 */
	private function original_page( string $html, string $source ): string {
		$this->send_header( 'Retry-After', (string) (int) apply_filters( 'translatewp_crawler_retry_after', self::RETRY_AFTER_SECONDS ) );

		$canonical = '<link rel="canonical" href="' . esc_url( $source ) . '" />';
		$html      = (string) preg_replace( '/<link\b[^>]*\brel=["\']canonical["\'][^>]*>\s*/i', '', $html );
		$html      = false !== stripos( $html, '</head>' )
			? (string) preg_replace( '/<\/head>/i', $canonical . "\n</head>", $html, 1 )
			: $canonical . $html;

		$default = LanguageUrl::default_locale();
		if ( '' !== $default ) {
			$html = (string) preg_replace(
				'/(<html\b[^>]*\blang=)(["\'])[^"\']*\2/i',
				'${1}${2}' . esc_attr( $default ) . '${2}',
				$html,
				1
			);
		}

		return $html;
	}

	private function send_header( string $name, string $value ): void {
		self::$sent_headers[ $name ] = $value;
		if ( ! headers_sent() ) {
			header( $name . ': ' . $value );
		}
	}
}
