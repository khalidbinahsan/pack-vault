<?php
/**
 * Uninstall Packvault.
 *
 * Removes settings and scheduled events. Backup files in
 * wp-content/packvault-backups are intentionally KEPT so a user never loses
 * backups by uninstalling the plugin; delete that folder manually if needed.
 *
 * @package Packvault
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'packvault_settings' );
delete_option( 'packvault_last_backup' );
wp_clear_scheduled_hook( 'packvault_scheduled_backup' );
wp_unschedule_hook( 'packvault_job_continue' );
