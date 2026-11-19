<?php
/**
 * A wpdb double for QueueMetadataTest. Without a connection it records the
 * prepared statements and answers from $col_result; with a mysqli connection
 * it runs them on a real server. fail_at makes the Nth statement fail;
 * explain_writes records the EXPLAIN of each write instead of running it;
 * scope_override replaces the real answer of the scope select.
 */

declare(strict_types=1);

class QueueMetadataTestDb {
	public string $prefix   = 'wp_';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';
	public array $queries   = [];
	public ?array $col_result = [];
	public int $fail_at     = 0;
	public bool $explain_writes = false;
	public array $plans     = [];
	public ?array $scope_override = null;
	public ?mysqli $db;

	public function __construct( ?mysqli $db = null ) {
		$this->db = $db;
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$sql = preg_replace_callback(
			'/%[ds]/',
			function ( array $m ) use ( &$i, $args ): string {
				$value = $args[ $i++ ];
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				return "'" . ( $this->db ? $this->db->real_escape_string( (string) $value ) : addslashes( (string) $value ) ) . "'";
			},
			$query
		);
		if ( $i !== count( $args ) ) {
			throw new RuntimeException( "prepare: {$i} placeholders for " . count( $args ) . ' args' );
		}
		return $sql;
	}

	public function query( string $sql ) {
		$this->last_error = '';
		$this->queries[]  = $sql;
		if ( count( $this->queries ) === $this->fail_at ) {
			$this->last_error = 'forced failure';
			return false;
		}
		if ( ! $this->db ) {
			return 1;
		}
		try {
			if ( $this->explain_writes && 0 !== strpos( $sql, 'SELECT' ) ) {
				$this->plans[] = $this->db->query( 'EXPLAIN ' . $sql )->fetch_all( MYSQLI_ASSOC );
				return 1;
			}
			$result = $this->db->query( $sql );
		} catch ( mysqli_sql_exception $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
		if ( $result instanceof mysqli_result ) {
			$rows = $result->fetch_all( MYSQLI_NUM );
			$result->free();
			return $rows;
		}
		return $this->db->affected_rows;
	}

	public function get_col( string $sql ): array {
		$rows = $this->query( $sql );
		if ( false === $rows ) {
			return [];
		}
		if ( ! $this->db ) {
			if ( null === $this->col_result ) {
				$this->last_error = 'select failed';
				return [];
			}
			return $this->col_result;
		}
		return $this->scope_override ?? array_column( $rows, 0 );
	}
}

/** Finds a mysqld binary: TWP_TEST_MYSQLD, Homebrew, then Local's bundled servers. */
function queue_metadata_find_mysqld(): ?string {
	$explicit = getenv( 'TWP_TEST_MYSQLD' );
	if ( $explicit ) {
		return is_executable( $explicit ) ? $explicit : null;
	}
	$home       = (string) getenv( 'HOME' );
	$candidates = array_merge(
		glob( '/opt/homebrew/opt/mysql*/bin/mysqld' ) ?: [],
		glob( '/usr/local/opt/mysql*/bin/mysqld' ) ?: [],
		glob( '/opt/homebrew/opt/mariadb*/bin/mariadbd' ) ?: [],
		glob( $home . '/Library/Application Support/Local/lightning-services/mysql-*/bin/*/bin/mysqld' ) ?: []
	);
	foreach ( $candidates as $candidate ) {
		if ( is_executable( $candidate ) ) {
			return $candidate;
		}
	}
	return null;
}

/**
 * Starts a throwaway server on a temporary data directory, port and socket.
 * Returns [ mysqli, stop callable ] or a string with the reason it could not start.
 *
 * @return array{0: mysqli, 1: callable}|string
 */
function queue_metadata_start_server( string $mysqld ) {
	if ( ! class_exists( 'mysqli' ) ) {
		return 'the mysqli extension is missing';
	}
	$dir = '/tmp/twpqm-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir . '/data', 0700, true );
	$port   = 0;
	$socket = $dir . '/s.sock';
	for ( $try = 0; $try < 20 && ! $port; $try++ ) {
		$candidate = random_int( 42000, 48000 );
		$probe     = @fsockopen( '127.0.0.1', $candidate, $errno, $errstr, 0.2 );
		if ( $probe ) {
			fclose( $probe );
		} else {
			$port = $candidate;
		}
	}
	$remove = static function () use ( $dir ): void {
		exec( 'rm -rf ' . escapeshellarg( $dir ) );
	};
	$common = '--no-defaults --datadir=' . escapeshellarg( $dir . '/data' ) . ' --log-error=' . escapeshellarg( $dir . '/err.log' );
	exec( escapeshellarg( $mysqld ) . ' ' . $common . ' --initialize-insecure 2>&1', $out, $code );
	if ( 0 !== $code ) {
		$log = (string) @file_get_contents( $dir . '/err.log' );
		$remove();
		return 'initialize failed: ' . trim( implode( ' ', $out ) . ' ' . substr( $log, -300 ) );
	}
	$cmd = escapeshellarg( $mysqld ) . ' ' . $common
		. ' --port=' . $port . ' --bind-address=127.0.0.1 --socket=' . escapeshellarg( $socket )
		. ' --pid-file=' . escapeshellarg( $dir . '/mysqld.pid' )
		. ' --mysqlx=OFF --skip-log-bin --max-allowed-packet=1M > /dev/null 2>&1 & echo $!';
	$pid = (int) shell_exec( $cmd );
	$stop = static function () use ( $pid, $dir, $remove ): void {
		if ( $pid > 0 ) {
			exec( 'kill ' . $pid . ' 2>/dev/null' );
			for ( $i = 0; $i < 150; $i++ ) {
				exec( 'kill -0 ' . $pid . ' 2>/dev/null', $unused, $alive );
				if ( 0 !== $alive ) {
					break;
				}
				usleep( 100000 );
			}
		}
		$remove();
	};
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	for ( $i = 0; $i < 300; $i++ ) {
		if ( file_exists( $socket ) ) {
			try {
				$db = new mysqli( 'localhost', 'root', '', '', 0, $socket );
				return [ $db, $stop ];
			} catch ( mysqli_sql_exception $e ) {
				unset( $e );
			}
		}
		usleep( 100000 );
	}
	$log = (string) @file_get_contents( $dir . '/err.log' );
	$stop();
	return 'server did not accept connections: ' . substr( $log, -300 );
}
