<?php
/**
 * Settings Manager
 *
 * Handles all plugin settings - COMPATIBLE with original table structure
 * Uses wp_siater_settings with id=777 row format
 */

namespace Siater2026\Core;

defined('ABSPATH') || exit;

class Settings {

    /**
     * Table name
     */
    private string $table;

    /**
     * Cached settings row
     */
    private ?object $cache = null;

    /**
     * Column definitions for the settings table
     */
    private array $columns = [
        'url' => '',
        'licenza' => '',
        'rand_code' => '',
        'num_record' => 300,
        'listino' => 1,
        'iva' => 0,
        'tagliecolori' => 0,
        'sincronizza_categorie' => 1,
        'arrotonda_prezzo' => 0,
        'aggiorna_immagini' => 0,
        'tipo_esistenza' => 'esfisica',
        'gestione_prodotti_esistenti' => 0,
        'esporta_ordini' => 0,
        'applica_sconto' => 0,
        'dev_use_ssl' => 1,
        'normalizza_brand' => 0,
        'aggiungi_iva' => 0,
        'importa_immagini_varianti' => 0,
        'solo_prodotti_con_foto_varianti' => 0,
        'debug_enabled' => 0,
        'verbose_output' => 0,
        // Cron options
        'cron_mode' => 'wordpress',        // 'wordpress' or 'manual'
        'sync_interval' => 900,            // seconds: 180, 300, 600, 900 (3, 5, 10, 15 min)
        'export_interval' => 1800,         // seconds: 1800, 3600 (30 min, 1 hour)
        'cleanup_interval' => 86400,       // seconds: 86400, 259200, 604800 (1 day, 3 days, 1 week)
    ];

    /**
     * Available intervals for sync
     */
    public const SYNC_INTERVALS = [
        180 => '3 minuti',
        300 => '5 minuti',
        600 => '10 minuti',
        900 => '15 minuti',
    ];

    /**
     * Available intervals for export
     */
    public const EXPORT_INTERVALS = [
        1800 => '30 minuti',
        3600 => '1 ora',
    ];

    /**
     * Available intervals for cleanup
     */
    public const CLEANUP_INTERVALS = [
        86400 => '1 giorno',
        259200 => '3 giorni',
        604800 => '1 settimana',
    ];

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'siater_settings';
        $this->load();
    }

    /**
     * Load settings from database
     */
    private function load(): void {
        global $wpdb;
        $this->cache = $wpdb->get_row("SELECT * FROM {$this->table} WHERE id = 777");
    }

    /**
     * Get a setting value
     */
    public function get(string $key, $default = null) {
        // Handle renamed columns for compatibility
        $key = $this->normalize_key($key);

        if ($this->cache && isset($this->cache->$key)) {
            return $this->cache->$key;
        }

        return $default ?? ($this->columns[$key] ?? null);
    }

    /**
     * Normalize key names (map new names to old column names)
     */
    private function normalize_key(string $key): string {
        $map = [
            'use_ssl' => 'dev_use_ssl',
            'auth_key' => 'rand_code',
        ];

        return $map[$key] ?? $key;
    }

    /**
     * Set a setting value
     */
    public function set(string $key, $value): bool {
        global $wpdb;

        $key = $this->normalize_key($key);

        $result = $wpdb->update(
            $this->table,
            [$key => $value],
            ['id' => 777],
            is_numeric($value) ? ['%d'] : ['%s'],
            ['%d']
        );

        if ($result !== false) {
            if ($this->cache) {
                $this->cache->$key = $value;
            }
            return true;
        }

        return false;
    }

    /**
     * Get all settings as array
     */
    public function all(): array {
        if (!$this->cache) {
            return $this->columns;
        }

        return (array) $this->cache;
    }

    /**
     * Save multiple settings at once
     */
    public function save_many(array $settings): bool {
        global $wpdb;

        $data = [];
        $format = [];

        foreach ($settings as $key => $value) {
            $key = $this->normalize_key($key);
            $data[$key] = $value;
            $format[] = is_numeric($value) ? '%d' : '%s';
        }

        $result = $wpdb->update(
            $this->table,
            $data,
            ['id' => 777],
            $format,
            ['%d']
        );

        if ($result !== false) {
            // Refresh cache
            $this->load();
            return true;
        }

        return false;
    }

    /**
     * Get the SIA feed URL with parameters
     */
    public function get_feed_url(int $offset = 0): string {
        $base_url = $this->get('url', '');
        if (empty($base_url)) {
            return '';
        }

        $protocol = $this->get('dev_use_ssl', 1) ? 'https' : 'http';
        $base_url = preg_replace('#^https?://#', '', $base_url);
        $base_url = rtrim($base_url, '/');

        // Date range: 5000 days back to tomorrow
        $from_date = date('d_m_Y', strtotime('-5000 days'));
        $to_date = date('d_m_Y', strtotime('+1 day'));

        $params = [
            'Command' => 'GetArt',
            'FromData' => $from_date,
            'ToData' => $to_date,
            'StartRecords' => $offset,
            'MaxRecords' => $this->get('num_record', 300),
            'WithMemo' => 'Yes',
            'PrezzoListinoX' => $this->get('listino', 1),
            'WithEsistenze' => 'Yes',
            'WithSubImg' => 'Yes',
            'PrezzoListinoIvaCompresa' => $this->get('iva', 0) ? 'Yes' : 'No',
            'WithLotti' => $this->get('tagliecolori', 0) ? 'Yes' : 'No',
            'WithFotoVar' => $this->get('importa_immagini_varianti', 0) ? 'Yes' : 'No',
        ];

        return sprintf(
            '%s://www.%s/Rss.aspx?%s',
            $protocol,
            $base_url,
            http_build_query($params)
        );
    }

    /**
     * Get feed URL for SKU-only fetch (for product cleanup)
     */
    public function get_sku_feed_url(int $offset = 0, int $batch_size = 500): string {
        $base_url = $this->get('url', '');
        if (empty($base_url)) {
            return '';
        }

        $protocol = $this->get('dev_use_ssl', 1) ? 'https' : 'http';
        $base_url = preg_replace('#^https?://#', '', $base_url);
        $base_url = rtrim($base_url, '/');

        $params = [
            'Command' => 'GetArt',
            'StartRecords' => $offset,
            'MaxRecords' => $batch_size,
            'WithMemo' => 'Yes',
            'PrezzoListinoX' => $this->get('listino', 1),
            'WithEsistenze' => 'Yes',
        ];

        return sprintf(
            '%s://www.%s/Rss.aspx?%s',
            $protocol,
            $base_url,
            http_build_query($params)
        );
    }

    /**
     * Get export file path
     */
    public function get_export_path(): string {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/siater-exports';

        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        return $export_dir . '/WEB_02_ORDINI.csv';
    }

    /**
     * Refresh settings from database
     */
    public function refresh(): void {
        $this->load();
    }
}
