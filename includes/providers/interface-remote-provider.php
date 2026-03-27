<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract for remote storage providers.
 *
 * Each provider (SSH, FTP, Google Drive, etc.) implements this interface
 * so the scheduler can dispatch to any transport uniformly.
 */
interface Remote_Provider {

    /**
     * Unique key identifying this provider (e.g. 'ssh', 'ftp', 'google_drive').
     */
    public function get_key(): string;

    /**
     * Human-readable label shown in the admin dropdown.
     */
    public function get_label(): string;

    /**
     * Validate that all required settings are present and correct.
     *
     * @param array $settings Provider-specific settings array.
     * @return true|WP_Error
     */
    public function validate_settings( array $settings ): bool|WP_Error;

    /**
     * Prepare runtime resources (connections, temp files, tokens).
     *
     * @param array $settings Provider-specific settings array.
     * @return array|WP_Error Runtime array on success.
     */
    public function prepare( array $settings ): array|WP_Error;

    /**
     * Send a single file to the remote destination.
     *
     * @param array  $runtime     Runtime array from prepare().
     * @param string $local_path  Absolute path to the local file.
     * @param string $remote_name Remote filename (basename).
     * @return true|WP_Error
     */
    public function send( array $runtime, string $local_path, string $remote_name ): bool|WP_Error;

    /**
     * Test that the provider can connect and write to the destination.
     *
     * @param array $settings Provider-specific settings array.
     * @return string|WP_Error Success message or error.
     */
    public function test_connection( array $settings ): string|WP_Error;

    /**
     * Release runtime resources (close connections, delete temp files).
     *
     * @param array $runtime Runtime array from prepare().
     */
    public function cleanup( array $runtime ): void;

    /**
     * Echo the HTML form rows for this provider's settings.
     *
     * @param array $saved Currently saved option values.
     */
    public function render_settings_fields( array $saved ): void;

    /**
     * Read this provider's settings from wp_options.
     *
     * @return array Settings array compatible with validate_settings() / prepare().
     */
    public function get_settings(): array;

    /**
     * Save this provider's settings from a POST request.
     */
    public function save_settings_from_request(): void;

    /**
     * Check whether this provider has enough configuration to attempt a transfer.
     *
     * @return bool
     */
    public function is_ready(): bool;

    /**
     * Format a human-readable destination string for log messages.
     *
     * @param array $settings Provider-specific settings array.
     * @return string
     */
    public function format_destination( array $settings ): string;

    /**
     * Render a status banner (tool availability, connection status, etc.).
     * Called before the form table in the admin UI.
     */
    public function render_status_banner(): void;

    /**
     * Exchange an authorization code for access/refresh tokens.
     *
     * @param string $code         Authorization code from the OAuth flow.
     * @param string $redirect_uri Redirect URI used during the auth request.
     * @return array|\WP_Error Token array on success.
     */
    public function exchange_code( string $code, string $redirect_uri = '' ): array|\WP_Error;

    /**
     * Clear stored tokens and disconnect from the remote provider.
     */
    public function disconnect(): void;
}
