<?php
/**
 * Plugin Name: SavedPixel Remote Backup
 * Plugin URI:  https://github.com/savedpixel
 * Description: Create, schedule, and export WordPress backups.
 * Version:     1.2.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Byron Jacobs
 * Author URI:  https://github.com/savedpixel
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

    if ( ! defined( 'RB_VERSION' ) ) {
        define( 'RB_VERSION', '1.2.1' );
    }

    if ( ! defined( 'RB_PLUGIN_DIR' ) ) {
        define( 'RB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    }

    if ( ! defined( 'RB_PLUGIN_URL' ) ) {
        define( 'RB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }

    if ( ! defined( 'RB_BASE_DIR' ) ) {
        define( 'RB_BASE_DIR', WP_CONTENT_DIR . '/remote-backup/' );
    }

    if ( ! defined( 'RB_STORAGE_DIR' ) ) {
        define( 'RB_STORAGE_DIR', trailingslashit( ABSPATH ) . 'storage/' );
    }

    if ( ! defined( 'RB_DATA_DIR' ) ) {
        define( 'RB_DATA_DIR', RB_BASE_DIR . 'data/' );
    }

    require_once RB_PLUGIN_DIR . 'includes/class-remote-backup-plugin.php';

    register_activation_hook( __FILE__, array( 'Remote_Backup_Plugin', 'activate' ) );
    register_deactivation_hook( __FILE__, array( 'Remote_Backup_Plugin', 'deactivate' ) );

    Remote_Backup_Plugin::instance();

} catch ( \Throwable $e ) {
    add_action( 'admin_notices', function () use ( $e ) {
        printf(
            '<div class="notice notice-error"><p><strong>SavedPixel Remote Backup</strong> failed to load: %s</p></div>',
            esc_html( $e->getMessage() )
        );
    } );
}
