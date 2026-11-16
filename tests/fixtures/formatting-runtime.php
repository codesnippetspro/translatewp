<?php

namespace TranslateWP\Components;

function class_exists( string $class, bool $autoload = true ): bool {
	if ( 'numberformatter' === strtolower( ltrim( $class, '\\' ) )
		&& in_array( '--without-intl', $_SERVER['argv'] ?? [], true ) ) {
		return false;
	}
	return \class_exists( $class, $autoload );
}
