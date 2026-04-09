<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remote_Backup_Scheduler {

    private $runner;
    private $logger;
    private $storage;

    /** @var Remote_Provider[] */
    private $providers = array();

    const CRON_HOOK          = 'sprb_scheduled_backup';
    const CRON_HOOK_DATABASE = 'sprb_scheduled_backup_database';
    const CRON_HOOK_FILES    = 'sprb_scheduled_backup_files';

    public function __construct( Remote_Backup_Runner $runner, Remote_Backup_Logger $logger, Remote_Backup_Storage $storage ) {
        $this->runner  = $runner;
        $this->logger  = $logger;
        $this->storage = $storage;

        add_action( self::CRON_HOOK, array( $this, 'run_legacy_scheduled' ) );
        add_action( self::CRON_HOOK_DATABASE, array( $this, 'run_scheduled_database' ) );
        add_action( self::CRON_HOOK_FILES, array( $this, 'run_scheduled_files' ) );
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
    }

    /**
     * Register a remote storage provider.
     */
    public function register_provider( Remote_Provider $provider ) {
        $this->providers[ $provider->get_key() ] = $provider;
    }

    /**
     * Get the currently active provider based on the rb_remote_protocol option.
     *
     * @return Remote_Provider|null
     */
    public function get_provider( ?string $key = null ): ?Remote_Provider {
        $key = $key ?? $this->get_active_protocol();
        return $this->providers[ $key ] ?? null;
    }

    /**
     * Get all registered providers.
     *
     * @return Remote_Provider[]
     */
    public function get_providers(): array {
        return $this->providers;
    }

    /**
     * Get the stored protocol key.
     */
    public function get_active_protocol(): string {
        return sanitize_text_field( (string) get_option( 'sprb_remote_protocol', 'ssh' ) );
    }

    public function add_schedules( $schedules ) {
        $schedules['sprb_every_6h'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every 6 hours',
        );
        $schedules['sprb_every_12h'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => 'Every 12 hours',
        );
        return $schedules;
    }

    public function run_legacy_scheduled() {
        $scope = get_option( 'sprb_scheduled_scope', 'both' );
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
        $remote_mode = $this->normalize_remote_mode( get_option( "sprb_scheduled_remote_mode_{$scope}", 'remote' ) );
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
        $provider = $this->get_provider();
        return $provider && $provider->is_ready();
    }

    public function send_backup_to_remote( $backup, $context = 'Backup' ) {
        $provider = $this->get_provider();
        if ( ! $provider ) {
            $message = 'Remote upload failed: no provider is configured for protocol "' . $this->get_active_protocol() . '".';
            $this->logger->log( "{$context} remote upload FAILED: no provider configured.", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, array() );
        }

        $settings = $provider->get_settings();
        $target   = $provider->format_destination( $settings );

        $validation = $provider->validate_settings( $settings );
        if ( is_wp_error( $validation ) ) {
            $message = 'Remote upload failed: ' . $validation->get_error_message();
            $this->logger->log( "{$context} remote upload FAILED: {$validation->get_error_message()}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        $runtime = $provider->prepare( $settings );
        if ( is_wp_error( $runtime ) ) {
            $message = 'Remote upload failed: ' . $runtime->get_error_message();
            $this->logger->log( "{$context} remote upload FAILED: {$runtime->get_error_message()}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        $this->logger->log( "{$context} remote upload started — {$target}" );

        $files = $this->collect_backup_artifacts( $backup );
        if ( is_wp_error( $files ) ) {
            $provider->cleanup( $runtime );
            $message = 'Remote upload failed: ' . $files->get_error_message();
            $this->logger->log( "{$context} remote upload FAILED: {$files->get_error_message()}", 'error' );
            return $this->finalize_remote_result( $backup, 'failed', $message, $settings );
        }

        if ( empty( $files ) ) {
            $provider->cleanup( $runtime );
            $message = 'Remote upload skipped: no backup artifacts were available to upload.';
            $this->logger->log( "{$context} remote upload skipped — no files found.", 'warning' );
            return $this->finalize_remote_result( $backup, 'skipped', $message, $settings );
        }

        $errors = array();
        foreach ( $files as $local_path ) {
            $transfer = $provider->send( $runtime, $local_path, basename( $local_path ) );
            if ( is_wp_error( $transfer ) ) {
                $errors[] = basename( $local_path ) . ': ' . $transfer->get_error_message();
            }
        }

        $provider->cleanup( $runtime );

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
        $provider = $this->get_provider();
        if ( ! $provider ) {
            return new WP_Error( 'no_provider', 'No provider is configured for protocol "' . $this->get_active_protocol() . '".' );
        }

        $settings   = $provider->get_settings();
        $validation = $provider->validate_settings( $settings );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        return $provider->test_connection( $settings );
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

        return $this->sanitize_schedule_time( get_option( 'sprb_schedule_time', '02:00' ) );
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
        $allowed = array( 'none', 'hourly', 'sprb_every_6h', 'sprb_every_12h', 'daily', 'twicedaily', 'weekly' );

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
        $frequency_key  = 'sprb_schedule_' . $scope . '_frequency';
        $time_key       = 'sprb_schedule_' . $scope . '_time';
        $weekday_key    = 'sprb_schedule_' . $scope . '_weekday';
        $frequency_raw  = get_option( $frequency_key, '__sprb_missing__' );
        $time_raw       = get_option( $time_key, '__sprb_missing__' );
        $weekday_raw    = get_option( $weekday_key, '__sprb_missing__' );
        $legacy         = $this->legacy_scope_schedule( $scope );
        $frequency      = '__sprb_missing__' === $frequency_raw ? $legacy['frequency'] : $this->sanitize_schedule_frequency( $frequency_raw );
        $weekday        = '__sprb_missing__' === $weekday_raw
            ? $this->scheduled_scope_weekday( $scope, $legacy['configured_weekday'] ?? $this->default_schedule_weekday() )
            : $this->sanitize_schedule_weekday( $weekday_raw );

        return array(
            'scope'           => $scope,
            'frequency'       => $frequency,
            'configured_time' => '__sprb_missing__' === $time_raw ? $legacy['configured_time'] : $this->sanitize_schedule_time( $time_raw ),
            'configured_weekday' => 'weekly' === $frequency ? $weekday : null,
        );
    }

    private function legacy_scope_schedule( $scope ) {
        $legacy_scope = sanitize_text_field( (string) get_option( 'sprb_scheduled_scope', 'both' ) );
        $legacy_freq  = $this->sanitize_schedule_frequency( get_option( 'sprb_schedule_frequency', 'none' ) );
        $legacy_time  = $this->sanitize_schedule_time( get_option( 'sprb_schedule_time', '02:00' ) );

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
        $provider    = $this->get_provider();
        $destination = $provider ? $provider->format_destination( $settings ) : '';

        $changes = array(
            'remote_status'      => $status,
            'remote_message'     => $message,
            'remote_uploaded_at' => 'success' === $status ? current_time( 'mysql' ) : null,
            'remote_destination' => $destination,
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
}
