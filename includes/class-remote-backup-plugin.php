<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remote_Backup_Plugin {

    private static $instance = null;
    public $storage;
    public $runner;
    public $downloads;
    public $admin;
    public $logger;
    public $scheduler;
    public $api;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_modules();
    }

    private function load_modules() {
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-logger.php';
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-storage.php';
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-runner.php';
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-downloads.php';
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-scheduler.php';
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-api.php';
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-admin.php';
        require_once SPRB_PLUGIN_DIR . 'includes/providers/interface-remote-provider.php';
        require_once SPRB_PLUGIN_DIR . 'includes/providers/class-provider-ssh.php';
        require_once SPRB_PLUGIN_DIR . 'includes/providers/class-provider-ftp.php';
        require_once SPRB_PLUGIN_DIR . 'includes/providers/class-provider-google-drive.php';
        require_once SPRB_PLUGIN_DIR . 'includes/providers/class-provider-onedrive.php';
        require_once SPRB_PLUGIN_DIR . 'includes/providers/class-provider-dropbox.php';

        $this->logger    = new Remote_Backup_Logger();
        $this->storage   = new Remote_Backup_Storage();
        $this->runner    = new Remote_Backup_Runner( $this->storage, $this->logger );
        $this->downloads = new Remote_Backup_Downloads( $this->storage );
        $this->scheduler = new Remote_Backup_Scheduler( $this->runner, $this->logger, $this->storage );

        $this->scheduler->register_provider( new Provider_SSH() );
        $this->scheduler->register_provider( new Provider_FTP() );
        $this->scheduler->register_provider( new Provider_Google_Drive() );
        $this->scheduler->register_provider( new Provider_OneDrive() );
        $this->scheduler->register_provider( new Provider_Dropbox() );

        $this->admin     = new Remote_Backup_Admin( $this->storage, $this->runner, $this->downloads, $this->logger, $this->scheduler );
        $this->api       = new Remote_Backup_Api( $this->storage, $this->scheduler, $this->admin );
    }

    public static function activate() {
        require_once SPRB_PLUGIN_DIR . 'includes/class-remote-backup-storage.php';
        $storage = new Remote_Backup_Storage();
        $storage->ensure_directories();
    }

    public static function deactivate() {
        // Clear scheduled cron on deactivation.
        foreach ( array( Remote_Backup_Scheduler::CRON_HOOK, Remote_Backup_Scheduler::CRON_HOOK_DATABASE, Remote_Backup_Scheduler::CRON_HOOK_FILES ) as $hook ) {
            $ts = wp_next_scheduled( $hook );
            if ( $ts ) {
                wp_unschedule_event( $ts, $hook );
            }
        }
    }
}
