<?php
/**
 * Metahaya main.
 *
 * @package metahaya
 */

/**
 * Metahaya main class.
 */
class Metahaya_Main {
	/**
	 * Options.
	 *
	 * @var array
	 */
	public $options;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		load_plugin_textdomain( 'metahaya' );

		add_action( 'activated_plugin', array( $this, 'activated_plugin' ) );
		add_action( 'deactivated_plugin', array( $this, 'deactivated_plugin' ) );

		$this->options = get_option( 'metahaya_options' );
		if ( false === $this->options || version_compare( $this->options['plugin_version'], METAHAYA_VERSION, '<' ) ) {
			$this->activation();
			$this->options = get_option( 'metahaya_options' );
		}

		if ( false === $this->options ) {
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}

	/**
	 * Fires once activated plugins have loaded.
	 *
	 * @since 1.0.0
	 */
	public function plugins_loaded() {
		add_filter( 'get_meta_sql', array( $this, 'get_meta_sql' ), 10, 6 );
		add_filter( 'posts_clauses_request', array( $this, 'posts_clauses_request' ), 10, 2 );
	}

	/**
	 * Prints admin screen notices.
	 *
	 * @since 1.0.0
	 */
	public function admin_notices() {
		global $pagenow;

		if ( 'plugins.php' === $pagenow || 'index.php' === $pagenow ) {
			printf(
				'<div class="notice notice-warning"><p><b>%s</b>: %s</p></div>',
				esc_html( __( 'Metahaya', 'metahaya' ) ),
				esc_html( __( 'Requires MySQL 5.7.22 or later, or MariaDB 10.5 or later.', 'metahaya' ) )
			);
		}
	}

	/**
	 * Gets the default value of the options.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_default_options() {
		return array(
			// phpcs:disable Squiz.PHP.CommentedOutCode.Found
			'plugin_version' => METAHAYA_VERSION,
			'enable'         => true,
			// phpcs:enable
		);
	}

	/**
	 * Create database tables.
	 *
	 * @since 1.0.0
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql =
			"CREATE TABLE {$wpdb->prefix}metahaya_postmeta (
			post_id bigint(20) unsigned NOT NULL default 0,
			json JSON,
			PRIMARY KEY (post_id)
			) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create database triggers.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	public function create_trigger() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			"CREATE TRIGGER {$wpdb->prefix}metahaya_insert_post AFTER " .
			"INSERT ON {$wpdb->prefix}posts " .
			'FOR EACH ROW ' .
			"INSERT INTO {$wpdb->prefix}metahaya_postmeta (post_id, json) VALUES (NEW.ID, '{}');"
		);
		if ( $wpdb->last_error ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			"CREATE TRIGGER {$wpdb->prefix}metahaya_delete_post BEFORE " .
			"DELETE ON {$wpdb->prefix}posts " .
			'FOR EACH ROW ' .
			"DELETE FROM {$wpdb->prefix}metahaya_postmeta WHERE post_id = OLD.ID;"
		);
		if ( $wpdb->last_error ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			"CREATE TRIGGER {$wpdb->prefix}metahaya_insert_postmeta AFTER " .
			"INSERT ON {$wpdb->prefix}postmeta " .
			'FOR EACH ROW ' .
			"UPDATE {$wpdb->prefix}metahaya_postmeta SET `json` = JSON_SET(`json`, CONCAT('$.\"', NEW.meta_key, '\"'), NEW.meta_value) WHERE post_id = NEW.post_id;"
		);
		if ( $wpdb->last_error ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			"CREATE TRIGGER {$wpdb->prefix}metahaya_update_postmeta AFTER " .
			"UPDATE ON {$wpdb->prefix}postmeta " .
			'FOR EACH ROW ' .
			"UPDATE {$wpdb->prefix}metahaya_postmeta SET `json` = JSON_SET(`json`, CONCAT('$.\"', NEW.meta_key, '\"'), NEW.meta_value) WHERE post_id = NEW.post_id;"
		);
		if ( $wpdb->last_error ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			"CREATE TRIGGER {$wpdb->prefix}metahaya_delete_postmeta AFTER " .
			"DELETE ON {$wpdb->prefix}postmeta " .
			'FOR EACH ROW ' .
			"UPDATE {$wpdb->prefix}metahaya_postmeta SET `json` = JSON_REMOVE(`json`, CONCAT('$.\"', OLD.meta_key, '\"')) WHERE post_id = OLD.post_id;"
		);
		if ( $wpdb->last_error ) {
			return false;
		}

		return true;
	}

	/**
	 * Drop database triggers.
	 *
	 * @since 1.0.0
	 */
	public static function drop_trigger() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}metahaya_insert_post;" );
		$wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}metahaya_delete_post;" );
		$wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}metahaya_insert_postmeta;" );
		$wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}metahaya_update_postmeta;" );
		$wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}metahaya_delete_postmeta;" );
		// phpcs:enable
	}

	/**
	 * Update metahaya tables.
	 *
	 * @since 1.0.0
	 */
	public function update_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			"INSERT INTO {$wpdb->prefix}metahaya_postmeta (post_id, json) " .
			"SELECT post_id, JSON_OBJECTAGG(meta_key, meta_value) AS json FROM {$wpdb->prefix}postmeta GROUP BY post_id " .
			'ON DUPLICATE KEY UPDATE json = VALUES(json)'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}metahaya_postmeta " .
			"WHERE {$wpdb->prefix}metahaya_postmeta.post_id NOT IN (SELECT ID FROM {$wpdb->prefix}posts)"
		);
	}

	/**
	 * Plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function activated_plugin() {
		$this->activation();
	}

	/**
	 * Plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function activation() {
		global $wpdb;

		$db_version     = $wpdb->db_version();
		$db_server_info = $wpdb->db_server_info();
		$min_version    = ( false !== strpos( $db_server_info, 'MariaDB' ) ) ? '10.5' : '5.7.22';
		if ( empty( $wpdb->is_mysql ) || version_compare( $db_version, $min_version, '<' ) ) {
			delete_option( 'metahaya_options' );
			return;
		}

		$options = get_option( 'metahaya_options' );
		if ( false === $options ) {
			$options = $this->get_default_options();
			add_option( 'metahaya_options', $options );
		} else {
			$options['plugin_version'] = METAHAYA_VERSION;
			update_option( 'metahaya_options', $options );
		}

		$this->create_table();
		$this->create_trigger();
		$this->update_table();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function deactivated_plugin() {
		$this->drop_trigger();
	}

	/**
	 * Plugin Uninstall site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function uninstall_site() {
		global $wpdb;

		self::drop_trigger();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}metahaya_postmeta;" );

		delete_option( 'metahaya_options' );
	}

	/**
	 * Plugin Uninstall all site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		if ( is_multisite() ) {
			$site_ids = get_sites( array( 'fields' => 'ids' ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::uninstall_site();
			}
			restore_current_blog();
		} else {
			self::uninstall_site();
		}
	}

	/**
	 * Filters the meta query's generated SQL.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $sql               Array containing the query's JOIN and WHERE clauses.
	 * @param array    $queries           Array of meta queries.
	 * @param string   $type              Type of meta. Possible values include but are not limited
	 *                                    to 'post', 'comment', 'blog', 'term', and 'user'.
	 * @param string   $primary_table     Primary table.
	 * @param string   $primary_id_column Primary column ID.
	 * @param object   $context           The main query object that corresponds to the type, for
	 *                                    example a `WP_Query`, `WP_User_Query`, or `WP_Site_Query`.
	 * @return string[]|false
	 */
	public function get_meta_sql( $sql, $queries, $type, $primary_table, $primary_id_column, $context = null ) {
		if ( ! is_null( $context ) ) {
			if ( isset( $context->query_vars['suppress_filters'] ) && $context->query_vars['suppress_filters'] ) {
				return $sql;
			}
		}

		if ( 'post' !== $type ) {
			return $sql;
		}

		$metahaya_meta_query = new Metahaya_Meta_Query( $queries );
		return $metahaya_meta_query->get_sql( $type, $primary_table, $primary_id_column );
	}

	/**
	 * Filters all query clauses at once, for convenience.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $clauses Associative array of the clauses for the query.
	 * @param WP_Query $query   The WP_Query instance (passed by reference).
	 * @return string[].
	 */
	public function posts_clauses_request( $clauses, $query ) {
		global $wpdb;

		$orderby = $clauses['orderby'] ?? '';
		$join    = $clauses['join'] ?? '';

		if (
			! strpos( $orderby, 'meta_value' )
			|| ! strpos( $join, $wpdb->prefix . 'metahaya_postmeta' )
		) {
			return $clauses;
		}

		$query_vars        = $query->query_vars;
		$orderby_array     = explode( ',', $orderby );
		$orderby_vars      = is_array( $query_vars['orderby'] ) ? array_keys( $query_vars['orderby'] ) : array_keys( array( $query_vars['orderby'] ) );
		$meta_clauses      = $query->meta_query->get_clauses();
		$meta_clauses_keys = array_keys( $meta_clauses );
		$alias             = "{$wpdb->prefix}metahaya_postmeta";

		foreach ( $orderby_array as $key => $value ) {
			$order = explode( ' ', $value );
			$order = in_array( 'DESC', $order, true ) ? $order[ array_search( 'DESC', $order, true ) ] : $order[ array_search( 'ASC', $order, true ) ];

			if ( strpos( $value, 'meta_value' ) ) {
				$meta_clause = array();
				foreach ( $meta_clauses_keys as $meta_clauses_key ) {
					if ( false !== strpos( $value, $meta_clauses[ $meta_clauses_key ]['alias'] ) ) {
						$meta_clause = $meta_clauses[ $meta_clauses_key ];
						break;
					}
				}

				$meta_key = $meta_clause['key'];
				if ( preg_match( '/^[a-zA-Z0-9_]+$/', $meta_key ) ) {
					$meta_key = '$.' . $meta_key;
				} else {
					$meta_key = '$."' . $meta_key . '"';
				}

				if ( 'meta_value_num' === $query_vars['orderby'] ) {
					$orderby_array[ $key ] = "CAST(JSON_UNQUOTE(JSON_EXTRACT($alias.json, '$meta_key')) AS SIGNED) $order";
				} elseif ( empty( $meta_clause['type'] ) ) {
					$orderby_array[ $key ] = "JSON_EXTRACT($alias.json, '$meta_key') $order";
				} else {
					$orderby_array[ $key ] = "CAST(JSON_UNQUOTE(JSON_EXTRACT($alias.json, '$meta_key')) AS {$meta_clause['cast']}) $order";
				}
			}
		}

		$clauses['orderby'] = implode( ',', $orderby_array );

		return $clauses;
	}
}
