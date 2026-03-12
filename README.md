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

## Features

- Backup scopes for `database`, `files`, and `both`.
- Manual backups from wp-admin with asynchronous job handling and progress feedback.
- Scheduled database and file backups with separate frequency, time, and weekday controls.
- Retention controls for how many database and file backups to keep.
- Local artifact storage for compressed database dumps and ZIP archives.
- Separate plugin-archive handling alongside full file backups.
- Download actions for database, files, and plugin artifacts.
- Delete actions for stored backups.
- Remote delivery support with local-only vs remote-delivery modes.
- Remote transport configuration for SSH or FTP, including connection testing and remote-path setup.
- Pull-token API for remote catalog access and artifact downloads by a monitor site.
- In-dashboard transfer/progress state for long-running backups.

## Admin Page

The admin workspace is organized around manual backup actions, schedule and retention settings, remote transport settings, pull-token access, and backup history. The history table exposes direct download buttons for stored artifacts and delete actions for old backups. Long-running manual runs use background jobs and an overlay progress UI instead of blocking the page.

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
