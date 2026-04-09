<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remote_Backup_Admin {

    const ASYNC_BACKUP_HOOK = 'sprb_async_manual_backup';
    const ACTIVE_JOB_OPTION = 'sprb_active_backup_job_id';

    private $storage;
    private $runner;
    private $downloads;
    private $logger;
    private $scheduler;
    private $monitor;
    private $notice = '';

    public function __construct(
        Remote_Backup_Storage $storage,
        ?Remote_Backup_Runner $runner,
        ?Remote_Backup_Downloads $downloads,
        Remote_Backup_Logger $logger,
        ?Remote_Backup_Scheduler $scheduler,
        $monitor = null
    ) {
        $this->storage   = $storage;
        $this->runner    = $runner;
        $this->downloads = $downloads;
        $this->logger    = $logger;
        $this->scheduler = $scheduler;
        $this->monitor   = $monitor;

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( self::ASYNC_BACKUP_HOOK, array( $this, 'process_async_backup_job' ), 10, 1 );

        if ( $this->has_backup_features() ) {
            add_action( 'wp_ajax_sprb_run_backup', array( $this, 'ajax_run_backup' ) );
            add_action( 'wp_ajax_sprb_backup_progress', array( $this, 'ajax_backup_progress' ) );
            add_action( 'wp_ajax_sprb_backup_status', array( $this, 'ajax_backup_status' ) );
            add_action( 'wp_ajax_sprb_save_folders', array( $this, 'ajax_save_folders' ) );
            add_action( 'wp_ajax_sprb_list_dir', array( $this, 'ajax_list_dir' ) );
            add_action( 'wp_ajax_sprb_gdrive_disconnect', array( $this, 'ajax_gdrive_disconnect' ) );
            add_action( 'wp_ajax_sprb_gdrive_manual_auth', array( $this, 'ajax_gdrive_manual_auth' ) );
            add_action( 'wp_ajax_sprb_onedrive_disconnect', array( $this, 'ajax_onedrive_disconnect' ) );
            add_action( 'wp_ajax_sprb_onedrive_manual_auth', array( $this, 'ajax_onedrive_manual_auth' ) );
        add_action( 'wp_ajax_sprb_dropbox_disconnect', array( $this, 'ajax_dropbox_disconnect' ) );
        add_action( 'wp_ajax_sprb_dropbox_manual_auth', array( $this, 'ajax_dropbox_manual_auth' ) );
            add_action( 'wp_ajax_sprb_test_connection', array( $this, 'ajax_test_connection' ) );
            add_action( 'wp_ajax_sprb_simulate_backup', array( $this, 'ajax_simulate_backup' ) );
        }

        if ( $this->has_monitor_features() && ! class_exists( 'Remote_Backup_Monitor_Admin' ) ) {
            add_action( 'wp_ajax_sprb_monitor_action', array( $this, 'ajax_monitor_action' ) );
            add_action( 'wp_ajax_sprb_monitor_snapshot', array( $this, 'ajax_monitor_snapshot' ) );
            add_action( 'wp_ajax_sprb_monitor_progress', array( $this, 'ajax_monitor_progress' ) );
        }
    }

    private function has_backup_features() {
        return $this->runner && $this->downloads && $this->scheduler;
    }

    private function has_monitor_features() {
        return null !== $this->monitor;
    }

    private function menu_parent_slug() {
        return function_exists( 'savedpixel_admin_parent_slug' ) ? savedpixel_admin_parent_slug() : '';
    }

    private function backup_page_slug() {
        return 'savedpixel-remote-backup';
    }

    private function monitor_page_slug() {
        return 'savedpixel-remote-backup-monitor';
    }

    private function admin_asset_url( $asset ) {
        return plugins_url( 'savedpixel-remote-backup/assets/' . ltrim( (string) $asset, '/' ) );
    }

    public function enqueue_admin_assets() {
        $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page slug routing only.
        $allowed_pages = array( $this->backup_page_slug() );
        if ( $this->has_monitor_features() && ! class_exists( 'Remote_Backup_Monitor_Admin' ) ) {
            $allowed_pages[] = $this->monitor_page_slug();
        }
        if ( ! in_array( $page, $allowed_pages, true ) ) {
            return;
        }

        if ( function_exists( 'savedpixel_admin_enqueue_preview_style' ) ) {
            savedpixel_admin_enqueue_preview_style();
        }

        $css_path = plugin_dir_path( __DIR__ ) . 'assets/admin.css';
        $js_path  = plugin_dir_path( __DIR__ ) . 'assets/admin.js';
        wp_enqueue_style( 'spsprb-admin', $this->admin_asset_url( 'admin.css' ), array(), (string) @filemtime( $css_path ) );
        wp_enqueue_script( 'spsprb-admin', $this->admin_asset_url( 'admin.js' ), array(), (string) @filemtime( $js_path ), true );
    }

    private function background_finish_supported() {
        return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
    }

    private function active_job_id() {
        $job_id = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
        if ( '' === $job_id ) {
            return '';
        }

        $state = $this->get_backup_job_state( $job_id );
        if ( empty( $state ) || ! in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
            delete_option( self::ACTIVE_JOB_OPTION );
            return '';
        }

        // Auto-clear stale jobs stuck in "queued" for more than 5 minutes.
        if ( 'queued' === ( $state['status'] ?? '' ) && ! empty( $state['created_at'] ) ) {
            $created = strtotime( $state['created_at'] );
            if ( $created && ( time() - $created ) > 300 ) {
                $this->set_backup_job_state( $job_id, array_merge( $state, array(
                    'status'  => 'failed',
                    'phase'   => 'failed',
                    'message' => 'Backup timed out — the background worker never started.',
                ) ) );
                delete_option( self::ACTIVE_JOB_OPTION );
                return '';
            }
        }

        return $job_id;
    }

    private function active_job_state() {
        $job_id = $this->active_job_id();
        if ( '' === $job_id ) {
            return null;
        }

        return $this->get_backup_job_state( $job_id );
    }

    private function backup_job_state_key( $job_id ) {
        return 'sprb_backup_job_state_' . md5( (string) $job_id );
    }

    private function backup_job_payload_key( $job_id ) {
        return 'sprb_backup_job_payload_' . md5( (string) $job_id );
    }

    private function get_backup_job_state( $job_id ) {
        $state = get_transient( $this->backup_job_state_key( $job_id ) );
        return is_array( $state ) ? $state : null;
    }

    private function set_backup_job_state( $job_id, array $state ) {
        $state['id']         = (string) $job_id;
        $state['updated_at'] = current_time( 'mysql' );
        set_transient( $this->backup_job_state_key( $job_id ), $state, DAY_IN_SECONDS );
        return $state;
    }

    private function update_backup_job_state( $job_id, array $changes ) {
        $state = $this->get_backup_job_state( $job_id );
        if ( ! $state ) {
            return null;
        }

        return $this->set_backup_job_state( $job_id, array_merge( $state, $changes ) );
    }

    private function create_backup_job( $scope, $remote_mode, array $folders, array $state_overrides = array(), array $payload_overrides = array() ) {
        $job_id = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );
        $state  = array(
            'status'      => 'queued',
            'phase'       => 'queued',
            'scope'       => $scope,
            'remote_mode' => $remote_mode,
            'message'     => 'Backup queued. Waiting for the background worker to start.',
            'notice_type' => 'info',
            'created_at'  => current_time( 'mysql' ),
            'backup_id'   => null,
            'total_size'  => 0,
            'error'       => null,
        );
        $state  = array_merge( $state, $state_overrides );

        set_transient(
            $this->backup_job_payload_key( $job_id ),
            array_merge(
                array(
                    'scope'         => $scope,
                    'remote_mode'   => $remote_mode,
                    'folders'       => array_values( $folders ),
                    'context_label' => 'Manual backup',
                    'trigger_source' => 'manual-ui',
                ),
                $payload_overrides
            ),
            HOUR_IN_SECONDS
        );

        update_option( self::ACTIVE_JOB_OPTION, $job_id, false );

        return $this->set_backup_job_state( $job_id, $state );
    }

    public function queue_async_backup_request( $scope = 'both', $remote_mode = 'local', array $folders = array(), array $args = array() ) {
        if ( ! $this->has_backup_features() ) {
            return new WP_Error( 'sprb_backup_unavailable', 'Backup features are not available on this site.' );
        }

        $scope = sanitize_text_field( (string) $scope );
        if ( ! in_array( $scope, array( 'database', 'files', 'both' ), true ) ) {
            $scope = 'both';
        }

        $remote_mode = $this->normalize_remote_mode( $remote_mode );
        $folders     = array_values(
            array_filter(
                array_map(
                    static function ( $folder ) {
                        return sanitize_text_field( (string) $folder );
                    },
                    $folders
                )
            )
        );

        $active_job = $this->active_job_state();
        if ( $active_job && in_array( $active_job['status'] ?? '', array( 'queued', 'running' ), true ) ) {
            return new WP_Error(
                'sprb_backup_running',
                'A backup is already running. Wait for it to finish before starting another one.',
                array(
                    'jobId'  => $active_job['id'] ?? '',
                    'status' => $active_job['status'] ?? 'running',
                )
            );
        }

        $context_label = sanitize_text_field( (string) ( $args['context_label'] ?? 'Manual backup' ) );
        if ( '' === $context_label ) {
            $context_label = 'Manual backup';
        }

        $trigger_source = sanitize_key( (string) ( $args['trigger_source'] ?? 'manual-ui' ) );
        if ( '' === $trigger_source ) {
            $trigger_source = 'manual-ui';
        }

        $job = $this->create_backup_job(
            $scope,
            $remote_mode,
            $folders,
            array(
                'message'        => sanitize_text_field( (string) ( $args['queued_message'] ?? 'Backup queued. Waiting for the background worker to start.' ) ),
                'trigger_source' => $trigger_source,
            ),
            array(
                'context_label'  => $context_label,
                'trigger_source' => $trigger_source,
            )
        );

        $queued = $this->schedule_async_backup_job( $job['id'] );
        $this->logger->log(
            sprintf(
                'Backup queued via %1$s — job: %2$s, scope: %3$s, delivery: %4$s%5$s',
                $trigger_source,
                $job['id'],
                $scope,
                $remote_mode,
                $folders ? ', folders: ' . implode( ', ', $folders ) : ''
            )
        );

        return array(
            'jobId'              => $job['id'],
            'status'             => $job['status'],
            'phase'              => $job['phase'],
            'message'            => $queued
                ? 'Backup queued. The background worker will start automatically.'
                : 'Backup queued, but WordPress could not trigger the background worker immediately. Check WP-Cron or loopback access if it does not start.',
            'noticeType'         => $queued ? 'info' : 'warning',
            'queuedImmediately'  => (bool) $queued,
            'remoteMode'         => $remote_mode,
            'scope'              => $scope,
            'triggerSource'      => $trigger_source,
        );
    }

    public function get_backup_job_status_payload( $job_id = '' ) {
        $job_id = sanitize_text_field( (string) $job_id );
        if ( '' === $job_id ) {
            $job_id = $this->active_job_id();
        }

        if ( '' === $job_id ) {
            return array(
                'jobId'      => '',
                'status'     => 'idle',
                'phase'      => 'idle',
                'message'    => '',
                'noticeType' => 'info',
                'backupId'   => null,
                'totalSize'  => 0,
                'updatedAt'  => null,
            );
        }

        $state = $this->get_backup_job_state( $job_id );
        if ( ! $state ) {
            if ( $job_id === $this->active_job_id() ) {
                delete_option( self::ACTIVE_JOB_OPTION );
            }

            return array(
                'jobId'      => $job_id,
                'status'     => 'missing',
                'phase'      => 'idle',
                'message'    => 'Backup job state was not found.',
                'noticeType' => 'warning',
                'backupId'   => null,
                'totalSize'  => 0,
                'updatedAt'  => null,
            );
        }

        $phase = $state['phase'] ?? 'idle';
        $progress_sizes = array( 'db_size' => 0, 'files_size' => 0, 'total_size' => 0, 'expected_size' => 0 );
        if ( in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
            $progress = $this->runner->get_progress();
            if ( is_array( $progress ) ) {
                if ( 'idle' !== ( $progress['phase'] ?? 'idle' ) ) {
                    $phase = $progress['phase'];
                }
                $progress_sizes['db_size']       = $progress['db_size'] ?? 0;
                $progress_sizes['files_size']    = $progress['files_size'] ?? 0;
                $progress_sizes['total_size']    = $progress['total_size'] ?? 0;
                $progress_sizes['expected_size'] = $progress['expected_size'] ?? 0;
            }
        }

        return array(
            'jobId'        => $job_id,
            'status'       => $state['status'] ?? 'idle',
            'phase'        => $phase,
            'message'      => $state['message'] ?? '',
            'noticeType'   => $state['notice_type'] ?? 'info',
            'backupId'     => $state['backup_id'] ?? null,
            'totalSize'    => $state['total_size'] ?? 0,
            'dbSize'       => $progress_sizes['db_size'],
            'filesSize'    => $progress_sizes['files_size'],
            'progressSize' => $progress_sizes['total_size'],
            'expectedSize' => $progress_sizes['expected_size'],
            'updatedAt'    => $state['updated_at'] ?? null,
        );
    }

    private function schedule_async_backup_job( $job_id ) {
        $timestamp = time() + 1;

        wp_clear_scheduled_hook( self::ASYNC_BACKUP_HOOK, array( $job_id ) );
        wp_schedule_single_event( $timestamp, self::ASYNC_BACKUP_HOOK, array( $job_id ) );

        $scheduled = wp_next_scheduled( self::ASYNC_BACKUP_HOOK, array( $job_id ) );
        if ( ! $scheduled ) {
            return false;
        }

        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron( $timestamp );
        }

        return true;
    }

    private function send_background_start_response( array $payload ) {
        ignore_user_abort( true );
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running backup bootstrap response.

        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            $json = '{"success":false,"data":{"message":"Failed to encode response."}}';
        }

        if ( ! headers_sent() ) {
            status_header( 200 );
            nocache_headers();
            header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
            header( 'Content-Length: ' . strlen( $json ) );
            header( 'Connection: close' );
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body.
        echo $json;

        if ( function_exists( 'session_write_close' ) ) {
            session_write_close();
        }

        while ( ob_get_level() > 0 ) {
            @ob_end_flush();
        }

        flush();

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( 'litespeed_finish_request' ) ) {
            call_user_func( 'litespeed_finish_request' );
        }
    }

    private function finalize_backup_job( $job_id, $status, $message, $notice_type = 'info', array $extra = array() ) {
        $state = $this->update_backup_job_state(
            $job_id,
            array_merge(
                array(
                    'status'      => $status,
                    'phase'       => 'success' === $status ? 'complete' : ( 'failed' === $status ? 'failed' : 'idle' ),
                    'message'     => (string) $message,
                    'notice_type' => $notice_type,
                    'error'       => 'failed' === $status ? (string) $message : null,
                ),
                $extra
            )
        );

        if ( $job_id === $this->active_job_id() ) {
            delete_option( self::ACTIVE_JOB_OPTION );
        }

        delete_transient( $this->backup_job_payload_key( $job_id ) );

        return $state;
    }

    public function process_async_backup_job( $job_id ) {
        $job_id  = sanitize_text_field( (string) $job_id );
        $state   = $this->get_backup_job_state( $job_id );
        $payload = get_transient( $this->backup_job_payload_key( $job_id ) );

        if ( empty( $job_id ) || ! $state || ! is_array( $payload ) ) {
            return;
        }

        if ( ! in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
            return;
        }

        $scope       = sanitize_text_field( $payload['scope'] ?? 'both' );
        $remote_mode = $this->normalize_remote_mode( $payload['remote_mode'] ?? 'local' );
        $folders     = ! empty( $payload['folders'] ) && is_array( $payload['folders'] ) ? array_values( $payload['folders'] ) : array();
        $context     = sanitize_text_field( (string) ( $payload['context_label'] ?? 'Manual backup' ) );
        if ( '' === $context ) {
            $context = 'Manual backup';
        }

        $this->update_backup_job_state(
            $job_id,
            array(
                'status'  => 'running',
                'phase'   => 'starting',
                'message' => 'Backup started. Working in the background.',
            )
        );

        $this->logger->log( "Async backup started — job: {$job_id}, scope: {$scope}, delivery: {$remote_mode}" . ( $folders ? ', folders: ' . implode( ', ', $folders ) : '' ) );

        $result = $this->runner->run( $scope, $folders );

        if ( is_wp_error( $result ) ) {
            $this->logger->log( 'Async backup FAILED: ' . $result->get_error_message(), 'error' );
            $this->finalize_backup_job( $job_id, 'failed', 'Backup failed: ' . $result->get_error_message(), 'error' );
            return;
        }

        if ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
            $message = 'Backup failed: ' . ( $result['error'] ?? 'Unknown error.' );
            $this->logger->log( 'Async backup FAILED: ' . ( $result['error'] ?? 'Unknown error.' ), 'error' );
            $this->finalize_backup_job( $job_id, 'failed', $message, 'error' );
            return;
        }

        $remote = $this->maybe_send_remote_backup( $result, $context, $remote_mode );
        $notice = $this->build_backup_notice_data( $result, $remote );

        $this->logger->log( 'Async backup finished — job: ' . $job_id . ', backup: ' . ( $result['id'] ?? 'unknown' ) );
        $this->finalize_backup_job(
            $job_id,
            'success',
            $notice['message'],
            $notice['type'],
            array(
                'backup_id'  => $result['id'] ?? null,
                'total_size' => $result['total_size'] ?? 0,
                'error'      => null,
            )
        );
    }

    /* ── AJAX handlers ────────────────────────────────── */

    public function ajax_run_backup() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }
        $scope = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'both' ) );
        if ( ! in_array( $scope, array( 'database', 'files', 'both' ), true ) ) {
            $scope = 'both';
        }
        $remote_mode = $this->normalize_remote_mode(
            sanitize_text_field( wp_unslash( $_POST['remote_mode'] ?? get_option( 'sprb_manual_remote_mode', 'local' ) ) )
        );
        update_option( 'sprb_manual_remote_mode', $remote_mode );

        // Collect selected folders for file backups.
        $folders = array();
        $folder_inputs = isset( $_POST['folders'] ) ? wp_unslash( $_POST['folders'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Folder names are sanitized per item below.
        if ( ! empty( $folder_inputs ) && is_array( $folder_inputs ) ) {
            foreach ( $folder_inputs as $f ) {
                $folders[] = sanitize_text_field( wp_unslash( $f ) );
            }
        }

        $job = $this->queue_async_backup_request(
            $scope,
            $remote_mode,
            $folders,
            array(
                'context_label' => 'Manual backup',
                'trigger_source' => 'manual-ui',
                'queued_message' => 'Backup queued. Waiting for the background worker to start.',
            )
        );
        if ( is_wp_error( $job ) ) {
            wp_send_json_error(
                array(
                    'message' => $job->get_error_message(),
                    'jobId'   => $job->get_error_data()['jobId'] ?? '',
                    'status'  => $job->get_error_data()['status'] ?? 'running',
                )
            );
        }

        $payload = array(
            'success' => true,
            'data'    => array(
                'jobId'      => $job['jobId'],
                'status'     => $job['status'],
                'phase'      => $job['phase'],
                'message'    => 'Backup started. The page will update automatically.',
                'noticeType' => $job['noticeType'],
            ),
        );

        if ( $this->background_finish_supported() ) {
            $this->send_background_start_response( $payload );
            $this->process_async_backup_job( $job['jobId'] );
            exit;
        }

        // No fastcgi — run the backup synchronously before responding.
        ignore_user_abort( true );
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running backup.
        $this->process_async_backup_job( $job['jobId'] );

        $final = $this->get_backup_job_status_payload( $job['jobId'] );
        wp_send_json_success( $final );
    }

    public function ajax_backup_progress() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }
        $active = $this->active_job_state();
        $phase  = $this->runner->get_progress();

        if ( $active && in_array( $active['status'] ?? '', array( 'queued', 'running' ), true ) ) {
            if ( 'idle' === $phase ) {
                $phase = $active['phase'] ?? 'queued';
            }
        }

        wp_send_json_success(
            array(
                'phase'  => $phase,
                'status' => $active['status'] ?? 'idle',
                'jobId'  => $active['id'] ?? '',
            )
        );
    }

    public function ajax_backup_status() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
        wp_send_json_success( $this->get_backup_job_status_payload( $job_id ) );
    }

    public function ajax_save_folders() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }
        $folders = array();
        $folder_inputs = isset( $_POST['folders'] ) ? wp_unslash( $_POST['folders'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Folder names are sanitized per item below.
        if ( ! empty( $folder_inputs ) && is_array( $folder_inputs ) ) {
            foreach ( $folder_inputs as $f ) {
                $folders[] = sanitize_text_field( $f );
            }
        }
        update_option( 'sprb_backup_folders', $folders );
        wp_send_json_success();
    }

    /**
     * AJAX handler: list subdirectories of a given relative path.
     */
    public function ajax_list_dir() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $rel_path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';

        // Validate: must not contain path traversal.
        if ( '' === $rel_path || false !== strpos( $rel_path, '..' ) ) {
            wp_send_json_error( 'Invalid path.' );
        }

        $abs_path = trailingslashit( wp_normalize_path( ABSPATH . $rel_path ) );

        // Must be under ABSPATH.
        if ( 0 !== strpos( $abs_path, wp_normalize_path( ABSPATH ) ) ) {
            wp_send_json_error( 'Invalid path.' );
        }

        if ( ! is_dir( $abs_path ) ) {
            wp_send_json_error( 'Not a directory.' );
        }

        $skip          = array( '.', '..', '.git', '.svn', 'node_modules' );
        $excluded_dirs = array_map(
            'trailingslashit',
            array(
                wp_normalize_path( SPRB_STORAGE_DIR ),
                wp_normalize_path( SPRB_BASE_DIR ),
            )
        );

        $entries = array();
        foreach ( scandir( $abs_path ) as $item ) {
            if ( in_array( $item, $skip, true ) ) {
                continue;
            }
            $item_abs = $abs_path . $item;

            if ( is_dir( $item_abs ) ) {
                $item_full = trailingslashit( wp_normalize_path( $item_abs ) );
                if ( in_array( $item_full, $excluded_dirs, true ) ) {
                    continue;
                }
                // Check if this subdirectory has any contents.
                $has_children = false;
                $child_scan   = @scandir( $item_abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( $child_scan ) {
                    foreach ( $child_scan as $sub ) {
                        if ( ! in_array( $sub, $skip, true ) ) {
                            $has_children = true;
                            break;
                        }
                    }
                }
                $entries[] = array(
                    'name'        => $item,
                    'path'        => $rel_path . '/' . $item,
                    'isFile'      => false,
                    'hasChildren' => $has_children,
                );
            } else {
                $entries[] = array(
                    'name'        => $item,
                    'path'        => $rel_path . '/' . $item,
                    'isFile'      => true,
                    'hasChildren' => false,
                );
            }
        }

        // Sort: directories first, then files, each alphabetical.
        usort( $entries, function ( $a, $b ) {
            if ( $a['isFile'] !== $b['isFile'] ) {
                return $a['isFile'] ? 1 : -1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        wp_send_json_success( $entries );
    }

    public function ajax_gdrive_disconnect() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $provider = $this->scheduler ? $this->scheduler->get_provider( 'google_drive' ) : null;
        if ( $provider ) {
            $provider->disconnect();
            $this->logger->log( 'Google Drive disconnected.' );
        }

        wp_send_json_success( array( 'message' => 'Google Drive disconnected.' ) );
    }

    public function ajax_gdrive_manual_auth() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
        if ( '' === $code ) {
            wp_send_json_error( 'Authorization code is required.' );
        }

        /* Save custom OAuth credentials when provided. */
        $custom_id     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
        $custom_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
        if ( '' !== $custom_id ) {
            update_option( 'sprb_gdrive_client_id', $custom_id );
        }
        if ( '' !== $custom_secret ) {
            update_option( 'sprb_gdrive_client_secret', $custom_secret );
        }

        $provider = $this->scheduler ? $this->scheduler->get_provider( 'google_drive' ) : null;
        if ( ! $provider ) {
            wp_send_json_error( 'Google Drive provider not available.' );
        }

        $result = $provider->exchange_code( $code, 'http://localhost' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->logger->log( 'Google Drive authorized via manual code entry.' );
        wp_send_json_success( array( 'message' => 'Google Drive connected successfully.' ) );
    }

    public function ajax_onedrive_disconnect() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $provider = $this->scheduler ? $this->scheduler->get_provider( 'onedrive' ) : null;
        if ( $provider ) {
            $provider->disconnect();
            $this->logger->log( 'OneDrive disconnected.' );
        }

        wp_send_json_success( array( 'message' => 'OneDrive disconnected.' ) );
    }

    public function ajax_onedrive_manual_auth() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
        if ( '' === $code ) {
            wp_send_json_error( 'Authorization code is required.' );
        }

        /* Save custom OAuth credentials when provided. */
        $custom_id     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
        $custom_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
        if ( '' !== $custom_id ) {
            update_option( 'sprb_onedrive_client_id', $custom_id );
        }
        if ( '' !== $custom_secret ) {
            update_option( 'sprb_onedrive_client_secret', $custom_secret );
        }

        $provider = $this->scheduler ? $this->scheduler->get_provider( 'onedrive' ) : null;
        if ( ! $provider ) {
            wp_send_json_error( 'OneDrive provider not available.' );
        }

        $result = $provider->exchange_code( $code, 'http://localhost' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->logger->log( 'OneDrive authorized via manual code entry.' );
        wp_send_json_success( array( 'message' => 'OneDrive connected successfully.' ) );
    }

    public function ajax_dropbox_disconnect() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $provider = $this->scheduler ? $this->scheduler->get_provider( 'dropbox' ) : null;
        if ( $provider ) {
            $provider->disconnect();
            $this->logger->log( 'Dropbox disconnected.' );
        }

        wp_send_json_success( array( 'message' => 'Dropbox disconnected.' ) );
    }

    public function ajax_dropbox_manual_auth() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
        if ( '' === $code ) {
            wp_send_json_error( 'Authorization code is required.' );
        }

        /* Save custom OAuth credentials when provided. */
        $custom_id     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
        $custom_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
        if ( '' !== $custom_id ) {
            update_option( 'sprb_dropbox_client_id', $custom_id );
        }
        if ( '' !== $custom_secret ) {
            update_option( 'sprb_dropbox_client_secret', $custom_secret );
        }

        $provider = $this->scheduler ? $this->scheduler->get_provider( 'dropbox' ) : null;
        if ( ! $provider ) {
            wp_send_json_error( 'Dropbox provider not available.' );
        }

        $result = $provider->exchange_code( $code, 'http://localhost' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->logger->log( 'Dropbox authorized via manual code entry.' );
        wp_send_json_success( array( 'message' => 'Dropbox connected successfully.' ) );
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $result = $this->scheduler->test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'message' => $result ) );
    }

    /**
     * Simulate a 2 GB backup by writing fake progress transients through each phase.
     * The client polls rb_backup_status as usual; this endpoint steps the transient forward
     * on each call so the modal can display realistic size values.
     */
    public function ajax_simulate_backup() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $action = sanitize_key( wp_unslash( $_POST['sim_action'] ?? 'start' ) );

        if ( 'start' === $action ) {
            $sim = array(
                'phase'        => 'database',
                'db_target'    => 150 * 1024 * 1024,     // 150 MB database
                'file_target'  => 1850 * 1024 * 1024,    // 1.85 GB files
                'current_db'   => 0,
                'current_files'=> 0,
                'extra_ticks'  => 0,
            );
            update_option( 'sprb_sim_state', $sim );

            // Create a fake job so the polling loop has a job ID.
            $job_id = 'sim-' . wp_generate_password( 8, false );
            $this->set_backup_job_state( $job_id, array(
                'status'     => 'running',
                'phase'      => 'database',
                'message'    => 'Simulated backup running.',
                'notice_type'=> 'info',
                'backup_id'  => null,
                'total_size' => 0,
            ) );
            update_option( self::ACTIVE_JOB_OPTION, $job_id );

            $this->runner->set_progress( 'database', array(
                'db_size'    => 0,
                'files_size' => 0,
                'total_size' => 0,
            ) );

            wp_send_json_success( array(
                'jobId'   => $job_id,
                'status'  => 'running',
                'phase'   => 'database',
                'message' => 'Simulated 2 GB backup started.',
            ) );
        }

        if ( 'tick' === $action ) {
            $sim = get_option( 'sprb_sim_state', array() );
            if ( empty( $sim ) ) {
                wp_send_json_error( 'No simulation in progress.' );
            }

            $mb            = 1024 * 1024; // 1 MB per tick.
            $db_target     = $sim['db_target'];
            $file_target   = $sim['file_target'];
            $total_target  = $db_target + $file_target;
            $current_db    = $sim['current_db'] ?? 0;
            $current_files = $sim['current_files'] ?? 0;
            $phase         = $sim['phase'] ?? 'database';

            if ( 'database' === $phase ) {
                $current_db = min( $current_db + $mb, $db_target );
                if ( $current_db >= $db_target ) {
                    $phase = 'files';
                }
            } elseif ( 'files' === $phase ) {
                $current_files = min( $current_files + $mb, $file_target );
                if ( $current_files >= $file_target ) {
                    $phase = 'plugins';
                }
            } elseif ( 'plugins' === $phase ) {
                $sim['extra_ticks'] = ( $sim['extra_ticks'] ?? 0 ) + 1;
                if ( $sim['extra_ticks'] >= 5 ) {
                    $phase = 'remote';
                    $sim['extra_ticks'] = 0;
                }
            } elseif ( 'remote' === $phase ) {
                $sim['extra_ticks'] = ( $sim['extra_ticks'] ?? 0 ) + 1;
                if ( $sim['extra_ticks'] >= 5 ) {
                    $phase = 'complete';
                }
            }

            $sim['current_db']    = $current_db;
            $sim['current_files'] = $current_files;
            $sim['phase']         = $phase;
            $current_total        = $current_db + $current_files;

            $progress_data = array(
                'expected_size' => $total_target,
                'db_size'       => $current_db,
                'files_size'    => $current_files,
                'total_size'    => $current_total,
            );

            if ( 'complete' === $phase ) {
                $progress_data['db_size']    = $db_target;
                $progress_data['files_size'] = $file_target;
                $progress_data['total_size'] = $total_target;

                $job_id = $this->active_job_id();
                if ( $job_id ) {
                    $this->update_backup_job_state( $job_id, array(
                        'status'      => 'success',
                        'phase'       => 'complete',
                        'message'     => 'Simulated 2 GB backup completed.',
                        'notice_type' => 'success',
                        'total_size'  => $total_target,
                    ) );
                    delete_option( self::ACTIVE_JOB_OPTION );
                }
                delete_option( 'sprb_sim_state' );

                $this->runner->set_progress( $phase, $progress_data );
                wp_send_json_success( array( 'phase' => $phase, 'done' => true ) );
            }

            $this->runner->set_progress( $phase, $progress_data );

            $job_id = $this->active_job_id();
            if ( $job_id ) {
                $this->update_backup_job_state( $job_id, array( 'phase' => $phase ) );
            }

            update_option( 'sprb_sim_state', $sim );
            wp_send_json_success( array( 'phase' => $phase, 'done' => false ) );
        }

        wp_send_json_error( 'Invalid simulation action.' );
    }

    public function ajax_monitor_action() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_monitor_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $action = sanitize_key( wp_unslash( $_POST['monitor_action'] ?? '' ) );
        $url    = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
        $site   = $this->monitor_site_by_url( $url );

        if ( ! in_array( $action, array( 'poll', 'poll_all', 'pull_database', 'pull_files', 'cancel_transfer' ), true ) ) {
            wp_send_json_error( 'Invalid monitor action.' );
        }

        if ( 'poll_all' !== $action && ! $site ) {
            wp_send_json_error( 'The monitored site could not be found.' );
        }

        if ( in_array( $action, array( 'pull_database', 'pull_files' ), true ) && empty( $site['pull_enabled'] ) ) {
            wp_send_json_error( 'That site is configured for status-only monitoring. Add the pull token first.' );
        }

        $payload = array(
            'message' => $this->monitor_action_start_message( $action, $site ),
            'action'  => $action,
            'url'     => $url,
        );

        if ( $this->background_finish_supported() ) {
            $this->send_background_start_response(
                array(
                    'success' => true,
                    'data'    => $payload,
                )
            );

            $this->run_monitor_action( $action, $url );
            exit;
        }

        $result = $this->run_monitor_action( $action, $url );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success(
            array(
                'message' => $this->monitor_action_result_message( $action, $site, $result ),
                'action'  => $action,
                'url'     => $url,
            )
        );
    }

    public function ajax_monitor_snapshot() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_monitor_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $state = $this->monitor_page_state();

        wp_send_json_success(
            array(
                'summaryHtml' => $this->render_monitor_summary_cards( $state['summary'] ),
                'sitesHtml'   => $this->render_monitor_sites_table( $state['sites'], $state['statuses'] ),
                'active'      => ! empty( $state['active'] ),
            )
        );
    }

    public function ajax_monitor_progress() {
        check_ajax_referer( 'sprb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_monitor_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $state = $this->monitor_page_state();

        wp_send_json_success(
            array(
                'progressHtml' => $this->render_monitor_progress_panel( $state['progress_items'] ),
                'active'       => ! empty( $state['active'] ),
            )
        );
    }

    /* ── Top-level sidebar menu ───────────────────────── */

    public function register_menu() {
        $parent_slug = $this->menu_parent_slug();

        if ( $this->has_backup_features() ) {
            if ( '' !== $parent_slug ) {
                add_submenu_page(
                    $parent_slug,
                    'SavedPixel Remote Backup',
                    'Remote Backup',
                    'manage_options',
                    $this->backup_page_slug(),
                    array( $this, 'render_page' ),
                    20
                );
            } else {
                add_menu_page(
                    'SavedPixel Remote Backup',
                    'Remote Backup',
                    'manage_options',
                    $this->backup_page_slug(),
                    array( $this, 'render_page' ),
                    'dashicons-cloud-saved',
                    81
                );
            }
        }

        if ( $this->has_monitor_features() && ! class_exists( 'Remote_Backup_Monitor_Admin' ) ) {
            if ( '' !== $parent_slug ) {
                add_submenu_page(
                    $parent_slug,
                    'SavedPixel Remote Backup Monitor',
                    'Backup Monitor',
                    'manage_options',
                    $this->monitor_page_slug(),
                    array( $this, 'render_monitor_page' ),
                    21
                );
            } else {
                add_menu_page(
                    'SavedPixel Remote Backup Monitor',
                    'Backup Monitor',
                    'manage_options',
                    $this->monitor_page_slug(),
                    array( $this, 'render_monitor_page' ),
                    'dashicons-visibility',
                    82
                );
            }
        }
    }

    /* ── Actions ──────────────────────────────────────── */

    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle Google Drive OAuth callback.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- State param verified below.
        if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) ) {
            $state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
            if ( wp_verify_nonce( $state, 'sprb_gdrive_oauth' ) ) {
                $code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
                $provider = $this->scheduler ? $this->scheduler->get_provider( 'google_drive' ) : null;
                if ( $provider ) {
                    $result = $provider->exchange_code( $code );
                    if ( is_wp_error( $result ) ) {
                        $this->notice = $this->notice_html( 'Google Drive authorization failed: ' . $result->get_error_message(), 'error' );
                    } else {
                        $this->logger->log( 'Google Drive authorized successfully.' );
                        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->backup_page_slug() . '&sprb_gdrive_connected=1' ) );
                        exit;
                    }
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only param.
        if ( isset( $_GET['sprb_gdrive_connected'] ) ) {
            $this->notice = $this->notice_html( 'Google Drive connected successfully.' );
        }

        $has_backup  = $this->has_backup_features();
        $has_monitor = $this->has_monitor_features();
        $save_all_settings = $has_backup && isset( $_POST['sprb_save_settings'] );
        $settings_save_generated_pull_token = false;

        if ( $save_all_settings ) {
            $_POST['sprb_save_schedule']    = '1';
            $_POST['sprb_save_connection']  = '1';
            $_POST['sprb_save_pull_access'] = '1';
        }

        // Manual backup with explicit scope.
        if ( $has_backup && isset( $_POST['sprb_manual_scope'] ) ) {
            check_admin_referer( 'sprb_manual' );
            $scope = sanitize_text_field( wp_unslash( $_POST['sprb_manual_scope'] ) );
            if ( ! in_array( $scope, array( 'database', 'files', 'both' ), true ) ) {
                $scope = 'both';
            }
            $remote_mode = $this->normalize_remote_mode(
                sanitize_text_field( wp_unslash( $_POST['sprb_manual_remote_mode'] ?? get_option( 'sprb_manual_remote_mode', 'local' ) ) )
            );
            update_option( 'sprb_manual_remote_mode', $remote_mode );
            $this->logger->log( "Manual backup triggered — scope: {$scope}, delivery: {$remote_mode}" );
            $result = $this->runner->run( $scope );
            if ( is_wp_error( $result ) ) {
                $this->logger->log( 'Manual backup FAILED: ' . $result->get_error_message(), 'error' );
                $this->notice = $this->notice_html( 'Backup failed: ' . $result->get_error_message(), 'error' );
            } elseif ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
                $this->logger->log( 'Manual backup FAILED: ' . ( $result['error'] ?? 'Unknown error.' ), 'error' );
                $this->notice = $this->notice_html( 'Backup failed: ' . ( $result['error'] ?? 'Unknown error.' ), 'error' );
            } else {
                $remote       = $this->maybe_send_remote_backup( $result, 'Manual backup', $remote_mode );
                $notice       = $this->build_backup_notice_data( $result, $remote );
                $this->notice = $this->notice_html( $notice['message'], $notice['type'] );
            }
        }

        // Save scheduled settings.
        if ( $has_backup && isset( $_POST['sprb_save_schedule'] ) ) {
            check_admin_referer( 'sprb_schedule', 'sprb_schedule_nonce' );

            $db_frequency = $this->scheduler->sanitize_schedule_frequency( sanitize_text_field( wp_unslash( $_POST['sprb_schedule_database_frequency'] ?? get_option( 'sprb_schedule_database_frequency', 'none' ) ) ) );
            $db_time      = $this->scheduler->sanitize_schedule_time( sanitize_text_field( wp_unslash( $_POST['sprb_schedule_database_time'] ?? get_option( 'sprb_schedule_database_time', '02:00' ) ) ) );
            $db_weekday   = $this->scheduler->sanitize_schedule_weekday( sanitize_text_field( wp_unslash( $_POST['sprb_schedule_database_weekday'] ?? get_option( 'sprb_schedule_database_weekday', $this->scheduler->get_schedule_weekday( 'database' ) ) ) ) );
            $files_frequency = $this->scheduler->sanitize_schedule_frequency( sanitize_text_field( wp_unslash( $_POST['sprb_schedule_files_frequency'] ?? get_option( 'sprb_schedule_files_frequency', 'none' ) ) ) );
            $files_time      = $this->scheduler->sanitize_schedule_time( sanitize_text_field( wp_unslash( $_POST['sprb_schedule_files_time'] ?? get_option( 'sprb_schedule_files_time', '02:00' ) ) ) );
            $files_weekday   = $this->scheduler->sanitize_schedule_weekday( sanitize_text_field( wp_unslash( $_POST['sprb_schedule_files_weekday'] ?? get_option( 'sprb_schedule_files_weekday', $this->scheduler->get_schedule_weekday( 'files' ) ) ) ) );

            update_option( 'sprb_schedule_database_frequency', $db_frequency );
            update_option( 'sprb_schedule_database_time', $db_time );
            update_option( 'sprb_schedule_database_weekday', $db_weekday );
            update_option( 'sprb_schedule_files_frequency', $files_frequency );
            update_option( 'sprb_schedule_files_time', $files_time );
            update_option( 'sprb_schedule_files_weekday', $files_weekday );

            foreach ( array( 'database', 'files' ) as $delivery_scope ) {
                $opt_key = "sprb_scheduled_remote_mode_{$delivery_scope}";
                $mode    = get_option( $opt_key, 'remote' );
                if ( isset( $_POST[ $opt_key ] ) ) {
                    $mode = $this->normalize_remote_mode( sanitize_text_field( wp_unslash( $_POST[ $opt_key ] ) ) );
                }
                if ( 'remote' === $mode && ! $this->remote_settings_ready( get_option( 'sprb_remote_protocol', 'ssh' ) ) ) {
                    $mode = 'local';
                }
                update_option( $opt_key, $mode );
            }

            $retain_db = (int) get_option( 'sprb_retain_db', 0 );
            if ( isset( $_POST['sprb_retain_db'] ) ) {
                $retain_db = absint( $_POST['sprb_retain_db'] );
            }
            update_option( 'sprb_retain_db', $retain_db );
            $retain_files = (int) get_option( 'sprb_retain_files', 0 );
            if ( isset( $_POST['sprb_retain_files'] ) ) {
                $retain_files = absint( $_POST['sprb_retain_files'] );
            }
            update_option( 'sprb_retain_files', $retain_files );

            $this->scheduler->reschedule();
            $this->logger->log(
                'Schedule settings saved — db: ' . $db_frequency .
                ( 'weekly' === $db_frequency ? ' on ' . $db_weekday : '' ) .
                " @ {$db_time} (delivery: " . get_option( 'sprb_scheduled_remote_mode_database', 'remote' ) . '), files: ' . $files_frequency .
                ( 'weekly' === $files_frequency ? ' on ' . $files_weekday : '' ) .
                " @ {$files_time} (delivery: " . get_option( 'sprb_scheduled_remote_mode_files', 'remote' ) . "), retain db: {$retain_db}, retain files: {$retain_files}"
            );
            if ( ! $save_all_settings ) {
                $this->notice = $this->notice_html( 'Schedule settings saved.' );
            }
        }

        // Save or test remote connection settings.
        if ( $has_backup && ( isset( $_POST['sprb_save_connection'] ) || isset( $_POST['sprb_test_remote'] ) ) ) {
            check_admin_referer( 'sprb_remote', 'sprb_remote_nonce' );
            $protocol = $this->save_remote_settings_from_request();
            $label    = $this->remote_protocol_label( $protocol );

            if ( isset( $_POST['sprb_save_connection'] ) ) {
                $this->logger->log( "Remote connection settings saved — protocol: {$protocol}" );
                if ( ! $save_all_settings ) {
                    $this->notice = $this->notice_html( 'Remote connection settings saved.' );
                }
            }

            if ( isset( $_POST['sprb_test_remote'] ) ) {
                $this->logger->log( "Remote connection test started — protocol: {$protocol}" );
                $result = $this->scheduler->test_connection();
                if ( is_wp_error( $result ) ) {
                    $this->notice = $this->notice_html( "Remote Test ({$label}): " . $result->get_error_message(), 'error' );
                } else {
                    $this->notice = $this->notice_html( "Remote Test ({$label}): " . $result );
                }
            }
        }

        // Delete backup.
        if ( $has_backup && isset( $_GET['sprb_delete'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sprb_delete' ) ) {
                $id = sanitize_text_field( wp_unslash( $_GET['sprb_delete'] ) );
                $this->storage->delete_backup( $id );
                $this->logger->log( "Backup deleted: {$id}" );
                $this->notice = $this->notice_html( 'Backup deleted.' );
            }
        }

        // Install openssh-client.
        if ( $has_backup && isset( $_POST['sprb_install_ssh'] ) ) {
            check_admin_referer( 'sprb_remote' );
            $output     = array();
            $return_var = 0;
            // Try sudo first (www-data can't apt-get directly).
            exec( 'sudo apt-get update -qq 2>&1 && sudo apt-get install -y -qq openssh-client sshpass 2>&1', $output, $return_var );
            $text = implode( "\n", array_slice( $output, -5 ) );
            if ( 0 === $return_var ) {
                $this->logger->log( 'openssh-client + sshpass installed successfully.' );
                $this->notice = $this->notice_html( 'SSH tools installed successfully. Reload the page to verify.' );
            } else {
                $this->logger->log( "SSH install failed (exit {$return_var}): {$text}", 'error' );
                $this->notice = $this->notice_html( 'Install failed — run manually: docker exec -u root CONTAINER apt-get install -y openssh-client sshpass', 'error' );
            }
        }

        // Save pull access settings.
        if ( $has_backup && isset( $_POST['sprb_save_pull_access'] ) ) {
            check_admin_referer( 'sprb_pull_access', 'sprb_pull_access_nonce' );

            $token = trim( sanitize_text_field( wp_unslash( $_POST['sprb_pull_token'] ?? '' ) ) );
            if ( '' === $token ) {
                $token = Remote_Backup_Api::rotate_pull_token();
                $settings_save_generated_pull_token = true;
                if ( ! $save_all_settings ) {
                    $this->notice = $this->notice_html( 'Pull token was empty, so a new one was generated.' );
                }
            } else {
                update_option( Remote_Backup_Api::TOKEN_OPTION, $token );
                if ( ! $save_all_settings ) {
                    $this->notice = $this->notice_html( 'Pull access settings saved.' );
                }
            }

            $this->logger->log( 'Pull access settings saved.' );
        }

        if ( $save_all_settings ) {
            $message = $settings_save_generated_pull_token
                ? 'Backup settings saved. Pull token was empty, so a new one was generated.'
                : 'Backup settings saved.';
            $this->notice = $this->notice_html( $message );
        }

        if ( $has_backup && isset( $_POST['sprb_regenerate_pull_token'] ) ) {
            check_admin_referer( 'sprb_pull_access', 'sprb_pull_access_nonce' );
            Remote_Backup_Api::rotate_pull_token();
            $this->logger->log( 'Pull token regenerated.' );
            $this->notice = $this->notice_html( 'Pull token regenerated. Update the monitor with the new token.' );
        }

        // Clear log.
        if ( isset( $_POST['sprb_clear_log'] ) ) {
            check_admin_referer( 'sprb_log' );
            $this->logger->clear();
            $this->notice = $this->notice_html( 'Debug log cleared.' );
        }

        // Monitor: Add site.
        if ( $has_monitor && isset( $_POST['sprb_add_site'] ) ) {
            check_admin_referer( 'sprb_monitor' );
            $url      = esc_url_raw( wp_unslash( $_POST['sprb_site_url'] ?? '' ) );
            $label    = sanitize_text_field( wp_unslash( $_POST['sprb_site_label'] ?? '' ) );
            $pull_key = trim( sanitize_text_field( wp_unslash( $_POST['sprb_site_pull_token'] ?? '' ) ) );
            $result   = $this->monitor->add_site( $url, $label, $pull_key );
            if ( is_wp_error( $result ) ) {
                $this->notice = $this->notice_html( $result->get_error_message(), 'error' );
            } else {
                $synced = $this->monitor->sync_site_schedule( $url );
                if ( is_wp_error( $synced ) ) {
                    $this->notice = $this->notice_html(
                        ( 'updated' === $result ? 'Site updated.' : 'Site added.' ) . ' Remote schedule sync failed: ' . $synced->get_error_message(),
                        'warning'
                    );
                } else {
                    $this->notice = $this->notice_html( ( 'updated' === $result ? 'Site updated.' : 'Site added.' ) . ' Remote schedule synced.' );
                }
            }
        }

        if ( $has_monitor && isset( $_POST['sprb_save_monitor_settings'] ) ) {
            check_admin_referer( 'sprb_monitor' );
            update_option( 'sprb_monitor_retry_minutes', max( 5, absint( $_POST['sprb_monitor_retry_minutes'] ?? 15 ) ) );
            update_option( 'sprb_monitor_watch_minutes', max( 5, absint( $_POST['sprb_monitor_watch_minutes'] ?? 90 ) ) );
            update_option( 'sprb_monitor_notification_email', sanitize_text_field( wp_unslash( $_POST['sprb_monitor_notification_email'] ?? '' ) ) );
            $this->notice = $this->notice_html( 'Monitor settings saved.' );
        }

        // Monitor: Remove site.
        if ( $has_monitor && isset( $_GET['sprb_remove_site'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sprb_monitor' ) ) {
                $url = esc_url_raw( wp_unslash( $_GET['sprb_remove_site'] ) );
                $this->monitor->remove_site( $url );
                $this->notice = $this->notice_html( 'Site removed.' );
            }
        }

        // Monitor: Poll single site.
        if ( $has_monitor && isset( $_GET['sprb_poll_site'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sprb_monitor' ) ) {
                $url = esc_url_raw( wp_unslash( $_GET['sprb_poll_site'] ) );
                $this->monitor->poll_site( $url );
                $this->notice = $this->notice_html( 'Site polled.' );
            }
        }

        if ( $has_monitor && isset( $_GET['sprb_pull_site_database'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sprb_monitor' ) ) {
                $url    = esc_url_raw( wp_unslash( $_GET['sprb_pull_site_database'] ) );
                $result = $this->monitor->pull_site_artifact( $url, 'database' );
                $site   = $this->monitor_site_by_url( $url );
                $this->notice = $this->notice_html(
                    $this->monitor_action_result_message( 'pull_database', $site, $result ),
                    is_wp_error( $result ) ? 'error' : 'success'
                );
            }
        }

        if ( $has_monitor && isset( $_GET['sprb_pull_site_files'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sprb_monitor' ) ) {
                $url    = esc_url_raw( wp_unslash( $_GET['sprb_pull_site_files'] ) );
                $result = $this->monitor->pull_site_artifact( $url, 'files' );
                $site   = $this->monitor_site_by_url( $url );
                $this->notice = $this->notice_html(
                    $this->monitor_action_result_message( 'pull_files', $site, $result ),
                    is_wp_error( $result ) ? 'error' : 'success'
                );
            }
        }

        // Monitor: Poll all.
        if ( $has_monitor && isset( $_POST['sprb_poll_all'] ) ) {
            check_admin_referer( 'sprb_monitor' );
            $this->monitor->poll_all( true );
            $this->notice = $this->notice_html( 'All sites polled.' );
        }
    }

    private function monitor_site_by_url( $url ) {
        $url = untrailingslashit( esc_url_raw( (string) $url ) );
        foreach ( $this->monitor->get_sites() as $site ) {
            if ( ( $site['url'] ?? '' ) === $url ) {
                return $site;
            }
        }

        return null;
    }

    private function run_monitor_action( $action, $url = '' ) {
        switch ( $action ) {
            case 'cancel_transfer':
                $this->monitor->request_cancel( $url );
                return true;

            case 'pull_database':
                return $this->monitor->pull_site_artifact( $url, 'database' );

            case 'pull_files':
                return $this->monitor->pull_site_artifact( $url, 'files' );

            case 'poll_all':
                $this->monitor->poll_all( true );
                return true;

            case 'poll':
            default:
                return $this->monitor->poll_site( $url, true );
        }
    }

    private function monitor_action_start_message( $action, $site = null ) {
        $label = $this->monitor_site_label( $site );

        switch ( $action ) {
            case 'cancel_transfer':
                return "Cancelling the active transfer for {$label}.";

            case 'pull_database':
                return "Started pulling the latest database backup from {$label}.";

            case 'pull_files':
                return "Started pulling the latest files backup from {$label}.";

            case 'poll_all':
                return 'Started polling all monitored sites.';

            case 'poll':
            default:
                return "Started polling {$label}.";
        }
    }

    private function monitor_action_result_message( $action, $site, $result ) {
        if ( is_wp_error( $result ) ) {
            return $result->get_error_message();
        }

        if ( in_array( $action, array( 'pull_database', 'pull_files' ), true ) && ! empty( $result['last_download_message'] ) ) {
            return (string) $result['last_download_message'];
        }

        if ( 'poll' === $action && ! empty( $result['error'] ) ) {
            return 'Poll failed: ' . (string) $result['error'];
        }

        if ( 'poll_all' === $action ) {
            return 'All sites polled.';
        }

        return $this->monitor_action_start_message( $action, $site );
    }

    private function monitor_site_label( $site = null, $url = '' ) {
        if ( is_array( $site ) ) {
            if ( ! empty( $site['label'] ) ) {
                return (string) $site['label'];
            }
            if ( ! empty( $site['url'] ) ) {
                return (string) wp_parse_url( $site['url'], PHP_URL_HOST );
            }
        }

        if ( '' !== $url ) {
            return (string) wp_parse_url( $url, PHP_URL_HOST );
        }

        return 'the site';
    }

    private function monitor_page_state() {
        $sites     = $this->monitor->get_sites();
        $statuses  = $this->monitor->get_statuses();
        $summary   = $this->monitor_summary_counts( $sites, $statuses );
        $progress_items = array();

        foreach ( $sites as $site ) {
            $progress = $this->monitor->get_site_progress( $site['url'] ?? '' );
            if ( empty( $progress ) || empty( $progress['running'] ) ) {
                continue;
            }

            $progress['site_label'] = $site['label'] ?? $this->monitor_site_label( $site );
            $progress['site_url']   = $site['url'] ?? '';
            $progress_items[]       = $progress;
        }

        usort(
            $progress_items,
            static function ( $a, $b ) {
                return strcmp( (string) ( $b['started_at'] ?? '' ), (string) ( $a['started_at'] ?? '' ) );
            }
        );

        return array(
            'sites'           => $sites,
            'statuses'        => $statuses,
            'summary'         => $summary,
            'progress_items'  => $progress_items,
            'active'          => ! empty( $progress_items ),
        );
    }

    private function monitor_summary_counts( $sites, $statuses ) {
        $summary = array(
            'total'   => count( $sites ),
            'healthy' => 0,
            'warning' => 0,
            'offline' => 0,
            'failed'  => 0,
        );

        foreach ( $sites as $site ) {
            $status = $statuses[ $site['url'] ?? '' ]['status'] ?? 'unknown';
            if ( 'ok' === $status ) {
                $summary['healthy']++;
            } elseif ( 'warning' === $status ) {
                $summary['warning']++;
            } elseif ( 'offline' === $status ) {
                $summary['offline']++;
            } elseif ( 'failed' === $status ) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function badge_variant_for_status( $status ) {
        $status = strtolower( (string) $status );

        if ( in_array( $status, array( 'ok', 'success', 'healthy' ), true ) ) {
            return 'success';
        }

        if ( in_array( $status, array( 'warning', 'queued', 'running', 'pending' ), true ) ) {
            return 'warning';
        }

        if ( in_array( $status, array( 'failed', 'offline', 'error' ), true ) ) {
            return 'danger';
        }

        return 'neutral';
    }

    private function label_for_status( $status ) {
        $status = strtolower( (string) $status );

        if ( 'ok' === $status ) {
            return 'Ok';
        }

        if ( '' === $status || 'unknown' === $status ) {
            return 'Unknown';
        }

        return ucwords( str_replace( '_', ' ', $status ) );
    }

    private function badge_variant_for_scope( $scope ) {
        $scope = strtolower( (string) $scope );

        if ( 'database' === $scope ) {
            return 'info';
        }

        if ( 'files' === $scope ) {
            return 'warning';
        }

        if ( 'both' === $scope ) {
            return 'success';
        }

        return 'neutral';
    }

    private function label_for_scope( $scope ) {
        if ( 'both' === $scope ) {
            return 'DB + Files';
        }

        return ucwords( str_replace( '_', ' ', (string) $scope ) );
    }

    private function render_monitor_summary_cards( $summary ) {
        if ( empty( $summary['total'] ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="sp-summary">
            <div class="sp-summary-card sp-summary--total"><span class="sp-summary-num"><?php echo (int) $summary['total']; ?></span><span class="sp-summary-label">Sites</span></div>
            <div class="sp-summary-card sp-summary--ok"><span class="sp-summary-num"><?php echo (int) $summary['healthy']; ?></span><span class="sp-summary-label">Healthy</span></div>
            <div class="sp-summary-card sp-summary--warning"><span class="sp-summary-num"><?php echo (int) $summary['warning']; ?></span><span class="sp-summary-label">Warning</span></div>
            <div class="sp-summary-card sp-summary--failed"><span class="sp-summary-num"><?php echo (int) $summary['failed']; ?></span><span class="sp-summary-label">Failed</span></div>
            <div class="sp-summary-card sp-summary--offline"><span class="sp-summary-num"><?php echo (int) $summary['offline']; ?></span><span class="sp-summary-label">Offline</span></div>
        </div>
        <?php

        return ob_get_clean();
    }

    private function render_monitor_progress_panel( $progress_items ) {
        $progress_items = array_values( array_filter( (array) $progress_items ) );

        ob_start();
        ?>
        <div id="sp-monitor-progress-panel">
            <?php if ( empty( $progress_items ) ) : ?>
                <div class="sp-card sp-transfer-panel sp-transfer-panel--empty" style="display:none;"></div>
            <?php else : ?>
                <section class="sp-transfer-section">
                    <div class="sp-card__header">
                        <div>
                            <h2 class="sp-card__title">Active Transfers</h2>
                        </div>
                        <span class="sp-badge sp-badge--neutral"><?php echo esc_html( count( $progress_items ) . ' items' ); ?></span>
                    </div>
                    <div class="sp-card sp-transfer-panel">
                        <div class="sp-card__body">
                            <div class="sp-transfer-grid">
                                <?php foreach ( $progress_items as $progress ) : ?>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns controlled admin markup. ?>
                                    <?php echo $this->render_monitor_progress( $progress, $progress['site_label'] ?? '' ); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private function render_monitor_sites_table( $sites, $statuses ) {
        ob_start();
        ?>
        <section id="sp-monitor-sites-section" class="sp-monitor-sites-section">
            <div id="sp-monitor-sites-header" class="sp-card__header">
                <div id="sp-monitor-sites-header-main">
                    <h2 id="sp-monitor-sites-title" class="sp-card__title">Monitored Sites</h2>
                </div>
                <span id="sp-monitor-sites-count" class="sp-badge sp-badge--neutral"><?php echo esc_html( count( $sites ) . ' items' ); ?></span>
            </div>
            <div class="sp-card sp-monitor-sites sp-u-mt-0" id="sp-monitor-sites">
            <?php if ( empty( $sites ) ) : ?>
                <div class="sp-card__body">
                    <p class="sp-empty">No sites monitored yet. Add one above.</p>
                </div>
            <?php else : ?>
                <div class="sp-card__body sp-card__body--flush">
                    <div class="sp-table-wrap">
                        <table id="sp-monitor-sites-table" class="sp-table">
                            <thead>
                                <tr>
                                    <th>Site</th>
                                    <th>Status</th>
                                    <th>Schedule</th>
                                    <th>Backup Window</th>
                                    <th>Pull</th>
                                    <th class="sp-th-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $sites as $site ) : ?>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns controlled admin markup. ?>
                                    <?php echo $this->render_monitor_site_rows( $site, $statuses ); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </section>
        <?php

        return ob_get_clean();
    }

    private function render_monitor_site_rows( $site, $statuses ) {
        $url             = $site['url'];
        $status          = is_array( $statuses[ $url ] ?? null ) ? $statuses[ $url ] : array();
        $progress        = $this->monitor->get_site_progress( $url );
        $pulled_backups  = $this->monitor->get_pulled_backups( $url, 8 );
        $history         = $this->monitor->get_history( $url, 5 );
        $status_key      = $status['status'] ?? 'unknown';
        $status_label    = $this->label_for_status( $status_key );
        $status_variant  = $this->badge_variant_for_status( $status_key );
        $scope_schedules = ! empty( $site['remote_schedules'] ) ? $site['remote_schedules'] : ( $status['scope_schedules'] ?? array() );
        $status_meta     = array_filter(
            array(
                ! empty( $status['last_backup_status'] ) ? 'Last backup status: ' . $status['last_backup_status'] : '',
                ! empty( $status['plugin_version'] ) ? 'Plugin version: ' . $status['plugin_version'] : '',
                ! empty( $status['last_alert_message'] ) ? 'Alert: ' . $status['last_alert_message'] : '',
            )
        );
        $display_storage_dir = '';

        if ( ! empty( $pulled_backups[0]['storage_dir'] ) ) {
            $display_storage_dir = (string) $pulled_backups[0]['storage_dir'];
        } elseif ( ! empty( $status['last_download_storage_dir'] ) ) {
            $display_storage_dir = (string) $status['last_download_storage_dir'];
        }

        $schedule_primary   = $this->monitor_schedule_line( 'Database', $scope_schedules['database'] ?? array() );
        $schedule_secondary = $this->monitor_schedule_line( 'Files', $scope_schedules['files'] ?? array() );
        $last_backup_text   = ! empty( $status['last_backup_date'] ) ? 'Last ' . $status['last_backup_date'] : 'No backups yet';
        $next_poll_text     = ! empty( $status['next_poll_at'] ) ? 'Next ' . get_date_from_gmt( $status['next_poll_at'], 'Y-m-d H:i:s' ) : '';
        $checked_text       = ! empty( $status['last_checked'] ) ? 'Checked ' . $status['last_checked'] : '';
        $pull_primary       = ! empty( $site['pull_enabled'] )
            ? ( ! empty( $status['last_downloaded_at'] ) ? 'Last pull ' . $status['last_downloaded_at'] : ( $status['last_download_message'] ?? 'Ready to pull' ) )
            : 'Status only';
        $pull_secondary = ! empty( $status['last_download_status'] )
            ? ucfirst( (string) $status['last_download_status'] ) . ( ! empty( $status['download_count'] ) ? ' · ' . (string) $status['download_count'] . ' stored' : '' )
            : '';
        $folder_text = '' !== $display_storage_dir ? 'Folder ' . $display_storage_dir : '';
        $active_note = ! empty( $progress['running'] ) ? 'Active transfer shown above.' : '';

        $poll_url       = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_poll_site=' . urlencode( $url ) ), 'sprb_monitor' );
        $pull_db_url    = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_pull_site_database=' . urlencode( $url ) ), 'sprb_monitor' );
        $pull_files_url = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_pull_site_files=' . urlencode( $url ) ), 'sprb_monitor' );
        $remove_url     = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_remove_site=' . urlencode( $url ) ), 'sprb_monitor' );

        ob_start();
        ?>
        <tr class="sp-monitor-row" data-url="<?php echo esc_attr( $url ); ?>">
            <td>
                <div class="sp-site-cell">
                    <strong class="sp-site-cell__name"><?php echo esc_html( $site['label'] ); ?></strong>
                    <a class="sp-site-cell__url" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a>
                </div>
            </td>
            <td>
                <span class="sp-badge sp-badge--<?php echo esc_attr( $status_variant ); ?>"><?php echo esc_html( $status_label ); ?></span>
                <?php if ( ! empty( $status_meta ) ) : ?>
                    <span class="sp-status-info" title="<?php echo esc_attr( implode( "\n", $status_meta ) ); ?>">ⓘ</span>
                <?php endif; ?>
                <?php if ( $status && ! empty( $status['error'] ) ) : ?>
                    <span class="sp-status-info" title="<?php echo esc_attr( $status['error'] ); ?>">ⓘ</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="sp-monitor-meta">
                    <strong><?php echo esc_html( $schedule_primary ); ?></strong>
                    <span><?php echo esc_html( $schedule_secondary ); ?></span>
                    <?php if ( ! empty( $site['remote_schedule_synced_at'] ) ) : ?>
                        <span><?php echo esc_html( 'Synced ' . $site['remote_schedule_synced_at'] ); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="sp-monitor-meta">
                    <strong><?php echo esc_html( $last_backup_text ); ?></strong>
                    <?php if ( '' !== $next_poll_text ) : ?>
                        <span><?php echo esc_html( $next_poll_text ); ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $checked_text ) : ?>
                        <span><?php echo esc_html( $checked_text ); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="sp-monitor-meta">
                    <strong><?php echo esc_html( $pull_primary ); ?></strong>
                    <?php if ( '' !== $pull_secondary ) : ?>
                        <span><?php echo esc_html( $pull_secondary ); ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $folder_text ) : ?>
                        <span><?php echo esc_html( $folder_text ); ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $active_note ) : ?>
                        <span><?php echo esc_html( $active_note ); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="sp-monitor-actions">
                    <a href="<?php echo esc_url( $poll_url ); ?>" class="sp-btn sp-btn--ghost sp-btn--icon sp-monitor-action" data-monitor-action="poll" data-url="<?php echo esc_attr( $url ); ?>" aria-label="Poll now" title="Poll now">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 5a7 7 0 0 1 6.66 4.85H16v2h6V6h-2v2.27A9 9 0 1 0 21 12h-2a7 7 0 1 1-7-7Z" fill="currentColor"></path>
                        </svg>
                    </a>
                    <?php if ( ! empty( $site['pull_enabled'] ) ) : ?>
                        <a href="<?php echo esc_url( $pull_db_url ); ?>" class="sp-btn sp-btn--ghost sp-btn--icon sp-monitor-action" data-monitor-action="pull_database" data-url="<?php echo esc_attr( $url ); ?>" title="Pull DB">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 2C6.48 2 2 3.34 2 5v14c0 1.66 4.48 3 10 3s10-1.34 10-3V5c0-1.66-4.48-3-10-3Zm0 2c4.42 0 8 1.12 8 2s-3.58 2-8 2-8-1.12-8-2 3.58-2 8-2ZM4 9.26C5.53 10.33 8.46 11 12 11s6.47-.67 8-1.74V12c0 .88-3.58 2-8 2s-8-1.12-8-2V9.26ZM12 20c-4.42 0-8-1.12-8-2v-2.74C5.53 16.33 8.46 17 12 17s6.47-.67 8-1.74V18c0 .88-3.58 2-8 2Z" fill="currentColor"/>
                            </svg>
                        </a>
                        <a href="<?php echo esc_url( $pull_files_url ); ?>" class="sp-btn sp-btn--ghost sp-btn--icon sp-monitor-action" data-monitor-action="pull_files" data-url="<?php echo esc_attr( $url ); ?>" title="Pull Files">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2Z" fill="currentColor"/>
                            </svg>
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $remove_url ); ?>" class="sp-btn sp-btn--danger sp-btn--icon" aria-label="Remove site" title="Remove site" onclick="return confirm('Remove this site from monitoring?');">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12V8h12v13H6Z" fill="currentColor"></path>
                        </svg>
                    </a>
                </div>
            </td>
        </tr>
        <?php if ( ! empty( $history ) || ! empty( $pulled_backups ) ) : ?>
            <tr class="sp-history-detail" data-url="<?php echo esc_attr( $url ); ?>" style="display:none;">
                <td colspan="6">
                    <div class="sp-history-detail-body">
                        <?php if ( ! empty( $history ) ) : ?>
                            <div class="sp-history-block">
                                <div class="sp-history-block__header">Last 5 checks</div>
                                <table class="sp-history-mini">
                                    <tbody>
                                        <?php foreach ( $history as $item ) : ?>
                                            <?php
                                            $item_status  = $item['status'] ?? 'unknown';
                                            $item_variant = $this->badge_variant_for_status( $item_status );
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html( $item['last_checked'] ?? '' ); ?></td>
                                                <td><span class="sp-badge sp-badge--<?php echo esc_attr( $item_variant ); ?>"><?php echo esc_html( $this->label_for_status( $item_status ) ); ?></span></td>
                                                <td><?php echo esc_html( $item['last_backup_date'] ?? 'No backups' ); ?></td>
                                                <td><?php echo ! empty( $item['next_poll_at'] ) ? esc_html( get_date_from_gmt( $item['next_poll_at'], 'Y-m-d H:i:s' ) ) : '—'; ?></td>
                                                <td><?php echo ! empty( $item['last_download_status'] ) ? esc_html( ucfirst( (string) $item['last_download_status'] ) ) : '—'; ?></td>
                                                <td><?php echo ! empty( $item['error'] ) ? esc_html( $item['error'] ) : ''; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $pulled_backups ) ) : ?>
                            <div class="sp-history-block">
                                <div class="sp-history-block__header">Saved backups</div>
                                <table class="sp-history-mini sp-history-mini--saved">
                                    <thead>
                                        <tr>
                                            <th>Folder</th>
                                            <th class="sp-history-mini__blank" aria-hidden="true"></th>
                                            <th>Backup Ref</th>
                                            <th>Pulled At</th>
                                            <th>Files</th>
                                            <th class="sp-history-mini__blank" aria-hidden="true"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $pulled_backups as $pulled_backup ) : ?>
                                            <tr>
                                                <td>
                                                    <div class="sp-site-cell sp-site-cell--folder">
                                                        <strong class="sp-site-cell__name"><?php echo esc_html( basename( (string) ( $pulled_backup['storage_dir'] ?? $site['label'] ) ) ); ?></strong>
                                                        <span class="sp-site-cell__meta">Remote pull destination</span>
                                                    </div>
                                                </td>
                                                <td></td>
                                                <td><code><?php echo esc_html( $pulled_backup['backup_id'] ?? '—' ); ?></code></td>
                                                <td><?php echo esc_html( $pulled_backup['downloaded_at'] ?? '—' ); ?></td>
                                                <td>
                                                    <?php if ( ! empty( $pulled_backup['artifacts'] ) ) : ?>
                                                        <ul class="sp-monitor-files">
                                                            <?php foreach ( $pulled_backup['artifacts'] as $artifact ) : ?>
                                                                <li>
                                                                    <code><?php echo esc_html( $artifact['filename'] ?? '' ); ?></code>
                                                                    <?php if ( ! empty( $artifact['type'] ) || ! empty( $artifact['size_label'] ) ) : ?>
                                                                        <span class="description">
                                                                            <?php
                                                                            $artifact_meta = array_filter(
                                                                                array(
                                                                                    ! empty( $artifact['type'] ) ? ucfirst( (string) $artifact['type'] ) : '',
                                                                                    ! empty( $artifact['size_label'] ) ? (string) $artifact['size_label'] : '',
                                                                                )
                                                                            );
                                                                            echo esc_html( implode( ' · ', $artifact_meta ) );
                                                                            ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else : ?>
                                                        <em>No files recorded</em>
                                                    <?php endif; ?>
                                                </td>
                                                <td></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endif; ?>
        <?php

        return ob_get_clean();
    }

    private function monitor_schedule_line( $title, $schedule ) {
        $schedule = is_array( $schedule ) ? $schedule : array();
        if ( empty( $schedule['enabled'] ) || empty( $schedule['frequency'] ) || 'none' === $schedule['frequency'] ) {
            return $title . ': Disabled';
        }

        $line = $title . ': ' . $this->monitor_frequency_label( $schedule['frequency'] ?? 'none' );

        if ( 'weekly' === ( $schedule['frequency'] ?? '' ) && ! empty( $schedule['configured_weekday_label'] ) ) {
            $line .= ' on ' . $schedule['configured_weekday_label'];
        }

        if ( ! empty( $schedule['configured_time'] ) ) {
            $line .= ' at ' . $schedule['configured_time'];
        }

        if ( ! empty( $schedule['next_run_local'] ) ) {
            $line .= ' · Next ' . $schedule['next_run_local'];
        }

        return $line;
    }

    private function monitor_frequency_label( $frequency ) {
        $labels = array(
            'hourly'       => 'Hourly',
            'daily'        => 'Daily',
            'weekly'       => 'Weekly',
            'sprb_every_6h'  => 'Every 6 Hours',
            'sprb_every_12h' => 'Every 12 Hours',
            'none'         => 'Disabled',
        );

        return $labels[ $frequency ] ?? ucwords( str_replace( '_', ' ', str_replace( 'sprb_every_', 'Every ', (string) $frequency ) ) );
    }

    private function render_monitor_progress( $progress, $title = '' ) {
        if ( empty( $progress ) || empty( $progress['message'] ) ) {
            return '';
        }

        $running      = ! empty( $progress['running'] );
        $status_class = sanitize_html_class( (string) ( $progress['status'] ?? 'idle' ) );
        $percent      = (int) ( $progress['percent'] ?? 0 );
        $bar_width    = (int) min( 100, max( $running && $percent <= 0 ? 12 : $percent, 0 ) );
        $url          = esc_attr( (string) ( $progress['url'] ?? '' ) );
        $meta         = array();

        if ( ! empty( $progress['artifact_label'] ) && 'backup' !== $progress['artifact_label'] ) {
            $meta[] = ucfirst( (string) $progress['artifact_label'] );
        } elseif ( 'poll' === ( $progress['action'] ?? '' ) ) {
            $meta[] = 'Status sync';
        }

        if ( ! empty( $progress['filename'] ) ) {
            $meta[] = (string) $progress['filename'];
        }

        if ( ! empty( $progress['total_bytes'] ) ) {
            $meta[] = $this->monitor_format_bytes( (int) ( $progress['downloaded_bytes'] ?? 0 ) ) . ' / ' . $this->monitor_format_bytes( (int) $progress['total_bytes'] );
        }

        ob_start();
        ?>
        <div class="sp-transfer-card sp-transfer-card--<?php echo esc_attr( $status_class ); ?>" data-running="<?php echo $running ? '1' : '0'; ?>" data-url="<?php echo esc_attr( $url ); ?>">
            <?php if ( '' !== $title ) : ?>
                <div class="sp-transfer-card__title"><?php echo esc_html( (string) $title ); ?></div>
            <?php endif; ?>
            <div class="sp-transfer-card__message"><?php echo esc_html( (string) $progress['message'] ); ?></div>
            <?php if ( ! empty( $meta ) ) : ?>
                <div class="sp-transfer-card__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></div>
            <?php endif; ?>
            <div class="sp-transfer-card__bar<?php echo empty( $progress['total_bytes'] ) ? ' is-indeterminate' : ''; ?>">
                <span style="width: <?php echo esc_attr( $bar_width ); ?>%;"></span>
            </div>
            <?php if ( ! empty( $progress['total_bytes'] ) ) : ?>
                <div class="sp-transfer-card__percent"><?php echo esc_html( (string) $percent ); ?>%</div>
            <?php endif; ?>
            <?php if ( $running && '' !== $url ) : ?>
                <div id="sp-cancel-wrap-<?php echo esc_attr( md5( $url ) ); ?>" class="sp-transfer-card__actions" style="margin-top:6px;">
                    <a id="sp-cancel-<?php echo esc_attr( md5( $url ) ); ?>" href="#" class="sp-monitor-action sp-link sp-link--danger" data-monitor-action="cancel_transfer" data-url="<?php echo esc_attr( $url ); ?>">Cancel Transfer</a>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private function monitor_format_bytes( $bytes ) {
        return $bytes > 0 ? size_format( (int) $bytes ) : '0 B';
    }

    private function notice_html( $msg, $type = 'success' ) {
        return '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }

    private function maybe_migrate_delivery_options() {
        $old = get_option( 'sprb_scheduled_remote_mode' );
        if ( false === $old ) {
            return;
        }
        $mode = $this->normalize_remote_mode( $old );
        if ( false === get_option( 'sprb_scheduled_remote_mode_database' ) ) {
            update_option( 'sprb_scheduled_remote_mode_database', $mode );
        }
        if ( false === get_option( 'sprb_scheduled_remote_mode_files' ) ) {
            update_option( 'sprb_scheduled_remote_mode_files', $mode );
        }
        delete_option( 'sprb_scheduled_remote_mode' );
    }

    private function normalize_remote_mode( $value ) {
        return 'remote' === sanitize_text_field( (string) $value ) ? 'remote' : 'local';
    }

    private function normalize_remote_protocol( $value ) {
        $value = sanitize_text_field( (string) $value );
        $providers = $this->scheduler ? $this->scheduler->get_providers() : array();
        if ( isset( $providers[ $value ] ) ) {
            return $value;
        }
        return 'ssh';
    }

    private function remote_protocol_label( $protocol ) {
        $provider = $this->scheduler ? $this->scheduler->get_provider( $protocol ) : null;
        return $provider ? $provider->get_label() : strtoupper( $protocol );
    }

    private function save_remote_settings_from_request() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- This helper is only called after check_admin_referer( 'sprb_remote' ).
        $protocol = $this->normalize_remote_protocol( sanitize_text_field( wp_unslash( $_POST['sprb_remote_protocol'] ?? get_option( 'sprb_remote_protocol', 'ssh' ) ) ) );
        update_option( 'sprb_remote_protocol', $protocol );

        $provider = $this->scheduler ? $this->scheduler->get_provider( $protocol ) : null;
        if ( $provider ) {
            $provider->save_settings_from_request();
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return $protocol;
    }

    private function remote_settings_ready( $protocol, $settings = array() ) {
        $protocol = $this->normalize_remote_protocol( $protocol );
        $provider = $this->scheduler ? $this->scheduler->get_provider( $protocol ) : null;
        return $provider ? $provider->is_ready() : false;
    }

    private function maybe_send_remote_backup( $backup, $context = 'Backup', $remote_mode = 'local' ) {
        if ( 'remote' !== $this->normalize_remote_mode( $remote_mode ) ) {
            return null;
        }

        $this->runner->set_progress( 'remote' );
        $result = $this->scheduler->send_backup_to_remote( $backup, $context );
        $this->runner->set_progress( 'complete' );

        return $result;
    }

    private function build_backup_notice_data( $backup, $remote = null ) {
        $size    = size_format( $backup['total_size'] ?? 0 );
        $message = 'Backup completed — ' . $size . ' total.';
        $type    = 'success';

        if ( empty( $remote ) || empty( $remote['message'] ) ) {
            return array(
                'message' => $message,
                'type'    => $type,
            );
        }

        if ( 'success' === ( $remote['status'] ?? '' ) ) {
            $message .= ' ' . $remote['message'];
        } else {
            $message = 'Backup completed locally — ' . $size . ' total. ' . $remote['message'];
            $type    = 'warning';
        }

        return array(
            'message' => $message,
            'type'    => $type,
        );
    }

    /* ── Render ───────────────────────────────────────── */

    /**
     * Compute backup summary stats for the summary cards row.
     */
    private function backup_summary_stats( array $backups ) {
        $total   = count( $backups );
        $db      = 0;
        $files   = 0;
        $remote  = 0;
        $storage = 0;

        foreach ( $backups as $b ) {
            $scope = $b['scope'] ?? '';
            if ( 'database' === $scope ) {
                $db++;
            } elseif ( 'files' === $scope ) {
                $files++;
            } elseif ( 'both' === $scope ) {
                $db++;
                $files++;
            }

            $rs = $b['remote_status'] ?? '';
            if ( 'success' === $rs ) {
                $remote++;
            }

            $storage += (int) ( $b['total_size'] ?? 0 );
        }

        return array(
            'total'   => $total,
            'db'      => $db,
            'files'   => $files,
            'remote'  => $remote,
            'storage' => $storage,
        );
    }

    /**
     * Render backup summary cards row matching the monitor's sp-summary pattern.
     */
    private function render_backup_summary_cards( array $summary ) {
        ob_start();
        ?>
        <div id="sprb-summary" class="sp-summary">
            <div id="sprb-summary-total" class="sp-summary-card sp-summary--neutral"><span id="sprb-summary-total-num" class="sp-summary-num"><?php echo (int) $summary['total']; ?></span><span id="sprb-summary-total-label" class="sp-summary-label">Backups</span></div>
            <div id="sprb-summary-db" class="sp-summary-card sp-summary--info"><span id="sprb-summary-db-num" class="sp-summary-num"><?php echo (int) $summary['db']; ?></span><span id="sprb-summary-db-label" class="sp-summary-label">Database</span></div>
            <div id="sprb-summary-files" class="sp-summary-card sp-summary--warning"><span id="sprb-summary-files-num" class="sp-summary-num"><?php echo (int) $summary['files']; ?></span><span id="sprb-summary-files-label" class="sp-summary-label">Files</span></div>
            <div id="sprb-summary-remote" class="sp-summary-card sp-summary--ok"><span id="sprb-summary-remote-num" class="sp-summary-num"><?php echo (int) $summary['remote']; ?></span><span id="sprb-summary-remote-label" class="sp-summary-label">Remote</span></div>
            <div id="sprb-summary-storage" class="sp-summary-card sp-summary--neutral"><span id="sprb-summary-storage-num" class="sp-summary-num"><?php echo esc_html( size_format( $summary['storage'] ) ); ?></span><span id="sprb-summary-storage-label" class="sp-summary-label">Storage</span></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            return;
        }

        $backups             = $this->storage->get_backups();
        $writable            = $this->storage->is_writable();
        $database_schedule   = $this->scheduler->describe_schedule( 'database' );
        $files_schedule      = $this->scheduler->describe_schedule( 'files' );
        $sched_db_freq       = $database_schedule['frequency'] ?? 'none';
        $sched_db_time       = $database_schedule['configured_time'] ?? '02:00';
        $sched_db_weekday    = $database_schedule['configured_weekday'] ?? $this->scheduler->get_schedule_weekday( 'database' );
        $sched_files_freq    = $files_schedule['frequency'] ?? 'none';
        $sched_files_time    = $files_schedule['configured_time'] ?? '02:00';
        $sched_files_weekday = $files_schedule['configured_weekday'] ?? $this->scheduler->get_schedule_weekday( 'files' );
        $this->maybe_migrate_delivery_options();
        $sched_remote_db     = $this->normalize_remote_mode( get_option( 'sprb_scheduled_remote_mode_database', 'remote' ) );
        $sched_remote_files  = $this->normalize_remote_mode( get_option( 'sprb_scheduled_remote_mode_files', 'remote' ) );
        $manual_remote       = $this->normalize_remote_mode( get_option( 'sprb_manual_remote_mode', 'local' ) );
        $retain_db           = get_option( 'sprb_retain_db', 0 );
        $retain_files        = get_option( 'sprb_retain_files', 0 );
        $remote_protocol     = $this->normalize_remote_protocol( get_option( 'sprb_remote_protocol', 'ssh' ) );
        $remote_label        = $this->remote_protocol_label( $remote_protocol );
        $next_database       = $database_schedule['next_run_local'] ?? null;
        $next_files          = $files_schedule['next_run_local'] ?? null;
        $pull_token          = Remote_Backup_Api::get_pull_token( true );
        $status_url          = rest_url( 'remote-backup/v1/status' );
        $catalog_url         = rest_url( 'remote-backup/v1/backups' );
        $active_job          = $this->active_job_state();
        $weekday_options     = $this->scheduler->weekday_options();
        $remote_ready        = $this->remote_settings_ready( $remote_protocol );
        $providers           = $this->scheduler->get_providers();

        if ( ! $remote_ready ) {
            $manual_remote      = 'local';
            $sched_remote_db    = 'local';
            $sched_remote_files = 'local';
        }

        wp_localize_script( 'spsprb-admin', 'rbAdmin', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'sprb_ajax' ),
            'backupPageUrl'    => admin_url( 'admin.php?page=' . $this->backup_page_slug() ),
            'activeJobId'      => $active_job['id'] ?? '',
            'activeJobStatus'  => $active_job['status'] ?? 'idle',
        ) );

        $overview_url = function_exists( 'savedpixel_admin_page_url' )
            ? savedpixel_admin_page_url( 'savedpixel' )
            : admin_url( 'admin.php?page=savedpixel' );

        $saved_folders = get_option( 'sprb_backup_folders', array() );
        $skip          = array( '.', '..', '.git', '.svn', 'node_modules' );
        $excluded_dirs = array_map(
            'trailingslashit',
            array(
                wp_normalize_path( SPRB_STORAGE_DIR ),
                wp_normalize_path( SPRB_BASE_DIR ),
            )
        );
        $tree       = array();
        $root_files = array();
        foreach ( scandir( ABSPATH ) as $item ) {
            if ( in_array( $item, $skip, true ) ) {
                continue;
            }
            if ( ! is_dir( ABSPATH . $item ) ) {
                $root_files[] = $item;
                continue;
            }
            $item_path = trailingslashit( wp_normalize_path( ABSPATH . $item ) );
            if ( in_array( $item_path, $excluded_dirs, true ) ) {
                continue;
            }
            // Check if this top-level dir has any contents (files or subdirs).
            $has_children = false;
            foreach ( scandir( ABSPATH . $item ) as $child ) {
                if ( in_array( $child, $skip, true ) ) {
                    continue;
                }
                if ( is_dir( ABSPATH . $item . '/' . $child ) ) {
                    $child_full = trailingslashit( wp_normalize_path( ABSPATH . $item . '/' . $child ) );
                    if ( in_array( $child_full, $excluded_dirs, true ) ) {
                        continue;
                    }
                }
                $has_children = true;
                break;
            }
            $tree[ $item ] = $has_children;
        }
        ksort( $tree );
        sort( $root_files, SORT_STRING | SORT_FLAG_CASE );

        $is_saved = function ( $path ) use ( $saved_folders ) {
            if ( empty( $saved_folders ) ) {
                return true;
            }
            // Exact match.
            if ( in_array( $path, $saved_folders, true ) ) {
                return true;
            }
            // Check if any saved folder is a descendant of this path.
            $prefix = $path . '/';
            foreach ( $saved_folders as $sf ) {
                if ( 0 === strpos( $sf, $prefix ) ) {
                    return true;
                }
            }
            return false;
        };
        ?><?php
        $summary = $this->backup_summary_stats( $backups );
        ?><?php savedpixel_admin_page_start( 'spsprb-page' ); ?>
                <header id="sprb-header" class="sp-page-header">
                    <div id="sprb-header-main">
                        <h1 id="sprb-header-title" class="sp-page-title">SavedPixel Remote Backup</h1>
                        <p id="sprb-header-desc" class="sp-page-desc sp-u-max-w-none">Run manual backups, configure scheduled retention, and manage remote delivery or pull-based access from a single backup workspace.</p>
                    </div>
                    <div id="sprb-header-actions" class="sp-header-actions">
                        <a id="sprb-back-link" class="button" href="<?php echo esc_url( $overview_url ); ?>">Back to Overview</a>
                        <div id="sprb-header-actions-right" class="sp-header-actions-right">
                            <button type="button" id="sprb-backup-now-btn" class="button" <?php disabled( ! $writable ); ?>>Backup Now</button>
                            <button type="button" id="sprb-open-settings-btn" class="button" data-open-modal="sprb-settings-modal">Settings</button>
                            <button type="button" id="sprb-save-settings-btn" class="button button-primary">Save Settings</button>
                        </div>
                    </div>
                </header>
                <form id="sprb-header-save-form" method="post" style="display:none;">
                    <?php wp_nonce_field( 'sprb_schedule', 'sprb_schedule_nonce', false ); ?>
                    <?php wp_nonce_field( 'sprb_remote', 'sprb_remote_nonce', false ); ?>
                    <?php wp_nonce_field( 'sprb_pull_access', 'sprb_pull_access_nonce', false ); ?>
                    <?php wp_referer_field(); ?>
                    <input type="hidden" name="sprb_save_settings" id="sprb-save-settings-flag" value="1">
                    <div id="sprb-header-save-payload"></div>
                </form>

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice HTML is built by notice_html(). ?>
                <?php echo $this->notice; ?>

                <?php if ( ! $writable ) : ?>
                    <div id="sprb-storage-notice" class="notice notice-error"><strong id="sprb-storage-notice-title">Storage not writable.</strong> Backups cannot run until <code id="sprb-storage-path"><?php echo esc_html( SPRB_STORAGE_DIR ); ?></code> is writable.</div>
                <?php endif; ?>

                <div id="sprb-backup-popup-overlay" class="sp-modal-overlay" style="display:none;">
                    <div id="sprb-backup-popup" class="sp-modal">
                        <div id="sprb-backup-popup-header" class="sp-modal__header">
                            <h3 id="sprb-backup-popup-title" class="sp-modal__title">Backup Now</h3>
                            <button type="button" id="sprb-backup-popup-close" class="sp-modal__close" aria-label="Close">&times;</button>
                        </div>
                        <div id="sprb-backup-popup-body" class="sp-modal__body">
                            <p id="sprb-backup-popup-desc" class="sp-modal__desc">Choose what to back up:</p>
                            <div id="sprb-backup-popup-options" class="sp-modal__options">
                                <label id="sprb-backup-opt-database" class="sp-modal__option" for="sprb-backup-scope-database">
                                    <input type="radio" id="sprb-backup-scope-database" name="sprb_backup_scope" value="database" checked>
                                    <span id="sprb-backup-opt-database-icon" class="dashicons dashicons-database"></span>
                                    <span id="sprb-backup-opt-database-text">Database Only</span>
                                </label>
                                <label id="sprb-backup-opt-files" class="sp-modal__option" for="sprb-backup-scope-files">
                                    <input type="radio" id="sprb-backup-scope-files" name="sprb_backup_scope" value="files">
                                    <span id="sprb-backup-opt-files-icon" class="dashicons dashicons-media-archive"></span>
                                    <span id="sprb-backup-opt-files-text">Files Only</span>
                                </label>
                                <label id="sprb-backup-opt-both" class="sp-modal__option" for="sprb-backup-scope-both">
                                    <input type="radio" id="sprb-backup-scope-both" name="sprb_backup_scope" value="both">
                                    <span id="sprb-backup-opt-both-icon" class="dashicons dashicons-admin-site-alt3"></span>
                                    <span id="sprb-backup-opt-both-text">Everything</span>
                                </label>
                            </div>
                            <?php if ( $remote_ready ) : ?>
                                <hr id="sprb-backup-popup-divider" class="sp-modal__divider">
                                <label id="sprb-backup-opt-remote" class="sp-modal__option sp-modal__option--remote" for="sprb_backup_send_remote">
                                    <input type="checkbox" id="sprb_backup_send_remote" value="1">
                                    <span id="sprb-backup-opt-remote-icon" class="dashicons dashicons-cloud-upload"></span>
                                    <span id="sprb-backup-opt-remote-text">Also send to <?php echo esc_html( $remote_label ); ?></span>
                                </label>
                                <p id="sprb-backup-remote-note" class="description" style="margin-top:4px;">The backup is created locally first, then uploaded to remote storage.</p>
                            <?php endif; ?>
                        </div>
                        <div id="sprb-backup-popup-footer" class="sp-modal__footer">
                            <button type="button" id="sprb-backup-popup-cancel" class="button">Cancel</button>
                            <button type="button" id="sprb-backup-popup-start" class="button button-primary">Start Backup</button>
                        </div>
                    </div>
                </div>

                <div id="sprb-auth-modal-overlay" class="sp-modal-overlay" style="display:none;">
                    <div id="sprb-auth-modal" class="sp-modal">
                        <div id="sprb-auth-modal-header" class="sp-modal__header">
                            <h3 id="sprb-auth-modal-title" class="sp-modal__title">Authorize Cloud Storage</h3>
                            <button type="button" id="sprb-auth-modal-close" class="sp-modal__close" aria-label="Close">&times;</button>
                        </div>
                        <form id="sprb-auth-modal-form">
                            <div id="sprb-auth-modal-body" class="sp-modal__body">
                                <p id="sprb-auth-modal-step1" class="sp-modal__desc"><strong id="sprb-auth-modal-step1-label">Step 1</strong> &mdash; Open the authorization page and approve access.</p>
                                <p id="sprb-auth-modal-link-wrap" style="margin-top:8px;">
                                    <a id="sprb-auth-modal-link" href="#" target="_blank" rel="noopener" class="button">Open Authorization Page</a>
                                </p>
                                <hr id="sprb-auth-modal-divider" class="sp-modal__divider">
                                <p id="sprb-auth-modal-step2" class="sp-modal__desc"><strong id="sprb-auth-modal-step2-label">Step 2</strong> &mdash; Paste the authorization code from the redirect URL.</p>
                                <input type="text" id="sprb-auth-modal-code" class="regular-text" placeholder="Paste authorization code here" style="margin-top:8px;width:100%;">
                                <span id="sprb-auth-modal-status" style="display:block;margin-top:8px;"></span>
                                <hr id="sprb-auth-modal-divider2" class="sp-modal__divider">
                                <p id="sprb-auth-modal-custom-toggle-wrap">
                                    <a id="sprb-auth-modal-custom-toggle" href="#" style="font-size:12px;">Use custom OAuth credentials</a>
                                </p>
                                <div id="sprb-auth-modal-custom-fields" style="display:none;margin-top:8px;">
                                    <label id="sprb-auth-modal-client-id-label" for="sprb-auth-modal-client-id" style="display:block;font-size:12px;margin-bottom:4px;">Client ID</label>
                                    <input type="text" id="sprb-auth-modal-client-id" class="regular-text" style="width:100%;" placeholder="Leave blank to use built-in credentials">
                                    <label id="sprb-auth-modal-client-secret-label" for="sprb-auth-modal-client-secret" style="display:block;font-size:12px;margin-top:8px;margin-bottom:4px;">Client Secret</label>
                                    <input type="password" id="sprb-auth-modal-client-secret" class="regular-text" style="width:100%;" placeholder="Leave blank to use built-in credentials" autocomplete="off">
                                </div>
                            </div>
                            <div id="sprb-auth-modal-footer" class="sp-modal__footer">
                                <button type="button" id="sprb-auth-modal-cancel" class="button">Cancel</button>
                                <button type="button" id="sprb-auth-modal-test" class="button" style="display:none;">Test Connection</button>
                                <button type="button" id="sprb-auth-modal-submit" class="button button-primary">Submit Code</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns controlled admin markup. ?>
                <?php echo $this->render_backup_summary_cards( $summary ); ?>

                <!-- ── Settings Modal (tabbed) ─────────────────── -->
                <div id="sprb-settings-modal" class="sp-modal-overlay" style="display:none;">
                    <div id="sprb-settings-modal-dialog" class="sp-modal sp-modal--wide">
                        <div id="sprb-settings-modal-header" class="sp-modal__header">
                            <h3 id="sprb-settings-modal-title" class="sp-modal__title">
                                <span id="sprb-settings-modal-icon" class="dashicons dashicons-admin-generic"></span>
                                Backup Settings
                            </h3>
                            <button type="button" id="sprb-settings-modal-close" class="sp-modal__close" aria-label="Close">&times;</button>
                        </div>

                        <div id="sprb-settings-tabs" class="sp-modal-tabs">
                            <button type="button" id="sprb-tab-database" class="sp-tab-button active" data-tab="sprb-panel-database">Database</button>
                            <button type="button" id="sprb-tab-files-schedule" class="sp-tab-button" data-tab="sprb-panel-files-schedule">Files</button>
                            <button type="button" id="sprb-tab-files" class="sp-tab-button" data-tab="sprb-panel-files">File Selection</button>
                            <button type="button" id="sprb-tab-remote" class="sp-tab-button" data-tab="sprb-panel-remote">Remote Storage</button>
                            <button type="button" id="sprb-tab-pull" class="sp-tab-button" data-tab="sprb-panel-pull">Pull Access</button>
                        </div>

                        <!-- Tab 1: Database Schedule -->
                        <div id="sprb-panel-database" class="sp-tab-content active">
                            <?php if ( 'none' === $sched_db_freq ) : ?>
                                <div id="sprb-db-notice" class="sp-notice sp-notice--error"><strong id="sprb-db-notice-icon">Database backups</strong> are currently disabled.</div>
                            <?php endif; ?>
                            <form id="sprb-schedule-form-db" method="post">
                                <?php wp_nonce_field( 'sprb_schedule', 'sprb_schedule_nonce', false ); ?>
                                <?php wp_referer_field(); ?>
                                <div id="sprb-db-row-freq-time" class="sp-form-row">
                                    <div id="sprb-db-frequency-field" class="sp-form-group">
                                        <label id="sprb-db-frequency-label" class="sp-form-label" for="sprb_schedule_database_frequency">Frequency</label>
                                        <select name="sprb_schedule_database_frequency" id="sprb_schedule_database_frequency" class="sp-select">
                                            <option value="none" <?php selected( $sched_db_freq, 'none' ); ?>>Disabled</option>
                                            <option value="hourly" <?php selected( $sched_db_freq, 'hourly' ); ?>>Every Hour</option>
                                            <option value="sprb_every_6h" <?php selected( $sched_db_freq, 'sprb_every_6h' ); ?>>Every 6 Hours</option>
                                            <option value="sprb_every_12h" <?php selected( $sched_db_freq, 'sprb_every_12h' ); ?>>Every 12 Hours</option>
                                            <option value="twicedaily" <?php selected( $sched_db_freq, 'twicedaily' ); ?>>Twice Daily</option>
                                            <option value="daily" <?php selected( $sched_db_freq, 'daily' ); ?>>Daily</option>
                                            <option value="weekly" <?php selected( $sched_db_freq, 'weekly' ); ?>>Weekly</option>
                                        </select>
                                    </div>
                                    <div id="sprb-db-time-field" class="sp-form-group">
                                        <label id="sprb-db-time-label" class="sp-form-label" for="sprb_schedule_database_time">Time</label>
                                        <input type="time" name="sprb_schedule_database_time" id="sprb_schedule_database_time" class="sp-input sp-input-time" value="<?php echo esc_attr( $sched_db_time ); ?>" step="60">

                                    </div>
                                </div>
                                <div id="sprb-db-weekday-field" class="sp-form-group" <?php echo 'weekly' !== $sched_db_freq ? 'style="display:none"' : ''; ?>>
                                    <label id="sprb-db-weekday-label" class="sp-form-label" for="sprb_schedule_database_weekday">Day</label>
                                    <select name="sprb_schedule_database_weekday" id="sprb_schedule_database_weekday" class="sp-select">
                                        <?php foreach ( $weekday_options as $weekday_value => $weekday_label ) : ?>
                                            <option value="<?php echo esc_attr( $weekday_value ); ?>" <?php selected( $sched_db_weekday, $weekday_value ); ?>><?php echo esc_html( $weekday_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p id="sprb-db-weekday-help" class="sp-form-description">Used only when Frequency is set to Weekly.</p>
                                </div>
                                <div id="sprb-db-row-retain-delivery" class="sp-form-row">
                                    <div id="sprb-db-retention-field" class="sp-form-group">
                                        <label id="sprb-db-retention-label" class="sp-form-label" for="sprb_retain_db">Retention</label>
                                        <input type="number" name="sprb_retain_db" id="sprb_retain_db" class="sp-input sp-input-number" value="<?php echo esc_attr( $retain_db ); ?>" min="0" max="100">

                                    </div>
                                    <div id="sprb-db-delivery-field" class="sp-form-group">
                                        <label id="sprb-db-delivery-label" class="sp-form-label" for="sprb_scheduled_remote_mode_database">Scheduled Delivery</label>
                                        <select name="sprb_scheduled_remote_mode_database" id="sprb_scheduled_remote_mode_database" class="sp-select">
                                            <option value="local" <?php selected( $sched_remote_db, 'local' ); ?>>Backup normally</option>
                                            <option value="remote" <?php selected( $sched_remote_db, 'remote' ); ?> <?php disabled( ! $remote_ready ); ?>>Backup + send to remote storage</option>
                                        </select>

                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Tab 2: Files Schedule -->
                        <div id="sprb-panel-files-schedule" class="sp-tab-content">
                            <?php if ( 'none' === $sched_files_freq ) : ?>
                                <div id="sprb-files-schedule-notice" class="sp-notice sp-notice--error"><strong id="sprb-files-notice-icon">File backups</strong> are currently disabled.</div>
                            <?php endif; ?>
                            <form id="sprb-schedule-form-files" method="post">
                                <?php wp_nonce_field( 'sprb_schedule', 'sprb_schedule_nonce_files', false ); ?>
                                <?php wp_referer_field(); ?>
                                <div id="sprb-files-row-freq-time" class="sp-form-row">
                                    <div id="sprb-files-frequency-field" class="sp-form-group">
                                        <label id="sprb-files-frequency-label" class="sp-form-label" for="sprb_schedule_files_frequency">Frequency</label>
                                        <select name="sprb_schedule_files_frequency" id="sprb_schedule_files_frequency" class="sp-select">
                                            <option value="none" <?php selected( $sched_files_freq, 'none' ); ?>>Disabled</option>
                                            <option value="hourly" <?php selected( $sched_files_freq, 'hourly' ); ?>>Every Hour</option>
                                            <option value="sprb_every_6h" <?php selected( $sched_files_freq, 'sprb_every_6h' ); ?>>Every 6 Hours</option>
                                            <option value="sprb_every_12h" <?php selected( $sched_files_freq, 'sprb_every_12h' ); ?>>Every 12 Hours</option>
                                            <option value="twicedaily" <?php selected( $sched_files_freq, 'twicedaily' ); ?>>Twice Daily</option>
                                            <option value="daily" <?php selected( $sched_files_freq, 'daily' ); ?>>Daily</option>
                                            <option value="weekly" <?php selected( $sched_files_freq, 'weekly' ); ?>>Weekly</option>
                                        </select>
                                    </div>
                                    <div id="sprb-files-time-field" class="sp-form-group">
                                        <label id="sprb-files-time-label" class="sp-form-label" for="sprb_schedule_files_time">Time</label>
                                        <input type="time" name="sprb_schedule_files_time" id="sprb_schedule_files_time" class="sp-input sp-input-time" value="<?php echo esc_attr( $sched_files_time ); ?>" step="60">

                                    </div>
                                </div>
                                <div id="sprb-files-weekday-field" class="sp-form-group" <?php echo 'weekly' !== $sched_files_freq ? 'style="display:none"' : ''; ?>>
                                    <label id="sprb-files-weekday-label" class="sp-form-label" for="sprb_schedule_files_weekday">Day</label>
                                    <select name="sprb_schedule_files_weekday" id="sprb_schedule_files_weekday" class="sp-select">
                                        <?php foreach ( $weekday_options as $weekday_value => $weekday_label ) : ?>
                                            <option value="<?php echo esc_attr( $weekday_value ); ?>" <?php selected( $sched_files_weekday, $weekday_value ); ?>><?php echo esc_html( $weekday_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p id="sprb-files-weekday-help" class="sp-form-description">Used only when Frequency is set to Weekly.</p>
                                </div>
                                <div id="sprb-files-row-retain-delivery" class="sp-form-row">
                                    <div id="sprb-files-retention-field" class="sp-form-group">
                                        <label id="sprb-files-retention-label" class="sp-form-label" for="sprb_retain_files">Retention</label>
                                        <input type="number" name="sprb_retain_files" id="sprb_retain_files" class="sp-input sp-input-number" value="<?php echo esc_attr( $retain_files ); ?>" min="0" max="100">

                                    </div>
                                    <div id="sprb-files-delivery-field" class="sp-form-group">
                                        <label id="sprb-files-delivery-label" class="sp-form-label" for="sprb_scheduled_remote_mode_files">Scheduled Delivery</label>
                                        <select name="sprb_scheduled_remote_mode_files" id="sprb_scheduled_remote_mode_files" class="sp-select">
                                            <option value="local" <?php selected( $sched_remote_files, 'local' ); ?>>Backup normally</option>
                                            <option value="remote" <?php selected( $sched_remote_files, 'remote' ); ?> <?php disabled( ! $remote_ready ); ?>>Backup + send to remote storage</option>
                                        </select>

                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Tab 3: Remote Storage -->
                        <div id="sprb-panel-remote" class="sp-tab-content">
                            <form id="sprb-remote-form" method="post">
                                <?php wp_nonce_field( 'sprb_remote', 'sprb_remote_nonce', false ); ?>
                                <?php wp_referer_field(); ?>
                                <?php foreach ( $providers as $key => $provider ) : ?>
                                    <div id="sprb-remote-status-<?php echo esc_attr( $key ); ?>" class="sp-protocol-<?php echo esc_attr( $key ); ?>" <?php echo $key !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <?php $provider->render_status_banner(); ?>
                                    </div>
                                <?php endforeach; ?>
                                <div id="sprb-remote-provider-field" class="sp-form-group">
                                    <label id="sprb-remote-provider-label" class="sp-form-label" for="sprb_remote_protocol">Provider</label>
                                    <select name="sprb_remote_protocol" id="sprb_remote_protocol" class="sp-select">
                                        <?php foreach ( $providers as $key => $provider ) : ?>
                                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $remote_protocol, $key ); ?>><?php echo esc_html( $provider->get_label() ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <table id="sprb-remote-table" class="form-table sp-form-table">
                                    <?php foreach ( $providers as $key => $provider ) : ?>
                                        <tbody id="sprb-remote-fields-<?php echo esc_attr( $key ); ?>" class="sp-protocol-<?php echo esc_attr( $key ); ?>" <?php echo $key !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                            <?php $provider->render_settings_fields( $provider->get_settings() ); ?>
                                        </tbody>
                                    <?php endforeach; ?>
                                </table>
                                <div id="sprb-remote-actions" style="margin-top:12px;display:flex;gap:8px;">
                                    <button type="submit" id="sprb-test-remote-btn" name="sprb_test_remote" value="1" class="button" form="sprb-remote-form">Test Connection</button>
                                </div>
                            </form>
                        </div>

                        <!-- Tab 4: Pull Access -->
                        <div id="sprb-panel-pull" class="sp-tab-content">
                            <div id="sprb-pull-status-notice" class="sp-notice sp-notice--success"><strong>Pull access</strong> is enabled for this site.</div>
                            <form id="sprb-pull-form" method="post">
                                <?php wp_nonce_field( 'sprb_pull_access', 'sprb_pull_access_nonce', false ); ?>
                                <?php wp_referer_field(); ?>
                                <div id="sprb-pull-token-field" class="sp-form-group">
                                    <label id="sprb-pull-token-label" class="sp-form-label">Pull Token</label>
                                    <div id="sprb-pull-token-display" class="sp-code"><?php echo esc_html( $pull_token ); ?></div>
                                    <p id="sprb-pull-token-help" class="sp-form-description">The monitor plugin sends this token as <code id="sprb-pull-token-header">X-RB-Pull-Token</code> when it reads the catalog and downloads backup artifacts.</p>
                                </div>
                                <div id="sprb-pull-actions" class="sp-form-group">
                                    <label id="sprb-pull-actions-label" class="sp-form-label">Token Actions</label>
                                    <div id="sprb-pull-actions-row" style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <button type="button" id="sprb-copy-token-btn" class="button">Copy Token</button>
                                        <button type="submit" id="sprb-regenerate-pull-token-btn" name="sprb_regenerate_pull_token" value="1" class="button" form="sprb-pull-form" onclick="return confirm('Regenerate the pull token? Existing monitors will stop working until updated.');">Regenerate</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Tab 5: File Selection -->
                        <div id="sprb-panel-files" class="sp-tab-content">
                            <div id="sprb-files-notice" class="sp-notice">Select which files and folders to include in file backups.</div>
                            <div id="sprb-files-toolbar">
                                <div id="sprb-files-bulk-actions" class="sp-actions">
                                    <button type="button" class="button button-small" id="sp-folders-all">Select All</button>
                                    <button type="button" class="button button-small" id="sp-folders-none">Deselect All</button>
                                </div>
                            </div>
                            <div id="sp-folder-picker">
                                <div id="sp-folder-tree" class="sp-folder-tree">
                                    <?php foreach ( $tree as $dir => $has_children ) : ?>
                                        <?php $dir_slug = sanitize_title( $dir ); ?>
                                        <div id="sp-node-<?php echo esc_attr( $dir_slug ); ?>" class="sp-tree-node<?php echo $has_children ? ' sp-tree-node--parent' : ''; ?>" data-path="<?php echo esc_attr( $dir ); ?>">
                                            <div id="sp-row-<?php echo esc_attr( $dir_slug ); ?>" class="sp-tree-row">
                                                <?php if ( $has_children ) : ?>
                                                    <span id="sp-toggle-<?php echo esc_attr( $dir_slug ); ?>" class="sp-tree-toggle"><span class="dashicons dashicons-arrow-right-alt2"></span></span>
                                                <?php else : ?>
                                                    <span id="sp-spacer-<?php echo esc_attr( $dir_slug ); ?>" class="sp-tree-spacer"></span>
                                                <?php endif; ?>
                                                <label id="sp-label-<?php echo esc_attr( $dir_slug ); ?>" class="sp-folder-item" for="sp-folder-cb-<?php echo esc_attr( $dir_slug ); ?>">
                                                    <input type="checkbox" id="sp-folder-cb-<?php echo esc_attr( $dir_slug ); ?>" class="sp-folder-cb" value="<?php echo esc_attr( $dir ); ?>" <?php checked( $is_saved( $dir ) ); ?> data-has-children="<?php echo $has_children ? '1' : '0'; ?>">
                                                    <span id="sp-folder-icon-<?php echo esc_attr( $dir_slug ); ?>" class="dashicons dashicons-portfolio"></span>
                                                    <span id="sp-folder-text-<?php echo esc_attr( $dir_slug ); ?>"><?php echo esc_html( $dir ); ?>/</span>
                                                </label>
                                            </div>
                                            <?php if ( $has_children ) : ?>
                                                <div id="sp-children-<?php echo esc_attr( $dir_slug ); ?>" class="sp-tree-children" style="display:none;"></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php foreach ( $root_files as $file ) : ?>
                                        <?php $file_slug = sanitize_title( $file ); ?>
                                        <div id="sp-file-<?php echo esc_attr( $file_slug ); ?>" class="sp-tree-node sp-tree-node--file" data-path="<?php echo esc_attr( $file ); ?>">
                                            <div id="sp-file-row-<?php echo esc_attr( $file_slug ); ?>" class="sp-tree-row">
                                                <span id="sp-file-spacer-<?php echo esc_attr( $file_slug ); ?>" class="sp-tree-spacer"></span>
                                                <label id="sp-label-file-<?php echo esc_attr( $file_slug ); ?>" class="sp-file-item" for="sp-file-cb-<?php echo esc_attr( $file_slug ); ?>">
                                                    <input type="checkbox" id="sp-file-cb-<?php echo esc_attr( $file_slug ); ?>" class="sp-folder-cb" value="<?php echo esc_attr( $file ); ?>" <?php checked( $is_saved( $file ) ); ?>>
                                                    <span id="sp-file-icon-<?php echo esc_attr( $file_slug ); ?>" class="dashicons dashicons-media-default"></span>
                                                    <span id="sp-file-text-<?php echo esc_attr( $file_slug ); ?>"><?php echo esc_html( $file ); ?></span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div id="sprb-files-save-row" style="margin-top:12px;display:flex;align-items:center;gap:8px;">
                                <button type="button" id="sp-folders-save" class="button button-primary">Save Selection</button>
                                <span id="sp-folders-saved" class="sp-folders-saved" style="display:none;">Saved</span>
                                <span id="sprb-files-count" class="sp-badge sp-badge--neutral" style="margin-left:auto;"><?php echo esc_html( empty( $saved_folders ) ? 'All items included' : sprintf( '%d saved item%s', count( $saved_folders ), 1 === count( $saved_folders ) ? '' : 's' ) ); ?></span>
                            </div>
                        </div>

                        <div id="sprb-settings-modal-footer" class="sp-modal__footer">
                            <button type="button" id="sprb-settings-modal-cancel" class="button">Cancel</button>
                            <button type="button" id="sprb-settings-modal-save" class="button button-primary">Save Changes</button>
                        </div>
                    </div>
                </div>

                <div id="sprb-content-stack" class="sp-stack">

                <div id="sp-progress-overlay" class="sp-progress-overlay" style="display:none;">
                    <span id="sp-progress-icon" class="dashicons dashicons-update sp-spin"></span>
                    <span id="sp-progress-text">Starting backup…</span>
                </div>

                <section id="sprb-history-section">
                    <div id="sprb-history-header" class="sp-card__header">
                        <div id="sprb-history-header-main">
                            <h2 id="sprb-history-title" class="sp-card__title">Backup History</h2>
                        </div>
                        <span id="sprb-history-count" class="sp-badge sp-badge--neutral"><?php echo esc_html( count( $backups ) . ' items' ); ?></span>
                    </div>
                    <div id="sprb-history-card" class="sp-card sp-card--history">
                        <div id="sprb-history-card-body" class="sp-card__body sp-card__body--flush">
                        <?php if ( empty( $backups ) ) : ?>
                            <div id="sprb-history-empty" class="sp-empty">
                                <h2>No backups yet</h2>
                                <p>Use the buttons above to create one.</p>
                            </div>
                        <?php else : ?>
                            <div id="sprb-history-table-wrap" class="sp-table-wrap">
                                <table id="sprb-history-table" class="sp-table">
                                    <thead id="sprb-history-thead">
                                        <tr id="sprb-history-header-row">
                                            <th>Date</th>
                                            <th>Scope</th>
                                            <th>Status</th>
                                            <th>Remote</th>
                                            <th>Total Size</th>
                                            <th>DB Size</th>
                                            <th></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="sprb-history-body">
                                        <?php foreach ( array_reverse( $backups ) as $b ) : ?>
                                            <?php
                                            $status         = $b['status'] ?? 'success';
                                            $status_variant = $this->badge_variant_for_status( $status );
                                            $remote_status  = $b['remote_status'] ?? null;
                                            $remote_variant = $remote_status ? $this->badge_variant_for_status( 'skipped' === $remote_status ? 'warning' : $remote_status ) : 'neutral';
                                            $remote_message = $b['remote_message'] ?? '';
                                            $remote_title   = array_filter(
                                                array(
                                                    $remote_message,
                                                    ! empty( $b['remote_uploaded_at'] ) ? 'Uploaded: ' . $b['remote_uploaded_at'] : '',
                                                    ! empty( $b['remote_destination'] ) ? 'Target: ' . $b['remote_destination'] : '',
                                                )
                                            );
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html( $b['date'] ); ?></td>
                                                <td><span class="sp-badge sp-badge--<?php echo esc_attr( $this->badge_variant_for_scope( $b['scope'] ?? '' ) ); ?>"><?php echo esc_html( $this->label_for_scope( $b['scope'] ?? '' ) ); ?></span></td>
                                                <td>
                                                    <span class="sp-badge sp-badge--<?php echo esc_attr( $status_variant ); ?>"><?php echo esc_html( $this->label_for_status( $status ) ); ?></span>
                                                    <?php if ( 'failed' === $status && ! empty( $b['error'] ) ) : ?>
                                                        <span class="sp-status-info" title="<?php echo esc_attr( $b['error'] ); ?>">ⓘ</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ( $remote_status ) : ?>
                                                        <span class="sp-badge sp-badge--<?php echo esc_attr( $remote_variant ); ?>"><?php echo esc_html( ucfirst( 'success' === $remote_status ? 'Uploaded' : (string) $remote_status ) ); ?></span>
                                                        <?php if ( ! empty( $remote_title ) ) : ?>
                                                            <span class="sp-status-info" title="<?php echo esc_attr( implode( "\n", $remote_title ) ); ?>">ⓘ</span>
                                                        <?php endif; ?>
                                                    <?php else : ?>
                                                        <span class="sp-badge sp-badge--neutral">Local only</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html( size_format( $b['total_size'] ) ); ?></td>
                                                <td><?php echo $b['db_size'] ? esc_html( size_format( $b['db_size'] ) ) : '—'; ?></td>
                                                <td>
                                                    <?php if ( ! empty( $b['db_file'] ) || ! empty( $b['files_file'] ) || ! empty( $b['plugins_file'] ) ) : ?>
                                                        <a id="sprb-download-<?php echo esc_attr( $b['id'] ); ?>" href="<?php echo esc_url( $this->downloads->download_url( $b['id'], ! empty( $b['db_file'] ) ? 'database' : ( ! empty( $b['files_file'] ) ? 'files' : 'plugins' ) ) ); ?>" class="sp-link">Download</a>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a id="sprb-delete-<?php echo esc_attr( $b['id'] ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $this->backup_page_slug() . '&rb_delete=' . urlencode( $b['id'] ) ), 'sprb_delete' ) ); ?>" class="sp-link sp-link--danger" onclick="return confirm('Delete this backup and its files?');">Delete</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section id="sprb-log-section">
                    <div id="sprb-log-header" class="sp-card__header">
                        <div id="sprb-log-header-main">
                            <h2 id="sprb-log-title" class="sp-card__title">Debug Log</h2>
                        </div>
                    </div>
                    <div id="sprb-log-card" class="sp-card sp-card--log">
                        <div id="sprb-log-body" class="sp-card__body sp-card__body--flush">
                            <?php $log = $this->logger->get_log(); ?>
                            <?php if ( $log ) : ?>
                                <pre id="sprb-log-output" class="sp-log"><?php echo esc_html( $log ); ?></pre>
                            <?php else : ?>
                                <p id="sprb-log-empty" class="sp-empty">No log entries yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ( $log ) : ?>
                        <form id="sprb-log-clear-form" method="post" class="sp-log-actions">
                            <?php wp_nonce_field( 'sprb_log' ); ?>
                            <input type="hidden" name="sprb_clear_log" value="1">
                            <button id="sprb-log-clear-btn" type="submit" class="button button-small" onclick="return confirm('Clear the entire log?');">Clear Log</button>
                        </form>
                    <?php endif; ?>
                </section>

                </div>
        <?php
        savedpixel_admin_page_end();
    }


    /* ── Monitor page ─────────────────────────────────── */

    public function render_monitor_page() {
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_monitor_features() ) {
            return;
        }

        $state            = $this->monitor_page_state();
        $sites            = $state['sites'];
        $statuses         = $state['statuses'];
        $summary          = $state['summary'];
        $monitor_settings = $this->monitor->get_settings();

        wp_localize_script( 'spsprb-admin', 'rbAdmin', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'sprb_ajax' ),
            'backupPageUrl'    => admin_url( 'admin.php?page=' . $this->backup_page_slug() ),
            'activeJobId'      => '',
            'activeJobStatus'  => 'idle',
            'monitor'          => array(
                'enabled'          => true,
                'snapshotInterval' => 5000,
                'progressInterval' => 1000,
                'active'           => ! empty( $state['active'] ),
            ),
        ) );

        $overview_url = function_exists( 'savedpixel_admin_page_url' )
            ? savedpixel_admin_page_url( 'savedpixel' )
            : admin_url( 'admin.php?page=savedpixel' );
        ?><?php savedpixel_admin_page_start(); ?>
                <header class="sp-page-header">
                    <div>
                        <h1 class="sp-page-title">SavedPixel Backup Monitor</h1>
                        <p class="sp-page-desc">Monitor connected sites, inspect recent health checks, and manage centralized backup polling from one screen.</p>
                    </div>
                    <div class="sp-header-actions">
                        <a class="button" href="<?php echo esc_url( $overview_url ); ?>">Back to Overview</a>
                    </div>
                </header>

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice HTML is built by notice_html(). ?>
                <?php echo $this->notice; ?>

                <div id="sp-monitor-summary"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns controlled admin markup. ?><?php echo $this->render_monitor_summary_cards( $summary ); ?></div>
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns controlled admin markup. ?>
                <?php echo $this->render_monitor_progress_panel( $state['progress_items'] ); ?>

                <div class="sp-monitor-layout">
                    <div class="sp-card sp-card--monitor-settings">
                        <div class="sp-card__body">
                            <h2>Monitor Settings</h2>
                            <form method="post">
                                <?php wp_nonce_field( 'sprb_monitor' ); ?>
                                <table class="form-table sp-form-table sp-u-mt-6">
                                    <tr>
                                        <th><label for="sprb_monitor_retry_minutes">Poll Delay</label></th>
                                        <td>
                                            <input type="number" name="sprb_monitor_retry_minutes" id="sprb_monitor_retry_minutes" class="small-text" min="5" max="240" value="<?php echo esc_attr( $monitor_settings['retry_minutes'] ); ?>">
                                            <p class="description">Minutes after the scheduled backup time before the monitor checks that site.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="sprb_monitor_watch_minutes">Watch Window</label></th>
                                        <td>
                                            <input type="number" name="sprb_monitor_watch_minutes" id="sprb_monitor_watch_minutes" class="small-text" min="5" max="720" value="<?php echo esc_attr( $monitor_settings['watch_minutes'] ); ?>">
                                            <p class="description">Minutes after the scheduled run before reporting no response if that poll still finds no completed backup.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="sprb_monitor_notification_email">Notify Email</label></th>
                                        <td>
                                            <input type="text" name="sprb_monitor_notification_email" id="sprb_monitor_notification_email" class="regular-text" value="<?php echo esc_attr( $monitor_settings['notification_email'] ); ?>">
                                            <p class="description">Comma-separated emails are allowed. Leave blank to fall back to the site admin email.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p><button type="submit" name="sprb_save_monitor_settings" value="1" class="button button-primary">Save Monitor Settings</button></p>
                            </form>

                            <hr class="sp-divider">
                            <div class="sp-monitor-inline-actions">
                                <div>
                                    <h3 class="sp-segment-title sp-u-mt-0">Actions</h3>
                                    <p class="sp-desc">The monitor cron runs every five minutes, but each site is only checked when its synced database or files schedule says it is due. Polling refreshes status and schedules only. Use <code>Pull DB</code> or <code>Pull Files</code> to download artifacts.</p>
                                </div>
                                <form method="post">
                                    <?php wp_nonce_field( 'sprb_monitor' ); ?>
                                    <button type="submit" name="sprb_poll_all" value="1" class="button button-primary sp-monitor-action" data-monitor-action="poll_all" data-url="" <?php disabled( empty( $sites ) ); ?>>Poll All Now</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="sp-card sp-card--monitor-add">
                        <div class="sp-card__body">
                            <h2>Add Site</h2>
                            <form method="post">
                                <?php wp_nonce_field( 'sprb_monitor' ); ?>
                                <table class="form-table sp-form-table sp-u-mt-6">
                                    <tr>
                                        <th><label for="sprb_site_url">Site URL</label></th>
                                        <td><input type="url" name="sprb_site_url" id="sprb_site_url" class="regular-text" placeholder="https://example.com" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="sprb_site_label">Label</label></th>
                                        <td><input type="text" name="sprb_site_label" id="sprb_site_label" class="regular-text" placeholder="My Site (optional)"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="sprb_site_pull_token">Pull Token</label></th>
                                        <td>
                                            <input type="text" name="sprb_site_pull_token" id="sprb_site_pull_token" class="regular-text code" placeholder="Optional: enables artifact downloads">
                                            <p class="description">Leave blank to monitor status only. Add the token from the client site to let this host pull completed backups. When you add a site, the monitor immediately syncs that site&rsquo;s database and files schedules and saves the next poll time from the remote configuration.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p><button type="submit" name="sprb_add_site" value="1" class="button button-primary">Add Site</button></p>
                            </form>
                        </div>
                    </div>
                </div>

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns controlled admin markup. ?>
                <?php echo $this->render_monitor_sites_table( $sites, $statuses ); ?>
        <?php
        savedpixel_admin_page_end();
    }

}
