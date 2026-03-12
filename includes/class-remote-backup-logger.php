<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'Remote_Backup_Logger' ) ) {
    return;
}

class Remote_Backup_Logger {

    private $log_file;

    public function __construct() {
        $this->log_file = RB_DATA_DIR . 'debug.log';
    }

    public function log( $message, $level = 'info' ) {
        $timestamp = gmdate( 'Y-m-d H:i:s' );
        $line      = sprintf( "[%s] [%s] %s\n", $timestamp, strtoupper( $level ), $message );
        file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );

        // Rotate if over 500 KB.
        if ( file_exists( $this->log_file ) && filesize( $this->log_file ) > 512000 ) {
            $rotated = RB_DATA_DIR . 'debug-prev.log';
            if ( file_exists( $rotated ) ) {
                wp_delete_file( $rotated );
            }
            rename( $this->log_file, $rotated ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Log rotation stays on the same local filesystem.
        }
    }

    public function get_log( $lines = 200 ) {
        if ( ! file_exists( $this->log_file ) ) {
            return '';
        }
        $all   = file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $tail  = array_slice( $all, -$lines );
        $tail  = array_reverse( $tail );
        return implode( "\n", $tail );
    }

    public function clear() {
        if ( file_exists( $this->log_file ) ) {
            file_put_contents( $this->log_file, '' );
        }
    }
}
