<?php
/**
 * Resumable .packvault archive reader.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Reads entries from an archive by byte offset.
 */
final class Archive_Reader {

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Archive size.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Open and validate an archive.
	 *
	 * @param string $path Archive path.
	 * @throws \RuntimeException When the file is missing or not a Packvault archive.
	 */
	public function __construct( $path ) {
		$this->handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $this->handle ) {
			throw new \RuntimeException( esc_html__( 'The backup file could not be opened.', 'pack-vault' ) );
		}
		$this->size = (int) filesize( $path );
		$header     = fread( $this->handle, Archive::HEADER_SIZE );
		if ( strlen( (string) $header ) !== Archive::HEADER_SIZE || 0 !== strpos( $header, Archive::MAGIC ) ) {
			$this->close();
			throw new \RuntimeException( esc_html__( 'This is not a valid Packvault backup file.', 'pack-vault' ) );
		}
		$version = unpack( 'v', substr( $header, strlen( Archive::MAGIC ), 2 ) );
		if ( (int) $version[1] > Archive::VERSION ) {
			$this->close();
			throw new \RuntimeException( esc_html__( 'This backup was made by a newer version of Packvault. Please update the plugin.', 'pack-vault' ) );
		}
	}

	/**
	 * Archive size in bytes.
	 *
	 * @return int
	 */
	public function size() {
		return $this->size;
	}

	/**
	 * Read the entry header at an offset.
	 *
	 * @param int $offset Offset of an entry header.
	 * @return array|null Entry info, or null at the end of the archive.
	 * @throws \RuntimeException When the archive is truncated or corrupt.
	 */
	public function entry_at( $offset ) {
		fseek( $this->handle, (int) $offset );
		$marker = fread( $this->handle, 4 );

		if ( Archive::END_MARKER === $marker || '' === $marker || false === $marker ) {
			if ( Archive::END_MARKER !== $marker ) {
				throw new \RuntimeException( esc_html__( 'The backup file is incomplete (it ends unexpectedly). It may still be uploading or was cut off.', 'pack-vault' ) );
			}
			return null;
		}
		if ( Archive::ENTRY_MARKER !== $marker ) {
			throw new \RuntimeException( esc_html__( 'The backup file is corrupt.', 'pack-vault' ) );
		}

		$raw = fread( $this->handle, Archive::ENTRY_HEADER_SIZE - 4 );
		if ( strlen( (string) $raw ) !== Archive::ENTRY_HEADER_SIZE - 4 ) {
			throw new \RuntimeException( esc_html__( 'The backup file is corrupt.', 'pack-vault' ) );
		}
		$info = unpack( 'vlen/Psize/Vmtime', $raw );
		$path = $info['len'] > 0 ? fread( $this->handle, $info['len'] ) : '';

		$data_offset = (int) $offset + Archive::ENTRY_HEADER_SIZE + (int) $info['len'];
		if ( $data_offset + (int) $info['size'] > $this->size ) {
			throw new \RuntimeException( esc_html__( 'The backup file is incomplete (it ends unexpectedly). It may still be uploading or was cut off.', 'pack-vault' ) );
		}

		return array(
			'path'        => (string) $path,
			'size'        => (int) $info['size'],
			'mtime'       => (int) $info['mtime'],
			'data_offset' => $data_offset,
			'next'        => $data_offset + (int) $info['size'],
		);
	}

	/**
	 * Read an entry's data into memory (small entries only).
	 *
	 * @param array $entry Entry info.
	 * @param int   $max   Maximum bytes.
	 * @return string
	 */
	public function read( array $entry, $max = 1048576 ) {
		fseek( $this->handle, $entry['data_offset'] );
		$length = min( $entry['size'], $max );
		return $length > 0 ? (string) fread( $this->handle, $length ) : '';
	}

	/**
	 * Copy (part of) an entry to a file on disk.
	 *
	 * @param array  $entry    Entry info.
	 * @param string $dest     Destination path.
	 * @param int    $done     Bytes already copied.
	 * @param float  $deadline Microtime deadline.
	 * @return int Bytes copied so far.
	 * @throws \RuntimeException When the destination cannot be written.
	 */
	public function extract( array $entry, $dest, $done, $deadline ) {
		$done = (int) $done;
		$out  = @fopen( $dest, 0 === $done ? 'wb' : 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $out ) {
			throw new \RuntimeException(
				/* translators: %s: file path */
				sprintf( esc_html__( 'Could not write %s', 'pack-vault' ), esc_html( $dest ) )
			);
		}
		if ( $done > 0 ) {
			ftruncate( $out, $done );
			fseek( $out, $done );
		}

		fseek( $this->handle, $entry['data_offset'] + $done );
		while ( $done < $entry['size'] ) {
			$length = min( Archive::CHUNK_SIZE, $entry['size'] - $done );
			$buffer = fread( $this->handle, $length );
			if ( false === $buffer || '' === $buffer ) {
				fclose( $out );
				throw new \RuntimeException( esc_html__( 'Reading the backup file failed.', 'pack-vault' ) );
			}
			if ( fwrite( $out, $buffer ) !== strlen( $buffer ) ) {
				fclose( $out );
				throw new \RuntimeException( esc_html__( 'Writing a restored file failed. The disk may be full.', 'pack-vault' ) );
			}
			$done += strlen( $buffer );
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		fclose( $out );
		return $done;
	}

	/**
	 * Close the archive.
	 */
	public function close() {
		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Read the manifest of an archive without extracting anything.
	 *
	 * @param string $path Archive path.
	 * @return array|null
	 */
	public static function manifest( $path ) {
		try {
			$reader = new self( $path );
			$entry  = $reader->entry_at( Archive::HEADER_SIZE );
			$data   = ( $entry && Archive::MANIFEST_PATH === $entry['path'] ) ? $reader->read( $entry ) : '';
			$reader->close();
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$manifest = json_decode( $data, true );
		return is_array( $manifest ) ? $manifest : null;
	}
}
