=== Packvault – Backup, Restore & Migration ===
Contributors: khalidbin
Donate link: https://khalidbinahsan.com
Tags: backup, restore, migration, clone, duplicate
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up your whole WordPress site into one portable .packvault file. Download, upload and restore backups of any size, and migrate to any host.

== Description ==

**Packvault** puts your entire WordPress site — database, media library, plugins and themes — into a single portable `.packvault` file. Keep it as a backup, download it to your computer, or upload it to another WordPress site to clone or migrate in a few clicks.

Packvault is designed and developed by [Khalid Bin Ahsan](https://khalidbinahsan.com).

= Why Packvault? =

* **One file, whole site.** Database, uploads, plugins and themes in a single `.packvault` archive.
* **No size limits.** Backups, uploads and downloads of any size. Work runs in short resumable steps, so large sites never hit PHP time or memory limits, and uploads are sent in small chunks that bypass `upload_max_filesize`.
* **Resumable uploads.** Pause, resume, and survive dropped connections.
* **Resumable downloads.** Backups are streamed with HTTP Range support, so download managers can resume.
* **Painless migration.** Restoring on a different domain or server path automatically rewrites URLs and paths — including inside serialized PHP data and JSON — without ever unserializing objects.
* **Safe restores.** The database is imported into temporary tables and swapped in at the very end, so your site keeps running during the restore.
* **Scheduled backups.** Hourly, twice daily, daily or weekly automatic backups that run in the background, with retention and email notifications.
* **Secure by default.** Backups live in a folder that blocks direct web access; downloads require an administrator login.
* **Modern dashboard.** A fast, clean React interface.

= Coming soon =

Remote storage destinations: Google Drive, Dropbox, OneDrive, Amazon S3 and S3-compatible storage, Backblaze B2, and FTP/SFTP.

== Installation ==

1. Upload the `packvault` folder to `/wp-content/plugins/`, or install Packvault from **Plugins → Add New**.
2. Activate the plugin.
3. Open **Packvault** in the admin menu and click **Back up now**.

To migrate a site: install Packvault on the destination site, open **Packvault → Import**, upload your `.packvault` file and click **Restore**.

== Frequently Asked Questions ==

= Is there a file size limit? =

No. Packvault splits every job into short steps and uploads files in small chunks, so the only real limit is your server's disk space.

= Where are backups stored? =

In `wp-content/packvault-backups/`. The folder is protected against direct web access (`.htaccess`, `web.config` and `index.php`). On Nginx, backups are additionally protected by random file names; you can add `location ~ /packvault-backups/ { deny all; }` to your server configuration for extra safety.

= Does it back up WordPress core files? =

No — like most migration tools, Packvault backs up everything that makes your site unique (database and `wp-content`). WordPress core is installed fresh on the destination.

= Will I be logged out after a restore? =

Yes, when the database is restored the users from the backup replace the current ones, so you log in with the credentials from the backup.

= Does it support Multisite? =

Not yet. Multisite support is planned.

= Do scheduled backups need my browser open? =

No. Scheduled backups run in the background via WP-Cron. If your host disables WP-Cron, set up a real server cron job that calls `wp-cron.php`.

= What happens when I uninstall Packvault? =

Settings and scheduled events are removed. Your backup files are intentionally kept in `wp-content/packvault-backups/` so you never lose them by accident.

== Screenshots ==

1. Dashboard with one-click backup and site stats.
2. Backups list with download, restore and delete.
3. Resumable import of a backup of any size.
4. Scheduled automatic backups.

== Changelog ==

= 1.0.0 =
* Initial release by Khalid Bin Ahsan.
* Single-file `.packvault` backups of database, media, plugins and themes.
* Chunked, resumable uploads and Range-enabled downloads of any size.
* Restore and migration with serialization-safe URL and path replacement.
* Scheduled backups with retention and email notifications.
* Modern React dashboard.

== Upgrade Notice ==

= 1.0.0 =
First release.
