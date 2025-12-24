<?php
/**
 * Variation Handler
 *
 * Handles creating and updating product variations (size/color combinations)
 */

namespace Siater2026\Product;

defined('ABSPATH') || exit;

use Siater2026\Core\Settings;
use Siater2026\Utils\Logger;

class VariationHandler {

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
     * Sync a variation for a variable product
     *
     * @param int $parent_id Parent product ID
     * @param array $data Variation data from feed
     * @return int|false Variation ID or false on failure
     */
    public function sync(int $parent_id, array $data): int|false {
        $size = $data['variante1'] ?? '';
        $color = $data['variante2'] ?? '';

        // At least one attribute is required
        if (empty($size) && empty($color)) {
            $this->logger->warning("Variation without attributes for product $parent_id");
            return false;
        }

        // Ensure attributes exist on parent product
        $this->ensure_parent_attributes($parent_id, $size, $color);

        // Build variation attributes
        $attributes = [];
        if (!empty($size)) {
            $size_slug = $this->get_attribute_term_slug('pa_size', $size);
            $attributes['pa_size'] = $size_slug;
        }
        if (!empty($color)) {
            $color_slug = $this->get_attribute_term_slug('pa_color', $color);
            $attributes['pa_color'] = $color_slug;
        }

        // Check if variation exists
        $existing_id = $this->find_variation($parent_id, $attributes);

        if ($existing_id) {
            return $this->update_variation($existing_id, $data);
        }

        return $this->create_variation($parent_id, $attributes, $data);
    }

    /**
     * Ensure attributes exist on parent product
     */
    private function ensure_parent_attributes(int $parent_id, string $size, string $color): void {
        $product = wc_get_product($parent_id);
        if (!$product || !$product->is_type('variable')) {
            return;
        }

        $existing_attrs = $product->get_attributes();
        $updated = false;

        // Handle size attribute
        if (!empty($size)) {
            $this->ensure_attribute_taxonomy('pa_size', 'Taglia');
            $this->ensure_attribute_term('pa_size', $size);

            if (!isset($existing_attrs['pa_size'])) {
                $attr = new \WC_Product_Attribute();
                $attr->set_id(wc_attribute_taxonomy_id_by_name('pa_size'));
                $attr->set_name('pa_size');
                $attr->set_options([]);
                $attr->set_position(0);
                $attr->set_visible(true);
                $attr->set_variation(true);
                $existing_attrs['pa_size'] = $attr;
                $updated = true;
            }

            // Add term to attribute options
            $current_options = $existing_attrs['pa_size']->get_options();
            $term = get_term_by('name', $size, 'pa_size');
            if ($term && !in_array($term->term_id, $current_options)) {
                $current_options[] = $term->term_id;
                $existing_attrs['pa_size']->set_options($current_options);
                $updated = true;
            }
        }

        // Handle color attribute
        if (!empty($color)) {
            $this->ensure_attribute_taxonomy('pa_color', 'Colore');
            $this->ensure_attribute_term('pa_color', $color);

            if (!isset($existing_attrs['pa_color'])) {
                $attr = new \WC_Product_Attribute();
                $attr->set_id(wc_attribute_taxonomy_id_by_name('pa_color'));
                $attr->set_name('pa_color');
                $attr->set_options([]);
                $attr->set_position(1);
                $attr->set_visible(true);
                $attr->set_variation(true);
                $existing_attrs['pa_color'] = $attr;
                $updated = true;
            }

            // Add term to attribute options
            $current_options = $existing_attrs['pa_color']->get_options();
            $term = get_term_by('name', $color, 'pa_color');
            if ($term && !in_array($term->term_id, $current_options)) {
                $current_options[] = $term->term_id;
                $existing_attrs['pa_color']->set_options($current_options);
                $updated = true;
            }
        }

        if ($updated) {
            $product->set_attributes($existing_attrs);
            $product->save();
        }
    }

    /**
     * Ensure attribute taxonomy exists
     */
    private function ensure_attribute_taxonomy(string $taxonomy, string $label): void {
        // Check if taxonomy exists
        if (taxonomy_exists($taxonomy)) {
            return;
        }

        // Get attribute slug (remove pa_ prefix)
        $slug = str_replace('pa_', '', $taxonomy);

        // Check if attribute exists in woocommerce_attribute_taxonomies
        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
                $slug
            )
        );

        if (!$exists) {
            // Create attribute
            wc_create_attribute([
                'name' => $label,
                'slug' => $slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);

            // Register taxonomy
            register_taxonomy($taxonomy, 'product', [
                'hierarchical' => false,
                'labels' => [
                    'name' => $label,
                ],
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => ['slug' => $slug],
            ]);
        }
    }

    /**
     * Ensure attribute term exists
     */
    private function ensure_attribute_term(string $taxonomy, string $term_name): void {
        if (!term_exists($term_name, $taxonomy)) {
            wp_insert_term($term_name, $taxonomy);
        }
    }

    /**
     * Get attribute term slug
     */
    private function get_attribute_term_slug(string $taxonomy, string $term_name): string {
        $term = get_term_by('name', $term_name, $taxonomy);
        return $term ? $term->slug : sanitize_title($term_name);
    }

    /**
     * Find existing variation by attributes
     */
    private function find_variation(int $parent_id, array $attributes): int {
        $data_store = \WC_Data_Store::load('product');
        return $data_store->find_matching_product_variation(
            wc_get_product($parent_id),
            $attributes
        );
    }

    /**
     * Create a new variation
     */
    private function create_variation(int $parent_id, array $attributes, array $data): int|false {
        try {
            $variation = new \WC_Product_Variation();

            $variation->set_parent_id($parent_id);
            $variation->set_attributes($attributes);
            $variation->set_status('publish');

            // SKU for variation (unique combination)
            $var_sku = $data['cod'] . '-' . implode('-', array_values($attributes));
            $variation->set_sku($var_sku);

            // Price
            $variation->set_regular_price($data['prezzo']);
            if (!empty($data['sale_price'])) {
                $variation->set_sale_price($data['sale_price']);
            }

            // Stock
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($data['stock'] ?? 0);
            $variation->set_stock_status($data['stock'] > 0 ? 'instock' : 'outofstock');

            // Weight
            if (!empty($data['peso'])) {
                $variation->set_weight($data['peso']);
            }

            // Save variation
            $variation_id = $variation->save();

            if (!$variation_id) {
                $this->logger->error("Failed to create variation for product $parent_id");
                return false;
            }

            // Set variation image if enabled
            if ($this->settings->get('importa_immagini_varianti', 0)) {
                $this->image_handler->set_variation_image($variation_id, $data);
            }

            $this->logger->success("Created variation $variation_id for product $parent_id");

            return $variation_id;

        } catch (\Exception $e) {
            $this->logger->error("Error creating variation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing variation
     */
    private function update_variation(int $variation_id, array $data): int|false {
        try {
            $variation = wc_get_product($variation_id);

            if (!$variation || !$variation->is_type('variation')) {
                $this->logger->error("Variation not found: $variation_id");
                return false;
            }

            // Update price
            $variation->set_regular_price($data['prezzo']);
            if (!empty($data['sale_price'])) {
                $variation->set_sale_price($data['sale_price']);
            } else {
                $variation->set_sale_price('');
            }

            // Update stock
            $variation->set_stock_quantity($data['stock'] ?? 0);
            $variation->set_stock_status($data['stock'] > 0 ? 'instock' : 'outofstock');

            // Weight
            if (!empty($data['peso'])) {
                $variation->set_weight($data['peso']);
            }

            // Save variation
            $variation->save();

            // Update variation image if enabled
            if ($this->settings->get('importa_immagini_varianti', 0) && $this->settings->get('aggiorna_immagini', 0)) {
                $this->image_handler->set_variation_image($variation_id, $data);
            }

            $this->logger->info("Updated variation $variation_id");

            return $variation_id;

        } catch (\Exception $e) {
            $this->logger->error("Error updating variation $variation_id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync parent product stock after all variations are synced
     */
    public function sync_parent_stock(int $parent_id): void {
        $product = wc_get_product($parent_id);

        if (!$product || !$product->is_type('variable')) {
            return;
        }

        // Variable products don't manage their own stock
        // Stock status is determined by children
        \WC_Product_Variable::sync($parent_id);

        // Clear transients
        wc_delete_product_transients($parent_id);
    }
}
