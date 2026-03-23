<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remote_Backup_Api {

    const TOKEN_OPTION = 'rb_pull_token';

    private $storage;
    private $scheduler;
    private $admin;

    public function __construct( Remote_Backup_Storage $storage, Remote_Backup_Scheduler $scheduler, Remote_Backup_Admin $admin ) {
        $this->storage   = $storage;
        $this->scheduler = $scheduler;
        $this->admin     = $admin;

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public static function get_pull_token( $generate = false ) {
        $token = trim( (string) get_option( self::TOKEN_OPTION, '' ) );

        if ( '' === $token && $generate ) {
            $token = wp_generate_password( 40, false, false );
            update_option( self::TOKEN_OPTION, $token );
        }

        return $token;
    }

    public static function rotate_pull_token() {
        $token = wp_generate_password( 40, false, false );
        update_option( self::TOKEN_OPTION, $token );

        return $token;
    }

    public function register_routes() {
        register_rest_route(
            'remote-backup/v1',
            '/status',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'status_callback' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'remote-backup/v1',
            '/backups',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'backups_callback' ),
                'permission_callback' => array( $this, 'authorize_pull_request' ),
            )
        );

        register_rest_route(
            'remote-backup/v1',
            '/download/(?P<id>[A-Za-z0-9\-]+)/(?P<artifact>database|files|plugins)',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'download_callback' ),
                'permission_callback' => array( $this, 'authorize_pull_request' ),
            )
        );

        register_rest_route(
            'remote-backup/v1',
            '/trigger',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'trigger_callback' ),
                'permission_callback' => array( $this, 'authorize_pull_request' ),
            )
        );
    }

    public function status_callback( WP_REST_Request $request = null ) {
        $backups         = $this->storage->get_backups();
        $last_backup     = null;
        $last_successful = $this->storage->latest_successful_backup();
        $safe_list       = array();

        foreach ( $backups as $backup ) {
            $item = $this->safe_backup_summary( $backup );
            if ( null === $item ) {
                continue;
            }
            $safe_list[] = $item;
        }

        if ( ! empty( $safe_list ) ) {
            $last_backup = end( $safe_list );
        }

        return rest_ensure_response(
            array(
                'site'                 => home_url(),
                'plugin_version'       => defined( 'RB_VERSION' ) ? RB_VERSION : 'unknown',
                'last_backup'          => $last_backup,
                'last_successful'      => $this->safe_backup_summary( $last_successful ),
                'backups'              => $safe_list,
                'schedule'             => $this->scheduler->describe_schedule(),
                'schedules'            => array(
                    'database' => $this->scheduler->describe_schedule( 'database' ),
                    'files'    => $this->scheduler->describe_schedule( 'files' ),
                ),
                'active_job'           => $this->admin->get_backup_job_status_payload(),
                'pull'                 => array(
                    'enabled'     => '' !== self::get_pull_token(),
                    'catalog_url' => rest_url( 'remote-backup/v1/backups' ),
                ),
            )
        );
    }

    public function backups_callback( WP_REST_Request $request ) {
        $items   = array();
        $backups = $this->storage->get_backups();

        usort(
            $backups,
            function ( $a, $b ) {
                return strcmp( $a['date'] ?? '', $b['date'] ?? '' );
            }
        );

        foreach ( $backups as $backup ) {
            $payload = $this->storage->backup_to_api_payload( $backup );
            if ( null === $payload || 'success' !== ( $payload['status'] ?? 'success' ) ) {
                continue;
            }

            if ( empty( $payload['artifacts'] ) ) {
                continue;
            }

            foreach ( $payload['artifacts'] as &$artifact ) {
                $artifact['download_url'] = $this->download_url( $payload['id'], $artifact['type'] );
            }
            unset( $artifact );

            $items[] = $payload;
        }

        return rest_ensure_response(
            array(
                'site'    => home_url(),
                'count'   => count( $items ),
                'backups' => $items,
            )
        );
    }

    public function download_callback( WP_REST_Request $request ) {
        $id       = sanitize_text_field( (string) $request['id'] );
        $artifact = sanitize_text_field( (string) $request['artifact'] );
        $backup   = $this->storage->get_backup( $id );

        if ( ! $backup ) {
            return new WP_Error( 'rb_not_found', 'Backup not found.', array( 'status' => 404 ) );
        }

        $key_map = array(
            'database' => 'db_file',
            'files'    => 'files_file',
            'plugins'  => 'plugins_file',
        );

        if ( empty( $key_map[ $artifact ] ) || empty( $backup[ $key_map[ $artifact ] ] ) ) {
            return new WP_Error( 'rb_artifact_missing', 'Artifact not available for this backup.', array( 'status' => 404 ) );
        }

        $filename = basename( (string) $backup[ $key_map[ $artifact ] ] );
        $filepath = $this->storage->resolve_storage_path( $filename );

        if ( ! file_exists( $filepath ) ) {
            return new WP_Error( 'rb_file_missing', 'Backup file not found on disk.', array( 'status' => 404 ) );
        }

        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Accel-Buffering: no' );
        header( 'Content-Length: ' . filesize( $filepath ) );
        $streamed = $this->storage->stream_file( $filepath );
        if ( is_wp_error( $streamed ) ) {
            return $streamed;
        }
        exit;
    }

    public function trigger_callback( WP_REST_Request $request ) {
        $scope       = sanitize_text_field( (string) $request->get_param( 'scope' ) );
        $remote_mode = sanitize_text_field( (string) $request->get_param( 'remote_mode' ) );
        $folders     = $request->get_param( 'folders' );
        $folders     = is_array( $folders ) ? $folders : array();

        $result = $this->admin->queue_async_backup_request(
            $scope,
            $remote_mode,
            $folders,
            array(
                'context_label'  => 'Remote trigger',
                'trigger_source' => 'remote-api',
                'queued_message' => 'Backup queued from remote trigger request.',
            )
        );

        if ( is_wp_error( $result ) ) {
            $status = 'rb_backup_running' === $result->get_error_code() ? 409 : 400;

            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array(
                    'status' => $status,
                    'data'   => $result->get_error_data(),
                )
            );
        }

        return rest_ensure_response(
            array(
                'site'       => home_url(),
                'job'        => $result,
                'active_job' => $this->admin->get_backup_job_status_payload( $result['jobId'] ?? '' ),
            )
        );
    }

    public function authorize_pull_request( WP_REST_Request $request ) {
        $configured = self::get_pull_token();
        $provided   = $this->request_pull_token( $request );

        if ( '' === $configured || '' === $provided || ! hash_equals( $configured, $provided ) ) {
            return new WP_Error( 'rb_unauthorized', 'Invalid pull token.', array( 'status' => 401 ) );
        }

        return true;
    }

    private function safe_backup_summary( $backup ) {
        if ( empty( $backup ) || ! is_array( $backup ) ) {
            return null;
        }

        $item = array(
            'date'     => $backup['date'] ?? null,
            'date_gmt' => ! empty( $backup['date'] ) ? get_gmt_from_date( $backup['date'], 'Y-m-d H:i:s' ) : null,
            'scope'    => $backup['scope'] ?? null,
            'status'   => $backup['status'] ?? 'success',
        );

        if ( 'failed' === $item['status'] && ! empty( $backup['error'] ) ) {
            $item['error'] = $backup['error'];
        }

        return $item;
    }

    private function request_pull_token( WP_REST_Request $request ) {
        $token = trim( (string) $request->get_header( 'x-rb-pull-token' ) );
        if ( '' !== $token ) {
            return $token;
        }

        return trim( (string) $request->get_param( 'pull_token' ) );
    }

    private function download_url( $backup_id, $artifact ) {
        return rest_url(
            sprintf(
                'remote-backup/v1/download/%1$s/%2$s',
                rawurlencode( (string) $backup_id ),
                rawurlencode( (string) $artifact )
            )
        );
    }
}
