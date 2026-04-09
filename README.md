# SavedPixel Remote Backup

Create manual or scheduled WordPress backups, keep local artifacts, and optionally deliver them to remote storage.

## What It Does

SavedPixel Remote Backup is a WordPress backup workspace for database dumps, file archives, and plugin archives. It supports manual runs, scheduled runs, retention limits, remote delivery, download and deletion controls, and pull-token access for a paired monitor site that wants to collect finished backup artifacts.

## Key Workflows

- Run a one-time backup for the database, files, or both.
- Schedule database and file backups independently with configurable times and weekly options.
- Keep backups local only or deliver them to remote storage after they finish.
- Download or delete artifacts from the backup history table.
- Expose a pull token so a monitor site can read backup status and fetch completed artifacts.
- Accept authenticated remote trigger requests to start backups on demand from a paired monitor site.

## Features

- Backup scopes for `database`, `files`, and `both`.
- Manual backups from wp-admin with asynchronous job handling and progress feedback.
- Dismissible backup progress modal that hands off to a full-width inline progress row.
- Scheduled database and file backups with separate frequency, time, and weekday controls.
- Per-scope scheduled delivery mode for database and files independently.
- Retention controls for how many database and file backups to keep.
- Local artifact storage for compressed database dumps and ZIP archives.
- Separate plugin-archive handling alongside full file backups.
- Download actions for database, files, and plugin artifacts.
- Delete actions for stored backups.
- Remote storage provider architecture with pluggable provider interface.
- Google Drive, OneDrive, and Dropbox cloud storage providers with OAuth 2.0.
- AJAX lazy-loading folder tree with checkbox cascading for granular file selection.
- Pull-token API for remote catalog access and artifact downloads by a monitor site.
- Pull-token-authenticated REST endpoint for remote backup triggering by a paired monitor site.
- Automatic migration of settings from older prefix on upgrade.
- Trigger metadata response for monitor-side run tracking.
- In-dashboard transfer/progress state for long-running backups.
- Summary cards showing backup counts, remote status, and storage usage at a glance.

## Admin Page

The admin page uses a monitor-style layout with summary cards at the top and modal-based settings. Header action buttons provide quick access to Backup Now, File Selection, DB Schedule, Files Schedule, Remote Storage, Pull Access, and Save Settings. Each settings area opens as a popup modal. Active backups open a progress modal that can be dismissed into a full-width inline progress row above the history table, while the backup history table and debug log remain as inline sections below the summary cards.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later

## Installation

1. Upload the `savedpixel-remote-backup` folder to `wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Open **SavedPixel > Remote Backup**.
4. Configure manual delivery mode, backup schedules, retention limits, and any SSH or FTP settings you want to use.

## Usage Notes

- This plugin creates and exports backups; it does not provide a full guided restore wizard.
- Remote delivery is optional. The plugin can be used in local-only mode.
- Pull-token access is intended for SavedPixel Remote Backup Monitor or other trusted internal tooling.

## Author

**Byron Jacobs**  
[GitHub](https://github.com/savedpixel)

## License

GPL-2.0-or-later
