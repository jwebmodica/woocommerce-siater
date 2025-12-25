<?php
/**
 * Admin Handler
 *
 * Handles admin menu, settings page, and admin UI
 */

namespace Siater\Admin;

defined('ABSPATH') || exit;

class AdminHandler {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_form']);
    }

    /**
     * Add admin menu
     */
    public function add_menu(): void {
        add_menu_page(
            __('SIAter Sync', 'siater'),
            __('SIAter Sync', 'siater'),
            'manage_woocommerce',
            'siater',
            [$this, 'render_settings_page'],
            'dashicons-update',
            56
        );

        add_submenu_page(
            'siater',
            __('Impostazioni', 'siater'),
            __('Impostazioni', 'siater'),
            'manage_woocommerce',
            'siater',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'siater',
            __('Stato Sync', 'siater'),
            __('Stato Sync', 'siater'),
            'manage_woocommerce',
            'siater-status',
            [$this, 'render_status_page']
        );

        add_submenu_page(
            'siater',
            __('Debug Log', 'siater'),
            __('Debug Log', 'siater'),
            'manage_woocommerce',
            'siater-debug',
            [$this, 'render_debug_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'siater') === false) {
            return;
        }

        wp_enqueue_style(
            'siater-admin',
            SIATER_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SIATER_VERSION
        );
    }

    /**
     * Handle form submission
     */
    public function handle_form(): void {
        if (!isset($_POST['siater_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['siater_nonce'], 'siater_settings')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = siater()->settings;

        // License actions
        if (isset($_POST['activate_license'])) {
            $this->handle_license_activation();
            return;
        }

        if (isset($_POST['deactivate_license'])) {
            $this->handle_license_deactivation();
            return;
        }

        // Manual actions
        if (isset($_POST['run_sync_now'])) {
            $sync = new \Siater\Sync\SyncHandler($settings);
            $sync->run();
            add_settings_error('siater', 'sync_run', __('Sincronizzazione eseguita.', 'siater'), 'updated');
            return;
        }

        if (isset($_POST['run_cleanup_now'])) {
            $cleaner = new \Siater\Sync\ProductCleaner($settings);
            $cleaner->run();
            add_settings_error('siater', 'cleanup_run', __('Pulizia eseguita.', 'siater'), 'updated');
            return;
        }

        if (isset($_POST['reset_sync'])) {
            $sync = new \Siater\Sync\SyncHandler($settings);
            $sync->reset();
            add_settings_error('siater', 'sync_reset', __('Stato sync resettato.', 'siater'), 'updated');
            return;
        }

        if (isset($_POST['force_cleanup'])) {
            $cleaner = new \Siater\Sync\ProductCleaner($settings);
            $cleaner->force_start();
            add_settings_error('siater', 'cleanup_force', __('Pulizia forzata avviata.', 'siater'), 'updated');
            return;
        }

        // Save settings
        $fields = [
            'url' => 'sanitize_text_field',
            'num_record' => 'intval',
            'listino' => 'intval',
            'iva' => 'intval',
            'tagliecolori' => 'intval',
            'sincronizza_categorie' => 'intval',
            'arrotonda_prezzo' => 'intval',
            'aggiorna_immagini' => 'intval',
            'tipo_esistenza' => 'sanitize_text_field',
            'esporta_ordini' => 'intval',
            'applica_sconto' => 'intval',
            'dev_use_ssl' => 'intval',
            'normalizza_brand' => 'intval',
            'aggiungi_iva' => 'intval',
            'importa_immagini_varianti' => 'intval',
            'solo_prodotti_con_foto_varianti' => 'intval',
            'debug_enabled' => 'intval',
            'verbose_output' => 'intval',
            // Cron options
            'cron_mode' => 'sanitize_text_field',
            'sync_interval' => 'intval',
            'export_interval' => 'intval',
            'cleanup_interval' => 'intval',
        ];

        // Check if cron settings changed
        $old_cron_mode = $settings->get('cron_mode', 'wordpress');
        $old_sync_interval = $settings->get('sync_interval', 900);
        $old_export_interval = $settings->get('export_interval', 1800);
        $old_cleanup_interval = $settings->get('cleanup_interval', 86400);

        foreach ($fields as $field => $sanitize) {
            if (isset($_POST[$field])) {
                $value = call_user_func($sanitize, $_POST[$field]);
                $settings->set($field, $value);
            }
        }

        // Reschedule cron if settings changed
        $new_cron_mode = $settings->get('cron_mode', 'wordpress');
        $new_sync_interval = $settings->get('sync_interval', 900);
        $new_export_interval = $settings->get('export_interval', 1800);
        $new_cleanup_interval = $settings->get('cleanup_interval', 86400);

        if ($old_cron_mode !== $new_cron_mode ||
            $old_sync_interval !== $new_sync_interval ||
            $old_export_interval !== $new_export_interval ||
            $old_cleanup_interval !== $new_cleanup_interval) {
            \Siater::reschedule_cron_events();
        }

        add_settings_error(
            'siater',
            'settings_updated',
            __('Impostazioni salvate.', 'siater'),
            'updated'
        );
    }

    /**
     * Handle license activation
     */
    private function handle_license_activation(): void {
        $license_code = sanitize_text_field($_POST['license_code'] ?? '');
        $client_name = sanitize_text_field($_POST['client_name'] ?? '');

        if (empty($license_code) || empty($client_name)) {
            add_settings_error(
                'siater',
                'license_error',
                __('Inserisci codice licenza e nome utente.', 'siater'),
                'error'
            );
            return;
        }

        $result = siater()->license->activate($license_code, $client_name);

        add_settings_error(
            'siater',
            'license_result',
            $result['message'],
            $result['success'] ? 'updated' : 'error'
        );
    }

    /**
     * Handle license deactivation
     */
    private function handle_license_deactivation(): void {
        $result = siater()->license->deactivate();

        add_settings_error(
            'siater',
            'license_result',
            $result['message'],
            $result['success'] ? 'updated' : 'error'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        $settings = siater()->settings;
        $license = siater()->license;

        settings_errors('siater');
        ?>
        <div class="wrap siater-admin">
            <h1><?php esc_html_e('Siater Connector', 'siater'); ?></h1>

            <?php if (!$license->is_valid()): ?>
                <?php $this->render_license_form(); ?>
            <?php else: ?>
                <?php $this->render_license_status(); ?>

                <form method="post" action="">
                    <?php wp_nonce_field('siater_settings', 'siater_nonce'); ?>

                    <!-- SIA Configuration -->
                    <div class="siater-section">
                        <h2 class="siater-section-header">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php esc_html_e('Configurazione SIA', 'siater'); ?>
                        </h2>
                        <div class="siater-section-content">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="url"><?php esc_html_e('URL SIA', 'siater'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="url" name="url" class="regular-text"
                                               value="<?php echo esc_attr($settings->get('url')); ?>"
                                               placeholder="esempio.sicilwareinformatica.it">
                                        <p class="description"><?php esc_html_e('Dominio SIA senza https:// o www', 'siater'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="listino"><?php esc_html_e('Listino Prezzi', 'siater'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="listino" name="listino" class="small-text"
                                               value="<?php echo esc_attr($settings->get('listino', 1)); ?>" min="1">
                                        <p class="description"><?php esc_html_e('ID del listino prezzi da utilizzare', 'siater'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="num_record"><?php esc_html_e('Prodotti per Batch', 'siater'); ?></label>
                                    </th>
                                    <td>
                                        <select id="num_record" name="num_record">
                                            <?php foreach ([10, 20, 30, 50, 100, 200, 300] as $num): ?>
                                                <option value="<?php echo $num; ?>" <?php selected($settings->get('num_record'), $num); ?>>
                                                    <?php echo $num; ?> <?php esc_html_e('prodotti', 'siater'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('Numero di prodotti da sincronizzare per ogni ciclo', 'siater'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Product Options -->
                    <div class="siater-section">
                        <h2 class="siater-section-header">
                            <span class="dashicons dashicons-products"></span>
                            <?php esc_html_e('Opzioni Prodotti', 'siater'); ?>
                        </h2>
                        <div class="siater-section-content">
                            <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Taglie e Colori', 'siater'); ?></th>
                            <td>
                                <select name="tagliecolori">
                                    <option value="0" <?php selected($settings->get('tagliecolori'), 0); ?>>
                                        <?php esc_html_e('No - Solo prodotti semplici', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('tagliecolori'), 1); ?>>
                                        <?php esc_html_e('Si - Importa varianti', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('IVA nei Prezzi', 'siater'); ?></th>
                            <td>
                                <select name="iva">
                                    <option value="0" <?php selected($settings->get('iva'), 0); ?>>
                                        <?php esc_html_e('No - Prezzi senza IVA', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('iva'), 1); ?>>
                                        <?php esc_html_e('Si - Prezzi con IVA', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Aggiungi IVA 22%', 'siater'); ?></th>
                            <td>
                                <select name="aggiungi_iva">
                                    <option value="0" <?php selected($settings->get('aggiungi_iva'), 0); ?>>
                                        <?php esc_html_e('No', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('aggiungi_iva'), 1); ?>>
                                        <?php esc_html_e('Si - Calcola IVA sui prezzi', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Arrotondamento Prezzo', 'siater'); ?></th>
                            <td>
                                <select name="arrotonda_prezzo">
                                    <option value="0" <?php selected($settings->get('arrotonda_prezzo'), 0); ?>>
                                        <?php esc_html_e('Nessuno', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('arrotonda_prezzo'), 1); ?>>
                                        <?php esc_html_e('Arrotonda per eccesso (intero)', 'siater'); ?>
                                    </option>
                                    <option value="2" <?php selected($settings->get('arrotonda_prezzo'), 2); ?>>
                                        <?php esc_html_e('Arrotonda per eccesso (decimale)', 'siater'); ?>
                                    </option>
                                    <option value="3" <?php selected($settings->get('arrotonda_prezzo'), 3); ?>>
                                        <?php esc_html_e('Arrotonda a 50 centesimi', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Applica Sconto', 'siater'); ?></th>
                            <td>
                                <select name="applica_sconto">
                                    <option value="0" <?php selected($settings->get('applica_sconto'), 0); ?>>
                                        <?php esc_html_e('No', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('applica_sconto'), 1); ?>>
                                        <?php esc_html_e('Si - Usa sconto come prezzo scontato', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Tipo Giacenza', 'siater'); ?></th>
                            <td>
                                <select name="tipo_esistenza">
                                    <option value="esfisica" <?php selected($settings->get('tipo_esistenza'), 'esfisica'); ?>>
                                        <?php esc_html_e('Esistenza Fisica', 'siater'); ?>
                                    </option>
                                    <option value="esreale" <?php selected($settings->get('tipo_esistenza'), 'esreale'); ?>>
                                        <?php esc_html_e('Esistenza Reale', 'siater'); ?>
                                    </option>
                                    <option value="esteorica" <?php selected($settings->get('tipo_esistenza'), 'esteorica'); ?>>
                                        <?php esc_html_e('Esistenza Teorica', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Categories & Brand -->
                    <div class="siater-section">
                        <h2 class="siater-section-header">
                            <span class="dashicons dashicons-category"></span>
                            <?php esc_html_e('Categorie e Brand', 'siater'); ?>
                        </h2>
                        <div class="siater-section-content">
                            <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Sincronizza Categorie', 'siater'); ?></th>
                            <td>
                                <select name="sincronizza_categorie">
                                    <option value="1" <?php selected($settings->get('sincronizza_categorie'), 1); ?>>
                                        <?php esc_html_e('Si', 'siater'); ?>
                                    </option>
                                    <option value="0" <?php selected($settings->get('sincronizza_categorie'), 0); ?>>
                                        <?php esc_html_e('No', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Normalizza Brand', 'siater'); ?></th>
                            <td>
                                <select name="normalizza_brand">
                                    <option value="0" <?php selected($settings->get('normalizza_brand'), 0); ?>>
                                        <?php esc_html_e('No', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('normalizza_brand'), 1); ?>>
                                        <?php esc_html_e('Si - Rimuovi testo dopo /', 'siater'); ?>
                                    </option>
                                </select>
                                <p class="description"><?php esc_html_e('Es: "Nike / Uomo" diventa "Nike"', 'siater'); ?></p>
                            </td>
                        </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Images -->
                    <div class="siater-section">
                        <h2 class="siater-section-header">
                            <span class="dashicons dashicons-format-image"></span>
                            <?php esc_html_e('Immagini', 'siater'); ?>
                        </h2>
                        <div class="siater-section-content">
                            <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Aggiorna Immagini', 'siater'); ?></th>
                            <td>
                                <select name="aggiorna_immagini">
                                    <option value="0" <?php selected($settings->get('aggiorna_immagini'), 0); ?>>
                                        <?php esc_html_e('No - Solo prima importazione', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('aggiorna_immagini'), 1); ?>>
                                        <?php esc_html_e('Si - Aggiorna sempre', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Immagini Varianti', 'siater'); ?></th>
                            <td>
                                <select name="importa_immagini_varianti">
                                    <option value="0" <?php selected($settings->get('importa_immagini_varianti'), 0); ?>>
                                        <?php esc_html_e('No', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('importa_immagini_varianti'), 1); ?>>
                                        <?php esc_html_e('Si - Importa immagini varianti', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Solo con Immagini Varianti', 'siater'); ?></th>
                            <td>
                                <select name="solo_prodotti_con_foto_varianti">
                                    <option value="0" <?php selected($settings->get('solo_prodotti_con_foto_varianti'), 0); ?>>
                                        <?php esc_html_e('No - Importa tutti', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('solo_prodotti_con_foto_varianti'), 1); ?>>
                                        <?php esc_html_e('Si - Solo prodotti con foto varianti', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Order Export -->
                    <div class="siater-section">
                        <h2 class="siater-section-header">
                            <span class="dashicons dashicons-clipboard"></span>
                            <?php esc_html_e('Esportazione Ordini', 'siater'); ?>
                        </h2>
                        <div class="siater-section-content">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e('Esporta Ordini', 'siater'); ?></th>
                                    <td>
                                        <select name="esporta_ordini">
                                            <option value="0" <?php selected($settings->get('esporta_ordini'), 0); ?>>
                                                <?php esc_html_e('Disabilitato', 'siater'); ?>
                                            </option>
                                            <option value="1" <?php selected($settings->get('esporta_ordini'), 1); ?>>
                                                <?php esc_html_e('Abilitato - Esporta in CSV', 'siater'); ?>
                                            </option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Esporta gli ordini in formato CSV per il gestionale SIA', 'siater'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Advanced Options -->
                    <div class="siater-section">
                        <h2 class="siater-section-header">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <?php esc_html_e('Opzioni Avanzate', 'siater'); ?>
                        </h2>
                        <div class="siater-section-content">
                            <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Usa SSL', 'siater'); ?></th>
                            <td>
                                <select name="dev_use_ssl">
                                    <option value="1" <?php selected($settings->get('dev_use_ssl'), 1); ?>>
                                        <?php esc_html_e('Si - HTTPS', 'siater'); ?>
                                    </option>
                                    <option value="0" <?php selected($settings->get('dev_use_ssl'), 0); ?>>
                                        <?php esc_html_e('No - HTTP', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Debug', 'siater'); ?></th>
                            <td>
                                <select name="debug_enabled">
                                    <option value="0" <?php selected($settings->get('debug_enabled'), 0); ?>>
                                        <?php esc_html_e('Disabilitato', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('debug_enabled'), 1); ?>>
                                        <?php esc_html_e('Abilitato', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Output Verbose', 'siater'); ?></th>
                            <td>
                                <select name="verbose_output">
                                    <option value="0" <?php selected($settings->get('verbose_output'), 0); ?>>
                                        <?php esc_html_e('No', 'siater'); ?>
                                    </option>
                                    <option value="1" <?php selected($settings->get('verbose_output'), 1); ?>>
                                        <?php esc_html_e('Si', 'siater'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Cron Scheduling -->
                    <div class="siater-section">
                        <h2 class="siater-section-header">
                            <span class="dashicons dashicons-clock"></span>
                            <?php esc_html_e('Pianificazione Cron', 'siater'); ?>
                        </h2>
                        <div class="siater-section-content">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e('Modalita Cron', 'siater'); ?></th>
                                    <td>
                                        <select name="cron_mode" id="cron_mode">
                                            <option value="wordpress" <?php selected($settings->get('cron_mode', 'wordpress'), 'wordpress'); ?>>
                                                <?php esc_html_e('WordPress Cron (Automatico)', 'siater'); ?>
                                            </option>
                                            <option value="manual" <?php selected($settings->get('cron_mode', 'wordpress'), 'manual'); ?>>
                                                <?php esc_html_e('Cron Esterno (Manuale)', 'siater'); ?>
                                            </option>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('WordPress Cron: sincronizzazione automatica. Cron Esterno: usa cron job del server.', 'siater'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="cron-interval-row">
                                    <th scope="row"><?php esc_html_e('Intervallo Sync Prodotti', 'siater'); ?></th>
                                    <td>
                                        <select name="sync_interval">
                                            <?php foreach (\Siater\Core\Settings::SYNC_INTERVALS as $seconds => $label): ?>
                                                <option value="<?php echo esc_attr($seconds); ?>" <?php selected((int)$settings->get('sync_interval', 900), $seconds); ?>>
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr class="cron-interval-row">
                                    <th scope="row"><?php esc_html_e('Intervallo Export Ordini', 'siater'); ?></th>
                                    <td>
                                        <select name="export_interval">
                                            <?php foreach (\Siater\Core\Settings::EXPORT_INTERVALS as $seconds => $label): ?>
                                                <option value="<?php echo esc_attr($seconds); ?>" <?php selected((int)$settings->get('export_interval', 1800), $seconds); ?>>
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr class="cron-interval-row">
                                    <th scope="row"><?php esc_html_e('Intervallo Pulizia Prodotti', 'siater'); ?></th>
                                    <td>
                                        <select name="cleanup_interval">
                                            <?php foreach (\Siater\Core\Settings::CLEANUP_INTERVALS as $seconds => $label): ?>
                                                <option value="<?php echo esc_attr($seconds); ?>" <?php selected((int)$settings->get('cleanup_interval', 86400), $seconds); ?>>
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>

                            <script>
                            jQuery(document).ready(function($) {
                                function toggleCronIntervals() {
                                    var mode = $('#cron_mode').val();
                                    if (mode === 'wordpress') {
                                        $('.cron-interval-row').show();
                                    } else {
                                        $('.cron-interval-row').hide();
                                    }
                                }
                                toggleCronIntervals();
                                $('#cron_mode').on('change', toggleCronIntervals);
                            });
                            </script>

                            <div class="siater-submit">
                                <?php submit_button(__('Salva Impostazioni', 'siater'), 'primary', 'submit', false); ?>
                            </div>
                        </div>
                    </div>

                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render license form
     */
    private function render_license_form(): void {
        ?>
        <div class="siater-license-card">
            <h2><?php esc_html_e('Attivazione Licenza', 'siater'); ?></h2>
            <p><?php esc_html_e('Inserisci i dati della licenza per attivare il plugin e accedere a tutte le funzionalita.', 'siater'); ?></p>

            <form method="post" action="">
                <?php wp_nonce_field('siater_settings', 'siater_nonce'); ?>

                <p>
                    <label for="license_code" style="display: block; margin-bottom: 5px; font-weight: 500;">
                        <?php esc_html_e('Codice Licenza', 'siater'); ?>
                    </label>
                    <input type="text" id="license_code" name="license_code" class="regular-text"
                           placeholder="XXXX-XXXX-XXXX-XXXX" required>
                </p>

                <p style="margin-bottom: 20px;">
                    <label for="client_name" style="display: block; margin-bottom: 5px; font-weight: 500;">
                        <?php esc_html_e('Nome Utente', 'siater'); ?>
                    </label>
                    <input type="text" id="client_name" name="client_name" class="regular-text"
                           placeholder="<?php esc_attr_e('Il tuo nome o azienda', 'siater'); ?>" required>
                </p>

                <button type="submit" name="activate_license" class="button button-primary">
                    <span class="dashicons dashicons-yes" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Attiva Licenza', 'siater'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Render license status
     */
    private function render_license_status(): void {
        $license_data = siater()->license->get_license_data();
        ?>
        <div class="siater-license-active">
            <div>
                <span class="dashicons dashicons-yes-alt"></span>
                <strong><?php esc_html_e('Licenza Attiva', 'siater'); ?></strong>
                <?php if ($license_data): ?>
                    &mdash; <?php echo esc_html($license_data['client_name'] ?? ''); ?>
                <?php endif; ?>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field('siater_settings', 'siater_nonce'); ?>
                <button type="submit" name="deactivate_license" class="button button-secondary">
                    <?php esc_html_e('Disattiva Licenza', 'siater'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Render status page
     */
    public function render_status_page(): void {
        $settings = siater()->settings;

        $sync = new \Siater\Sync\SyncHandler($settings);
        $sync_status = $sync->get_status();

        $cleaner = new \Siater\Sync\ProductCleaner($settings);
        $cleaner_status = $cleaner->get_status();

        settings_errors('siater');
        ?>
        <div class="wrap siater-admin">
            <h1><?php esc_html_e('Stato Sincronizzazione', 'siater'); ?></h1>

            <h2><?php esc_html_e('Sincronizzazione Prodotti', 'siater'); ?></h2>
            <table class="widefat" style="max-width: 600px;">
                <tr>
                    <th><?php esc_html_e('Stato', 'siater'); ?></th>
                    <td>
                        <?php if ($sync_status['is_running']): ?>
                            <span style="color: orange;"><?php esc_html_e('In esecuzione', 'siater'); ?></span>
                        <?php else: ?>
                            <span style="color: green;"><?php esc_html_e('In attesa', 'siater'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Ultima sincronizzazione', 'siater'); ?></th>
                    <td><?php echo esc_html($sync_status['last_sync_formatted']); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Ore trascorse', 'siater'); ?></th>
                    <td><?php echo esc_html($sync_status['hours_since_last']); ?> ore</td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Offset corrente', 'siater'); ?></th>
                    <td><?php echo esc_html($sync_status['current_offset']); ?></td>
                </tr>
            </table>

            <form method="post" action="" style="margin-top: 15px;">
                <?php wp_nonce_field('siater_settings', 'siater_nonce'); ?>
                <button type="submit" name="run_sync_now" class="button button-primary">
                    <?php esc_html_e('Esegui Sync Ora', 'siater'); ?>
                </button>
                <button type="submit" name="reset_sync" class="button">
                    <?php esc_html_e('Reset Stato', 'siater'); ?>
                </button>
            </form>

            <hr>

            <h2><?php esc_html_e('Pulizia Prodotti', 'siater'); ?></h2>
            <p class="description"><?php esc_html_e('Rimuove i prodotti non piu presenti nel feed SIA.', 'siater'); ?></p>
            <table class="widefat" style="max-width: 600px;">
                <tr>
                    <th><?php esc_html_e('Fase', 'siater'); ?></th>
                    <td>
                        <?php
                        $phase_labels = [
                            'idle' => __('In attesa', 'siater'),
                            'fetch' => __('Recupero SKU da SIA', 'siater'),
                            'compare' => __('Confronto prodotti', 'siater'),
                            'delete' => __('Eliminazione prodotti', 'siater'),
                        ];
                        echo esc_html($phase_labels[$cleaner_status['phase']] ?? $cleaner_status['phase']);
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Ultima esecuzione', 'siater'); ?></th>
                    <td><?php echo esc_html($cleaner_status['last_complete_formatted']); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Prossima esecuzione', 'siater'); ?></th>
                    <td>
                        <?php
                        if ($cleaner_status['next_run_hours'] > 0) {
                            printf(__('Tra %.1f ore', 'siater'), $cleaner_status['next_run_hours']);
                        } else {
                            esc_html_e('Al prossimo cron', 'siater');
                        }
                        ?>
                    </td>
                </tr>
                <?php if ($cleaner_status['pending_deletions'] > 0): ?>
                <tr>
                    <th><?php esc_html_e('Prodotti da eliminare', 'siater'); ?></th>
                    <td><?php echo esc_html($cleaner_status['pending_deletions']); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <form method="post" action="" style="margin-top: 15px;">
                <?php wp_nonce_field('siater_settings', 'siater_nonce'); ?>
                <button type="submit" name="run_cleanup_now" class="button button-primary">
                    <?php esc_html_e('Esegui Pulizia Ora', 'siater'); ?>
                </button>
                <button type="submit" name="force_cleanup" class="button">
                    <?php esc_html_e('Forza Nuovo Ciclo', 'siater'); ?>
                </button>
            </form>

            <hr>

            <h2><?php esc_html_e('Pianificazione Automatica', 'siater'); ?></h2>
            <?php
            $cron_mode = $settings->get('cron_mode', 'wordpress');
            $sync_interval = (int) $settings->get('sync_interval', 900);
            $export_interval = (int) $settings->get('export_interval', 1800);
            $cleanup_interval = (int) $settings->get('cleanup_interval', 86400);

            $sync_label = \Siater\Core\Settings::SYNC_INTERVALS[$sync_interval] ?? '15 minuti';
            $export_label = \Siater\Core\Settings::EXPORT_INTERVALS[$export_interval] ?? '30 minuti';
            $cleanup_label = \Siater\Core\Settings::CLEANUP_INTERVALS[$cleanup_interval] ?? '1 giorno';
            ?>

            <table class="widefat" style="max-width: 600px;">
                <tr>
                    <th><?php esc_html_e('Modalita', 'siater'); ?></th>
                    <td>
                        <?php if ($cron_mode === 'wordpress'): ?>
                            <span style="color: green;"><?php esc_html_e('WordPress Cron (Automatico)', 'siater'); ?></span>
                        <?php else: ?>
                            <span style="color: orange;"><?php esc_html_e('Cron Esterno (Manuale)', 'siater'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Sync Prodotti', 'siater'); ?></th>
                    <td>
                        <?php
                        $next_sync = wp_next_scheduled(SIATER_CRON_SYNC);
                        if ($next_sync) {
                            echo esc_html(date('Y-m-d H:i:s', $next_sync));
                            echo ' <span class="description">(ogni ' . esc_html($sync_label) . ')</span>';
                        } else {
                            if ($cron_mode === 'wordpress') {
                                esc_html_e('Non programmato - ', 'siater');
                                echo '<a href="' . esc_url(admin_url('admin.php?page=siater')) . '">' . esc_html__('riattiva', 'siater') . '</a>';
                            } else {
                                esc_html_e('Usa cron esterno', 'siater');
                            }
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Export Ordini', 'siater'); ?></th>
                    <td>
                        <?php
                        $next_export = wp_next_scheduled(SIATER_CRON_EXPORT);
                        if ($next_export) {
                            echo esc_html(date('Y-m-d H:i:s', $next_export));
                            echo ' <span class="description">(ogni ' . esc_html($export_label) . ')</span>';
                        } else {
                            if ($cron_mode === 'wordpress') {
                                esc_html_e('Non programmato - ', 'siater');
                                echo '<a href="' . esc_url(admin_url('admin.php?page=siater')) . '">' . esc_html__('riattiva', 'siater') . '</a>';
                            } else {
                                esc_html_e('Usa cron esterno', 'siater');
                            }
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Pulizia Prodotti', 'siater'); ?></th>
                    <td>
                        <?php
                        $next_cleanup = wp_next_scheduled(SIATER_CRON_CLEANUP);
                        if ($next_cleanup) {
                            echo esc_html(date('Y-m-d H:i:s', $next_cleanup));
                            echo ' <span class="description">(ogni ' . esc_html($cleanup_label) . ')</span>';
                        } else {
                            if ($cron_mode === 'wordpress') {
                                esc_html_e('Non programmato - ', 'siater');
                                echo '<a href="' . esc_url(admin_url('admin.php?page=siater')) . '">' . esc_html__('riattiva', 'siater') . '</a>';
                            } else {
                                esc_html_e('Usa cron esterno', 'siater');
                            }
                        }
                        ?>
                    </td>
                </tr>
            </table>

            <hr>

            <h2><?php esc_html_e('URL Manuali (Backup)', 'siater'); ?></h2>
            <p class="description"><?php esc_html_e('Usa questi URL se preferisci configurare cron job esterni:', 'siater'); ?></p>

            <h4><?php esc_html_e('Sincronizzazione Prodotti', 'siater'); ?></h4>
            <code><?php echo esc_html(home_url('/siater-sync/?authkey=' . $settings->get('rand_code'))); ?></code>

            <h4><?php esc_html_e('Esportazione Ordini', 'siater'); ?></h4>
            <code><?php echo esc_html(home_url('/siater-export/?authkey=' . $settings->get('rand_code'))); ?></code>

            <h4><?php esc_html_e('Pulizia Prodotti', 'siater'); ?></h4>
            <code><?php echo esc_html(home_url('/siater-cleanup/?authkey=' . $settings->get('rand_code'))); ?></code>
        </div>
        <?php
    }

    /**
     * Render debug page
     */
    public function render_debug_page(): void {
        $logger = \Siater\Utils\Logger::instance();

        // Handle clear log
        if (isset($_POST['clear_log']) && wp_verify_nonce($_POST['clear_log_nonce'] ?? '', 'siater_clear_log')) {
            $logger->clear();
        }

        $content = $logger->get_content(200);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Debug Log', 'siater'); ?></h1>

            <p>
                <strong><?php esc_html_e('File:', 'siater'); ?></strong>
                <?php echo esc_html($logger->get_file_path()); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('siater_clear_log', 'clear_log_nonce'); ?>
                <button type="submit" name="clear_log" class="button">
                    <?php esc_html_e('Cancella Log', 'siater'); ?>
                </button>
            </form>

            <h2><?php esc_html_e('Contenuto Log', 'siater'); ?></h2>
            <pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; overflow: auto; max-height: 600px; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; line-height: 1.4;"><?php
                if (empty($content)) {
                    echo esc_html__('Log vuoto', 'siater');
                } else {
                    // Colorize output
                    $content = esc_html($content);
                    $content = preg_replace('/\[ERROR\]/', '<span style="color: #f48771;">[ERROR]</span>', $content);
                    $content = preg_replace('/\[WARNING\]/', '<span style="color: #dcdcaa;">[WARNING]</span>', $content);
                    $content = preg_replace('/\[SUCCESS\]/', '<span style="color: #4ec9b0;">[SUCCESS]</span>', $content);
                    $content = preg_replace('/\[INFO\]/', '<span style="color: #9cdcfe;">[INFO]</span>', $content);
                    $content = preg_replace('/\[DEBUG\]/', '<span style="color: #808080;">[DEBUG]</span>', $content);
                    echo $content;
                }
            ?></pre>
        </div>
        <?php
    }
}
