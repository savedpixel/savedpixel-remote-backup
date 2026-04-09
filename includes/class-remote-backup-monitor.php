<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'Remote_Backup_Monitor' ) ) {
    return;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Monitor downloads use native file handles and atomic moves for large artifacts.
class Remote_Backup_Monitor {

    const CRON_HOOK        = 'sprb_monitor_poll';
    const CRON_SCHEDULE    = 'sprb_monitor_every_5m';
    const HISTORY_MAX_DAYS = 90;
    const PROGRESS_TTL     = DAY_IN_SECONDS;

    private $logger;
    private $storage;
    private $sites_path;
    private $status_path;
    private $history_path;

    public function __construct( Remote_Backup_Logger $logger, Remote_Backup_Storage $storage ) {
        $this->logger       = $logger;
        $this->storage      = $storage;
        $this->sites_path   = SPRB_DATA_DIR . 'monitor-sites.json';
        $this->status_path  = SPRB_DATA_DIR . 'monitor.json';
        $this->history_path = SPRB_DATA_DIR . 'monitor-history.json';

        add_action( self::CRON_HOOK, array( $this, 'poll_due_sites' ) );
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
    }

    public function add_schedules( $schedules ) {
        $schedules[ self::CRON_SCHEDULE ] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 minutes',
        );

        return $schedules;
    }

    public function get_settings() {
        $retry_minutes = absint( get_option( 'sprb_monitor_retry_minutes', 15 ) );
        if ( $retry_minutes < 5 ) {
            $retry_minutes = 5;
        }

        $watch_minutes = absint( get_option( 'sprb_monitor_watch_minutes', 90 ) );
        if ( $watch_minutes < $retry_minutes ) {
            $watch_minutes = $retry_minutes;
        }

        $notification_email = trim( (string) get_option( 'sprb_monitor_notification_email', '' ) );
        if ( '' === $notification_email ) {
            $notification_email = (string) get_option( 'admin_email', '' );
        }

        return array(
            'retry_minutes'      => $retry_minutes,
            'poll_delay_minutes' => $retry_minutes,
            'watch_minutes'      => $watch_minutes,
            'notification_email' => $notification_email,
        );
    }

    public function get_sites() {
        if ( ! file_exists( $this->sites_path ) ) {
            return array();
        }

        $data  = json_decode( file_get_contents( $this->sites_path ), true );
        $sites = is_array( $data ) ? $data : array();

        return array_values(
            array_filter(
                array_map( array( $this, 'normalize_site' ), $sites ),
                function ( $site ) {
                    return ! empty( $site['url'] );
                }
            )
        );
    }

    public function add_site( $url, $label = '', $pull_token = '' ) {
        $site = $this->normalize_site(
            array(
                'url'        => $url,
                'label'      => $label,
                'pull_token' => $pull_token,
            )
        );

        if ( empty( $site['url'] ) ) {
            return new WP_Error( 'sprb_monitor', 'Invalid URL.' );
        }

        $sites   = $this->get_sites();
        $updated = false;

        foreach ( $sites as &$existing ) {
            if ( $existing['url'] !== $site['url'] ) {
                continue;
            }

            $existing['label']       = $site['label'];
            $existing['pull_token']  = $site['pull_token'];
            $existing['pull_enabled'] = $site['pull_enabled'];
            $updated                 = true;
            break;
        }
        unset( $existing );

        if ( ! $updated ) {
            $site['added'] = current_time( 'mysql' );
            $sites[]       = $site;
        }

        $this->save_json( $this->sites_path, $sites );
        $this->ensure_cron();
        $this->logger->log(
            sprintf(
                'Monitor: site %1$s — %2$s (pull: %3$s)',
                $updated ? 'updated' : 'added',
                $site['url'],
                $site['pull_enabled'] ? 'enabled' : 'status-only'
            )
        );

        return $updated ? 'updated' : 'created';
    }

    public function sync_site_schedule( $url ) {
        return $this->poll_site( $url, true );
    }

    public function remove_site( $url ) {
        $url     = untrailingslashit( esc_url_raw( $url ) );
        $sites   = $this->get_sites();
        $updated = array();
        $found   = false;

        foreach ( $sites as $site ) {
            if ( $site['url'] === $url ) {
                $found = true;
                continue;
            }

            $updated[] = $site;
        }

        if ( ! $found ) {
            return new WP_Error( 'sprb_monitor', 'Site not found.' );
        }

        $this->save_json( $this->sites_path, $updated );

        $statuses = $this->get_statuses();
        unset( $statuses[ $url ] );
        $this->save_json( $this->status_path, $statuses );

        if ( empty( $updated ) ) {
            $this->unschedule_cron();
        }

        $this->logger->log( "Monitor: site removed — {$url}" );

        return true;
    }

    public function get_statuses() {
        if ( ! file_exists( $this->status_path ) ) {
            return array();
        }

        $data     = json_decode( file_get_contents( $this->status_path ), true );
        $statuses = is_array( $data ) ? $data : array();

        foreach ( $statuses as $url => $status ) {
            $statuses[ $url ] = $this->normalize_status( $status );
        }

        return $statuses;
    }

    public function get_site_progress( $url ) {
        $url   = untrailingslashit( esc_url_raw( (string) $url ) );
        $state = get_transient( $this->progress_key( $url ) );
        if ( ! is_array( $state ) ) {
            return null;
        }

        $state = wp_parse_args(
            $state,
            array(
                'url'              => $url,
                'running'          => false,
                'status'           => 'idle',
                'action'           => 'poll',
                'phase'            => 'idle',
                'message'          => '',
                'artifact_types'   => array(),
                'artifact_label'   => '',
                'backup_id'        => null,
                'filename'         => null,
                'target_path'      => null,
                'downloaded_bytes' => 0,
                'total_bytes'      => 0,
                'percent'          => 0,
                'started_at'       => null,
                'finished_at'      => null,
                'updated_at'       => null,
            )
        );

        if ( ! empty( $state['target_path'] ) && file_exists( $state['target_path'] ) ) {
            $state['downloaded_bytes'] = (int) filesize( $state['target_path'] );
        } else {
            $state['downloaded_bytes'] = (int) ( $state['downloaded_bytes'] ?? 0 );
        }

        $total = (int) ( $state['total_bytes'] ?? 0 );
        $done  = (int) ( $state['downloaded_bytes'] ?? 0 );
        if ( $total > 0 ) {
            $state['percent'] = (int) min( 100, floor( ( $done / $total ) * 100 ) );
        } else {
            $state['percent'] = 0;
        }

        unset( $state['target_path'] );

        return $state;
    }

    public function pull_site_artifact( $url, $artifact_type ) {
        $artifact_type = sanitize_text_field( (string) $artifact_type );
        if ( ! in_array( $artifact_type, array( 'database', 'files', 'plugins' ), true ) ) {
            return new WP_Error( 'sprb_monitor_artifact', 'Invalid artifact type.' );
        }

        return $this->poll_site(
            $url,
            true,
            array(
                'action'             => 'pull_' . $artifact_type,
                'artifact_types'     => array( $artifact_type ),
                'latest_only'        => true,
                'download_artifacts' => true,
            )
        );
    }

    public function poll_due_sites() {
        $sites = $this->get_sites();
        foreach ( $sites as $site ) {
            $this->poll_site(
                $site['url'],
                false,
                array(
                    'action'             => 'scheduled_poll',
                    'download_artifacts' => true,
                )
            );
        }
    }

    public function poll_all( $force = true ) {
        $sites = $this->get_sites();
        foreach ( $sites as $site ) {
            $this->poll_site( $site['url'], $force );
        }
    }

    public function poll_site( $url, $force = true, $options = array() ) {
        $site = $this->get_site( $url );
        if ( ! $site ) {
            return new WP_Error( 'sprb_monitor', 'Site not found.' );
        }

        $options = $this->normalize_poll_options( $options );

        $settings = $this->get_settings();
        $statuses = $this->get_statuses();
        $entry    = $this->normalize_status( $statuses[ $site['url'] ] ?? array() );
        $now_ts   = current_time( 'timestamp', true );

        if ( ! $force && ! $this->should_poll_site( $entry, $now_ts ) ) {
            return $entry;
        }

        $lock = $this->acquire_site_lock( $site['url'] );
        if ( is_wp_error( $lock ) ) {
            $this->set_site_progress(
                $site['url'],
                array(
                    'running'        => false,
                    'status'         => 'warning',
                    'action'         => $options['action'],
                    'phase'          => 'idle',
                    'message'        => $lock->get_error_message(),
                    'artifact_types' => $options['artifact_types'],
                    'artifact_label' => $this->artifact_types_label( $options['artifact_types'] ),
                    'finished_at'    => current_time( 'mysql' ),
                )
            );
            $this->logger->log( "Monitor: skipped {$site['url']} — " . $lock->get_error_message() );
            return $entry;
        }

        $this->set_site_progress(
            $site['url'],
            array(
                'running'        => true,
                'status'         => 'running',
                'action'         => $options['action'],
                'phase'          => 'status',
                'message'        => 'Checking remote backup status…',
                'artifact_types' => $options['artifact_types'],
                'artifact_label' => $this->artifact_types_label( $options['artifact_types'] ),
                'started_at'     => current_time( 'mysql' ),
            )
        );

        try {
            $response = $this->fetch_remote_status( $site );
            if ( is_wp_error( $response ) ) {
                $entry = $this->handle_offline_poll( $site, $entry, $settings, $response, $now_ts );
                $statuses[ $site['url'] ] = $entry;
                $this->save_json( $this->status_path, $statuses );
                $this->append_history( $site['url'], $entry );
                $this->set_site_progress(
                    $site['url'],
                    array(
                        'running'     => false,
                        'status'      => 'failed',
                        'phase'       => 'failed',
                        'message'     => $response->get_error_message(),
                        'finished_at' => current_time( 'mysql' ),
                    )
                );
                $this->logger->log( "Monitor: poll failed for {$site['url']} — " . $response->get_error_message(), 'error' );

                return $entry;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 !== $code || ! is_array( $body ) ) {
                $error = new WP_Error( 'sprb_monitor_http', 'HTTP ' . $code );
                $entry = $this->handle_offline_poll( $site, $entry, $settings, $error, $now_ts );
                $statuses[ $site['url'] ] = $entry;
                $this->save_json( $this->status_path, $statuses );
                $this->append_history( $site['url'], $entry );
                $this->set_site_progress(
                    $site['url'],
                    array(
                        'running'     => false,
                        'status'      => 'failed',
                        'phase'       => 'failed',
                        'message'     => 'Catalog request returned HTTP ' . $code . '.',
                        'finished_at' => current_time( 'mysql' ),
                    )
                );
                $this->logger->log( "Monitor: poll returned HTTP {$code} for {$site['url']}", 'error' );

                return $entry;
            }

            $last_backup     = is_array( $body['last_backup'] ?? null ) ? $body['last_backup'] : array();
            $last_successful = is_array( $body['last_successful'] ?? null ) ? $body['last_successful'] : array();
            $legacy_schedule = is_array( $body['schedule'] ?? null ) ? $body['schedule'] : array();
            $scope_schedules = $this->normalize_scope_schedule_map( $body['schedules'] ?? array(), $legacy_schedule );
            $schedule        = $this->primary_scope_schedule( $scope_schedules, $legacy_schedule );

            $entry['last_checked']         = current_time( 'mysql' );
            $entry['status']               = 'ok';
            $entry['error']                = null;
            $entry['plugin_version']       = $body['plugin_version'] ?? null;
            $entry['last_backup_date']     = $last_backup['date'] ?? null;
            $entry['last_backup_date_gmt'] = $last_backup['date_gmt'] ?? null;
            $entry['last_backup_status']   = $last_backup['status'] ?? null;
            $entry['last_successful_date'] = $last_successful['date'] ?? null;
            $entry['pull_enabled']         = ! empty( $site['pull_enabled'] );
            $entry['scope_schedules']      = $scope_schedules;

            $entry = $this->sync_schedule_state( $entry, $schedule, $last_backup, $settings, $now_ts );
            $this->sync_site_schedule_snapshot( $site['url'], $scope_schedules, $schedule );

            if ( $entry['pull_enabled'] && ! empty( $options['download_artifacts'] ) ) {
                $entry = $this->download_pending_backups( $site, $entry, $options );
            } elseif ( ! $entry['pull_enabled'] ) {
                $entry['last_download_status']  = null;
                $entry['last_download_message'] = null;
                $entry['last_download_storage_dir'] = null;
            }

            list( $entry['status'], $entry['status_reason'] ) = $this->derive_status( $entry, $now_ts );
            $entry           = $this->maybe_alert_for_state( $site, $entry, $settings );

            if ( 'ok' === $entry['status'] ) {
                $entry = $this->clear_alert( $entry );
            }

            $statuses[ $site['url'] ] = $entry;
            $this->save_json( $this->status_path, $statuses );
            $this->append_history( $site['url'], $entry );
            $this->logger->log( "Monitor: polled {$site['url']} — status: {$entry['status']}" );
            $this->set_site_progress(
                $site['url'],
                array(
                    'running'     => false,
                    'status'      => 'failed' === ( $entry['last_download_status'] ?? '' ) ? 'failed' : 'success',
                    'phase'       => 'complete',
                    'message'     => ( ! empty( $options['download_artifacts'] ) && ! empty( $entry['last_download_message'] ) )
                        ? (string) $entry['last_download_message']
                        : 'Site poll finished.',
                    'finished_at' => current_time( 'mysql' ),
                )
            );

            return $entry;
        } finally {
            $this->release_site_lock( $lock );
        }
    }

    public function get_history( $url = null, $limit = 50 ) {
        if ( ! file_exists( $this->history_path ) ) {
            return array();
        }

        $data = json_decode( file_get_contents( $this->history_path ), true );
        if ( ! is_array( $data ) ) {
            return array();
        }

        if ( $url ) {
            $data = array_values(
                array_filter(
                    $data,
                    function ( $item ) use ( $url ) {
                        return ( $item['url'] ?? '' ) === $url;
                    }
                )
            );
        }

        return array_slice( array_reverse( $data ), 0, $limit );
    }

    public function get_pulled_backups( $url, $limit = 20 ) {
        return $this->storage->get_remote_pulled_backups( $url, $limit );
    }

    public function ensure_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public function unschedule_cron() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    private function get_site( $url ) {
        $url = untrailingslashit( esc_url_raw( $url ) );
        foreach ( $this->get_sites() as $site ) {
            if ( $site['url'] === $url ) {
                return $site;
            }
        }

        return null;
    }

    private function should_poll_site( $entry, $now_ts ) {
        $next_poll = $this->gmt_to_timestamp( $entry['next_poll_at'] ?? null );

        if ( ! $next_poll ) {
            return true;
        }

        return $next_poll <= $now_ts;
    }

    private function normalize_site( $site ) {
        $url = untrailingslashit( esc_url_raw( $site['url'] ?? '' ) );
        $legacy_schedule = array(
            'enabled'        => ! empty( $site['remote_schedule_enabled'] ),
            'frequency'      => sanitize_text_field( (string) ( $site['remote_schedule_frequency'] ?? 'none' ) ),
            'scope'          => sanitize_text_field( (string) ( $site['remote_schedule_scope'] ?? 'both' ) ),
            'interval'       => absint( $site['remote_schedule_interval'] ?? 0 ),
            'configured_weekday' => sanitize_text_field( (string) ( $site['remote_schedule_weekday'] ?? '' ) ),
            'configured_weekday_label' => sanitize_text_field( (string) ( $site['remote_schedule_weekday_label'] ?? '' ) ),
            'next_run_gmt'   => $site['remote_schedule_next_run_gmt'] ?? null,
            'next_run_local' => $site['remote_schedule_next_run_local'] ?? null,
        );
        $remote_schedules = ! empty( $site['remote_schedules'] )
            ? $this->normalize_scope_schedule_map( $site['remote_schedules'] ?? array() )
            : $this->normalize_scope_schedule_map( array(), $legacy_schedule );
        $primary_schedule = $this->primary_scope_schedule( $remote_schedules, $legacy_schedule );

        return array(
            'url'          => $url,
            'label'        => sanitize_text_field( $site['label'] ?? '' ) ?: ( $url ? wp_parse_url( $url, PHP_URL_HOST ) : '' ),
            'added'        => $site['added'] ?? current_time( 'mysql' ),
            'pull_token'   => trim( (string) ( $site['pull_token'] ?? '' ) ),
            'pull_enabled' => '' !== trim( (string) ( $site['pull_token'] ?? '' ) ),
            'remote_schedules'          => $remote_schedules,
            'remote_schedule_enabled'   => ! empty( $primary_schedule['enabled'] ),
            'remote_schedule_frequency' => sanitize_text_field( (string) ( $primary_schedule['frequency'] ?? 'none' ) ),
            'remote_schedule_scope'     => sanitize_text_field( (string) ( $primary_schedule['scope'] ?? 'both' ) ),
            'remote_schedule_interval'  => absint( $primary_schedule['interval'] ?? 0 ),
            'remote_schedule_weekday'   => sanitize_text_field( (string) ( $primary_schedule['configured_weekday'] ?? '' ) ),
            'remote_schedule_weekday_label' => sanitize_text_field( (string) ( $primary_schedule['configured_weekday_label'] ?? '' ) ),
            'remote_schedule_next_run_gmt'   => $primary_schedule['next_run_gmt'] ?? null,
            'remote_schedule_next_run_local' => $primary_schedule['next_run_local'] ?? null,
            'remote_schedule_synced_at'  => $site['remote_schedule_synced_at'] ?? null,
        );
    }

    private function normalize_status( $status ) {
        $status = is_array( $status ) ? $status : array();
        $legacy_schedule = array(
            'enabled'        => ! empty( $status['schedule_enabled'] ),
            'frequency'      => $status['schedule_frequency'] ?? 'none',
            'scope'          => $status['schedule_scope'] ?? 'both',
            'interval'       => (int) ( $status['schedule_interval'] ?? 0 ),
            'configured_weekday' => $status['schedule_weekday'] ?? null,
            'configured_weekday_label' => $status['schedule_weekday_label'] ?? null,
            'next_run_gmt'   => $status['expected_run_gmt'] ?? null,
            'next_run_local' => $status['expected_run_local'] ?? null,
        );

        $normalized = wp_parse_args(
            $status,
            array(
                'last_checked'          => null,
                'status'                => 'unknown',
                'error'                 => null,
                'last_backup_date'      => null,
                'last_backup_date_gmt'  => null,
                'last_backup_status'    => null,
                'last_successful_date'  => null,
                'plugin_version'        => null,
                'schedule_enabled'      => false,
                'schedule_frequency'    => 'none',
                'schedule_scope'        => 'both',
                'schedule_interval'     => 0,
                'schedule_weekday'      => null,
                'schedule_weekday_label'=> null,
                'expected_run_gmt'      => null,
                'expected_run_local'    => null,
                'awaiting_cycle'        => false,
                'last_cycle_state'      => null,
                'last_cycle_gmt'        => null,
                'next_poll_at'          => null,
                'pull_enabled'          => false,
                'downloaded_backup_ids' => array(),
                'downloaded_artifact_keys' => array(),
                'download_count'        => 0,
                'last_downloaded_backup_id' => null,
                'last_downloaded_at'    => null,
                'last_download_status'  => null,
                'last_download_message' => null,
                'last_download_storage_dir' => null,
                'last_alert_key'        => null,
                'last_alerted_at'       => null,
                'last_alert_message'    => null,
            )
        );

        $normalized['scope_schedules'] = ! empty( $status['scope_schedules'] )
            ? $this->normalize_scope_schedule_map( $status['scope_schedules'] ?? array() )
            : $this->normalize_scope_schedule_map( array(), $legacy_schedule );
        $normalized['downloaded_backup_ids'] = array_values(
            array_unique(
                array_filter(
                    array_map( 'strval', (array) $normalized['downloaded_backup_ids'] )
                )
            )
        );
        $normalized['downloaded_artifact_keys'] = array_values(
            array_unique(
                array_filter(
                    array_map( 'strval', (array) $normalized['downloaded_artifact_keys'] )
                )
            )
        );
        $normalized['download_count'] = count( $normalized['downloaded_backup_ids'] );

        return $normalized;
    }

    private function fetch_remote_status( $site ) {
        $args = array(
            'timeout'   => 20,
            'sslverify' => false,
        );

        if ( ! empty( $site['pull_token'] ) ) {
            $args['headers'] = array(
                'X-RB-Pull-Token' => $site['pull_token'],
            );
        }

        return wp_remote_get( untrailingslashit( $site['url'] ) . '/wp-json/remote-backup/v1/status', $args );
    }

    private function fetch_backup_catalog( $site ) {
        return wp_remote_get(
            untrailingslashit( $site['url'] ) . '/wp-json/remote-backup/v1/backups',
            array(
                'timeout'   => 60,
                'sslverify' => false,
                'headers'   => array(
                    'X-RB-Pull-Token' => $site['pull_token'],
                ),
            )
        );
    }

    private function handle_offline_poll( $site, $entry, $settings, WP_Error $error, $now_ts ) {
        $entry['last_checked'] = current_time( 'mysql' );
        $entry['status']       = 'offline';
        $entry['error']        = $error->get_error_message();

        $expected_ts = $this->gmt_to_timestamp( $entry['expected_run_gmt'] );
        $poll_ts     = $this->scheduled_poll_timestamp( $entry['expected_run_gmt'], $settings );

        if ( $poll_ts && $now_ts < $poll_ts ) {
            $entry['awaiting_cycle'] = false;
            $entry['next_poll_at']   = $this->format_gmt( $poll_ts );

            return $entry;
        }

        if ( $expected_ts ) {
            $deadline = $expected_ts + ( (int) $settings['watch_minutes'] * MINUTE_IN_SECONDS );

            if ( $now_ts < $deadline ) {
                $entry['awaiting_cycle'] = true;
                $entry['next_poll_at']   = $this->format_gmt( $deadline );

                return $entry;
            }

            $entry['awaiting_cycle']   = false;
            $entry['last_cycle_state'] = 'timeout';
            $entry['last_cycle_gmt']   = $entry['expected_run_gmt'];

            $next_expected = $this->resolve_future_schedule_gmt(
                $entry['expected_run_gmt'],
                (int) $entry['schedule_interval'],
                $now_ts
            );

            $entry['expected_run_gmt']   = $next_expected;
            $entry['expected_run_local'] = $this->local_from_gmt( $next_expected );
            $entry['next_poll_at']       = $this->format_gmt( $this->next_poll_timestamp( $next_expected, $settings, $now_ts, (int) $entry['schedule_interval'] ) );
            $entry                      = $this->maybe_alert_for_state( $site, $entry, $settings );

            return $entry;
        }

        $entry['awaiting_cycle'] = false;
        $entry['next_poll_at']   = $this->format_gmt( $now_ts + DAY_IN_SECONDS );

        return $entry;
    }

    private function sync_schedule_state( $entry, $schedule, $last_backup, $settings, $now_ts ) {
        $schedule_enabled = ! empty( $schedule['enabled'] ) && ! empty( $schedule['next_run_gmt'] );
        $next_run_gmt     = $schedule['next_run_gmt'] ?? null;

        $entry['schedule_enabled']   = $schedule_enabled;
        $entry['schedule_frequency'] = $schedule['frequency'] ?? 'none';
        $entry['schedule_scope']     = $schedule['scope'] ?? 'both';
        $entry['schedule_interval']  = (int) ( $schedule['interval'] ?? 0 );
        $entry['schedule_weekday']   = $schedule['configured_weekday'] ?? null;
        $entry['schedule_weekday_label'] = $schedule['configured_weekday_label'] ?? null;

        if ( ! $schedule_enabled ) {
            $entry['awaiting_cycle']     = false;
            $entry['expected_run_gmt']   = null;
            $entry['expected_run_local'] = null;
            $entry['next_poll_at']       = $this->format_gmt( $now_ts + DAY_IN_SECONDS );

            return $entry;
        }

        $schedule_interval = (int) $entry['schedule_interval'];
        $watch_seconds     = (int) $settings['watch_minutes'] * MINUTE_IN_SECONDS;

        $expected_ts = $this->gmt_to_timestamp( $entry['expected_run_gmt'] );
        $next_run_ts = $this->gmt_to_timestamp( $next_run_gmt );

        if ( empty( $entry['expected_run_gmt'] ) || ( $expected_ts > $now_ts && $next_run_ts && $next_run_ts !== $expected_ts ) ) {
            $resolved_expected = $this->resolve_future_schedule_gmt( $next_run_gmt, $schedule_interval, $now_ts );
            if ( $resolved_expected ) {
                $entry['expected_run_gmt']   = $resolved_expected;
                $entry['expected_run_local'] = $this->local_from_gmt( $resolved_expected );
            }
        }

        $expected_ts = $this->gmt_to_timestamp( $entry['expected_run_gmt'] );
        if ( $expected_ts && $this->backup_matches_cycle( $last_backup, $expected_ts, 0 ) ) {
            $entry['awaiting_cycle']   = false;
            $entry['last_cycle_state'] = ( 'failed' === ( $last_backup['status'] ?? 'success' ) ) ? 'failed' : 'success';
            $entry['last_cycle_gmt']   = $entry['expected_run_gmt'];

            $next_expected = $this->resolve_future_schedule_gmt( $next_run_gmt, $schedule_interval, $now_ts );
            $entry['expected_run_gmt']   = $next_expected;
            $entry['expected_run_local'] = $this->local_from_gmt( $next_expected );
            $entry['next_poll_at']       = $this->format_gmt( $this->next_poll_timestamp( $next_expected, $settings, $now_ts, $schedule_interval ) );

            return $entry;
        }

        if ( ! $expected_ts ) {
            $entry['awaiting_cycle'] = false;
            $entry['next_poll_at']   = $this->format_gmt( $this->next_poll_timestamp( $next_run_gmt, $settings, $now_ts, $schedule_interval ) );

            return $entry;
        }

        $scheduled_poll_ts = $this->scheduled_poll_timestamp( $entry['expected_run_gmt'], $settings );
        if ( $scheduled_poll_ts && $now_ts < $scheduled_poll_ts ) {
            $entry['awaiting_cycle'] = false;
            $entry['next_poll_at']   = $this->format_gmt( $scheduled_poll_ts );

            return $entry;
        }

        $deadline = $expected_ts + $watch_seconds;
        if ( $now_ts < $deadline ) {
            $entry['awaiting_cycle'] = true;
            $entry['next_poll_at']   = $this->format_gmt( $deadline );

            return $entry;
        }

        $entry['awaiting_cycle']   = false;
        $entry['last_cycle_state'] = 'timeout';
        $entry['last_cycle_gmt']   = $entry['expected_run_gmt'];

        $next_expected = $this->resolve_future_schedule_gmt( $next_run_gmt, $schedule_interval, $now_ts );
        $entry['expected_run_gmt']   = $next_expected;
        $entry['expected_run_local'] = $this->local_from_gmt( $next_expected );
        $entry['next_poll_at']       = $this->format_gmt( $this->next_poll_timestamp( $next_expected, $settings, $now_ts, $schedule_interval ) );

        return $entry;
    }

    private function sync_site_schedule_snapshot( $url, $scope_schedules, $schedule ) {
        $sites   = $this->get_sites();
        $updated = false;

        foreach ( $sites as &$site ) {
            if ( ( $site['url'] ?? '' ) !== $url ) {
                continue;
            }

            $site['remote_schedules']          = $scope_schedules;
            $site['remote_schedule_enabled']    = ! empty( $schedule['enabled'] ) && ! empty( $schedule['next_run_gmt'] );
            $site['remote_schedule_frequency']  = sanitize_text_field( (string) ( $schedule['frequency'] ?? 'none' ) );
            $site['remote_schedule_scope']      = sanitize_text_field( (string) ( $schedule['scope'] ?? 'both' ) );
            $site['remote_schedule_interval']   = absint( $schedule['interval'] ?? 0 );
            $site['remote_schedule_weekday']    = sanitize_text_field( (string) ( $schedule['configured_weekday'] ?? '' ) );
            $site['remote_schedule_weekday_label'] = sanitize_text_field( (string) ( $schedule['configured_weekday_label'] ?? '' ) );
            $site['remote_schedule_next_run_gmt']   = $schedule['next_run_gmt'] ?? null;
            $site['remote_schedule_next_run_local'] = $schedule['next_run_local'] ?? $this->local_from_gmt( $schedule['next_run_gmt'] ?? null );
            $site['remote_schedule_synced_at']  = current_time( 'mysql' );
            $updated                            = true;
            break;
        }
        unset( $site );

        if ( $updated ) {
            $this->save_json( $this->sites_path, $sites );
        }
    }

    private function normalize_scope_schedule_map( $schedules = array(), $legacy_schedule = array() ) {
        $normalized = array(
            'database' => $this->normalize_scope_schedule_payload( $schedules['database'] ?? array(), 'database' ),
            'files'    => $this->normalize_scope_schedule_payload( $schedules['files'] ?? array(), 'files' ),
        );

        if ( ! empty( $legacy_schedule ) ) {
            $legacy = $this->normalize_scope_schedule_payload( $legacy_schedule, $legacy_schedule['scope'] ?? 'both' );
            if ( 'database' === $legacy['scope'] ) {
                $normalized['database'] = $legacy;
            } elseif ( 'files' === $legacy['scope'] ) {
                $normalized['files'] = $legacy;
            } elseif ( ! empty( $legacy['enabled'] ) ) {
                $normalized['database'] = array_merge( $normalized['database'], $legacy, array( 'scope' => 'database' ) );
                $normalized['files']    = array_merge( $normalized['files'], $legacy, array( 'scope' => 'files' ) );
            }
        }

        return $normalized;
    }

    private function normalize_scope_schedule_payload( $schedule, $scope ) {
        $scope = 'database' === sanitize_text_field( (string) $scope ) ? 'database' : ( 'files' === sanitize_text_field( (string) $scope ) ? 'files' : 'both' );
        $schedule = is_array( $schedule ) ? $schedule : array();

        return array(
            'enabled'                  => ! empty( $schedule['enabled'] ),
            'frequency'                => sanitize_text_field( (string) ( $schedule['frequency'] ?? 'none' ) ),
            'scope'                    => sanitize_text_field( (string) ( $schedule['scope'] ?? $scope ) ),
            'interval'                 => (int) ( $schedule['interval'] ?? 0 ),
            'configured_time'          => ! empty( $schedule['configured_time'] ) ? sanitize_text_field( (string) $schedule['configured_time'] ) : null,
            'configured_weekday'       => ! empty( $schedule['configured_weekday'] ) ? sanitize_text_field( (string) $schedule['configured_weekday'] ) : null,
            'configured_weekday_label' => ! empty( $schedule['configured_weekday_label'] ) ? sanitize_text_field( (string) $schedule['configured_weekday_label'] ) : null,
            'next_run_gmt'             => ! empty( $schedule['next_run_gmt'] ) ? sanitize_text_field( (string) $schedule['next_run_gmt'] ) : null,
            'next_run_local'           => ! empty( $schedule['next_run_local'] ) ? sanitize_text_field( (string) $schedule['next_run_local'] ) : null,
        );
    }

    private function primary_scope_schedule( $scope_schedules, $legacy_schedule = array() ) {
        $enabled = array_values(
            array_filter(
                array(
                    $scope_schedules['database'] ?? null,
                    $scope_schedules['files'] ?? null,
                ),
                function ( $schedule ) {
                    return is_array( $schedule ) && ! empty( $schedule['enabled'] ) && ! empty( $schedule['next_run_gmt'] );
                }
            )
        );

        if ( ! empty( $enabled ) ) {
            usort(
                $enabled,
                function ( $a, $b ) {
                    return strcmp( (string) $a['next_run_gmt'], (string) $b['next_run_gmt'] );
                }
            );

            return $enabled[0];
        }

        return $this->normalize_scope_schedule_payload( $legacy_schedule, $legacy_schedule['scope'] ?? 'both' );
    }

    private function derive_status( $entry, $now_ts ) {
        if ( 'failed' === ( $entry['last_backup_status'] ?? null ) ) {
            return array( 'failed', 'Last backup reported a failure.' );
        }

        if ( ! empty( $entry['awaiting_cycle'] ) ) {
            return array( 'warning', 'Waiting for a scheduled backup to complete.' );
        }

        if ( 'timeout' === ( $entry['last_cycle_state'] ?? null ) ) {
            return array( 'warning', 'Scheduled backup timed out without completing.' );
        }

        if ( 'failed' === ( $entry['last_download_status'] ?? null ) ) {
            return array( 'warning', 'Last artifact download failed.' );
        }

        if ( empty( $entry['last_backup_date'] ) ) {
            return array( 'warning', 'No backup has been recorded yet.' );
        }

        $last_backup_gmt = $entry['last_backup_date_gmt'] ?? null;
        if ( empty( $last_backup_gmt ) && ! empty( $entry['last_backup_date'] ) ) {
            $last_backup_gmt = get_gmt_from_date( $entry['last_backup_date'], 'Y-m-d H:i:s' );
        }

        $age = $now_ts - $this->gmt_to_timestamp( $last_backup_gmt );
        if ( $age > 48 * HOUR_IN_SECONDS ) {
            return array( 'warning', 'Last backup is older than 48 hours.' );
        }

        return array( 'ok', '' );
    }

    private function maybe_alert_for_state( $site, $entry, $settings ) {
        $label = $site['label'] ?: $site['url'];

        if ( 'failed' === ( $entry['last_cycle_state'] ?? null ) ) {
            return $this->maybe_send_alert(
                $entry,
                'failed:' . ( $entry['last_cycle_gmt'] ?? '' ),
                sprintf(
                    'Remote Backup monitor: %1$s reported a failed scheduled backup for %2$s.',
                    $label,
                    $entry['last_backup_date'] ?: ( $entry['last_cycle_gmt'] ?? 'the current run' )
                ),
                $settings
            );
        }

        if ( 'timeout' === ( $entry['last_cycle_state'] ?? null ) ) {
            return $this->maybe_send_alert(
                $entry,
                'timeout:' . ( $entry['last_cycle_gmt'] ?? '' ),
                sprintf(
                    'Remote Backup monitor: %1$s did not report a successful backup within %2$d minutes of the scheduled run %3$s.',
                    $label,
                    (int) $settings['watch_minutes'],
                    $entry['last_cycle_gmt'] ?: 'in progress'
                ),
                $settings
            );
        }

        return $entry;
    }

    private function maybe_send_alert( $entry, $key, $message, $settings ) {
        if ( '' === $key || $key === ( $entry['last_alert_key'] ?? '' ) ) {
            return $entry;
        }

        $recipients = array_values(
            array_filter(
                array_map(
                    'trim',
                    preg_split( '/[\s,;]+/', (string) $settings['notification_email'] )
                ),
                'is_email'
            )
        );

        if ( ! empty( $recipients ) ) {
            wp_mail( $recipients, '[Remote Backup] Monitor alert', $message );
        }

        $this->logger->log( 'Monitor alert: ' . $message, 'warning' );
        $entry['last_alert_key']     = $key;
        $entry['last_alerted_at']    = current_time( 'mysql' );
        $entry['last_alert_message'] = $message;

        return $entry;
    }

    private function clear_alert( $entry ) {
        $entry['last_alert_key']     = null;
        $entry['last_alerted_at']    = null;
        $entry['last_alert_message'] = null;

        return $entry;
    }

    private function download_pending_backups( $site, $entry, $options = array() ) {
        $options = $this->normalize_poll_options( $options );
        $entry   = $this->hydrate_download_tracking( $site['url'], $entry );

        $this->set_site_progress(
            $site['url'],
            array(
                'running'        => true,
                'status'         => 'running',
                'action'         => $options['action'],
                'phase'          => 'catalog',
                'message'        => 'Reading the remote backup catalog…',
                'artifact_types' => $options['artifact_types'],
                'artifact_label' => $this->artifact_types_label( $options['artifact_types'] ),
            )
        );

        $response = $this->fetch_backup_catalog( $site );
        if ( is_wp_error( $response ) ) {
            $entry['last_download_status']  = 'failed';
            $entry['last_download_message'] = $response->get_error_message();
            $entry['download_count']        = count( (array) $entry['downloaded_backup_ids'] );
            $this->set_site_progress(
                $site['url'],
                array(
                    'running'     => false,
                    'status'      => 'failed',
                    'phase'       => 'failed',
                    'message'     => $response->get_error_message(),
                    'finished_at' => current_time( 'mysql' ),
                )
            );

            return $entry;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code || ! is_array( $body ) ) {
            $entry['last_download_status']  = 'failed';
            $entry['last_download_message'] = 'Catalog request returned HTTP ' . $code . '.';
            $entry['download_count']        = count( (array) $entry['downloaded_backup_ids'] );
            $this->set_site_progress(
                $site['url'],
                array(
                    'running'     => false,
                    'status'      => 'failed',
                    'phase'       => 'failed',
                    'message'     => 'Catalog request returned HTTP ' . $code . '.',
                    'finished_at' => current_time( 'mysql' ),
                )
            );

            return $entry;
        }

        $downloaded_artifacts = array_fill_keys( array_map( 'strval', (array) $entry['downloaded_artifact_keys'] ), true );
        $new_count            = 0;
        $backups              = $this->select_catalog_backups( (array) ( $body['backups'] ?? array() ), $downloaded_artifacts, $options );

        if ( ! empty( $options['latest_only'] ) && empty( $backups ) ) {
            $entry['last_download_status']  = 'idle';
            $entry['last_download_message'] = 'Latest ' . $this->artifact_types_label( $options['artifact_types'] ) . ' backup is already pulled or not available yet.';
            $entry['download_count']        = count( (array) $entry['downloaded_backup_ids'] );
            $this->set_site_progress(
                $site['url'],
                array(
                    'running'     => false,
                    'status'      => 'success',
                    'phase'       => 'complete',
                    'message'     => $entry['last_download_message'],
                    'finished_at' => current_time( 'mysql' ),
                )
            );

            return $entry;
        }

        foreach ( $backups as $backup ) {
            $backup_id = (string) ( $backup['id'] ?? '' );
            if ( '' === $backup_id ) {
                continue;
            }

            $missing_artifact_types = $this->missing_backup_artifact_types( $backup, $downloaded_artifacts, $options['artifact_types'] );
            if ( empty( $missing_artifact_types ) ) {
                continue;
            }

            $backup_options                   = $options;
            $backup_options['artifact_types'] = $missing_artifact_types;

            $result = $this->download_backup( $site, $backup, $backup_options );
            if ( is_wp_error( $result ) ) {
                $entry['last_download_status']  = 'failed';
                $entry['last_download_message'] = $result->get_error_message();
                $entry['download_count']        = count( (array) $entry['downloaded_backup_ids'] );
                $this->set_site_progress(
                    $site['url'],
                    array(
                        'running'     => false,
                        'status'      => 'failed',
                        'phase'       => 'failed',
                        'message'     => $result->get_error_message(),
                        'finished_at' => current_time( 'mysql' ),
                    )
                );

                return $entry;
            }

            $entry['downloaded_backup_ids'][] = $backup_id;
            $entry['last_downloaded_backup_id'] = $backup_id;
            $entry['last_downloaded_at']      = current_time( 'mysql' );
            $entry['last_download_status']    = 'success';
            $entry['last_download_storage_dir'] = $result['storage_dir'] ?? null;
            $entry['last_download_message']   = sprintf(
                'Pulled backup %1$s to %2$s (%3$d artifact%4$s).',
                $backup_id,
                $result['storage_dir'] ?? 'storage',
                (int) $result['count'],
                1 === (int) $result['count'] ? '' : 's'
            );

            foreach ( (array) ( $result['artifacts'] ?? array() ) as $stored_artifact ) {
                $artifact_type = sanitize_text_field( (string) ( $stored_artifact['type'] ?? '' ) );
                if ( '' === $artifact_type ) {
                    continue;
                }
                $downloaded_artifacts[ $this->artifact_download_key( $backup_id, $artifact_type ) ] = true;
            }

            $entry['downloaded_backup_ids'] = array_values(
                array_unique(
                    array_filter(
                        array_map( 'strval', (array) $entry['downloaded_backup_ids'] )
                    )
                )
            );
            $entry['downloaded_artifact_keys'] = array_keys( $downloaded_artifacts );
            $new_count++;
        }

        $entry['downloaded_backup_ids']    = array_slice( array_values( array_unique( $entry['downloaded_backup_ids'] ) ), -200 );
        $entry['downloaded_artifact_keys'] = array_slice( array_values( array_unique( (array) $entry['downloaded_artifact_keys'] ) ), -600 );
        $entry['download_count']           = count( $entry['downloaded_backup_ids'] );

        if ( 0 === $new_count && empty( $entry['last_downloaded_at'] ) ) {
            $entry['last_download_status']  = 'idle';
            $entry['last_download_message'] = 'No successful ' . $this->artifact_types_label( $options['artifact_types'] ) . ' backups available to pull yet.';
        }

        $this->set_site_progress(
            $site['url'],
            array(
                'running'     => false,
                'status'      => 'success',
                'phase'       => 'complete',
                'message'     => $entry['last_download_message'] ?? 'Pull finished.',
                'finished_at' => current_time( 'mysql' ),
            )
        );

        return $entry;
    }

    private function download_backup( $site, $backup, $options = array() ) {
        $options   = $this->normalize_poll_options( $options );
        $backup_id = sanitize_text_field( (string) ( $backup['id'] ?? '' ) );
        $artifacts = is_array( $backup['artifacts'] ?? null ) ? $backup['artifacts'] : array();

        if ( ! empty( $options['artifact_types'] ) ) {
            $artifacts = array_values(
                array_filter(
                    $artifacts,
                    function ( $artifact ) use ( $options ) {
                        return in_array( sanitize_text_field( (string) ( $artifact['type'] ?? '' ) ), $options['artifact_types'], true );
                    }
                )
            );
        }

        if ( '' === $backup_id || empty( $artifacts ) ) {
            return new WP_Error( 'sprb_monitor_download', 'Backup catalog entry is missing artifact data.' );
        }

        $dir              = $this->storage->remote_pull_backup_dir( $site['url'], $backup_id );
        $manifest_path    = $this->storage->remote_pull_backup_manifest_path( $site['url'], $backup_id );
        $stored_artifacts = array();

        foreach ( $artifacts as $artifact ) {
            $type         = sanitize_text_field( (string) ( $artifact['type'] ?? '' ) );
            $download_url = esc_url_raw( (string) ( $artifact['download_url'] ?? '' ) );
            $filename     = basename( (string) ( $artifact['filename'] ?? '' ) );
            $timeout      = $this->download_timeout_seconds( $artifact );

            if ( '' === $type || '' === $download_url || '' === $filename ) {
                return new WP_Error( 'sprb_monitor_download', 'Backup catalog entry is missing a download URL.' );
            }

            $target   = $this->storage->remote_pull_artifact_path( $site['url'], $backup_id, $type, $filename );
            $tempfile = $target . '.part';

            if ( file_exists( $target ) && is_readable( $target ) && (int) filesize( $target ) === (int) ( $artifact['size'] ?? 0 ) ) {
                $stored_artifacts[] = array(
                    'type'          => $type,
                    'filename'      => basename( $target ),
                    'size'          => (int) filesize( $target ),
                    'relative_path' => $this->storage->relative_storage_path( $target ),
                );
                continue;
            }

            if ( file_exists( $tempfile ) ) {
                wp_delete_file( $tempfile );
            }

            $this->set_site_progress(
                $site['url'],
                array(
                    'running'          => true,
                    'status'           => 'running',
                    'action'           => $options['action'],
                    'phase'            => 'downloading',
                    'message'          => 'Pulling ' . $filename . '…',
                    'artifact_types'   => $options['artifact_types'],
                    'artifact_label'   => $this->artifact_types_label( $options['artifact_types'] ),
                    'backup_id'        => $backup_id,
                    'filename'         => $filename,
                    'target_path'      => $tempfile,
                    'downloaded_bytes' => file_exists( $tempfile ) ? (int) filesize( $tempfile ) : 0,
                    'total_bytes'      => (int) ( $artifact['size'] ?? 0 ),
                )
            );
            $response = wp_remote_get(
                $download_url,
                array(
                    'timeout'   => $timeout,
                    'sslverify' => false,
                    'stream'    => true,
                    'filename'  => $tempfile,
                    'headers'   => array(
                        'X-RB-Pull-Token' => $site['pull_token'],
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                $detail = $this->download_error_context( $tempfile );
                if ( file_exists( $tempfile ) ) {
                    wp_delete_file( $tempfile );
                }
                return new WP_Error( 'sprb_monitor_download', sprintf( 'Download failed for %1$s: %2$s%3$s', $filename, $response->get_error_message(), $detail ) );
            }

            if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! file_exists( $tempfile ) ) {
                $detail = $this->download_error_context( $tempfile );
                if ( file_exists( $tempfile ) ) {
                    wp_delete_file( $tempfile );
                }
                return new WP_Error( 'sprb_monitor_download', sprintf( 'Download failed for %1$s: HTTP %2$d.%3$s', $filename, wp_remote_retrieve_response_code( $response ), $detail ) );
            }

            if ( file_exists( $target ) && ! wp_delete_file( $target ) ) {
                wp_delete_file( $tempfile );
                return new WP_Error( 'sprb_monitor_download', sprintf( 'Download failed for %1$s: existing target file is not writable.', $filename ) );
            }

            if ( ! @rename( $tempfile, $target ) ) {
                if ( ! @copy( $tempfile, $target ) ) {
                    wp_delete_file( $tempfile );
                    return new WP_Error( 'sprb_monitor_download', sprintf( 'Download failed for %1$s: could not move the streamed file into storage.', $filename ) );
                }
                wp_delete_file( $tempfile );
            }

            $stored_artifacts[] = array(
                'type'          => $type,
                'filename'      => $filename,
                'size'          => (int) filesize( $target ),
                'relative_path' => $this->storage->relative_storage_path( $target ),
            );
        }

        file_put_contents(
            $manifest_path,
            wp_json_encode(
                array(
                    'site_url'      => $site['url'],
                    'site_label'    => $site['label'],
                    'downloaded_at' => current_time( 'mysql' ),
                    'backup'        => $backup,
                'stored_artifacts' => $stored_artifacts,
                ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        $this->set_site_progress(
            $site['url'],
            array(
                'running'          => true,
                'status'           => 'running',
                'action'           => $options['action'],
                'phase'            => 'finalizing',
                'message'          => 'Finalizing pulled backup…',
                'backup_id'        => $backup_id,
                'filename'         => null,
                'target_path'      => null,
                'downloaded_bytes' => 0,
                'total_bytes'      => 0,
            )
        );

        return array(
            'count'       => count( $artifacts ),
            'storage_dir' => $this->storage->relative_storage_path( $dir ),
            'artifacts'   => $stored_artifacts,
        );
    }

    private function download_timeout_seconds( $artifact ) {
        $size = (int) ( $artifact['size'] ?? 0 );

        if ( $size <= 0 ) {
            return 300;
        }

        // Assume a conservative 5 MB/s effective transfer rate, then add buffer.
        $seconds = (int) ceil( $size / ( 5 * MB_IN_BYTES ) ) + 120;

        return max( 300, min( 7200, $seconds ) );
    }

    private function normalize_poll_options( $options ) {
        $options = wp_parse_args(
            is_array( $options ) ? $options : array(),
            array(
                'action'             => 'poll',
                'artifact_types'     => array(),
                'latest_only'        => false,
                'download_artifacts' => false,
            )
        );

        $options['action']             = sanitize_key( (string) $options['action'] );
        $options['latest_only']        = ! empty( $options['latest_only'] );
        $options['download_artifacts'] = ! empty( $options['download_artifacts'] );
        $options['artifact_types'] = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $artifact_type ) {
                            $artifact_type = sanitize_text_field( (string) $artifact_type );
                            return in_array( $artifact_type, array( 'database', 'files', 'plugins' ), true ) ? $artifact_type : '';
                        },
                        (array) $options['artifact_types']
                    )
                )
            )
        );

        return $options;
    }

    private function select_catalog_backups( $backups, $downloaded_artifacts, $options ) {
        $options = $this->normalize_poll_options( $options );
        $items   = array_values( is_array( $backups ) ? $backups : array() );

        usort(
            $items,
            static function ( $a, $b ) {
                return strcmp(
                    (string) ( $b['date_gmt'] ?? $b['date'] ?? '' ),
                    (string) ( $a['date_gmt'] ?? $a['date'] ?? '' )
                );
            }
        );

        if ( ! empty( $options['latest_only'] ) ) {
            foreach ( $items as $backup ) {
                if ( ! $this->backup_matches_artifact_types( $backup, $options['artifact_types'] ) ) {
                    continue;
                }

                if ( empty( $this->missing_backup_artifact_types( $backup, $downloaded_artifacts, $options['artifact_types'] ) ) ) {
                    return array();
                }

                return array( $backup );
            }

            return array();
        }

        return array_values(
            array_filter(
                $items,
                function ( $backup ) use ( $downloaded_artifacts, $options ) {
                    return ! empty( $this->missing_backup_artifact_types( $backup, $downloaded_artifacts, $options['artifact_types'] ) );
                }
            )
        );
    }

    private function hydrate_download_tracking( $url, $entry ) {
        $entry['downloaded_backup_ids'] = array_values(
            array_unique(
                array_filter(
                    array_map( 'strval', (array) ( $entry['downloaded_backup_ids'] ?? array() ) )
                )
            )
        );
        $entry['downloaded_artifact_keys'] = array_values(
            array_unique(
                array_filter(
                    array_map( 'strval', (array) ( $entry['downloaded_artifact_keys'] ?? array() ) )
                )
            )
        );

        foreach ( $this->storage->get_remote_pulled_backups( $url, 200 ) as $pulled_backup ) {
            $backup_id = sanitize_text_field( (string) ( $pulled_backup['backup_id'] ?? '' ) );
            if ( '' === $backup_id ) {
                continue;
            }

            $entry['downloaded_backup_ids'][] = $backup_id;

            foreach ( (array) ( $pulled_backup['artifacts'] ?? array() ) as $artifact ) {
                $artifact_type = sanitize_text_field( (string) ( $artifact['type'] ?? '' ) );
                if ( '' === $artifact_type ) {
                    continue;
                }
                $entry['downloaded_artifact_keys'][] = $this->artifact_download_key( $backup_id, $artifact_type );
            }
        }

        $entry['downloaded_backup_ids'] = array_slice(
            array_values( array_unique( $entry['downloaded_backup_ids'] ) ),
            -200
        );
        $entry['downloaded_artifact_keys'] = array_slice(
            array_values( array_unique( $entry['downloaded_artifact_keys'] ) ),
            -600
        );
        $entry['download_count'] = count( $entry['downloaded_backup_ids'] );

        return $entry;
    }

    private function missing_backup_artifact_types( $backup, $downloaded_artifacts, $requested_artifact_types = array() ) {
        $requested_artifact_types = array_values( array_filter( (array) $requested_artifact_types ) );
        $missing                  = array();
        $backup_id                = sanitize_text_field( (string) ( $backup['id'] ?? '' ) );

        if ( '' === $backup_id ) {
            return $missing;
        }

        foreach ( (array) ( $backup['artifacts'] ?? array() ) as $artifact ) {
            $artifact_type = sanitize_text_field( (string) ( $artifact['type'] ?? '' ) );
            if ( '' === $artifact_type ) {
                continue;
            }
            if ( ! empty( $requested_artifact_types ) && ! in_array( $artifact_type, $requested_artifact_types, true ) ) {
                continue;
            }
            if ( isset( $downloaded_artifacts[ $this->artifact_download_key( $backup_id, $artifact_type ) ] ) ) {
                continue;
            }

            $missing[] = $artifact_type;
        }

        return array_values( array_unique( $missing ) );
    }

    private function backup_matches_artifact_types( $backup, $artifact_types ) {
        $artifact_types = array_values( array_filter( (array) $artifact_types ) );
        if ( empty( $artifact_types ) ) {
            return true;
        }

        foreach ( (array) ( $backup['artifacts'] ?? array() ) as $artifact ) {
            $type = sanitize_text_field( (string) ( $artifact['type'] ?? '' ) );
            if ( in_array( $type, $artifact_types, true ) ) {
                return true;
            }
        }

        return false;
    }

    private function artifact_types_label( $artifact_types ) {
        $artifact_types = array_values( array_filter( (array) $artifact_types ) );
        if ( empty( $artifact_types ) ) {
            return 'backup';
        }

        $labels = array(
            'database' => 'database',
            'files'    => 'files',
            'plugins'  => 'plugins',
        );

        $mapped = array_values(
            array_filter(
                array_map(
                    static function ( $artifact_type ) use ( $labels ) {
                        return $labels[ $artifact_type ] ?? null;
                    },
                    $artifact_types
                )
            )
        );

        if ( empty( $mapped ) ) {
            return 'backup';
        }

        return 1 === count( $mapped ) ? $mapped[0] : implode( ' + ', $mapped );
    }

    private function artifact_download_key( $backup_id, $artifact_type ) {
        return sanitize_text_field( (string) $backup_id ) . ':' . sanitize_key( (string) $artifact_type );
    }

    private function progress_key( $url ) {
        return 'sprb_monitor_progress_' . md5( strtolower( untrailingslashit( (string) $url ) ) );
    }

    private function set_site_progress( $url, $changes ) {
        $url   = untrailingslashit( esc_url_raw( (string) $url ) );
        $state = get_transient( $this->progress_key( $url ) );
        if ( ! is_array( $state ) ) {
            $state = array(
                'url'              => $url,
                'running'          => false,
                'status'           => 'idle',
                'action'           => 'poll',
                'phase'            => 'idle',
                'message'          => '',
                'artifact_types'   => array(),
                'artifact_label'   => 'backup',
                'backup_id'        => null,
                'filename'         => null,
                'target_path'      => null,
                'downloaded_bytes' => 0,
                'total_bytes'      => 0,
                'started_at'       => null,
                'finished_at'      => null,
            );
        }

        $state              = array_merge( $state, is_array( $changes ) ? $changes : array() );
        $state['updated_at'] = current_time( 'mysql' );
        set_transient( $this->progress_key( $url ), $state, self::PROGRESS_TTL );

        return $state;
    }

    private function acquire_site_lock( $url ) {
        $path   = $this->site_lock_path( $url );
        $handle = fopen( $path, 'c+' );

        if ( ! $handle ) {
            return new WP_Error( 'sprb_monitor_lock', 'Failed to open the monitor lock file.' );
        }

        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            return new WP_Error( 'sprb_monitor_locked', 'Another poll is already running for this site.' );
        }

        ftruncate( $handle, 0 );
        fwrite(
            $handle,
            wp_json_encode(
                array(
                    'url'         => $url,
                    'pid'         => function_exists( 'getmypid' ) ? getmypid() : null,
                    'locked_at'   => current_time( 'mysql' ),
                    'locked_gmt'  => current_time( 'mysql', true ),
                ),
                JSON_UNESCAPED_SLASHES
            )
        );
        fflush( $handle );

        return $handle;
    }

    private function release_site_lock( $handle ) {
        if ( ! is_resource( $handle ) ) {
            return;
        }

        flock( $handle, LOCK_UN );
        fclose( $handle );
    }

    private function site_lock_path( $url ) {
        return SPRB_DATA_DIR . 'monitor-lock-' . md5( (string) $url ) . '.lock';
    }

    private function download_error_context( $filepath ) {
        if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
            return '';
        }

        $sample = file_get_contents( $filepath, false, null, 0, 1024 );
        if ( false === $sample ) {
            return '';
        }

        $plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $sample ) ) );
        if ( '' === $plain ) {
            return '';
        }

        if ( ! preg_match( '/fatal error|warning|notice|exception|forbidden|denied|not found|memory size|stack trace|html/i', $plain ) ) {
            return '';
        }

        return ' Remote response: ' . substr( $plain, 0, 220 );
    }

    private function backup_matches_cycle( $last_backup, $expected_ts, $lead_seconds ) {
        $backup_gmt = $last_backup['date_gmt'] ?? null;
        if ( empty( $backup_gmt ) && ! empty( $last_backup['date'] ) ) {
            $backup_gmt = get_gmt_from_date( $last_backup['date'], 'Y-m-d H:i:s' );
        }

        $backup_ts = $this->gmt_to_timestamp( $backup_gmt );
        if ( ! $backup_ts ) {
            return false;
        }

        return $backup_ts >= ( $expected_ts - max( $lead_seconds, 5 * MINUTE_IN_SECONDS ) );
    }

    private function next_poll_timestamp( $next_run_gmt, $settings, $now_ts, $interval = 0 ) {
        $delay_seconds = (int) ( $settings['poll_delay_minutes'] ?? $settings['retry_minutes'] ?? 15 ) * MINUTE_IN_SECONDS;
        $next_run_ts = $this->gmt_to_timestamp( $next_run_gmt );
        if ( ! $next_run_ts ) {
            return $now_ts + DAY_IN_SECONDS;
        }

        $candidate = $next_run_ts + $delay_seconds;
        if ( $candidate <= $now_ts ) {
            $future_run = $this->resolve_future_schedule_gmt( $next_run_gmt, (int) $interval, $now_ts - $delay_seconds );
            if ( $future_run ) {
                return $this->gmt_to_timestamp( $future_run ) + $delay_seconds;
            }

            return $now_ts + DAY_IN_SECONDS;
        }

        return $candidate;
    }

    private function scheduled_poll_timestamp( $expected_run_gmt, $settings ) {
        $expected_ts = $this->gmt_to_timestamp( $expected_run_gmt );
        if ( ! $expected_ts ) {
            return 0;
        }

        $delay_seconds = (int) ( $settings['poll_delay_minutes'] ?? $settings['retry_minutes'] ?? 15 ) * MINUTE_IN_SECONDS;

        return $expected_ts + $delay_seconds;
    }

    private function resolve_future_schedule_gmt( $scheduled_gmt, $interval, $after_ts ) {
        $scheduled_ts = $this->gmt_to_timestamp( $scheduled_gmt );
        if ( ! $scheduled_ts ) {
            return null;
        }

        $interval = (int) $interval;
        if ( $scheduled_ts <= $after_ts && $interval > 0 ) {
            $missed_cycles = (int) floor( ( $after_ts - $scheduled_ts ) / $interval ) + 1;
            $scheduled_ts += $missed_cycles * $interval;
        }

        if ( $scheduled_ts <= $after_ts ) {
            return null;
        }

        return $this->format_gmt( $scheduled_ts );
    }

    private function append_history( $url, $entry ) {
        $history = array();
        if ( file_exists( $this->history_path ) ) {
            $raw = json_decode( file_get_contents( $this->history_path ), true );
            if ( is_array( $raw ) ) {
                $history = $raw;
            }
        }

        $history[] = array_merge( array( 'url' => $url ), $this->history_snapshot( $entry ) );
        $this->trim_history( $history );
        $this->save_json( $this->history_path, $history );
    }

    private function history_snapshot( $entry ) {
        return array(
            'last_checked'         => $entry['last_checked'] ?? null,
            'status'               => $entry['status'] ?? 'unknown',
            'error'                => $entry['error'] ?? null,
            'last_backup_date'     => $entry['last_backup_date'] ?? null,
            'last_backup_date_gmt' => $entry['last_backup_date_gmt'] ?? null,
            'last_backup_status'   => $entry['last_backup_status'] ?? null,
            'expected_run_gmt'     => $entry['expected_run_gmt'] ?? null,
            'next_poll_at'         => $entry['next_poll_at'] ?? null,
            'last_download_status' => $entry['last_download_status'] ?? null,
            'last_downloaded_at'   => $entry['last_downloaded_at'] ?? null,
        );
    }

    private function trim_history( &$history ) {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::HISTORY_MAX_DAYS * DAY_IN_SECONDS );
        $history = array_values(
            array_filter(
                $history,
                function ( $entry ) use ( $cutoff ) {
                    return ( $entry['last_checked'] ?? '' ) >= $cutoff;
                }
            )
        );
    }

    private function gmt_to_timestamp( $datetime ) {
        if ( empty( $datetime ) ) {
            return 0;
        }

        return strtotime( (string) $datetime . ' UTC' );
    }

    private function format_gmt( $timestamp ) {
        if ( empty( $timestamp ) ) {
            return null;
        }

        return gmdate( 'Y-m-d H:i:s', (int) $timestamp );
    }

    private function local_from_gmt( $datetime ) {
        if ( empty( $datetime ) ) {
            return null;
        }

        return get_date_from_gmt( $datetime, 'Y-m-d H:i:s' );
    }

    private function save_json( $path, $data ) {
        $this->storage->ensure_directories();

        file_put_contents(
            $path,
            wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            LOCK_EX
        );
    }
}
