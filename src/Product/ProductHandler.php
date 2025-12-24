<?php
/**
 * Product Handler
 *
 * Handles creating and updating WooCommerce products
 * Uses SKU (cod) to check if product exists instead of custom table
 */

namespace Siater\Product;

defined('ABSPATH') || exit;

use Siater\Core\Settings;
use Siater\Utils\Logger;

class ProductHandler {

    /**
     * Settings instance
     */
    private Settings $settings;

    /**
     * Logger instance
     */
    private Logger $logger;

    /**
     * Image handler
     */
    private ImageHandler $image_handler;

    /**
     * Constructor
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->logger = Logger::instance();
        $this->image_handler = new ImageHandler($settings);
    }

    /**
     * Create or update a simple product
     *
     * @param array $data Product data from feed
     * @return int|false Product ID or false on failure
     */
    public function sync_simple(array $data): int|false {
        $sku = $data['cod'] ?? '';

        if (empty($sku)) {
            $this->logger->error('Cannot sync product without SKU');
            return false;
        }

        // Check if product exists by SKU
        $existing_id = wc_get_product_id_by_sku($sku);

        if ($existing_id) {
            return $this->update_simple($existing_id, $data);
        }

        return $this->create_simple($data);
    }

    /**
     * Create a new simple product
     */
    private function create_simple(array $data): int|false {
        try {
            $product = new \WC_Product_Simple();

            $product->set_name($data['descr'] ?? 'Prodotto senza nome');
            $product->set_sku($data['cod']);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');

            // Description
            if (!empty($data['memo'])) {
                $product->set_description($data['memo']);
            }

            // Price
            $product->set_regular_price($data['prezzo']);
            if (!empty($data['sale_price'])) {
                $product->set_sale_price($data['sale_price']);
            }

            // Stock
            $product->set_manage_stock(true);
            $product->set_stock_quantity($data['stock'] ?? 0);
            $product->set_stock_status($data['stock'] > 0 ? 'instock' : 'outofstock');

            // Weight
            if (!empty($data['peso'])) {
                $product->set_weight($data['peso']);
            }

            // Save product
            $product_id = $product->save();

            if (!$product_id) {
                $this->logger->error("Failed to create product: {$data['cod']}");
                return false;
            }

            // Set categories
            $this->set_categories($product_id, $data);

            // Set brand
            $this->set_brand($product_id, $data);

            // Set images
            $this->image_handler->set_product_images($product_id, $data);

            $this->logger->success("Created simple product: {$data['cod']} (ID: $product_id)");

            return $product_id;

        } catch (\Exception $e) {
            $this->logger->error("Error creating product {$data['cod']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing simple product
     */
    private function update_simple(int $product_id, array $data): int|false {
        try {
            $product = wc_get_product($product_id);

            if (!$product) {
                $this->logger->error("Product not found for update: $product_id");
                return false;
            }

            // Update basic info
            $product->set_name($data['descr'] ?? $product->get_name());

            if (!empty($data['memo'])) {
                $product->set_description($data['memo']);
            }

            // Update price
            $product->set_regular_price($data['prezzo']);
            if (!empty($data['sale_price'])) {
                $product->set_sale_price($data['sale_price']);
            } else {
                $product->set_sale_price('');
            }

            // Update stock
            $product->set_stock_quantity($data['stock'] ?? 0);
            $product->set_stock_status($data['stock'] > 0 ? 'instock' : 'outofstock');

            // Weight
            if (!empty($data['peso'])) {
                $product->set_weight($data['peso']);
            }

            // Save
            $product->save();

            // Update categories
            $this->set_categories($product_id, $data);

            // Update brand
            $this->set_brand($product_id, $data);

            // Update images if enabled
            if ($this->settings->get('aggiorna_immagini', 0)) {
                $this->image_handler->set_product_images($product_id, $data);
            }

            $this->logger->info("Updated simple product: {$data['cod']} (ID: $product_id)");

            return $product_id;

        } catch (\Exception $e) {
            $this->logger->error("Error updating product {$data['cod']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create or update a variable product (parent only)
     *
     * @param array $data Product data from feed
     * @return int|false Product ID or false on failure
     */
    public function sync_variable(array $data): int|false {
        $sku = $data['cod'] ?? '';

        if (empty($sku)) {
            $this->logger->error('Cannot sync variable product without SKU');
            return false;
        }

        // For variable products, we use gruppov or cod as parent SKU
        $parent_sku = $data['gruppov'] ?? $sku;

        // Check if parent product exists
        $existing_id = wc_get_product_id_by_sku($parent_sku);

        if ($existing_id) {
            return $existing_id; // Parent exists, just return ID
        }

        return $this->create_variable($data, $parent_sku);
    }

    /**
     * Create a variable product (parent)
     */
    private function create_variable(array $data, string $parent_sku): int|false {
        try {
            $product = new \WC_Product_Variable();

            $product->set_name($data['descr'] ?? 'Prodotto senza nome');
            $product->set_sku($parent_sku);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');

            // Description
            if (!empty($data['memo'])) {
                $product->set_description($data['memo']);
            }

            // Weight
            if (!empty($data['peso'])) {
                $product->set_weight($data['peso']);
            }

            // Save product
            $product_id = $product->save();

            if (!$product_id) {
                $this->logger->error("Failed to create variable product: $parent_sku");
                return false;
            }

            // Set categories
            $this->set_categories($product_id, $data);

            // Set brand
            $this->set_brand($product_id, $data);

            // Set images
            $this->image_handler->set_product_images($product_id, $data);

            $this->logger->success("Created variable product: $parent_sku (ID: $product_id)");

            return $product_id;

        } catch (\Exception $e) {
            $this->logger->error("Error creating variable product $parent_sku: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set product categories
     */
    private function set_categories(int $product_id, array $data): void {
        if (!$this->settings->get('sincronizza_categorie', 1)) {
            return;
        }

        if (empty($data['categories'])) {
            return;
        }

        $category_ids = [];
        $parent_id = 0;

        // Create hierarchical categories
        foreach ($data['categories'] as $category_name) {
            $category_name = trim($category_name);
            if (empty($category_name)) {
                continue;
            }

            $term = get_term_by('name', $category_name, 'product_cat');

            if (!$term) {
                // Create category
                $result = wp_insert_term($category_name, 'product_cat', [
                    'parent' => $parent_id,
                ]);

                if (!is_wp_error($result)) {
                    $parent_id = $result['term_id'];
                    $category_ids[] = $parent_id;
                }
            } else {
                $parent_id = $term->term_id;
                $category_ids[] = $parent_id;
            }
        }

        if (!empty($category_ids)) {
            wp_set_object_terms($product_id, $category_ids, 'product_cat');
        }
    }

    /**
     * Set product brand (requires Perfect Brands for WooCommerce)
     */
    private function set_brand(int $product_id, array $data): void {
        if (empty($data['marca'])) {
            return;
        }

        // Check if Perfect Brands taxonomy exists
        if (!taxonomy_exists('pwb-brand')) {
            return;
        }

        $brand_name = $data['marca'];

        // Get or create brand term
        $term = get_term_by('name', $brand_name, 'pwb-brand');

        if (!$term) {
            $result = wp_insert_term($brand_name, 'pwb-brand');
            if (!is_wp_error($result)) {
                $term_id = $result['term_id'];
            } else {
                return;
            }
        } else {
            $term_id = $term->term_id;
        }

        wp_set_object_terms($product_id, [$term_id], 'pwb-brand');
    }

    /**
     * Check if product exists by SKU
     */
    public function product_exists(string $sku): bool {
        return wc_get_product_id_by_sku($sku) > 0;
    }

    /**
     * Get product ID by SKU
     */
    public function get_product_id(string $sku): int {
        return wc_get_product_id_by_sku($sku) ?: 0;
    }
}
