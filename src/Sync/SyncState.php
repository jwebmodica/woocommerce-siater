<?php
/**
 * Sync State Manager
 *
 * Manages synchronization state - COMPATIBLE with original wp_siater_feed table
 * Uses id=777 row format with columns: inizio, offsetto, sincro, sync_lock, lock_timestamp
 */

namespace Siater\Sync;

defined('ABSPATH') || exit;

class SyncState {

    /**
     * Table name
     */
    private string $table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'siater_feed';
    }

    /**
     * Get current state
     */
    public function get(): ?object {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM {$this->table} WHERE id = 777");
    }

    /**
     * Get current offset
     */
    public function get_offset(): int {
        $state = $this->get();
        return $state ? (int) $state->offsetto : 0;
    }

    /**
     * Set offset
     */
    public function set_offset(int $offset): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            ['offsetto' => $offset],
            ['id' => 777],
            ['%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Get last sync timestamp
     */
    public function get_last_sync(): int {
        $state = $this->get();
        return $state ? (int) $state->inizio : 0;
    }

    /**
     * Check if sync is in progress
     */
    public function is_syncing(): bool {
        $state = $this->get();
        return $state && (bool) $state->sincro;
    }

    /**
     * Set sync in progress flag
     */
    public function set_syncing(bool $syncing): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            ['sincro' => $syncing ? 1 : 0],
            ['id' => 777],
            ['%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Mark sync as completed - reset state
     */
    public function mark_completed(): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            [
                'inizio' => time(),
                'offsetto' => 0,
                'sincro' => 0,
                'sync_lock' => 0,
                'lock_timestamp' => 0,
            ],
            ['id' => 777],
            ['%d', '%d', '%d', '%d', '%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Check if sync is currently locked
     */
    public function is_locked(): bool {
        $state = $this->get();
        return $state && (bool) $state->sync_lock;
    }

    /**
     * Acquire lock
     */
    public function acquire_lock(): bool {
        global $wpdb;

        $state = $this->get();

        // Check for stale lock (> 10 minutes)
        if ($state && $state->sync_lock) {
            $lock_age = time() - (int) $state->lock_timestamp;
            if ($lock_age < 600) {
                return false; // Lock is still valid
            }
        }

        // Acquire lock
        return $wpdb->update(
            $this->table,
            [
                'sync_lock' => 1,
                'lock_timestamp' => time(),
                'sincro' => 1,
            ],
            ['id' => 777],
            ['%d', '%d', '%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Release lock
     */
    public function release_lock(): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            [
                'sync_lock' => 0,
                'lock_timestamp' => 0,
            ],
            ['id' => 777],
            ['%d', '%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Reset sync state completely
     */
    public function reset(): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            [
                'inizio' => 0,
                'offsetto' => 0,
                'sincro' => 0,
                'sync_lock' => 0,
                'lock_timestamp' => 0,
            ],
            ['id' => 777],
            ['%d', '%d', '%d', '%d', '%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Get hours since last sync
     */
    public function hours_since_last_sync(): float {
        $last_sync = $this->get_last_sync();
        if ($last_sync === 0) {
            return PHP_FLOAT_MAX;
        }
        return (time() - $last_sync) / 3600;
    }

    /**
     * Update lock timestamp (heartbeat)
     */
    public function update_lock_timestamp(): bool {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            ['lock_timestamp' => time()],
            ['id' => 777],
            ['%d'],
            ['%d']
        ) !== false;
    }
}
