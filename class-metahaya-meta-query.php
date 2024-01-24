<?php
/**
 * Metahaya_Meta_Query class
 *
 * @package Metahaya
 * @since 1.0.0
 */

/**
 * Metahaya Meta Query class.
 *
 * See WP_Meta_Query class.
 *
 * @since 1.0.0
 */
class Metahaya_Meta_Query {
	/**
	 * Array of metadata queries.
	 *
	 * See WP_Meta_Query::__construct() for information on meta query arguments.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	public $queries = array();

	/**
	 * The relation between the queries. Can be one of 'AND' or 'OR'.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $relation;

	/**
	 * Database table to query for the metadata.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $meta_table;

	/**
	 * Column in meta_table that represents the ID of the object the metadata belongs to.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $meta_id_column;

	/**
	 * Database table that where the metadata's objects are stored (eg $wpdb->users).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $primary_table;

	/**
	 * Column in primary_table that represents the ID of the object.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $primary_id_column;

	/**
	 * A flat list of table aliases used in JOIN clauses.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $table_aliases = array();

	/**
	 * A flat list of clauses, keyed by clause 'name'.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $clauses = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array $meta_query Array of meta query clauses.
	 */
	public function __construct( $meta_query = false ) {
		if ( ! $meta_query ) {
			return;
		}

		if ( isset( $meta_query['relation'] ) && 'OR' === strtoupper( $meta_query['relation'] ) ) {
			$this->relation = 'OR';
		} else {
			$this->relation = 'AND';
		}

		$this->queries = $meta_query;
	}

	/**
	 * Determines whether a query clause is first-order.
	 *
	 * @since 1.0.0
	 *
	 * @param array $query Meta query arguments.
	 * @return bool Whether the query clause is a first-order clause.
	 */
	protected function is_first_order_clause( $query ) {
		return isset( $query['key'] ) || isset( $query['value'] );
	}

	/**
	 * Returns the appropriate alias for the given meta type if applicable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type MySQL type to cast meta_value.
	 * @return string MySQL type.
	 */
	public function get_cast_for_type( $type = '' ) {
		if ( empty( $type ) ) {
			return 'CHAR';
		}

		$meta_type = strtoupper( $type );

		if ( ! preg_match( '/^(?:BINARY|CHAR|DATE|DATETIME|SIGNED|UNSIGNED|TIME|NUMERIC(?:\(\d+(?:,\s?\d+)?\))?|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/', $meta_type ) ) {
			return 'CHAR';
		}

		if ( 'NUMERIC' === $meta_type ) {
			$meta_type = 'SIGNED';
		}

		return $meta_type;
	}

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type              Type of meta. Possible values include but are not limited
	 *                                  to 'post', 'comment', 'blog', 'term', and 'user'.
	 * @param string $primary_table     Database table where the object being filtered is stored (eg wp_users).
	 * @param string $primary_id_column ID column for the filtered object in $primary_table.
	 * @return string[]|false
	 */
	public function get_sql( $type, $primary_table, $primary_id_column ) {
		$meta_table = $this->get_meta_table( $type );
		if ( ! $meta_table ) {
			return false;
		}

		$this->table_aliases = array();

		$this->meta_table     = $meta_table;
		$this->meta_id_column = sanitize_key( $type . '_id' );

		$this->primary_table     = $primary_table;
		$this->primary_id_column = $primary_id_column;

		$sql = $this->get_sql_clauses();

		return $sql;
	}

	/**
	 * Generates SQL clauses to be appended to a main query.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	protected function get_sql_clauses() {
		$queries = $this->queries;
		$sql     = $this->get_sql_for_query( $queries );

		if ( ! empty( $sql['where'] ) ) {
			$sql['where'] = ' AND ' . $sql['where'];
		}

		return $sql;
	}

	/**
	 * Generates SQL clauses for a single query array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $query Query to parse (passed by reference).
	 * @param int   $depth Optional. Number of tree levels deep we currently are.
	 *                     Used to calculate indentation. Default 0.
	 * @return string[] {
	 *     Array containing JOIN and WHERE SQL clauses to append to a single query array.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	protected function get_sql_for_query( &$query, $depth = 0 ) {
		$sql_chunks = array(
			'join'  => array(),
			'where' => array(),
		);

		$sql = array(
			'join'  => '',
			'where' => '',
		);

		$indent = '';
		for ( $i = 0; $i < $depth; $i++ ) {
			$indent .= '  ';
		}

		foreach ( $query as $key => &$clause ) {
			if ( 'relation' === $key ) {
				$relation = $query['relation'];
			} elseif ( is_array( $clause ) ) {

				// This is a first-order clause.
				if ( $this->is_first_order_clause( $clause ) ) {
					$clause_sql = $this->get_sql_for_clause( $clause, $query, $key );

					$where_count = count( $clause_sql['where'] );
					if ( ! $where_count ) {
						$sql_chunks['where'][] = '';
					} elseif ( 1 === $where_count ) {
						$sql_chunks['where'][] = $clause_sql['where'][0];
					} else {
						$sql_chunks['where'][] = '( ' . implode( ' AND ', $clause_sql['where'] ) . ' )';
					}

					$sql_chunks['join'] = array_merge( $sql_chunks['join'], $clause_sql['join'] );
					// This is a subquery, so we recurse.
				} else {
					$clause_sql = $this->get_sql_for_query( $clause, $depth + 1 );

					$sql_chunks['where'][] = $clause_sql['where'];
					$sql_chunks['join'][]  = $clause_sql['join'];
				}
			}
		}

		// Filter to remove empties.
		$sql_chunks['join']  = array_filter( $sql_chunks['join'] );
		$sql_chunks['where'] = array_filter( $sql_chunks['where'] );

		if ( empty( $relation ) ) {
			$relation = 'AND';
		}

		// Filter duplicate JOIN clauses and combine into a single string.
		if ( ! empty( $sql_chunks['join'] ) ) {
			$sql['join'] = implode( ' ', array_unique( $sql_chunks['join'] ) );
		}

		// Generate a single WHERE clause with proper brackets and indentation.
		if ( ! empty( $sql_chunks['where'] ) ) {
			$sql['where'] = '( ' . "\n  " . $indent . implode( ' ' . "\n  " . $indent . $relation . ' ' . "\n  " . $indent, $sql_chunks['where'] ) . "\n" . $indent . ')';
		}

		return $sql;
	}

	/**
	 * Retrieves the name of the metadata table for the specified object type.
	 *
	 * @since 1.0.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $type Type of object metadata is for. Accepts 'post', 'comment', 'term', 'user',
	 *                     or any other object type with an associated meta table.
	 * @return string|false Metadata table name, or false if no metadata table exists
	 */
	private function get_meta_table( $type ) {
		global $wpdb;

		if ( 'post' !== $type ) {
			return false;
		}

		return "{$wpdb->prefix}metahaya_{$type}meta";
	}

	/**
	 * Generates SQL JOIN and WHERE clauses for a first-order query clause.
	 *
	 * See WP_Meta_Query::get_sql_for_clause().
	 *
	 * @since 1.0.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array  $clause       Query clause (passed by reference).
	 * @param array  $parent_query Parent query array.
	 * @param string $clause_key   Optional. The array key used to name the clause in the original `$meta_query`
	 *                             parameters. If not provided, a key will be generated automatically.
	 *                             Default empty string.
	 * @return array {
	 *     Array containing JOIN and WHERE SQL clauses to append to a first-order query.
	 *
	 *     @type string[] $join  Array of SQL fragments to append to the main JOIN clause.
	 *     @type string[] $where Array of SQL fragments to append to the main WHERE clause.
	 * }
	 */
	public function get_sql_for_clause( &$clause, $parent_query, $clause_key = '' ) {
		global $wpdb;

		$sql_chunks = array(
			'where' => array(),
			'join'  => array(),
		);

		if ( isset( $clause['compare'] ) ) {
			$clause['compare'] = strtoupper( $clause['compare'] );
		} else {
			$clause['compare'] = isset( $clause['value'] ) && is_array( $clause['value'] ) ? 'IN' : '=';
		}

		$non_numeric_operators = array(
			'=',
			'!=',
			'LIKE',
			'NOT LIKE',
			'IN',
			'NOT IN',
			'EXISTS',
			'NOT EXISTS',
			'RLIKE',
			'REGEXP',
			'NOT REGEXP',
		);

		$numeric_operators = array(
			'>',
			'>=',
			'<',
			'<=',
			'BETWEEN',
			'NOT BETWEEN',
		);

		if ( ! in_array( $clause['compare'], $non_numeric_operators, true ) && ! in_array( $clause['compare'], $numeric_operators, true ) ) {
			$clause['compare'] = '=';
		}

		if ( isset( $clause['compare_key'] ) ) {
			$clause['compare_key'] = strtoupper( $clause['compare_key'] );
		} else {
			$clause['compare_key'] = isset( $clause['key'] ) && is_array( $clause['key'] ) ? 'IN' : '=';
		}

		if ( ! in_array( $clause['compare_key'], $non_numeric_operators, true ) ) {
			$clause['compare_key'] = '=';
		}

		$meta_compare     = $clause['compare'];
		$meta_compare_key = $clause['compare_key'];

		$meta_key = trim( $clause['key'] );
		if ( preg_match( '/^[a-zA-Z0-9_]+$/', $meta_key ) ) {
			$meta_key = '$.' . $meta_key;
		} else {
			$meta_key = '$."' . $meta_key . '"';
		}

		// First build the JOIN clause, if one is required.
		$join = '';

		$alias = false;
		if ( false === $alias ) {
			$alias = $this->meta_table;

			$join .= " INNER JOIN $this->meta_table";
			$join .= " AS $alias";
			$join .= " ON ( $this->primary_table.$this->primary_id_column = $alias.$this->meta_id_column )";

			$this->table_aliases[] = $alias;
			$sql_chunks['join'][]  = $join;
		}

		// Save the alias to this clause, for future siblings to find.
		$clause['alias'] = $alias;

		// Determine the data type.
		$_meta_type     = isset( $clause['type'] ) ? $clause['type'] : '';
		$meta_type      = $this->get_cast_for_type( $_meta_type );
		$clause['cast'] = $meta_type;

		// Fallback for clause keys is the table alias. Key must be a string.
		if ( is_int( $clause_key ) || ! $clause_key ) {
			$clause_key = $clause['alias'];
		}

		// Ensure unique clause keys, so none are overwritten.
		$iterator        = 1;
		$clause_key_base = $clause_key;
		while ( isset( $this->clauses[ $clause_key ] ) ) {
			$clause_key = $clause_key_base . '-' . $iterator;
			++$iterator;
		}

		// Store the clause in our flat array.
		$this->clauses[ $clause_key ] =& $clause;

		// Next, build the WHERE clause.

		// meta_key.
		if ( array_key_exists( 'key', $clause ) ) {
			if ( 'NOT EXISTS' === $meta_compare ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql_chunks['where'][] = $wpdb->prepare( "(!JSON_CONTAINS_PATH($alias.json, 'one', %s) OR $alias.json IS NULL)", $meta_key );
			} elseif ( 'EXISTS' === $meta_compare && ! array_key_exists( 'value', $clause ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql_chunks['where'][] = $wpdb->prepare( "JSON_CONTAINS_PATH($alias.json, 'one', %s)", $meta_key );
			}
		}

		// meta_value.
		if ( array_key_exists( 'value', $clause ) ) {
			$meta_value = $clause['value'];

			if ( in_array( $meta_compare, array( 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN' ), true ) ) {
				if ( ! is_array( $meta_value ) ) {
					$meta_value = preg_split( '/[,\s]+/', $meta_value );
				}
			} elseif ( is_string( $meta_value ) ) {
				$meta_value = trim( $meta_value );
			}

			switch ( $meta_compare ) {
				case 'IN':
				case 'NOT IN':
					$meta_compare_string = '(' . substr( str_repeat( ',%s', count( $meta_value ) ), 1 ) . ')';
					$where               = $wpdb->prepare( $meta_compare_string, $meta_value ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					break;

				case 'BETWEEN':
				case 'NOT BETWEEN':
					$where = $wpdb->prepare( '%s AND %s', $meta_value[0], $meta_value[1] );
					break;

				case 'LIKE':
				case 'NOT LIKE':
					$where = $wpdb->prepare( '%s', '%' . $wpdb->esc_like( $meta_value ) . '%' );
					break;

				// EXISTS with a value is interpreted as '='.
				case 'EXISTS':
					$meta_compare = '=';
					$where        = $wpdb->prepare( '%s', $meta_value );
					break;

				// 'value' is ignored for NOT EXISTS.
				case 'NOT EXISTS':
					$where = '';
					break;

				default:
					$where = $wpdb->prepare( '%s', $meta_value );
					break;
			}

			if ( $where ) {
				if ( 'CHAR' === $meta_type ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$column = $wpdb->prepare( "JSON_UNQUOTE(JSON_EXTRACT($alias.json, %s))", $meta_key );
				} else {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$column = $wpdb->prepare( "CAST(JSON_UNQUOTE(JSON_EXTRACT($alias.json, %s)) AS $meta_type)", $meta_key );
				}
				$sql_chunks['where'][] = "{$column} {$meta_compare} {$where}";
			}
		}

		/*
		 * Multiple WHERE clauses (for meta_key and meta_value) should
		 * be joined in parentheses.
		 */
		if ( 1 < count( $sql_chunks['where'] ) ) {
			$sql_chunks['where'] = array( '( ' . implode( ' AND ', $sql_chunks['where'] ) . ' )' );
		}

		return $sql_chunks;
	}
}
