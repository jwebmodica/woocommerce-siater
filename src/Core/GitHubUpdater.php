<?php
/**
 * GitHub Plugin Updater
 *
 * Checks GitHub releases for plugin updates and integrates with WordPress update system.
 */

namespace Siater\Core;

defined('ABSPATH') || exit;

class GitHubUpdater {

    /**
     * GitHub repository owner/name
     */
    private string $repo;

    /**
     * Plugin slug
     */
    private string $slug;

    /**
     * Plugin basename
     */
    private string $basename;

    /**
     * Current plugin version
     */
    private string $version;

    /**
     * GitHub API URL
     */
    private string $api_url;

    /**
     * Cache key for transient
     */
    private string $cache_key;

    /**
     * Cache duration in seconds (12 hours)
     */
    private int $cache_duration = 43200;

    /**
     * Constructor
     *
     * @param string $repo GitHub repository (owner/repo format)
     */
    public function __construct(string $repo) {
        $this->repo = $repo;
        $this->slug = 'siater-connector';
        $this->basename = SIATER_PLUGIN_BASENAME;
        $this->version = SIATER_VERSION;
        $this->api_url = "https://api.github.com/repos/{$repo}/releases/latest";
        $this->cache_key = 'siater_github_update_' . md5($repo);

        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_directory_name'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
    }

    /**
     * Check for plugin updates
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $transient;
        }

        $latest_version = ltrim($release['tag_name'], 'v');

        if (version_compare($this->version, $latest_version, '<')) {
            $transient->response[$this->basename] = (object) [
                'slug' => $this->slug,
                'plugin' => $this->basename,
                'new_version' => $latest_version,
                'url' => $release['html_url'],
                'package' => $release['download_url'],
                'icons' => [],
                'banners' => [],
                'tested' => '',
                'requires_php' => '7.4',
            ];
        } else {
            $transient->no_update[$this->basename] = (object) [
                'slug' => $this->slug,
                'plugin' => $this->basename,
                'new_version' => $this->version,
                'url' => '',
                'package' => '',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin information for the WordPress plugins API
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        $latest_version = ltrim($release['tag_name'], 'v');

        return (object) [
            'name' => 'Siater Connector',
            'slug' => $this->slug,
            'version' => $latest_version,
            'author' => '<a href="https://www.sicilwareinformatica.it">Sicilware Informatica</a>',
            'author_profile' => 'https://www.sicilwareinformatica.it',
            'homepage' => 'https://www.sicilwareinformatica.it',
            'requires' => '6.0',
            'tested' => '6.7',
            'requires_php' => '7.4',
            'downloaded' => 0,
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => 'Sincronizza prodotti tra WooCommerce e il gestionale SIA (Sicilware Informatica).',
                'changelog' => $this->format_changelog($release['body']),
            ],
            'download_link' => $release['download_url'],
        ];
    }

    /**
     * Fix directory name after extraction
     *
     * GitHub downloads extract to owner-repo-hash format, we need to rename it
     */
    public function fix_directory_name($source, $remote_source, $upgrader, $hook_extra = []) {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }

        $corrected_source = trailingslashit($remote_source) . 'siater-connector/';

        if ($wp_filesystem->move($source, $corrected_source)) {
            return $corrected_source;
        }

        return $source;
    }

    /**
     * Clear cache after update
     */
    public function clear_cache($upgrader, $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins']) && in_array($this->basename, $options['plugins'])) {
                delete_transient($this->cache_key);
            }
        }
    }

    /**
     * Get latest release from GitHub
     */
    private function get_latest_release(): ?array {
        // Check cache first
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Fetch from GitHub API
        $response = wp_remote_get($this->api_url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Siater-Connector/' . $this->version,
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['tag_name'])) {
            return null;
        }

        // Look for siater-connector.zip asset first, fallback to zipball
        $download_url = $data['zipball_url'];
        if (!empty($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if ($asset['name'] === 'siater-connector.zip') {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $release = [
            'tag_name' => $data['tag_name'],
            'html_url' => $data['html_url'],
            'download_url' => $download_url,
            'body' => $data['body'] ?? '',
            'published_at' => $data['published_at'] ?? '',
        ];

        // Cache the result
        set_transient($this->cache_key, $release, $this->cache_duration);

        return $release;
    }

    /**
     * Format changelog from GitHub release body
     */
    private function format_changelog(string $body): string {
        if (empty($body)) {
            return '<p>No changelog available.</p>';
        }

        // Convert markdown to basic HTML
        $changelog = esc_html($body);
        $changelog = nl2br($changelog);

        // Convert markdown headers
        $changelog = preg_replace('/^## (.+)$/m', '<h4>$1</h4>', $changelog);
        $changelog = preg_replace('/^### (.+)$/m', '<h5>$1</h5>', $changelog);

        // Convert markdown lists
        $changelog = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/^- (.+)$/m', '<li>$1</li>', $changelog);

        return $changelog;
    }

    /**
     * Force check for updates (clears cache)
     */
    public function force_check(): ?array {
        delete_transient($this->cache_key);
        return $this->get_latest_release();
    }
}
