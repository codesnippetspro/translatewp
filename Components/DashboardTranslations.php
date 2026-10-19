<?php

namespace TranslateWP\Components;

class DashboardTranslations {

	private const BAR_COLORS = [ '#0e3ecd', '#E08E3C', '#008000', '#9B59B6', '#E74C3C', '#1ABC9C', '#F39C12', '#5B8DEF' ];

	private const RECENT_LIMIT = 10;

	public static function summary( IndexQueue $queue, array $languages_config, int $now ): array {
		$locale_names = self::locale_names( $languages_config );
		$pages        = self::distinct_pages( array_filter( $queue->list_rows(), [ TranslationReadiness::class, 'is_ready' ] ) );

		$cutoff             = $now - DAY_IN_SECONDS;
		$translations_today = 0;
		$by_language        = [];

		foreach ( $pages as $page ) {
			$language = (string) $page['language'];

			if ( (int) $page['updated'] >= $cutoff ) {
				$translations_today++;
			}

			$by_language[ $language ] = ( $by_language[ $language ] ?? 0 ) + 1;
		}

		return [
			'translationsToday' => $translations_today,
			'languageUsage'     => self::language_usage( $by_language, $locale_names ),
			'recentContent'     => self::recent_content( $pages, $locale_names ),
		];
	}

	/**
	 * Collapse finished rows to one entry per page and language, keeping the
	 * most recent completion, so a page left with both a warm-up row and a
	 * visitor-miss row in one language still counts as a single translated page.
	 */
	private static function distinct_pages( array $rows ): array {
		$pages = [];

		foreach ( $rows as $row ) {
			$language = (string) $row['language'];
			if ( '' === $language ) {
				continue;
			}

			$key = (string) $row['url'] . '|' . $language;
			if ( ! isset( $pages[ $key ] ) || (int) $row['updated'] > (int) $pages[ $key ]['updated'] ) {
				$pages[ $key ] = $row;
			}
		}

		return array_values( $pages );
	}

	private static function locale_names( array $languages_config ): array {
		$locale_names = [];
		foreach ( $languages_config as $language ) {
			if ( is_array( $language ) && ! empty( $language['locale'] ) ) {
				$locale_names[ $language['locale'] ] = $language['name'] ?? $language['locale'];
			}
		}

		return $locale_names;
	}

	private static function language_usage( array $by_language, array $locale_names ): array {
		arsort( $by_language );
		$total = array_sum( $by_language );

		$usage       = [];
		$color_index = 0;
		foreach ( $by_language as $locale => $count ) {
			$usage[] = [
				'locale'       => $locale,
				'name'         => $locale_names[ $locale ] ?? strtoupper( (string) $locale ),
				'translations' => (int) $count,
				'share'        => $total > 0 ? (int) round( $count / $total * 100 ) : 0,
				'barColor'     => self::BAR_COLORS[ $color_index % count( self::BAR_COLORS ) ],
			];
			$color_index++;
		}

		return $usage;
	}

	private static function recent_content( array $pages, array $locale_names ): array {
		usort(
			$pages,
			static function ( $left, $right ): int {
				return (int) $right['updated'] <=> (int) $left['updated'];
			}
		);

		$recent = [];
		foreach ( array_slice( $pages, 0, self::RECENT_LIMIT ) as $page ) {
			$language = (string) $page['language'];
			$url      = (string) $page['url'];
			$title    = '' !== trim( (string) $page['page_title'] ) ? PlainText::decode( (string) $page['page_title'] ) : $url;

			$recent[] = [
				'page'     => $title,
				'locale'   => $language,
				'language' => $locale_names[ $language ] ?? strtoupper( $language ),
				'href'     => '' !== $url ? $url : '#',
			];
		}

		return $recent;
	}
}
