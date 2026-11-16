<?php

namespace TranslateWP\Components;

class ReadinessBudget {

	public const REQUEST_SECONDS = 3.0;
	public const STATUS_CALLS = 3;

	private $clock;
	private float $deadline;
	private int $status_calls = 0;

	public function __construct( float $seconds = self::REQUEST_SECONDS, ?callable $clock = null ) {
		$this->clock = $clock ?? static fn(): float => microtime( true );
		$this->deadline = call_user_func( $this->clock ) + $seconds;
	}

	public function remaining_seconds(): float {
		return max( 0.0, $this->deadline - call_user_func( $this->clock ) );
	}

	public function claim_status_timeout(): float {
		if ( $this->status_calls >= self::STATUS_CALLS ) {
			return 0.0;
		}
		$timeout = $this->remaining_seconds();
		if ( $timeout > 0 ) {
			++$this->status_calls;
		}
		return $timeout;
	}
}
