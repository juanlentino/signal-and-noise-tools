<?php
/**
 * A fake $wpdb for the lanes table: just the statements inc/analytics-lanes-store.php
 * and -read.php send. prepare() keeps the SQL and its arguments apart, insert()
 * enforces the table's unique key, and a transaction snapshot is restored on
 * ROLLBACK. `fail_insert` = N makes the Nth insert fail.
 */
class Lanes_Fake_WPDB {
	public $prefix = 'wp_';
	public $rows = array();
	public $last_error = '';
	public $fail_insert = 0;
	private $snap = null;
	private $inserts = 0;
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $sql, ...$args ) {
		$args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
		return array( 'sql' => $sql, 'args' => $args );
	}
	private function where( $p ) {
		$a      = $p['args'];
		$sensor = array_shift( $a );
		$ver    = (int) array_shift( $a );
		$ne     = false !== strpos( $p['sql'], 'rule_version <>' );
		return static fn( $r ) => $r['sensor'] === $sensor && in_array( $r['day'], $a, true ) && ( $ne ? $r['rule_version'] !== $ver : $r['rule_version'] === $ver );
	}
	public function query( $q ) {
		if ( is_string( $q ) ) {
			if ( 'START TRANSACTION' === $q ) { $this->snap = $this->rows; $this->inserts = 0; }
			if ( 'ROLLBACK' === $q ) { $this->rows = $this->snap; }
			return true;
		}
		$this->last_error = '';
		$m = $this->where( $q );
		$n = count( $this->rows );
		$this->rows = array_values( array_filter( $this->rows, static fn( $r ) => ! $m( $r ) ) );
		return $n - count( $this->rows );
	}
	public function get_col( $q ) {
		return array_values( array_unique( array_column( array_filter( $this->rows, $this->where( $q ) ), 'day' ) ) );
	}
	public function insert( $table, array $data ) {
		++$this->inserts;
		if ( $this->fail_insert && $this->inserts === $this->fail_insert ) { $this->fail_insert = 0; $this->last_error = 'planted failure'; return false; }
		$key = static fn( $r ) => implode( '|', array( $r['day'], $r['sensor'], $r['lane'], $r['reason'], $r['agent'], $r['family'], $r['purpose'] ) );
		foreach ( $this->rows as $r ) {
			if ( $key( $r ) === $key( $data ) ) { $this->last_error = 'Duplicate entry'; return false; }
		}
		$this->rows[] = $data;
		return 1;
	}
	public function get_results( $q, $out = null ) {
		list( $from, $to ) = $q['args'];
		return array_values( array_filter( $this->rows, static fn( $r ) => $r['day'] >= $from && $r['day'] <= $to ) );
	}
}
$GLOBALS['wpdb'] = new Lanes_Fake_WPDB();
