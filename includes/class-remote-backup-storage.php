<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'Remote_Backup_Storage' ) ) {
    return;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Large downloads are streamed with native PHP handles.
class Remote_Backup_Storage {

    public function ensure_directories() {
        $dirs = array(
            RB_BASE_DIR,
            RB_STORAGE_DIR,
            RB_DATA_DIR,
        );
        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
        }

        // Protect backup data and storage roots from direct web access.
        foreach ( array( RB_BASE_DIR, RB_STORAGE_DIR ) as $protected_dir ) {
            $htaccess = trailingslashit( $protected_dir ) . '.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
            }
        }

        // Create index.php guards.
        foreach ( $dirs as $dir ) {
            $this->ensure_guard_file( $dir );
        }

        // Seed empty manifest.
        $manifest = $this->manifest_path();
        if ( ! file_exists( $manifest ) ) {
            file_put_contents( $manifest, wp_json_encode( array( 'backups' => array() ) ) );
        }
    }

    public function is_writable() {
        return is_dir( RB_STORAGE_DIR ) && wp_is_writable( RB_STORAGE_DIR );
    }

    /* ── Manifest helpers ─────────────────────────────── */

    private function manifest_path() {
        return RB_DATA_DIR . 'backups.json';
    }

    public function get_backups() {
        $path = $this->manifest_path();
        if ( ! file_exists( $path ) ) {
            return array();
        }
        $data = json_decode( file_get_contents( $path ), true );
        if ( ! is_array( $data ) || ! isset( $data['backups'] ) ) {
            return array();
        }
        // Backward compat: default missing status to 'success'.
        return array_map( function( $b ) {
            if ( ! isset( $b['status'] ) ) {
                $b['status'] = 'success';
                $b['error']  = null;
            }
            if ( ! array_key_exists( 'remote_status', $b ) ) {
                $b['remote_status'] = null;
            }
            if ( ! array_key_exists( 'remote_message', $b ) ) {
                $b['remote_message'] = null;
            }
            if ( ! array_key_exists( 'remote_uploaded_at', $b ) ) {
                $b['remote_uploaded_at'] = null;
            }
            if ( ! array_key_exists( 'remote_destination', $b ) ) {
                $b['remote_destination'] = null;
            }
            return $b;
        }, $data['backups'] );
    }

    public function add_backup( $entry ) {
        $this->ensure_directories();
        $backups   = $this->get_backups();
        $backups[] = $entry;
        file_put_contents(
            $this->manifest_path(),
            wp_json_encode( array( 'backups' => $backups ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );
    }

    public function get_backup( $id ) {
        foreach ( $this->get_backups() as $b ) {
            if ( isset( $b['id'] ) && $b['id'] === $id ) {
                return $b;
            }
        }
        return null;
    }

    public function update_backup( $id, $changes ) {
        if ( empty( $id ) || ! is_array( $changes ) || empty( $changes ) ) {
            return null;
        }

        $backups = $this->get_backups();
        $updated = null;

        foreach ( $backups as &$backup ) {
            if ( isset( $backup['id'] ) && $backup['id'] === $id ) {
                $backup  = array_merge( $backup, $changes );
                $updated = $backup;
                break;
            }
        }
        unset( $backup );

        if ( null === $updated ) {
            return null;
        }

        file_put_contents(
            $this->manifest_path(),
            wp_json_encode( array( 'backups' => $backups ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );

        return $updated;
    }

    public function delete_backup( $id ) {
        $backups = $this->get_backups();
        $updated = array();
        $removed = null;
        foreach ( $backups as $b ) {
            if ( isset( $b['id'] ) && $b['id'] === $id ) {
                $removed = $b;
            } else {
                $updated[] = $b;
            }
        }
        if ( $removed ) {
            // Remove artifact files.
            foreach ( array( 'db_file', 'files_file', 'plugins_file' ) as $key ) {
                if ( ! empty( $removed[ $key ] ) ) {
                    $path = $this->resolve_storage_path( $removed[ $key ] );
                    if ( file_exists( $path ) ) {
                        wp_delete_file( $path );
                    }
                }
            }
            file_put_contents(
                $this->manifest_path(),
                wp_json_encode( array( 'backups' => $updated ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            );
        }
        return $removed;
    }

    public function artifact_path( $filename ) {
        return RB_STORAGE_DIR . $filename;
    }

    public function resolve_storage_path( $path ) {
        $path = ltrim( (string) $path, '/' );

        foreach ( $this->storage_roots() as $root ) {
            $candidate = trailingslashit( $root ) . $path;
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        return RB_STORAGE_DIR . $path;
    }

    public function stream_file( $filepath, $chunk_size = 1048576 ) {
        $filepath = (string) $filepath;

        if ( ! file_exists( $filepath ) ) {
            return new WP_Error( 'rb_stream_missing', 'Backup file not found on disk.' );
        }

        if ( ! is_readable( $filepath ) ) {
            return new WP_Error( 'rb_stream_unreadable', 'Backup file is not readable.' );
        }

        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large downloads need extended execution time.
        }

        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }

        $handle = fopen( $filepath, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'rb_stream_open', 'Backup file could not be opened for download.' );
        }

        $chunk_size = max( 8192, (int) $chunk_size );

        while ( ! feof( $handle ) ) {
            $buffer = fread( $handle, $chunk_size );

            if ( false === $buffer ) {
                fclose( $handle );
                return new WP_Error( 'rb_stream_read', 'Backup file could not be read during download.' );
            }

            if ( '' === $buffer ) {
                continue;
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary file stream.
            echo $buffer;

            if ( connection_aborted() ) {
                break;
            }

            flush();
        }

        fclose( $handle );

        return true;
    }

    public function get_backup_artifacts( $backup ) {
        $artifacts = array();
        $map       = array(
            'database' => 'db_file',
            'files'    => 'files_file',
            'plugins'  => 'plugins_file',
        );

        foreach ( $map as $type => $key ) {
            if ( empty( $backup[ $key ] ) ) {
                continue;
            }

            $filename = basename( (string) $backup[ $key ] );
            $path     = $this->resolve_storage_path( $filename );
            if ( ! file_exists( $path ) ) {
                continue;
            }

            $artifacts[] = array(
                'type'     => $type,
                'filename' => $filename,
                'size'     => filesize( $path ),
            );
        }

        return $artifacts;
    }

    public function latest_successful_backup() {
        $backups = $this->get_backups();
        if ( empty( $backups ) ) {
            return null;
        }

        usort( $backups, function ( $a, $b ) {
            return strcmp( $b['date'] ?? '', $a['date'] ?? '' );
        } );

        foreach ( $backups as $backup ) {
            if ( 'success' === ( $backup['status'] ?? 'success' ) ) {
                return $backup;
            }
        }

        return null;
    }

    public function backup_to_api_payload( $backup ) {
        if ( empty( $backup['id'] ) ) {
            return null;
        }

        $payload = array(
            'id'              => (string) $backup['id'],
            'date'            => $backup['date'] ?? null,
            'date_gmt'        => ! empty( $backup['date'] ) ? get_gmt_from_date( $backup['date'], 'Y-m-d H:i:s' ) : null,
            'scope'           => $backup['scope'] ?? null,
            'status'          => $backup['status'] ?? 'success',
            'total_size'      => (int) ( $backup['total_size'] ?? 0 ),
            'db_size'         => (int) ( $backup['db_size'] ?? 0 ),
            'files_size'      => (int) ( $backup['files_size'] ?? 0 ),
            'plugins_size'    => (int) ( $backup['plugins_size'] ?? 0 ),
            'remote_status'   => $backup['remote_status'] ?? null,
            'artifacts'       => $this->get_backup_artifacts( $backup ),
        );

        if ( 'failed' === $payload['status'] && ! empty( $backup['error'] ) ) {
            $payload['error'] = $backup['error'];
        }

        return $payload;
    }

    public function remote_pull_site_key( $url ) {
        return sanitize_key( substr( md5( strtolower( untrailingslashit( (string) $url ) ) ), 0, 12 ) );
    }

    public function remote_pull_site_slug( $url ) {
        $host = strtolower( (string) wp_parse_url( esc_url_raw( (string) $url ), PHP_URL_HOST ) );
        $slug = preg_replace( '/[^a-z0-9.\-]+/', '-', $host );
        $slug = trim( (string) $slug, '.-' );

        if ( '' === $slug ) {
            $slug = $this->remote_pull_site_key( $url );
        }

        return $slug;
    }

    private function remote_pull_primary_site_dir_path( $url ) {
        return trailingslashit( RB_STORAGE_DIR . $this->remote_pull_site_slug( $url ) );
    }

    private function remote_pull_legacy_site_dir_paths( $url ) {
        $slug = $this->remote_pull_site_slug( $url );
        $key  = $this->remote_pull_site_key( $url );

        return array_unique(
            array(
                trailingslashit( $this->legacy_storage_root() . 'pulled/' . $slug ),
                trailingslashit( $this->legacy_storage_root() . 'pulled/' . $key ),
            )
        );
    }

    public function remote_pull_site_dir( $url ) {
        $dir = $this->remote_pull_primary_site_dir_path( $url );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $this->ensure_guard_file( $dir );

        return $dir;
    }

    public function remote_pull_site_dirs( $url ) {
        $dirs    = array();
        $primary = $this->remote_pull_primary_site_dir_path( $url );

        if ( is_dir( $primary ) ) {
            $dirs[] = $primary;
        }

        foreach ( $this->remote_pull_legacy_site_dir_paths( $url ) as $legacy ) {
            if ( $legacy !== $primary && is_dir( $legacy ) ) {
                $dirs[] = $legacy;
            }
        }

        return array_values( array_unique( $dirs ) );
    }

    public function remote_pull_backup_dir( $url, $backup_id = '' ) {
        unset( $backup_id );

        return $this->remote_pull_site_dir( $url );
    }

    public function remote_pull_backup_manifest_path( $url, $backup_id ) {
        $backup_id = sanitize_file_name( (string) $backup_id );

        return trailingslashit( $this->remote_pull_site_dir( $url ) ) . '.manifest-' . $backup_id . '.json';
    }

    public function remote_pull_artifact_path( $url, $backup_id, $type, $filename ) {
        $filename = $this->remote_pull_artifact_filename( $backup_id, $type, $filename );

        return trailingslashit( $this->remote_pull_site_dir( $url ) ) . $filename;
    }

    public function relative_storage_path( $path ) {
        $path = wp_normalize_path( (string) $path );

        foreach ( $this->storage_roots() as $storage_dir ) {
            $storage_dir = trailingslashit( wp_normalize_path( $storage_dir ) );
            if ( 0 === strpos( $path, $storage_dir ) ) {
                return ltrim( substr( $path, strlen( $storage_dir ) ), '/' );
            }
        }

        return ltrim( $path, '/' );
    }

    public function get_remote_pulled_backups( $url, $limit = 20 ) {
        $items = array();

        foreach ( $this->remote_pull_site_dirs( $url ) as $site_dir ) {
            $manifests = array_merge(
                glob( trailingslashit( $site_dir ) . '.manifest-*.json' ) ?: array(),
                glob( trailingslashit( $site_dir ) . '*/manifest.json' ) ?: array()
            );

            foreach ( $manifests as $manifest_path ) {
                $item = $this->read_remote_pulled_backup_manifest( $manifest_path );
                if ( ! empty( $item ) ) {
                    $items[] = $item;
                }
            }
        }

        usort(
            $items,
            function ( $a, $b ) {
                return strcmp(
                    (string) ( $b['downloaded_at'] ?? $b['backup_id'] ?? '' ),
                    (string) ( $a['downloaded_at'] ?? $a['backup_id'] ?? '' )
                );
            }
        );

        return array_slice( $items, 0, max( 1, (int) $limit ) );
    }

    private function read_remote_pulled_backup_manifest( $manifest_path ) {
        if ( ! file_exists( $manifest_path ) ) {
            return null;
        }

        $data = json_decode( file_get_contents( $manifest_path ), true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        $backup   = is_array( $data['backup'] ?? null ) ? $data['backup'] : array();
        $base_dir = dirname( $manifest_path );

        $artifacts = is_array( $data['stored_artifacts'] ?? null ) ? $data['stored_artifacts'] : array();
        if ( empty( $artifacts ) && ! empty( $backup['artifacts'] ) && is_array( $backup['artifacts'] ) ) {
            foreach ( $backup['artifacts'] as $artifact ) {
                $artifacts[] = $artifact;
            }
        }

        $stored_artifacts = array();
        foreach ( $artifacts as $artifact ) {
            $stored_artifact = $this->normalize_remote_pulled_artifact( $artifact, $base_dir );
            if ( ! empty( $stored_artifact ) ) {
                $stored_artifacts[] = $stored_artifact;
            }
        }

        return array(
            'backup_id'     => sanitize_text_field( (string) ( $backup['id'] ?? $this->backup_id_from_manifest_path( $manifest_path ) ?? basename( $base_dir ) ) ),
            'downloaded_at' => $data['downloaded_at'] ?? null,
            'storage_dir'   => $this->relative_storage_path( $base_dir ),
            'artifacts'     => $stored_artifacts,
        );
    }

    private function normalize_remote_pulled_artifact( $artifact, $base_dir ) {
        $filename = basename( (string) ( $artifact['filename'] ?? '' ) );
        if ( '' === $filename ) {
            return null;
        }

        $relative_path = ltrim( (string) ( $artifact['relative_path'] ?? '' ), '/' );
        $path          = '';

        if ( '' !== $relative_path ) {
            $path = $this->resolve_storage_path( $relative_path );
        }

        if ( '' === $path || ! file_exists( $path ) ) {
            $path = trailingslashit( $base_dir ) . $filename;
        }

        $size = isset( $artifact['size'] ) ? (int) $artifact['size'] : 0;
        if ( $size <= 0 && file_exists( $path ) ) {
            $size = (int) filesize( $path );
        }

        if ( '' === $relative_path ) {
            $relative_path = $this->relative_storage_path( $path );
        }

        return array(
            'type'          => sanitize_text_field( (string) ( $artifact['type'] ?? '' ) ),
            'filename'      => $filename,
            'size'          => $size,
            'size_label'    => $size > 0 ? size_format( $size ) : null,
            'relative_path' => ltrim( (string) $relative_path, '/' ),
        );
    }

    public function migrate_legacy_remote_pulled_storage() {
        $this->ensure_directories();

        $stats       = array(
            'backups'   => 0,
            'artifacts' => 0,
            'skipped'   => 0,
        );
        $legacy_root = trailingslashit( $this->legacy_storage_root() . 'pulled' );

        if ( ! is_dir( $legacy_root ) ) {
            return $stats;
        }

        $manifests = glob( $legacy_root . '*/*/manifest.json' );
        if ( empty( $manifests ) ) {
            return $stats;
        }

        foreach ( $manifests as $manifest_path ) {
            $data = json_decode( file_get_contents( $manifest_path ), true );
            if ( ! is_array( $data ) ) {
                $stats['skipped']++;
                continue;
            }

            $site_url = esc_url_raw( (string) ( $data['site_url'] ?? '' ) );
            if ( '' === $site_url ) {
                $site_url = 'https://' . basename( dirname( dirname( $manifest_path ) ) );
            }

            $backup    = is_array( $data['backup'] ?? null ) ? $data['backup'] : array();
            $backup_id = sanitize_text_field( (string) ( $backup['id'] ?? basename( dirname( $manifest_path ) ) ) );
            $source_dir = dirname( $manifest_path );
            $artifacts  = is_array( $data['stored_artifacts'] ?? null ) ? $data['stored_artifacts'] : array();

            if ( '' === $site_url || '' === $backup_id ) {
                $stats['skipped']++;
                continue;
            }

            if ( empty( $artifacts ) && ! empty( $backup['artifacts'] ) && is_array( $backup['artifacts'] ) ) {
                $artifacts = $backup['artifacts'];
            }

            $stored_artifacts = array();

            foreach ( $artifacts as $artifact ) {
                $type            = sanitize_text_field( (string) ( $artifact['type'] ?? '' ) );
                $source_filename = basename( (string) ( $artifact['filename'] ?? '' ) );
                $source_path     = trailingslashit( $source_dir ) . $source_filename;

                if ( '' === $source_filename || ! file_exists( $source_path ) ) {
                    continue;
                }

                $target_path = $this->remote_pull_artifact_path( $site_url, $backup_id, $type, $source_filename );
                $target_dir  = dirname( $target_path );

                if ( ! is_dir( $target_dir ) ) {
                    wp_mkdir_p( $target_dir );
                }
                $this->ensure_guard_file( $target_dir );

                if ( ! file_exists( $target_path ) || (int) filesize( $target_path ) !== (int) filesize( $source_path ) ) {
                    copy( $source_path, $target_path );
                }

                $stored_artifacts[] = array(
                    'type'          => $type,
                    'filename'      => basename( $target_path ),
                    'size'          => (int) filesize( $target_path ),
                    'relative_path' => $this->relative_storage_path( $target_path ),
                );
                $stats['artifacts']++;
            }

            if ( empty( $stored_artifacts ) ) {
                $stats['skipped']++;
                continue;
            }

            file_put_contents(
                $this->remote_pull_backup_manifest_path( $site_url, $backup_id ),
                wp_json_encode(
                    array(
                        'site_url'         => $site_url,
                        'site_label'       => $data['site_label'] ?? '',
                        'downloaded_at'    => $data['downloaded_at'] ?? null,
                        'backup'           => $backup,
                        'stored_artifacts' => $stored_artifacts,
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                )
            );

            $stats['backups']++;
        }

        return $stats;
    }

    private function storage_roots() {
        $roots  = array( trailingslashit( wp_normalize_path( RB_STORAGE_DIR ) ) );
        $legacy = trailingslashit( wp_normalize_path( $this->legacy_storage_root() ) );

        if ( $legacy !== $roots[0] ) {
            $roots[] = $legacy;
        }

        return $roots;
    }

    private function legacy_storage_root() {
        return trailingslashit( RB_BASE_DIR . 'storage/' );
    }

    private function backup_id_from_manifest_path( $manifest_path ) {
        $filename = basename( (string) $manifest_path );
        if ( preg_match( '/^\.manifest-(.+)\.json$/', $filename, $matches ) ) {
            return sanitize_text_field( (string) $matches[1] );
        }

        return null;
    }

    private function remote_pull_artifact_filename( $backup_id, $type, $filename ) {
        $timestamp = $this->remote_pull_backup_timestamp( $backup_id, $filename );
        $prefix    = $this->remote_pull_artifact_prefix( $type );
        $extension = $this->remote_pull_artifact_extension( $filename );

        return sanitize_file_name( "{$prefix}-{$timestamp}" ) . $extension;
    }

    private function remote_pull_backup_timestamp( $backup_id, $filename ) {
        if ( preg_match( '/^(\d{8}-\d{6})/', (string) $backup_id, $matches ) ) {
            return $matches[1];
        }

        if ( preg_match( '/(\d{8}-\d{6})/', (string) $filename, $matches ) ) {
            return $matches[1];
        }

        return sanitize_file_name( (string) $backup_id );
    }

    private function remote_pull_artifact_prefix( $type ) {
        $map = array(
            'database' => 'db',
            'files'    => 'files',
            'plugins'  => 'plugins',
        );

        $type = sanitize_key( (string) $type );

        return $map[ $type ] ?? ( '' !== $type ? $type : 'artifact' );
    }

    private function remote_pull_artifact_extension( $filename ) {
        $filename = strtolower( basename( (string) $filename ) );

        if ( preg_match( '/\.sql\.gz$/', $filename ) ) {
            return '.sql.gz';
        }

        $extension = pathinfo( $filename, PATHINFO_EXTENSION );

        return '' !== $extension ? '.' . $extension : '';
    }

    /**
     * Purge old backups beyond retention limits.
     *
     * @param int $keep_db    Max database-containing backups to keep (0 = unlimited).
     * @param int $keep_files  Max files-containing backups to keep (0 = unlimited).
     * @return int Number of backups deleted.
     */
    public function purge_beyond_retention( $keep_db = 0, $keep_files = 0 ) {
        if ( $keep_db <= 0 && $keep_files <= 0 ) {
            return 0;
        }

        $backups = $this->get_backups();
        if ( empty( $backups ) ) {
            return 0;
        }

        // Sort newest-first by date.
        usort( $backups, function ( $a, $b ) {
            return strcmp( $b['date'] ?? '', $a['date'] ?? '' );
        } );

        $ids_to_delete = array();
        $db_count    = 0;
        $files_count = 0;

        foreach ( $backups as $b ) {
            if ( ( $b['status'] ?? '' ) !== 'success' ) {
                continue;
            }

            $has_db    = ! empty( $b['db_file'] );
            $has_files = ! empty( $b['files_file'] );
            $dominated = true;

            if ( $has_db ) {
                $db_count++;
            }
            if ( $has_files ) {
                $files_count++;
            }

            // Keep this backup if any of its types are still within limits.
            if ( $has_db && ( $keep_db <= 0 || $db_count <= $keep_db ) ) {
                $dominated = false;
            }
            if ( $has_files && ( $keep_files <= 0 || $files_count <= $keep_files ) ) {
                $dominated = false;
            }
            if ( ! $has_db && ! $has_files ) {
                $dominated = false;
            }

            if ( $dominated ) {
                $ids_to_delete[] = $b['id'];
            }
        }

        foreach ( $ids_to_delete as $id ) {
            $this->delete_backup( $id );
        }

        return count( $ids_to_delete );
    }

    private function ensure_guard_file( $dir ) {
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.' );
        }
    }
}
