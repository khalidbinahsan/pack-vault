<?php
/**
 * Resumable .packvault archive writer.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Appends entries to an archive. Every instance resumes at a known-good byte
 * offset (anything written after it by an interrupted request is truncated).
 */
final class Archive_Writer {

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Open the archive at a known-good offset.
	 *
	 * @param string $path   Archive path.
	 * @param int    $offset Offset to resume from (0 = new archive).
	 * @throws \RuntimeException When the file cannot be opened.
	 */
	public function __construct( $path, $offset ) {
		$this->handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $this->handle ) {
			throw new \RuntimeException( esc_html__( 'Could not open the backup file for writing. Check that wp-content/packvault-backups is writable.', 'pack-vault' ) );
		}
		$offset = (int) $offset;
		ftruncate( $this->handle, $offset );
		fseek( $this->handle, $offset );
		if ( 0 === $offset ) {
			$this->write( Archive::file_header() );
		}
	}

	/**
	 * Current size of the archive.
	 *
	 * @return int
	 */
	public function tell() {
		return (int) ftell( $this->handle );
	}

	/**
	 * Add a small in-memory entry.
	 *
	 * @param string $path Entry path.
	 * @param string $data Contents.
	 */
	public function add_string( $path, $data ) {
		$this->write( Archive::entry_header( $path, strlen( $data ), time() ) );
		$this->write( $data );
	}

	/**
	 * Copy (part of) a file into the archive.
	 *
	 * The entry header is written when $done is 0. Copying stops at the deadline
	 * and the caller resumes later with the returned byte count. If the source
	 * shrinks or disappears meanwhile, the entry is zero-padded so the archive
	 * structure stays valid.
	 *
	 * @param string $source   Absolute source path.
	 * @param string $path     Entry path.
	 * @param int    $size     Declared entry size.
	 * @param int    $mtime    Modification time.
	 * @param int    $done     Bytes already copied.
	 * @param float  $deadline Microtime deadline.
	 * @return int Bytes copied so far (=== $size when complete).
	 */
	public function add_file_chunk( $source, $path, $size, $mtime, $done, $deadline ) {
		$size = (int) $size;
		$done = (int) $done;

		if ( 0 === $done ) {
			$this->write( Archive::entry_header( $path, $size, $mtime ) );
		}
		if ( $done >= $size ) {
			return $size;
		}

		$src = @fopen( $source, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $src && $done > 0 ) {
			fseek( $src, $done );
		}

		while ( $done < $size ) {
			$length = min( Archive::CHUNK_SIZE, $size - $done );
			$buffer = $src ? fread( $src, $length ) : '';
			if ( false === $buffer ) {
				$buffer = '';
			}
			if ( strlen( $buffer ) < $length ) {
				$buffer = str_pad( $buffer, $length, "\0" );
			}
			$this->write( $buffer );
			$done += $length;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( $src ) {
			fclose( $src );
		}
		return $done;
	}

	/**
	 * Write the footer.
	 *
	 * @param int $count Number of entries.
	 */
	public function finish( $count ) {
		$this->write( Archive::END_MARKER . pack( 'P', (int) $count ) );
	}

	/**
	 * Flush and close.
	 */
	public function close() {
		if ( $this->handle ) {
			fflush( $this->handle );
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Write everything or fail loudly.
	 *
	 * @param string $data Bytes.
	 * @throws \RuntimeException On short write (usually a full disk).
	 */
	private function write( $data ) {
		$length  = strlen( $data );
		$written = 0;
		while ( $written < $length ) {
			$result = fwrite( $this->handle, 0 === $written ? $data : substr( $data, $written ) );
			if ( false === $result || 0 === $result ) {
				throw new \RuntimeException( esc_html__( 'Writing the backup file failed. The disk may be full.', 'pack-vault' ) );
			}
			$written += $result;
		}
	}
}
