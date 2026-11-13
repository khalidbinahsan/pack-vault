<?php
/**
 * Plugin bootstrap.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every Packvault component into WordPress.
 */
final class Plugin {

	/**
	 * Boot the plugin (runs on plugins_loaded).
	 */
	public static function boot() {
		Admin::init();
		Rest::init();
		Scheduler::init();
		Downloader::init();

		add_filter( 'plugin_action_links_' . PACKVAULT_BASENAME, array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		Storage::ensure();
		Scheduler::reschedule();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		Scheduler::clear();
	}

	/**
	 * "Backups" + "Settings" links on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=packvault' ) ) . '">' . esc_html__( 'Backups', 'pack-vault' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=packvault#/settings' ) ) . '">' . esc_html__( 'Settings', 'pack-vault' ) . '</a>',
		);
		return array_merge( $custom, $links );
	}

	/**
	 * Author branding links in the plugin row.
	 *
	 * @param array  $links Row meta links.
	 * @param string $file  Plugin basename.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( PACKVAULT_BASENAME !== $file ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( PACKVAULT_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Developer website', 'pack-vault' ) . '</a>';
		$links[] = '<a href="' . esc_url( 'mailto:' . PACKVAULT_AUTHOR_EMAIL ) . '">' . esc_html__( 'Email support', 'pack-vault' ) . '</a>';
		return $links;
	}
}
