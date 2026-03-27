<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- SSH transport manages temp key files and captured warnings directly.
class Provider_SSH implements Remote_Provider {

    public function get_key(): string {
        return 'ssh';
    }

    public function get_label(): string {
        return 'SSH / SCP';
    }

    public function get_settings(): array {
        $path = trim( (string) get_option( 'rb_ssh_path', '' ) );
        if ( '/' !== $path ) {
            $path = rtrim( $path, '/' );
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

    public function save_settings_from_request(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Called after nonce check in admin.
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
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function is_ready(): bool {
        $settings = $this->get_settings();
        $auth_ready = 'password' === $settings['auth']
            ? '' !== trim( (string) $settings['password'] )
            : '' !== trim( (string) $settings['key'] );

        return '' !== trim( (string) $settings['host'] )
            && '' !== trim( (string) $settings['username'] )
            && '' !== trim( (string) $settings['path'] )
            && $auth_ready;
    }

    public function format_destination( array $settings ): string {
        $target = '';
        $username = $settings['username'] ?? '';
        $host     = $settings['host'] ?? '';
        $path     = $settings['path'] ?? '';

        if ( '' !== $username || '' !== $host ) {
            $target = trim( $username . '@' . $host, '@' );
        }

        if ( '' !== $path ) {
            $target .= ':' . $path;
        }

        return ltrim( $target, ':' );
    }

    public function validate_settings( array $settings ): bool|WP_Error {
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

        if ( ! $this->command_available( 'scp' ) ) {
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

    public function prepare( array $settings ): array|WP_Error {
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
            $this->cleanup( $runtime );
            return $key_check;
        }

        return $runtime;
    }

    public function send( array $runtime, string $local_path, string $remote_name ): bool|WP_Error {
        $remote_dest = $this->join_remote_path( $runtime['path'], $remote_name );
        $ssh_opts    = $this->build_ssh_options( $runtime['auth'], 30 );
        $remote_spec = escapeshellarg( $runtime['username'] . '@' . $runtime['host'] . ':' . $remote_dest );

        if ( 'key' === $runtime['auth'] ) {
            $cmd = sprintf(
                'scp %1$s -i %2$s -P %3$d %4$s %5$s 2>&1',
                $ssh_opts,
                escapeshellarg( $runtime['key_file'] ),
                $runtime['port'],
                escapeshellarg( $local_path ),
                $remote_spec
            );
        } else {
            $cmd = sprintf(
                'sshpass -p %1$s scp %2$s -P %3$d %4$s %5$s 2>&1',
                escapeshellarg( $runtime['password'] ),
                $ssh_opts,
                $runtime['port'],
                escapeshellarg( $local_path ),
                $remote_spec
            );
        }

        $output     = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );

        if ( 0 !== $return_var ) {
            return new WP_Error( 'scp_fail', $this->format_process_failure( 'SCP transfer', $return_var, $output, $runtime['auth'] ) );
        }

        return true;
    }

    public function test_connection( array $settings ): string|WP_Error {
        $runtime = $this->prepare( $settings );
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
        $this->cleanup( $runtime );

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

    public function cleanup( array $runtime ): void {
        if ( ! empty( $runtime['key_file'] ) && file_exists( $runtime['key_file'] ) ) {
            unlink( $runtime['key_file'] );
        }
    }

    public function render_status_banner(): void {
        if ( $this->ssh_tools_ready() ) : ?>
            <p id="rb-ssh-tools-status" class="sp-transport-tools-ok description">
                <span class="sp-ok">scp ✓</span> &nbsp;
                <span class="sp-ok">ssh ✓</span> &nbsp;
                <span class="<?php echo $this->sshpass_available() ? 'sp-ok' : 'sp-missing'; ?>">sshpass <?php echo $this->sshpass_available() ? '✓' : '✗'; ?></span>
            </p>
        <?php else : ?>
            <div id="rb-ssh-tools-banner" class="sp-transport-tools-banner">
                <span class="sp-transport-tools-icons">
                    <span class="<?php echo $this->command_available( 'scp' ) ? 'sp-ok' : 'sp-missing'; ?>">scp <?php echo $this->command_available( 'scp' ) ? '✓' : '✗'; ?></span>
                    <span class="<?php echo $this->command_available( 'ssh' ) ? 'sp-ok' : 'sp-missing'; ?>">ssh <?php echo $this->command_available( 'ssh' ) ? '✓' : '✗'; ?></span>
                    <span class="<?php echo $this->sshpass_available() ? 'sp-ok' : 'sp-missing'; ?>">sshpass <?php echo $this->sshpass_available() ? '✓' : '✗'; ?></span>
                </span>
            </div>
        <?php endif;
    }

    public function render_settings_fields( array $saved ): void {
        $ssh_host     = $saved['host'] ?? '';
        $ssh_port     = $saved['port'] ?? 22;
        $ssh_username = $saved['username'] ?? '';
        $ssh_auth     = $saved['auth'] ?? 'key';
        $ssh_key      = $saved['key'] ?? '';
        $ssh_password = $saved['password'] ?? '';
        $ssh_path     = $saved['path'] ?? '';
        ?>
        <tr id="rb-row-ssh-host" class="sp-protocol-ssh">
            <th><label for="rb_ssh_host">Host</label></th>
            <td><input type="text" name="rb_ssh_host" id="rb_ssh_host" class="regular-text" value="<?php echo esc_attr( $ssh_host ); ?>" placeholder="backup.example.com"></td>
        </tr>
        <tr id="rb-row-ssh-port" class="sp-protocol-ssh">
            <th><label for="rb_ssh_port">Port</label></th>
            <td><input type="number" name="rb_ssh_port" id="rb_ssh_port" class="small-text" value="<?php echo esc_attr( $ssh_port ); ?>" min="1" max="65535"></td>
        </tr>
        <tr id="rb-row-ssh-username" class="sp-protocol-ssh">
            <th><label for="rb_ssh_username">Username</label></th>
            <td><input type="text" name="rb_ssh_username" id="rb_ssh_username" class="regular-text" value="<?php echo esc_attr( $ssh_username ); ?>" placeholder="backups"></td>
        </tr>
        <tr id="rb-row-ssh-path" class="sp-protocol-ssh">
            <th><label for="rb_ssh_path">Remote Path</label></th>
            <td><input type="text" name="rb_ssh_path" id="rb_ssh_path" class="regular-text" value="<?php echo esc_attr( $ssh_path ); ?>" placeholder="/home/backups/example-site"></td>
        </tr>
        <tr id="rb-row-ssh-auth" class="sp-protocol-ssh">
            <th><label for="rb_ssh_auth_method">Auth Method</label></th>
            <td>
                <select name="rb_ssh_auth_method" id="rb_ssh_auth_method">
                    <option value="key" <?php selected( $ssh_auth, 'key' ); ?>>SSH Private Key</option>
                    <option value="password" <?php selected( $ssh_auth, 'password' ); ?>>Password</option>
                </select>
            </td>
        </tr>
        <tr id="rb-row-ssh-key" class="sp-protocol-ssh sp-auth-key" <?php echo 'key' !== $ssh_auth ? 'style="display:none;"' : ''; ?>>
            <th><label for="rb_ssh_key">Private Key</label></th>
            <td>
                <textarea name="rb_ssh_key" id="rb_ssh_key" rows="5" class="large-text code" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;…&#10;-----END OPENSSH PRIVATE KEY-----"><?php echo esc_textarea( $ssh_key ); ?></textarea>
                <p class="description">Paste the full unencrypted private key including header/footer lines. Public keys and passphrase-protected keys are not supported.</p>
            </td>
        </tr>
        <tr id="rb-row-ssh-password" class="sp-protocol-ssh sp-auth-password" <?php echo 'password' !== $ssh_auth ? 'style="display:none;"' : ''; ?>>
            <th><label for="rb_ssh_password">Password</label></th>
            <td>
                <input type="password" name="rb_ssh_password" id="rb_ssh_password" class="regular-text" value="<?php echo esc_attr( $ssh_password ); ?>" autocomplete="off">
                <p class="description">Requires <code>sshpass</code> installed on the server.</p>
            </td>
        </tr>
        <?php
    }

    /* ── Ensure remote directory ──────────────────────── */

    public function ensure_remote_directory( array $runtime ): bool|WP_Error {
        $command = sprintf(
            'mkdir -p %1$s && test -d %1$s',
            escapeshellarg( $runtime['path'] )
        );

        $result = $this->execute_ssh_command( $runtime, $command, 20 );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( 'remote_dir', 'Could not prepare the remote directory: ' . $result->get_error_message() );
        }

        return true;
    }

    /* ── Private helpers ──────────────────────────────── */

    private function command_available( string $command ): bool {
        $path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null' ) );
        return '' !== $path;
    }

    private function ssh_tools_ready(): bool {
        return $this->command_available( 'ssh' ) && $this->command_available( 'scp' );
    }

    private function sshpass_available(): bool {
        return $this->command_available( 'sshpass' );
    }

    private function normalize_private_key( string $key ): string {
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

    private function validate_private_key_text( string $key ): bool|WP_Error {
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

    private function validate_private_key_file( string $key_file ): bool|WP_Error {
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

    private function execute_ssh_command( array $settings, string $remote_command, int $timeout = 15 ): array|WP_Error {
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

    private function build_ssh_options( string $auth, int $timeout ): string {
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

    private function format_process_failure( string $context, int $return_var, array $output, string $auth ): string {
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

    private function sanitize_process_output( array $output ): string {
        $lines = array();

        foreach ( $output as $line ) {
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

    private function summarize_messages( array $messages, int $max_items = 2, int $max_chars = 260 ): string {
        $messages = array_values( array_filter( array_map( 'trim', $messages ) ) );
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

    private function join_remote_path( string $base, string $leaf ): string {
        if ( '/' === $base ) {
            return '/' . ltrim( $leaf, '/' );
        }

        return rtrim( $base, '/' ) . '/' . ltrim( $leaf, '/' );
    }

    public function exchange_code( string $code, string $redirect_uri = '' ): array|\WP_Error {
        return new \WP_Error( 'not_supported', 'SSH does not use OAuth.' );
    }

    public function disconnect(): void {}
}
