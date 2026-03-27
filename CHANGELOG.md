# Changelog

## 1.2.1 - 2026-03-27

- Added dismissible in-progress backup modal support with inline progress continuation.
- Changed inline backup progress to stay hidden until the modal is dismissed.
- Changed dismissed inline progress layout to use a full-width bar with a single-line size summary.
- Fixed progress spinner animation so modal and inline states visibly spin during active work.
- Fixed dismiss button availability so it appears immediately when the backup modal opens.

## 1.2.0 - 2026-03-25

- Added remote storage provider architecture with pluggable provider interface.
- Added Google Drive provider with OAuth 2.0 authorization and resumable uploads.
- Added OneDrive provider with Microsoft Graph API, OAuth 2.0, and upload sessions.
- Added Dropbox provider with OAuth 2.0 auth code flow and upload sessions for large files.
- Added Backup Now popup modal with scope selection and conditional remote storage checkbox.
- Added AJAX lazy-loading infinite-depth folder tree with checkbox cascading and indeterminate parent state.
- Changed scheduled delivery from shared option to per-scope settings for database and files independently.
- Fixed scheduled delivery dropdown allowing remote selection when no provider is configured.

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
