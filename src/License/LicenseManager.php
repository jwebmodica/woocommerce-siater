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

        // Build JSON body according to LicenseBox API spec
        $body = array_merge([
            'product_id' => $this->product_id,
        ], $data);

        // Only activate_license needs verify_type
        if ($endpoint === 'activate_license') {
            $body['verify_type'] = 'non_envato';
        }

        $request_args = [
            'timeout' => 30,
            'headers' => [
                'LB-API-KEY' => $this->api_key,
                'LB-URL' => home_url(),
                'LB-IP' => $this->get_ip(),
                'LB-LANG' => 'english',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'sslverify' => false,
        ];

        // Debug logging
        $this->debug_log('API Request', [
            'url' => $url,
            'headers' => $request_args['headers'],
            'body' => $body,
        ]);

        $response = wp_remote_post($url, $request_args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->debug_log('API Error (WP_Error)', ['message' => $error_message]);
            return [
                'status' => false,
                'message' => $error_message,
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $this->debug_log('API Response', [
            'code' => $response_code,
            'body' => $response_body,
        ]);

        $decoded = @json_decode($response_body, true);

        if (!is_array($decoded)) {
            $this->debug_log('API Error (Invalid JSON)', ['raw_body' => $response_body]);
            return [
                'status' => false,
                'message' => __('Risposta non valida dal server', 'siater') . ' (HTTP ' . $response_code . ')',
            ];
        }

        return $decoded;
    }

    /**
     * Debug logging for license operations
     */
    private function debug_log(string $label, array $data): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_file = WP_CONTENT_DIR . '/siater-license-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$label}:\n" . print_r($data, true) . "\n\n";

        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
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
