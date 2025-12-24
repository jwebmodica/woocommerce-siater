<?php
/**
 * Feed Parser
 *
 * Parses the SIA RSS feed and extracts product data
 */

namespace Siater2026\Sync;

defined('ABSPATH') || exit;

use Siater2026\Core\Settings;
use Siater2026\Utils\Logger;

class FeedParser {

    /**
     * Settings instance
     */
    private Settings $settings;

    /**
     * Logger instance
     */
    private Logger $logger;

    /**
     * Field delimiter between values
     */
    private const FIELD_DELIMITER = '{|}';

    /**
     * Record delimiter between products
     */
    private const RECORD_DELIMITER = '{||}';

    /**
     * Field map for simple products (no variations)
     */
    private const SIMPLE_FIELDS = [
        'cod', 'foto', 'subfoto1', 'subfoto2', 'subfoto3', 'subfoto4',
        'id', 'descr', 'sku', 'class', 'fascia', 'marca', 'memo',
        'prezzo', 'sconto', 'sconto2', 'peso', 'escludi',
        'esfisica', 'esreale', 'esteorica'
    ];

    /**
     * Field map for variable products (with variations, no var images)
     */
    private const VARIABLE_FIELDS = [
        'cod', 'foto', 'subfoto1', 'subfoto2', 'subfoto3', 'subfoto4',
        'id', 'descr', 'sku', 'class', 'fascia', 'marca', 'memo',
        'prezzo', 'sconto', 'sconto2', 'peso', 'escludi', 'lotti',
        'variante1', 'variante2', 'variante3',
        'esfisica', 'esreale', 'esteorica', 'gruppov', 'nsrif'
    ];

    /**
     * Field map for variable products with variation images
     */
    private const VARIABLE_FIELDS_WITH_IMAGES = [
        'cod', 'foto', 'subfoto1', 'subfoto2', 'subfoto3', 'subfoto4',
        'varimagefoto1', 'varimagefoto2', 'varimagefoto3', 'varimagefoto4',
        'varimagefoto5', 'varimagefoto6', 'varimagefoto7', 'varimagefoto8',
        'id', 'descr', 'sku', 'class', 'fascia', 'marca', 'memo',
        'prezzo', 'sconto', 'sconto2', 'peso', 'escludi', 'lotti',
        'variante1', 'variante2', 'variante3',
        'esfisica', 'esreale', 'esteorica', 'gruppov', 'nsrif'
    ];

    /**
     * Constructor
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->logger = Logger::instance();
    }

    /**
     * Fetch and parse feed
     *
     * @param int $offset Current offset for pagination
     * @return array Array of parsed products
     */
    public function fetch(int $offset = 0): array {
        $url = $this->settings->get_feed_url($offset);

        if (empty($url)) {
            $this->logger->error('Feed URL is empty - check settings');
            return [];
        }

        $this->logger->info("Fetching feed: $url");

        // Fetch with cURL for better timeout control
        $response = $this->curl_fetch($url);

        if ($response === false) {
            $this->logger->error('Failed to fetch feed');
            return [];
        }

        return $this->parse($response);
    }

    /**
     * Fetch URL using cURL
     */
    private function curl_fetch(string $url): ?string {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: it-IT,it;q=0.9,en;q=0.8',
            ],
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            $this->logger->error("cURL error: $error");
            return null;
        }

        if ($http_code !== 200) {
            $this->logger->error("HTTP error: $http_code");
            return null;
        }

        return $response;
    }

    /**
     * Parse feed content into product array
     */
    public function parse(string $content): array {
        $products = [];

        // Split by record delimiter
        $records = explode(self::RECORD_DELIMITER, $content);

        if (empty($records)) {
            $this->logger->warning('No records found in feed');
            return [];
        }

        $this->logger->info('Found ' . count($records) . ' records in feed');

        // Determine field map based on settings
        $field_map = $this->get_field_map();

        foreach ($records as $record) {
            $record = trim($record);

            if (empty($record)) {
                continue;
            }

            $product = $this->parse_record($record, $field_map);

            if ($product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Get field map based on current settings
     */
    private function get_field_map(): array {
        $has_variations = $this->settings->get('tagliecolori', 0);
        $has_var_images = $this->settings->get('importa_immagini_varianti', 0);

        if (!$has_variations) {
            return self::SIMPLE_FIELDS;
        }

        if ($has_var_images) {
            return self::VARIABLE_FIELDS_WITH_IMAGES;
        }

        return self::VARIABLE_FIELDS;
    }

    /**
     * Parse a single record into product data
     */
    private function parse_record(string $record, array $field_map): ?array {
        $fields = explode(self::FIELD_DELIMITER, $record);

        // Validate field count
        if (count($fields) < count($field_map)) {
            // Try to detect the correct field count
            $this->logger->debug('Field count mismatch: got ' . count($fields) . ', expected ' . count($field_map));
            return null;
        }

        $product = [];

        foreach ($field_map as $index => $field_name) {
            $value = isset($fields[$index]) ? trim($fields[$index]) : '';
            $product[$field_name] = $this->sanitize_field($field_name, $value);
        }

        // Skip excluded products
        if (!empty($product['escludi']) && $product['escludi'] == 1) {
            return null;
        }

        // Skip products without code
        if (empty($product['cod'])) {
            return null;
        }

        // Process computed fields
        $product = $this->process_computed_fields($product);

        return $product;
    }

    /**
     * Sanitize a field value based on field type
     */
    private function sanitize_field(string $field_name, string $value): mixed {
        // Price fields - convert comma to dot
        if (in_array($field_name, ['prezzo', 'sconto', 'sconto2', 'peso', 'esfisica', 'esreale', 'esteorica'])) {
            $value = str_replace(',', '.', $value);
            return floatval($value);
        }

        // Integer fields
        if (in_array($field_name, ['id', 'escludi', 'lotti'])) {
            return intval($value);
        }

        // Text fields - decode HTML entities and strip tags
        if (in_array($field_name, ['descr', 'memo'])) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Keep basic HTML for memo, strip for descr
            if ($field_name === 'descr') {
                $value = wp_strip_all_tags($value);
            } else {
                $value = wp_kses_post($value);
            }
        }

        return $value;
    }

    /**
     * Process computed fields
     */
    private function process_computed_fields(array $product): array {
        // Get the correct stock type
        $stock_type = $this->settings->get('tipo_esistenza', 'esfisica');
        $product['stock'] = $product[$stock_type] ?? $product['esfisica'] ?? 0;

        // Apply VAT if needed
        if ($this->settings->get('aggiungi_iva', 0) && $product['prezzo'] > 0) {
            $product['prezzo'] = $product['prezzo'] * 1.22;
        }

        // Apply price rounding
        $rounding = $this->settings->get('arrotonda_prezzo', 0);
        if ($rounding > 0 && $product['prezzo'] > 0) {
            $product['prezzo'] = $this->round_price($product['prezzo'], $rounding);
        }

        // Calculate sale price if discount enabled
        if ($this->settings->get('applica_sconto', 0) && $product['sconto'] > 0) {
            $sale_price = $product['prezzo'] * (1 - ($product['sconto'] / 100));
            if ($rounding > 0) {
                $sale_price = $this->round_price($sale_price, $rounding);
            }
            $product['sale_price'] = $sale_price;
        }

        // Parse category hierarchy
        if (!empty($product['class'])) {
            $product['categories'] = array_filter(array_map('trim', explode('\\', $product['class'])));
        }

        // Normalize brand if needed
        if (!empty($product['marca']) && $this->settings->get('normalizza_brand', 0)) {
            $parts = explode('/', $product['marca']);
            $product['marca'] = trim($parts[0]);
        }

        // Collect gallery images
        $product['gallery'] = array_filter([
            $product['subfoto1'] ?? '',
            $product['subfoto2'] ?? '',
            $product['subfoto3'] ?? '',
            $product['subfoto4'] ?? '',
        ]);

        // Collect variation images
        $product['variation_images'] = array_filter([
            $product['varimagefoto1'] ?? '',
            $product['varimagefoto2'] ?? '',
            $product['varimagefoto3'] ?? '',
            $product['varimagefoto4'] ?? '',
            $product['varimagefoto5'] ?? '',
            $product['varimagefoto6'] ?? '',
            $product['varimagefoto7'] ?? '',
            $product['varimagefoto8'] ?? '',
        ]);

        // Determine if this is a variation row
        $product['is_variation'] = isset($product['lotti']) && $product['lotti'] == -1;

        // Check if product has usable variation images (skip placeholder)
        $has_var_images = false;
        foreach ($product['variation_images'] as $img) {
            if ($img && strpos($img, 'img_non_disponibile') === false) {
                $has_var_images = true;
                break;
            }
        }
        $product['has_variation_images'] = $has_var_images;

        return $product;
    }

    /**
     * Apply price rounding
     *
     * @param float $price Price to round
     * @param int $type 0=none, 1=ceil, 2=decimal, 3=50cent
     */
    private function round_price(float $price, int $type): float {
        switch ($type) {
            case 1: // Ceil to integer
                return ceil($price);

            case 2: // Ceil to 2 decimals
                return ceil($price * 100) / 100;

            case 3: // Round to nearest 50 cents
                return round($price * 2) / 2;

            default:
                return $price;
        }
    }
}
