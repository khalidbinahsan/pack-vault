<?php
/**
 * Resumable database exporter.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.WP.AlternativeFunctions

/**
 * Dumps every table that uses the site's table prefix into a .sql file.
 *
 * Every statement is written on exactly one line, so the importer can stream
 * the file line by line. Table names use a placeholder prefix so a backup can
 * be restored on a site with a different table prefix.
 */
final class Database_Exporter {

	const PREFIX_PLACEHOLDER = '{{PACKVAULT_PREFIX}}';
	const BATCH_ROWS         = 1000;
	const MAX_STATEMENT      = 524288;

	/**
	 * Initial exporter state.
	 *
	 * @param array  $options  Backup options.
	 * @param string $sql_file Output path.
	 * @return array
	 */
	public static function init_state( array $options, $sql_file ) {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
		$tables = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $row[1] ) && 'BASE TABLE' === strtoupper( $row[1] ) ) {
				$tables[] = $row[0];
			}
		}

		$header  = "-- Packvault database export\n";
		$header .= '-- Plugin: Packvault ' . PACKVAULT_VERSION . ' by ' . PACKVAULT_AUTHOR_NAME . ' (' . PACKVAULT_AUTHOR_URL . ")\n";
		$header .= '-- Created: ' . gmdate( 'c' ) . "\n";
		$header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
		file_put_contents( $sql_file, $header );

		return array(
			'sql'      => $sql_file,
			'size'     => strlen( $header ),
			'tables'   => $tables,
			'index'    => 0,
			'stage'    => 'create',
			'cursor'   => null,
			'offset'   => 0,
			'rows'     => 0,
			'options'  => array(
				'spam'      => ! empty( $options['exclude_spam_comments'] ),
				'revisions' => ! empty( $options['exclude_post_revisions'] ),
			),
		);
	}

	/**
	 * Export until the deadline.
	 *
	 * @param array $state    Exporter state (modified).
	 * @param float $deadline Microtime deadline.
	 * @return bool True when every table is exported.
	 * @throws \RuntimeException On write errors.
	 */
	public static function step( array &$state, $deadline ) {
		global $wpdb;

		$out = fopen( $state['sql'], 'c+b' );
		if ( ! $out ) {
			throw new \RuntimeException( esc_html__( 'Could not write the database dump in the Packvault temp folder.', 'pack-vault' ) );
		}
		ftruncate( $out, $state['size'] );
		fseek( $out, $state['size'] );

		while ( $state['index'] < count( $state['tables'] ) && microtime( true ) < $deadline ) {
			$table = $state['tables'][ $state['index'] ];
			$alias = self::alias( $table );

			if ( 'create' === $state['stage'] ) {
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
				if ( empty( $create[1] ) ) {
					++$state['index'];
					continue;
				}
				$sql = str_replace( '`' . $wpdb->base_prefix, '`' . self::PREFIX_PLACEHOLDER, $create[1] );
				$sql = preg_replace( '/\s*[\r\n]+\s*/', ' ', $sql );
				self::write( $out, "DROP TABLE IF EXISTS `{$alias}`;\n{$sql};\n" );

				$state['stage']  = 'rows';
				$state['cursor'] = null;
				$state['offset'] = 0;
				$state['meta']   = self::table_meta( $table );
			}

			$meta    = $state['meta'];
			$where   = self::filter( $table, $state['options'] );
			$columns = '`' . implode( '`,`', $meta['columns'] ) . '`';

			if ( $meta['pk'] ) {
				$conditions = $where;
				if ( null !== $state['cursor'] ) {
					$conditions[] = $wpdb->prepare( "`{$meta['pk']}` > %d", $state['cursor'] );
				}
				$sql = "SELECT * FROM `{$table}`" . ( $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '' ) . " ORDER BY `{$meta['pk']}` ASC LIMIT " . self::BATCH_ROWS;
			} else {
				$sql = "SELECT * FROM `{$table}`" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' LIMIT ' . (int) $state['offset'] . ', ' . self::BATCH_ROWS;
			}

			// Identifiers come from SHOW TABLES/COLUMNS of this database; conditions are prepared above.
			$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( $wpdb->last_error ) {
				throw new \RuntimeException(
					/* translators: 1: table name, 2: database error */
					sprintf( esc_html__( 'Exporting table %1$s failed: %2$s', 'pack-vault' ), esc_html( $table ), esc_html( $wpdb->last_error ) )
				);
			}

			if ( empty( $rows ) ) {
				++$state['index'];
				$state['stage'] = 'create';
				unset( $state['meta'] );
				$state['size'] = (int) ftell( $out );
				continue;
			}

			$prefix = "INSERT INTO `{$alias}` ({$columns}) VALUES ";
			$values = array();
			$length = 0;
			foreach ( $rows as $row ) {
				$encoded = array();
				foreach ( $row as $i => $value ) {
					$encoded[] = self::encode( $value, ! empty( $meta['binary'][ $i ] ) );
				}
				$tuple    = '(' . implode( ',', $encoded ) . ')';
				$values[] = $tuple;
				$length  += strlen( $tuple ) + 1;
				if ( $length >= self::MAX_STATEMENT ) {
					self::write( $out, $prefix . implode( ',', $values ) . ";\n" );
					$values = array();
					$length = 0;
				}
			}
			if ( $values ) {
				self::write( $out, $prefix . implode( ',', $values ) . ";\n" );
			}

			$state['rows'] += count( $rows );
			if ( $meta['pk'] ) {
				$last            = end( $rows );
				$state['cursor'] = $last[ $meta['pk_index'] ];
			} else {
				$state['offset'] += count( $rows );
			}
			$state['size'] = (int) ftell( $out );
		}

		$state['size'] = (int) ftell( $out );
		fclose( $out );
		return $state['index'] >= count( $state['tables'] );
	}

	/**
	 * Table name with the placeholder prefix.
	 *
	 * @param string $table Real table name.
	 * @return string
	 */
	private static function alias( $table ) {
		global $wpdb;
		return self::PREFIX_PLACEHOLDER . substr( $table, strlen( $wpdb->base_prefix ) );
	}

	/**
	 * Column list, binary flags and an integer primary key if there is one.
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	private static function table_meta( $table ) {
		global $wpdb;
		$cols    = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$columns = array();
		$binary  = array();
		$types   = array();
		foreach ( (array) $cols as $i => $col ) {
			$columns[]                 = $col['Field'];
			$type                      = strtolower( $col['Type'] );
			$binary[ $i ]              = (bool) preg_match( '/blob|binary|bit|geometry|point|polygon|linestring/', $type );
			$types[ $col['Field'] ]    = $type;
		}

		$keys = $wpdb->get_results( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ), ARRAY_A );
		$pk   = null;
		if ( is_array( $keys ) && 1 === count( $keys ) ) {
			$field = $keys[0]['Column_name'];
			if ( isset( $types[ $field ] ) && preg_match( '/int/', $types[ $field ] ) ) {
				$pk = $field;
			}
		}

		return array(
			'columns'  => $columns,
			'binary'   => $binary,
			'pk'       => $pk,
			'pk_index' => $pk ? array_search( $pk, $columns, true ) : null,
		);
	}

	/**
	 * Optional row filters (spam comments, revisions, transients).
	 *
	 * @param string $table   Table.
	 * @param array  $options Exporter options.
	 * @return string[] SQL conditions.
	 */
	private static function filter( $table, array $options ) {
		global $wpdb;
		$where = array();
		if ( $table === $wpdb->options ) {
			$where[] = "`option_name` NOT LIKE '\\_transient\\_%' AND `option_name` NOT LIKE '\\_site\\_transient\\_%'";
		}
		if ( $options['spam'] && $table === $wpdb->comments ) {
			$where[] = "`comment_approved` <> 'spam'";
		}
		if ( $options['revisions'] && $table === $wpdb->posts ) {
			$where[] = "`post_type` <> 'revision'";
		}
		return $where;
	}

	/**
	 * Encode a value as an SQL literal.
	 *
	 * @param string|null $value  Value.
	 * @param bool        $binary Binary column.
	 * @return string
	 */
	private static function encode( $value, $binary ) {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( $binary ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return "'" . strtr(
			(string) $value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		) . "'";
	}

	/**
	 * Write or throw.
	 *
	 * @param resource $handle File handle.
	 * @param string   $data   Data.
	 * @throws \RuntimeException On failure.
	 */
	private static function write( $handle, $data ) {
		if ( fwrite( $handle, $data ) !== strlen( $data ) ) {
			throw new \RuntimeException( esc_html__( 'Writing the database dump failed. The disk may be full.', 'pack-vault' ) );
		}
	}
}
