<?php
/**
 * Product Cleaner
 *
 * Removes products that are no longer in the SIA feed
 * Uses a 3-phase approach:
 * 1) Fetch & cache all SKUs from SIA (in batches)
 * 2) Compare with WooCommerce products
 * 3) Trash products not found in SIA
 */

namespace Siater2026\Sync;

defined('ABSPATH') || exit;

use Siater2026\Core\Settings;
use Siater2026\Utils\Logger;

class ProductCleaner {

    /**
     * Settings instance
     */
    private Settings $settings;

    /**
     * Logger instance
     */
    private Logger $logger;

    /**
     * Configuration
     */
    private int $hours_between_cycles = 6;
    private int $delete_batch_size = 50;
    private int $fetch_batch_size = 500;

    /**
     * Option keys for state tracking
     */
    private const OPTION_PHASE = 'siater_cleaner_phase';
    private const OPTION_FETCH_OFFSET = 'siater_cleaner_fetch_offset';
    private const OPTION_SUPPLIER_SKUS = 'siater_cleaner_supplier_skus';
    private const OPTION_SKUS_TO_DELETE = 'siater_cleaner_skus_to_delete';
    private const OPTION_LAST_COMPLETE = 'siater_cleaner_last_complete';

    /**
     * Constructor
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->logger = Logger::instance();
    }

    /**
     * Run the cleaner (call this from cron)
     */
    public function run(): void {
        if ($this->settings->get('debug_enabled', 0)) {
            $this->logger->enable();
        }

        $current_phase = get_option(self::OPTION_PHASE, '');

        // No active phase - check if we should start
        if (empty($current_phase)) {
            if (!$this->should_start_new_cycle()) {
                return;
            }
            $current_phase = 'fetch';
            update_option(self::OPTION_PHASE, 'fetch');
            update_option(self::OPTION_FETCH_OFFSET, 0);
            delete_option(self::OPTION_SUPPLIER_SKUS);
            $this->logger->info('Product Cleaner: Starting new cycle');
        }

        // Execute current phase
        switch ($current_phase) {
            case 'fetch':
                $this->phase_fetch();
                break;
            case 'compare':
                $this->phase_compare();
                break;
            case 'delete':
                $this->phase_delete();
                break;
        }
    }

    /**
     * Check if we should start a new cleaning cycle
     */
    private function should_start_new_cycle(): bool {
        $last_complete = get_option(self::OPTION_LAST_COMPLETE, 0);

        if ($last_complete > 0) {
            $hours_passed = (time() - $last_complete) / 3600;
            if ($hours_passed < $this->hours_between_cycles) {
                return false;
            }
        }

        return true;
    }

    /**
     * Phase 1: Fetch SKUs from SIA feed
     */
    private function phase_fetch(): void {
        $fetch_offset = (int) get_option(self::OPTION_FETCH_OFFSET, 0);
        $supplier_skus = get_option(self::OPTION_SUPPLIER_SKUS, []);

        $this->logger->info("Cleaner Phase 1: Fetching SKUs (offset: $fetch_offset)");

        // Build URL
        $url = $this->settings->get_sku_feed_url($fetch_offset, $this->fetch_batch_size);

        if (empty($url)) {
            $this->logger->error('Cleaner: No feed URL configured');
            $this->abort_cycle();
            return;
        }

        // Fetch feed
        $body = $this->fetch_url($url);

        if ($body === false) {
            $this->logger->error('Cleaner: Failed to fetch feed');
            return; // Will retry on next run
        }

        // Parse feed
        $lines = array_filter(explode('{||}', $body));

        // Check if we have data
        if (count($lines) < 2) {
            if (empty($supplier_skus)) {
                $this->logger->error('Cleaner: No SKUs found in feed');
                $this->abort_cycle();
                return;
            }

            // Done fetching, move to compare
            delete_option(self::OPTION_FETCH_OFFSET);
            update_option(self::OPTION_PHASE, 'compare');
            $this->logger->info('Cleaner: Fetch complete. Total SKUs: ' . count($supplier_skus));
            return;
        }

        // Parse header to find Codice column
        $header = explode('{|}', $lines[0]);
        $codice_index = array_search('Codice', $header);

        // If not found, try first column (cod)
        if ($codice_index === false) {
            $codice_index = 0;
        }

        // Extract SKUs from this batch
        $batch_count = 0;
        for ($i = 1; $i < count($lines); $i++) {
            $fields = explode('{|}', $lines[$i]);
            if (isset($fields[$codice_index]) && !empty(trim($fields[$codice_index]))) {
                $supplier_skus[] = trim($fields[$codice_index]);
                $batch_count++;
            }
        }

        // Save updated SKUs list
        update_option(self::OPTION_SUPPLIER_SKUS, $supplier_skus, false);

        // Check if this was the last batch
        if ($batch_count < $this->fetch_batch_size) {
            delete_option(self::OPTION_FETCH_OFFSET);
            update_option(self::OPTION_PHASE, 'compare');
            $this->logger->info("Cleaner: Fetched $batch_count SKUs (last batch). Total: " . count($supplier_skus));
        } else {
            $next_offset = $fetch_offset + $this->fetch_batch_size;
            update_option(self::OPTION_FETCH_OFFSET, $next_offset);
            $this->logger->info("Cleaner: Fetched $batch_count SKUs. Total: " . count($supplier_skus) . ". Next offset: $next_offset");
        }
    }

    /**
     * Phase 2: Compare and find products to delete
     */
    private function phase_compare(): void {
        global $wpdb;

        $this->logger->info('Cleaner Phase 2: Comparing products');

        $supplier_skus = get_option(self::OPTION_SUPPLIER_SKUS, []);

        if (empty($supplier_skus)) {
            $this->logger->error('Cleaner: No cached supplier SKUs');
            $this->abort_cycle();
            return;
        }

        // Get all WooCommerce product SKUs
        $woo_products = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as sku
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
        ");

        $this->logger->info('Cleaner: Supplier: ' . count($supplier_skus) . ' SKUs | WooCommerce: ' . count($woo_products) . ' products');

        // Create lookup for fast comparison
        $supplier_skus_lookup = array_flip($supplier_skus);

        // Find products NOT in supplier catalog
        $skus_to_delete = [];
        foreach ($woo_products as $product) {
            if (!isset($supplier_skus_lookup[$product->sku])) {
                $skus_to_delete[] = $product->sku;
            }
        }

        // Clean up supplier SKUs cache
        delete_option(self::OPTION_SUPPLIER_SKUS);

        if (empty($skus_to_delete)) {
            $this->complete_cycle();
            $this->logger->success('Cleaner: All products are in sync');
            return;
        }

        // Save SKUs to delete
        update_option(self::OPTION_SKUS_TO_DELETE, $skus_to_delete, false);
        update_option(self::OPTION_PHASE, 'delete');

        $this->logger->info('Cleaner: Found ' . count($skus_to_delete) . ' products to trash');
    }

    /**
     * Phase 3: Trash products in batches
     */
    private function phase_delete(): void {
        global $wpdb;

        $skus_to_delete = get_option(self::OPTION_SKUS_TO_DELETE, []);

        if (empty($skus_to_delete)) {
            $this->complete_cycle();
            return;
        }

        $this->logger->info('Cleaner Phase 3: Trashing products (' . count($skus_to_delete) . ' remaining)');

        // Get batch of SKUs to process
        $batch = array_slice($skus_to_delete, 0, $this->delete_batch_size);

        if (empty($batch)) {
            $this->complete_cycle();
            return;
        }

        // Escape SKUs for SQL IN clause
        $escaped_skus = array_map(function($sku) use ($wpdb) {
            return $wpdb->prepare('%s', $sku);
        }, $batch);
        $sku_list = implode(',', $escaped_skus);

        // Get all product IDs for these SKUs
        $product_ids = $wpdb->get_col("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku' AND meta_value IN ({$sku_list})
        ");

        $trashed = 0;
        if (!empty($product_ids)) {
            $id_list = implode(',', array_map('intval', $product_ids));

            // Trash products (direct SQL for performance)
            $wpdb->query("
                UPDATE {$wpdb->posts}
                SET post_status = 'trash'
                WHERE ID IN ({$id_list}) AND post_type = 'product'
            ");

            $trashed = count($product_ids);
        }

        // Remove processed SKUs from list
        $skus_to_delete = array_slice($skus_to_delete, $this->delete_batch_size);

        if (empty($skus_to_delete)) {
            $this->complete_cycle();
            $this->clear_wc_caches();
            $this->logger->success("Cleaner: Trashed $trashed products. Cycle complete.");
        } else {
            update_option(self::OPTION_SKUS_TO_DELETE, $skus_to_delete, false);
            $this->logger->info("Cleaner: Trashed $trashed. " . count($skus_to_delete) . " remaining.");
        }
    }

    /**
     * Complete the cleaning cycle
     */
    private function complete_cycle(): void {
        delete_option(self::OPTION_PHASE);
        delete_option(self::OPTION_SKUS_TO_DELETE);
        delete_option(self::OPTION_SUPPLIER_SKUS);
        delete_option(self::OPTION_FETCH_OFFSET);
        update_option(self::OPTION_LAST_COMPLETE, time());
    }

    /**
     * Abort cycle (on error)
     */
    private function abort_cycle(): void {
        delete_option(self::OPTION_PHASE);
        delete_option(self::OPTION_FETCH_OFFSET);
        delete_option(self::OPTION_SUPPLIER_SKUS);
    }

    /**
     * Clear WooCommerce caches
     */
    private function clear_wc_caches(): void {
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
        delete_transient('wc_term_counts');
        wp_cache_flush();
    }

    /**
     * Fetch URL content
     */
    private function fetch_url(string $url): string|false {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'timeout' => 60,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        return @file_get_contents($url, false, $context);
    }

    /**
     * Get cleaner status
     */
    public function get_status(): array {
        $phase = get_option(self::OPTION_PHASE, '');
        $last_complete = get_option(self::OPTION_LAST_COMPLETE, 0);
        $skus_to_delete = get_option(self::OPTION_SKUS_TO_DELETE, []);

        return [
            'phase' => $phase ?: 'idle',
            'last_complete' => $last_complete,
            'last_complete_formatted' => $last_complete ? date('Y-m-d H:i:s', $last_complete) : 'Mai',
            'pending_deletions' => count($skus_to_delete),
            'next_run_hours' => $last_complete ? max(0, $this->hours_between_cycles - ((time() - $last_complete) / 3600)) : 0,
        ];
    }

    /**
     * Force start a new cycle
     */
    public function force_start(): void {
        delete_option(self::OPTION_LAST_COMPLETE);
        $this->abort_cycle();
    }

    /**
     * Set hours between cycles
     */
    public function set_interval(int $hours): void {
        $this->hours_between_cycles = max(1, $hours);
    }
}
