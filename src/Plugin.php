<?php

declare(strict_types=1);

namespace WicketPortus;

use HyperFields\Admin\ExportImportUI;
use WicketPortus\Contracts\OptionGroupProviderInterface;
use WicketPortus\Manifest\TransferOrchestrator;
use WicketPortus\Modules\AccCarbonFieldsOptionsModule;
use WicketPortus\Modules\MembershipOptionsModule;
use WicketPortus\Modules\PluginInventoryModule;
use WicketPortus\Modules\ThemeAcfOptionsModule;
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

        echo WarningPrinter::sensitive_data_notice();
        echo ExportImportUI::render(
            options: $this->get_data_tools_options(),
            allowedImportOptions: array_keys($this->get_data_tools_options()),
            prefix: '',
            title: __('Portus Export / Import', 'wicket-portus'),
            description: __('Export and import Wicket-managed option groups. Review diffs before confirming import.', 'wicket-portus'),
            exporter: static function () use ($orchestrator): string {
                $manifest = $orchestrator->export();
                $encoded  = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return is_string($encoded) ? $encoded : '';
            },
            previewer: static function (array $decoded, string $jsonString) use ($orchestrator): array {
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
            },
            importer: static function (string $jsonString) use ($orchestrator): array {
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
            },
        );
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
            }
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
        $this->registry->register(new ThemeAcfOptionsModule($reader, $transfer));
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
