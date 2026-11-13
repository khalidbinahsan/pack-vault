<?php
/**
 * Plugin settings.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and sanitises Packvault settings in the packvault_settings option.
 */
final class Settings {

	const OPTION = 'packvault_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Default backup contents.
			'exclude_media'          => false,
			'exclude_plugins'        => false,
			'exclude_themes'         => false,
			'exclude_database'       => false,
			'exclude_spam_comments'  => true,
			'exclude_post_revisions' => false,
			'exclude_paths'          => '',
			'exclude_extensions'     => '',
			// Engine.
			'retention'              => 10,
			'time_budget'            => 20,
			// Schedule.
			'schedule_enabled'       => false,
			'schedule_frequency'     => 'daily',
			'schedule_time'          => '02:00',
			'schedule_email'         => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitise and save a partial settings array.
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings.
	 */
	public static function update( array $input ) {
		$current  = self::all();
		$defaults = self::defaults();
		$clean    = $current;

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( is_bool( $default ) ) {
				$clean[ $key ] = (bool) rest_sanitize_boolean( $value );
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = absint( $value );
			} elseif ( in_array( $key, array( 'exclude_paths', 'exclude_extensions' ), true ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $value );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		if ( ! in_array( $clean['schedule_frequency'], array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ) {
			$clean['schedule_frequency'] = 'daily';
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $clean['schedule_time'] ) ) {
			$clean['schedule_time'] = '02:00';
		}
		if ( '' !== $clean['schedule_email'] && ! is_email( $clean['schedule_email'] ) ) {
			$clean['schedule_email'] = '';
		}
		$clean['retention']   = min( 1000, $clean['retention'] );
		$clean['time_budget'] = max( 5, min( 120, $clean['time_budget'] ) );

		update_option( self::OPTION, $clean, false );
		Scheduler::reschedule();

		return $clean;
	}

	/**
	 * Backup options (what to include) from defaults overridden by a request.
	 *
	 * @param array $overrides Per-backup overrides.
	 * @return array
	 */
	public static function backup_options( array $overrides = array() ) {
		$all  = self::all();
		$keys = array( 'exclude_media', 'exclude_plugins', 'exclude_themes', 'exclude_database', 'exclude_spam_comments', 'exclude_post_revisions' );
		$opts = array();
		foreach ( $keys as $key ) {
			$opts[ $key ] = array_key_exists( $key, $overrides ) ? (bool) rest_sanitize_boolean( $overrides[ $key ] ) : (bool) $all[ $key ];
		}
		$opts['exclude_paths']      = self::lines( $all['exclude_paths'] );
		$opts['exclude_extensions'] = array_map(
			static function ( $ext ) {
				return strtolower( ltrim( $ext, '.' ) );
			},
			self::lines( str_replace( ',', "\n", $all['exclude_extensions'] ) )
		);
		$opts['note'] = isset( $overrides['note'] ) ? sanitize_text_field( (string) $overrides['note'] ) : '';
		return $opts;
	}

	/**
	 * Split text into trimmed non-empty lines.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function lines( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}
}
