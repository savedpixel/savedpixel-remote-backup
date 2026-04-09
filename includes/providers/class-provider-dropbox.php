<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Provider_Dropbox implements Remote_Provider {

    private const TOKEN_OPTION        = 'sprb_dropbox_tokens';
    private const CHUNK_SIZE          = 5 * 1024 * 1024; // 5 MB
    private const SIMPLE_UPLOAD_LIMIT = 150 * 1024 * 1024; // 150 MB
    private const AUTH_URL            = 'https://www.dropbox.com/oauth2/authorize';
    private const TOKEN_URL           = 'https://api.dropboxapi.com/oauth2/token';
    private const CONTENT_API         = 'https://content.dropboxapi.com/2';
    private const API_URL             = 'https://api.dropboxapi.com/2';
    private const MANUAL_REDIRECT_URI = 'http://localhost';

    public function get_key(): string {
        return 'dropbox';
    }

    public function get_label(): string {
        return 'Dropbox';
    }

    public function validate_settings( array $settings ): bool|WP_Error {
        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
            return new WP_Error( 'dropbox_missing_credentials', 'Dropbox App Key and App Secret are required.' );
        }

        $tokens = $settings['tokens'] ?? array();
        if ( empty( $tokens['refresh_token'] ) ) {
            return new WP_Error( 'dropbox_not_authorized', 'Dropbox is not authorized. Click "Authorize with Dropbox" to connect.' );
        }

        return true;
    }

    public function prepare( array $settings ): array|WP_Error {
        $tokens = $settings['tokens'] ?? array();

        if ( empty( $tokens['access_token'] ) || $this->token_expired( $tokens ) ) {
            $refreshed = $this->refresh_access_token( $settings );
            if ( is_wp_error( $refreshed ) ) {
                return $refreshed;
            }
            $tokens = $refreshed;
        }

        return array(
            'access_token' => $tokens['access_token'],
            'folder_path'  => '/' . ltrim( $settings['folder_name'] ?: 'SavedPixel Backups', '/' ),
            'client_id'    => $settings['client_id'],
            'client_secret'=> $settings['client_secret'],
        );
    }

    public function send( array $runtime, string $local_path, string $remote_name ): bool|WP_Error {
        $token       = $runtime['access_token'];
        $folder_path = $runtime['folder_path'];

        $folder_ok = $this->ensure_folder( $token, $folder_path );
        if ( is_wp_error( $folder_ok ) ) {
            return $folder_ok;
        }

        $file_size = filesize( $local_path );
        if ( false === $file_size ) {
            return new WP_Error( 'dropbox_file_read', 'Cannot read local backup file.' );
        }

        $dest_path = rtrim( $folder_path, '/' ) . '/' . $remote_name;

        if ( $file_size < self::SIMPLE_UPLOAD_LIMIT ) {
            return $this->simple_upload( $token, $local_path, $dest_path );
        }

        return $this->session_upload( $token, $local_path, $dest_path, $file_size );
    }

    public function test_connection( array $settings ): string|WP_Error {
        $runtime = $this->prepare( $settings );
        if ( is_wp_error( $runtime ) ) {
            return $runtime;
        }

        $response = wp_remote_post( self::API_URL . '/users/get_current_account', array(
            'headers' => array( 'Authorization' => 'Bearer ' . $runtime['access_token'] ),
            'body'    => 'null',
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dropbox_test_failed', 'Connection test failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'dropbox_test_failed', "Dropbox API returned HTTP {$code}." );
        }

        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
        $name  = $body['name']['display_name'] ?? 'unknown';
        $email = $body['email'] ?? '';

        $msg = "Connected to Dropbox as {$name}";
        if ( '' !== $email ) {
            $msg .= " ({$email})";
        }
        return $msg . '.';
    }

    public function cleanup( array $runtime ): void {
        // No runtime resources to release.
    }

    public function render_settings_fields( array $saved ): void {
        $client_id     = $saved['client_id'] ?? '';
        $client_secret = $saved['client_secret'] ?? '';
        $folder_name   = $saved['folder_name'] ?? 'SavedPixel Backups';
        $tokens        = $saved['tokens'] ?? array();
        $is_connected  = ! empty( $tokens['refresh_token'] );
        $auth_url      = $this->build_manual_auth_url( $client_id );
        ?>
        <tr id="sprb-row-dropbox-auth" class="sp-protocol-dropbox">
            <td id="sprb-dropbox-auth-cell" colspan="2">
                <?php if ( $is_connected ) : ?>
                    <span id="sprb-dropbox-status" class="sp-ok">✓ Connected</span>
                    <button type="button" id="sprb-dropbox-disconnect" class="button">Disconnect</button>
                <?php else : ?>
                    <button type="button" id="sprb-dropbox-auth-btn" class="button button-primary sprb-auth-open" data-auth-url="<?php echo esc_url( $auth_url ); ?>" data-provider="dropbox">Authorize</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ( $is_connected ) : ?>
        <tr id="sprb-row-dropbox-folder" class="sp-protocol-dropbox">
            <td id="sprb-dropbox-folder-cell" colspan="2">
                <label id="sprb-label-dropbox-folder" class="sp-form-label" for="sprb_dropbox_folder_name">Folder Name</label>
                <input type="text" name="sprb_dropbox_folder_name" id="sprb_dropbox_folder_name" class="sp-input" value="<?php echo esc_attr( $folder_name ); ?>" placeholder="SavedPixel Backups" style="width:100%;">
            </td>
        </tr>
        <?php endif; ?>
        <input type="hidden" name="sprb_dropbox_client_id" id="sprb_dropbox_client_id" value="<?php echo esc_attr( $client_id ); ?>">
        <input type="hidden" name="sprb_dropbox_client_secret" id="sprb_dropbox_client_secret" value="<?php echo esc_attr( $client_secret ); ?>">
        <?php
    }

    public function get_settings(): array {
        return array(
            'client_id'     => get_option( 'sprb_dropbox_client_id', '' ),
            'client_secret' => get_option( 'sprb_dropbox_client_secret', '' ),
            'folder_name'   => get_option( 'sprb_dropbox_folder_name', 'SavedPixel Backups' ),
            'tokens'        => get_option( self::TOKEN_OPTION, array() ),
        );
    }

    public function save_settings_from_request(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Called after check_admin_referer( 'sprb_remote' ).
        $client_id = sanitize_text_field( wp_unslash( $_POST['sprb_dropbox_client_id'] ?? get_option( 'sprb_dropbox_client_id', '' ) ) );
        update_option( 'sprb_dropbox_client_id', $client_id );

        $client_secret = sanitize_text_field( wp_unslash( $_POST['sprb_dropbox_client_secret'] ?? get_option( 'sprb_dropbox_client_secret', '' ) ) );
        update_option( 'sprb_dropbox_client_secret', $client_secret );

        $folder_name = sanitize_text_field( wp_unslash( $_POST['sprb_dropbox_folder_name'] ?? get_option( 'sprb_dropbox_folder_name', 'SavedPixel Backups' ) ) );
        update_option( 'sprb_dropbox_folder_name', $folder_name );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function is_ready(): bool {
        $settings = $this->get_settings();
        return ! empty( $settings['client_id'] )
            && ! empty( $settings['client_secret'] )
            && ! empty( $settings['tokens']['refresh_token'] );
    }

    public function format_destination( array $settings ): string {
        $folder = $settings['folder_name'] ?? 'SavedPixel Backups';
        return 'Dropbox: /' . ltrim( $folder, '/' );
    }

    public function render_status_banner(): void {
        $tokens = get_option( self::TOKEN_OPTION, array() );
        if ( ! empty( $tokens['refresh_token'] ) ) {
            echo '<div id="sprb-dropbox-banner-ok" class="sp-notice sp-notice--success"><strong>Dropbox</strong> is connected.</div>';
        } else {
            echo '<div id="sprb-dropbox-banner-missing" class="sp-notice sp-notice--error"><strong>Dropbox</strong> is not authorized.</div>';
        }
    }

    /* ── OAuth helpers ────────────────────────────────── */

    public function build_manual_auth_url( string $client_id ): string {
        if ( empty( $client_id ) ) {
            return '#';
        }

        return add_query_arg( array(
            'client_id'         => $client_id,
            'redirect_uri'      => self::MANUAL_REDIRECT_URI,
            'response_type'     => 'code',
            'token_access_type' => 'offline',
        ), self::AUTH_URL );
    }

    public function exchange_code( string $code, string $redirect_uri = '' ): array|WP_Error {
        $client_id     = get_option( 'sprb_dropbox_client_id', '' );
        $client_secret = get_option( 'sprb_dropbox_client_secret', '' );
        if ( '' === $redirect_uri ) {
            $redirect_uri = self::MANUAL_REDIRECT_URI;
        }

        $response = wp_remote_post( self::TOKEN_URL, array(
            'body'    => array(
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dropbox_token_exchange', 'Token exchange failed: ' . $response->get_error_message() );
        }

        $code_status = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code_status || empty( $body['access_token'] ) ) {
            $error_desc = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            return new WP_Error( 'dropbox_token_exchange', "Token exchange failed: {$error_desc}" );
        }

        $tokens = array(
            'access_token'  => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? '',
            'expires_at'    => time() + ( (int) ( $body['expires_in'] ?? 14400 ) ),
        );

        update_option( self::TOKEN_OPTION, $tokens );

        return $tokens;
    }

    public function disconnect(): void {
        delete_option( self::TOKEN_OPTION );
    }

    /* ── Private helpers ──────────────────────────────── */

    private function token_expired( array $tokens ): bool {
        $expires_at = $tokens['expires_at'] ?? 0;
        return time() >= ( $expires_at - 60 );
    }

    private function refresh_access_token( array $settings ): array|WP_Error {
        $tokens = $settings['tokens'] ?? array();
        if ( empty( $tokens['refresh_token'] ) ) {
            return new WP_Error( 'dropbox_no_refresh', 'No refresh token available. Re-authorize with Dropbox.' );
        }

        $response = wp_remote_post( self::TOKEN_URL, array(
            'body'    => array(
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'refresh_token' => $tokens['refresh_token'],
                'grant_type'    => 'refresh_token',
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dropbox_refresh', 'Token refresh failed: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            $error_desc = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            return new WP_Error( 'dropbox_refresh', "Token refresh failed: {$error_desc}" );
        }

        // Dropbox refresh does not return a new refresh_token — keep the existing one.
        $updated = array(
            'access_token'  => $body['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at'    => time() + ( (int) ( $body['expires_in'] ?? 14400 ) ),
        );

        update_option( self::TOKEN_OPTION, $updated );

        return $updated;
    }

    private function ensure_folder( string $token, string $folder_path ): bool|WP_Error {
        // Check if folder exists via get_metadata.
        $response = wp_remote_post( self::API_URL . '/files/get_metadata', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'path' => $folder_path ) ),
            'timeout' => 10,
        ) );

        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $code ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( 'folder' === ( $body['.tag'] ?? '' ) ) {
                    return true;
                }
            }
        }

        // Folder does not exist — create it.
        $create = wp_remote_post( self::API_URL . '/files/create_folder_v2', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'path'       => $folder_path,
                'autorename' => false,
            ) ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $create ) ) {
            return new WP_Error( 'dropbox_folder', 'Failed to create folder: ' . $create->get_error_message() );
        }

        $create_code = wp_remote_retrieve_response_code( $create );
        if ( 200 !== $create_code ) {
            // 409 with path/conflict means folder already exists — that's OK.
            $create_body = json_decode( wp_remote_retrieve_body( $create ), true );
            $tag = $create_body['error']['.tag'] ?? '';
            if ( 'path' === $tag ) {
                $path_tag = $create_body['error']['path']['.tag'] ?? '';
                if ( 'conflict' === $path_tag ) {
                    return true;
                }
            }
            return new WP_Error( 'dropbox_folder', "Failed to create folder (HTTP {$create_code})." );
        }

        return true;
    }

    /* ── Upload helpers ───────────────────────────────── */

    private function simple_upload( string $token, string $local_path, string $dest_path ): bool|WP_Error {
        $contents = file_get_contents( $local_path );
        if ( false === $contents ) {
            return new WP_Error( 'dropbox_file_read', 'Cannot read backup file for upload.' );
        }

        $api_arg = wp_json_encode( array(
            'path'       => $dest_path,
            'mode'       => 'add',
            'autorename' => true,
            'mute'       => true,
        ) );

        $response = wp_remote_post( self::CONTENT_API . '/files/upload', array(
            'headers' => array(
                'Authorization'  => 'Bearer ' . $token,
                'Content-Type'   => 'application/octet-stream',
                'Dropbox-API-Arg' => $api_arg,
            ),
            'body'    => $contents,
            'timeout' => 300,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dropbox_upload', 'Upload failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $body = wp_remote_retrieve_body( $response );
            return new WP_Error( 'dropbox_upload', "Upload failed (HTTP {$code}): {$body}" );
        }

        return true;
    }

    private function session_upload( string $token, string $local_path, string $dest_path, int $file_size ): bool|WP_Error {
        // Start upload session.
        $start = wp_remote_post( self::CONTENT_API . '/files/upload_session/start', array(
            'headers' => array(
                'Authorization'   => 'Bearer ' . $token,
                'Content-Type'    => 'application/octet-stream',
                'Dropbox-API-Arg' => wp_json_encode( array( 'close' => false ) ),
            ),
            'body'    => '',
            'timeout' => 30,
        ) );

        if ( is_wp_error( $start ) ) {
            return new WP_Error( 'dropbox_session_start', 'Upload session start failed: ' . $start->get_error_message() );
        }

        $start_code = wp_remote_retrieve_response_code( $start );
        if ( 200 !== $start_code ) {
            return new WP_Error( 'dropbox_session_start', "Upload session start failed (HTTP {$start_code})." );
        }

        $start_body = json_decode( wp_remote_retrieve_body( $start ), true );
        $session_id = $start_body['session_id'] ?? '';
        if ( '' === $session_id ) {
            return new WP_Error( 'dropbox_session_start', 'No session ID returned.' );
        }

        // Upload chunks — streaming reads required for chunked uploads; WP_Filesystem has no streaming equivalent.
        $handle = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( ! $handle ) {
            return new WP_Error( 'dropbox_file_open', 'Cannot open backup file for reading.' );
        }

        $offset = 0;
        while ( $offset < $file_size ) {
            $remaining  = $file_size - $offset;
            $chunk_size = min( self::CHUNK_SIZE, $remaining );
            $chunk      = fread( $handle, $chunk_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $is_last    = ( $offset + $chunk_size ) >= $file_size;

            if ( $is_last ) {
                // Finish session with the last chunk.
                $finish = wp_remote_post( self::CONTENT_API . '/files/upload_session/finish', array(
                    'headers' => array(
                        'Authorization'   => 'Bearer ' . $token,
                        'Content-Type'    => 'application/octet-stream',
                        'Dropbox-API-Arg' => wp_json_encode( array(
                            'cursor' => array(
                                'session_id' => $session_id,
                                'offset'     => $offset,
                            ),
                            'commit' => array(
                                'path'       => $dest_path,
                                'mode'       => 'add',
                                'autorename' => true,
                                'mute'       => true,
                            ),
                        ) ),
                    ),
                    'body'    => $chunk,
                    'timeout' => 120,
                ) );

                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

                if ( is_wp_error( $finish ) ) {
                    return new WP_Error( 'dropbox_session_finish', 'Upload session finish failed: ' . $finish->get_error_message() );
                }

                $finish_code = wp_remote_retrieve_response_code( $finish );
                if ( 200 !== $finish_code ) {
                    $body = wp_remote_retrieve_body( $finish );
                    return new WP_Error( 'dropbox_session_finish', "Upload session finish failed (HTTP {$finish_code}): {$body}" );
                }

                return true;
            }

            // Append chunk.
            $append = wp_remote_post( self::CONTENT_API . '/files/upload_session/append_v2', array(
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $token,
                    'Content-Type'    => 'application/octet-stream',
                    'Dropbox-API-Arg' => wp_json_encode( array(
                        'cursor' => array(
                            'session_id' => $session_id,
                            'offset'     => $offset,
                        ),
                        'close'  => false,
                    ) ),
                ),
                'body'    => $chunk,
                'timeout' => 120,
            ) );

            if ( is_wp_error( $append ) ) {
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                return new WP_Error( 'dropbox_session_append', 'Chunk upload failed: ' . $append->get_error_message() );
            }

            $append_code = wp_remote_retrieve_response_code( $append );
            if ( 200 !== $append_code ) {
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                $body = wp_remote_retrieve_body( $append );
                return new WP_Error( 'dropbox_session_append', "Chunk upload failed (HTTP {$append_code}): {$body}" );
            }

            $offset += $chunk_size;
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return true;
    }
}
