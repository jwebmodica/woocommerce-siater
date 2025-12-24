<?php
/**
 * License Manager
 *
 * Handles license verification, activation, and deactivation
 * Uses a smarter caching system with transients
 */

namespace Siater\License;

defined('ABSPATH') || exit;

class LicenseManager {

    /**
     * Product ID for license server
     */
    private string $product_id = '4015B1E5';

    /**
     * License API URL
     */
    private string $api_url = 'http://licenza.jwebmodica.com/';

    /**
     * API Key
     */
    private string $api_key = '53A6D2D1B9DD5592B06E';

    /**
     * Current version
     */
    private string $version = '3.0.0';

    /**
     * License file path
     */
    private string $license_file;

    /**
     * Cache transient key
     */
    private string $cache_key = 'siater_license_status';

    /**
     * Cache duration in seconds (12 hours)
     */
    private int $cache_duration = 43200;

    /**
     * Constructor
     */
    public function __construct() {
        $this->license_file = WP_CONTENT_DIR . '/siater.lic';
    }

    /**
     * Check if license is valid
     */
    public function is_valid(): bool {
        // Check cache first
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached === 'valid';
        }

        // Verify with server
        $result = $this->verify();

        // Cache result
        set_transient($this->cache_key, $result['valid'] ? 'valid' : 'invalid', $this->cache_duration);

        return $result['valid'];
    }

    /**
     * Check if license file exists
     */
    public function has_license_file(): bool {
        return file_exists($this->license_file);
    }

    /**
     * Get license data from file
     */
    public function get_license_data(): ?array {
        if (!$this->has_license_file()) {
            return null;
        }

        $content = @file_get_contents($this->license_file);
        if (!$content) {
            return null;
        }

        $data = @json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Activate license
     */
    public function activate(string $license_code, string $client_name): array {
        $response = $this->api_request('activate_license', [
            'license_code' => $license_code,
            'client_name' => $client_name,
        ]);

        if ($response['status']) {
            // Save license file
            $license_data = [
                'license_code' => $license_code,
                'client_name' => $client_name,
                'activated_at' => time(),
                'lic_response' => $response['lic_response'] ?? '',
            ];

            file_put_contents($this->license_file, json_encode($license_data));

            // Clear cache
            delete_transient($this->cache_key);

            return [
                'success' => true,
                'message' => $response['message'] ?? __('Licenza attivata con successo', 'siater'),
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? __('Errore durante l\'attivazione', 'siater'),
        ];
    }

    /**
     * Verify license
     */
    public function verify(): array {
        $license_data = $this->get_license_data();

        if (!$license_data) {
            return [
                'valid' => false,
                'message' => __('Nessuna licenza trovata', 'siater'),
            ];
        }

        $response = $this->api_request('verify_license', [
            'license_code' => $license_data['license_code'] ?? '',
            'client_name' => $license_data['client_name'] ?? '',
            'lic_response' => $license_data['lic_response'] ?? '',
        ]);

        return [
            'valid' => $response['status'] ?? false,
            'message' => $response['message'] ?? '',
        ];
    }

    /**
     * Deactivate license
     */
    public function deactivate(): array {
        $license_data = $this->get_license_data();

        if (!$license_data) {
            return [
                'success' => false,
                'message' => __('Nessuna licenza da disattivare', 'siater'),
            ];
        }

        $response = $this->api_request('deactivate_license', [
            'license_code' => $license_data['license_code'] ?? '',
            'client_name' => $license_data['client_name'] ?? '',
            'lic_response' => $license_data['lic_response'] ?? '',
        ]);

        if ($response['status']) {
            // Remove license file
            @unlink($this->license_file);

            // Clear cache
            delete_transient($this->cache_key);

            return [
                'success' => true,
                'message' => $response['message'] ?? __('Licenza disattivata', 'siater'),
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? __('Errore durante la disattivazione', 'siater'),
        ];
    }

    /**
     * Check for plugin updates
     */
    public function check_update(): array {
        $response = $this->api_request('check_update', []);

        return [
            'update_available' => $response['status'] ?? false,
            'new_version' => $response['version'] ?? '',
            'message' => $response['message'] ?? '',
        ];
    }

    /**
     * Make API request
     */
    private function api_request(string $endpoint, array $data): array {
        $url = rtrim($this->api_url, '/') . '/api/' . $endpoint;

        $body = array_merge($data, [
            'product_id' => $this->product_id,
            'api_key' => $this->api_key,
            'current_version' => $this->version,
            'domain' => $this->get_domain(),
            'ip' => $this->get_ip(),
        ]);

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'body' => $body,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return [
                'status' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = @json_decode($body, true);

        return is_array($decoded) ? $decoded : [
            'status' => false,
            'message' => __('Risposta non valida dal server', 'siater'),
        ];
    }

    /**
     * Get current domain
     */
    private function get_domain(): string {
        $url = home_url();
        $parsed = parse_url($url);
        return $parsed['host'] ?? '';
    }

    /**
     * Get server IP
     */
    private function get_ip(): string {
        return $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? gethostbyname(gethostname());
    }

    /**
     * Clear license cache
     */
    public function clear_cache(): void {
        delete_transient($this->cache_key);
    }
}
