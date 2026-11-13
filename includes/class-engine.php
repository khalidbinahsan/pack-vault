<?php
/**
 * Job engine: runs one time-boxed slice of a job per request.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatches job slices to the backup/restore runners and keeps background
 * (scheduled) jobs moving with loopback requests plus a WP-Cron fallback.
 */
final class Engine {

	const CONTINUE_HOOK = 'packvault_job_continue';

	/**
	 * Run one slice of a job.
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	public static function run( Job $job ) {
		if ( 'running' !== $job->status ) {
			return $job;
		}
		$lock = self::acquire( $job->id );
		if ( false === $lock ) {
			return $job; // Another request is already working on it.
		}

		// Reload under the lock so we never work on stale state.
		$fresh = Job::load( $job->id );
		if ( $fresh ) {
			$job = $fresh;
		}

		Util::prepare_environment();
		$deadline = Util::deadline();

		try {
			if ( 'running' === $job->status ) {
				if ( 'backup' === $job->type ) {
					Backup_Runner::step( $job, $deadline );
				} else {
					Restore_Runner::step( $job, $deadline );
				}
			}
		} catch ( \Throwable $e ) {
			$job->fail( Util::message( $e ) );
			if ( 'backup' === $job->type ) {
				Backup_Runner::cleanup( $job, true );
			} else {
				Restore_Runner::cleanup( $job );
			}
		}

		$job->save();
		self::release( $lock );

		if ( 'running' !== $job->status ) {
			self::on_finished( $job );
		} elseif ( 'schedule' === $job->source ) {
			self::kick( $job );
		}
		return $job;
	}

	/**
	 * Take the per-job run lock.
	 *
	 * @param string $id   Job id.
	 * @param bool   $wait Block until the lock is free (slices are time-boxed).
	 * @return resource|null|false Handle, null when locking is unsupported, false when busy.
	 */
	private static function acquire( $id, $wait = false ) {
		$handle = @fopen( Storage::jobs_dir() . '/' . $id . '.lock', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return null;
		}
		if ( ! flock( $handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		return $handle;
	}

	/**
	 * Release the run lock.
	 *
	 * @param resource|null $handle Lock handle.
	 */
	private static function release( $handle ) {
		if ( $handle ) {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Cancel a job.
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	public static function cancel( Job $job ) {
		if ( 'running' !== $job->status ) {
			return $job;
		}

		// Wait for an in-flight slice to finish; otherwise it would save the
		// job back as "running" (and rewrite the partial file) after we cancel.
		$lock  = self::acquire( $job->id, true );
		$fresh = Job::load( $job->id );
		if ( $fresh ) {
			$job = $fresh;
		}
		if ( 'running' !== $job->status ) {
			self::release( $lock );
			return $job;
		}

		$job->status  = 'cancelled';
		$job->message = __( 'Cancelled.', 'pack-vault' );
		$job->log( 'Cancelled by user.' );
		if ( 'backup' === $job->type ) {
			Backup_Runner::cleanup( $job, true );
		} else {
			Restore_Runner::cleanup( $job );
		}
		$job->save();
		self::release( $lock );
		return $job;
	}

	/**
	 * Continue a background job (WP-Cron fallback hook).
	 *
	 * @param string $id Job id.
	 */
	public static function continue_job( $id ) {
		$job = Job::load( $id );
		if ( $job && 'running' === $job->status ) {
			self::run( $job );
		}
	}

	/**
	 * Keep a background job going: an immediate non-blocking loopback request,
	 * plus a cron event in case loopbacks are blocked on this host.
	 *
	 * @param Job $job Job.
	 */
	public static function kick( Job $job ) {
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK, array( $job->id ) ) ) {
			wp_schedule_single_event( time() + 60, self::CONTINUE_HOOK, array( $job->id ) );
		}
		wp_remote_post(
			rest_url( Rest::NS . '/jobs/' . $job->id . '/run' ),
			array(
				'timeout'   => 1,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
				'headers'   => array( 'X-Packvault-Token' => $job->token ),
			)
		);
	}

	/**
	 * Notifications and housekeeping once a job ends.
	 *
	 * @param Job $job Job.
	 */
	private static function on_finished( Job $job ) {
		wp_clear_scheduled_hook( self::CONTINUE_HOOK, array( $job->id ) );
		Job::prune();

		if ( 'schedule' !== $job->source ) {
			return;
		}
		$email = Settings::get( 'schedule_email' );
		if ( ! $email || 'cancelled' === $job->status ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( 'done' === $job->status ) {
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Scheduled backup completed', 'pack-vault' ), $site );
			$body    = sprintf(
				/* translators: 1: backup file name, 2: size */
				__( "Your scheduled backup finished successfully.\n\nFile: %1\$s\nSize: %2\$s", 'pack-vault' ),
				isset( $job->result['name'] ) ? $job->result['name'] : '',
				size_format( isset( $job->result['size'] ) ? $job->result['size'] : 0 )
			);
		} else {
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Scheduled backup FAILED', 'pack-vault' ), $site );
			/* translators: %s: error message */
			$body = sprintf( __( "Your scheduled backup failed.\n\nReason: %s", 'pack-vault' ), $job->message );
		}
		$body .= "\n\n— Packvault by " . PACKVAULT_AUTHOR_NAME . ' (' . PACKVAULT_AUTHOR_URL . ')';
		wp_mail( $email, $subject, $body );
	}
}
