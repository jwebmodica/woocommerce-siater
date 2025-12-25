<?php
/**
 * Plugin Name: Siater Connector
 * Plugin URI: https://www.sicilwareinformatica.it
 * Description: Sincronizza prodotti tra WooCommerce e il gestionale SIA (Sicilware Informatica). Importa prodotti semplici e variabili, gestisce taglie/colori, sincronizza prezzi e giacenze.
 * Version: 3.0.1
 * Author: Sicilware Informatica
 * Author URI: https://www.sicilwareinformatica.it
 * Text Domain: siater
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

// Plugin constants
define('SIATER_VERSION', '3.0.1');
define('SIATER_PLUGIN_FILE', __FILE__);
define('SIATER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIATER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SIATER_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'Siater\\';
    $base_dir = SIATER_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Cron hook names
 */
define('SIATER_CRON_SYNC', 'siater_cron_sync');
define('SIATER_CRON_EXPORT', 'siater_cron_export');
define('SIATER_CRON_CLEANUP', 'siater_cron_cleanup');

/**
 * Main Plugin Class
 */
final class Siater {

    /**
     * Plugin instance
     */
    private static ?Siater $instance = null;

    /**
     * License manager
     */
    public \Siater\License\LicenseManager $license;

    /**
     * Settings manager
     */
    public \Siater\Core\Settings $settings;

    /**
     * Admin handler
     */
    public ?\Siater\Admin\AdminHandler $admin = null;

    /**
     * GitHub updater
     */
    public ?\Siater\Core\GitHubUpdater $updater = null;

    /**
     * Get plugin instance
     */
    public static function instance(): Siater {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_requirements();
        $this->init();
    }

    /**
     * Check plugin requirements
     */
    private function check_requirements(): void {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo esc_html__('Siater richiede PHP 7.4 o superiore.', 'siater');
                echo '</p></div>';
            });
            return;
        }
    }

    /**
     * Initialize plugin
     */
    private function init(): void {
        // Ensure database columns exist (upgrade path)
        $this->ensure_db_columns();

        // Initialize core components
        $this->license = new \Siater\License\LicenseManager();
        $this->settings = new \Siater\Core\Settings();

        // Admin hooks
        if (is_admin()) {
            $this->admin = new \Siater\Admin\AdminHandler();
        }

        // GitHub updater
        $this->updater = new \Siater\Core\GitHubUpdater('jwebmodica/woocommerce-siater');

        // Load text domain
        add_action('init', [$this, 'load_textdomain']);

        // Register API endpoints (for backward compatibility with manual cron)
        add_action('init', [$this, 'register_endpoints']);

        // Register cron hooks
        add_action(SIATER_CRON_SYNC, [$this, 'run_cron_sync']);
        add_action(SIATER_CRON_EXPORT, [$this, 'run_cron_export']);
        add_action(SIATER_CRON_CLEANUP, [$this, 'run_cron_cleanup']);

        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
    }

    /**
     * Ensure all required database columns exist
     * This handles upgrades without requiring plugin reactivation
     */
    private function ensure_db_columns(): void {
        global $wpdb;

        // Only run once per request and only in admin
        static $checked = false;
        if ($checked || !is_admin()) {
            return;
        }
        $checked = true;

        $settings_table = $wpdb->prefix . 'siater_settings';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$settings_table'") !== $settings_table) {
            return;
        }

        // Get current columns
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $settings_table");
        $column_names = array_map(function($col) { return $col->Field; }, $columns);

        // Required columns for cron functionality
        $required_columns = [
            'cron_mode' => "VARCHAR(20) DEFAULT 'wordpress'",
            'sync_interval' => "INT DEFAULT 900",
            'export_interval' => "INT DEFAULT 1800",
            'cleanup_interval' => "INT DEFAULT 86400",
            'debug_enabled' => "MEDIUMINT(9) DEFAULT 0",
            'verbose_output' => "MEDIUMINT(9) DEFAULT 0",
            'importa_immagini_varianti' => "MEDIUMINT(9) DEFAULT 0",
            'solo_prodotti_con_foto_varianti' => "MEDIUMINT(9) DEFAULT 0",
        ];

        foreach ($required_columns as $column => $definition) {
            if (!in_array($column, $column_names)) {
                $wpdb->query("ALTER TABLE $settings_table ADD COLUMN $column $definition");
            }
        }
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules(array $schedules): array {
        // Sync intervals
        $schedules['siater_every_1_min'] = [
            'interval' => 60,
            'display' => __('Ogni minuto', 'siater'),
        ];
        $schedules['siater_every_2_min'] = [
            'interval' => 120,
            'display' => __('Ogni 2 minuti', 'siater'),
        ];
        $schedules['siater_every_3_min'] = [
            'interval' => 180,
            'display' => __('Ogni 3 minuti', 'siater'),
        ];
        $schedules['siater_every_5_min'] = [
            'interval' => 300,
            'display' => __('Ogni 5 minuti', 'siater'),
        ];
        $schedules['siater_every_10_min'] = [
            'interval' => 600,
            'display' => __('Ogni 10 minuti', 'siater'),
        ];
        $schedules['siater_every_15_min'] = [
            'interval' => 900,
            'display' => __('Ogni 15 minuti', 'siater'),
        ];
        // Export intervals
        $schedules['siater_every_30_min'] = [
            'interval' => 1800,
            'display' => __('Ogni 30 minuti', 'siater'),
        ];
        $schedules['siater_every_1_hour'] = [
            'interval' => 3600,
            'display' => __('Ogni ora', 'siater'),
        ];
        // Cleanup intervals
        $schedules['siater_daily'] = [
            'interval' => 86400,
            'display' => __('Una volta al giorno', 'siater'),
        ];
        $schedules['siater_every_3_days'] = [
            'interval' => 259200,
            'display' => __('Ogni 3 giorni', 'siater'),
        ];
        $schedules['siater_weekly'] = [
            'interval' => 604800,
            'display' => __('Una volta a settimana', 'siater'),
        ];
        return $schedules;
    }

    /**
     * Get schedule name from interval
     */
    public static function get_schedule_name(int $interval): string {
        $map = [
            60 => 'siater_every_1_min',
            120 => 'siater_every_2_min',
            180 => 'siater_every_3_min',
            300 => 'siater_every_5_min',
            600 => 'siater_every_10_min',
            900 => 'siater_every_15_min',
            1800 => 'siater_every_30_min',
            3600 => 'siater_every_1_hour',
            86400 => 'siater_daily',
            259200 => 'siater_every_3_days',
            604800 => 'siater_weekly',
        ];
        return $map[$interval] ?? 'siater_every_15_min';
    }

    /**
     * Run sync via WordPress cron
     */
    public function run_cron_sync(): void {
        // Verify license
        if (!$this->license->is_valid()) {
            return;
        }

        $sync = new \Siater\Sync\SyncHandler($this->settings);
        $sync->run();
    }

    /**
     * Run order export via WordPress cron
     */
    public function run_cron_export(): void {
        if (!$this->settings->get('esporta_ordini', 0)) {
            return;
        }

        $export = new \Siater\Order\OrderExporter($this->settings);
        $export->run();
    }

    /**
     * Run product cleanup via WordPress cron
     */
    public function run_cron_cleanup(): void {
        // Verify license
        if (!$this->license->is_valid()) {
            return;
        }

        $cleaner = new \Siater\Sync\ProductCleaner($this->settings);
        $cleaner->run();
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'siater',
            false,
            dirname(SIATER_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Register rewrite endpoints for sync (backward compatibility)
     */
    public function register_endpoints(): void {
        add_rewrite_rule(
            'siater-sync/?$',
            'index.php?siater_sync=1',
            'top'
        );
        add_rewrite_rule(
            'siater-export/?$',
            'index.php?siater_export=1',
            'top'
        );
        add_rewrite_rule(
            'siater-cleanup/?$',
            'index.php?siater_cleanup=1',
            'top'
        );

        add_filter('query_vars', function($vars) {
            $vars[] = 'siater_sync';
            $vars[] = 'siater_export';
            $vars[] = 'siater_cleanup';
            $vars[] = 'authkey';
            return $vars;
        });

        add_action('template_redirect', [$this, 'handle_endpoints']);
    }

    /**
     * Handle sync/export endpoints
     */
    public function handle_endpoints(): void {
        global $wp_query;

        if (get_query_var('siater_sync')) {
            $this->handle_sync_endpoint();
            exit;
        }

        if (get_query_var('siater_export')) {
            $this->handle_export_endpoint();
            exit;
        }

        if (get_query_var('siater_cleanup')) {
            $this->handle_cleanup_endpoint();
            exit;
        }
    }

    /**
     * Handle sync endpoint
     */
    private function handle_sync_endpoint(): void {
        $auth_key = isset($_GET['authkey']) ? sanitize_text_field($_GET['authkey']) : '';
        $stored_key = $this->settings->get('rand_code', '');

        if (empty($stored_key) || $auth_key !== $stored_key) {
            wp_die('Accesso non autorizzato', 'Errore', ['response' => 403]);
        }

        // Verify license
        if (!$this->license->is_valid()) {
            wp_die('Licenza non valida', 'Errore', ['response' => 403]);
        }

        // Run sync
        $sync = new \Siater\Sync\SyncHandler($this->settings);
        $sync->run();
    }

    /**
     * Handle export endpoint
     */
    private function handle_export_endpoint(): void {
        $auth_key = isset($_GET['authkey']) ? sanitize_text_field($_GET['authkey']) : '';
        $stored_key = $this->settings->get('rand_code', '');

        if (empty($stored_key) || $auth_key !== $stored_key) {
            wp_die('Accesso non autorizzato', 'Errore', ['response' => 403]);
        }

        if (!$this->settings->get('esporta_ordini', false)) {
            wp_die('Esportazione ordini non abilitata', 'Errore', ['response' => 403]);
        }

        // Run export
        $export = new \Siater\Order\OrderExporter($this->settings);
        $export->run();
    }

    /**
     * Handle cleanup endpoint
     */
    private function handle_cleanup_endpoint(): void {
        $auth_key = isset($_GET['authkey']) ? sanitize_text_field($_GET['authkey']) : '';
        $stored_key = $this->settings->get('rand_code', '');

        if (empty($stored_key) || $auth_key !== $stored_key) {
            wp_die('Accesso non autorizzato', 'Errore', ['response' => 403]);
        }

        // Verify license
        if (!$this->license->is_valid()) {
            wp_die('Licenza non valida', 'Errore', ['response' => 403]);
        }

        // Run cleanup
        $cleaner = new \Siater\Sync\ProductCleaner($this->settings);
        $cleaner->run();
        echo "Cleanup executed\n";
    }

    /**
     * Schedule WordPress cron events based on settings
     */
    public static function schedule_cron_events(): void {
        global $wpdb;

        // Get settings
        $table = $wpdb->prefix . 'siater_settings';
        $settings = $wpdb->get_row("SELECT * FROM $table WHERE id = 777");

        // Default intervals if settings not available
        $sync_interval = isset($settings->sync_interval) ? (int) $settings->sync_interval : 900;
        $export_interval = isset($settings->export_interval) ? (int) $settings->export_interval : 1800;
        $cleanup_interval = isset($settings->cleanup_interval) ? (int) $settings->cleanup_interval : 86400;
        $cron_mode = isset($settings->cron_mode) ? $settings->cron_mode : 'wordpress';

        // Clear existing schedules first
        self::clear_cron_events();

        // Only schedule if WordPress cron mode
        if ($cron_mode !== 'wordpress') {
            return;
        }

        // Schedule sync
        $sync_schedule = self::get_schedule_name($sync_interval);
        wp_schedule_event(time(), $sync_schedule, SIATER_CRON_SYNC);

        // Schedule export
        $export_schedule = self::get_schedule_name($export_interval);
        wp_schedule_event(time(), $export_schedule, SIATER_CRON_EXPORT);

        // Schedule cleanup
        $cleanup_schedule = self::get_schedule_name($cleanup_interval);
        wp_schedule_event(time(), $cleanup_schedule, SIATER_CRON_CLEANUP);
    }

    /**
     * Reschedule cron events (call after settings change)
     */
    public static function reschedule_cron_events(): void {
        self::schedule_cron_events();
    }

    /**
     * Clear scheduled cron events
     */
    public static function clear_cron_events(): void {
        wp_clear_scheduled_hook(SIATER_CRON_SYNC);
        wp_clear_scheduled_hook(SIATER_CRON_EXPORT);
        wp_clear_scheduled_hook(SIATER_CRON_CLEANUP);
    }

    /**
     * Check if WordPress cron is enabled
     */
    public function is_wp_cron_enabled(): bool {
        return $this->settings->get('cron_mode', 'wordpress') === 'wordpress';
    }
}

/**
 * Plugin activation
 * NOTE: Does NOT create new tables - uses existing wp_siater_settings and wp_siater_feed
 */
register_activation_hook(__FILE__, function() {
    global $wpdb;

    // Check if existing tables exist (from old plugin)
    $settings_table = $wpdb->prefix . 'siater_settings';
    $feed_table = $wpdb->prefix . 'siater_feed';

    $settings_exists = $wpdb->get_var("SHOW TABLES LIKE '$settings_table'") === $settings_table;
    $feed_exists = $wpdb->get_var("SHOW TABLES LIKE '$feed_table'") === $feed_table;

    // Create tables only if they don't exist (fresh install)
    if (!$settings_exists) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $settings_table (
            id MEDIUMINT(9) NOT NULL,
            url VARCHAR(250) DEFAULT NULL,
            licenza VARCHAR(250) DEFAULT NULL,
            rand_code VARCHAR(250) DEFAULT NULL,
            num_record MEDIUMINT(9) DEFAULT 300,
            listino MEDIUMINT(9) DEFAULT NULL,
            iva MEDIUMINT(9) DEFAULT NULL,
            tagliecolori MEDIUMINT(9) DEFAULT NULL,
            sincronizza_categorie MEDIUMINT(9) DEFAULT 1,
            arrotonda_prezzo MEDIUMINT(9) DEFAULT 0,
            aggiorna_immagini MEDIUMINT(9) DEFAULT 0,
            tipo_esistenza VARCHAR(50) DEFAULT 'esfisica',
            gestione_prodotti_esistenti MEDIUMINT(9) DEFAULT 0,
            esporta_ordini MEDIUMINT(9) DEFAULT 0,
            applica_sconto MEDIUMINT(9) DEFAULT 0,
            dev_use_ssl MEDIUMINT(9) DEFAULT 1,
            normalizza_brand MEDIUMINT(9) DEFAULT 0,
            aggiungi_iva MEDIUMINT(9) DEFAULT 0,
            importa_immagini_varianti MEDIUMINT(9) DEFAULT 0,
            solo_prodotti_con_foto_varianti MEDIUMINT(9) DEFAULT 0,
            debug_enabled MEDIUMINT(9) DEFAULT 0,
            verbose_output MEDIUMINT(9) DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta($sql);

        // Insert default row
        $wpdb->insert($settings_table, [
            'id' => 777,
            'rand_code' => md5(uniqid(wp_rand(), true)),
            'num_record' => 300,
            'sincronizza_categorie' => 1,
            'dev_use_ssl' => 1,
        ]);
    }

    if (!$feed_exists) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $feed_table (
            id MEDIUMINT(9) NOT NULL,
            inizio INT DEFAULT 0,
            offsetto INT DEFAULT 0,
            sincro INT DEFAULT 0,
            sync_lock TINYINT(1) DEFAULT 0,
            lock_timestamp INT DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta($sql);

        // Insert default row
        $wpdb->insert($feed_table, [
            'id' => 777,
            'inizio' => 0,
            'offsetto' => 0,
            'sincro' => 0,
        ]);
    } else {
        // Ensure new columns exist (upgrade path)
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $feed_table");
        $column_names = array_map(function($col) { return $col->Field; }, $columns);

        if (!in_array('sync_lock', $column_names)) {
            $wpdb->query("ALTER TABLE $feed_table ADD COLUMN sync_lock TINYINT(1) DEFAULT 0");
        }
        if (!in_array('lock_timestamp', $column_names)) {
            $wpdb->query("ALTER TABLE $feed_table ADD COLUMN lock_timestamp INT DEFAULT 0");
        }
    }

    // Also check settings table for new columns
    if ($settings_exists) {
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $settings_table");
        $column_names = array_map(function($col) { return $col->Field; }, $columns);

        if (!in_array('debug_enabled', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN debug_enabled MEDIUMINT(9) DEFAULT 0");
        }
        if (!in_array('verbose_output', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN verbose_output MEDIUMINT(9) DEFAULT 0");
        }
        if (!in_array('importa_immagini_varianti', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN importa_immagini_varianti MEDIUMINT(9) DEFAULT 0");
        }
        if (!in_array('solo_prodotti_con_foto_varianti', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN solo_prodotti_con_foto_varianti MEDIUMINT(9) DEFAULT 0");
        }
        // Cron options
        if (!in_array('cron_mode', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN cron_mode VARCHAR(20) DEFAULT 'wordpress'");
        }
        if (!in_array('sync_interval', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN sync_interval INT DEFAULT 900");
        }
        if (!in_array('export_interval', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN export_interval INT DEFAULT 1800");
        }
        if (!in_array('cleanup_interval', $column_names)) {
            $wpdb->query("ALTER TABLE $settings_table ADD COLUMN cleanup_interval INT DEFAULT 86400");
        }
    }

    // Schedule cron events
    Siater::schedule_cron_events();

    // Flush rewrite rules
    flush_rewrite_rules();
});

/**
 * Plugin deactivation
 */
register_deactivation_hook(__FILE__, function() {
    // Clear cron events
    Siater::clear_cron_events();
    flush_rewrite_rules();
});

/**
 * Get plugin instance
 */
function siater(): Siater {
    return Siater::instance();
}

/**
 * Initialize plugin after plugins loaded
 */
add_action('plugins_loaded', function() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            echo esc_html__('Siater richiede WooCommerce per funzionare.', 'siater');
            echo '</p></div>';
        });
        return;
    }

    siater();
});
