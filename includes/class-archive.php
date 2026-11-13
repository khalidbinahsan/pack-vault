<?php
/**
 * The .packvault single-file archive format.
 *
 * Layout (all integers little-endian):
 *
 *   File header  (16 bytes)
 *     magic        8 bytes  "PKVAULT\x01"
 *     version      uint16
 *     reserved     6 bytes  (zero)
 *
 *   Entry (repeated)
 *     marker       4 bytes  "SAEN"
 *     path length  uint16
 *     data size    uint64
 *     mtime        uint32
 *     path         <path length> bytes (UTF-8, forward slashes)
 *     data         <data size> bytes (raw, uncompressed)
 *
 *   Footer
 *     marker       4 bytes  "SAEF"
 *     entry count  uint64
 *
 * Reserved entry paths:
 *   packvault/manifest.json  – always the first entry
 *   packvault/database.sql   – optional database dump
 *   content/<path>         – files relative to wp-content
 *
 * Entries are streamed in fixed-size chunks so archives of any size can be
 * written and read across many short PHP requests.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Format constants and helpers.
 */
final class Archive {

	const MAGIC             = "PKVAULT\x01"; // Exactly 8 bytes: HEADER_SIZE depends on it.
	const VERSION           = 1;
	const HEADER_SIZE       = 16;
	const ENTRY_MARKER      = 'SAEN';
	const END_MARKER        = 'SAEF';
	const ENTRY_HEADER_SIZE = 18;
	const CHUNK_SIZE        = 1048576;

	const MANIFEST_PATH = 'packvault/manifest.json';
	const DATABASE_PATH = 'packvault/database.sql';
	const CONTENT_PREFIX = 'content/';

	/**
	 * Binary file header.
	 *
	 * @return string
	 */
	public static function file_header() {
		return self::MAGIC . pack( 'v', self::VERSION ) . str_repeat( "\0", 6 );
	}

	/**
	 * Binary entry header.
	 *
	 * @param string $path  Entry path.
	 * @param int    $size  Data size.
	 * @param int    $mtime Modification time.
	 * @return string
	 */
	public static function entry_header( $path, $size, $mtime ) {
		return self::ENTRY_MARKER . pack( 'vPV', strlen( $path ), (int) $size, (int) $mtime & 0xFFFFFFFF ) . $path;
	}

	/**
	 * Whether a file starts with the Packvault magic bytes.
	 *
	 * @param string $file File path.
	 * @return bool
	 */
	public static function is_archive( $file ) {
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}
		$magic = fread( $handle, strlen( self::MAGIC ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return self::MAGIC === $magic;
	}

	/**
	 * Validate an entry path, or return null if unsafe.
	 *
	 * Only canonical relative paths are accepted (exactly what Archive_Writer
	 * produces): no absolute paths, drive letters, NUL bytes, and no empty,
	 * "." or ".." segments. Rejecting "." and "//" matters because callers
	 * compare path prefixes to protect Packvault's own folders; a path like
	 * "content/./plugins/packvault/x" would otherwise slip past those checks.
	 *
	 * @param string $path Entry path.
	 * @return string|null
	 */
	public static function safe_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		if ( '' === $path || '/' === $path[0] || false !== strpos( $path, "\0" ) || preg_match( '#^[A-Za-z]:#', $path ) ) {
			return null;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}
		return $path;
	}
}
