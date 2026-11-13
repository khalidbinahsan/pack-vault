<?php
/**
 * Admin screen.
 *
 * @package Packvault
 */

namespace Packvault;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Packvault menu and boots the React dashboard.
 */
final class Admin {

	const SLUG = 'packvault';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ) );
		add_filter( 'update_footer', array( __CLASS__, 'footer_version' ), 20 );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		self::$hook = add_menu_page(
			__( 'Packvault Backups', 'pack-vault' ),
			__( 'Packvault', 'pack-vault' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			self::icon(),
			76
		);
	}

	/**
	 * Monochrome vault icon for the admin menu.
	 *
	 * @return string
	 */
	private static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="black" fill-rule="evenodd"><path d="M5 3h14a3 3 0 0 1 3 3v11a3 3 0 0 1-3 3h-1v1.5h-2V20H8v1.5H6V20H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3Zm7 4a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm0 2.5a2 2 0 1 1 0 4 2 2 0 0 1 0-4Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * React mount point.
	 */
	public static function render() {
		echo '<div class="wrap packvault-wrap"><div id="packvault-root"><div class="packvault-boot">' . esc_html__( 'Loading Packvault…', 'pack-vault' ) . '</div></div></div>';
	}

	/**
	 * Is the current screen ours?
	 *
	 * @return bool
	 */
	private static function is_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && self::$hook && self::$hook === $screen->id;
	}

	/**
	 * Enqueue the dashboard.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		$asset_file = PACKVAULT_PATH . 'build/index.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => PACKVAULT_VERSION,
		);

		wp_enqueue_script( 'packvault-admin', PACKVAULT_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		// index.asset.php's hash only tracks the JS bundle; version the CSS by
		// its own mtime so style-only changes are never served from cache.
		$css_file = PACKVAULT_PATH . 'build/index.css';
		$css_ver  = is_readable( $css_file ) ? $asset['version'] . '.' . filemtime( $css_file ) : $asset['version'];
		wp_enqueue_style( 'packvault-admin', PACKVAULT_URL . 'build/index.css', array(), $css_ver );
		wp_style_add_data( 'packvault-admin', 'rtl', 'replace' );
		wp_set_script_translations( 'packvault-admin', 'pack-vault', PACKVAULT_PATH . 'languages' );

		$user = wp_get_current_user();
		wp_add_inline_script(
			'packvault-admin',
			'window.packvaultData = ' . wp_json_encode(
				array(
					'restUrl'   => esc_url_raw( rest_url( Rest::NS ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'version'   => PACKVAULT_VERSION,
					'siteName'  => get_bloginfo( 'name' ),
					'homeUrl'   => home_url(),
					'adminUrl'  => admin_url(),
					'loginUrl'  => wp_login_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
					'userName'  => $user ? $user->display_name : '',
					'dateFormat' => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					'author'    => array(
						'name'    => PACKVAULT_AUTHOR_NAME,
						'url'     => PACKVAULT_AUTHOR_URL,
						'email'   => PACKVAULT_AUTHOR_EMAIL,
						'plugin'  => 'https://khalidbinahsan.com/pack-vault/',
						'reviews' => 'https://wordpress.org/support/plugin/pack-vault/reviews/#new-post',
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Branded footer on the Packvault screen.
	 *
	 * @param string $text Footer text.
	 * @return string
	 */
	public static function footer_text( $text ) {
		if ( ! self::is_screen() ) {
			return $text;
		}
		return sprintf(
			/* translators: 1: plugin name, 2: author link */
			esc_html__( 'Thank you for trusting %1$s, crafted with care by %2$s.', 'pack-vault' ),
			'<strong>Packvault</strong>',
			'<a href="' . esc_url( PACKVAULT_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( PACKVAULT_AUTHOR_NAME ) . '</a>'
		);
	}

	/**
	 * Version in the footer.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function footer_version( $text ) {
		return self::is_screen() ? 'Packvault ' . esc_html( PACKVAULT_VERSION ) : $text;
	}
}
