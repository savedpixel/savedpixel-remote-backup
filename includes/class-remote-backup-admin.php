<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remote_Backup_Admin {

    const ASYNC_BACKUP_HOOK = 'rb_async_manual_backup';
    const ACTIVE_JOB_OPTION = 'rb_active_backup_job_id';

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
            add_action( 'wp_ajax_rb_run_backup', array( $this, 'ajax_run_backup' ) );
            add_action( 'wp_ajax_rb_backup_progress', array( $this, 'ajax_backup_progress' ) );
            add_action( 'wp_ajax_rb_backup_status', array( $this, 'ajax_backup_status' ) );
            add_action( 'wp_ajax_rb_save_folders', array( $this, 'ajax_save_folders' ) );

            $this->downloads->init();
        }

        if ( $this->has_monitor_features() && ! class_exists( 'Remote_Backup_Monitor_Admin' ) ) {
            add_action( 'wp_ajax_rb_monitor_action', array( $this, 'ajax_monitor_action' ) );
            add_action( 'wp_ajax_rb_monitor_snapshot', array( $this, 'ajax_monitor_snapshot' ) );
            add_action( 'wp_ajax_rb_monitor_progress', array( $this, 'ajax_monitor_progress' ) );
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

        wp_enqueue_style( 'rb-admin', $this->admin_asset_url( 'admin.css' ), array(), RB_VERSION );
        wp_enqueue_script( 'rb-admin', $this->admin_asset_url( 'admin.js' ), array(), RB_VERSION, true );
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
        return 'rb_backup_job_state_' . md5( (string) $job_id );
    }

    private function backup_job_payload_key( $job_id ) {
        return 'rb_backup_job_payload_' . md5( (string) $job_id );
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
            return new WP_Error( 'rb_backup_unavailable', 'Backup features are not available on this site.' );
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
                'rb_backup_running',
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
        if ( in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
            $current_phase = $this->runner->get_progress();
            if ( 'idle' !== $current_phase ) {
                $phase = $current_phase;
            }
        }

        return array(
            'jobId'      => $job_id,
            'status'     => $state['status'] ?? 'idle',
            'phase'      => $phase,
            'message'    => $state['message'] ?? '',
            'noticeType' => $state['notice_type'] ?? 'info',
            'backupId'   => $state['backup_id'] ?? null,
            'totalSize'  => $state['total_size'] ?? 0,
            'updatedAt'  => $state['updated_at'] ?? null,
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
        check_ajax_referer( 'rb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }
        $scope = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'both' ) );
        if ( ! in_array( $scope, array( 'database', 'files', 'both' ), true ) ) {
            $scope = 'both';
        }
        $remote_mode = $this->normalize_remote_mode(
            sanitize_text_field( wp_unslash( $_POST['remote_mode'] ?? get_option( 'rb_manual_remote_mode', 'local' ) ) )
        );
        update_option( 'rb_manual_remote_mode', $remote_mode );

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
        wp_send_json_success(
            array(
                'jobId'      => $job['jobId'],
                'status'     => $job['status'],
                'phase'      => $job['phase'],
                'message'    => $job['queuedImmediately']
                    ? 'Backup started. The page will update automatically.'
                    : 'Backup queued, but WordPress could not trigger the background worker immediately. If it does not start, check WP-Cron or loopback access.',
                'noticeType' => $job['noticeType'],
            )
        );
    }

    public function ajax_backup_progress() {
        check_ajax_referer( 'rb_ajax', '_nonce' );
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
        check_ajax_referer( 'rb_ajax', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) || ! $this->has_backup_features() ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
        wp_send_json_success( $this->get_backup_job_status_payload( $job_id ) );
    }

    public function ajax_save_folders() {
        check_ajax_referer( 'rb_ajax', '_nonce' );
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
        update_option( 'rb_backup_folders', $folders );
        wp_send_json_success();
    }

    public function ajax_monitor_action() {
        check_ajax_referer( 'rb_ajax', '_nonce' );
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
        check_ajax_referer( 'rb_ajax', '_nonce' );
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
        check_ajax_referer( 'rb_ajax', '_nonce' );
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

        $has_backup  = $this->has_backup_features();
        $has_monitor = $this->has_monitor_features();

        // Manual backup with explicit scope.
        if ( $has_backup && isset( $_POST['rb_manual_scope'] ) ) {
            check_admin_referer( 'rb_manual' );
            $scope = sanitize_text_field( wp_unslash( $_POST['rb_manual_scope'] ) );
            if ( ! in_array( $scope, array( 'database', 'files', 'both' ), true ) ) {
                $scope = 'both';
            }
            $remote_mode = $this->normalize_remote_mode(
                sanitize_text_field( wp_unslash( $_POST['rb_manual_remote_mode'] ?? get_option( 'rb_manual_remote_mode', 'local' ) ) )
            );
            update_option( 'rb_manual_remote_mode', $remote_mode );
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
        if ( $has_backup && isset( $_POST['rb_save_schedule'] ) ) {
            check_admin_referer( 'rb_schedule' );

            $db_frequency = $this->scheduler->sanitize_schedule_frequency( sanitize_text_field( wp_unslash( $_POST['rb_schedule_database_frequency'] ?? get_option( 'rb_schedule_database_frequency', 'none' ) ) ) );
            $db_time      = $this->scheduler->sanitize_schedule_time( sanitize_text_field( wp_unslash( $_POST['rb_schedule_database_time'] ?? get_option( 'rb_schedule_database_time', '02:00' ) ) ) );
            $db_weekday   = $this->scheduler->sanitize_schedule_weekday( sanitize_text_field( wp_unslash( $_POST['rb_schedule_database_weekday'] ?? get_option( 'rb_schedule_database_weekday', $this->scheduler->get_schedule_weekday( 'database' ) ) ) ) );
            $files_frequency = $this->scheduler->sanitize_schedule_frequency( sanitize_text_field( wp_unslash( $_POST['rb_schedule_files_frequency'] ?? get_option( 'rb_schedule_files_frequency', 'none' ) ) ) );
            $files_time      = $this->scheduler->sanitize_schedule_time( sanitize_text_field( wp_unslash( $_POST['rb_schedule_files_time'] ?? get_option( 'rb_schedule_files_time', '02:00' ) ) ) );
            $files_weekday   = $this->scheduler->sanitize_schedule_weekday( sanitize_text_field( wp_unslash( $_POST['rb_schedule_files_weekday'] ?? get_option( 'rb_schedule_files_weekday', $this->scheduler->get_schedule_weekday( 'files' ) ) ) ) );

            update_option( 'rb_schedule_database_frequency', $db_frequency );
            update_option( 'rb_schedule_database_time', $db_time );
            update_option( 'rb_schedule_database_weekday', $db_weekday );
            update_option( 'rb_schedule_files_frequency', $files_frequency );
            update_option( 'rb_schedule_files_time', $files_time );
            update_option( 'rb_schedule_files_weekday', $files_weekday );

            $scheduled_remote_mode = get_option( 'rb_scheduled_remote_mode', 'remote' );
            if ( isset( $_POST['rb_scheduled_remote_mode'] ) ) {
                $scheduled_remote_mode = $this->normalize_remote_mode( sanitize_text_field( wp_unslash( $_POST['rb_scheduled_remote_mode'] ) ) );
            }
            update_option( 'rb_scheduled_remote_mode', $scheduled_remote_mode );

            $retain_db = (int) get_option( 'rb_retain_db', 0 );
            if ( isset( $_POST['rb_retain_db'] ) ) {
                $retain_db = absint( $_POST['rb_retain_db'] );
            }
            update_option( 'rb_retain_db', $retain_db );
            $retain_files = (int) get_option( 'rb_retain_files', 0 );
            if ( isset( $_POST['rb_retain_files'] ) ) {
                $retain_files = absint( $_POST['rb_retain_files'] );
            }
            update_option( 'rb_retain_files', $retain_files );

            $this->scheduler->reschedule();
            $this->logger->log(
                'Schedule settings saved — db: ' . $db_frequency .
                ( 'weekly' === $db_frequency ? ' on ' . $db_weekday : '' ) .
                " @ {$db_time}, files: {$files_frequency}" .
                ( 'weekly' === $files_frequency ? ' on ' . $files_weekday : '' ) .
                " @ {$files_time}, delivery: {$scheduled_remote_mode}, retain db: {$retain_db}, retain files: {$retain_files}"
            );
            $this->notice = $this->notice_html( 'Schedule settings saved.' );
        }

        // Save or test remote connection settings.
        if ( $has_backup && ( isset( $_POST['rb_save_connection'] ) || isset( $_POST['rb_test_remote'] ) ) ) {
            check_admin_referer( 'rb_remote' );
            $protocol = $this->save_remote_settings_from_request();
            $label    = $this->remote_protocol_label( $protocol );

            if ( isset( $_POST['rb_save_connection'] ) ) {
                $this->logger->log( "Remote connection settings saved — protocol: {$protocol}" );
                $this->notice = $this->notice_html( 'Remote connection settings saved.' );
            }

            if ( isset( $_POST['rb_test_remote'] ) ) {
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
        if ( $has_backup && isset( $_GET['rb_delete'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rb_delete' ) ) {
                $id = sanitize_text_field( wp_unslash( $_GET['rb_delete'] ) );
                $this->storage->delete_backup( $id );
                $this->logger->log( "Backup deleted: {$id}" );
                $this->notice = $this->notice_html( 'Backup deleted.' );
            }
        }

        // Install openssh-client.
        if ( $has_backup && isset( $_POST['rb_install_ssh'] ) ) {
            check_admin_referer( 'rb_remote' );
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
        if ( $has_backup && isset( $_POST['rb_save_pull_access'] ) ) {
            check_admin_referer( 'rb_pull_access' );

            $token = trim( sanitize_text_field( wp_unslash( $_POST['rb_pull_token'] ?? '' ) ) );
            if ( '' === $token ) {
                $token = Remote_Backup_Api::rotate_pull_token();
                $this->notice = $this->notice_html( 'Pull token was empty, so a new one was generated.' );
            } else {
                update_option( Remote_Backup_Api::TOKEN_OPTION, $token );
                $this->notice = $this->notice_html( 'Pull access settings saved.' );
            }

            $this->logger->log( 'Pull access settings saved.' );
        }

        if ( $has_backup && isset( $_POST['rb_regenerate_pull_token'] ) ) {
            check_admin_referer( 'rb_pull_access' );
            Remote_Backup_Api::rotate_pull_token();
            $this->logger->log( 'Pull token regenerated.' );
            $this->notice = $this->notice_html( 'Pull token regenerated. Update the monitor with the new token.' );
        }

        // Clear log.
        if ( isset( $_POST['rb_clear_log'] ) ) {
            check_admin_referer( 'rb_log' );
            $this->logger->clear();
            $this->notice = $this->notice_html( 'Debug log cleared.' );
        }

        // Monitor: Add site.
        if ( $has_monitor && isset( $_POST['rb_add_site'] ) ) {
            check_admin_referer( 'rb_monitor' );
            $url      = esc_url_raw( wp_unslash( $_POST['rb_site_url'] ?? '' ) );
            $label    = sanitize_text_field( wp_unslash( $_POST['rb_site_label'] ?? '' ) );
            $pull_key = trim( sanitize_text_field( wp_unslash( $_POST['rb_site_pull_token'] ?? '' ) ) );
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

        if ( $has_monitor && isset( $_POST['rb_save_monitor_settings'] ) ) {
            check_admin_referer( 'rb_monitor' );
            update_option( 'rb_monitor_retry_minutes', max( 5, absint( $_POST['rb_monitor_retry_minutes'] ?? 15 ) ) );
            update_option( 'rb_monitor_watch_minutes', max( 5, absint( $_POST['rb_monitor_watch_minutes'] ?? 90 ) ) );
            update_option( 'rb_monitor_notification_email', sanitize_text_field( wp_unslash( $_POST['rb_monitor_notification_email'] ?? '' ) ) );
            $this->notice = $this->notice_html( 'Monitor settings saved.' );
        }

        // Monitor: Remove site.
        if ( $has_monitor && isset( $_GET['rb_remove_site'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rb_monitor' ) ) {
                $url = esc_url_raw( wp_unslash( $_GET['rb_remove_site'] ) );
                $this->monitor->remove_site( $url );
                $this->notice = $this->notice_html( 'Site removed.' );
            }
        }

        // Monitor: Poll single site.
        if ( $has_monitor && isset( $_GET['rb_poll_site'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rb_monitor' ) ) {
                $url = esc_url_raw( wp_unslash( $_GET['rb_poll_site'] ) );
                $this->monitor->poll_site( $url );
                $this->notice = $this->notice_html( 'Site polled.' );
            }
        }

        if ( $has_monitor && isset( $_GET['rb_pull_site_database'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rb_monitor' ) ) {
                $url    = esc_url_raw( wp_unslash( $_GET['rb_pull_site_database'] ) );
                $result = $this->monitor->pull_site_artifact( $url, 'database' );
                $site   = $this->monitor_site_by_url( $url );
                $this->notice = $this->notice_html(
                    $this->monitor_action_result_message( 'pull_database', $site, $result ),
                    is_wp_error( $result ) ? 'error' : 'success'
                );
            }
        }

        if ( $has_monitor && isset( $_GET['rb_pull_site_files'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rb_monitor' ) ) {
                $url    = esc_url_raw( wp_unslash( $_GET['rb_pull_site_files'] ) );
                $result = $this->monitor->pull_site_artifact( $url, 'files' );
                $site   = $this->monitor_site_by_url( $url );
                $this->notice = $this->notice_html(
                    $this->monitor_action_result_message( 'pull_files', $site, $result ),
                    is_wp_error( $result ) ? 'error' : 'success'
                );
            }
        }

        // Monitor: Poll all.
        if ( $has_monitor && isset( $_POST['rb_poll_all'] ) ) {
            check_admin_referer( 'rb_monitor' );
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

    private function command_available( $command ) {
        static $cache = array();

        $command = sanitize_key( (string) $command );
        if ( '' === $command ) {
            return false;
        }

        if ( array_key_exists( $command, $cache ) ) {
            return $cache[ $command ];
        }

        $path              = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null' ) );
        $cache[ $command ] = '' !== $path;

        return $cache[ $command ];
    }

    private function sshpass_available() {
        return $this->command_available( 'sshpass' );
    }

    private function ssh_tools_ready() {
        return $this->command_available( 'ssh' ) && $this->command_available( 'scp' );
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
            <div class="sp-card sp-monitor-sites" id="sp-monitor-sites">
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

        $poll_url       = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_poll_site=' . urlencode( $url ) ), 'rb_monitor' );
        $pull_db_url    = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_pull_site_database=' . urlencode( $url ) ), 'rb_monitor' );
        $pull_files_url = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_pull_site_files=' . urlencode( $url ) ), 'rb_monitor' );
        $remove_url     = wp_nonce_url( admin_url( 'admin.php?page=' . $this->monitor_page_slug() . '&rb_remove_site=' . urlencode( $url ) ), 'rb_monitor' );

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
            'rb_every_6h'  => 'Every 6 Hours',
            'rb_every_12h' => 'Every 12 Hours',
            'none'         => 'Disabled',
        );

        return $labels[ $frequency ] ?? ucwords( str_replace( '_', ' ', str_replace( 'rb_every_', 'Every ', (string) $frequency ) ) );
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
                    <a id="sp-cancel-<?php echo esc_attr( md5( $url ) ); ?>" href="#" class="rbm-monitor-action sp-link sp-link--danger" data-monitor-action="cancel_transfer" data-url="<?php echo esc_attr( $url ); ?>">Cancel Transfer</a>
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

    private function normalize_remote_mode( $value ) {
        return 'remote' === sanitize_text_field( (string) $value ) ? 'remote' : 'local';
    }

    private function normalize_remote_protocol( $value ) {
        return 'ftp' === sanitize_text_field( (string) $value ) ? 'ftp' : 'ssh';
    }

    private function remote_protocol_label( $protocol ) {
        return 'ftp' === $this->normalize_remote_protocol( $protocol ) ? 'FTP' : 'SSH';
    }

    private function save_remote_settings_from_request() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- This helper is only called after check_admin_referer( 'rb_remote' ).
        $protocol = $this->normalize_remote_protocol( sanitize_text_field( wp_unslash( $_POST['rb_remote_protocol'] ?? get_option( 'rb_remote_protocol', 'ssh' ) ) ) );
        update_option( 'rb_remote_protocol', $protocol );

        $ssh_host = sanitize_text_field( wp_unslash( $_POST['rb_ssh_host'] ?? get_option( 'rb_ssh_host', '' ) ) );
        update_option( 'rb_ssh_host', $ssh_host );

        $ssh_port = absint( $_POST['rb_ssh_port'] ?? get_option( 'rb_ssh_port', 22 ) ) ?: 22;
        update_option( 'rb_ssh_port', $ssh_port );

        $ssh_username = sanitize_text_field( wp_unslash( $_POST['rb_ssh_username'] ?? get_option( 'rb_ssh_username', '' ) ) );
        update_option( 'rb_ssh_username', $ssh_username );

        $ssh_auth = sanitize_text_field( wp_unslash( $_POST['rb_ssh_auth_method'] ?? get_option( 'rb_ssh_auth_method', 'key' ) ) );
        if ( ! in_array( $ssh_auth, array( 'key', 'password' ), true ) ) {
            $ssh_auth = 'key';
        }
        update_option( 'rb_ssh_auth_method', $ssh_auth );

        $ssh_password = (string) wp_unslash( $_POST['rb_ssh_password'] ?? get_option( 'rb_ssh_password', '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must be stored verbatim.
        update_option( 'rb_ssh_password', $ssh_password );

        $ssh_key = (string) wp_unslash( $_POST['rb_ssh_key'] ?? get_option( 'rb_ssh_key', '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Private keys must preserve their original formatting.
        update_option( 'rb_ssh_key', $ssh_key );

        $ssh_path = sanitize_text_field( wp_unslash( $_POST['rb_ssh_path'] ?? get_option( 'rb_ssh_path', '' ) ) );
        update_option( 'rb_ssh_path', $ssh_path );

        $ftp_host = sanitize_text_field( wp_unslash( $_POST['rb_ftp_host'] ?? get_option( 'rb_ftp_host', '' ) ) );
        update_option( 'rb_ftp_host', $ftp_host );

        $ftp_port = absint( $_POST['rb_ftp_port'] ?? get_option( 'rb_ftp_port', 21 ) ) ?: 21;
        update_option( 'rb_ftp_port', $ftp_port );

        $ftp_username = sanitize_text_field( wp_unslash( $_POST['rb_ftp_username'] ?? get_option( 'rb_ftp_username', '' ) ) );
        update_option( 'rb_ftp_username', $ftp_username );

        $ftp_password = (string) wp_unslash( $_POST['rb_ftp_password'] ?? get_option( 'rb_ftp_password', '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must be stored verbatim.
        update_option( 'rb_ftp_password', $ftp_password );

        $ftp_path = sanitize_text_field( wp_unslash( $_POST['rb_ftp_path'] ?? get_option( 'rb_ftp_path', '' ) ) );
        update_option( 'rb_ftp_path', $ftp_path );

        update_option( 'rb_ftp_passive', isset( $_POST['rb_ftp_passive'] ) ? 1 : 0 );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return $protocol;
    }

    private function remote_settings_ready( $protocol, $settings = array() ) {
        $protocol = $this->normalize_remote_protocol( $protocol );
        $settings = wp_parse_args(
            $settings,
            array(
                'ssh_host'     => get_option( 'rb_ssh_host', '' ),
                'ssh_username' => get_option( 'rb_ssh_username', '' ),
                'ssh_auth'     => get_option( 'rb_ssh_auth_method', 'key' ),
                'ssh_password' => get_option( 'rb_ssh_password', '' ),
                'ssh_key'      => get_option( 'rb_ssh_key', '' ),
                'ssh_path'     => get_option( 'rb_ssh_path', '' ),
                'ftp_host'     => get_option( 'rb_ftp_host', '' ),
                'ftp_username' => get_option( 'rb_ftp_username', '' ),
                'ftp_password' => get_option( 'rb_ftp_password', '' ),
                'ftp_path'     => get_option( 'rb_ftp_path', '' ),
            )
        );

        if ( 'ftp' === $protocol ) {
            return function_exists( 'ftp_connect' )
                && '' !== trim( (string) $settings['ftp_host'] )
                && '' !== trim( (string) $settings['ftp_username'] )
                && '' !== trim( (string) $settings['ftp_password'] )
                && '' !== trim( (string) $settings['ftp_path'] );
        }

        $ssh_auth_ready = 'password' === $settings['ssh_auth']
            ? '' !== trim( (string) $settings['ssh_password'] )
            : '' !== trim( (string) $settings['ssh_key'] );

        return '' !== trim( (string) $settings['ssh_host'] )
            && '' !== trim( (string) $settings['ssh_username'] )
            && '' !== trim( (string) $settings['ssh_path'] )
            && $ssh_auth_ready;
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

    private function ssh_tools_status() {
        $scp = ! empty( trim( shell_exec( 'which scp 2>/dev/null' ) ?? '' ) );
        $ssh = ! empty( trim( shell_exec( 'which ssh 2>/dev/null' ) ?? '' ) );
        $sshpass = ! empty( trim( shell_exec( 'which sshpass 2>/dev/null' ) ?? '' ) );
        return array( 'scp' => $scp, 'ssh' => $ssh, 'sshpass' => $sshpass );
    }

    /* ── Render ───────────────────────────────────────── */

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
        $sched_remote        = $this->normalize_remote_mode( get_option( 'rb_scheduled_remote_mode', 'remote' ) );
        $manual_remote       = $this->normalize_remote_mode( get_option( 'rb_manual_remote_mode', 'local' ) );
        $retain_db           = get_option( 'rb_retain_db', 0 );
        $retain_files        = get_option( 'rb_retain_files', 0 );
        $remote_protocol     = $this->normalize_remote_protocol( get_option( 'rb_remote_protocol', 'ssh' ) );
        $remote_label        = $this->remote_protocol_label( $remote_protocol );
        $ssh_host            = get_option( 'rb_ssh_host', '' );
        $ssh_port            = get_option( 'rb_ssh_port', 22 );
        $ssh_username        = get_option( 'rb_ssh_username', '' );
        $ssh_auth            = get_option( 'rb_ssh_auth_method', 'key' );
        $ssh_password        = get_option( 'rb_ssh_password', '' );
        $ssh_key             = get_option( 'rb_ssh_key', '' );
        $ssh_path            = get_option( 'rb_ssh_path', '' );
        $ftp_host            = get_option( 'rb_ftp_host', '' );
        $ftp_port            = get_option( 'rb_ftp_port', 21 );
        $ftp_username        = get_option( 'rb_ftp_username', '' );
        $ftp_password        = get_option( 'rb_ftp_password', '' );
        $ftp_path            = get_option( 'rb_ftp_path', '' );
        $ftp_passive         = (bool) get_option( 'rb_ftp_passive', 1 );
        $next_database       = $database_schedule['next_run_local'] ?? null;
        $next_files          = $files_schedule['next_run_local'] ?? null;
        $pull_token          = Remote_Backup_Api::get_pull_token( true );
        $status_url          = rest_url( 'remote-backup/v1/status' );
        $catalog_url         = rest_url( 'remote-backup/v1/backups' );
        $active_job          = $this->active_job_state();
        $weekday_options     = $this->scheduler->weekday_options();
        $remote_ready        = $this->remote_settings_ready(
            $remote_protocol,
            array(
                'ssh_host'     => $ssh_host,
                'ssh_username' => $ssh_username,
                'ssh_auth'     => $ssh_auth,
                'ssh_password' => $ssh_password,
                'ssh_key'      => $ssh_key,
                'ssh_path'     => $ssh_path,
                'ftp_host'     => $ftp_host,
                'ftp_username' => $ftp_username,
                'ftp_password' => $ftp_password,
                'ftp_path'     => $ftp_path,
            )
        );
        $ftp_available       = function_exists( 'ftp_connect' );

        if ( ! $remote_ready ) {
            $manual_remote = 'local';
        }

        wp_localize_script( 'rb-admin', 'rbAdmin', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'rb_ajax' ),
            'backupPageUrl'    => admin_url( 'admin.php?page=' . $this->backup_page_slug() ),
            'activeJobId'      => $active_job['id'] ?? '',
            'activeJobStatus'  => $active_job['status'] ?? 'idle',
        ) );

        $overview_url = function_exists( 'savedpixel_admin_page_url' )
            ? savedpixel_admin_page_url( 'savedpixel' )
            : admin_url( 'admin.php?page=savedpixel' );

        $saved_folders = get_option( 'rb_backup_folders', array() );
        $skip          = array( '.', '..', '.git' );
        $excluded_dirs = array_map(
            'trailingslashit',
            array(
                wp_normalize_path( RB_STORAGE_DIR ),
                wp_normalize_path( RB_BASE_DIR ),
            )
        );
        $tree          = array();
        foreach ( scandir( ABSPATH ) as $item ) {
            $item_path = trailingslashit( wp_normalize_path( ABSPATH . $item ) );
            if ( in_array( $item, $skip, true ) || ! is_dir( ABSPATH . $item ) || in_array( $item_path, $excluded_dirs, true ) ) {
                continue;
            }
            $children = array();
            foreach ( scandir( ABSPATH . $item ) as $child ) {
                $child_path = trailingslashit( wp_normalize_path( ABSPATH . $item . '/' . $child ) );
                if ( in_array( $child, $skip, true ) || ! is_dir( ABSPATH . $item . '/' . $child ) || in_array( $child_path, $excluded_dirs, true ) ) {
                    continue;
                }
                $children[] = $child;
            }
            sort( $children );
            $tree[ $item ] = $children;
        }
        ksort( $tree );

        $is_checked = function ( $path ) use ( $saved_folders ) {
            if ( empty( $saved_folders ) ) {
                return true;
            }

            return in_array( $path, $saved_folders, true );
        };
        $parent_has_any = function ( $parent, $children ) use ( $saved_folders ) {
            if ( empty( $saved_folders ) ) {
                return true;
            }
            if ( in_array( $parent, $saved_folders, true ) ) {
                return true;
            }
            foreach ( $children as $child ) {
                if ( in_array( $parent . '/' . $child, $saved_folders, true ) ) {
                    return true;
                }
            }
            return false;
        };
        ?><?php savedpixel_admin_page_start( 'sprb-page' ); ?>
                <header id="rb-header" class="sp-page-header">
                    <div id="rb-header-main">
                        <h1 id="rb-header-title" class="sp-page-title">SavedPixel Remote Backup</h1>
                        <p id="rb-header-desc" class="sp-page-desc sp-page-desc--wide">Run manual backups, configure scheduled retention, and manage remote delivery or pull-based access from a single backup workspace.</p>
                    </div>
                    <div id="rb-header-actions" class="sp-header-actions">
                        <a id="rb-back-link" class="button" href="<?php echo esc_url( $overview_url ); ?>">Back to Overview</a>
                    </div>
                </header>

                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice HTML is built by notice_html(). ?>
                <?php echo $this->notice; ?>

                <?php if ( ! $writable ) : ?>
                    <div class="notice notice-error"><p><strong>Storage not writable.</strong> Backups cannot run until <code><?php echo esc_html( RB_STORAGE_DIR ); ?></code> is writable.</p></div>
                <?php endif; ?>

                <div id="rb-grid" class="sp-grid">
                    <div id="rb-schedule-card" class="sp-card sp-card--schedule">
                        <div id="rb-schedule-body" class="sp-card__body">
                            <h2 id="rb-schedule-title"><span class="dashicons dashicons-cloud-saved"></span> Backups</h2>
                            <p class="sp-desc">Create a one-time backup right now. Choose whether to keep it local only or upload it to remote storage after it finishes.</p>
                            <table id="rb-manual-delivery-table" class="form-table sp-form-table">
                                <tr id="rb-manual-delivery-row">
                                    <th id="rb-manual-delivery-label" scope="row"><label for="rb_manual_remote_mode">Delivery</label></th>
                                    <td id="rb-manual-delivery-field">
                                        <select id="rb_manual_remote_mode">
                                            <option value="local" <?php selected( $manual_remote, 'local' ); ?>>Backup normally</option>
                                            <option value="remote" <?php selected( $manual_remote, 'remote' ); ?> <?php disabled( ! $remote_ready ); ?>>Backup + send to remote storage</option>
                                        </select>
                                        <p class="description">
                                            <?php echo $remote_ready ? esc_html( "Remote delivery uses the {$remote_label} settings in the panel on the right." ) : esc_html( 'Configure remote storage settings to enable remote delivery.' ); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <div class="sp-actions" id="sp-manual-actions">
                                <button type="button" class="button sp-btn sp-btn--db sp-ajax-backup" data-scope="database" <?php disabled( ! $writable ); ?>>
                                    <span class="dashicons dashicons-database"></span> Database
                                </button>
                                <button type="button" class="button sp-btn sp-btn--files sp-ajax-backup" data-scope="files" <?php disabled( ! $writable ); ?>>
                                    <span class="dashicons dashicons-media-archive"></span> Files
                                </button>
                                <button type="button" class="button button-primary sp-btn sp-btn--both sp-ajax-backup" data-scope="both" <?php disabled( ! $writable ); ?>>
                                    <span class="dashicons dashicons-admin-site-alt3"></span> Everything
                                </button>
                            </div>
                            <p id="sp-manual-note" class="description" style="margin-top:6px;"><strong>Everything</strong> backs up the database plus only the folders selected below. <strong>Files</strong> also uses the selection below.</p>

                            <div class="sp-folder-picker" id="sp-folder-picker">
                                <p class="sp-folder-label"><span class="dashicons dashicons-open-folder"></span> <strong>Folders to include in file backups:</strong></p>
                                <div class="sp-folder-tree">
                                    <?php foreach ( $tree as $dir => $children ) : ?>
                                        <?php
                                        $has_children  = ! empty( $children );
                                        $dir_checked   = $has_children ? $parent_has_any( $dir, $children ) : $is_checked( $dir );
                                        $parent_direct = in_array( $dir, $saved_folders, true );
                                        ?>
                                        <div class="sp-tree-node<?php echo $has_children ? ' sp-tree-node--parent' : ''; ?>">
                                            <div class="sp-tree-row">
                                                <?php if ( $has_children ) : ?>
                                                    <span class="sp-tree-toggle"></span>
                                                <?php else : ?>
                                                    <span class="sp-tree-spacer"></span>
                                                <?php endif; ?>
                                                <label class="sp-folder-item">
                                                    <input type="checkbox" class="sp-folder-cb" value="<?php echo esc_attr( $dir ); ?>" <?php checked( $dir_checked ); ?> data-children="<?php echo $has_children ? '1' : '0'; ?>">
                                                    <span class="dashicons dashicons-category"></span>
                                                    <?php echo esc_html( $dir ); ?>/
                                                </label>
                                            </div>
                                            <?php if ( $has_children ) : ?>
                                                <div class="sp-tree-children" style="display:none;">
                                                    <?php foreach ( $children as $child ) : ?>
                                                        <?php
                                                        $child_path    = $dir . '/' . $child;
                                                        $child_checked = empty( $saved_folders ) || $parent_direct || in_array( $child_path, $saved_folders, true );
                                                        ?>
                                                        <div class="sp-tree-row sp-tree-row--child">
                                                            <span class="sp-tree-spacer"></span>
                                                            <label class="sp-folder-item">
                                                                <input type="checkbox" class="sp-folder-cb sp-child-cb" value="<?php echo esc_attr( $child_path ); ?>" data-parent="<?php echo esc_attr( $dir ); ?>" <?php checked( $child_checked ); ?>>
                                                                <span class="dashicons dashicons-category"></span>
                                                                <?php echo esc_html( $child ); ?>/
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="sp-folder-actions">
                                    <button type="button" class="button button-small" id="sp-folders-all">Select All</button>
                                    <button type="button" class="button button-small" id="sp-folders-none">Deselect All</button>
                                    <button type="button" class="button button-small button-primary" id="sp-folders-save">Save Selection</button>
                                    <span id="sp-folders-saved" class="sp-folders-saved" style="display:none;">✓ Saved</span>
                                </div>
                            </div>

                            <div id="sp-progress-overlay" class="sp-progress-overlay" style="display:none;">
                                <span class="dashicons dashicons-update sp-spin"></span>
                                <span id="sp-progress-text">Starting backup…</span>
                            </div>

                            <hr class="sp-divider">

                            <h3 id="rb-schedule-subtitle" class="sp-segment-title"><span class="dashicons dashicons-clock"></span> Scheduled Backups</h3>
                            <?php if ( $next_database || $next_files ) : ?>
                                <div class="sp-note">
                                    <?php if ( $next_database ) : ?>
                                        <p>Next database run: <strong><?php echo esc_html( mysql2date( 'M j, Y @ H:i', $next_database ) ); ?></strong></p>
                                    <?php endif; ?>
                                    <?php if ( $next_files ) : ?>
                                        <p>Next files run: <strong><?php echo esc_html( mysql2date( 'M j, Y @ H:i', $next_files ) ); ?></strong></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <form id="rb-schedule-form" method="post">
                                <?php wp_nonce_field( 'rb_schedule' ); ?>
                                <input type="hidden" name="rb_save_schedule" value="1">
                                <table id="rb-schedule-table" class="form-table sp-form-table">
                                    <tr id="rb-row-db-frequency">
                                        <th><label for="rb_schedule_database_frequency">Database Frequency</label></th>
                                        <td>
                                            <select name="rb_schedule_database_frequency" id="rb_schedule_database_frequency">
                                                <option value="none" <?php selected( $sched_db_freq, 'none' ); ?>>Disabled</option>
                                                <option value="hourly" <?php selected( $sched_db_freq, 'hourly' ); ?>>Every Hour</option>
                                                <option value="rb_every_6h" <?php selected( $sched_db_freq, 'rb_every_6h' ); ?>>Every 6 Hours</option>
                                                <option value="rb_every_12h" <?php selected( $sched_db_freq, 'rb_every_12h' ); ?>>Every 12 Hours</option>
                                                <option value="twicedaily" <?php selected( $sched_db_freq, 'twicedaily' ); ?>>Twice Daily</option>
                                                <option value="daily" <?php selected( $sched_db_freq, 'daily' ); ?>>Daily</option>
                                                <option value="weekly" <?php selected( $sched_db_freq, 'weekly' ); ?>>Weekly</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-db-time">
                                        <th><label for="rb_schedule_database_time">Database Time</label></th>
                                        <td>
                                            <input type="time" name="rb_schedule_database_time" id="rb_schedule_database_time" value="<?php echo esc_attr( $sched_db_time ); ?>" step="60">
                                            <p class="description">Uses the WordPress site timezone. Hourly schedules use the minutes portion.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb_schedule_database_weekday_row" <?php echo 'weekly' !== $sched_db_freq ? 'style="display:none"' : ''; ?>>
                                        <th><label for="rb_schedule_database_weekday">Database Day</label></th>
                                        <td>
                                            <select name="rb_schedule_database_weekday" id="rb_schedule_database_weekday">
                                                <?php foreach ( $weekday_options as $weekday_value => $weekday_label ) : ?>
                                                    <option value="<?php echo esc_attr( $weekday_value ); ?>" <?php selected( $sched_db_weekday, $weekday_value ); ?>><?php echo esc_html( $weekday_label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description">Used only when Database Frequency is set to Weekly.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-files-frequency">
                                        <th><label for="rb_schedule_files_frequency">Files Frequency</label></th>
                                        <td>
                                            <select name="rb_schedule_files_frequency" id="rb_schedule_files_frequency">
                                                <option value="none" <?php selected( $sched_files_freq, 'none' ); ?>>Disabled</option>
                                                <option value="hourly" <?php selected( $sched_files_freq, 'hourly' ); ?>>Every Hour</option>
                                                <option value="rb_every_6h" <?php selected( $sched_files_freq, 'rb_every_6h' ); ?>>Every 6 Hours</option>
                                                <option value="rb_every_12h" <?php selected( $sched_files_freq, 'rb_every_12h' ); ?>>Every 12 Hours</option>
                                                <option value="twicedaily" <?php selected( $sched_files_freq, 'twicedaily' ); ?>>Twice Daily</option>
                                                <option value="daily" <?php selected( $sched_files_freq, 'daily' ); ?>>Daily</option>
                                                <option value="weekly" <?php selected( $sched_files_freq, 'weekly' ); ?>>Weekly</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-files-time">
                                        <th><label for="rb_schedule_files_time">Files Time</label></th>
                                        <td>
                                            <input type="time" name="rb_schedule_files_time" id="rb_schedule_files_time" value="<?php echo esc_attr( $sched_files_time ); ?>" step="60">
                                            <p class="description">Uses the WordPress site timezone. Daily and weekly schedules run at this time. Every 6 hours, every 12 hours, and twice daily use it as the anchor time. Hourly uses the minutes portion.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb_schedule_files_weekday_row" <?php echo 'weekly' !== $sched_files_freq ? 'style="display:none"' : ''; ?>>
                                        <th><label for="rb_schedule_files_weekday">Files Day</label></th>
                                        <td>
                                            <select name="rb_schedule_files_weekday" id="rb_schedule_files_weekday">
                                                <?php foreach ( $weekday_options as $weekday_value => $weekday_label ) : ?>
                                                    <option value="<?php echo esc_attr( $weekday_value ); ?>" <?php selected( $sched_files_weekday, $weekday_value ); ?>><?php echo esc_html( $weekday_label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description">Used only when Files Frequency is set to Weekly.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-scheduled-delivery">
                                        <th><label for="rb_scheduled_remote_mode">Delivery</label></th>
                                        <td>
                                            <select name="rb_scheduled_remote_mode" id="rb_scheduled_remote_mode">
                                                <option value="local" <?php selected( $sched_remote, 'local' ); ?>>Backup normally</option>
                                                <option value="remote" <?php selected( $sched_remote, 'remote' ); ?>>Backup + send to remote storage</option>
                                            </select>
                                            <p class="description">Scheduled jobs always create the local backup first. Remote delivery uses the saved remote storage settings.</p>
                                        </td>
                                    </tr>
                                </table>

                                <hr class="sp-divider">
                                <h3 id="rb-retention-subtitle" class="sp-segment-title"><span class="dashicons dashicons-trash"></span> Retention</h3>
                                <p class="sp-desc">Automatically delete oldest backups beyond these limits. Set to 0 to keep all.</p>
                                <table id="rb-retention-table" class="form-table sp-form-table">
                                    <tr id="rb-row-retain-db">
                                        <th><label for="rb_retain_db">Database backups</label></th>
                                        <td><input type="number" name="rb_retain_db" id="rb_retain_db" value="<?php echo esc_attr( $retain_db ); ?>" min="0" max="100" class="small-text"> <span class="description">most recent to keep</span></td>
                                    </tr>
                                    <tr id="rb-row-retain-files">
                                        <th><label for="rb_retain_files">File backups</label></th>
                                        <td><input type="number" name="rb_retain_files" id="rb_retain_files" value="<?php echo esc_attr( $retain_files ); ?>" min="0" max="100" class="small-text"> <span class="description">most recent to keep</span></td>
                                    </tr>
                                </table>

                                <p id="rb-schedule-submit-row"><button type="submit" name="rb_save_schedule" value="1" class="button button-primary">Save Schedule</button></p>
                            </form>
                        </div>
                    </div>

                    <div id="rb-ssh-card" class="sp-card sp-card--ssh">
                        <div id="rb-ssh-body" class="sp-card__body">
                            <h2 id="rb-remote-title"><span class="dashicons dashicons-networking"></span> Remote Storage</h2>
                            <p class="sp-desc">Configure where remote-delivery backups are sent. SSH/SCP and FTP are both supported.</p>

                            <div class="sp-protocol-ssh" <?php echo 'ssh' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                <?php if ( $this->ssh_tools_ready() ) : ?>
                                    <p class="sp-transport-tools-ok description">
                                        <span class="sp-ok">scp ✓</span> &nbsp;
                                        <span class="sp-ok">ssh ✓</span> &nbsp;
                                        <span class="<?php echo $this->sshpass_available() ? 'sp-ok' : 'sp-missing'; ?>">sshpass <?php echo $this->sshpass_available() ? '✓' : '✗'; ?></span>
                                    </p>
                                <?php else : ?>
                                    <div class="sp-transport-tools-banner">
                                        <span class="sp-transport-tools-icons">
                                            <span class="<?php echo $this->command_available( 'scp' ) ? 'sp-ok' : 'sp-missing'; ?>">scp <?php echo $this->command_available( 'scp' ) ? '✓' : '✗'; ?></span>
                                            <span class="<?php echo $this->command_available( 'ssh' ) ? 'sp-ok' : 'sp-missing'; ?>">ssh <?php echo $this->command_available( 'ssh' ) ? '✓' : '✗'; ?></span>
                                            <span class="<?php echo $this->sshpass_available() ? 'sp-ok' : 'sp-missing'; ?>">sshpass <?php echo $this->sshpass_available() ? '✓' : '✗'; ?></span>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="sp-protocol-ftp" <?php echo 'ftp' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                <?php if ( $ftp_available ) : ?>
                                    <p class="sp-transport-tools-ok description">
                                        <span class="sp-ok">PHP FTP extension ✓</span>
                                    </p>
                                <?php else : ?>
                                    <div class="sp-transport-tools-banner">
                                        <span class="sp-transport-tools-icons">
                                            <span class="sp-missing">PHP FTP extension ✗</span>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <form id="rb-remote-form" method="post">
                                <?php wp_nonce_field( 'rb_remote' ); ?>
                                <table id="rb-remote-table" class="form-table sp-form-table">
                                    <tr id="rb-row-protocol">
                                        <th><label for="rb_remote_protocol">Protocol</label></th>
                                        <td>
                                            <select name="rb_remote_protocol" id="rb_remote_protocol">
                                                <option value="ssh" <?php selected( $remote_protocol, 'ssh' ); ?>>SSH / SCP</option>
                                                <option value="ftp" <?php selected( $remote_protocol, 'ftp' ); ?>>FTP</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-ssh-host" class="sp-protocol-ssh" <?php echo 'ssh' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ssh_host">Host</label></th>
                                        <td><input type="text" name="rb_ssh_host" id="rb_ssh_host" class="regular-text" value="<?php echo esc_attr( $ssh_host ); ?>" placeholder="backup.example.com"></td>
                                    </tr>
                                    <tr id="rb-row-ssh-port" class="sp-protocol-ssh" <?php echo 'ssh' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ssh_port">Port</label></th>
                                        <td><input type="number" name="rb_ssh_port" id="rb_ssh_port" class="small-text" value="<?php echo esc_attr( $ssh_port ); ?>" min="1" max="65535"></td>
                                    </tr>
                                    <tr id="rb-row-ssh-username" class="sp-protocol-ssh" <?php echo 'ssh' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ssh_username">Username</label></th>
                                        <td><input type="text" name="rb_ssh_username" id="rb_ssh_username" class="regular-text" value="<?php echo esc_attr( $ssh_username ); ?>" placeholder="backups"></td>
                                    </tr>
                                    <tr id="rb-row-ssh-path" class="sp-protocol-ssh" <?php echo 'ssh' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ssh_path">Remote Path</label></th>
                                        <td><input type="text" name="rb_ssh_path" id="rb_ssh_path" class="regular-text" value="<?php echo esc_attr( $ssh_path ); ?>" placeholder="/home/backups/example-site"></td>
                                    </tr>
                                    <tr id="rb-row-ssh-auth" class="sp-protocol-ssh" <?php echo 'ssh' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ssh_auth_method">Auth Method</label></th>
                                        <td>
                                            <select name="rb_ssh_auth_method" id="rb_ssh_auth_method">
                                                <option value="key" <?php selected( $ssh_auth, 'key' ); ?>>SSH Private Key</option>
                                                <option value="password" <?php selected( $ssh_auth, 'password' ); ?>>Password</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-ssh-key" class="sp-protocol-ssh sp-auth-key" <?php echo 'ssh' !== $remote_protocol || 'key' !== $ssh_auth ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ssh_key">Private Key</label></th>
                                        <td>
                                            <textarea name="rb_ssh_key" id="rb_ssh_key" rows="5" class="large-text code" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;…&#10;-----END OPENSSH PRIVATE KEY-----"><?php echo esc_textarea( $ssh_key ); ?></textarea>
                                            <p class="description">Paste the full unencrypted private key including header/footer lines. Public keys and passphrase-protected keys are not supported.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-ssh-password" class="sp-protocol-ssh sp-auth-password" <?php echo 'ssh' !== $remote_protocol || 'password' !== $ssh_auth ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ssh_password">Password</label></th>
                                        <td>
                                            <input type="password" name="rb_ssh_password" id="rb_ssh_password" class="regular-text" value="<?php echo esc_attr( $ssh_password ); ?>" autocomplete="off">
                                            <p class="description">Requires <code>sshpass</code> installed on the server.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-ftp-host" class="sp-protocol-ftp" <?php echo 'ftp' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ftp_host">Host</label></th>
                                        <td><input type="text" name="rb_ftp_host" id="rb_ftp_host" class="regular-text" value="<?php echo esc_attr( $ftp_host ); ?>" placeholder="ftp.example.com"></td>
                                    </tr>
                                    <tr id="rb-row-ftp-port" class="sp-protocol-ftp" <?php echo 'ftp' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ftp_port">Port</label></th>
                                        <td><input type="number" name="rb_ftp_port" id="rb_ftp_port" class="small-text" value="<?php echo esc_attr( $ftp_port ); ?>" min="1" max="65535"></td>
                                    </tr>
                                    <tr id="rb-row-ftp-username" class="sp-protocol-ftp" <?php echo 'ftp' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ftp_username">Username</label></th>
                                        <td><input type="text" name="rb_ftp_username" id="rb_ftp_username" class="regular-text" value="<?php echo esc_attr( $ftp_username ); ?>" placeholder="backups"></td>
                                    </tr>
                                    <tr id="rb-row-ftp-password" class="sp-protocol-ftp" <?php echo 'ftp' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ftp_password">Password</label></th>
                                        <td><input type="password" name="rb_ftp_password" id="rb_ftp_password" class="regular-text" value="<?php echo esc_attr( $ftp_password ); ?>" autocomplete="off"></td>
                                    </tr>
                                    <tr id="rb-row-ftp-path" class="sp-protocol-ftp" <?php echo 'ftp' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th><label for="rb_ftp_path">Remote Path</label></th>
                                        <td>
                                            <input type="text" name="rb_ftp_path" id="rb_ftp_path" class="regular-text" value="<?php echo esc_attr( $ftp_path ); ?>" placeholder="/backups or /home/backups/example-site">
                                            <p class="description">Use a path the FTP user can access. Some FTP servers expect a path relative to the FTP root.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-ftp-passive" class="sp-protocol-ftp" <?php echo 'ftp' !== $remote_protocol ? 'style="display:none;"' : ''; ?>>
                                        <th>Transfer Mode</th>
                                        <td>
                                            <label class="sp-checkbox-row" for="rb_ftp_passive">
                                                <input type="checkbox" name="rb_ftp_passive" id="rb_ftp_passive" value="1" <?php checked( $ftp_passive ); ?>>
                                                Use passive mode
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                                <p id="rb-remote-submit-row" class="sp-schedule-actions">
                                    <button type="submit" name="rb_save_connection" value="1" class="button button-primary">Save Remote Storage</button>
                                    <button type="submit" name="rb_test_remote" value="1" class="button">Test Connection</button>
                                </p>
                            </form>

                            <hr class="sp-divider">
                            <h3 id="rb-pull-subtitle" class="sp-segment-title"><span class="dashicons dashicons-admin-links"></span> Pull Access</h3>
                            <p class="sp-desc">Use this when a central SavedPixel Remote Backup monitor should pull completed backups from this site instead of receiving them over SSH/SCP or FTP.</p>
                            <form id="rb-pull-form" method="post">
                                <?php wp_nonce_field( 'rb_pull_access' ); ?>
                                <table id="rb-pull-table" class="form-table sp-form-table">
                                    <tr id="rb-row-pull-token">
                                        <th><label for="rb_pull_token">Pull Token</label></th>
                                        <td>
                                            <input type="text" name="rb_pull_token" id="rb_pull_token" class="regular-text code" value="<?php echo esc_attr( $pull_token ); ?>" autocomplete="off">
                                            <p class="description">The monitor plugin sends this token as <code>X-RB-Pull-Token</code> when it reads the catalog and downloads backup artifacts.</p>
                                        </td>
                                    </tr>
                                    <tr id="rb-row-status-endpoint">
                                        <th>Status Endpoint</th>
                                        <td><code><?php echo esc_html( $status_url ); ?></code></td>
                                    </tr>
                                    <tr id="rb-row-catalog-endpoint">
                                        <th>Catalog Endpoint</th>
                                        <td><code><?php echo esc_html( $catalog_url ); ?></code></td>
                                    </tr>
                                </table>
                                <p id="rb-pull-submit-row" class="sp-schedule-actions">
                                    <button type="submit" name="rb_save_pull_access" value="1" class="button button-primary">Save Pull Access</button>
                                    <button type="submit" name="rb_regenerate_pull_token" value="1" class="button" onclick="return confirm('Regenerate the pull token? Existing monitors will stop working until updated.');">Regenerate Token</button>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>

                <section id="rb-history-section">
                    <div id="rb-history-header" class="sp-card__header">
                        <div id="rb-history-header-main">
                            <h2 id="rb-history-title" class="sp-card__title">Backup History</h2>
                        </div>
                        <span id="rb-history-count" class="sp-badge sp-badge--neutral"><?php echo esc_html( count( $backups ) . ' items' ); ?></span>
                    </div>
                    <div id="rb-history-card" class="sp-card sp-card--history">
                        <div id="rb-history-card-body" class="sp-card__body sp-card__body--flush">
                        <?php if ( empty( $backups ) ) : ?>
                            <div id="rb-history-empty" class="sp-empty">
                                <h2>No backups yet</h2>
                                <p>Use the buttons above to create one.</p>
                            </div>
                        <?php else : ?>
                            <div id="rb-history-table-wrap" class="sp-table-wrap">
                                <table id="rb-history-table" class="sp-table">
                                    <thead id="rb-history-thead">
                                        <tr id="rb-history-header-row">
                                            <th>Date</th>
                                            <th>Scope</th>
                                            <th>Status</th>
                                            <th>Remote</th>
                                            <th>Total Size</th>
                                            <th>DB Size</th>
                                            <th class="sp-th-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="rb-history-body">
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
                                                <td class="sp-td-actions">
                                                    <div class="sp-actions">
                                                        <?php if ( ! empty( $b['db_file'] ) ) : ?>
                                                            <a href="<?php echo esc_url( $this->downloads->download_url( $b['id'], 'database' ) ); ?>" class="sp-btn sp-btn--ghost sp-btn--icon" title="Download DB"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3C7.58 3 4 4.34 4 6v12c0 1.66 3.58 3 8 3s8-1.34 8-3V6c0-1.66-3.58-3-8-3Zm0 2c3.87 0 6 1.13 6 1s-2.13 1-6 1-6-1.13-6-1 2.13-1 6-1ZM6 8.27C7.53 9 9.58 9.5 12 9.5s4.47-.5 6-1.23V12c0 .13-2.13 1-6 1s-6-.87-6-1V8.27ZM6 14.27C7.53 15 9.58 15.5 12 15.5s4.47-.5 6-1.23V18c0 .13-2.13 1-6 1s-6-.87-6-1v-3.73Z" fill="currentColor"/></svg></a>
                                                        <?php endif; ?>
                                                        <?php if ( ! empty( $b['files_file'] ) ) : ?>
                                                            <a href="<?php echo esc_url( $this->downloads->download_url( $b['id'], 'files' ) ); ?>" class="sp-btn sp-btn--ghost sp-btn--icon" title="Download Files"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2Z" fill="currentColor"/></svg></a>
                                                        <?php endif; ?>
                                                        <?php if ( ! empty( $b['plugins_file'] ) ) : ?>
                                                            <a href="<?php echo esc_url( $this->downloads->download_url( $b['id'], 'plugins' ) ); ?>" class="sp-btn sp-btn--ghost sp-btn--icon" title="Download Plugins"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.5 11H19V7c0-1.1-.9-2-2-2h-4V3.5a2.5 2.5 0 0 0-5 0V5H4c-1.1 0-2 .9-2 2v3.8h1.5a2.7 2.7 0 0 1 0 5.4H2V20c0 1.1.9 2 2 2h3.8v-1.5a2.7 2.7 0 0 1 5.4 0V22H17c1.1 0 2-.9 2-2v-4h1.5a2.5 2.5 0 0 0 0-5Z" fill="currentColor"/></svg></a>
                                                        <?php endif; ?>
                                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $this->backup_page_slug() . '&rb_delete=' . urlencode( $b['id'] ) ), 'rb_delete' ) ); ?>" class="sp-btn sp-btn--danger sp-btn--icon" title="Delete" onclick="return confirm('Delete this backup and its files?');"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12V8h12v13H6Z" fill="currentColor"></path></svg></a>
                                                    </div>
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

                <div id="rb-log-card" class="sp-card sp-card--log">
                    <div id="rb-log-body" class="sp-card__body">
                        <h2 id="rb-log-title"><span class="dashicons dashicons-editor-code"></span> Debug Log</h2>
                        <?php $log = $this->logger->get_log(); ?>
                        <?php if ( $log ) : ?>
                            <pre id="rb-log-output" class="sp-log"><?php echo esc_html( $log ); ?></pre>
                            <form id="rb-log-clear-form" method="post" class="sp-log-actions">
                                <?php wp_nonce_field( 'rb_log' ); ?>
                                <input type="hidden" name="rb_clear_log" value="1">
                                <button id="rb-log-clear-btn" type="submit" class="button button-small" onclick="return confirm('Clear the entire log?');">Clear Log</button>
                            </form>
                        <?php else : ?>
                            <p class="sp-empty">No log entries yet.</p>
                        <?php endif; ?>
                    </div>
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

        wp_localize_script( 'rb-admin', 'rbAdmin', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'rb_ajax' ),
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
                                <?php wp_nonce_field( 'rb_monitor' ); ?>
                                <table class="form-table sp-form-table">
                                    <tr>
                                        <th><label for="rb_monitor_retry_minutes">Poll Delay</label></th>
                                        <td>
                                            <input type="number" name="rb_monitor_retry_minutes" id="rb_monitor_retry_minutes" class="small-text" min="5" max="240" value="<?php echo esc_attr( $monitor_settings['retry_minutes'] ); ?>">
                                            <p class="description">Minutes after the scheduled backup time before the monitor checks that site.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="rb_monitor_watch_minutes">Watch Window</label></th>
                                        <td>
                                            <input type="number" name="rb_monitor_watch_minutes" id="rb_monitor_watch_minutes" class="small-text" min="5" max="720" value="<?php echo esc_attr( $monitor_settings['watch_minutes'] ); ?>">
                                            <p class="description">Minutes after the scheduled run before reporting no response if that poll still finds no completed backup.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="rb_monitor_notification_email">Notify Email</label></th>
                                        <td>
                                            <input type="text" name="rb_monitor_notification_email" id="rb_monitor_notification_email" class="regular-text" value="<?php echo esc_attr( $monitor_settings['notification_email'] ); ?>">
                                            <p class="description">Comma-separated emails are allowed. Leave blank to fall back to the site admin email.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p><button type="submit" name="rb_save_monitor_settings" value="1" class="button button-primary">Save Monitor Settings</button></p>
                            </form>

                            <hr class="sp-divider">
                            <div class="sp-monitor-inline-actions">
                                <div>
                                    <h3 class="sp-segment-title">Actions</h3>
                                    <p class="sp-desc">The monitor cron runs every five minutes, but each site is only checked when its synced database or files schedule says it is due. Polling refreshes status and schedules only. Use <code>Pull DB</code> or <code>Pull Files</code> to download artifacts.</p>
                                </div>
                                <form method="post">
                                    <?php wp_nonce_field( 'rb_monitor' ); ?>
                                    <button type="submit" name="rb_poll_all" value="1" class="button button-primary sp-monitor-action" data-monitor-action="poll_all" data-url="" <?php disabled( empty( $sites ) ); ?>>Poll All Now</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="sp-card sp-card--monitor-add">
                        <div class="sp-card__body">
                            <h2>Add Site</h2>
                            <form method="post">
                                <?php wp_nonce_field( 'rb_monitor' ); ?>
                                <table class="form-table sp-form-table">
                                    <tr>
                                        <th><label for="rb_site_url">Site URL</label></th>
                                        <td><input type="url" name="rb_site_url" id="rb_site_url" class="regular-text" placeholder="https://example.com" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="rb_site_label">Label</label></th>
                                        <td><input type="text" name="rb_site_label" id="rb_site_label" class="regular-text" placeholder="My Site (optional)"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="rb_site_pull_token">Pull Token</label></th>
                                        <td>
                                            <input type="text" name="rb_site_pull_token" id="rb_site_pull_token" class="regular-text code" placeholder="Optional: enables artifact downloads">
                                            <p class="description">Leave blank to monitor status only. Add the token from the client site to let this host pull completed backups. When you add a site, the monitor immediately syncs that site&rsquo;s database and files schedules and saves the next poll time from the remote configuration.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p><button type="submit" name="rb_add_site" value="1" class="button button-primary">Add Site</button></p>
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
