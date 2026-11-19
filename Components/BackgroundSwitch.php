<?php

namespace TranslateWP\Components;

use TranslateWP\Functionality\Indexer;

class BackgroundSwitch {

	public const OPTION = 'translatewp_background_paused';

	public static function off(): bool {
		return defined( 'TRANSLATEWP_BACKGROUND_OFF' ) || (bool) get_option( self::OPTION, false );
	}

	public static function observe( array $response ): void {
		if ( ! isset( $response['backgroundPaused'] ) || ! is_bool( $response['backgroundPaused'] ) ) {
			return;
		}
		if ( $response['backgroundPaused'] ) {
			update_option( self::OPTION, true, false );
		} elseif ( delete_option( self::OPTION ) ) {
			Indexer::schedule_drain();
		}
	}
}
