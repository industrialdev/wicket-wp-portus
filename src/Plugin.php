<?php

declare(strict_types=1);

namespace WicketPortus;

use HyperFields\Admin\ExportImportUI;
use HyperFields\Admin\ExportImportPageConfig;
use WicketPortus\Contracts\OptionGroupProviderInterface;
use WicketPortus\Manifest\TransferOrchestrator;
use WicketPortus\Modules\AccCarbonFieldsOptionsModule;
use WicketPortus\Modules\MembershipOptionsModule;
use WicketPortus\Modules\PluginInventoryModule;
use WicketPortus\Modules\WicketGfOptionsModule;
use WicketPortus\Modules\WicketSettingsModule;
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

    private static ?Plugin $instance = null;

    private ModuleRegistry $registry;

    private TransferOrchestrator $orchestrator;

    private string $data_tools_hook_suffix = '';

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
        $this->orchestrator = new TransferOrchestrator($this->registry);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_portus_data_tools_page'], 99);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_portus_data_tools_assets']);
        }

        /*
         * Allows extensions to register additional modules.
         *
         * @param ModuleRegistry $registry
         */
        do_action('wicket_portus_register_modules', $this->registry);
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
        $export_mode = isset($_POST['portus_export_mode'])
            ? sanitize_text_field(wp_unslash($_POST['portus_export_mode']))
            : 'template';
        $export_mode = in_array($export_mode, ['template', 'full'], true) ? $export_mode : 'template';

        $options = $this->get_data_tools_options();
        $option_groups = $this->get_data_tools_option_groups();
        $selection_module_map = $this->get_export_selection_module_map();

        $exporter = static function (array $selectedNames = [], string $prefix = '') use ($orchestrator, $export_mode, $selection_module_map): string {
            unset($prefix);

                $selected_module_keys = [];
                foreach ($selectedNames as $selected_name) {
                    $selected_name = (string) $selected_name;
                    if (isset($selection_module_map[$selected_name])) {
                        $selected_module_keys[] = (string) $selection_module_map[$selected_name];
                    }
                }
                $selected_module_keys = array_values(array_unique($selected_module_keys));

                $manifest = $orchestrator->export($selected_module_keys, $export_mode);
                $encoded  = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return is_string($encoded) ? $encoded : '';
        };

        $previewer = static function (
            array $decoded,
            string $jsonString,
            array $allowedImportOptions = [],
            string $prefix = '',
            array $options = [],
        ) use ($orchestrator): array {
            unset($allowedImportOptions, $prefix, $options);

                // Validate it looks like a Portus manifest.
                if (!isset($decoded['modules']) || !is_array($decoded['modules'])) {
                    return ['success' => false, 'message' => __('The uploaded file does not appear to be a valid Portus manifest.', 'wicket-portus')];
                }

                // current = what the orchestrator would export right now (for diff display).
                $current  = $orchestrator->export();
                $incoming = $decoded;

                $transientKey = 'hf_import_preview_' . md5(wp_generate_uuid4());
                set_transient($transientKey, $jsonString, 5 * MINUTE_IN_SECONDS);

                return [
                    'success'       => true,
                    'transient_key' => $transientKey,
                    'current'       => $current,
                    'incoming'      => $incoming,
                ];
        };

        $importer = static function (
            string $jsonString,
            array $allowedImportOptions = [],
            string $prefix = '',
        ) use ($orchestrator): array {
            unset($allowedImportOptions, $prefix);

                $decoded = json_decode($jsonString, true);
                if (!is_array($decoded) || !isset($decoded['modules'])) {
                    return ['success' => false, 'message' => __('Import failed: invalid Portus manifest.', 'wicket-portus')];
                }

                $result = $orchestrator->import($decoded, dry_run: false);
                $errors = $result['errors'] ?? [];

                return [
                    'success' => empty($errors),
                    'message' => empty($errors)
                        ? __('Portus manifest imported successfully.', 'wicket-portus')
                        : implode(' ', array_map('strval', $errors)),
                ];
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

        echo WarningPrinter::sensitive_data_notice();
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
            'memberships' => 'Wicket Memberships Plugin',
            'gravity_forms_wicket_plugin' => 'Wicket Gravity Forms Plugin',
            'account_centre' => 'Wicket Account Centre Plugin',
            'theme_acf_options' => 'Wicket Theme ACF',
            'site_inventory' => 'WordPress Plugin Inventory',
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
            'site_inventory' => 'Plugin Inventory (status + version checks)',
            default => sprintf('%s (module)', $this->module_group_label($module_key)),
        };
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
        $this->registry->register(new WicketSettingsModule($reader, $transfer));
        $this->registry->register(new MembershipOptionsModule($reader, $transfer));
        $this->registry->register(new WicketGfOptionsModule($reader, $transfer));
        $this->registry->register(new AccCarbonFieldsOptionsModule($reader, $transfer));
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
        $current_mode = isset($_POST['portus_export_mode'])
            ? sanitize_text_field(wp_unslash($_POST['portus_export_mode']))
            : 'template';

        ob_start();
        ?>
        <div class="hyperpress-field-wrapper" style="margin-top: 20px; padding: 12px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Export Mode', 'wicket-portus'); ?></h3>
            <p class="description">
                <?php esc_html_e('Choose how sensitive data should be handled in this export.', 'wicket-portus'); ?>
            </p>

            <fieldset>
                <legend class="screen-reader-text"><?php esc_html_e('Export mode selection', 'wicket-portus'); ?></legend>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="radio" name="portus_export_mode" value="template"
                        <?php checked($current_mode, 'template'); ?>
                        <?php checked($current_mode, ''); // Default to template if not set ?>>
                    <strong><?php esc_html_e('Template (Safe for New Clients)', 'wicket-portus'); ?></strong>
                    <br>
                    <span class="description">
                        <?php esc_html_e('Credentials, API keys, and environment-specific URLs are removed. Use this for onboarding new organisations or cloning configuration.', 'wicket-portus'); ?>
                    </span>
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="radio" name="portus_export_mode" value="full"
                        <?php checked($current_mode, 'full'); ?>
                        data-portus-full-export-toggle>
                    <strong style="color: #d63638;"><?php esc_html_e('Full Export (Includes Credentials)', 'wicket-portus'); ?></strong>
                    <br>
                    <span class="description">
                        <?php esc_html_e('All data including credentials and API keys. Use this for same-client environment sync only.', 'wicket-portus'); ?>
                    </span>
                </label>

                <div id="portus-full-export-gate" class="portus-full-export-confirmation" style="margin-top: 15px; padding: 12px; border-left: 4px solid #d63638; background: #fff5f5; display: <?php echo $current_mode === 'full' ? 'block' : 'none'; ?>;">
                    <p style="margin: 0 0 8px 0; color: #d63638;">
                        <strong><?php esc_html_e('⚠️ Warning: This export contains sensitive data.', 'wicket-portus'); ?></strong>
                    </p>
                    <p style="margin: 0 0 12px 0;" class="description">
                        <?php esc_html_e('API keys, authentication tokens, and environment URLs will be included. Do not share this file outside your organisation or via insecure channels.', 'wicket-portus'); ?>
                    </p>
                    <label style="display: block; margin-top: 8px;">
                        <input type="checkbox" name="portus_full_export_confirm" value="1">
                        <span style="font-weight: 600;">
                            <?php esc_html_e('I understand this file contains sensitive credentials and I will handle it securely.', 'wicket-portus'); ?>
                        </span>
                    </label>
                </div>
            </fieldset>
        </div>

        <script>
        (function () {
            var fullRadio = document.querySelector('input[data-portus-full-export-toggle]');
            var gate = document.getElementById('portus-full-export-gate');
            var confirmation = gate ? gate.querySelector('.portus-full-export-confirmation') : null;
            var confirmCheckbox = confirmation ? confirmation.querySelector('input[type="checkbox"]') : null;
            var exportForm = gate ? gate.closest('form') : null;
            var exportButton = exportForm ? exportForm.querySelector('button[name="hf_export_submit"]') : null;

            if (!fullRadio || !gate || !confirmation || !confirmCheckbox || !exportForm || !exportButton) {
                return;
            }

            // Toggle confirmation visibility when Full is selected.
            fullRadio.addEventListener('change', function () {
                if (this.checked) {
                    gate.style.display = 'block';
                    confirmation.style.display = 'block';
                    confirmCheckbox.checked = false;
                    if (exportButton) {
                        exportButton.disabled = true;
                        exportButton.classList.add('disabled');
                    }
                }
            });

            // Re-enable export button only when checkbox is checked.
            if (confirmCheckbox) {
                confirmCheckbox.addEventListener('change', function () {
                    if (exportButton) {
                        exportButton.disabled = !this.checked;
                        exportButton.classList.toggle('disabled', !this.checked);
                    }
                });
            }

            // Initial state: if full is pre-selected (e.g. form submission error), show confirmation.
            if (fullRadio.checked) {
                gate.style.display = 'block';
                confirmation.style.display = 'block';
                if (exportButton && !confirmCheckbox.checked) {
                    exportButton.disabled = true;
                    exportButton.classList.add('disabled');
                }
            }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
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
