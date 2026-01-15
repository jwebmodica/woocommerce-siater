<?php
/**
 * Direct Cleanup Endpoint
 *
 * Bypasses WordPress routing, caching, and maintenance mode.
 * Usage: https://yoursite.com/wp-content/plugins/siater-connector/cleanup.php?authkey=YOUR_KEY
 */

// Prevent direct browser access without authkey
if (empty($_GET['authkey'])) {
    die('Missing authkey');
}

// Load WordPress
$wp_load_paths = [
    dirname(__FILE__) . '/../../../wp-load.php',
    dirname(__FILE__) . '/../../../../wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('Could not load WordPress');
}

// Verify authkey
$settings = new \Siater\Core\Settings();
$stored_key = $settings->get('rand_code', '');
$provided_key = sanitize_text_field($_GET['authkey']);

if (empty($stored_key) || $provided_key !== $stored_key) {
    wp_die('Accesso non autorizzato', 'Errore', ['response' => 403]);
}

// Verify license
$license = new \Siater\License\LicenseManager();
if (!$license->is_valid()) {
    wp_die('Licenza non valida', 'Errore', ['response' => 403]);
}

// Run cleanup
$cleaner = new \Siater\Sync\ProductCleaner($settings);
$cleaner->run();
