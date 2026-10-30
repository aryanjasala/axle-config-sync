<?php
/**
 * Fakes for unit tests: an in-memory $wpdb for the change-set table.
 *
 * @package AryanX\Axle\ConfigSync
 */

// phpcs:ignoreFile

namespace {
	if ( ! class_exists( 'WP_Term' ) ) {
		class WP_Term {
			public $term_id  = 0;
			public $taxonomy = '';
			public $slug     = '';
			public $name     = '';
			public function __construct( array $fields = array() ) {
				foreach ( $fields as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}
}

namespace AryanX\Axle\ConfigSync\Tests {

/**
 * Just enough of wpdb for Changesets: rows live in an array.
 */
class FakeWpdb {
	public $prefix    = 'wp_';
	public $options   = 'wp_options';
	public $posts     = 'wp_posts';
	public $insert_id = 0;
	/** @var array<int, array> */
	public $rows = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return json_encode( array( $query, $args ) );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function insert( $table, $data, $format = null ) {
		$this->insert_id                = count( $this->rows ) + 1;
		$data['id']                     = $this->insert_id;
		$this->rows[ $this->insert_id ] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$changed = 0;
		foreach ( $this->rows as $id => $row ) {
			$match = true;
			foreach ( $where as $key => $value ) {
				if ( (string) $row[ $key ] !== (string) $value ) {
					$match = false;
				}
			}
			if ( $match ) {
				$this->rows[ $id ] = array_merge( $row, $data );
				++$changed;
			}
		}
		return $changed;
	}

	public function get_row( $prepared, $output = null ) {
		list( , $args ) = json_decode( $prepared, true );
		$id             = (int) end( $args );
		return $this->rows[ $id ] ?? null;
	}

	public function get_results( $prepared, $output = null ) {
		$rows = array_reverse( array_values( $this->rows ) );
		return $rows;
	}

	public function get_var( $prepared ) {
		return count( $this->rows );
	}

	public function get_col( $prepared ) {
		return array();
	}

	public function query( $prepared ) {
		return 0;
	}
}
}
