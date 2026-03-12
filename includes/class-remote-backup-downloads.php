<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remote_Backup_Downloads {

    private $storage;

    public function __construct( Remote_Backup_Storage $storage ) {
        $this->storage = $storage;
    }

    public function init() {
        add_action( 'admin_init', array( $this, 'handle_download' ) );
    }

    public function handle_download() {
        if ( empty( $_GET['rb_download'] ) || empty( $_GET['rb_id'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        check_admin_referer( 'rb_download' );

        $id   = sanitize_text_field( wp_unslash( $_GET['rb_id'] ) );
        $type = sanitize_text_field( wp_unslash( $_GET['rb_download'] ) );

        $backup = $this->storage->get_backup( $id );
        if ( ! $backup ) {
            wp_die( 'Backup not found.', 404 );
        }

        $key_map = array(
            'database' => 'db_file',
            'files'    => 'files_file',
            'plugins'  => 'plugins_file',
        );

        if ( ! isset( $key_map[ $type ] ) || empty( $backup[ $key_map[ $type ] ] ) ) {
            wp_die( 'Artifact not available for this backup.', 404 );
        }

        $filename = $backup[ $key_map[ $type ] ];
        // Prevent path traversal.
        $filename = basename( $filename );
        $filepath = $this->storage->resolve_storage_path( $filename );

        if ( ! file_exists( $filepath ) ) {
            wp_die( 'Backup file not found on disk.', 404 );
        }

        // Serve the file.
        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Accel-Buffering: no' );
        header( 'Content-Length: ' . filesize( $filepath ) );
        $streamed = $this->storage->stream_file( $filepath );
        if ( is_wp_error( $streamed ) ) {
            wp_die( esc_html( $streamed->get_error_message() ), 500 );
        }
        exit;
    }

    public function download_url( $backup_id, $type ) {
        return wp_nonce_url(
            admin_url( 'admin.php?page=savedpixel-remote-backup&rb_download=' . urlencode( $type ) . '&rb_id=' . urlencode( $backup_id ) ),
            'rb_download'
        );
    }
}
