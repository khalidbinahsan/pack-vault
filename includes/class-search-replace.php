<?php
/**
 * Serialization-safe search & replace for migrations.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

/**
 * Replaces old URLs/paths with new ones in the imported temp tables.
 *
 * Serialized PHP values are rewritten by a byte-level walker that fixes string
 * lengths without ever calling unserialize(), so no objects are instantiated.
 */
final class Search_Replace {

	const BATCH = 300;

	/**
	 * Build the replacement pairs for a migration.
	 *
	 * @param array $manifest Source manifest.
	 * @return array{0: string[], 1: string[]}
	 */
	public static function pairs( array $manifest ) {
		$map = array();

		// A list of pairs (not a keyed array): home and siteurl are often identical.
		$urls = array(
			array( isset( $manifest['home_url'] ) ? $manifest['home_url'] : '', home_url() ),
			array( isset( $manifest['site_url'] ) ? $manifest['site_url'] : '', site_url() ),
		);
		foreach ( $urls as list( $old, $new ) ) {
			$old = untrailingslashit( (string) $old );
			$new = untrailingslashit( (string) $new );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$host_path = preg_replace( '#^https?:#i', '', $old );
			$variants  = array(
				'http:' . $host_path  => $new,
				'https:' . $host_path => $new,
				$host_path            => preg_replace( '#^https?:#i', '', $new ),
			);
			foreach ( $variants as $from => $to ) {
				// The home URL is mapped first and wins when home and siteurl
				// were identical on the source site.
				if ( ! isset( $map[ $from ] ) ) {
					$map[ $from ] = $to;
				}
			}
		}

		$paths = array(
			array( isset( $manifest['content_dir'] ) ? $manifest['content_dir'] : '', Util::content_dir() ),
			array( isset( $manifest['abspath'] ) ? $manifest['abspath'] : '', Util::path( ABSPATH ) ),
		);
		foreach ( $paths as list( $old, $new ) ) {
			$old = untrailingslashit( (string) $old );
			if ( strlen( $old ) > 3 && $old !== $new && ! isset( $map[ $old ] ) ) {
				$map[ $old ] = $new;
			}
		}

		// JSON-escaped variants (Gutenberg blocks, page builders).
		foreach ( $map as $old => $new ) {
			$escaped = str_replace( '/', '\\/', $old );
			if ( $escaped !== $old ) {
				$map[ $escaped ] = str_replace( '/', '\\/', $new );
			}
		}

		// Longest first so overlapping prefixes resolve correctly.
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return array( array_keys( $map ), array_values( $map ) );
	}

	/**
	 * Initial state over all temp tables.
	 *
	 * @param array $manifest Source manifest.
	 * @return array
	 */
	public static function init_state( array $manifest ) {
		list( $from, $to ) = self::pairs( $manifest );
		return array(
			'from'   => $from,
			'to'     => $to,
			'tables' => $from ? Database_Importer::tables_with_prefix( Database_Importer::TEMP_PREFIX ) : array(),
			'index'  => 0,
			'cursor' => null,
			'offset' => 0,
			'meta'   => null,
			'rows'   => 0,
		);
	}

	/**
	 * Replace until the deadline.
	 *
	 * @param array $state    State (modified).
	 * @param float $deadline Microtime deadline.
	 * @return bool True when done.
	 */
	public static function step( array &$state, $deadline ) {
		global $wpdb;

		while ( $state['index'] < count( $state['tables'] ) && microtime( true ) < $deadline ) {
			$table = $state['tables'][ $state['index'] ];
			if ( null === $state['meta'] ) {
				$state['meta']   = self::table_meta( $table );
				$state['cursor'] = null;
				$state['offset'] = 0;
			}
			$meta = $state['meta'];
			if ( ! $meta['pk'] || ! $meta['text'] ) {
				self::next_table( $state );
				continue;
			}

			$select = '`' . implode( '`,`', array_merge( $meta['pk'], $meta['text'] ) ) . '`';
			$order  = '`' . implode( '`,`', $meta['pk'] ) . '`';

			if ( $meta['int_pk'] ) {
				$pk    = $meta['pk'][0];
				$likes = array();
				foreach ( $meta['text'] as $column ) {
					foreach ( $state['from'] as $needle ) {
						$likes[] = $wpdb->prepare( "`{$column}` LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' );
					}
				}
				$where = '(' . implode( ' OR ', $likes ) . ')';
				if ( null !== $state['cursor'] ) {
					$where .= $wpdb->prepare( " AND `{$pk}` > %d", $state['cursor'] );
				}
				$sql = "SELECT {$select} FROM `{$table}` WHERE {$where} ORDER BY `{$pk}` ASC LIMIT " . self::BATCH;
			} else {
				$sql = "SELECT {$select} FROM `{$table}` ORDER BY {$order} LIMIT " . (int) $state['offset'] . ', ' . self::BATCH;
			}

			// Identifiers come from SHOW TABLES/COLUMNS of this database; conditions are prepared above.
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( empty( $rows ) ) {
				self::next_table( $state );
				continue;
			}

			foreach ( $rows as $row ) {
				$changes = array();
				foreach ( $meta['text'] as $column ) {
					if ( null === $row[ $column ] ) {
						continue;
					}
					$new = self::replace( $row[ $column ], $state['from'], $state['to'] );
					if ( $new !== $row[ $column ] ) {
						$changes[ $column ] = $new;
					}
				}
				if ( $changes ) {
					$where = array();
					foreach ( $meta['pk'] as $key ) {
						$where[ $key ] = $row[ $key ];
					}
					$wpdb->update( $table, $changes, $where );
					++$state['rows'];
				}
			}

			if ( $meta['int_pk'] ) {
				$last            = end( $rows );
				$state['cursor'] = $last[ $meta['pk'][0] ];
			} else {
				$state['offset'] += count( $rows );
			}
		}

		return $state['index'] >= count( $state['tables'] );
	}

	/**
	 * Move to the next table.
	 *
	 * @param array $state State.
	 */
	private static function next_table( array &$state ) {
		++$state['index'];
		$state['meta']   = null;
		$state['cursor'] = null;
		$state['offset'] = 0;
	}

	/**
	 * Primary key and text columns of a table.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	private static function table_meta( $table ) {
		global $wpdb;
		$cols   = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$pk     = array();
		$text   = array();
		$int_pk = false;
		foreach ( (array) $cols as $col ) {
			$type = strtolower( $col['Type'] );
			if ( 'PRI' === $col['Key'] ) {
				$pk[]   = $col['Field'];
				$int_pk = (bool) preg_match( '/int/', $type );
			} elseif ( preg_match( '/char|text|json/', $type ) && 'guid' !== $col['Field'] ) {
				$text[] = $col['Field'];
			}
		}
		return array(
			'pk'     => $pk,
			'text'   => $text,
			'int_pk' => 1 === count( $pk ) && $int_pk,
		);
	}

	/**
	 * Replace inside a value, serialization-aware.
	 *
	 * @param string   $value Value.
	 * @param string[] $from  Needles.
	 * @param string[] $to    Replacements.
	 * @return string
	 */
	public static function replace( $value, array $from, array $to ) {
		$value = (string) $value;
		if ( is_serialized( $value, false ) ) {
			$offset = 0;
			$result = self::walk( $value, $offset, $from, $to );
			if ( null !== $result && strlen( $value ) === $offset ) {
				return $result;
			}
		}
		// strtr() is single-pass, so a replacement is never replaced again.
		return strtr( $value, array_combine( $from, $to ) );
	}

	/**
	 * Re-emit one serialized value starting at $pos, replacing inside strings.
	 *
	 * @param string   $data Serialized data.
	 * @param int      $pos  Position (advanced).
	 * @param string[] $from Needles.
	 * @param string[] $to   Replacements.
	 * @return string|null Rewritten value or null when the data is malformed.
	 */
	private static function walk( $data, &$pos, array $from, array $to ) {
		if ( ! isset( $data[ $pos ] ) ) {
			return null;
		}
		$type = $data[ $pos ];

		switch ( $type ) {
			case 'N':
				if ( 'N;' !== substr( $data, $pos, 2 ) ) {
					return null;
				}
				$pos += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $data, ';', $pos );
				if ( false === $end ) {
					return null;
				}
				$out = substr( $data, $pos, $end - $pos + 1 );
				$pos = $end + 1;
				return $out;

			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $data, $m, 0, $pos ) ) {
					return null;
				}
				$len   = (int) $m[1];
				$start = $pos + strlen( $m[0] );
				if ( '";' !== substr( $data, $start + $len, 2 ) ) {
					return null;
				}
				$string = substr( $data, $start, $len );
				$pos    = $start + $len + 2;
				$string = self::replace( $string, $from, $to );
				return 's:' . strlen( $string ) . ':"' . $string . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $data, $m, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $m[0] );
				$out  = $m[0];
				for ( $i = 0, $n = 2 * (int) $m[1]; $i < $n; $i++ ) {
					$item = self::walk( $data, $pos, $from, $to );
					if ( null === $item ) {
						return null;
					}
					$out .= $item;
				}
				if ( ! isset( $data[ $pos ] ) || '}' !== $data[ $pos ] ) {
					return null;
				}
				++$pos;
				return $out . '}';

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $data, $m, 0, $pos ) ) {
					return null;
				}
				$class_end = $pos + strlen( $m[0] ) + (int) $m[1];
				if ( ! preg_match( '/\G":(\d+):\{/', $data, $m2, 0, $class_end ) ) {
					return null;
				}
				$out = substr( $data, $pos, $class_end - $pos ) . $m2[0];
				$pos = $class_end + strlen( $m2[0] );
				for ( $i = 0, $n = 2 * (int) $m2[1]; $i < $n; $i++ ) {
					$item = self::walk( $data, $pos, $from, $to );
					if ( null === $item ) {
						return null;
					}
					$out .= $item;
				}
				if ( ! isset( $data[ $pos ] ) || '}' !== $data[ $pos ] ) {
					return null;
				}
				++$pos;
				return $out . '}';

			case 'C':
				// Custom-serialized object: copy verbatim (its payload format is opaque).
				if ( ! preg_match( '/\GC:(\d+):"/', $data, $m, 0, $pos ) ) {
					return null;
				}
				$class_end = $pos + strlen( $m[0] ) + (int) $m[1];
				if ( ! preg_match( '/\G":(\d+):\{/', $data, $m2, 0, $class_end ) ) {
					return null;
				}
				$end = $class_end + strlen( $m2[0] ) + (int) $m2[1];
				if ( ! isset( $data[ $end ] ) || '}' !== $data[ $end ] ) {
					return null;
				}
				$out = substr( $data, $pos, $end - $pos + 1 );
				$pos = $end + 1;
				return $out;

			case 'E':
				if ( ! preg_match( '/\GE:(\d+):"/', $data, $m, 0, $pos ) ) {
					return null;
				}
				$end = $pos + strlen( $m[0] ) + (int) $m[1];
				if ( '";' !== substr( $data, $end, 2 ) ) {
					return null;
				}
				$out = substr( $data, $pos, $end - $pos + 2 );
				$pos = $end + 2;
				return $out;
		}
		return null;
	}
}
