<?php
/**
 * Resumable wp-content file scanner.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Walks wp-content breadth-first and writes "size\tmtime\trelative/path" lines
 * to a list file. State is a plain array so it can be saved between requests.
 */
final class File_Scanner {

	/**
	 * Directories (relative to wp-content) that are never backed up.
	 *
	 * @return string[]
	 */
	public static function always_excluded() {
		$list = array(
			Storage::DIR_NAME,
			'cache',
			'upgrade',
			'upgrade-temp-backup',
			'ai1wm-backups',
			'updraft',
			'backups-dup-lite',
			'backups-dup-pro',
			'backup-db',
			'backupwordpress',
			'wpvividbackups',
			'wflogs',
			'et-cache',
			'litespeed',
		);
		/**
		 * Filter directories (relative to wp-content) excluded from every backup.
		 *
		 * @param string[] $list Relative paths.
		 */
		return (array) apply_filters( 'packvault_always_excluded_paths', $list );
	}

	/**
	 * Initial scanner state.
	 *
	 * @param array  $options   Backup options.
	 * @param string $list_file Path of the list file to write.
	 * @return array
	 */
	public static function init_state( array $options, $list_file ) {
		$exclude = self::always_excluded();
		if ( ! empty( $options['exclude_media'] ) ) {
			$exclude[] = 'uploads';
		}
		if ( ! empty( $options['exclude_plugins'] ) ) {
			$exclude[] = 'plugins';
			$exclude[] = 'mu-plugins';
		}
		if ( ! empty( $options['exclude_themes'] ) ) {
			$exclude[] = 'themes';
		}
		foreach ( (array) $options['exclude_paths'] as $path ) {
			$path = trim( str_replace( '\\', '/', $path ), '/' );
			if ( '' !== $path ) {
				$exclude[] = $path;
			}
		}

		file_put_contents( $list_file, '' );

		return array(
			'list'       => $list_file,
			'list_size'  => 0,
			'queue'      => array( '' ),
			'skip_paths' => array_values( array_unique( $exclude ) ),
			'extensions' => array_values( (array) $options['exclude_extensions'] ),
			'files'      => 0,
			'bytes'      => 0,
		);
	}

	/**
	 * Scan until the deadline.
	 *
	 * @param array $state    Scanner state (modified).
	 * @param float $deadline Microtime deadline.
	 * @return bool True when the whole tree has been scanned.
	 */
	public static function step( array &$state, $deadline ) {
		$content = Util::content_dir();
		$list    = fopen( $state['list'], 'c+b' );
		if ( ! $list ) {
			throw new \RuntimeException( esc_html__( 'Could not write the file list in the Packvault temp folder.', 'pack-vault' ) );
		}
		// Drop anything an interrupted request may have appended.
		ftruncate( $list, $state['list_size'] );
		fseek( $list, $state['list_size'] );

		while ( ! empty( $state['queue'] ) && microtime( true ) < $deadline ) {
			$rel = array_shift( $state['queue'] );
			$abs = '' === $rel ? $content : $content . '/' . $rel;
			$dir = @opendir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $dir ) {
				continue;
			}
			$buffer = '';
			while ( false !== ( $name = readdir( $dir ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( '.' === $name || '..' === $name || false !== strpbrk( $name, "\n\r\t" ) ) {
					continue;
				}
				$child_rel = '' === $rel ? $name : $rel . '/' . $name;
				$child_abs = $abs . '/' . $name;
				if ( self::is_excluded( $child_rel, $state['skip_paths'] ) ) {
					continue;
				}
				if ( is_dir( $child_abs ) ) {
					if ( ! is_link( $child_abs ) ) {
						$state['queue'][] = $child_rel;
					}
					continue;
				}
				if ( ! is_file( $child_abs ) || ! is_readable( $child_abs ) ) {
					continue;
				}
				if ( $state['extensions'] ) {
					$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
					if ( in_array( $ext, $state['extensions'], true ) ) {
						continue;
					}
				}
				$size    = (int) filesize( $child_abs );
				$buffer .= $size . "\t" . (int) filemtime( $child_abs ) . "\t" . $child_rel . "\n";
				++$state['files'];
				$state['bytes'] += $size;
			}
			closedir( $dir );
			if ( '' !== $buffer ) {
				fwrite( $list, $buffer );
			}
			$state['list_size'] = (int) ftell( $list );
		}

		fclose( $list );
		return empty( $state['queue'] );
	}

	/**
	 * Whether a relative path falls under an excluded path.
	 *
	 * @param string   $rel     Relative path.
	 * @param string[] $exclude Excluded paths.
	 * @return bool
	 */
	private static function is_excluded( $rel, array $exclude ) {
		foreach ( $exclude as $path ) {
			if ( $rel === $path || 0 === strpos( $rel, $path . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
