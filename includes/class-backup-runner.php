<?php
/**
 * Backup job steps.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * scan → database → archive → finalize.
 */
final class Backup_Runner {

	/**
	 * Create a backup job.
	 *
	 * @param array  $overrides Per-backup options.
	 * @param string $source    manual|schedule.
	 * @return Job
	 * @throws \RuntimeException When storage is not writable.
	 */
	public static function create_job( array $overrides = array(), $source = 'manual' ) {
		if ( ! Storage::ensure() || ! wp_is_writable( Storage::dir() ) ) {
			throw new \RuntimeException( esc_html__( 'The backup folder wp-content/packvault-backups is not writable.', 'pack-vault' ) );
		}
		$options = Settings::backup_options( $overrides );
		$job     = Job::create( 'backup', array(), $source );
		$work    = Storage::temp_dir() . '/' . $job->id;
		wp_mkdir_p( $work );

		$name       = Storage::unique_name( Storage::generate_name() );
		$job->state = array(
			'options' => $options,
			'name'    => $name,
			'part'    => Storage::dir() . '/' . $name . '.part',
			'work'    => $work,
			'phase'   => 'scan',
			'scan'    => File_Scanner::init_state( $options, $work . '/files.list' ),
			'db'      => null,
			'archive' => null,
		);
		$job->update( 'scan', __( 'Scanning files…', 'pack-vault' ), 1 );
		$job->save();
		return $job;
	}

	/**
	 * Run one slice.
	 *
	 * @param Job   $job      Job.
	 * @param float $deadline Microtime deadline.
	 */
	public static function step( Job $job, $deadline ) {
		$s = &$job->state;

		if ( 'scan' === $s['phase'] ) {
			$done = File_Scanner::step( $s['scan'], $deadline );
			/* translators: %s: number of files */
			$job->update( 'scan', sprintf( __( 'Scanning files… %s found', 'pack-vault' ), number_format_i18n( $s['scan']['files'] ) ), min( 9, 1 + (int) ( $s['scan']['files'] / 2000 ) ) );
			if ( ! $done ) {
				// Heartbeat in the log every 10k files so long scans visibly move.
				$mark = (int) floor( $s['scan']['files'] / 10000 );
				if ( $mark > ( isset( $s['scan_logged'] ) ? $s['scan_logged'] : 0 ) ) {
					$s['scan_logged'] = $mark;
					$job->log( sprintf( 'Scanned %s files so far (%s).', number_format_i18n( $s['scan']['files'] ), size_format( $s['scan']['bytes'] ) ) );
				}
				return;
			}
			$job->log( sprintf( 'Found %d files (%s).', $s['scan']['files'], size_format( $s['scan']['bytes'] ) ) );
			if ( empty( $s['options']['exclude_database'] ) ) {
				$s['phase'] = 'database';
				$s['db']    = Database_Exporter::init_state( $s['options'], $s['work'] . '/database.sql' );
				$job->update( 'database', __( 'Exporting database…', 'pack-vault' ), 10 );
			} else {
				$s['phase'] = 'archive';
			}
		}

		if ( 'database' === $s['phase'] ) {
			$done   = Database_Exporter::step( $s['db'], $deadline );
			$tables = max( 1, count( $s['db']['tables'] ) );
			$job->update(
				'database',
				/* translators: 1: current table number, 2: total tables */
				sprintf( __( 'Exporting database… table %1$d of %2$d', 'pack-vault' ), min( $s['db']['index'] + 1, $tables ), $tables ),
				10 + (int) ( 20 * $s['db']['index'] / $tables )
			);
			if ( ! $done ) {
				return;
			}
			$job->log( sprintf( 'Exported %d tables, %d rows (%s).', count( $s['db']['tables'] ), $s['db']['rows'], size_format( $s['db']['size'] ) ) );
			$s['phase'] = 'archive';
		}

		if ( 'archive' === $s['phase'] ) {
			if ( null === $s['archive'] ) {
				$s['archive'] = array(
					'offset'      => 0,
					'entries'     => 0,
					'stage'       => 'manifest',
					'db_done'     => 0,
					'list_offset' => 0,
					'cur'         => null,
					'cur_done'    => 0,
					'bytes'       => 0,
					'files'       => 0,
					'total'       => max( 1, $s['scan']['bytes'] + ( $s['db'] ? $s['db']['size'] : 0 ) ),
				);
				$job->update( 'archive', __( 'Building the .packvault archive…', 'pack-vault' ), 30 );
			}
			$done = self::archive_step( $job, $deadline );
			$a    = $s['archive'];
			$job->update(
				'archive',
				/* translators: 1: bytes written, 2: total bytes */
				sprintf( __( 'Building archive… %1$s of %2$s', 'pack-vault' ), size_format( $a['bytes'] ), size_format( $a['total'] ) ),
				30 + (int) ( 69 * min( 1, $a['bytes'] / $a['total'] ) )
			);
			// Log every 10% of the archive so the log keeps moving.
			$decile = (int) floor( 10 * min( 1, $a['bytes'] / $a['total'] ) );
			if ( $decile > ( isset( $a['logged'] ) ? $a['logged'] : 0 ) && $decile < 10 ) {
				$s['archive']['logged'] = $decile;
				$job->log(
					sprintf(
						'Archived %1$s of %2$s (%3$d%%, %4$s files).',
						size_format( $a['bytes'] ),
						size_format( $a['total'] ),
						$decile * 10,
						number_format_i18n( $a['files'] )
					)
				);
			}
			if ( ! $done ) {
				return;
			}
			$job->log( sprintf( 'Archive built: %s files packed.', number_format_i18n( $a['files'] ) ) );
			$s['phase'] = 'finalize';
		}

		if ( 'finalize' === $s['phase'] ) {
			$final = Storage::dir() . '/' . $s['name'];
			if ( ! @rename( $s['part'], $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				throw new \RuntimeException( esc_html__( 'Could not finalise the backup file.', 'pack-vault' ) );
			}
			$size = (int) filesize( $final );
			self::cleanup( $job, false );

			$job->status = 'done';
			$job->result = array(
				'name'  => $s['name'],
				'size'  => $size,
				'files' => $s['archive']['files'],
			);
			/* translators: %s: file size */
			$job->update( 'done', sprintf( __( 'Backup complete (%s).', 'pack-vault' ), size_format( $size ) ), 100 );

			Storage::apply_retention( (int) Settings::get( 'retention' ) );
			update_option( 'packvault_last_backup', array( 'name' => $s['name'], 'time' => time(), 'size' => $size, 'source' => $job->source ), false );
			do_action( 'packvault_backup_completed', $final, $job );
		}
	}

	/**
	 * Write manifest, database and files into the archive.
	 *
	 * @param Job   $job      Job.
	 * @param float $deadline Deadline.
	 * @return bool Done.
	 */
	private static function archive_step( Job $job, $deadline ) {
		$s      = &$job->state;
		$a      = &$s['archive'];
		$writer = new Archive_Writer( $s['part'], $a['offset'] );

		try {
			if ( 'manifest' === $a['stage'] ) {
				$writer->add_string( Archive::MANIFEST_PATH, wp_json_encode( self::manifest( $job ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				++$a['entries'];
				$a['stage']  = $s['db'] ? 'database' : 'files';
				$a['offset'] = $writer->tell();
			}

			if ( 'database' === $a['stage'] && microtime( true ) < $deadline ) {
				$size          = (int) filesize( $s['db']['sql'] );
				$done          = $writer->add_file_chunk( $s['db']['sql'], Archive::DATABASE_PATH, $size, time(), $a['db_done'], $deadline );
				$a['bytes']   += $done - $a['db_done'];
				$a['db_done']  = $done;
				$a['offset']   = $writer->tell();
				if ( $done >= $size ) {
					++$a['entries'];
					$a['stage'] = 'files';
					wp_delete_file( $s['db']['sql'] );
				}
			}

			if ( 'files' === $a['stage'] ) {
				$content = Util::content_dir();
				$list    = fopen( $s['scan']['list'], 'rb' );
				if ( ! $list ) {
					throw new \RuntimeException( esc_html__( 'The file list is missing.', 'pack-vault' ) );
				}

				while ( microtime( true ) < $deadline ) {
					if ( null === $a['cur'] ) {
						fseek( $list, $a['list_offset'] );
						$line = fgets( $list );
						if ( false === $line ) {
							$a['stage'] = 'footer';
							break;
						}
						$a['list_offset'] = (int) ftell( $list );
						$parts            = explode( "\t", rtrim( $line, "\n" ), 3 );
						if ( 3 !== count( $parts ) ) {
							continue;
						}
						$abs = $content . '/' . $parts[2];
						if ( ! is_file( $abs ) ) {
							continue; // Deleted since the scan.
						}
						clearstatcache( true, $abs );
						$a['cur']      = array( $parts[2], (int) filesize( $abs ), (int) $parts[1] );
						$a['cur_done'] = 0;
					}

					list( $rel, $size, $mtime ) = $a['cur'];
					$done          = $writer->add_file_chunk( $content . '/' . $rel, Archive::CONTENT_PREFIX . $rel, $size, $mtime, $a['cur_done'], $deadline );
					$a['bytes']   += $done - $a['cur_done'];
					$a['cur_done'] = $done;
					$a['offset']   = $writer->tell();
					if ( $done >= $size ) {
						$a['cur']      = null;
						$a['cur_done'] = 0;
						++$a['entries'];
						++$a['files'];
					}
				}
				fclose( $list );
			}

			if ( 'footer' === $a['stage'] ) {
				$writer->finish( $a['entries'] );
				$a['offset'] = $writer->tell();
				$a['stage']  = 'complete';
			}
		} finally {
			$writer->close();
		}

		return 'complete' === $a['stage'];
	}

	/**
	 * Manifest describing the source site.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	private static function manifest( Job $job ) {
		global $wpdb, $wp_version;
		$s = $job->state;
		$o = $s['options'];
		return array(
			'generator'    => 'Packvault',
			'version'      => PACKVAULT_VERSION,
			'format'       => Archive::VERSION,
			'author'       => PACKVAULT_AUTHOR_NAME . ' <' . PACKVAULT_AUTHOR_URL . '>',
			'created'      => time(),
			'created_gmt'  => gmdate( 'c' ),
			'source'       => $job->source,
			'note'         => $o['note'],
			'home_url'     => home_url(),
			'site_url'     => site_url(),
			'abspath'      => Util::path( ABSPATH ),
			'content_dir'  => Util::content_dir(),
			'table_prefix' => $wpdb->base_prefix,
			'wp_version'   => $wp_version,
			'php_version'  => PHP_VERSION,
			'db_version'   => $wpdb->db_version(),
			'multisite'    => is_multisite(),
			'stats'        => array(
				'files'  => $s['scan']['files'],
				'bytes'  => $s['scan']['bytes'],
				'tables' => $s['db'] ? count( $s['db']['tables'] ) : 0,
			),
			'contents'     => array(
				'database' => empty( $o['exclude_database'] ),
				'media'    => empty( $o['exclude_media'] ),
				'plugins'  => empty( $o['exclude_plugins'] ),
				'themes'   => empty( $o['exclude_themes'] ),
			),
		);
	}

	/**
	 * Remove temp files (and the partial archive when the job did not finish).
	 *
	 * @param Job  $job     Job.
	 * @param bool $partial Also delete the partial archive.
	 */
	public static function cleanup( Job $job, $partial ) {
		$s = $job->state;
		if ( ! empty( $s['work'] ) && 0 === strpos( Util::path( $s['work'] ), Storage::temp_dir() . '/' ) ) {
			Util::rmdir_recursive( $s['work'] );
		}
		if ( $partial && ! empty( $s['part'] ) && is_file( $s['part'] ) ) {
			wp_delete_file( $s['part'] );
		}
	}
}
