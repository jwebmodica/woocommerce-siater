<?php
/**
 * Debug Logger
 *
 * Handles debug logging with automatic rotation
 */

namespace Siater\Utils;

defined('ABSPATH') || exit;

class Logger {

    /**
     * Singleton instance
     */
    private static ?Logger $instance = null;

    /**
     * Log file path
     */
    private string $log_file;

    /**
     * Start time for duration tracking
     */
    private float $start_time;

    /**
     * Is debug enabled
     */
    private bool $enabled = false;

    /**
     * Max log lines before rotation
     */
    private int $max_lines = 500;

    /**
     * Get singleton instance
     */
    public static function instance(): Logger {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/siater-debug.log';
        $this->start_time = microtime(true);
    }

    /**
     * Enable logging
     */
    public function enable(): void {
        $this->enabled = true;
    }

    /**
     * Disable logging
     */
    public function disable(): void {
        $this->enabled = false;
    }

    /**
     * Check if enabled
     */
    public function is_enabled(): bool {
        return $this->enabled;
    }

    /**
     * Log a message
     */
    public function log(string $message, string $level = 'INFO'): void {
        if (!$this->enabled && $level !== 'ERROR') {
            return;
        }

        $elapsed = round(microtime(true) - $this->start_time, 3);
        $memory = round(memory_get_usage(true) / 1024 / 1024, 2);
        $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] [%s] [%.3fs] [%.2fMB / %.2fMB peak] %s\n",
            $timestamp,
            $level,
            $elapsed,
            $memory,
            $peak,
            $message
        );

        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        $this->rotate_if_needed();
    }

    /**
     * Log info message
     */
    public function info(string $message): void {
        $this->log($message, 'INFO');
    }

    /**
     * Log error message
     */
    public function error(string $message): void {
        $this->log($message, 'ERROR');
    }

    /**
     * Log warning message
     */
    public function warning(string $message): void {
        $this->log($message, 'WARNING');
    }

    /**
     * Log success message
     */
    public function success(string $message): void {
        $this->log($message, 'SUCCESS');
    }

    /**
     * Log debug message
     */
    public function debug(string $message): void {
        $this->log($message, 'DEBUG');
    }

    /**
     * Start sync session
     */
    public function start_sync(): void {
        $this->start_time = microtime(true);

        // Clear old logs
        file_put_contents($this->log_file, '');

        $this->info('========== SYNC STARTED ==========');
        $this->info('PHP Version: ' . PHP_VERSION);
        $this->info('WordPress: ' . get_bloginfo('version'));
        $this->info('WooCommerce: ' . (defined('WC_VERSION') ? WC_VERSION : 'N/A'));
        $this->info('Memory Limit: ' . ini_get('memory_limit'));
    }

    /**
     * End sync session
     */
    public function end_sync(bool $success = true): void {
        $elapsed = round(microtime(true) - $this->start_time, 2);
        $status = $success ? 'COMPLETED' : 'FAILED';

        $this->info("========== SYNC {$status} ({$elapsed}s) ==========");
    }

    /**
     * Rotate log if too large
     */
    private function rotate_if_needed(): void {
        if (!file_exists($this->log_file)) {
            return;
        }

        $lines = file($this->log_file);
        if ($lines && count($lines) > $this->max_lines) {
            $keep = array_slice($lines, -($this->max_lines / 2));
            file_put_contents($this->log_file, implode('', $keep), LOCK_EX);
        }
    }

    /**
     * Get log content
     */
    public function get_content(int $lines = 100): string {
        if (!file_exists($this->log_file)) {
            return '';
        }

        $content = file($this->log_file);
        if (!$content) {
            return '';
        }

        return implode('', array_slice($content, -$lines));
    }

    /**
     * Clear log file
     */
    public function clear(): void {
        file_put_contents($this->log_file, '');
    }

    /**
     * Get log file path
     */
    public function get_file_path(): string {
        return $this->log_file;
    }
}
