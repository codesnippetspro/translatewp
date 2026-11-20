<?php

namespace TranslateWP\Components;

class ReadinessBudget {

	public const REQUEST_SECONDS = 3.0;
	public const STATUS_CALLS = 3;

	private $clock;
	private float $deadline;
	private int $status_calls = 0;
	private int $status_limit = self::STATUS_CALLS;

	public function __construct( float $seconds = self::REQUEST_SECONDS, ?callable $clock = null ) {
		$this->clock = $clock ?? static fn(): float => microtime( true );
		$this->deadline = call_user_func( $this->clock ) + $seconds;
	}

	public function remaining_seconds(): float {
		return max( 0.0, $this->deadline - call_user_func( $this->clock ) );
	}

	public function set_status_limit( int $limit ): void {
		$this->status_limit = max( 1, $limit );
	}

	public function claim_status_timeout(): float {
		if ( $this->status_calls >= $this->status_limit ) {
			return 0.0;
		}
		$timeout = $this->remaining_seconds();
		if ( $timeout > 0 ) {
			++$this->status_calls;
		}
		return $timeout;
	}
}
