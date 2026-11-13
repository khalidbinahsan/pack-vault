<?php
/**
 * Restore job steps.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * extract → import → prepare → replace → swap → finish.
 *
 * Files are extracted straight into wp-content. The database is imported into
 * temporary tables and swapped in with a single RENAME at the end, so the live
 * site keeps working until the very last moment.
 */
final class Restore_Runner {

	/**
	 * Create a restore job for a stored backup.
	 *
	 * @param string $name Backup file name.
	 * @return Job
	 * @throws \RuntimeException When the backup is missing or unusable.
	 */
	public static function create_job( $name ) {
		$path = Storage::path( $name );
		if ( ! $path || ! is_file( $path ) ) {
			throw new \RuntimeException( esc_html__( 'Backup not found.', 'pack-vault' ) );
		}
		$manifest = Archive_Reader::manifest( $path );
		if ( ! $manifest ) {
			throw new \RuntimeException( esc_html__( 'This file is not a valid Packvault backup.', 'pack-vault' ) );
		}
		if ( ! empty( $manifest['multisite'] ) || is_multisite() ) {
			throw new \RuntimeException( esc_html__( 'Multisite restores are not supported yet.', 'pack-vault' ) );
		}

		$job  = Job::create( 'restore', array() );
		$work = Storage::temp_dir() . '/' . $job->id;
		wp_mkdir_p( $work );

		$job->state = array(
			'name'     => $name,
			'file'     => $path,
			'size'     => (int) filesize( $path ),
			'manifest' => $manifest,
			'work'     => $work,
			'phase'    => 'extract',
			'pos'      => Archive::HEADER_SIZE,
			'done'     => 0,
			'files'    => 0,
			'skipped'  => 0,
			'has_db'   => false,
			'import'   => null,
			'replace'  => null,
		);
		$job->update( 'extract', __( 'Restoring files…', 'pack-vault' ), 1 );
		/* translators: 1: backup name, 2: source URL */
		$job->log( sprintf( 'Restoring %1$s (from %2$s).', $name, isset( $manifest['home_url'] ) ? $manifest['home_url'] : '?' ) );
		$job->save();
		return $job;
	}

	/**
	 * Run one slice.
	 *
	 * @param Job   $job      Job.
	 * @param float $deadline Deadline.
	 */
	public static function step( Job $job, $deadline ) {
		$s = &$job->state;

		if ( 'extract' === $s['phase'] ) {
			$done = self::extract_step( $job, $deadline );
			$job->update(
				'extract',
				/* translators: %s: number of files */
				sprintf( __( 'Restoring files… %s restored', 'pack-vault' ), number_format_i18n( $s['files'] ) ),
				1 + (int) ( 49 * $s['pos'] / max( 1, $s['size'] ) )
			);
			if ( ! $done ) {
				return;
			}
			$job->log( sprintf( 'Restored %d files (%d skipped).', $s['files'], $s['skipped'] ) );
			if ( $s['has_db'] ) {
				$s['phase']  = 'import';
				$s['import'] = Database_Importer::init_state( $s['work'] . '/database.sql' );
				$job->update( 'import', __( 'Importing database…', 'pack-vault' ), 50 );
			} else {
				$s['phase'] = 'finish';
			}
		}

		if ( 'import' === $s['phase'] ) {
			$done = Database_Importer::step( $s['import'], $deadline, $job );
			$i    = $s['import'];
			$job->update(
				'import',
				/* translators: %d: percent */
				sprintf( __( 'Importing database… %d%%', 'pack-vault' ), (int) ( 100 * $i['offset'] / max( 1, $i['size'] ) ) ),
				50 + (int) ( 30 * $i['offset'] / max( 1, $i['size'] ) )
			);
			if ( ! $done ) {
				return;
			}
			if ( $i['errors'] ) {
				$job->log( sprintf( 'Database import finished with %d SQL errors.', $i['errors'] ) );
			}
			wp_delete_file( $i['sql'] );
			$s['phase'] = 'prepare';
		}

		if ( 'prepare' === $s['phase'] ) {
			$old_prefix = isset( $s['manifest']['table_prefix'] ) ? (string) $s['manifest']['table_prefix'] : '';
			$fixed      = Database_Importer::fix_prefix_keys( $old_prefix );
			if ( null !== $fixed ) {
				global $wpdb;
				$job->log( sprintf( 'Table prefix %1$s → %2$s: renamed %3$d role option and %4$d user meta keys.', $old_prefix, $wpdb->base_prefix, $fixed['roles'], $fixed['usermeta'] ) );
				if ( 0 === $fixed['roles'] || 0 === $fixed['usermeta'] ) {
					$job->log( 'Warning: some prefix-dependent keys were not found; administrators may need their role re-assigned.' );
				}
			}
			Database_Importer::keep_self_active();
			$s['replace'] = Search_Replace::init_state( $s['manifest'] );
			$s['phase']   = 'replace';
			$job->update( 'replace', __( 'Updating URLs and paths…', 'pack-vault' ), 82 );
			if ( $s['replace']['from'] ) {
				$job->log( 'Migrating ' . ( isset( $s['manifest']['home_url'] ) ? $s['manifest']['home_url'] : '' ) . ' → ' . home_url() );
			}
		}

		if ( 'replace' === $s['phase'] ) {
			$done   = Search_Replace::step( $s['replace'], $deadline );
			$r      = $s['replace'];
			$tables = max( 1, count( $r['tables'] ) );
			$job->update( 'replace', __( 'Updating URLs and paths…', 'pack-vault' ), 82 + (int) ( 13 * $r['index'] / $tables ) );
			if ( ! $done ) {
				return;
			}
			if ( $r['rows'] ) {
				$job->log( sprintf( 'Updated %d database rows for the new address.', $r['rows'] ) );
			}
			$s['phase'] = 'swap';
		}

		if ( 'swap' === $s['phase'] ) {
			$job->update( 'swap', __( 'Activating the restored database…', 'pack-vault' ), 96 );
			Database_Importer::swap();
			wp_cache_flush();
			delete_option( 'rewrite_rules' );
			$s['phase'] = 'finish';
		}

		if ( 'finish' === $s['phase'] ) {
			self::cleanup( $job );
			$job->status = 'done';
			$job->result = array(
				'name'      => $s['name'],
				'files'     => $s['files'],
				'database'  => $s['has_db'],
				'login_url' => wp_login_url( admin_url( 'admin.php?page=packvault' ) ),
			);
			$job->update( 'done', __( 'Restore complete.', 'pack-vault' ), 100 );
			do_action( 'packvault_restore_completed', $s['file'], $job );
		}
	}

	/**
	 * Extract entries until the deadline.
	 *
	 * @param Job   $job      Job.
	 * @param float $deadline Deadline.
	 * @return bool Done.
	 */
	private static function extract_step( Job $job, $deadline ) {
		$s       = &$job->state;
		$reader  = new Archive_Reader( $s['file'] );
		$content = Util::content_dir();
		$self    = Util::self_relative_dir() . '/';
		$backups = Storage::DIR_NAME . '/';
		$done    = false;

		try {
			while ( microtime( true ) < $deadline ) {
				$entry = $reader->entry_at( $s['pos'] );
				if ( null === $entry ) {
					$done = true;
					break;
				}

				$dest = null;
				$path = Archive::safe_path( $entry['path'] );
				if ( Archive::DATABASE_PATH === $path ) {
					$dest           = $s['work'] . '/database.sql';
					$s['has_db']    = true;
				} elseif ( $path && 0 === strpos( $path, Archive::CONTENT_PREFIX ) ) {
					$rel = substr( $path, strlen( Archive::CONTENT_PREFIX ) );
					// Case-insensitive: on Windows/macOS file systems "Plugins/Packvault/" is the same folder.
					if ( '' !== $rel && 0 !== stripos( $rel, $self ) && 0 !== stripos( $rel, $backups ) ) {
						$dest = $content . '/' . $rel;
					}
				}

				if ( null === $dest ) {
					if ( Archive::MANIFEST_PATH !== $path ) {
						++$s['skipped'];
					}
					$s['pos']  = $entry['next'];
					$s['done'] = 0;
					continue;
				}

				try {
					if ( 0 === $s['done'] ) {
						wp_mkdir_p( dirname( $dest ) );
					}
					$s['done'] = $reader->extract( $entry, $dest, $s['done'], $deadline );
				} catch ( \RuntimeException $e ) {
					$job->log( Util::message( $e ) );
					++$s['skipped'];
					$s['pos']  = $entry['next'];
					$s['done'] = 0;
					continue;
				}

				if ( $s['done'] >= $entry['size'] ) {
					if ( $entry['mtime'] > 0 ) {
						@touch( $dest, $entry['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Restore original modification time; WP_Filesystem::touch() would need FS credentials mid-job.
					}
					if ( Archive::DATABASE_PATH !== $path ) {
						++$s['files'];
					}
					$s['pos']  = $entry['next'];
					$s['done'] = 0;
				}
			}
		} finally {
			$reader->close();
		}
		return $done;
	}

	/**
	 * Remove temp files and temp tables.
	 *
	 * @param Job $job Job.
	 */
	public static function cleanup( Job $job ) {
		$s = $job->state;
		if ( ! empty( $s['work'] ) && 0 === strpos( Util::path( $s['work'] ), Storage::temp_dir() . '/' ) ) {
			Util::rmdir_recursive( $s['work'] );
		}
		if ( 'done' !== $job->status ) {
			Database_Importer::drop_prefixed( Database_Importer::TEMP_PREFIX );
		}
	}
}
