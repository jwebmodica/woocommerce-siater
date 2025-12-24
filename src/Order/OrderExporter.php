<?php
/**
 * Order Exporter
 *
 * Exports WooCommerce orders to CSV for SIA system
 */

namespace Siater2026\Order;

defined('ABSPATH') || exit;

use Siater2026\Core\Settings;
use Siater2026\Utils\Logger;

class OrderExporter {

    /**
     * Settings instance
     */
    private Settings $settings;

    /**
     * Logger instance
     */
    private Logger $logger;

    /**
     * CSV headers
     */
    private const HEADERS = [
        'Order id',
        'Date added',
        'Product Ref',
        'Product Quantity',
        'Product Price',
        'Customer Reference',
        'Payment module',
        'Delivery Company Name',
        'Delivery Firstname',
        'Delivery Lastname',
        'Delivery address line 1',
        'Delivery address line 2',
        'Delivery postcode',
        'Delivery city',
        'Delivery State',
        'Delivery phone',
        'Message',
        'Product Name',
        'Delivery email',
    ];

    /**
     * Constructor
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->logger = Logger::instance();
    }

    /**
     * Run export
     */
    public function run(): void {
        if ($this->settings->get('debug_enabled', 0)) {
            $this->logger->enable();
        }

        $this->logger->info('Starting order export');

        try {
            $this->export();
            $this->logger->success('Order export completed');
            echo "Export completed\n";
        } catch (\Exception $e) {
            $this->logger->error('Export failed: ' . $e->getMessage());
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Export orders to CSV
     */
    private function export(): void {
        // Get unexported orders
        $orders = $this->get_orders_to_export();

        if (empty($orders)) {
            $this->logger->info('No new orders to export');
            return;
        }

        $this->logger->info('Found ' . count($orders) . ' orders to export');

        // Get export path
        $file_path = $this->settings->get_export_path();

        // Ensure directory exists
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Open file
        $file_exists = file_exists($file_path);
        $handle = fopen($file_path, 'a');

        if (!$handle) {
            throw new \Exception("Cannot open file for writing: $file_path");
        }

        // Write headers if new file
        if (!$file_exists) {
            fputcsv($handle, self::HEADERS, ';');
        }

        // Export each order
        foreach ($orders as $order_id) {
            $this->export_order($handle, $order_id);
        }

        fclose($handle);

        $this->logger->success('Exported ' . count($orders) . ' orders to ' . $file_path);
    }

    /**
     * Get orders to export
     */
    private function get_orders_to_export(): array {
        // Use WooCommerce HPOS if available
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return $this->get_orders_hpos();
        }

        return $this->get_orders_legacy();
    }

    /**
     * Get orders using HPOS
     */
    private function get_orders_hpos(): array {
        $orders = wc_get_orders([
            'status' => 'processing',
            'limit' => -1,
            'meta_query' => [
                [
                    'key' => '_order_exported',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        return array_map(function($order) {
            return $order->get_id();
        }, $orders);
    }

    /**
     * Get orders using legacy tables
     */
    private function get_orders_legacy(): array {
        global $wpdb;

        $results = $wpdb->get_col("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_exported'
            WHERE p.post_type = 'shop_order'
            AND p.post_status = 'wc-processing'
            AND pm.meta_id IS NULL
            ORDER BY p.ID ASC
        ");

        return $results ?: [];
    }

    /**
     * Export a single order
     */
    private function export_order($handle, int $order_id): void {
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->logger->warning("Order not found: $order_id");
            return;
        }

        // Get order items
        $items = $order->get_items();

        if (empty($items)) {
            $this->logger->warning("Order has no items: $order_id");
            return;
        }

        // Get customer info
        $customer_note = $this->sanitize_note($order->get_customer_note());

        // Export each line item
        foreach ($items as $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            $product_name = $item->get_name();

            $row = [
                $order->get_id(),
                $order->get_date_created()->format('Y-m-d H:i:s'),
                $sku,
                $item->get_quantity(),
                $item->get_total(),
                $order->get_billing_email(),
                $order->get_payment_method_title(),
                $order->get_shipping_company(),
                $order->get_shipping_first_name(),
                $order->get_shipping_last_name(),
                $order->get_shipping_address_1(),
                $order->get_shipping_address_2(),
                $order->get_shipping_postcode(),
                $order->get_shipping_city(),
                $order->get_shipping_state(),
                $order->get_billing_phone(),
                $customer_note,
                $product_name,
                $order->get_billing_email(),
            ];

            fputcsv($handle, $row, ';');
        }

        // Mark order as exported
        $order->update_meta_data('_order_exported', '1');
        $order->update_meta_data('_order_exported_at', current_time('mysql'));
        $order->save();

        $this->logger->info("Exported order: $order_id");
    }

    /**
     * Sanitize customer note
     */
    private function sanitize_note(string $note): string {
        // Remove HTML
        $note = wp_strip_all_tags($note);

        // Remove semicolons (CSV delimiter)
        $note = str_replace(';', ',', $note);

        // Remove newlines
        $note = str_replace(["\r\n", "\r", "\n"], ' ', $note);

        // Trim and limit length
        $note = trim($note);
        if (strlen($note) > 500) {
            $note = substr($note, 0, 497) . '...';
        }

        return $note;
    }

    /**
     * Get export file path
     */
    public function get_export_file(): string {
        return $this->settings->get_export_path();
    }

    /**
     * Clear export file
     */
    public function clear_export_file(): void {
        $file_path = $this->settings->get_export_path();
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
}
