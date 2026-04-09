=== SavedPixel Remote Backup ===
Contributors: savedpixel
Tags: backup, database, files, remote, scheduled
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 1.3.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create manual or scheduled WordPress backups, keep local artifacts, and optionally deliver them to remote storage.

== Description ==

SavedPixel Remote Backup is a WordPress backup workspace for database dumps, file archives, and plugin archives. It supports manual runs, scheduled runs, retention limits, remote delivery, download and deletion controls, and pull-token access for a paired monitor site that wants to collect finished backup artifacts.

== Features ==

* Backup scopes for database, files, and both.
* Manual backups from wp-admin with asynchronous job handling and progress feedback.
* Dismissible backup progress modal that continues as a full-width inline progress row.
* Scheduled database and file backups with separate frequency, time, and weekday controls.
* Retention controls for how many database and file backups to keep.
* Local artifact storage for compressed database dumps and ZIP archives.
* Remote delivery support with SSH, FTP, Dropbox, Google Drive, and OneDrive.
* Pull-token API for remote catalog access and artifact downloads by a monitor site.

== Installation ==

1. Upload the `savedpixel-remote-backup` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Navigate to SavedPixel > Remote Backup to configure.

== External services ==

This plugin can optionally transmit backup files to third-party remote storage services when the site administrator explicitly configures a provider.

= Google Drive =
Backup archives may be uploaded to a Google Drive account via the Google Drive REST API.
* Service URL: [https://drive.google.com](https://drive.google.com)
* Terms of Service: [https://policies.google.com/terms](https://policies.google.com/terms)
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

= Dropbox =
Backup archives may be uploaded to a Dropbox account via the Dropbox HTTP API.
* Service URL: [https://www.dropbox.com](https://www.dropbox.com)
* Terms of Service: [https://www.dropbox.com/terms](https://www.dropbox.com/terms)
* Privacy Policy: [https://www.dropbox.com/privacy](https://www.dropbox.com/privacy)

= OneDrive =
Backup archives may be uploaded to a Microsoft OneDrive account via the Microsoft Graph API.
* Service URL: [https://onedrive.live.com](https://onedrive.live.com)
* Terms of Service: [https://www.microsoft.com/servicesagreement](https://www.microsoft.com/servicesagreement)
* Privacy Policy: [https://privacy.microsoft.com/privacystatement](https://privacy.microsoft.com/privacystatement)

= SSH / FTP =
Backup archives may be uploaded to any user-specified SSH (SFTP) or FTP server. No third-party service is involved; the destination is entirely controlled by the site administrator.

== Changelog ==

= 1.3.0 =
* See CHANGELOG.md for full release history.
