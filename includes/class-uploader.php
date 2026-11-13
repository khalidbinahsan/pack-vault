<?php
/**
 * Chunked uploads of any size.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Receives a backup in small raw-body chunks, so upload_max_filesize and
 * post_max_size never limit the size of the backup. Uploads are resumable:
 * the server always reports how many bytes it already has.
 */
final class Uploader {

	/**
	 * Chunk size the browser should use.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		$post_max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$size     = 8 * MB_IN_BYTES;
		if ( $post_max > 0 ) {
			$size = min( $size, (int) ( $post_max * 0.8 ) );
		}
		/**
		 * Filter the upload chunk size in bytes.
		 *
		 * @param int $size Chunk size.
		 */
		return (int) max( 256 * KB_IN_BYTES, apply_filters( 'packvault_upload_chunk_size', $size ) );
	}

	/**
	 * Start an upload.
	 *
	 * @param string $name Original file name.
	 * @param int    $size Total size.
	 * @return array
	 * @throws \RuntimeException On invalid input.
	 */
	public static function start( $name, $size ) {
		$name = sanitize_file_name( wp_basename( (string) $name ) );
		if ( '.' . Storage::EXT !== strtolower( substr( $name, - ( strlen( Storage::EXT ) + 1 ) ) ) ) {
			throw new \RuntimeException( esc_html__( 'Please choose a .packvault backup file.', 'pack-vault' ) );
		}
		if ( (int) $size <= Archive::HEADER_SIZE ) {
			throw new \RuntimeException( esc_html__( 'The file is empty.', 'pack-vault' ) );
		}
		Storage::ensure();
		$id   = Util::random_id( 8 );
		$meta = array(
			'name'    => $name,
			'size'    => (int) $size,
			'started' => time(),
		);
		file_put_contents( self::meta_path( $id ), wp_json_encode( $meta ) );
		file_put_contents( self::part_path( $id ), '' );
		return array(
			'id'         => $id,
			'received'   => 0,
			'chunk_size' => self::chunk_size(),
		);
	}

	/**
	 * Append a chunk.
	 *
	 * @param string $id     Upload id.
	 * @param int    $offset Offset the client believes it is at.
	 * @param string $data   Chunk bytes.
	 * @return array
	 * @throws \RuntimeException On invalid input.
	 */
	public static function append( $id, $offset, $data ) {
		$meta = self::meta( $id );
		$part = self::part_path( $id );
		clearstatcache( true, $part );
		$have = (int) filesize( $part );

		if ( (int) $offset !== $have ) {
			// Client and server disagree (e.g. a retried chunk); tell it where to resume.
			return array(
				'received' => $have,
				'resync'   => true,
			);
		}
		if ( $have + strlen( $data ) > $meta['size'] ) {
			throw new \RuntimeException( esc_html__( 'The upload is larger than announced.', 'pack-vault' ) );
		}
		if ( 0 === $have && 0 !== strpos( $data, Archive::MAGIC ) ) {
			self::abort( $id );
			throw new \RuntimeException( esc_html__( 'This file is not a Packvault backup.', 'pack-vault' ) );
		}

		$handle = fopen( $part, 'ab' );
		if ( ! $handle || fwrite( $handle, $data ) !== strlen( $data ) ) {
			if ( $handle ) {
				fclose( $handle );
			}
			throw new \RuntimeException( esc_html__( 'Saving the uploaded chunk failed. The disk may be full.', 'pack-vault' ) );
		}
		fclose( $handle );

		return array( 'received' => $have + strlen( $data ) );
	}

	/**
	 * Finish an upload and move it into the backup folder.
	 *
	 * @param string $id Upload id.
	 * @return array Backup name and size.
	 * @throws \RuntimeException When incomplete or invalid.
	 */
	public static function complete( $id ) {
		$meta = self::meta( $id );
		$part = self::part_path( $id );
		clearstatcache( true, $part );
		if ( (int) filesize( $part ) !== $meta['size'] ) {
			throw new \RuntimeException( esc_html__( 'The upload is incomplete.', 'pack-vault' ) );
		}
		if ( ! Archive::is_archive( $part ) ) {
			self::abort( $id );
			throw new \RuntimeException( esc_html__( 'This file is not a Packvault backup.', 'pack-vault' ) );
		}

		$name = Storage::is_valid_name( $meta['name'] ) ? $meta['name'] : Storage::generate_name();
		$name = Storage::unique_name( $name );
		if ( ! @rename( $part, Storage::dir() . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( esc_html__( 'Could not move the uploaded backup into place.', 'pack-vault' ) );
		}
		wp_delete_file( self::meta_path( $id ) );

		return array(
			'name' => $name,
			'size' => $meta['size'],
		);
	}

	/**
	 * Abort an upload.
	 *
	 * @param string $id Upload id.
	 */
	public static function abort( $id ) {
		if ( self::valid_id( $id ) ) {
			wp_delete_file( self::part_path( $id ) );
			wp_delete_file( self::meta_path( $id ) );
		}
	}

	/**
	 * Remove uploads abandoned for more than a day.
	 */
	public static function prune() {
		foreach ( (array) glob( Storage::temp_dir() . '/upload-*.json' ) as $file ) {
			if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				$id = substr( basename( $file, '.json' ), 7 );
				self::abort( $id );
			}
		}
	}

	/**
	 * Upload metadata.
	 *
	 * @param string $id Upload id.
	 * @return array
	 * @throws \RuntimeException When unknown.
	 */
	private static function meta( $id ) {
		if ( ! self::valid_id( $id ) || ! is_readable( self::meta_path( $id ) ) ) {
			throw new \RuntimeException( esc_html__( 'Unknown upload. Please start again.', 'pack-vault' ) );
		}
		$meta = json_decode( (string) file_get_contents( self::meta_path( $id ) ), true );
		if ( ! is_array( $meta ) ) {
			throw new \RuntimeException( esc_html__( 'Unknown upload. Please start again.', 'pack-vault' ) );
		}
		return $meta;
	}

	/**
	 * Valid id.
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	private static function valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[a-f0-9]{16}$/', $id );
	}

	/**
	 * Partial file path.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	private static function part_path( $id ) {
		return Storage::temp_dir() . '/upload-' . $id . '.part';
	}

	/**
	 * Metadata path.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	private static function meta_path( $id ) {
		return Storage::temp_dir() . '/upload-' . $id . '.json';
	}
}
