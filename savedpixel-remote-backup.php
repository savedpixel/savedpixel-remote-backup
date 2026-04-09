<?php
/**
 * Plugin Name: SavedPixel Remote Backup
 * Plugin URI:  https://wpremotebackup.com
 * Description: Create, schedule, and export WordPress backups.
 * Version:     1.3.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Byron Jacobs
 * Author URI:  https://byronjacobs.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: savedpixel-remote-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/savedpixel-admin-shared.php';

savedpixel_register_admin_preview_asset(
    plugin_dir_url( __FILE__ ) . 'assets/css/savedpixel-admin-preview.css',
    '1.1',
    array( 'savedpixel', 'savedpixel-remote-backup', 'savedpixel-remote-backup-monitor' )
);

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
    add_action( 'admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p><strong>SavedPixel Remote Backup</strong> requires PHP 8.1 or later. You are running PHP %s.</p></div>',
            esc_html( PHP_VERSION )
        );
    } );
    return;
}

try {

    if ( ! defined( 'SPRB_VERSION' ) ) {
        define( 'SPRB_VERSION', '1.3.0' );
    }

    if ( ! defined( 'SPRB_PLUGIN_DIR' ) ) {
        define( 'SPRB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    }

    if ( ! defined( 'SPRB_PLUGIN_URL' ) ) {
        define( 'SPRB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }

    if ( ! defined( 'SPRB_BASE_DIR' ) ) {
        define( 'SPRB_BASE_DIR', WP_CONTENT_DIR . '/remote-backup/' );
    }

    if ( ! defined( 'SPRB_STORAGE_DIR' ) ) {
        define( 'SPRB_STORAGE_DIR', trailingslashit( ABSPATH ) . 'storage/' );
    }

    if ( ! defined( 'SPRB_DATA_DIR' ) ) {
        define( 'SPRB_DATA_DIR', SPRB_BASE_DIR . 'data/' );
    }

    require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-plugin.php';

    register_activation_hook( __FILE__, array( 'Remote_Backup_Plugin', 'activate' ) );
    register_deactivation_hook( __FILE__, array( 'Remote_Backup_Plugin', 'deactivate' ) );

    /**
     * Migrate rb_* options to sprb_* on upgrade from ≤1.2.1.
     */
    add_action( 'admin_init', function () {
        if ( get_option( 'sprb_schema_version', '' ) === SPRB_VERSION ) {
            return;
        }

        $old_keys = array(
            'rb_pull_token',
            'rb_remote_protocol',
            'rb_manual_remote_mode',
            'rb_backup_folders',
            'rb_retain_db',
            'rb_retain_files',
            'rb_schedule_database_frequency',
            'rb_schedule_database_time',
            'rb_schedule_database_weekday',
            'rb_schedule_files_frequency',
            'rb_schedule_files_time',
            'rb_schedule_files_weekday',
            'rb_scheduled_remote_mode_database',
            'rb_scheduled_remote_mode_files',
            'rb_scheduled_scope',
            'rb_schedule_frequency',
            'rb_schedule_time',
            'rb_scheduled_remote_mode',
            'rb_active_backup_job_id',
            'rb_sim_state',
            'rb_ftp_host',
            'rb_ftp_port',
            'rb_ftp_username',
            'rb_ftp_password',
            'rb_ftp_path',
            'rb_ftp_passive',
            'rb_ssh_host',
            'rb_ssh_port',
            'rb_ssh_username',
            'rb_ssh_auth_method',
            'rb_ssh_key',
            'rb_ssh_password',
            'rb_ssh_path',
            'rb_gdrive_client_id',
            'rb_gdrive_client_secret',
            'rb_gdrive_folder_id',
            'rb_google_drive_tokens',
            'rb_onedrive_client_id',
            'rb_onedrive_client_secret',
            'rb_onedrive_folder_id',
            'rb_onedrive_tokens',
            'rb_dropbox_client_id',
            'rb_dropbox_client_secret',
            'rb_dropbox_folder_name',
            'rb_dropbox_tokens',
            'rb_monitor_retry_minutes',
            'rb_monitor_watch_minutes',
            'rb_monitor_notification_email',
        );

        foreach ( $old_keys as $old_key ) {
            $value = get_option( $old_key, null );
            if ( null === $value ) {
                continue;
            }
            $new_key = 'sprb_' . substr( $old_key, 3 );
            if ( false === get_option( $new_key ) ) {
                update_option( $new_key, $value );
            }
            delete_option( $old_key );
        }

        // Migrate cron hooks.
        $cron_map = array(
            'rb_scheduled_backup'          => 'sprb_scheduled_backup',
            'rb_scheduled_backup_database' => 'sprb_scheduled_backup_database',
            'rb_scheduled_backup_files'    => 'sprb_scheduled_backup_files',
            'rb_monitor_poll'              => 'sprb_monitor_poll',
            'rb_async_manual_backup'       => 'sprb_async_manual_backup',
        );

        $crons = _get_cron_array();
        if ( is_array( $crons ) ) {
            $dirty = false;
            foreach ( $crons as $ts => &$hooks ) {
                foreach ( $cron_map as $old_hook => $new_hook ) {
                    if ( isset( $hooks[ $old_hook ] ) ) {
                        $hooks[ $new_hook ] = $hooks[ $old_hook ];
                        unset( $hooks[ $old_hook ] );
                        $dirty = true;
                    }
                }
            }
            unset( $hooks );
            if ( $dirty ) {
                _set_cron_array( $crons );
            }
        }

        // Migrate transients.
        global $wpdb;
        $old_transients = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_transient_rb_%' OR option_name LIKE '_transient_timeout_rb_%'"
        );
        foreach ( $old_transients as $row ) {
            $new_name = str_replace( '_rb_', '_sprb_', $row->option_name );
            if ( false === get_option( $new_name ) ) {
                update_option( $new_name, maybe_unserialize( $row->option_value ) );
            }
            delete_option( $row->option_name );
        }

        update_option( 'sprb_schema_version', SPRB_VERSION );
    } );

    Remote_Backup_Plugin::instance();

} catch ( \Throwable $e ) {
    add_action( 'admin_notices', function () use ( $e ) {
        printf(
            '<div class="notice notice-error"><p><strong>SavedPixel Remote Backup</strong> failed to load: %s</p></div>',
            esc_html( $e->getMessage() )
        );
    } );
}
