<?php

namespace TranslateWP\Components;

/**
 * Clears the page caches the plugin knows once translations are ready to be
 * served: when the wizard finishes and when a language completes its first
 * pass. Each cache is addressed through its own public function or action
 * when that plugin is present; the generic action lets any other cache hook
 * in.
 */
class PageCachePurge {

	public const ACTION = 'translatewp_page_cache_purged';

	public static function purge_all( string $reason = '' ): void {
		ActionLog::record( 'Page cache purged' . ( '' !== $reason ? ' (' . $reason . ')' : '' ) );
		if ( function_exists( 'w3tc_flush_all' ) ) {
			\w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			\wp_cache_clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			\rocket_clean_domain();
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			\wpfc_clear_all_cache( true );
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			\sg_cachepress_purge_cache();
		}
		if ( class_exists( '\\WpeCommon' ) && method_exists( '\\WpeCommon', 'purge_varnish_cache' ) ) {
			\WpeCommon::purge_varnish_cache();
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'cache_enabler_clear_complete_cache' );
		do_action( 'rt_nginx_helper_purge_all' );
		do_action( self::ACTION, $reason );
	}
}
