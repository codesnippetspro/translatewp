<?php

namespace TranslateWP\Components;

class TranslationProxyDedupe {

	public static function window_seconds(): int {
		return 130;
	}

	public static function claim_segment( string $site_id, string $target_lang, string $segment_hash ) {
		return TranslationProxyStorage::claim_segment( $site_id, $target_lang, $segment_hash );
	}

	public static function release_segment( string $site_id, string $target_lang, string $segment_hash ): void {
		TranslationProxyStorage::release_segment( $site_id, $target_lang, $segment_hash );
	}

	public static function release_segments( string $site_id, string $target_lang, array $segment_hashes ): void {
		TranslationProxyStorage::release_segments( $site_id, $target_lang, $segment_hashes );
	}
}
