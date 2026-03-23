# Changelog

## 1.1.0 - 2026-03-22

- Added pull-token-authenticated REST endpoint for remote backup triggering.
- Added support for remotely requested manual and fallback backup runs via existing async job machinery.
- Added trigger metadata response so a monitor site can track requested runs to completion.

## 1.0.0 - 2026-03-18

- Initial release
- Run a one-time backup for the database, files, or both.
- Schedule database and file backups independently with configurable times and weekly options.
- Keep backups local only or deliver them to remote storage after they finish.
- Download or delete artifacts from the backup history table.
- Expose a pull token so a monitor site can read backup status and fetch completed artifacts.
- Backup scopes for `database`, `files`, and `both`.
- Manual backups from wp-admin with asynchronous job handling and progress feedback.
- Scheduled database and file backups with separate frequency, time, and weekday controls.
- Retention controls for how many database and file backups to keep.
- Local artifact storage for compressed database dumps and ZIP archives.
