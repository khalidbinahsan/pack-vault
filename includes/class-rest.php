<?php
/**
 * REST API (namespace packvault/v1).
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes used by the React dashboard.
 */
final class Rest {

	const NS = 'packvault/v1';

	const NAME_PATTERN = '(?P<name>[A-Za-z0-9][A-Za-z0-9._-]*\.packvault)';
	const ID_PATTERN   = '(?P<id>[a-f0-9]{16})';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function routes() {
		$admin = array( __CLASS__, 'can_manage' );
		$job   = array( __CLASS__, 'can_access_job' );

		self::route( '/status', 'GET', 'status', $admin );
		self::route( '/backups', 'GET', 'backups', $admin );
		self::route( '/backups', 'POST', 'create_backup', $admin );
		self::route( '/backups/' . self::NAME_PATTERN, 'DELETE', 'delete_backup', $admin );
		self::route( '/backups/' . self::NAME_PATTERN . '/restore', 'POST', 'restore_backup', $admin );

		self::route( '/jobs/' . self::ID_PATTERN, 'GET', 'get_job', $job );
		self::route( '/jobs/' . self::ID_PATTERN . '/run', 'POST', 'run_job', $job );
		self::route( '/jobs/' . self::ID_PATTERN . '/cancel', 'POST', 'cancel_job', $job );

		self::route( '/uploads', 'POST', 'upload_start', $admin );
		self::route( '/uploads/' . self::ID_PATTERN . '/chunk', 'POST', 'upload_chunk', $admin );
		self::route( '/uploads/' . self::ID_PATTERN . '/complete', 'POST', 'upload_complete', $admin );
		self::route( '/uploads/' . self::ID_PATTERN, 'DELETE', 'upload_abort', $admin );

		self::route( '/settings', 'GET', 'get_settings', $admin );
		self::route( '/settings', 'POST', 'save_settings', $admin );
	}

	/**
	 * Register one route whose callback converts exceptions into WP_Error.
	 *
	 * @param string   $path       Path.
	 * @param string   $method     Method.
	 * @param string   $callback   Static method name.
	 * @param callable $permission Permission callback.
	 */
	private static function route( $path, $method, $callback, $permission ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'permission_callback' => $permission,
				'callback'            => static function ( \WP_REST_Request $request ) use ( $callback ) {
					try {
						return rest_ensure_response( call_user_func( array( __CLASS__, $callback ), $request ) );
					} catch ( \RuntimeException $e ) {
						return new \WP_Error( 'packvault_error', Util::message( $e ), array( 'status' => 400 ) );
					}
				},
			)
		);
	}

	/**
	 * Admin capability.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Admins, or anyone holding the job's secret token. The token keeps a
	 * restore running after the database (and so the login session) is swapped,
	 * and powers background loopback requests for scheduled backups.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_access_job( \WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$token = (string) $request->get_header( 'X-Packvault-Token' );
		if ( '' === $token ) {
			return false;
		}
		$job = Job::load( (string) $request['id'] );
		return $job && is_string( $job->token ) && hash_equals( $job->token, $token );
	}

	/**
	 * Everything the dashboard needs in one request.
	 *
	 * @return array
	 */
	public static function status() {
		global $wp_version, $wpdb;
		Storage::ensure();
		Uploader::prune();

		$active = Job::active();
		$last   = $active ? null : Job::last();

		return array(
			'backups'     => self::backups(),
			'storage'     => Storage::stats(),
			'active_job'  => $active ? $active->to_array( true ) : null,
			'last_job'    => $last ? $last->to_array() : null,
			'last_backup' => get_option( 'packvault_last_backup', null ),
			'next_run'    => Scheduler::next_run(),
			'settings'    => Settings::all(),
			'system'      => array(
				'php'            => PHP_VERSION,
				'wp'             => $wp_version,
				'db'             => $wpdb->db_version(),
				'max_execution'  => (int) ini_get( 'max_execution_time' ),
				'memory_limit'   => (string) ini_get( 'memory_limit' ),
				'upload_max'     => (string) ini_get( 'upload_max_filesize' ),
				'post_max'       => (string) ini_get( 'post_max_size' ),
				'chunk_size'     => Uploader::chunk_size(),
				'int_size'       => PHP_INT_SIZE,
				'multisite'      => is_multisite(),
				'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'timezone'       => wp_timezone_string(),
			),
		);
	}

	/**
	 * Backup list with signed download URLs.
	 *
	 * @return array
	 */
	public static function backups() {
		return array_map(
			static function ( $backup ) {
				$backup['download'] = Downloader::url( $backup['name'] );
				return $backup;
			},
			Storage::list_backups()
		);
	}

	/**
	 * Start a backup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 * @throws \RuntimeException When another job is running.
	 */
	public static function create_backup( \WP_REST_Request $request ) {
		self::assert_idle();
		$options = (array) $request->get_param( 'options' );
		$job     = Backup_Runner::create_job( $options, 'manual' );
		return $job->to_array( true );
	}

	/**
	 * Delete a backup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 * @throws \RuntimeException On failure.
	 */
	public static function delete_backup( \WP_REST_Request $request ) {
		if ( ! Storage::delete( (string) $request['name'] ) ) {
			throw new \RuntimeException( esc_html__( 'The backup could not be deleted.', 'pack-vault' ) );
		}
		return array( 'deleted' => true );
	}

	/**
	 * Start a restore.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function restore_backup( \WP_REST_Request $request ) {
		self::assert_idle();
		$job = Restore_Runner::create_job( (string) $request['name'] );
		return $job->to_array( true );
	}

	/**
	 * Job status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 * @throws \RuntimeException When missing.
	 */
	public static function get_job( \WP_REST_Request $request ) {
		return self::load_job( $request )->to_array();
	}

	/**
	 * Run one slice of a job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function run_job( \WP_REST_Request $request ) {
		return Engine::run( self::load_job( $request ) )->to_array();
	}

	/**
	 * Cancel a job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function cancel_job( \WP_REST_Request $request ) {
		return Engine::cancel( self::load_job( $request ) )->to_array();
	}

	/**
	 * Begin an upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function upload_start( \WP_REST_Request $request ) {
		return Uploader::start( (string) $request->get_param( 'name' ), (int) $request->get_param( 'size' ) );
	}

	/**
	 * Receive one raw-body chunk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function upload_chunk( \WP_REST_Request $request ) {
		return Uploader::append( (string) $request['id'], (int) $request->get_param( 'offset' ), (string) $request->get_body() );
	}

	/**
	 * Finish an upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function upload_complete( \WP_REST_Request $request ) {
		$result             = Uploader::complete( (string) $request['id'] );
		$result['download'] = Downloader::url( $result['name'] );
		return $result;
	}

	/**
	 * Abort an upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function upload_abort( \WP_REST_Request $request ) {
		Uploader::abort( (string) $request['id'] );
		return array( 'aborted' => true );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return array(
			'settings' => Settings::all(),
			'next_run' => Scheduler::next_run(),
		);
	}

	/**
	 * Save settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_settings( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		return array(
			'settings' => Settings::update( is_array( $params ) ? $params : array() ),
			'next_run' => Scheduler::next_run(),
		);
	}

	/**
	 * Load a job from the route or fail.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return Job
	 * @throws \RuntimeException When missing.
	 */
	private static function load_job( \WP_REST_Request $request ) {
		$job = Job::load( (string) $request['id'] );
		if ( ! $job ) {
			throw new \RuntimeException( esc_html__( 'Job not found.', 'pack-vault' ) );
		}
		return $job;
	}

	/**
	 * Only one job at a time.
	 *
	 * @throws \RuntimeException When busy.
	 */
	private static function assert_idle() {
		if ( Job::active() ) {
			throw new \RuntimeException( esc_html__( 'Another backup or restore is already running. Please wait for it to finish.', 'pack-vault' ) );
		}
	}
}
