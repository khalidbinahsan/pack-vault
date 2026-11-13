<?php
/**
 * Resumable database importer.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.WP.AlternativeFunctions

/**
 * Streams a Packvault .sql dump into temporary tables (prefix TEMP_PREFIX).
 *
 * The live site keeps working on its own tables during the import; the
 * temporary tables are swapped in at the very end (see swap()).
 */
final class Database_Importer {

	const TEMP_PREFIX = 'pvtmp_';
	const OLD_PREFIX  = 'pvold_';

	/**
	 * Initial importer state.
	 *
	 * @param string $sql_file Dump path.
	 * @return array
	 */
	public static function init_state( $sql_file ) {
		self::drop_prefixed( self::TEMP_PREFIX );
		return array(
			'sql'    => $sql_file,
			'offset' => 0,
			'size'   => (int) filesize( $sql_file ),
			'errors' => 0,
		);
	}

	/**
	 * Import until the deadline.
	 *
	 * @param array $state    Importer state (modified).
	 * @param float $deadline Microtime deadline.
	 * @param Job   $job      Job (for logging).
	 * @return bool True when the whole dump is imported.
	 * @throws \RuntimeException When the dump cannot be read.
	 */
	public static function step( array &$state, $deadline, Job $job ) {
		$in = fopen( $state['sql'], 'rb' );
		if ( ! $in ) {
			throw new \RuntimeException( esc_html__( 'The extracted database dump could not be read.', 'pack-vault' ) );
		}
		fseek( $in, $state['offset'] );

		self::query( 'SET FOREIGN_KEY_CHECKS = 0' );
		self::query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		$collations = self::collation_map();

		$done = false;
		while ( microtime( true ) < $deadline ) {
			$line = fgets( $in );
			if ( false === $line ) {
				$done = true;
				break;
			}
			$state['offset'] = (int) ftell( $in );
			$line            = rtrim( $line, "\r\n" );
			if ( '' === $line || 0 === strpos( $line, '--' ) ) {
				continue;
			}

			$sql = str_replace( '`' . Database_Exporter::PREFIX_PLACEHOLDER, '`' . self::TEMP_PREFIX, $line );
			if ( 0 === strpos( $sql, 'CREATE TABLE' ) && $collations ) {
				$sql = strtr( $sql, $collations );
			}

			$error = self::query( $sql );
			if ( null !== $error ) {
				++$state['errors'];
				if ( $state['errors'] <= 25 ) {
					$job->log( 'SQL error: ' . $error . ' – ' . substr( $sql, 0, 120 ) );
				}
			}
		}
		fclose( $in );
		return $done;
	}

	/**
	 * Replacements for collations/charsets this server does not support.
	 *
	 * @return array
	 */
	private static function collation_map() {
		global $wpdb;
		$map = array();
		if ( ! $wpdb->has_cap( 'utf8mb4' ) ) {
			$map['utf8mb4_unicode_520_ci'] = 'utf8_general_ci';
			$map['utf8mb4_unicode_ci']     = 'utf8_general_ci';
			$map['utf8mb4_general_ci']     = 'utf8_general_ci';
			$map['utf8mb4_0900_ai_ci']     = 'utf8_general_ci';
			$map['utf8mb4']                = 'utf8';
			return $map;
		}
		$has_0900 = $wpdb->get_var( "SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'" );
		if ( ! $has_0900 ) {
			$fallback = $wpdb->has_cap( 'utf8mb4_520' ) ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
			foreach ( array( 'utf8mb4_0900_ai_ci', 'utf8mb4_0900_as_ci', 'utf8mb4_0900_as_cs', 'utf8mb4_0900_bin' ) as $collation ) {
				$map[ $collation ] = $fallback;
			}
		}
		return $map;
	}

	/**
	 * Run one statement from the backup's own SQL dump.
	 *
	 * The statements come from the .packvault archive (CREATE TABLE / INSERT /
	 * RENAME written by Database_Exporter) and only touch the temporary
	 * restore tables, so they cannot be prepared with placeholders.
	 *
	 * They run on wpdb's own mysqli connection rather than through
	 * $wpdb->query(): for utf8mb4_unicode_* tables wpdb rejects any statement
	 * containing bytes that are not valid UTF-8 ("contains invalid data"),
	 * which real sites' tables can legitimately hold, and the backup must be
	 * restored byte-for-byte. $wpdb->query() is the fallback for non-mysqli
	 * drivers.
	 *
	 * @param string $sql SQL.
	 * @return string|null Error message, or null on success.
	 */
	public static function query( $sql ) {
		global $wpdb;
		if ( $wpdb->dbh instanceof \mysqli ) {
			try {
				$result = $wpdb->dbh->query( $sql );
			} catch ( \Throwable $e ) {
				return $e->getMessage();
			}
			if ( false === $result ) {
				return (string) $wpdb->dbh->error;
			}
			if ( $result instanceof \mysqli_result ) {
				$result->free();
			}
			return null;
		}
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Statement from the backup's SQL dump; see docblock.
		$wpdb->suppress_errors( $suppress );
		return false === $result ? (string) $wpdb->last_error : null;
	}

	/**
	 * Tables starting with a prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	public static function tables_with_prefix( $prefix ) {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		return is_array( $tables ) ? $tables : array();
	}

	/**
	 * Drop every table starting with a prefix (Packvault temp prefixes only).
	 *
	 * @param string $prefix Prefix.
	 */
	public static function drop_prefixed( $prefix ) {
		if ( ! in_array( $prefix, array( self::TEMP_PREFIX, self::OLD_PREFIX ), true ) ) {
			return;
		}
		self::query( 'SET FOREIGN_KEY_CHECKS = 0' );
		foreach ( self::tables_with_prefix( $prefix ) as $table ) {
			self::query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Rewrite prefix-dependent keys (user roles, capabilities) when the source
	 * site used a different table prefix.
	 *
	 * These run through $wpdb->query() (not self::query()): $wpdb->prepare()
	 * swaps every "%" for a placeholder that only $wpdb->query() turns back
	 * into "%". Sent straight to mysqli, the LIKE pattern matched nothing, so
	 * {prefix}capabilities was never renamed and admins lost all access after
	 * migrating to a site with a different table prefix.
	 *
	 * @param string $old_prefix Source table prefix.
	 * @return array{roles: int, usermeta: int}|null Rows changed, or null when the prefixes match.
	 */
	public static function fix_prefix_keys( $old_prefix ) {
		global $wpdb;
		$new_prefix = $wpdb->base_prefix;
		if ( '' === $old_prefix || $old_prefix === $new_prefix ) {
			return null;
		}
		$options  = self::TEMP_PREFIX . 'options';
		$usermeta = self::TEMP_PREFIX . 'usermeta';

		$roles = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', $options, $new_prefix . 'user_roles', $old_prefix . 'user_roles' )
		);
		$meta  = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
				$usermeta,
				$new_prefix,
				strlen( $old_prefix ) + 1,
				$wpdb->esc_like( $old_prefix ) . '%'
			)
		);
		return array(
			'roles'    => (int) $roles,
			'usermeta' => (int) $meta,
		);
	}

	/**
	 * Keep Packvault active in the restored site so the restore can finish.
	 */
	public static function keep_self_active() {
		global $wpdb;
		$options = self::TEMP_PREFIX . 'options';
		$value   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options, 'active_plugins' ) );
		if ( null === $value ) {
			return;
		}
		$plugins = maybe_unserialize( $value );
		$plugins = is_array( $plugins ) ? $plugins : array();
		if ( in_array( PACKVAULT_BASENAME, $plugins, true ) ) {
			return;
		}
		$plugins[] = PACKVAULT_BASENAME;
		sort( $plugins );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $options, serialize( array_values( $plugins ) ), 'active_plugins' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Swap the imported temp tables in for the live ones in one RENAME.
	 *
	 * @throws \RuntimeException When the rename fails.
	 */
	public static function swap() {
		global $wpdb;
		self::drop_prefixed( self::OLD_PREFIX );

		$temp = self::tables_with_prefix( self::TEMP_PREFIX );
		if ( ! $temp ) {
			throw new \RuntimeException( esc_html__( 'No database tables were imported from the backup.', 'pack-vault' ) );
		}
		$existing = array_flip( self::tables_with_prefix( $wpdb->base_prefix ) );
		$renames  = array();
		foreach ( $temp as $table ) {
			$suffix = substr( $table, strlen( self::TEMP_PREFIX ) );
			$live   = $wpdb->base_prefix . $suffix;
			if ( isset( $existing[ $live ] ) ) {
				$renames[] = "`{$live}` TO `" . self::OLD_PREFIX . $suffix . '`';
			}
			$renames[] = "`{$table}` TO `{$live}`";
		}

		$error = self::query( 'RENAME TABLE ' . implode( ', ', $renames ) );
		if ( null !== $error ) {
			throw new \RuntimeException(
				/* translators: %s: database error */
				sprintf( esc_html__( 'Activating the restored database failed: %s', 'pack-vault' ), esc_html( $error ) )
			);
		}
		self::drop_prefixed( self::OLD_PREFIX );
	}
}
