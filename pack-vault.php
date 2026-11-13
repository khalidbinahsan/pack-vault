<?php
/**
 * Plugin Name:       Packvault – Backup, Restore & Migration
 * Plugin URI:        https://khalidbinahsan.com/packvault
 * Description:       Back up your entire WordPress site into a single portable .packvault file. Download, upload, restore and migrate backups of any size, and schedule automatic daily backups. Crafted by Khalid Bin Ahsan.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Khalid Bin Ahsan
 * Author URI:        https://khalidbinahsan.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pack-vault
 * Domain Path:       /languages
 *
 * @package Packvault
 * @author  Khalid Bin Ahsan <developer.khalidbin@gmail.com>
 * @link    https://khalidbinahsan.com
 */

defined( 'ABSPATH' ) || exit;

define( 'PACKVAULT_VERSION', '1.0.0' );
define( 'PACKVAULT_FILE', __FILE__ );
define( 'PACKVAULT_PATH', plugin_dir_path( __FILE__ ) );
define( 'PACKVAULT_URL', plugin_dir_url( __FILE__ ) );
define( 'PACKVAULT_BASENAME', plugin_basename( __FILE__ ) );

define( 'PACKVAULT_AUTHOR_NAME', 'Khalid Bin Ahsan' );
define( 'PACKVAULT_AUTHOR_URL', 'https://khalidbinahsan.com' );
define( 'PACKVAULT_AUTHOR_EMAIL', 'developer.khalidbin@gmail.com' );

require_once PACKVAULT_PATH . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'Packvault\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Packvault\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Packvault\\Plugin', 'boot' ) );
