<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- FTP transport captures warnings from PHP FTP functions directly.
class Provider_FTP implements Remote_Provider {

    public function get_key(): string {
        return 'ftp';
    }

    public function get_label(): string {
        return 'FTP';
    }

    public function get_settings(): array {
        $path = trim( (string) get_option( 'sprb_ftp_path', '' ) );
        if ( '/' !== $path ) {
            $path = rtrim( $path, '/' );
        }

        return array(
            'protocol' => 'ftp',
            'host'     => trim( (string) get_option( 'sprb_ftp_host', '' ) ),
            'port'     => absint( get_option( 'sprb_ftp_port', 21 ) ) ?: 21,
            'username' => trim( (string) get_option( 'sprb_ftp_username', '' ) ),
            'password' => (string) get_option( 'sprb_ftp_password', '' ),
            'path'     => $path,
            'passive'  => (bool) get_option( 'sprb_ftp_passive', 1 ),
            'auth'     => 'password',
        );
    }

    public function save_settings_from_request(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Called after nonce check in admin.
        $ftp_host = sanitize_text_field( wp_unslash( $_POST['sprb_ftp_host'] ?? get_option( 'sprb_ftp_host', '' ) ) );
        update_option( 'sprb_ftp_host', $ftp_host );

        $ftp_port = absint( $_POST['sprb_ftp_port'] ?? get_option( 'sprb_ftp_port', 21 ) ) ?: 21;
        update_option( 'sprb_ftp_port', $ftp_port );

        $ftp_username = sanitize_text_field( wp_unslash( $_POST['sprb_ftp_username'] ?? get_option( 'sprb_ftp_username', '' ) ) );
        update_option( 'sprb_ftp_username', $ftp_username );

        $ftp_password = (string) wp_unslash( $_POST['sprb_ftp_password'] ?? get_option( 'sprb_ftp_password', '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must be stored verbatim.
        update_option( 'sprb_ftp_password', $ftp_password );

        $ftp_path = sanitize_text_field( wp_unslash( $_POST['sprb_ftp_path'] ?? get_option( 'sprb_ftp_path', '' ) ) );
        update_option( 'sprb_ftp_path', $ftp_path );

        update_option( 'sprb_ftp_passive', isset( $_POST['sprb_ftp_passive'] ) ? 1 : 0 );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function is_ready(): bool {
        $settings = $this->get_settings();
        return function_exists( 'ftp_connect' )
            && '' !== trim( (string) $settings['host'] )
            && '' !== trim( (string) $settings['username'] )
            && '' !== trim( (string) $settings['password'] )
            && '' !== trim( (string) $settings['path'] );
    }

    public function format_destination( array $settings ): string {
        $host     = $settings['host'] ?? '';
        $username = $settings['username'] ?? '';
        $path     = $settings['path'] ?? '';
        $port     = absint( $settings['port'] ?? 21 ) ?: 21;
        $target   = 'ftp://';

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

    public function validate_settings( array $settings ): bool|WP_Error {
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

    public function prepare( array $settings ): array|WP_Error {
        $runtime        = $settings;
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

        $warning   = '';
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

    public function send( array $runtime, string $local_path, string $remote_name ): bool|WP_Error {
        $remote_dest = $this->join_remote_path( $runtime['path'], $remote_name );

        $warning = '';
        $sent    = $this->call_with_warning_capture(
            static function() use ( $runtime, $local_path, $remote_dest ) {
                return ftp_put( $runtime['ftp'], $remote_dest, $local_path, FTP_BINARY );
            },
            $warning
        );

        if ( ! $sent ) {
            return new WP_Error( 'ftp_put_failed', 'FTP transfer failed.' . $this->format_ftp_warning( $warning, ' The remote path may not exist or may not be writable.' ) );
        }

        return true;
    }

    public function test_connection( array $settings ): string|WP_Error {
        $runtime = $this->prepare( $settings );
        if ( is_wp_error( $runtime ) ) {
            return $runtime;
        }

        $ftp              = $runtime['ftp'];
        $directory_exists = $this->ftp_directory_exists( $ftp, $settings['path'] );
        $directory        = $this->ensure_ftp_remote_directory( $runtime );

        if ( is_wp_error( $directory ) ) {
            $this->cleanup( $runtime );
            return $directory;
        }

        $probe_local = tempnam( sys_get_temp_dir(), 'sprb_ftp_test_' );
        if ( false === $probe_local ) {
            $this->cleanup( $runtime );
            return new WP_Error( 'ftp_probe_temp', 'Connected to FTP, but could not create a local test file.' );
        }

        if ( false === file_put_contents( $probe_local, 'Remote Backup FTP probe ' . gmdate( 'c' ) ) ) {
            @wp_delete_file( $probe_local );
            $this->cleanup( $runtime );
            return new WP_Error( 'ftp_probe_write', 'Connected to FTP, but could not write the local test file.' );
        }

        $probe_remote = $this->join_remote_path( $settings['path'], '.sprb-write-test-' . wp_generate_password( 8, false, false ) );
        $warning      = '';
        $uploaded     = $this->call_with_warning_capture(
            static function() use ( $ftp, $probe_local, $probe_remote ) {
                return ftp_put( $ftp, $probe_remote, $probe_local, FTP_BINARY );
            },
            $warning
        );
        @wp_delete_file( $probe_local );

        if ( ! $uploaded ) {
            $this->cleanup( $runtime );
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

        $this->cleanup( $runtime );

        if ( $directory_exists ) {
            return 'Connected OK — FTP directory exists and is writable.';
        }

        return 'Connected OK — FTP directory was created and is writable.';
    }

    public function cleanup( array $runtime ): void {
        if ( ! empty( $runtime['ftp'] ) ) {
            @ftp_close( $runtime['ftp'] );
        }
    }

    public function render_status_banner(): void {
        $ftp_available = function_exists( 'ftp_connect' );
        if ( $ftp_available ) : ?>
            <p id="sprb-ftp-tools-status" class="sp-transport-tools-ok description">
                <span class="sp-ok">PHP FTP extension ✓</span>
            </p>
        <?php else : ?>
            <div id="sprb-ftp-tools-banner" class="sp-transport-tools-banner">
                <span class="sp-transport-tools-icons">
                    <span class="sp-missing">PHP FTP extension ✗</span>
                </span>
            </div>
        <?php endif;
    }

    public function render_settings_fields( array $saved ): void {
        $ftp_host     = $saved['host'] ?? '';
        $ftp_port     = $saved['port'] ?? 21;
        $ftp_username = $saved['username'] ?? '';
        $ftp_password = $saved['password'] ?? '';
        $ftp_path     = $saved['path'] ?? '';
        $ftp_passive  = (bool) ( $saved['passive'] ?? true );
        ?>
        <tr id="sprb-row-ftp-host" class="sp-protocol-ftp">
            <th><label for="sprb_ftp_host">Host</label></th>
            <td><input type="text" name="sprb_ftp_host" id="sprb_ftp_host" class="regular-text" value="<?php echo esc_attr( $ftp_host ); ?>" placeholder="ftp.example.com"></td>
        </tr>
        <tr id="sprb-row-ftp-port" class="sp-protocol-ftp">
            <th><label for="sprb_ftp_port">Port</label></th>
            <td><input type="number" name="sprb_ftp_port" id="sprb_ftp_port" class="small-text" value="<?php echo esc_attr( $ftp_port ); ?>" min="1" max="65535"></td>
        </tr>
        <tr id="sprb-row-ftp-username" class="sp-protocol-ftp">
            <th><label for="sprb_ftp_username">Username</label></th>
            <td><input type="text" name="sprb_ftp_username" id="sprb_ftp_username" class="regular-text" value="<?php echo esc_attr( $ftp_username ); ?>" placeholder="backups"></td>
        </tr>
        <tr id="sprb-row-ftp-password" class="sp-protocol-ftp">
            <th><label for="sprb_ftp_password">Password</label></th>
            <td><input type="password" name="sprb_ftp_password" id="sprb_ftp_password" class="regular-text" value="<?php echo esc_attr( $ftp_password ); ?>" autocomplete="off"></td>
        </tr>
        <tr id="sprb-row-ftp-path" class="sp-protocol-ftp">
            <th><label for="sprb_ftp_path">Remote Path</label></th>
            <td>
                <input type="text" name="sprb_ftp_path" id="sprb_ftp_path" class="regular-text" value="<?php echo esc_attr( $ftp_path ); ?>" placeholder="/backups or /home/backups/example-site">
                <p class="description">Use a path the FTP user can access. Some FTP servers expect a path relative to the FTP root.</p>
            </td>
        </tr>
        <tr id="sprb-row-ftp-passive" class="sp-protocol-ftp">
            <th>Transfer Mode</th>
            <td>
                <label class="sp-checkbox-row" for="sprb_ftp_passive">
                    <input type="checkbox" name="sprb_ftp_passive" id="sprb_ftp_passive" value="1" <?php checked( $ftp_passive ); ?>>
                    Use passive mode
                </label>
            </td>
        </tr>
        <?php
    }

    /* ── Ensure remote directory ──────────────────────── */

    public function ensure_ftp_remote_directory( array $settings ): bool|WP_Error {
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

    /* ── Private helpers ──────────────────────────────── */

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

    private function format_ftp_warning( string $warning, string $fallback = '' ): string {
        $warning = trim( $warning );
        if ( '' === $warning ) {
            return $fallback;
        }

        $warning = preg_replace( '/^ftp_[a-z_]+\(\):\s*/i', '', $warning );
        return ' FTP said: ' . $warning;
    }

    private function ftp_directory_exists( $ftp, string $path ): bool {
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

    private function restore_ftp_directory( $ftp, $directory ): void {
        if ( false === $directory || '' === $directory || null === $directory ) {
            return;
        }

        @ftp_chdir( $ftp, $directory );
    }

    private function join_remote_path( string $base, string $leaf ): string {
        if ( '/' === $base ) {
            return '/' . ltrim( $leaf, '/' );
        }

        return rtrim( $base, '/' ) . '/' . ltrim( $leaf, '/' );
    }

    public function exchange_code( string $code, string $redirect_uri = '' ): array|\WP_Error {
        return new \WP_Error( 'not_supported', 'FTP does not use OAuth.' );
    }

    public function disconnect(): void {}
}
