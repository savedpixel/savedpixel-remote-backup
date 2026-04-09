<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Provider_Google_Drive implements Remote_Provider {

    private const TOKEN_OPTION        = 'sprb_google_drive_tokens';
    private const FOLDER_OPTION       = 'sprb_gdrive_folder_id';
    private const CHUNK_SIZE          = 5 * 1024 * 1024; // 5 MB
    private const TOKEN_URL           = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL            = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const DRIVE_API           = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_API          = 'https://www.googleapis.com/upload/drive/v3';
    private const MANUAL_REDIRECT_URI = 'http://localhost';

    // Defaults stay blank in code so release builds do not embed deploy-time credentials.
    private const DEFAULT_CLIENT_ID     = '';
    private const DEFAULT_CLIENT_SECRET = '';

    public function get_key(): string {
        return 'google_drive';
    }

    public function get_label(): string {
        return 'Google Drive';
    }

    public function validate_settings( array $settings ): bool|WP_Error {
        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
            return new WP_Error( 'gdrive_missing_credentials', 'Google Drive Client ID and Client Secret are required.' );
        }

        $tokens = $settings['tokens'] ?? array();
        if ( empty( $tokens['refresh_token'] ) ) {
            return new WP_Error( 'gdrive_not_authorized', 'Google Drive is not authorized. Click "Authorize with Google" to connect.' );
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
            'folder_name'  => $settings['folder_name'] ?: 'SavedPixel Backups',
            'client_id'    => $settings['client_id'],
            'client_secret'=> $settings['client_secret'],
        );
    }

    public function send( array $runtime, string $local_path, string $remote_name ): bool|WP_Error {
        $token       = $runtime['access_token'];
        $folder_name = $runtime['folder_name'];

        $folder_id = $this->ensure_folder( $token, $folder_name );
        if ( is_wp_error( $folder_id ) ) {
            return $folder_id;
        }

        $file_size = filesize( $local_path );
        if ( false === $file_size ) {
            return new WP_Error( 'gdrive_file_read', 'Cannot read local backup file.' );
        }

        $mime_type = 'application/octet-stream';
        $metadata  = wp_json_encode( array(
            'name'    => $remote_name,
            'parents' => array( $folder_id ),
        ) );

        // Initiate resumable upload session.
        $init_response = wp_remote_post( self::UPLOAD_API . '/files?uploadType=resumable', array(
            'headers' => array(
                'Authorization'           => 'Bearer ' . $token,
                'Content-Type'            => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type'   => $mime_type,
                'X-Upload-Content-Length' => (string) $file_size,
            ),
            'body'    => $metadata,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $init_response ) ) {
            return new WP_Error( 'gdrive_upload_init', 'Failed to initiate upload: ' . $init_response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $init_response );
        if ( 200 !== $status_code ) {
            $body = wp_remote_retrieve_body( $init_response );
            return new WP_Error( 'gdrive_upload_init', "Upload init failed (HTTP {$status_code}): {$body}" );
        }

        $session_uri = wp_remote_retrieve_header( $init_response, 'location' );
        if ( empty( $session_uri ) ) {
            return new WP_Error( 'gdrive_upload_init', 'No resumable session URI returned.' );
        }

        // Upload file in chunks — streaming reads required for chunked uploads; WP_Filesystem has no streaming equivalent.
        $handle = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( ! $handle ) {
            return new WP_Error( 'gdrive_file_open', 'Cannot open backup file for reading.' );
        }

        $offset = 0;
        while ( $offset < $file_size ) {
            $chunk      = fread( $handle, self::CHUNK_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk_size = strlen( $chunk );
            $range_end  = $offset + $chunk_size - 1;

            $chunk_response = wp_remote_request( $session_uri, array(
                'method'  => 'PUT',
                'headers' => array(
                    'Content-Length' => (string) $chunk_size,
                    'Content-Range'  => "bytes {$offset}-{$range_end}/{$file_size}",
                ),
                'body'    => $chunk,
                'timeout' => 120,
            ) );

            if ( is_wp_error( $chunk_response ) ) {
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                return new WP_Error( 'gdrive_upload_chunk', 'Chunk upload failed: ' . $chunk_response->get_error_message() );
            }

            $chunk_status = wp_remote_retrieve_response_code( $chunk_response );
            // 308 = Resume Incomplete (more chunks needed), 200/201 = complete.
            if ( ! in_array( $chunk_status, array( 200, 201, 308 ), true ) ) {
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                $body = wp_remote_retrieve_body( $chunk_response );
                return new WP_Error( 'gdrive_upload_chunk', "Chunk upload failed (HTTP {$chunk_status}): {$body}" );
            }

            $offset += $chunk_size;
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return true;
    }

    public function test_connection( array $settings ): string|WP_Error {
        $runtime = $this->prepare( $settings );
        if ( is_wp_error( $runtime ) ) {
            return $runtime;
        }

        $response = wp_remote_get( self::DRIVE_API . '/about?fields=user', array(
            'headers' => array( 'Authorization' => 'Bearer ' . $runtime['access_token'] ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gdrive_test_failed', 'Connection test failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'gdrive_test_failed', "Google Drive API returned HTTP {$code}." );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $email = $body['user']['emailAddress'] ?? 'unknown';

        return "Connected to Google Drive as {$email}.";
    }

    public function cleanup( array $runtime ): void {
        // No runtime resources to release.
    }

    public function render_settings_fields( array $saved ): void {
        $client_id      = $saved['client_id'] ?? '';
        $client_secret  = $saved['client_secret'] ?? '';
        $folder_name    = $saved['folder_name'] ?? 'SavedPixel Backups';
        $tokens         = $saved['tokens'] ?? array();
        $is_connected   = ! empty( $tokens['refresh_token'] );
        $using_defaults = ( $client_id === self::DEFAULT_CLIENT_ID );
        $auth_url       = $this->build_manual_auth_url( $client_id );
        ?>
        <tr id="sprb-row-gdrive-auth" class="sp-protocol-google_drive">
            <td id="sprb-gdrive-auth-cell" colspan="2">
                <?php if ( $is_connected ) : ?>
                    <span id="sprb-gdrive-status" class="sp-ok">✓ Connected</span>
                    <button type="button" id="sprb-gdrive-disconnect" class="button">Disconnect</button>
                <?php else : ?>
                    <button type="button" id="sprb-gdrive-auth-btn" class="button button-primary sprb-auth-open" data-auth-url="<?php echo esc_url( $auth_url ); ?>" data-provider="gdrive">Authorize</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ( $is_connected ) : ?>
        <tr id="sprb-row-gdrive-folder" class="sp-protocol-google_drive">
            <td id="sprb-gdrive-folder-cell" colspan="2">
                <label id="sprb-label-gdrive-folder" class="sp-form-label" for="sprb_gdrive_folder_name">Folder Name</label>
                <input type="text" name="sprb_gdrive_folder_name" id="sprb_gdrive_folder_name" class="sp-input" value="<?php echo esc_attr( $folder_name ); ?>" placeholder="SavedPixel Backups" style="width:100%;">
            </td>
        </tr>
        <?php endif; ?>
        <input type="hidden" name="sprb_gdrive_client_id" id="sprb_gdrive_client_id" value="<?php echo esc_attr( $using_defaults ? '' : $client_id ); ?>">
        <input type="hidden" name="sprb_gdrive_client_secret" id="sprb_gdrive_client_secret" value="<?php echo esc_attr( $using_defaults ? '' : $client_secret ); ?>">
        <?php
    }

    public function get_settings(): array {
        $client_id     = get_option( 'sprb_gdrive_client_id', '' );
        $client_secret = get_option( 'sprb_gdrive_client_secret', '' );

        return array(
            'client_id'     => '' !== $client_id ? $client_id : self::DEFAULT_CLIENT_ID,
            'client_secret' => '' !== $client_secret ? $client_secret : self::DEFAULT_CLIENT_SECRET,
            'folder_name'   => get_option( 'sprb_gdrive_folder_name', 'SavedPixel Backups' ),
            'tokens'        => get_option( self::TOKEN_OPTION, array() ),
        );
    }

    public function save_settings_from_request(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Called after check_admin_referer( 'sprb_remote' ).
        $client_id = sanitize_text_field( wp_unslash( $_POST['sprb_gdrive_client_id'] ?? get_option( 'sprb_gdrive_client_id', '' ) ) );
        update_option( 'sprb_gdrive_client_id', $client_id );

        $client_secret = sanitize_text_field( wp_unslash( $_POST['sprb_gdrive_client_secret'] ?? get_option( 'sprb_gdrive_client_secret', '' ) ) );
        update_option( 'sprb_gdrive_client_secret', $client_secret );

        $folder_name = sanitize_text_field( wp_unslash( $_POST['sprb_gdrive_folder_name'] ?? get_option( 'sprb_gdrive_folder_name', 'SavedPixel Backups' ) ) );
        update_option( 'sprb_gdrive_folder_name', $folder_name );
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
        return 'Google Drive: ' . $folder;
    }

    public function render_status_banner(): void {
        $tokens = get_option( self::TOKEN_OPTION, array() );
        if ( ! empty( $tokens['refresh_token'] ) ) {
            echo '<div id="sprb-gdrive-banner-ok" class="sp-notice sp-notice--success"><strong>Google Drive</strong> is connected.</div>';
        } else {
            echo '<div id="sprb-gdrive-banner-missing" class="sp-notice sp-notice--error"><strong>Google Drive</strong> is not authorized.</div>';
        }
    }

    /* ── OAuth helpers ────────────────────────────────── */

    /**
     * Build the Google OAuth 2.0 authorization URL.
     */
    public function build_auth_url( string $client_id ): string {
        return $this->build_oauth_url( $client_id, admin_url( 'admin.php?page=savedpixel-remote-backup' ) );
    }

    /**
     * Build auth URL using http://localhost as the redirect — works when
     * the site runs on a .localhost domain that Google rejects.
     */
    public function build_manual_auth_url( string $client_id ): string {
        return $this->build_oauth_url( $client_id, self::MANUAL_REDIRECT_URI );
    }

    private function build_oauth_url( string $client_id, string $redirect_uri ): string {
        if ( empty( $client_id ) ) {
            return '#';
        }

        return add_query_arg( array(
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce( 'sprb_gdrive_oauth' ),
        ), self::AUTH_URL );
    }

    /**
     * Exchange an authorization code for tokens.
     */
    public function exchange_code( string $code, string $redirect_uri = '' ): array|WP_Error {
        $client_id     = get_option( 'sprb_gdrive_client_id', '' );
        $client_secret = get_option( 'sprb_gdrive_client_secret', '' );
        if ( '' === $redirect_uri ) {
            $redirect_uri = admin_url( 'admin.php?page=savedpixel-remote-backup' );
        }

        $response = wp_remote_post( self::TOKEN_URL, array(
            'body'    => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gdrive_token_exchange', 'Token exchange failed: ' . $response->get_error_message() );
        }

        $code_status = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code_status || empty( $body['access_token'] ) ) {
            $error_desc = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            return new WP_Error( 'gdrive_token_exchange', "Token exchange failed: {$error_desc}" );
        }

        $tokens = array(
            'access_token'  => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? '',
            'expires_at'    => time() + ( (int) ( $body['expires_in'] ?? 3600 ) ),
        );

        update_option( self::TOKEN_OPTION, $tokens );

        return $tokens;
    }

    /**
     * Clear stored tokens (disconnect).
     */
    public function disconnect(): void {
        delete_option( self::TOKEN_OPTION );
        delete_option( self::FOLDER_OPTION );
    }

    /* ── Private helpers ──────────────────────────────── */

    private function token_expired( array $tokens ): bool {
        $expires_at = $tokens['expires_at'] ?? 0;
        return time() >= ( $expires_at - 60 );
    }

    private function refresh_access_token( array $settings ): array|WP_Error {
        $tokens = $settings['tokens'] ?? array();
        if ( empty( $tokens['refresh_token'] ) ) {
            return new WP_Error( 'gdrive_no_refresh', 'No refresh token available. Re-authorize with Google.' );
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
            return new WP_Error( 'gdrive_refresh', 'Token refresh failed: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            $error_desc = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            return new WP_Error( 'gdrive_refresh', "Token refresh failed: {$error_desc}" );
        }

        $updated = array(
            'access_token'  => $body['access_token'],
            'refresh_token' => $tokens['refresh_token'], // Keep existing refresh token.
            'expires_at'    => time() + ( (int) ( $body['expires_in'] ?? 3600 ) ),
        );

        update_option( self::TOKEN_OPTION, $updated );

        return $updated;
    }

    private function ensure_folder( string $token, string $folder_name ): string|WP_Error {
        // Check cached folder ID.
        $cached_id = get_option( self::FOLDER_OPTION, '' );
        if ( '' !== $cached_id ) {
            // Verify it still exists.
            $check = wp_remote_get( self::DRIVE_API . '/files/' . $cached_id . '?fields=id,trashed', array(
                'headers' => array( 'Authorization' => 'Bearer ' . $token ),
                'timeout' => 10,
            ) );

            if ( ! is_wp_error( $check ) ) {
                $body = json_decode( wp_remote_retrieve_body( $check ), true );
                if ( ! empty( $body['id'] ) && empty( $body['trashed'] ) ) {
                    return $cached_id;
                }
            }

            delete_option( self::FOLDER_OPTION );
        }

        // Search for existing folder by name.
        $query    = "mimeType='application/vnd.google-apps.folder' and name='" . addcslashes( $folder_name, "\\'" ) . "' and trashed=false";
        $search   = wp_remote_get( self::DRIVE_API . '/files?' . http_build_query( array( 'q' => $query, 'fields' => 'files(id)', 'pageSize' => 1 ) ), array(
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
            'timeout' => 10,
        ) );

        if ( ! is_wp_error( $search ) ) {
            $body = json_decode( wp_remote_retrieve_body( $search ), true );
            if ( ! empty( $body['files'][0]['id'] ) ) {
                $folder_id = $body['files'][0]['id'];
                update_option( self::FOLDER_OPTION, $folder_id );
                return $folder_id;
            }
        }

        // Create the folder.
        $create = wp_remote_post( self::DRIVE_API . '/files', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'name'     => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ) ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $create ) ) {
            return new WP_Error( 'gdrive_folder', 'Failed to create folder: ' . $create->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $create ), true );
        if ( empty( $body['id'] ) ) {
            return new WP_Error( 'gdrive_folder', 'Failed to create folder — no ID returned.' );
        }

        $folder_id = $body['id'];
        update_option( self::FOLDER_OPTION, $folder_id );

        return $folder_id;
    }
}
