<?php
/**
 * Small shared helpers.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Utility helpers.
 */
final class Util {

	/**
	 * Give long-running work as much room as the host allows.
	 */
	public static function prepare_environment() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Backups of large sites need more than the default limit.
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		wp_raise_memory_limit( 'admin' );
	}

	/**
	 * Deadline (microtime) for the current request's slice of work.
	 *
	 * Work is split into short slices so it survives any PHP max_execution_time.
	 *
	 * @return float
	 */
	public static function deadline() {
		$budget = (int) Settings::get( 'time_budget' );
		$budget = max( 5, min( 120, $budget ) );

		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			// Leave headroom for the host-imposed limit.
			$budget = min( $budget, max( 5, $max - 5 ) );
		}
		return microtime( true ) + $budget;
	}

	/**
	 * Random lowercase hex id.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	public static function random_id( $bytes = 8 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Plain-text version of an exception message.
	 *
	 * Exception messages are HTML-escaped where they are thrown. They are
	 * consumed as plain text (JSON for the React UI, which escapes on render,
	 * plain-text emails and the job log), so decode the entities once here.
	 *
	 * @param \Throwable $e Exception.
	 * @return string
	 */
	public static function message( \Throwable $e ) {
		return html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Normalised path without trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function path( $path ) {
		return untrailingslashit( wp_normalize_path( $path ) );
	}

	/**
	 * The WordPress content directory (normalised).
	 *
	 * @return string
	 */
	public static function content_dir() {
		return self::path( WP_CONTENT_DIR );
	}

	/**
	 * Directory of this plugin relative to wp-content, e.g. "plugins/packvault".
	 *
	 * @return string
	 */
	public static function self_relative_dir() {
		$plugin_dir = self::path( dirname( PACKVAULT_FILE ) );
		$content    = self::content_dir();
		if ( 0 === strpos( $plugin_dir, $content . '/' ) ) {
			return substr( $plugin_dir, strlen( $content ) + 1 );
		}
		return 'plugins/' . dirname( PACKVAULT_BASENAME );
	}

	/**
	 * Remove a directory tree (used for Packvault's own temp folders only).
	 *
	 * @param string $dir Directory.
	 */
	public static function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
