<?php

declare(strict_types=1);

namespace WicketPortus;

use HyperFields\Admin\ExportImportUI;
use HyperFields\Admin\ExportImportPageConfig;
use WicketPortus\Contracts\OptionGroupProviderInterface;
use WicketPortus\Manifest\TransferOrchestrator;
use WicketPortus\Modules\AccCarbonFieldsOptionsModule;
use WicketPortus\Modules\CuratedPagesExportModule;
use WicketPortus\Modules\DeveloperWpOptionsSnapshotModule;
use WicketPortus\Modules\FinancialFieldsModule;
use WicketPortus\Modules\MyAccountPagesExportModule;
use WicketPortus\Modules\PostTypeExportModule;
use WicketPortus\Modules\PluginInventoryModule;
use WicketPortus\Modules\WicketGfOptionsModule;
use WicketPortus\Modules\WicketMembershipsModule;
use WicketPortus\Modules\WicketSettingsModule;
use WicketPortus\Modules\WooCommerceEmailModule;
use WicketPortus\Registry\ModuleRegistry;
use WicketPortus\Support\HyperfieldsOptionTransfer;
use WicketPortus\Support\WarningPrinter;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Main Portus plugin bootstrap.
 */
class Plugin
{
    private const MODULE_SELECTION_KEY_PREFIX = '__portus_module__';
    private const DEFERRED_PLUGIN_CHANGES_TRANSIENT = 'wicket_portus_deferred_plugin_changes';
    private const IMPORT_RESULT_SOURCE = 'wicket_portus';
    private const DEVELOPER_ONLY_MODULE_KEYS = [
        'developer_wp_options_snapshot',
    ];

    private static ?Plugin $instance = null;

    private ModuleRegistry $registry;

    private TransferOrchestrator $orchestrator;

    private string $data_tools_hook_suffix = '';

    /**
     * Private constructor for singleton bootstrap.
     */
    private function __construct() {}

    /**
     * Returns the singleton plugin instance.
     *
     * @return static
     */
    public static function get_instance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
            static::$instance->boot();
        }

        return static::$instance;
    }

    /**
     * Bootstraps registry + orchestration and wires admin UI hooks.
     *
     * @return void
     */
    private function boot(): void
    {
        $this->registry = new ModuleRegistry();
        $this->register_modules();
        $this->apply_disabled_modules();
        $this->orchestrator = new TransferOrchestrator($this->registry);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_portus_data_tools_page'], 99);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_portus_data_tools_assets']);
        }

        add_action('wicket_portus/import/after', [$this, 'on_import_after'], 10, 2);
        add_action('admin_init', [$this, 'maybe_apply_deferred_plugin_changes']);
        add_filter('hyperfields/import/ui_notice_message', [$this, 'filter_portus_import_notice_message'], 10, 3);
        add_filter('hyperfields/import/ui_notice_extra_html', [$this, 'filter_portus_import_notice_extra_html'], 10, 3);

        /*
         * Allows extensions to register additional modules.
         *
         * @param ModuleRegistry $registry
         */
        do_action('wicket_portus_register_modules', $this->registry);
    }

    /**
     * Applies disabled modules from filter.
     *
     * @return void
     */
    private function apply_disabled_modules(): void
    {
        /**
         * Filter to disable specific Portus modules.
         *
         * Return an array of module keys to disable.
         *
         * @param string[] $disabled Module keys to disable.
         */
        $disabled = apply_filters('wicket_portus_disabled_modules', [
            'content_pages',
            'content_my_account',
        ]);

        if (!is_array($disabled)) {
            return;
        }

        foreach ($disabled as $key) {
            $this->registry->disable((string) $key);
        }
    }

    /**
     * Registers the Portus submenu page beneath Wicket's top-level admin item.
     *
     * Uses HyperFields admin UI renderer in manual mode so Portus can prepend
     * sensitive-data warnings and keep ownership of operator messaging.
     *
     * @return void
     */
    public function register_portus_data_tools_page(): void
    {
        if (!class_exists(ExportImportUI::class)) {
            add_action('admin_notices', [$this, 'render_missing_hyperfields_notice']);

            return;
        }

        $this->data_tools_hook_suffix = (string) add_submenu_page(
            'wicket-settings',
            __('Portus Export / Import', 'wicket-portus'),
            __('Portus', 'wicket-portus'),
            'manage_options',
            'wicket-portus-data-tools',
            [$this, 'render_portus_data_tools_page']
        );
    }

    /**
     * Enqueues HyperFields assets for the Portus data tools page only.
     *
     * @param string $hook_suffix
     * @return void
     */
    public function enqueue_portus_data_tools_assets(string $hook_suffix): void
    {
        if ($this->data_tools_hook_suffix === '' || $hook_suffix !== $this->data_tools_hook_suffix) {
            return;
        }

        if (class_exists(ExportImportUI::class)) {
            ExportImportUI::enqueuePageAssets();
        }

        wp_enqueue_script(
            'wicket-portus-export-sfx',
            plugins_url('assets/js/export-sfx.js', WICKET_PORTUS_FILE),
            [],
            '1.0.0',
            true
        );

        wp_add_inline_script(
            'wicket-portus-export-sfx',
            'window.wicketPortusExportSfx = ' . wp_json_encode([
                'audioUrl' => plugins_url('assets/audio/portus-exportus.mp3', WICKET_PORTUS_FILE),
            ]) . ';',
            'before'
        );
    }

    /**
     * Renders the Portus export/import admin page.
     *
     * Export is handled here via TransferOrchestrator to produce the full Portus
     * manifest schema. Import/diff still delegate to ExportImportUI for the
     * file-upload and diff-preview flow.
     *
     * @return void
     */
    public function render_portus_data_tools_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'wicket-portus'));
        }

        if (!class_exists(ExportImportUI::class)) {
            echo WarningPrinter::admin_notice(
                __('HyperFields is not available. Portus export/import UI cannot render.', 'wicket-portus'),
                'error'
            );

            return;
        }

        $orchestrator = $this->orchestrator;

        // Read export mode from POST (template = default safer option).
        $export_mode = isset($_POST['hf_export_mode'])
            ? sanitize_text_field(wp_unslash($_POST['hf_export_mode']))
            : 'template';
        $export_mode = in_array($export_mode, ['template', 'full', 'developer'], true) ? $export_mode : 'template';

        // Server-side enforcement: sensitive modes require their confirmation
        // checkboxes to be explicitly ticked. Without confirmation, fall back
        // to template so the UI gate cannot be bypassed via direct POST.
        if (in_array($export_mode, ['full', 'developer'], true)) {
            $full_confirmed = isset($_POST['hf_full_export_confirm'])
                && sanitize_text_field(wp_unslash($_POST['hf_full_export_confirm'])) === '1';

            if (!$full_confirmed) {
                $export_mode = 'template';
            }
        }

        if ($export_mode === 'developer') {
            $dev_confirmed = isset($_POST['hf_developer_export_confirm'])
                && sanitize_text_field(wp_unslash($_POST['hf_developer_export_confirm'])) === '1';

            if (!$dev_confirmed) {
                $export_mode = 'template';
            }
        }

        $options = $this->get_data_tools_options();
        $option_groups = $this->get_data_tools_option_groups();
        $selection_module_map = $this->get_export_selection_module_map();
        $all_module_keys = $this->all_registered_module_keys();

        $exporter = static function (array $selectedNames = [], string $prefix = '') use ($orchestrator, $export_mode, $selection_module_map, $all_module_keys): string {
            unset($prefix);

                $selected_module_keys = [];
                foreach ($selectedNames as $selected_name) {
                    $selected_name = (string) $selected_name;
                    if (isset($selection_module_map[$selected_name])) {
                        $selected_module_keys[] = (string) $selection_module_map[$selected_name];
                    }
                }
                $selected_module_keys = array_values(array_unique($selected_module_keys));

                if ($export_mode === 'developer') {
                    $selected_module_keys = $all_module_keys;
                }

                $manifest = $orchestrator->export($selected_module_keys, $export_mode);
                $encoded  = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return is_string($encoded) ? $encoded : '';
        };

        $manifest_module_keys = static function (array $decoded): array {
            $modules = $decoded['modules'] ?? null;
            if (!is_array($modules)) {
                return [];
            }

            $keys = array_keys($modules);

            return array_values(array_unique(array_map('strval', $keys)));
        };

        $normalize_manifest_for_diff = static function (array $manifest): array {
            // Volatile envelope keys create noise and do not affect import writes.
            unset($manifest['generated_at'], $manifest['errors'], $manifest['export_mode']);

            return $manifest;
        };

        $previewer = static function (
            array $decoded,
            string $jsonString,
            array $allowedImportOptions = [],
            string $prefix = '',
            array $options = [],
        ) use ($orchestrator, $manifest_module_keys, $normalize_manifest_for_diff): array {
            unset($allowedImportOptions, $prefix, $options);

                // Validate it looks like a Portus manifest.
                if (!isset($decoded['modules']) || !is_array($decoded['modules'])) {
                    return ['success' => false, 'message' => __('The uploaded file does not appear to be a valid Portus manifest.', 'wicket-portus')];
                }

                $module_keys = $manifest_module_keys($decoded);
                if (empty($module_keys)) {
                    return ['success' => false, 'message' => __('The uploaded manifest does not contain any importable modules.', 'wicket-portus')];
                }

                $incoming_mode = isset($decoded['export_mode']) ? (string) $decoded['export_mode'] : 'full';
                $incoming_mode = in_array($incoming_mode, ['template', 'full', 'developer'], true) ? $incoming_mode : 'full';

                // current = what the orchestrator would export for the same module set (for diff display).
                $current  = $orchestrator->export($module_keys, $incoming_mode);
                $incoming = $decoded;

                $current = $normalize_manifest_for_diff($current);
                $incoming = $normalize_manifest_for_diff($incoming);

                $transientKey = 'hf_import_preview_' . md5(wp_generate_uuid4());
                set_transient($transientKey, $jsonString, 5 * MINUTE_IN_SECONDS);

                return [
                    'success'       => true,
                    'transient_key' => $transientKey,
                    'current'       => $current,
                    'incoming'      => $incoming,
                ];
        };

        $importer = function (
            string $jsonString,
            array $allowedImportOptions = [],
            string $prefix = '',
        ) use ($orchestrator, $manifest_module_keys): array {
            unset($allowedImportOptions, $prefix);

                $decoded = json_decode($jsonString, true);
                if (!is_array($decoded) || !isset($decoded['modules'])) {
                    return ['success' => false, 'message' => __('Import failed: invalid Portus manifest.', 'wicket-portus')];
                }

                $module_keys = $manifest_module_keys($decoded);
                if (empty($module_keys)) {
                    return ['success' => false, 'message' => __('Import failed: no supported modules found in manifest.', 'wicket-portus')];
                }

                $result = $orchestrator->import($decoded, dry_run: false, module_keys: $module_keys);
                $errors = $result['errors'] ?? [];
                $queuedPluginChanges = $this->queued_plugin_change_count();

                $import_result = [
                    'success' => empty($errors),
                    'message' => empty($errors)
                        ? __('Portus manifest imported successfully.', 'wicket-portus')
                        : implode(' ', array_map('strval', $errors)),
                    'source' => self::IMPORT_RESULT_SOURCE,
                    'meta' => [
                        'module_count' => count($module_keys),
                        'queued_plugin_changes' => $queuedPluginChanges,
                    ],
                ];

                /**
                 * Fires after a Portus manifest has been imported.
                 *
                 * Wrapped in try/catch so that exceptions or wp_redirect()+exit()
                 * calls from post-import hook callbacks can never suppress the
                 * success message shown to the operator.
                 *
                 * @param array $import_result Import result {success: bool, message: string}.
                 * @param array $decoded       The full decoded Portus manifest.
                 */
                try {
                    do_action('wicket_portus/import/after', $import_result, $decoded);
                } catch (\Throwable $e) {
                    error_log('wicket_portus/import/after hook error: ' . $e->getMessage());
                }

                $import_result['meta']['queued_plugin_changes'] = $this->queued_plugin_change_count();

                return $import_result;
        };

        $config = new ExportImportPageConfig(
            options: $options,
            allowedImportOptions: array_keys($options),
            optionGroups: $option_groups,
            prefix: '',
            title: __('Portus Export / Import', 'wicket-portus'),
            description: __('Export and import Wicket-managed option groups. Review diffs before confirming import.', 'wicket-portus'),
            exporter: $exporter,
            previewer: $previewer,
            importer: $importer,
            exportFormExtras: $this->build_export_mode_controls(),
        );

        if (in_array($export_mode, ['full', 'developer'], true)) {
            echo WarningPrinter::sensitive_data_notice();
        }

        echo ExportImportUI::renderConfigured($config);
    }

    /**
     * Emits admin warning when HyperFields is unavailable.
     *
     * @return void
     */
    public function render_missing_hyperfields_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo WarningPrinter::admin_notice(
            __('Wicket Portus requires HyperFields to load the export/import admin page.', 'wicket-portus'),
            'error'
        );
    }

    /**
     * Aggregates option groups for HyperFields UI from module providers.
     *
     * @return array<string, string>
     */
    private function get_data_tools_options(): array
    {
        $options = [];

        foreach ($this->registry->all() as $module) {
            if ($this->is_developer_only_module($module->key())) {
                continue;
            }

            if ($this->registry->is_disabled($module->key())) {
                continue;
            }

            if ($module instanceof OptionGroupProviderInterface) {
                $options = array_merge($options, $module->option_groups());
                continue;
            }

            $selection_key = $this->module_selection_key($module->key());
            $options[$selection_key] = $this->module_selection_label($module->key());
        }

        /**
         * Allows extensions to add/remove option groups in Portus data tools UI.
         *
         * @param array<string, string> $options
         */
        $options = apply_filters('wicket_portus_data_tools_options', $options);

        return is_array($options) ? $options : [];
    }

    /**
     * Aggregates option key => module group labels for export UI filtering.
     *
     * @return array<string, string>
     */
    private function get_data_tools_option_groups(): array
    {
        $groups = [];

        foreach ($this->registry->all() as $module) {
            if ($this->is_developer_only_module($module->key())) {
                continue;
            }

            if ($this->registry->is_disabled($module->key())) {
                continue;
            }

            $module_group = $this->module_group_label($module->key());

            if ($module instanceof OptionGroupProviderInterface) {
                foreach ($module->option_groups() as $option_key => $_label) {
                    $groups[(string) $option_key] = $module_group;
                }
                continue;
            }

            $groups[$this->module_selection_key($module->key())] = $module_group;
        }

        /**
         * Allows extensions to adjust per-option group labels in the export UI.
         *
         * @param array<string, string> $groups option key => module group label
         */
        $groups = apply_filters('wicket_portus_data_tools_option_groups', $groups);

        return is_array($groups) ? $groups : [];
    }

    /**
     * Maps module keys to operator-friendly group labels in export UI.
     *
     * @param string $module_key
     * @return string
     */
    private function module_group_label(string $module_key): string
    {
        return match ($module_key) {
            'wicket_settings' => 'Wicket Base Plugin',
            'memberships' => 'Wicket Memberships',
            'gravity_forms_wicket_plugin' => 'Wicket Gravity Forms',
            'account_centre' => 'Wicket Account Centre',
            'financial_fields' => 'Wicket Financial Fields',
            'site_inventory' => 'Plugin Inventory',
            'curated_pages' => 'Content: Curated Pages',
            'my_account_pages' => 'Content: My Account Pages',
            'woocommerce_emails' => 'WooCommerce Emails',
            'developer_wp_options_snapshot' => 'Developer: Full wp_options Snapshot',
            default => ucwords(str_replace('_', ' ', $module_key)),
        };
    }

    /**
     * Returns selection option key => module key map for export module resolution.
     *
     * @return array<string, string>
     */
    private function get_export_selection_module_map(): array
    {
        $map = [];

        foreach ($this->registry->all() as $module) {
            $module_key = $module->key();

            if ($this->is_developer_only_module($module_key)) {
                continue;
            }

            if ($this->registry->is_disabled($module_key)) {
                continue;
            }

            if ($module instanceof OptionGroupProviderInterface) {
                foreach (array_keys($module->option_groups()) as $option_key) {
                    $map[(string) $option_key] = $module_key;
                }
                continue;
            }

            $map[$this->module_selection_key($module_key)] = $module_key;
        }

        return $map;
    }

    /**
     * Returns a synthetic UI selection key for module-level entries.
     *
     * @param string $module_key
     * @return string
     */
    private function module_selection_key(string $module_key): string
    {
        return self::MODULE_SELECTION_KEY_PREFIX . $module_key;
    }

    /**
     * Returns the UI label for synthetic module-level selector rows.
     *
     * @param string $module_key
     * @return string
     */
    private function module_selection_label(string $module_key): string
    {
        return match ($module_key) {
            'wicket_settings' => 'API credentials and environment settings',
            'site_inventory' => 'Status + version checks',
            'curated_pages' => 'Curated page list (shop, checkout, etc.)',
            'my_account_pages' => 'My account page list (dashboard, profile, org, etc.)',
            'woocommerce_emails' => 'All email settings',
            'gravity_forms_wicket_plugin' => 'Slug mapping, pagination, member fields',
            'memberships' => 'Plugin options + config posts',
            'account_centre' => 'Plugin options',
            'financial_fields' => 'Revenue deferral and finance mapping',
            default => '',
        };
    }

    /**
     * Returns all registered module keys, including developer-only modules.
     *
     * @return string[]
     */
    private function all_registered_module_keys(): array
    {
        return array_values(array_map('strval', array_keys($this->registry->all())));
    }

    /**
     * Returns true when a module is intended for developer-mode exports only.
     *
     * @param string $module_key
     * @return bool
     */
    private function is_developer_only_module(string $module_key): bool
    {
        return in_array($module_key, self::DEVELOPER_ONLY_MODULE_KEYS, true);
    }

    /**
     * Registers core modules for the accelerated lane MVP.
     *
     * @return void
     */
    private function register_modules(): void
    {
        $reader = new WordPressOptionReader();
        $transfer = new HyperfieldsOptionTransfer();

        $this->registry->register(new PluginInventoryModule());
        $this->registry->register(new DeveloperWpOptionsSnapshotModule());
        $this->registry->register(new PostTypeExportModule('content_pages', 'page'));
        $this->registry->register(new PostTypeExportModule('content_my_account', 'my-account'));
        $this->registry->register(new CuratedPagesExportModule('curated_pages'));
        $this->registry->register(new MyAccountPagesExportModule('my_account_pages'));
        $this->registry->register(new WicketSettingsModule($reader, $transfer));
        $this->registry->register(new WicketMembershipsModule($reader, $transfer));
        $this->registry->register(new WicketGfOptionsModule($reader, $transfer));
        $this->registry->register(new AccCarbonFieldsOptionsModule($reader, $transfer));
        $this->registry->register(new FinancialFieldsModule($reader, $transfer));
        $this->registry->register(new WooCommerceEmailModule($reader, $transfer));
    }

    /**
     * Builds the export mode radio controls HTML.
     *
     * Template is the safer default (credentials stripped). Full export requires
     * an explicit confirmation gate via a checkbox that must be manually checked.
     *
     * @return string HTML
     */
    private function build_export_mode_controls(): string
    {
        $current_mode = isset($_POST['hf_export_mode'])
            ? sanitize_text_field(wp_unslash($_POST['hf_export_mode']))
            : 'template';

        ob_start();
        ?>
        <div class="hyperpress-field-wrapper" style="margin-top: 20px; padding: 12px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Export Mode', 'wicket-portus'); ?></h3>
            <p class="description">
                <?php esc_html_e('Choose how sensitive data should be handled in this export.', 'wicket-portus'); ?>
            </p>

            <style>
            #hf-export-mode-controls input[name="hf_export_mode"][value="developer"]:checked ~ #hf-developer-export-gate {
                display: block !important;
            }
            #hf-export-mode-controls input[name="hf_export_mode"][value="full"]:checked ~ #hf-full-export-gate,
            #hf-export-mode-controls input[name="hf_export_mode"][value="developer"]:checked ~ #hf-full-export-gate {
                display: block !important;
            }
            </style>

            <fieldset id="hf-export-mode-controls">
                <legend class="screen-reader-text"><?php esc_html_e('Export mode selection', 'wicket-portus'); ?></legend>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="radio" name="hf_export_mode" value="template"
                        <?php checked($current_mode, 'template'); ?>
                        <?php checked($current_mode, ''); // Default to template if not set ?>>
                    <strong><?php esc_html_e('Template (Safe for New Clients)', 'wicket-portus'); ?></strong>
                    <br>
                    <span class="description">
                        <?php esc_html_e('Credentials, API keys, and environment-specific URLs are removed. Use this for onboarding new organisations or cloning configuration.', 'wicket-portus'); ?>
                    </span>
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="radio" name="hf_export_mode" value="full"
                        <?php checked($current_mode, 'full'); ?>
                        data-hf-sensitive-export-toggle>
                    <strong style="color: #d63638;"><?php esc_html_e('Full Export (Includes Credentials)', 'wicket-portus'); ?></strong>
                    <br>
                    <span class="description">
                        <?php esc_html_e('All data including credentials and API keys. Use this for same-client environment sync only.', 'wicket-portus'); ?>
                    </span>
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="radio" name="hf_export_mode" value="developer"
                        <?php checked($current_mode, 'developer'); ?>
                        data-hf-sensitive-export-toggle>
                    <strong style="color: #b32d2e;"><?php esc_html_e('Developer Full Export (Full + wp_options Snapshot)', 'wicket-portus'); ?></strong>
                    <br>
                    <span class="description">
                        <?php esc_html_e('Developer-focused export of all registered Portus modules plus a full wp_options table snapshot for diagnostics and migration analysis.', 'wicket-portus'); ?>
                    </span>
                </label>

                <div id="hf-developer-export-gate" style="margin-top: 10px; margin-bottom: 12px; padding: 12px; border-left: 4px solid #8a2424; background: #fff8f8; display: <?php echo $current_mode === 'developer' ? 'block' : 'none'; ?>;">
                    <p style="margin: 0 0 8px 0; color: #8a2424;">
                        <strong><?php esc_html_e('Developer export includes all configured Portus modules plus full wp_options data.', 'wicket-portus'); ?></strong>
                    </p>
                    <label style="display: block; margin-top: 8px;">
                        <input type="checkbox" name="hf_developer_export_confirm" value="1">
                        <span style="font-weight: 600;">
                            <?php esc_html_e('I understand this will export all available data, including developer-level snapshots.', 'wicket-portus'); ?>
                        </span>
                    </label>
                </div>

                <div id="hf-full-export-gate" style="margin-top: 15px; padding: 12px; border-left: 4px solid #d63638; background: #fff5f5; display: <?php echo in_array($current_mode, ['full', 'developer'], true) ? 'block' : 'none'; ?>;">
                    <p style="margin: 0 0 8px 0; color: #d63638;">
                        <strong><?php esc_html_e('⚠️ Warning: This export contains sensitive data.', 'wicket-portus'); ?></strong>
                    </p>
                    <p style="margin: 0 0 12px 0;" class="description">
                        <?php esc_html_e('API keys, authentication tokens, and environment URLs will be included. Do not share this file outside your organisation or via insecure channels.', 'wicket-portus'); ?>
                    </p>
                    <label style="display: block; margin-top: 8px;">
                        <input type="checkbox" name="hf_full_export_confirm" value="1">
                        <span style="font-weight: 600;">
                            <?php esc_html_e('I understand this file contains sensitive credentials and I will handle it securely.', 'wicket-portus'); ?>
                        </span>
                    </label>
                </div>
            </fieldset>
        </div>

        <?php
        return (string) ob_get_clean();
    }

    /**
     * Runs post-import side-effects after a successful Portus manifest import.
     *
     * Hooked to `wicket_portus/import/after`. Activates/deactivates plugins
     * according to the manifest's site_inventory module, then flushes all caches.
     *
     * @param array $result   Import result {success: bool, message: string}.
     * @param array $manifest The full decoded Portus manifest.
     * @return void
     */
    public function on_import_after(array $result, array $manifest): void
    {
        if (empty($result['success'])) {
            return;
        }

        $this->process_plugin_inventory($manifest);
        $this->flush_all_caches();
    }

    /**
     * Queues plugin activation/deactivation changes derived from the manifest.
     *
     * Changes are stored in a short-lived transient and applied on the next
     * admin_init via maybe_apply_deferred_plugin_changes(). Running
     * activate_plugin()/deactivate_plugins() mid-render is unsafe: plugin
     * activation hooks may call wp_redirect()+exit(), which would abort the
     * page before the success notice is rendered.
     *
     * Portus itself is never queued for deactivation.
     *
     * @param array $manifest
     * @return void
     */
    private function process_plugin_inventory(array $manifest): void
    {
        $plugins = $manifest['modules']['site_inventory']['plugins'] ?? null;
        if (!is_array($plugins) || empty($plugins)) {
            return;
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $self_basename = defined('WICKET_PORTUS_FILE') ? plugin_basename(WICKET_PORTUS_FILE) : '';

        $active_plugins = get_option('active_plugins', []);
        $active_plugins = is_array($active_plugins) ? $active_plugins : [];

        $to_activate   = [];
        $to_deactivate = [];

        foreach ($plugins as $plugin_row) {
            if (!is_array($plugin_row)) {
                continue;
            }

            $plugin_file      = (string) ($plugin_row['plugin'] ?? '');
            $should_be_active = (bool) ($plugin_row['active'] ?? false);

            if ($plugin_file === '' || $plugin_file === $self_basename) {
                continue;
            }

            $is_active = in_array($plugin_file, $active_plugins, true);

            if ($should_be_active && !$is_active) {
                $to_activate[] = $plugin_file;
            } elseif (!$should_be_active && $is_active) {
                $to_deactivate[] = $plugin_file;
            }
        }

        if (empty($to_activate) && empty($to_deactivate)) {
            return;
        }

        set_transient(self::DEFERRED_PLUGIN_CHANGES_TRANSIENT, [
            'activate'   => $to_activate,
            'deactivate' => $to_deactivate,
        ], 60);
    }

    /**
     * Applies deferred plugin activate/deactivate changes on admin_init.
     *
     * Runs on the request AFTER the import, once the success notice has already
     * been shown to the operator and the page render is fully complete.
     *
     * @return void
     */
    public function maybe_apply_deferred_plugin_changes(): void
    {
        $changes = get_transient(self::DEFERRED_PLUGIN_CHANGES_TRANSIENT);
        if (!is_array($changes)) {
            return;
        }

        if (function_exists('current_user_can') && !current_user_can('activate_plugins')) {
            return;
        }

        delete_transient(self::DEFERRED_PLUGIN_CHANGES_TRANSIENT);

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $to_deactivate = is_array($changes['deactivate'] ?? null) ? $changes['deactivate'] : [];
        $to_activate   = is_array($changes['activate'] ?? null) ? $changes['activate'] : [];

        if (!empty($to_deactivate)) {
            deactivate_plugins($to_deactivate);
        }

        foreach ($to_activate as $plugin_file) {
            activate_plugin((string) $plugin_file, '', false, true);
        }
    }

    /**
     * Customizes the HyperFields import notice message for Portus imports.
     *
     * @param string $message
     * @param array $importResult
     * @param bool $importSuccess
     * @return string
     */
    public function filter_portus_import_notice_message(string $message, array $importResult, bool $importSuccess): string
    {
        if (!$this->is_portus_import_result($importResult)) {
            return $message;
        }

        if (!$importSuccess) {
            return $message;
        }

        $queued = $this->extract_queued_plugin_change_count($importResult);
        if ($queued <= 0) {
            return $message;
        }

        return sprintf(
            __('%1$s Plugin synchronization is queued (%2$d change(s)) and will be applied on the next admin request.', 'wicket-portus'),
            $message,
            $queued
        );
    }

    /**
     * Appends structured metadata under the HyperFields import notice for Portus imports.
     *
     * @param string $extraHtml
     * @param array $importResult
     * @param bool $importSuccess
     * @return string
     */
    public function filter_portus_import_notice_extra_html(string $extraHtml, array $importResult, bool $importSuccess): string
    {
        if (!$this->is_portus_import_result($importResult)) {
            return $extraHtml;
        }

        $moduleCount = isset($importResult['meta']['module_count']) ? (int) $importResult['meta']['module_count'] : 0;
        $queued = $this->extract_queued_plugin_change_count($importResult);

        $rows = [];
        if ($moduleCount > 0) {
            $rows[] = '<li>' . esc_html(sprintf(__('Modules processed: %d', 'wicket-portus'), $moduleCount)) . '</li>';
        }

        if ($queued > 0) {
            $rows[] = '<li>' . esc_html(sprintf(__('Queued plugin activation/deactivation changes: %d', 'wicket-portus'), $queued)) . '</li>';
        }

        if (empty($rows)) {
            return $extraHtml;
        }

        $list = '<ul style="margin:8px 0 0 18px;list-style:disc;">' . implode('', $rows) . '</ul>';

        return $extraHtml . $list;
    }

    /**
     * @param array<string, mixed> $importResult
     */
    private function is_portus_import_result(array $importResult): bool
    {
        return isset($importResult['source']) && (string) $importResult['source'] === self::IMPORT_RESULT_SOURCE;
    }

    /**
     * @param array<string, mixed> $importResult
     */
    private function extract_queued_plugin_change_count(array $importResult): int
    {
        return isset($importResult['meta']['queued_plugin_changes'])
            ? max(0, (int) $importResult['meta']['queued_plugin_changes'])
            : 0;
    }

    /**
     * Returns total queued plugin activation/deactivation changes.
     *
     * @return int
     */
    private function queued_plugin_change_count(): int
    {
        $changes = get_transient(self::DEFERRED_PLUGIN_CHANGES_TRANSIENT);
        if (!is_array($changes)) {
            return 0;
        }

        $activate = is_array($changes['activate'] ?? null) ? $changes['activate'] : [];
        $deactivate = is_array($changes['deactivate'] ?? null) ? $changes['deactivate'] : [];

        return count($activate) + count($deactivate);
    }

    /**
     * Flushes the WordPress object cache and known page-caching plugins.
     *
     * @return void
     */
    private function flush_all_caches(): void
    {
        // WordPress object cache
        wp_cache_flush();

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // LiteSpeed Cache
        do_action('litespeed_purge_all');

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // WP Fastest Cache
        if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
            $GLOBALS['wp_fastest_cache']->deleteCache(true);
        }

        // Autoptimize
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            \autoptimizeCache::clearall();
        }

        // Breeze (Cloudways)
        if (class_exists('Breeze_Admin') && method_exists('Breeze_Admin', 'breeze_clear_all_cache')) {
            \Breeze_Admin::breeze_clear_all_cache(true);
        }
    }

    /**
     * Returns the module registry.
     *
     * @return ModuleRegistry
     */
    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    /**
     * Returns the transfer orchestrator.
     *
     * @return TransferOrchestrator
     */
    public function orchestrator(): TransferOrchestrator
    {
        return $this->orchestrator;
    }
}
