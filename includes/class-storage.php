<?php
/**
 * Local backup storage (wp-content/packvault-backups).
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the protected backup directory and the .packvault files in it.
 */
final class Storage {

	const DIR_NAME = 'packvault-backups';
	const EXT      = 'packvault';

	/**
	 * Backup directory.
	 *
	 * @return string
	 */
	public static function dir() {
		return Util::content_dir() . '/' . self::DIR_NAME;
	}

	/**
	 * Job state directory.
	 *
	 * @return string
	 */
	public static function jobs_dir() {
		return self::dir() . '/jobs';
	}

	/**
	 * Temp working directory.
	 *
	 * @return string
	 */
	public static function temp_dir() {
		return self::dir() . '/tmp';
	}

	/**
	 * Create the folders and drop access-protection files into them.
	 *
	 * @return bool
	 */
	public static function ensure() {
		$ok = true;
		foreach ( array( self::dir(), self::jobs_dir(), self::temp_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				$ok = false;
				continue;
			}
			self::protect( $dir );
		}
		return $ok;
	}

	/**
	 * Block direct web access to a directory.
	 *
	 * @param string $dir Directory.
	 */
	private static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden. Protected by Packvault.\n",
			'.htaccess'  => "# Packvault – deny direct access to backups\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'robots.txt' => "User-agent: *\nDisallow: /\n",
		);
		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Whether a backup file name is safe and well formed.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_valid_name( $name ) {
		return is_string( $name ) && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.' . self::EXT . '$/', $name );
	}

	/**
	 * Absolute path of a backup, or null when the name is invalid.
	 *
	 * @param string $name File name.
	 * @return string|null
	 */
	public static function path( $name ) {
		return self::is_valid_name( $name ) ? self::dir() . '/' . $name : null;
	}

	/**
	 * A fresh, hard-to-guess backup file name.
	 *
	 * @return string
	 */
	public static function generate_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/[^A-Za-z0-9.-]/', '', (string) $host );
		$host = $host ? $host : 'site';
		return sprintf( '%s-%s-%s.%s', $host, wp_date( 'Ymd-His' ), Util::random_id( 8 ), self::EXT );
	}

	/**
	 * Make a name unique inside the backup directory.
	 *
	 * @param string $name Desired name.
	 * @return string
	 */
	public static function unique_name( $name ) {
		$base = substr( $name, 0, - ( strlen( self::EXT ) + 1 ) );
		$i    = 1;
		while ( file_exists( self::dir() . '/' . $name ) ) {
			$name = $base . '-' . $i . '.' . self::EXT;
			++$i;
		}
		return $name;
	}

	/**
	 * All backups, newest first.
	 *
	 * @return array[]
	 */
	public static function list_backups() {
		$files = glob( self::dir() . '/*.' . self::EXT );
		$list  = array();
		foreach ( (array) $files as $file ) {
			$name = basename( $file );
			if ( ! self::is_valid_name( $name ) ) {
				continue;
			}
			$manifest = Archive_Reader::manifest( $file );
			$list[]   = array(
				'name'     => $name,
				'size'     => (int) filesize( $file ),
				'created'  => (int) filemtime( $file ),
				'valid'    => null !== $manifest,
				'site_url' => isset( $manifest['home_url'] ) ? $manifest['home_url'] : '',
				'wp'       => isset( $manifest['wp_version'] ) ? $manifest['wp_version'] : '',
				'note'     => isset( $manifest['note'] ) ? $manifest['note'] : '',
				'source'   => isset( $manifest['source'] ) ? $manifest['source'] : 'upload',
				'contents' => isset( $manifest['contents'] ) ? $manifest['contents'] : array(),
			);
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		return $list;
	}

	/**
	 * Delete a backup.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function delete( $name ) {
		$path = self::path( $name );
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/**
	 * Keep only the newest $keep backups (0 = keep all).
	 *
	 * @param int $keep Number to keep.
	 */
	public static function apply_retention( $keep ) {
		$keep = (int) $keep;
		if ( $keep < 1 ) {
			return;
		}
		$backups = self::list_backups();
		foreach ( array_slice( $backups, $keep ) as $old ) {
			self::delete( $old['name'] );
		}
	}

	/**
	 * Storage summary for the dashboard.
	 *
	 * @return array
	 */
	public static function stats() {
		$backups = self::list_backups();
		$used    = 0;
		foreach ( $backups as $backup ) {
			$used += $backup['size'];
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( self::dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return array(
			'count'    => count( $backups ),
			'used'     => $used,
			'free'     => false === $free ? null : (float) $free,
			'writable' => wp_is_writable( self::dir() ),
			'path'     => self::dir(),
		);
	}
}
