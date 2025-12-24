<?php
/**
 * Sync Handler
 *
 * Main synchronization orchestrator - coordinates the sync process
 * Uses original wp_siater_feed table for state management
 */

namespace Siater2026\Sync;

defined('ABSPATH') || exit;

use Siater2026\Core\Settings;
use Siater2026\Utils\Logger;
use Siater2026\Product\ProductHandler;
use Siater2026\Product\VariationHandler;

class SyncHandler {

    /**
     * Settings instance
     */
    private Settings $settings;

    /**
     * Logger instance
     */
    private Logger $logger;

    /**
     * Sync state manager
     */
    private SyncState $state;

    /**
     * Feed parser
     */
    private FeedParser $feed_parser;

    /**
     * Product handler
     */
    private ProductHandler $product_handler;

    /**
     * Variation handler
     */
    private VariationHandler $variation_handler;

    /**
     * Max execution time (seconds)
     */
    private int $max_time = 540; // 9 minutes

    /**
     * Start time
     */
    private float $start_time;

    /**
     * Sync interval in hours
     */
    private int $sync_interval = 3;

    /**
     * Constructor
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->logger = Logger::instance();
        $this->state = new SyncState();
        $this->feed_parser = new FeedParser($settings);
        $this->product_handler = new ProductHandler($settings);
        $this->variation_handler = new VariationHandler($settings);
    }

    /**
     * Run synchronization
     */
    public function run(): void {
        $this->start_time = microtime(true);

        // Configure PHP
        $this->configure_environment();

        // Enable debug if setting is on
        if ($this->settings->get('debug_enabled', 0)) {
            $this->logger->enable();
        }

        // Acquire lock
        if (!$this->state->acquire_lock()) {
            $this->output('Sync already in progress');
            return;
        }

        $this->logger->start_sync();
        $this->output('Sync started');

        try {
            $this->process_sync();
            $this->logger->end_sync(true);
        } catch (\Exception $e) {
            $this->logger->error('Sync failed: ' . $e->getMessage());
            $this->logger->end_sync(false);
            $this->output('Error: ' . $e->getMessage());
        } finally {
            $this->state->release_lock();
        }
    }

    /**
     * Process synchronization
     */
    private function process_sync(): void {
        $offset = $this->state->get_offset();
        $hours_since_last = $this->state->hours_since_last_sync();

        // Check if we should start a new sync or continue
        if ($offset === 0 && $hours_since_last < $this->sync_interval) {
            $remaining = round($this->sync_interval - $hours_since_last, 1);
            $this->output("Last sync was " . round($hours_since_last, 1) . " hours ago. Next sync in {$remaining} hours.");
            return;
        }

        $this->logger->info("Starting sync from offset: $offset");
        $this->output("Processing from offset: $offset");

        // Fetch products from feed
        $products = $this->feed_parser->fetch($offset);
        $count = count($products);

        $this->logger->info("Fetched $count products");
        $this->output("Found $count products");

        if (empty($products)) {
            // No more products - sync complete
            $this->complete_sync();
            return;
        }

        // Track parent products for variation syncing
        $parent_products = [];

        // Process products
        $processed = 0;
        $has_variations = $this->settings->get('tagliecolori', 0);

        foreach ($products as $product) {
            // Check time limit
            if ($this->is_time_exceeded()) {
                $this->logger->warning('Time limit reached, will continue on next run');
                break;
            }

            // Check if we should skip products without variation images
            if ($has_variations && $this->settings->get('solo_prodotti_con_foto_varianti', 0)) {
                if (!$product['has_variation_images']) {
                    $this->logger->debug("Skipping product {$product['cod']} - no variation images");
                    $processed++;
                    continue;
                }
            }

            if ($has_variations && $product['is_variation']) {
                // This is a variation row
                $parent_sku = $product['gruppov'] ?? $product['cod'];

                // Create or get parent product
                if (!isset($parent_products[$parent_sku])) {
                    $parent_id = $this->product_handler->sync_variable($product);
                    if ($parent_id) {
                        $parent_products[$parent_sku] = $parent_id;
                    }
                }

                // Create/update variation
                if (isset($parent_products[$parent_sku])) {
                    $this->variation_handler->sync($parent_products[$parent_sku], $product);
                }
            } else {
                // Simple product
                $this->product_handler->sync_simple($product);
            }

            $processed++;

            // Memory cleanup every 50 products
            if ($processed % 50 === 0) {
                $this->memory_cleanup();
                $this->logger->info("Processed $processed products");

                // Update lock timestamp (heartbeat)
                $this->state->update_lock_timestamp();
            }
        }

        // Sync parent stock for all variable products
        foreach ($parent_products as $parent_id) {
            $this->variation_handler->sync_parent_stock($parent_id);
        }

        // Update offset
        $new_offset = $offset + $this->settings->get('num_record', 300);

        // Check if this was the last batch
        $batch_size = $this->settings->get('num_record', 300);
        if ($count < $batch_size) {
            // All products processed
            $this->complete_sync();
        } else {
            // More products to process
            $this->state->set_offset($new_offset);
            $this->output("Batch complete. Processed $processed products. Next offset: $new_offset");
        }
    }

    /**
     * Complete sync - reset state
     */
    private function complete_sync(): void {
        $this->state->mark_completed();
        $this->logger->success('Sync completed successfully');
        $this->output('Sync completed!');
    }

    /**
     * Configure PHP environment
     */
    private function configure_environment(): void {
        // Set time limit
        @set_time_limit(600);

        // Set memory limit
        $current = ini_get('memory_limit');
        $current_bytes = wp_convert_hr_to_bytes($current);
        $target_bytes = 256 * 1024 * 1024;

        if ($current_bytes < $target_bytes) {
            @ini_set('memory_limit', '256M');
        }
    }

    /**
     * Check if time limit exceeded
     */
    private function is_time_exceeded(): bool {
        $elapsed = microtime(true) - $this->start_time;
        return $elapsed >= $this->max_time;
    }

    /**
     * Memory cleanup
     */
    private function memory_cleanup(): void {
        global $wpdb;

        // Clear WordPress object cache
        wp_cache_flush();

        // Clear WPDB query log
        $wpdb->queries = [];

        // Force garbage collection if available
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * Output message
     */
    private function output(string $message): void {
        if ($this->settings->get('verbose_output', 0)) {
            echo $message . "\n";
            flush();
        }
    }

    /**
     * Get sync status
     */
    public function get_status(): array {
        $state = $this->state->get();

        return [
            'is_running' => $this->state->is_locked(),
            'is_syncing' => $this->state->is_syncing(),
            'current_offset' => $this->state->get_offset(),
            'last_sync' => $this->state->get_last_sync(),
            'last_sync_formatted' => $this->state->get_last_sync() ?
                date('Y-m-d H:i:s', $this->state->get_last_sync()) : 'Mai',
            'hours_since_last' => round($this->state->hours_since_last_sync(), 1),
        ];
    }

    /**
     * Force reset sync state
     */
    public function reset(): void {
        $this->state->reset();
    }
}
