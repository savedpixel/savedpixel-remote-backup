<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Backup transport helpers manage temp files and captured warnings directly.
class Remote_Backup_Scheduler {

    private $runner;
    private $logger;
    private $storage;

    const CRON_HOOK          = 'rb_scheduled_backup';
    const CRON_HOOK_DATABASE = 'rb_scheduled_backup_database';
    const CRON_HOOK_FILES    = 'rb_scheduled_backup_files';

    public function __construct( Remote_Backup_Runner $runner, Remote_Backup_Logger $logger, Remote_Backup_Storage $storage ) {
        $this->runner  = $runner;
        $this->logger  = $logger;
        $this->storage = $storage;

        add_action( self::CRON_HOOK, array( $this, 'run_legacy_scheduled' ) );
        add_action( self::CRON_HOOK_DATABASE, array( $this, 'run_scheduled_database' ) );
        add_action( self::CRON_HOOK_FILES, array( $this, 'run_scheduled_files' ) );
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
    }

    public function add_schedules( $schedules ) {
        $schedules['rb_every_6h'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every 6 hours',
        );
        $schedules['rb_every_12h'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => 'Every 12 hours',
        );
        return $schedules;
    }

    public function run_legacy_scheduled() {
        $scope = get_option( 'rb_scheduled_scope', 'both' );
        if ( in_array( $scope, array( 'database', 'files' ), true ) ) {
            $this->run_scheduled_scope( $scope );
            return;
        }

        $this->run_scheduled_scope( 'database' );
        $this->run_scheduled_scope( 'files' );
    }

    public function run_scheduled_database() {
        $this->run_scheduled_scope( 'database' );
    }

    public function run_scheduled_files() {
        $this->run_scheduled_scope( 'files' );
    }

    private function run_scheduled_scope( $scope ) {
        $remote_mode = $this->normalize_remote_mode( get_option( 'rb_scheduled_remote_mode', 'remote' ) );
        $this->logger->log( "Scheduled backup started — scope: {$scope}, delivery: {$remote_mode}" );

        $result = $this->runner->run( $scope );

        if ( is_wp_error( $result ) ) {
            $this->logger->log( 'Scheduled backup FAILED: ' . $result->get_error_message(), 'error' );
            return;
        }

        if ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
            $this->logger->log( 'Scheduled backup FAILED: ' . ( $result['error'] ?? 'Unknown error.' ), 'error' );
            return;
        }

        $this->logger->log( 'Scheduled backup completed — ' . size_format( $result['total_size'] ) );

        if ( 'remote' === $remote_mode ) {
            $this->send_backup_to_remote( $result, 'Scheduled backup' );
        } else {
            $this->logger->log( 'Scheduled backup kept locally only.' );
        }
    }

    public function reschedule() {
        $this->unschedule();

        foreach ( array( 'database', 'files' ) as $scope ) {
            $schedule = $this->get_scope_schedule( $scope );
            if ( 'none' === $schedule['frequency'] ) {
                continue;
            }

            $next_run = $this->next_scheduled_timestamp( $schedule['frequency'], $schedule['configured_time'], $schedule['configured_weekday'] ?? null );
            if ( ! $next_run ) {
                $this->logger->log( "Cron reschedule failed: invalid {$scope} schedule for {$schedule['frequency']} @ {$schedule['configured_time']}", 'error' );
                continue;
            }

            wp_schedule_event( $next_run, $schedule['frequency'], $this->scope_hook( $scope ) );
            $this->logger->log(
                'Cron rescheduled: ' . $scope . ' => ' . $schedule['frequency'] .
                ( 'weekly' === $schedule['frequency'] && ! empty( $schedule['configured_weekday'] ) ? ' on ' . $this->weekday_label( $schedule['configured_weekday'] ) : '' ) .
                ' @ ' . $schedule['configured_time'] . ' (next: ' . gmdate( 'Y-m-d H:i:s', $next_run ) . ' UTC)'
            );
        }
    }

    public function unschedule() {
        foreach ( $this->all_hooks() as $hook ) {
            $ts = wp_next_scheduled( $hook );
            if ( $ts ) {
                wp_unschedule_event( $ts, $hook );
            }
        }
    }

    public function has_remote_target() {
        $settings = $this->get_remote_settings();
        return ! empty( $settings['host'] );
    }

    public function send_backup_to_remote( $backup, $context = 'Backup' ) {
        $settings = $this->get_remote_settings();
        $target   = $this->format_remote_target( $settings );

        $validation = $this->validate_remote_settings( $settings, true );
        if ( is_wp_error( $validation ) ) {
            $message = 'Remote upload failed: ' . $validation->get_error_message();
            $this->logger->log( "{$context} remote upload FAILED: {$validation->get_error_message()}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        $runtime = $this->prepare_runtime_settings( $settings );
        if ( is_wp_error( $runtime ) ) {
            $message = 'Remote upload failed: ' . $runtime->get_error_message();
            $this->logger->log( "{$context} remote upload FAILED: {$runtime->get_error_message()}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        $this->logger->log( "{$context} remote upload started — {$target} (auth: {$settings['auth']})" );

        $directory = $this->ensure_remote_directory( $runtime );
        if ( is_wp_error( $directory ) ) {
            $this->cleanup_runtime_settings( $runtime );
            $message = 'Remote upload failed: ' . $directory->get_error_message();
            $this->logger->log( "{$context} remote upload FAILED: {$directory->get_error_message()}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        $files = $this->collect_backup_artifacts( $backup );
        if ( is_wp_error( $files ) ) {
            $this->cleanup_runtime_settings( $runtime );
            $message = 'Remote upload failed: ' . $files->get_error_message();
            $this->logger->log( "{$context} remote upload FAILED: {$files->get_error_message()}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        if ( empty( $files ) ) {
            $this->cleanup_runtime_settings( $runtime );
            $message = 'Remote upload skipped: no backup artifacts were available to upload.';
            $this->logger->log( "{$context} remote upload skipped — no files found.", 'warning' );
            return $this->finalize_remote_result( $backup, 'skipped', $message, $settings );
        }

        $errors = array();
        foreach ( $files as $local_path ) {
            $remote_dest = $this->join_remote_path( $settings['path'], basename( $local_path ) );
            $transfer    = $this->transfer_file_to_remote( $runtime, $local_path, $remote_dest );
            if ( is_wp_error( $transfer ) ) {
                $errors[] = basename( $local_path ) . ': ' . $transfer->get_error_message();
            }
        }

        $this->cleanup_runtime_settings( $runtime );

        if ( ! empty( $errors ) ) {
            $summary = $this->summarize_messages( $errors );
            $message = sprintf(
                'Remote upload failed for %1$d of %2$d file(s): %3$s',
                count( $errors ),
                count( $files ),
                $summary
            );
            $this->logger->log( "{$context} remote upload FAILED: {$summary}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        $message = sprintf(
            'Remote upload completed — %1$d file(s) transferred to %2$s.',
            count( $files ),
            $target
        );
        $this->logger->log( "{$context} remote upload completed — {$target}" );
        return $this->finalize_remote_result( $backup, 'success', $message, $settings );
    }

    public function test_connection() {
        $settings   = $this->get_remote_settings();
        $validation = $this->validate_remote_settings( $settings, false );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        if ( 'ftp' === $settings['protocol'] ) {
            return $this->test_ftp_connection( $settings );
        }

        $runtime = $this->prepare_runtime_settings( $settings );
        if ( is_wp_error( $runtime ) ) {
            return $runtime;
        }

        $probe_file = $this->join_remote_path( $settings['path'], '.rb-write-test-' . wp_generate_password( 8, false, false ) );
        $command    = sprintf(
            'if [ -d %1$s ]; then echo RB_DIR_EXISTS; else mkdir -p %1$s && echo RB_DIR_CREATED || echo RB_DIR_CREATE_FAILED; fi; if touch %2$s 2>/dev/null; then rm -f %2$s && echo RB_WRITE_OK; else echo RB_WRITE_FAILED; fi',
            escapeshellarg( $settings['path'] ),
            escapeshellarg( $probe_file )
        );

        $result = $this->execute_ssh_command( $runtime, $command, 15 );
        $this->cleanup_runtime_settings( $runtime );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $text = $result['text'];

        if ( false !== strpos( $text, 'RB_DIR_CREATE_FAILED' ) ) {
            return new WP_Error(
                'remote_dir_create_failed',
                sprintf( 'Connected OK — but the remote directory could not be created: %s', $settings['path'] )
            );
        }

        if ( false !== strpos( $text, 'RB_WRITE_FAILED' ) ) {
            $message = sprintf( 'Connected OK — but the remote path is not writable by `%s`: %s', $settings['username'], $settings['path'] );
            if ( '/' === $settings['path'] ) {
                $message .= ' Use a writable backup directory such as `/home/backups/example-site` or another path owned by that user.';
            }
            return new WP_Error( 'remote_path_not_writable', $message );
        }

        if ( false !== strpos( $text, 'RB_DIR_CREATED' ) ) {
            return 'Connected OK — remote directory was created and is writable.';
        }

        if ( false !== strpos( $text, 'RB_DIR_EXISTS' ) ) {
            return 'Connected OK — remote directory exists and is writable.';
        }

        return 'Connected OK — authentication succeeded.';
    }

    public function next_scheduled( $scope = null ) {
        if ( $scope ) {
            $scheduled = wp_next_scheduled( $this->scope_hook( $scope ) );
            if ( $scheduled ) {
                return $scheduled;
            }

            $legacy = wp_next_scheduled( self::CRON_HOOK );
            if ( $legacy ) {
                $legacy_schedule = $this->legacy_scope_schedule( $scope );
                if ( 'none' !== $legacy_schedule['frequency'] ) {
                    return $legacy;
                }
            }

            return false;
        }

        $timestamps = array_filter(
            array(
                wp_next_scheduled( self::CRON_HOOK_DATABASE ),
                wp_next_scheduled( self::CRON_HOOK_FILES ),
            )
        );

        if ( empty( $timestamps ) ) {
            $legacy = wp_next_scheduled( self::CRON_HOOK );
            return $legacy ?: false;
        }

        return min( $timestamps );
    }

    public function get_schedule_time( $scope = null ) {
        if ( $scope ) {
            $schedule = $this->get_scope_schedule( $scope );
            return $schedule['configured_time'];
        }

        return $this->sanitize_schedule_time( get_option( 'rb_schedule_time', '02:00' ) );
    }

    public function get_schedule_weekday( $scope = null ) {
        if ( $scope ) {
            $schedule = $this->get_scope_schedule( $scope );
            return $schedule['configured_weekday'] ?? $this->default_schedule_weekday();
        }

        return $this->default_schedule_weekday();
    }

    public function sanitize_schedule_time( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches ) ) {
            return sprintf( '%02d:%02d', (int) $matches[1], (int) $matches[2] );
        }

        return '02:00';
    }

    public function sanitize_schedule_frequency( $value ) {
        $value   = sanitize_text_field( (string) $value );
        $allowed = array( 'none', 'hourly', 'rb_every_6h', 'rb_every_12h', 'daily', 'twicedaily', 'weekly' );

        return in_array( $value, $allowed, true ) ? $value : 'none';
    }

    public function sanitize_schedule_weekday( $value ) {
        $value   = strtolower( sanitize_text_field( (string) $value ) );
        $allowed = array_keys( $this->weekday_options() );

        return in_array( $value, $allowed, true ) ? $value : $this->default_schedule_weekday();
    }

    public function describe_schedule( $scope = null ) {
        if ( $scope ) {
            return $this->describe_scope_schedule( $scope );
        }

        $database = $this->describe_scope_schedule( 'database' );
        $files    = $this->describe_scope_schedule( 'files' );
        $primary  = $this->primary_schedule( $database, $files );

        return array(
            'enabled'         => ! empty( $database['enabled'] ) || ! empty( $files['enabled'] ),
            'frequency'       => $primary['frequency'] ?? 'none',
            'scope'           => $primary['scope'] ?? 'both',
            'interval'        => (int) ( $primary['interval'] ?? 0 ),
            'configured_time' => $primary['configured_time'] ?? null,
            'configured_weekday' => $primary['configured_weekday'] ?? null,
            'configured_weekday_label' => $primary['configured_weekday_label'] ?? null,
            'next_run_gmt'    => $primary['next_run_gmt'] ?? null,
            'next_run_local'  => $primary['next_run_local'] ?? null,
            'database'        => $database,
            'files'           => $files,
        );
    }

    private function describe_scope_schedule( $scope ) {
        $schedule  = $this->get_scope_schedule( $scope );
        $frequency = $schedule['frequency'];
        $next      = $this->next_scheduled( $scope );
        $schedules = wp_get_schedules();
        $interval  = (int) ( $schedules[ $frequency ]['interval'] ?? 0 );

        return array(
            'enabled'         => 'none' !== $frequency && false !== $next,
            'frequency'       => $frequency,
            'scope'           => $scope,
            'interval'        => $interval,
            'configured_time' => $schedule['configured_time'],
            'configured_weekday' => $schedule['configured_weekday'] ?? null,
            'configured_weekday_label' => ! empty( $schedule['configured_weekday'] ) ? $this->weekday_label( $schedule['configured_weekday'] ) : null,
            'next_run_gmt'    => $next ? gmdate( 'Y-m-d H:i:s', $next ) : null,
            'next_run_local'  => $next ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) : null,
        );
    }

    private function get_scope_schedule( $scope ) {
        $scope          = $this->normalize_scope( $scope );
        $frequency_key  = 'rb_schedule_' . $scope . '_frequency';
        $time_key       = 'rb_schedule_' . $scope . '_time';
        $weekday_key    = 'rb_schedule_' . $scope . '_weekday';
        $frequency_raw  = get_option( $frequency_key, '__rb_missing__' );
        $time_raw       = get_option( $time_key, '__rb_missing__' );
        $weekday_raw    = get_option( $weekday_key, '__rb_missing__' );
        $legacy         = $this->legacy_scope_schedule( $scope );
        $frequency      = '__rb_missing__' === $frequency_raw ? $legacy['frequency'] : $this->sanitize_schedule_frequency( $frequency_raw );
        $weekday        = '__rb_missing__' === $weekday_raw
            ? $this->scheduled_scope_weekday( $scope, $legacy['configured_weekday'] ?? $this->default_schedule_weekday() )
            : $this->sanitize_schedule_weekday( $weekday_raw );

        return array(
            'scope'           => $scope,
            'frequency'       => $frequency,
            'configured_time' => '__rb_missing__' === $time_raw ? $legacy['configured_time'] : $this->sanitize_schedule_time( $time_raw ),
            'configured_weekday' => 'weekly' === $frequency ? $weekday : null,
        );
    }

    private function legacy_scope_schedule( $scope ) {
        $legacy_scope = sanitize_text_field( (string) get_option( 'rb_scheduled_scope', 'both' ) );
        $legacy_freq  = $this->sanitize_schedule_frequency( get_option( 'rb_schedule_frequency', 'none' ) );
        $legacy_time  = $this->sanitize_schedule_time( get_option( 'rb_schedule_time', '02:00' ) );

        if ( 'database' === $scope ) {
            $enabled = in_array( $legacy_scope, array( 'database', 'both' ), true );
        } else {
            $enabled = in_array( $legacy_scope, array( 'files', 'both' ), true );
        }

        return array(
            'scope'           => $scope,
            'frequency'       => $enabled ? $legacy_freq : 'none',
            'configured_time' => $legacy_time,
            'configured_weekday' => 'weekly' === $legacy_freq ? $this->scheduled_scope_weekday( $scope, $this->default_schedule_weekday() ) : null,
        );
    }

    private function primary_schedule( $database, $files ) {
        $enabled = array();

        if ( ! empty( $database['enabled'] ) ) {
            $enabled[] = $database;
        }
        if ( ! empty( $files['enabled'] ) ) {
            $enabled[] = $files;
        }

        if ( empty( $enabled ) ) {
            return array(
                'enabled'         => false,
                'frequency'       => 'none',
                'scope'           => 'both',
                'interval'        => 0,
                'configured_time' => null,
                'next_run_gmt'    => null,
                'next_run_local'  => null,
            );
        }

        usort(
            $enabled,
            function ( $a, $b ) {
                return strcmp( (string) ( $a['next_run_gmt'] ?? '' ), (string) ( $b['next_run_gmt'] ?? '' ) );
            }
        );

        return $enabled[0];
    }

    private function scope_hook( $scope ) {
        return 'database' === $this->normalize_scope( $scope ) ? self::CRON_HOOK_DATABASE : self::CRON_HOOK_FILES;
    }

    private function normalize_scope( $scope ) {
        return 'database' === sanitize_text_field( (string) $scope ) ? 'database' : 'files';
    }

    private function all_hooks() {
        return array(
            self::CRON_HOOK,
            self::CRON_HOOK_DATABASE,
            self::CRON_HOOK_FILES,
        );
    }

    private function next_scheduled_timestamp( $frequency, $schedule_time, $schedule_weekday = null ) {
        $schedule_time = $this->sanitize_schedule_time( $schedule_time );
        $schedules     = wp_get_schedules();
        $interval      = (int) ( $schedules[ $frequency ]['interval'] ?? 0 );

        if ( $interval <= 0 ) {
            return 0;
        }

        list( $hour, $minute ) = array_map( 'intval', explode( ':', $schedule_time ) );
        $timezone = wp_timezone();
        $now      = new DateTimeImmutable( 'now', $timezone );

        if ( 'weekly' === $frequency ) {
            $weekday   = $this->sanitize_schedule_weekday( $schedule_weekday );
            $candidate = $now->modify( 'this ' . $this->weekday_label( $weekday ) )->setTime( $hour, $minute, 0 );
        } elseif ( 'hourly' === $frequency ) {
            $candidate = $now->setTime( (int) $now->format( 'H' ), $minute, 0 );
        } else {
            $candidate = $now->setTime( $hour, $minute, 0 );
        }

        while ( $candidate->getTimestamp() <= $now->getTimestamp() ) {
            $candidate = $candidate->modify( $this->interval_modify_expression( $interval ) );
        }

        return $candidate->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
    }

    private function interval_modify_expression( $interval ) {
        switch ( (int) $interval ) {
            case HOUR_IN_SECONDS:
                return '+1 hour';
            case 6 * HOUR_IN_SECONDS:
                return '+6 hours';
            case 12 * HOUR_IN_SECONDS:
                return '+12 hours';
            case DAY_IN_SECONDS:
                return '+1 day';
            case WEEK_IN_SECONDS:
                return '+1 week';
            default:
                return '+' . (int) $interval . ' seconds';
        }
    }

    public function weekday_options() {
        return array(
            'monday'    => 'Monday',
            'tuesday'   => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday'  => 'Thursday',
            'friday'    => 'Friday',
            'saturday'  => 'Saturday',
            'sunday'    => 'Sunday',
        );
    }

    public function weekday_label( $weekday ) {
        $weekday = $this->sanitize_schedule_weekday( $weekday );
        $options = $this->weekday_options();

        return $options[ $weekday ] ?? 'Monday';
    }

    private function default_schedule_weekday() {
        return strtolower( wp_date( 'l', null, wp_timezone() ) );
    }

    private function scheduled_scope_weekday( $scope, $fallback ) {
        $scheduled = wp_next_scheduled( $this->scope_hook( $scope ) );
        if ( ! $scheduled ) {
            $scheduled = wp_next_scheduled( self::CRON_HOOK );
        }

        if ( ! $scheduled ) {
            return $this->sanitize_schedule_weekday( $fallback );
        }

        return strtolower( wp_date( 'l', $scheduled, wp_timezone() ) );
    }

    private function normalize_remote_mode( $value ) {
        return 'remote' === sanitize_text_field( (string) $value ) ? 'remote' : 'local';
    }

    private function normalize_remote_protocol( $value ) {
        return 'ftp' === sanitize_text_field( (string) $value ) ? 'ftp' : 'ssh';
    }

    private function get_remote_settings() {
        $protocol = $this->normalize_remote_protocol( get_option( 'rb_remote_protocol', 'ssh' ) );
        $path     = trim( (string) get_option( 'ssh' === $protocol ? 'rb_ssh_path' : 'rb_ftp_path', '' ) );
        if ( '/' !== $path ) {
            $path = rtrim( $path, '/' );
        }

        if ( 'ftp' === $protocol ) {
            return array(
                'protocol' => 'ftp',
                'host'     => trim( (string) get_option( 'rb_ftp_host', '' ) ),
                'port'     => absint( get_option( 'rb_ftp_port', 21 ) ) ?: 21,
                'username' => trim( (string) get_option( 'rb_ftp_username', '' ) ),
                'password' => (string) get_option( 'rb_ftp_password', '' ),
                'path'     => $path,
                'passive'  => (bool) get_option( 'rb_ftp_passive', 1 ),
                'auth'     => 'password',
            );
        }

        return array(
            'protocol' => 'ssh',
            'host'     => trim( (string) get_option( 'rb_ssh_host', '' ) ),
            'port'     => absint( get_option( 'rb_ssh_port', 22 ) ) ?: 22,
            'username' => trim( (string) get_option( 'rb_ssh_username', '' ) ),
            'auth'     => get_option( 'rb_ssh_auth_method', 'key' ),
            'path'     => $path,
            'key'      => (string) get_option( 'rb_ssh_key', '' ),
            'password' => (string) get_option( 'rb_ssh_password', '' ),
        );
    }

    private function validate_remote_settings( $settings, $require_scp ) {
        if ( 'ftp' === $settings['protocol'] ) {
            return $this->validate_ftp_settings( $settings );
        }

        return $this->validate_ssh_settings( $settings, $require_scp );
    }

    private function validate_ssh_settings( $settings, $require_scp ) {
        if ( empty( $settings['host'] ) ) {
            return new WP_Error( 'missing_host', 'Host is required.' );
        }

        if ( empty( $settings['username'] ) ) {
            return new WP_Error( 'missing_username', 'Username is required.' );
        }

        if ( empty( $settings['path'] ) ) {
            return new WP_Error( 'missing_path', 'Remote path is required.' );
        }

        if ( ! in_array( $settings['auth'], array( 'key', 'password' ), true ) ) {
            return new WP_Error( 'invalid_auth', 'Authentication method must be `key` or `password`.' );
        }

        if ( ! $this->command_available( 'ssh' ) ) {
            return new WP_Error( 'missing_ssh', 'The `ssh` command is not installed on this server.' );
        }

        if ( $require_scp && ! $this->command_available( 'scp' ) ) {
            return new WP_Error( 'missing_scp', 'The `scp` command is not installed on this server.' );
        }

        if ( 'password' === $settings['auth'] ) {
            if ( empty( $settings['password'] ) ) {
                return new WP_Error( 'missing_password', 'Password authentication is selected, but no password is configured.' );
            }
            if ( ! $this->command_available( 'sshpass' ) ) {
                return new WP_Error( 'missing_sshpass', 'Password authentication requires the `sshpass` command on this server.' );
            }
        } else {
            if ( empty( trim( $settings['key'] ) ) ) {
                return new WP_Error( 'missing_key', 'SSH private key authentication is selected, but no private key is configured.' );
            }
        }

        return true;
    }

    private function validate_ftp_settings( $settings ) {
        if ( empty( $settings['host'] ) ) {
            return new WP_Error( 'missing_host', 'FTP host is required.' );
        }

        if ( empty( $settings['username'] ) ) {
            return new WP_Error( 'missing_username', 'FTP username is required.' );
        }

        if ( empty( $settings['password'] ) ) {
            return new WP_Error( 'missing_password', 'FTP password is required.' );
        }

        if ( empty( $settings['path'] ) ) {
            return new WP_Error( 'missing_path', 'Remote path is required.' );
        }

        if ( ! function_exists( 'ftp_connect' ) ) {
            return new WP_Error( 'missing_ftp', 'The PHP FTP extension is not available on this server.' );
        }

        return true;
    }

    private function command_available( $command ) {
        $path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null' ) );
        return '' !== $path;
    }

    private function prepare_runtime_settings( $settings ) {
        if ( 'ftp' === $settings['protocol'] ) {
            return $this->prepare_ftp_runtime_settings( $settings );
        }

        $runtime = $settings;
        $runtime['key_file'] = null;

        if ( 'key' !== $settings['auth'] ) {
            return $runtime;
        }

        $normalized_key = $this->normalize_private_key( $settings['key'] );
        $key_check      = $this->validate_private_key_text( $normalized_key );
        if ( is_wp_error( $key_check ) ) {
            return $key_check;
        }

        $key_file = tempnam( sys_get_temp_dir(), 'rb_key_' );
        if ( false === $key_file ) {
            return new WP_Error( 'key_temp', 'Failed to create a temporary private key file.' );
        }

        if ( false === file_put_contents( $key_file, $normalized_key ) ) {
            @unlink( $key_file );
            return new WP_Error( 'key_write', 'Failed to write the temporary private key file.' );
        }

        chmod( $key_file, 0600 );
        $runtime['key_file'] = $key_file;

        $key_check = $this->validate_private_key_file( $key_file );
        if ( is_wp_error( $key_check ) ) {
            $this->cleanup_runtime_settings( $runtime );
            return $key_check;
        }

        return $runtime;
    }

    private function prepare_ftp_runtime_settings( $settings ) {
        $runtime = $settings;
        $warning        = '';
        $runtime['ftp'] = $this->call_with_warning_capture(
            static function() use ( $settings ) {
                return ftp_connect( $settings['host'], $settings['port'], 20 );
            },
            $warning
        );

        if ( ! $runtime['ftp'] ) {
            return new WP_Error(
                'ftp_connect_failed',
                'Could not connect to the FTP server. Check the host, port, or firewall.' . $this->format_ftp_warning( $warning )
            );
        }

        if ( function_exists( 'ftp_set_option' ) && defined( 'FTP_TIMEOUT_SEC' ) ) {
            @ftp_set_option( $runtime['ftp'], FTP_TIMEOUT_SEC, 30 );
        }

        $warning = '';
        $logged_in = $this->call_with_warning_capture(
            static function() use ( $runtime, $settings ) {
                return ftp_login( $runtime['ftp'], $settings['username'], $settings['password'] );
            },
            $warning
        );

        if ( ! $logged_in ) {
            @ftp_close( $runtime['ftp'] );
            return new WP_Error(
                'ftp_login_failed',
                'FTP authentication failed. Check the username and password.' . $this->format_ftp_warning( $warning )
            );
        }

        $warning = '';
        $passive = $this->call_with_warning_capture(
            static function() use ( $runtime, $settings ) {
                return ftp_pasv( $runtime['ftp'], ! empty( $settings['passive'] ) );
            },
            $warning
        );

        if ( ! $passive ) {
            @ftp_close( $runtime['ftp'] );
            return new WP_Error(
                'ftp_passive_failed',
                'Connected to FTP, but failed to switch transfer mode. Toggle passive mode or check the server configuration.' . $this->format_ftp_warning( $warning )
            );
        }

        return $runtime;
    }

    private function normalize_private_key( $key ) {
        $key = (string) $key;
        $key = preg_replace( '/^\xEF\xBB\xBF/', '', $key );
        $key = trim( $key );

        if ( false === strpos( $key, "\n" ) && false !== strpos( $key, '\\n' ) ) {
            $key = str_replace( array( '\\r\\n', '\\n', '\\r' ), "\n", $key );
        }

        $key = str_replace( array( "\r\n", "\r" ), "\n", $key );

        $lines = array_map(
            static function( $line ) {
                return trim( $line );
            },
            explode( "\n", $key )
        );
        $lines = array_values( array_filter( $lines, static function( $line ) {
            return '' !== $line;
        } ) );

        if ( empty( $lines ) ) {
            return '';
        }

        return implode( "\n", $lines ) . "\n";
    }

    private function validate_private_key_text( $key ) {
        if ( '' === trim( $key ) ) {
            return new WP_Error( 'missing_key', 'No SSH private key is configured.' );
        }

        $first_line = strtok( $key, "\n" );
        if ( false !== $first_line && preg_match( '/^(ssh-(rsa|ed25519)|ecdsa-sha2-)/', trim( $first_line ) ) ) {
            return new WP_Error( 'public_key', 'The value in the Private Key field looks like a public key. Paste the private key instead.' );
        }

        if ( ! preg_match( '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $key ) ) {
            return new WP_Error( 'invalid_key_header', 'The private key header is missing or invalid.' );
        }

        if ( ! preg_match( '/-----END [A-Z0-9 ]*PRIVATE KEY-----/', $key ) ) {
            return new WP_Error( 'invalid_key_footer', 'The private key footer is missing or invalid.' );
        }

        return true;
    }

    private function validate_private_key_file( $key_file ) {
        if ( ! $this->command_available( 'ssh-keygen' ) ) {
            return true;
        }

        $output     = array();
        $return_var = 0;
        $cmd        = sprintf(
            'ssh-keygen -y -P "" -f %s 2>&1',
            escapeshellarg( $key_file )
        );

        exec( $cmd, $output, $return_var );

        if ( 0 === $return_var ) {
            return true;
        }

        $text       = $this->sanitize_process_output( $output );
        $normalized = strtolower( $text );

        if ( false !== strpos( $normalized, 'incorrect passphrase supplied' ) || false !== strpos( $normalized, 'passphrase' ) ) {
            return new WP_Error( 'encrypted_key', 'This private key is passphrase-protected. The plugin only supports unencrypted private keys.' );
        }

        if ( false !== strpos( $normalized, 'invalid format' ) || false !== strpos( $normalized, 'error in libcrypto' ) ) {
            return new WP_Error( 'invalid_key_format', 'The private key format is invalid. Re-save it as an unencrypted OpenSSH or PEM private key and paste it again.' );
        }

        return new WP_Error(
            'invalid_key',
            'The private key could not be read by `ssh-keygen`.' . ( '' !== $text ? ' Details: ' . $this->summarize_messages( array( $text ), 1, 180 ) : '' )
        );
    }

    private function cleanup_runtime_settings( $runtime ) {
        if ( ! empty( $runtime['ftp'] ) ) {
            @ftp_close( $runtime['ftp'] );
        }

        if ( ! empty( $runtime['key_file'] ) && file_exists( $runtime['key_file'] ) ) {
            unlink( $runtime['key_file'] );
        }
    }

    private function collect_backup_artifacts( $backup ) {
        $files = array();

        foreach ( array( 'db_file', 'files_file', 'plugins_file' ) as $key ) {
            if ( empty( $backup[ $key ] ) ) {
                continue;
            }

            $local = $this->storage->resolve_storage_path( $backup[ $key ] );
            if ( ! file_exists( $local ) ) {
                return new WP_Error( 'missing_artifact', 'Local backup artifact is missing: ' . basename( $local ) );
            }

            $files[] = $local;
        }

        return $files;
    }

    private function ensure_remote_directory( $settings ) {
        if ( 'ftp' === $settings['protocol'] ) {
            return $this->ensure_ftp_remote_directory( $settings );
        }

        $command = sprintf(
            'mkdir -p %1$s && test -d %1$s',
            escapeshellarg( $settings['path'] )
        );

        $result = $this->execute_ssh_command( $settings, $command, 20 );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( 'remote_dir', 'Could not prepare the remote directory: ' . $result->get_error_message() );
        }

        return true;
    }

    private function ensure_ftp_remote_directory( $settings ) {
        $ftp          = $settings['ftp'];
        $path         = $settings['path'];
        $original_dir = @ftp_pwd( $ftp );

        if ( $this->ftp_directory_exists( $ftp, $path ) ) {
            $this->restore_ftp_directory( $ftp, $original_dir );
            return true;
        }

        $segments    = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
        $is_absolute = '/' === substr( $path, 0, 1 );
        $warning     = '';

        if ( $is_absolute ) {
            $root_changed = $this->call_with_warning_capture(
                static function() use ( $ftp ) {
                    return ftp_chdir( $ftp, '/' );
                },
                $warning
            );

            if ( ! $root_changed ) {
                $this->restore_ftp_directory( $ftp, $original_dir );
                return new WP_Error(
                    'ftp_root_failed',
                    'Connected to FTP, but could not access the server root for the configured remote path.' . $this->format_ftp_warning( $warning )
                );
            }
        }

        if ( empty( $segments ) ) {
            $this->restore_ftp_directory( $ftp, $original_dir );
            return true;
        }

        foreach ( $segments as $segment ) {
            $warning = '';
            $changed = $this->call_with_warning_capture(
                static function() use ( $ftp, $segment ) {
                    return ftp_chdir( $ftp, $segment );
                },
                $warning
            );

            if ( $changed ) {
                continue;
            }

            $warning = '';
            $created = $this->call_with_warning_capture(
                static function() use ( $ftp, $segment ) {
                    return ftp_mkdir( $ftp, $segment );
                },
                $warning
            );

            if ( false === $created ) {
                $retry_changed = $this->call_with_warning_capture(
                    static function() use ( $ftp, $segment ) {
                        return ftp_chdir( $ftp, $segment );
                    },
                    $warning
                );

                if ( ! $retry_changed ) {
                    $this->restore_ftp_directory( $ftp, $original_dir );
                    return new WP_Error(
                        'ftp_dir_failed',
                        'Connected to FTP, but the remote directory could not be created or accessed: ' . $path . $this->format_ftp_warning( $warning )
                    );
                }

                continue;
            }

            $warning = '';
            $changed = $this->call_with_warning_capture(
                static function() use ( $ftp, $segment ) {
                    return ftp_chdir( $ftp, $segment );
                },
                $warning
            );

            if ( ! $changed ) {
                $this->restore_ftp_directory( $ftp, $original_dir );
                return new WP_Error(
                    'ftp_dir_enter_failed',
                    'Connected to FTP, but the remote directory was created and could not be accessed: ' . $path . $this->format_ftp_warning( $warning )
                );
            }
        }

        $this->restore_ftp_directory( $ftp, $original_dir );
        return true;
    }

    private function transfer_file_to_remote( $settings, $local_path, $remote_dest ) {
        if ( 'ftp' === $settings['protocol'] ) {
            return $this->ftp_send( $settings, $local_path, $remote_dest );
        }

        return $this->scp_send( $settings, $local_path, $remote_dest );
    }

    private function scp_send( $settings, $local_path, $remote_dest ) {
        $ssh_opts    = $this->build_ssh_options( $settings['auth'], 30 );
        $remote_spec = escapeshellarg( $settings['username'] . '@' . $settings['host'] . ':' . $remote_dest );

        if ( 'key' === $settings['auth'] ) {
            $cmd = sprintf(
                'scp %1$s -i %2$s -P %3$d %4$s %5$s 2>&1',
                $ssh_opts,
                escapeshellarg( $settings['key_file'] ),
                $settings['port'],
                escapeshellarg( $local_path ),
                $remote_spec
            );
        } else {
            $cmd = sprintf(
                'sshpass -p %1$s scp %2$s -P %3$d %4$s %5$s 2>&1',
                escapeshellarg( $settings['password'] ),
                $ssh_opts,
                $settings['port'],
                escapeshellarg( $local_path ),
                $remote_spec
            );
        }

        $this->logger->log( 'SCP: ' . basename( $local_path ) . ' → ' . $remote_dest );

        $output     = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );

        if ( 0 !== $return_var ) {
            $message = $this->format_process_failure( 'SCP transfer', $return_var, $output, $settings['auth'] );
            $this->logger->log( 'SCP failed: ' . $message, 'error' );
            return new WP_Error( 'scp_fail', $message );
        }

        $this->logger->log( 'SCP OK: ' . basename( $local_path ) );
        return true;
    }

    private function ftp_send( $settings, $local_path, $remote_dest ) {
        $this->logger->log( 'FTP: ' . basename( $local_path ) . ' → ' . $remote_dest );

        $warning = '';
        $sent    = $this->call_with_warning_capture(
            static function() use ( $settings, $local_path, $remote_dest ) {
                return ftp_put( $settings['ftp'], $remote_dest, $local_path, FTP_BINARY );
            },
            $warning
        );

        if ( ! $sent ) {
            $message = 'FTP transfer failed.' . $this->format_ftp_warning( $warning, ' The remote path may not exist or may not be writable.' );
            $this->logger->log( 'FTP failed: ' . $message, 'error' );
            return new WP_Error( 'ftp_put_failed', $message );
        }

        $this->logger->log( 'FTP OK: ' . basename( $local_path ) );
        return true;
    }

    private function test_ftp_connection( $settings ) {
        $runtime = $this->prepare_runtime_settings( $settings );
        if ( is_wp_error( $runtime ) ) {
            return $runtime;
        }

        $ftp              = $runtime['ftp'];
        $directory_exists = $this->ftp_directory_exists( $ftp, $settings['path'] );
        $directory        = $this->ensure_ftp_remote_directory( $runtime );

        if ( is_wp_error( $directory ) ) {
            $this->cleanup_runtime_settings( $runtime );
            return $directory;
        }

        $probe_local = tempnam( sys_get_temp_dir(), 'rb_ftp_test_' );
        if ( false === $probe_local ) {
            $this->cleanup_runtime_settings( $runtime );
            return new WP_Error( 'ftp_probe_temp', 'Connected to FTP, but could not create a local test file.' );
        }

        if ( false === file_put_contents( $probe_local, 'Remote Backup FTP probe ' . gmdate( 'c' ) ) ) {
            @unlink( $probe_local );
            $this->cleanup_runtime_settings( $runtime );
            return new WP_Error( 'ftp_probe_write', 'Connected to FTP, but could not write the local test file.' );
        }

        $probe_remote = $this->join_remote_path( $settings['path'], '.rb-write-test-' . wp_generate_password( 8, false, false ) );
        $warning      = '';
        $uploaded     = $this->call_with_warning_capture(
            static function() use ( $ftp, $probe_local, $probe_remote ) {
                return ftp_put( $ftp, $probe_remote, $probe_local, FTP_BINARY );
            },
            $warning
        );
        @unlink( $probe_local );

        if ( ! $uploaded ) {
            $this->cleanup_runtime_settings( $runtime );
            return new WP_Error(
                'ftp_probe_upload_failed',
                'Connected to FTP, but failed to write a test file to `' . $settings['path'] . '`.' . $this->format_ftp_warning( $warning, ' The remote path may not exist or may not be writable.' )
            );
        }

        $warning = '';
        $deleted = $this->call_with_warning_capture(
            static function() use ( $ftp, $probe_remote ) {
                return ftp_delete( $ftp, $probe_remote );
            },
            $warning
        );

        $this->cleanup_runtime_settings( $runtime );

        if ( ! $deleted ) {
            $this->logger->log(
                'FTP probe cleanup failed for ' . $probe_remote . $this->format_ftp_warning( $warning, '' ),
                'warning'
            );
        }

        if ( $directory_exists ) {
            return 'Connected OK — FTP directory exists and is writable.';
        }

        return 'Connected OK — FTP directory was created and is writable.';
    }

    private function execute_ssh_command( $settings, $remote_command, $timeout = 15 ) {
        $ssh_opts = $this->build_ssh_options( $settings['auth'], $timeout );
        $target   = escapeshellarg( $settings['username'] . '@' . $settings['host'] );
        $command  = escapeshellarg( $remote_command );

        if ( 'key' === $settings['auth'] ) {
            $cmd = sprintf(
                'ssh %1$s -i %2$s -p %3$d %4$s %5$s 2>&1',
                $ssh_opts,
                escapeshellarg( $settings['key_file'] ),
                $settings['port'],
                $target,
                $command
            );
        } else {
            $cmd = sprintf(
                'sshpass -p %1$s ssh %2$s -p %3$d %4$s %5$s 2>&1',
                escapeshellarg( $settings['password'] ),
                $ssh_opts,
                $settings['port'],
                $target,
                $command
            );
        }

        $output     = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );

        if ( 0 !== $return_var ) {
            return new WP_Error( 'ssh_fail', $this->format_process_failure( 'SSH connection', $return_var, $output, $settings['auth'] ) );
        }

        return array(
            'output' => $output,
            'text'   => $this->sanitize_process_output( $output ),
        );
    }

    private function build_ssh_options( $auth, $timeout ) {
        $options = array(
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
            '-o ConnectTimeout=' . absint( $timeout ),
        );

        if ( 'key' === $auth ) {
            $options[] = '-o BatchMode=yes';
        }

        return implode( ' ', $options );
    }

    private function format_process_failure( $context, $return_var, $output, $auth ) {
        $text       = $this->sanitize_process_output( $output );
        $normalized = strtolower( $text );
        $reason     = 'The remote server returned an unknown error.';

        if ( false !== strpos( $normalized, 'permission denied' ) ) {
            $reason = 'Authentication failed. Check the username and ' . ( 'password' === $auth ? 'password.' : 'private key.' );
        } elseif ( false !== strpos( $normalized, 'publickey' ) ) {
            $reason = 'The remote server rejected the SSH key for this user.';
        } elseif ( false !== strpos( $normalized, 'error in libcrypto' ) ) {
            $reason = 'The private key could not be parsed. It is usually malformed, uses Windows line endings, or is encrypted with a passphrase.';
        } elseif ( false !== strpos( $normalized, 'incorrect passphrase supplied' ) || false !== strpos( $normalized, 'enter passphrase' ) ) {
            $reason = 'This private key is passphrase-protected, and the plugin only supports unencrypted keys.';
        } elseif ( false !== strpos( $normalized, 'connection refused' ) ) {
            $reason = 'The SSH service is not accepting connections on this port.';
        } elseif ( false !== strpos( $normalized, 'connection timed out' ) || false !== strpos( $normalized, 'operation timed out' ) ) {
            $reason = 'The connection timed out. Check the host, port, firewall, or routing.';
        } elseif ( false !== strpos( $normalized, 'no route to host' ) || false !== strpos( $normalized, 'network is unreachable' ) ) {
            $reason = 'This server cannot reach the remote host.';
        } elseif ( false !== strpos( $normalized, 'could not resolve hostname' ) || false !== strpos( $normalized, 'name or service not known' ) ) {
            $reason = 'The hostname could not be resolved from this server.';
        } elseif ( false !== strpos( $normalized, 'invalid format' ) ) {
            $reason = 'The private key format is invalid or unsupported.';
        } elseif ( false !== strpos( $normalized, 'bad permissions' ) || false !== strpos( $normalized, 'unprotected private key file' ) ) {
            $reason = 'SSH rejected the private key file permissions.';
        } elseif ( false !== strpos( $normalized, 'no such file or directory' ) ) {
            $reason = 'The remote path or a required local file was not found.';
        }

        if ( '' !== $text ) {
            $reason .= ' SSH said: ' . $this->summarize_messages( array( $text ), 1, 220 );
        }

        return sprintf( '%1$s failed (exit %2$d). %3$s', $context, $return_var, $reason );
    }

    private function sanitize_process_output( $output ) {
        $lines = array();

        foreach ( (array) $output as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            if ( 0 === strpos( $line, 'Warning: Permanently added' ) ) {
                continue;
            }
            $lines[] = $line;
        }

        return implode( "\n", $lines );
    }

    private function call_with_warning_capture( $callback, &$warning = '' ) {
        $warning = '';
        set_error_handler(
            static function( $errno, $errstr ) use ( &$warning ) {
                $warning = trim( (string) $errstr );
                return true;
            }
        );

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private function format_ftp_warning( $warning, $fallback = '' ) {
        $warning = trim( (string) $warning );
        if ( '' === $warning ) {
            return $fallback;
        }

        $warning = preg_replace( '/^ftp_[a-z_]+\(\):\s*/i', '', $warning );
        return ' FTP said: ' . $warning;
    }

    private function ftp_directory_exists( $ftp, $path ) {
        $original_dir = @ftp_pwd( $ftp );
        $warning      = '';
        $changed      = $this->call_with_warning_capture(
            static function() use ( $ftp, $path ) {
                return ftp_chdir( $ftp, $path );
            },
            $warning
        );

        if ( $changed && false !== $original_dir ) {
            $this->restore_ftp_directory( $ftp, $original_dir );
        }

        return (bool) $changed;
    }

    private function restore_ftp_directory( $ftp, $directory ) {
        if ( false === $directory || '' === $directory || null === $directory ) {
            return;
        }

        @ftp_chdir( $ftp, $directory );
    }

    private function summarize_messages( $messages, $max_items = 2, $max_chars = 260 ) {
        $messages = array_values( array_filter( array_map( 'trim', (array) $messages ) ) );
        if ( empty( $messages ) ) {
            return 'No additional details were returned.';
        }

        $messages = array_slice( $messages, 0, $max_items );
        $text     = implode( ' | ', $messages );

        if ( strlen( $text ) > $max_chars ) {
            $text = substr( $text, 0, $max_chars - 3 ) . '...';
        }

        return $text;
    }

    private function finalize_remote_result( $backup, $status, $message, $settings ) {
        $changes = array(
            'remote_status'      => $status,
            'remote_message'     => $message,
            'remote_uploaded_at' => 'success' === $status ? current_time( 'mysql' ) : null,
            'remote_destination' => $this->format_remote_target( $settings ),
        );

        $updated = $backup;
        if ( ! empty( $backup['id'] ) ) {
            $stored = $this->storage->update_backup( $backup['id'], $changes );
            if ( $stored ) {
                $updated = $stored;
            } else {
                $updated = array_merge( $backup, $changes );
            }
        } else {
            $updated = array_merge( $backup, $changes );
        }

        return array(
            'status'  => $status,
            'message' => $message,
            'backup'  => $updated,
        );
    }

    private function format_remote_target( $settings ) {
        $host     = $settings['host'] ?? '';
        $username = $settings['username'] ?? '';
        $path     = $settings['path'] ?? '';

        if ( 'ftp' === ( $settings['protocol'] ?? 'ssh' ) ) {
            $port   = absint( $settings['port'] ?? 21 ) ?: 21;
            $target = 'ftp://';

            if ( '' !== $username ) {
                $target .= $username . '@';
            }

            $target .= $host;

            if ( 21 !== $port ) {
                $target .= ':' . $port;
            }

            if ( '' !== $path ) {
                $target .= '/' . ltrim( $path, '/' );
            }

            return rtrim( $target, '/' );
        }

        $target = '';
        if ( '' !== $username || '' !== $host ) {
            $target = trim( $username . '@' . $host, '@' );
        }

        if ( '' !== $path ) {
            $target .= ':' . $path;
        }

        return ltrim( $target, ':' );
    }

    private function join_remote_path( $base, $leaf ) {
        if ( '/' === $base ) {
            return '/' . ltrim( $leaf, '/' );
        }

        return rtrim( $base, '/' ) . '/' . ltrim( $leaf, '/' );
    }
}
