<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Backup export intentionally streams raw schema/data and may run for a long time.
class Remote_Backup_Runner {

    private $storage;
    private $logger;

    public function __construct( Remote_Backup_Storage $storage, $logger = null ) {
        $this->storage = $storage;
        $this->logger  = $logger;
    }

    private function log( $msg, $level = 'info' ) {
        if ( $this->logger ) {
            $this->logger->log( $msg, $level );
        }
    }

    /**
     * Run a backup.
     *
     * @param string $scope  'database', 'files', or 'both'.
     * @return array|WP_Error  Backup manifest entry on success.
     */
    /**
     * @param string $scope   'database', 'files', or 'both'.
     * @param array  $folders  Optional list of top-level directory names to include in file backups.
     */
    public function run( $scope = 'both', $folders = array() ) {
        @set_time_limit( 0 );
        $this->log( "Backup started — scope: {$scope}" );
        $this->set_progress( 'starting' );

        if ( ! $this->storage->is_writable() ) {
            $this->log( 'Storage directory not writable', 'error' );
            $this->set_progress( 'failed' );
            return new WP_Error( 'rb_storage', 'Backup storage directory is not writable.' );
        }

        $id        = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false );
        $timestamp = current_time( 'mysql' );
        $entry     = array(
            'id'        => $id,
            'scope'     => $scope,
            'date'      => $timestamp,
            'status'    => 'running',
            'error'     => null,
            'remote_status'      => null,
            'remote_message'     => null,
            'remote_uploaded_at' => null,
            'remote_destination' => null,
            'db_file'   => null,
            'db_size'   => 0,
            'files_file'   => null,
            'files_size'   => 0,
            'plugins_file' => null,
            'plugins_size' => 0,
            'total_size'   => 0,
        );

        $this->storage->add_backup( $entry );

        // Database backup.
        if ( in_array( $scope, array( 'database', 'both' ), true ) ) {
            $this->set_progress( 'database' );
            $result = $this->backup_database( $id );
            if ( is_wp_error( $result ) ) {
                return $this->fail_backup_run( $entry, $result->get_error_message(), 'database' );
            }
            $entry['db_file'] = $result['filename'];
            $entry['db_size'] = $result['size'];
            $this->storage->update_backup(
                $id,
                array(
                    'db_file' => $entry['db_file'],
                    'db_size' => $entry['db_size'],
                )
            );
            $this->set_progress( 'database', array(
                'db_size'    => $entry['db_size'],
                'total_size' => $entry['db_size'],
            ) );
        }

        // Files backup.
        if ( in_array( $scope, array( 'files', 'both' ), true ) ) {
            $effective_folders = $this->resolve_file_folders( $folders );
            $this->set_progress( 'files', array(
                'db_size'    => $entry['db_size'],
                'total_size' => $entry['db_size'],
            ) );
            $result = $this->backup_files( $id, $effective_folders );
            if ( is_wp_error( $result ) ) {
                return $this->fail_backup_run( $entry, $result->get_error_message(), 'files' );
            }
            $entry['files_file'] = $result['filename'];
            $entry['files_size'] = $result['size'];
            $this->storage->update_backup(
                $id,
                array(
                    'files_file' => $entry['files_file'],
                    'files_size' => $entry['files_size'],
                )
            );
            $this->set_progress( 'files', array(
                'db_size'    => $entry['db_size'],
                'files_size' => $entry['files_size'],
                'total_size' => $entry['db_size'] + $entry['files_size'],
            ) );

            if ( $this->should_create_plugins_archive( $effective_folders ) ) {
                // Plugins-only convenience archive for full file backups.
                $this->set_progress( 'plugins', array(
                    'db_size'    => $entry['db_size'],
                    'files_size' => $entry['files_size'],
                    'total_size' => $entry['db_size'] + $entry['files_size'],
                ) );
                $result = $this->backup_plugins( $id );
                if ( is_wp_error( $result ) ) {
                    return $this->fail_backup_run( $entry, $result->get_error_message(), 'plugins' );
                }
                $entry['plugins_file'] = $result['filename'];
                $entry['plugins_size'] = $result['size'];
                $this->storage->update_backup(
                    $id,
                    array(
                        'plugins_file' => $entry['plugins_file'],
                        'plugins_size' => $entry['plugins_size'],
                    )
                );
                $this->set_progress( 'plugins', array(
                    'db_size'    => $entry['db_size'],
                    'files_size' => $entry['files_size'] + $entry['plugins_size'],
                    'total_size' => $entry['db_size'] + $entry['files_size'] + $entry['plugins_size'],
                ) );
            } else {
                $this->log( 'Skipping plugins-only archive because a custom folder selection is active.' );
            }
        }

        $entry['total_size'] = $entry['db_size'] + $entry['files_size'] + $entry['plugins_size'];
        $entry['status']     = 'success';

        $this->storage->update_backup(
            $id,
            array(
                'status'      => 'success',
                'error'       => null,
                'db_file'     => $entry['db_file'],
                'db_size'     => $entry['db_size'],
                'files_file'  => $entry['files_file'],
                'files_size'  => $entry['files_size'],
                'plugins_file'=> $entry['plugins_file'],
                'plugins_size'=> $entry['plugins_size'],
                'total_size'  => $entry['total_size'],
            )
        );

        // Purge old backups per retention settings.
        $keep_db    = (int) get_option( 'rb_retain_db', 0 );
        $keep_files = (int) get_option( 'rb_retain_files', 0 );
        if ( $keep_db > 0 || $keep_files > 0 ) {
            $purged = $this->storage->purge_beyond_retention( $keep_db, $keep_files );
            if ( $purged > 0 ) {
                $this->log( "Retention purge: deleted {$purged} old backup(s)" );
            }
        }

        $this->set_progress( 'complete', array(
            'db_size'    => $entry['db_size'],
            'files_size' => $entry['files_size'] + $entry['plugins_size'],
            'total_size' => $entry['total_size'],
        ) );
        $this->log( "Backup completed — id: {$id}, total: " . size_format( $entry['total_size'] ) );

        return $entry;
    }

    private function fail_backup_run( $entry, $message, $step ) {
        $entry['status'] = 'failed';
        $entry['error']  = (string) $message;
        $updated = $this->storage->update_backup(
            $entry['id'] ?? '',
            array(
                'status'       => 'failed',
                'error'        => $entry['error'],
                'db_file'      => $entry['db_file'] ?? null,
                'db_size'      => $entry['db_size'] ?? 0,
                'files_file'   => $entry['files_file'] ?? null,
                'files_size'   => $entry['files_size'] ?? 0,
                'plugins_file' => $entry['plugins_file'] ?? null,
                'plugins_size' => $entry['plugins_size'] ?? 0,
                'total_size'   => (int) ( $entry['db_size'] ?? 0 ) + (int) ( $entry['files_size'] ?? 0 ) + (int) ( $entry['plugins_size'] ?? 0 ),
            )
        );
        if ( null === $updated ) {
            $this->storage->add_backup( $entry );
        }
        $this->set_progress( 'failed' );
        $this->log(
            sprintf(
                'Backup failed — id: %1$s, scope: %2$s, step: %3$s, error: %4$s',
                $entry['id'] ?? 'unknown',
                $entry['scope'] ?? 'unknown',
                $step,
                $entry['error']
            ),
            'error'
        );

        return $entry;
    }

    /* ── Progress tracking via transient ──────────────── */

    public function set_progress( $phase, $sizes = array() ) {
        $data = array_merge(
            array(
                'phase'      => $phase,
                'db_size'    => 0,
                'files_size' => 0,
                'total_size' => 0,
            ),
            $sizes
        );
        set_transient( 'rb_backup_progress', $data, 600 );
    }

    public function get_progress() {
        $data = get_transient( 'rb_backup_progress' );
        if ( is_array( $data ) ) {
            return $data;
        }
        // Legacy string value.
        if ( is_string( $data ) && '' !== $data ) {
            return array( 'phase' => $data, 'db_size' => 0, 'files_size' => 0, 'total_size' => 0 );
        }
        return array( 'phase' => 'idle', 'db_size' => 0, 'files_size' => 0, 'total_size' => 0 );
    }

    /* ── Database dump ────────────────────────────────── */

    private function backup_database( $id ) {
        global $wpdb;

        $filename = "db-{$id}.sql.gz";
        $path     = $this->storage->artifact_path( $filename );

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            return new WP_Error( 'rb_db', 'No database tables found.' );
        }

        $gz = gzopen( $path, 'wb9' );
        if ( ! $gz ) {
            return new WP_Error( 'rb_db', 'Failed to open gzip stream for database dump.' );
        }

        foreach ( $tables as $table ) {
            // Table structure.
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( $create ) {
                gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );
                gzwrite( $gz, $create[1] . ";\n\n" );
            }

            // Table data in batches.
            $offset = 0;
            $batch  = 500;
            while ( true ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset ),
                    ARRAY_A
                );
                if ( empty( $rows ) ) {
                    break;
                }
                foreach ( $rows as $row ) {
                    $cols   = array_map( function( $v ) use ( $wpdb ) {
                        return null === $v ? 'NULL' : "'" . esc_sql( $v ) . "'";
                    }, array_values( $row ) );
                    $insert = "INSERT INTO `{$table}` VALUES(" . implode( ',', $cols ) . ");\n";
                    gzwrite( $gz, $insert );
                }
                $offset += $batch;
            }
            gzwrite( $gz, "\n" );
        }

        gzclose( $gz );

        return array(
            'filename' => $filename,
            'size'     => filesize( $path ),
        );
    }

    /* ── Files archive ────────────────────────────────── */

    private function backup_files( $id, $folders = array() ) {
        $folders = $this->resolve_file_folders( $folders );

        $filename = "files-{$id}.zip";
        $path     = $this->storage->artifact_path( $filename );
        $base_dir = trailingslashit( ABSPATH );
        $exclude  = array( RB_STORAGE_DIR, RB_BASE_DIR, $base_dir . '.git/' );
        $files    = array();

        if ( ! empty( $folders ) ) {
            // Separate top-level dirs from nested paths (e.g. "wp-content/themes").
            $top_dirs  = array();
            $sub_paths = array(); // keyed by parent → array of children
            foreach ( $folders as $f ) {
                if ( false !== strpos( $f, '/' ) ) {
                    list( $parent, $child ) = explode( '/', $f, 2 );
                    $sub_paths[ $parent ][] = $child;
                } else {
                    $top_dirs[] = $f;
                }
            }

            // Separate root files from root directories in the selection.
            $top_files = array();
            $real_dirs = array();
            foreach ( $top_dirs as $td ) {
                if ( is_file( $base_dir . $td ) ) {
                    $top_files[] = $td;
                } else {
                    $real_dirs[] = $td;
                }
            }

            // Include selected root-level files.
            foreach ( $top_files as $tf ) {
                $this->add_file_to_archive_list( $base_dir . $tf, $tf, $files, $exclude );
            }

            // Process directories.
            foreach ( scandir( $base_dir ) as $item ) {
                if ( '.' === $item || '..' === $item ) {
                    continue;
                }
                $full = $base_dir . $item;
                if ( ! is_dir( $full ) ) {
                    continue;
                }

                if ( in_array( $item, $real_dirs, true ) ) {
                    // Top-level selected — include entire directory.
                    $this->collect_directory_files( $full . '/', $item . '/', $files, $exclude );
                } elseif ( isset( $sub_paths[ $item ] ) ) {
                    // Only specific subdirectories selected.
                    foreach ( $sub_paths[ $item ] as $child ) {
                        $child_full = $full . '/' . $child;
                        if ( is_dir( $child_full ) ) {
                            $this->collect_directory_files( $child_full . '/', $item . '/' . $child . '/', $files, $exclude );
                        }
                    }
                    // Also include files directly inside the parent (not subdirs).
                    foreach ( scandir( $full ) as $pf ) {
                        if ( '.' === $pf || '..' === $pf ) continue;
                        $pf_full = $full . '/' . $pf;
                        if ( ! is_dir( $pf_full ) ) {
                            $this->add_file_to_archive_list( $pf_full, $item . '/' . $pf, $files, $exclude );
                        }
                    }
                }
            }
        } else {
            // No selection — back up everything (original behaviour).
            $this->collect_directory_files( $base_dir, '', $files, $exclude );
        }

        return $this->create_zip_archive( $path, $files, $base_dir, 'files backup' );
    }

    private function resolve_file_folders( $folders = array() ) {
        if ( empty( $folders ) ) {
            $folders = get_option( 'rb_backup_folders', array() );
        }

        if ( ! is_array( $folders ) ) {
            return array();
        }

        $folders = array_values( array_unique( array_filter( array_map( 'strval', $folders ) ) ) );

        return $folders;
    }

    private function should_create_plugins_archive( $folders ) {
        return empty( $folders );
    }

    /* ── Plugins-only archive ─────────────────────────── */

    private function backup_plugins( $id ) {
        $filename    = "plugins-{$id}.zip";
        $path        = $this->storage->artifact_path( $filename );
        $plugins_dir = WP_PLUGIN_DIR . '/';
        $files       = array();

        $this->collect_directory_files( $plugins_dir, 'plugins/', $files, array(
            RB_STORAGE_DIR,
        ) );

        return $this->create_zip_archive( $path, $files, WP_CONTENT_DIR . '/', 'plugins backup' );
    }

    /* ── Zip helper ───────────────────────────────────── */

    private function create_zip_archive( $path, $files, $remove_root, $context ) {
        if ( empty( $files ) ) {
            return new WP_Error( 'rb_zip', 'No files were available to archive.' );
        }

        $backend = $this->available_zip_backend();
        if ( ! $backend ) {
            return new WP_Error( 'rb_zip', 'No ZIP backend is available. Enable the PHP ZipArchive extension, install the zip binary, or allow the WordPress PclZip fallback.' );
        }

        if ( 'ziparchive' !== $backend ) {
            $level = 'shell' === $backend ? 'info' : 'warning';
            $this->log( sprintf( '%s is using the %s fallback backend.', ucfirst( $context ), $backend ), $level );
        }

        if ( file_exists( $path ) ) {
            wp_delete_file( $path );
        }

        if ( 'ziparchive' === $backend ) {
            return $this->create_zip_with_ziparchive( $path, $files );
        }

        if ( 'shell' === $backend ) {
            return $this->create_zip_with_shell( $path, $files, $remove_root );
        }

        return $this->create_zip_with_pclzip( $path, $files, $remove_root );
    }

    private function available_zip_backend() {
        if ( class_exists( 'ZipArchive' ) ) {
            return 'ziparchive';
        }

        if ( $this->zip_binary_path() ) {
            return 'shell';
        }

        if ( $this->load_pclzip() ) {
            return 'pclzip';
        }

        return null;
    }

    private function create_zip_with_ziparchive( $path, $files ) {
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            return new WP_Error( 'rb_zip', 'Failed to create ZIP archive.' );
        }

        foreach ( $files as $archive_path => $source_path ) {
            $zip->addFile( $source_path, $archive_path );
        }

        $zip->close();

        return array(
            'filename' => basename( $path ),
            'size'     => (int) filesize( $path ),
        );
    }

    private function create_zip_with_shell( $path, $files, $remove_root ) {
        $zip_binary = $this->zip_binary_path();
        if ( ! $zip_binary ) {
            return new WP_Error( 'rb_zip', 'The zip command is not available on this server.' );
        }

        $list_file = wp_tempnam( 'rb-zip-list' );
        if ( ! $list_file ) {
            return new WP_Error( 'rb_zip', 'Failed to create a temporary file list for the ZIP archive.' );
        }

        file_put_contents( $list_file, implode( "\n", array_keys( $files ) ) . "\n" );

        $working_dir = untrailingslashit( (string) $remove_root );
        $command     = 'cd ' . escapeshellarg( $working_dir ) . ' && ' . escapeshellarg( $zip_binary ) . ' -q ' . escapeshellarg( $path ) . ' -@ < ' . escapeshellarg( $list_file ) . ' 2>&1';
        $output      = array();
        $return_var  = 0;

        @exec( $command, $output, $return_var );

        if ( file_exists( $list_file ) ) {
            wp_delete_file( $list_file );
        }

        if ( 0 !== $return_var || ! file_exists( $path ) ) {
            return new WP_Error( 'rb_zip', 'The zip command failed to create the archive.' . ( ! empty( $output ) ? ' ' . trim( implode( ' ', array_slice( $output, -2 ) ) ) : '' ) );
        }

        return array(
            'filename' => basename( $path ),
            'size'     => (int) filesize( $path ),
        );
    }

    private function create_zip_with_pclzip( $path, $files, $remove_root ) {
        if ( ! $this->load_pclzip() ) {
            return new WP_Error( 'rb_zip', 'The WordPress PclZip library is not available.' );
        }

        $archive = new PclZip( $path );
        $result  = $archive->create(
            array_values( $files ),
            PCLZIP_OPT_REMOVE_PATH,
            untrailingslashit( (string) $remove_root )
        );

        if ( 0 === $result || ! file_exists( $path ) ) {
            $message = method_exists( $archive, 'errorInfo' ) ? $archive->errorInfo( true ) : 'Unknown PclZip error.';
            return new WP_Error( 'rb_zip', 'PclZip failed to create the archive. ' . trim( (string) $message ) );
        }

        return array(
            'filename' => basename( $path ),
            'size'     => (int) filesize( $path ),
        );
    }

    private function load_pclzip() {
        if ( class_exists( 'PclZip' ) ) {
            return true;
        }

        $file = ABSPATH . 'wp-admin/includes/class-pclzip.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }

        return class_exists( 'PclZip' );
    }

    private function zip_binary_path() {
        static $zip_binary = null;
        static $checked = false;

        if ( $checked ) {
            return $zip_binary;
        }

        $checked = true;

        if ( ! $this->can_exec() ) {
            return null;
        }

        $output = array();
        $code   = 1;
        @exec( 'command -v zip 2>/dev/null', $output, $code );

        if ( 0 === $code && ! empty( $output[0] ) ) {
            $zip_binary = trim( (string) $output[0] );
        }

        return $zip_binary;
    }

    private function can_exec() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        $disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );

        return ! in_array( 'exec', $disabled, true );
    }

    private function add_file_to_archive_list( $source_path, $archive_path, &$files, $exclude = array() ) {
        if ( ! file_exists( $source_path ) || is_dir( $source_path ) || $this->is_excluded_path( $source_path, $exclude ) ) {
            return;
        }

        $files[ ltrim( str_replace( '\\', '/', (string) $archive_path ), '/' ) ] = $source_path;
    }

    private function collect_directory_files( $dir, $prefix, &$files, $exclude = array() ) {
        $dir = trailingslashit( $dir );
        $handle = opendir( $dir );
        if ( ! $handle ) {
            return;
        }

        while ( false !== ( $file = readdir( $handle ) ) ) {
            if ( '.' === $file || '..' === $file ) {
                continue;
            }

            $full_path = $dir . $file;
            if ( $this->is_excluded_path( $full_path, $exclude ) ) {
                continue;
            }

            if ( is_dir( $full_path ) ) {
                $this->collect_directory_files( $full_path . '/', $prefix . $file . '/', $files, $exclude );
            } else {
                $this->add_file_to_archive_list( $full_path, $prefix . $file, $files, $exclude );
            }
        }

        closedir( $handle );
    }

    private function is_excluded_path( $path, $exclude = array() ) {
        $normalized = trailingslashit( wp_normalize_path( (string) $path ) );

        foreach ( $exclude as $excluded_path ) {
            $excluded = trailingslashit( wp_normalize_path( (string) $excluded_path ) );
            if ( 0 === strpos( $normalized, $excluded ) ) {
                return true;
            }
        }

        return false;
    }

    private function add_directory_to_zip( ZipArchive $zip, $dir, $prefix, $exclude = array() ) {
        $dir = trailingslashit( $dir );
        $handle = opendir( $dir );
        if ( ! $handle ) {
            return;
        }

        while ( false !== ( $file = readdir( $handle ) ) ) {
            if ( '.' === $file || '..' === $file ) {
                continue;
            }

            $full_path = $dir . $file;

            // Skip excluded directories.
            $skip = false;
            foreach ( $exclude as $ex ) {
                if ( 0 === strpos( trailingslashit( $full_path ), $ex ) ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            if ( is_dir( $full_path ) ) {
                $this->add_directory_to_zip( $zip, $full_path . '/', $prefix . $file . '/', $exclude );
            } else {
                $zip->addFile( $full_path, $prefix . $file );
            }
        }

        closedir( $handle );
    }
}
