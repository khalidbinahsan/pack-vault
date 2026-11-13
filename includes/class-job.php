<?php
/**
 * Persistent job state.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * A backup or restore job.
 *
 * State lives in a JSON file (not the database) because a restore replaces
 * the database while the job is still running.
 */
final class Job {

	const STALE_AFTER = 1800;

	/** @var string */
	public $id;
	/** @var string backup|restore */
	public $type;
	/** @var string running|done|failed|cancelled */
	public $status = 'running';
	/** @var string */
	public $step = 'init';
	/** @var int 0–100 */
	public $progress = 0;
	/** @var string */
	public $message = '';
	/** @var string manual|schedule */
	public $source = 'manual';
	/** @var string Secret for token-authenticated run requests. */
	public $token;
	/** @var array Runner state. */
	public $state = array();
	/** @var array Result data for the UI. */
	public $result = array();
	/** @var string[] */
	public $log = array();
	/** @var int */
	public $created;
	/** @var int */
	public $updated;

	/**
	 * Create and persist a new job.
	 *
	 * @param string $type   backup|restore.
	 * @param array  $state  Initial runner state.
	 * @param string $source manual|schedule.
	 * @return self
	 */
	public static function create( $type, array $state, $source = 'manual' ) {
		Storage::ensure();
		$job          = new self();
		$job->id      = Util::random_id( 8 );
		$job->type    = $type;
		$job->source  = $source;
		$job->token   = Util::random_id( 24 );
		$job->state   = $state;
		$job->created = time();
		$job->save();
		file_put_contents( self::active_file(), $job->id );
		return $job;
	}

	/**
	 * Load a job.
	 *
	 * @param string $id Job id.
	 * @return self|null
	 */
	public static function load( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{16}$/', $id ) ) {
			return null;
		}
		$file = Storage::jobs_dir() . '/' . $id . '.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$job = new self();
		foreach ( get_object_vars( $job ) as $key => $unused ) {
			if ( array_key_exists( $key, $data ) ) {
				$job->$key = $data[ $key ];
			}
		}
		return $job;
	}

	/**
	 * The currently running job, if any (stale jobs are marked failed).
	 *
	 * @return self|null
	 */
	public static function active() {
		$file = self::active_file();
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$job = self::load( trim( (string) file_get_contents( $file ) ) );
		if ( ! $job || 'running' !== $job->status ) {
			return null;
		}
		if ( time() - (int) $job->updated > self::STALE_AFTER ) {
			$job->fail( __( 'The job stopped responding and was abandoned.', 'pack-vault' ) );
			$job->save();
			return null;
		}
		return $job;
	}

	/**
	 * Most recently finished job (for the dashboard).
	 *
	 * @return self|null
	 */
	public static function last() {
		$file = self::active_file();
		return is_readable( $file ) ? self::load( trim( (string) file_get_contents( $file ) ) ) : null;
	}

	/**
	 * Pointer file to the active job.
	 *
	 * @return string
	 */
	private static function active_file() {
		return Storage::jobs_dir() . '/active';
	}

	/**
	 * Persist atomically.
	 */
	public function save() {
		$this->updated = time();
		$data          = get_object_vars( $this );
		$file = Storage::jobs_dir() . '/' . $this->id . '.json';
		$tmp  = $file . '.tmp';
		file_put_contents( $tmp, wp_json_encode( $data ) );
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			file_put_contents( $file, wp_json_encode( $data ) );
			wp_delete_file( $tmp );
		}
	}

	/**
	 * Append a log line.
	 *
	 * @param string $message Message.
	 */
	public function log( $message ) {
		$this->log[] = '[' . wp_date( 'H:i:s' ) . '] ' . $message;
		if ( count( $this->log ) > 200 ) {
			$this->log = array_slice( $this->log, -200 );
		}
	}

	/**
	 * Set step, message and progress in one go.
	 *
	 * @param string $step     Step.
	 * @param string $message  Message.
	 * @param int    $progress Progress.
	 */
	public function update( $step, $message, $progress ) {
		if ( $step !== $this->step ) {
			$this->log( $message );
		}
		$this->step     = $step;
		$this->message  = $message;
		$this->progress = max( 0, min( 100, (int) $progress ) );
	}

	/**
	 * Mark failed.
	 *
	 * @param string $message Reason.
	 */
	public function fail( $message ) {
		$this->status  = 'failed';
		$this->message = $message;
		$this->log( 'Error: ' . $message );
	}

	/**
	 * Public representation (no secrets, no internal state).
	 *
	 * @param bool $with_token Include the run token (admins only).
	 * @return array
	 */
	public function to_array( $with_token = false ) {
		$data = array(
			'id'       => $this->id,
			'type'     => $this->type,
			'status'   => $this->status,
			'step'     => $this->step,
			'progress' => $this->progress,
			'message'  => $this->message,
			'source'   => $this->source,
			'result'   => $this->result,
			'log'      => array_slice( $this->log, -50 ),
			'created'  => $this->created,
			'updated'  => $this->updated,
		);
		if ( $with_token ) {
			$data['token'] = $this->token;
		}
		return $data;
	}

	/**
	 * Delete old job files, keeping the latest few.
	 */
	public static function prune() {
		$files = glob( Storage::jobs_dir() . '/*.json' );
		if ( ! $files || count( $files ) <= 20 ) {
			return;
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);
		foreach ( array_slice( $files, 20 ) as $file ) {
			wp_delete_file( $file );
			wp_delete_file( substr( $file, 0, -5 ) . '.lock' );
		}
	}
}
