<?php
/**
 * Streaming downloads of any size.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Streams a backup through PHP (the folder itself is not web-accessible),
 * with HTTP Range support so browsers and download managers can resume.
 */
final class Downloader {

	const ACTION = 'packvault_download';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Signed download URL for a backup.
	 *
	 * Raw (unescaped) URL: it is delivered to JS via REST, so it must not be
	 * HTML-escaped like wp_nonce_url() does (that turns & into &amp; and
	 * breaks the nonce). Escape with esc_url() if ever printed into HTML.
	 *
	 * @param string $name Backup name.
	 * @return string
	 */
	public static function url( $name ) {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'file'     => rawurlencode( $name ),
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Stream the file.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download backups.', 'pack-vault' ), 403 );
		}
		check_admin_referer( self::ACTION );

		// Validated against a strict whitelist pattern by Storage::path().
		$name = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$path = Storage::path( $name );
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Backup not found.', 'pack-vault' ), 404 );
		}

		Util::prepare_environment();
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$size  = (int) filesize( $path );
		$start = 0;
		$end   = $size - 1;

		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			if ( '' === $m[1] && '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				if ( '' !== $m[2] ) {
					$end = min( (int) $m[2], $size - 1 );
				}
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		} else {
			status_header( 200 );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'X-Content-Type-Options: nosniff' );

		$handle = fopen( $path, 'rb' );
		fseek( $handle, $start );
		$remaining = $end - $start + 1;
		while ( $remaining > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$buffer = fread( $handle, (int) min( 1048576, $remaining ) );
			if ( false === $buffer || '' === $buffer ) {
				break;
			}
			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file stream.
			flush();
			$remaining -= strlen( $buffer );
		}
		fclose( $handle );
		exit;
	}
}
