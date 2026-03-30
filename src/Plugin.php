<?php

declare(strict_types=1);

namespace WicketPortus;

use HyperFields\Admin\ExportImportUI;
use WicketPortus\Modules\MembershipOptionsModule;
use WicketPortus\Modules\WicketGfOptionsModule;
use WicketPortus\Modules\WicketSettingsModule;
use WicketPortus\Registry\ModuleRegistry;
use WicketPortus\Support\WordPressOptionReader;

/**
 * Main plugin class.
 *
 * Bootstraps Portus on the plugins_loaded hook, builds the module registry,
 * and registers the core settings adapters. Additional modules (ACC, theme,
 * plugin inventory) are registered by other team members via the
 * wicket_portus_register_modules action.
 */
class Plugin
{
    private static ?Plugin $instance = null;

    private ModuleRegistry $registry;

    private function __construct() {}

    /**
     * Returns the single plugin instance, creating and booting it on first call.
     *
     * Uses late static binding (new static()) so subclasses can extend without
     * overriding this method.
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
     * Initialises the module registry, registers core modules, then fires the
     * extension hook so other plugin files can add their own adapters.
     */
    private function boot(): void
    {
        $this->registry = new ModuleRegistry();
        $this->register_modules();

        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_portus_data_tools_page'], 99);
        }

        /*
         * Fires after core modules are registered.
         * Other plugins (Marlon's ACC/theme adapters, Esteban's inventory module)
         * use this hook to register additional modules.
         *
         * @param ModuleRegistry $registry
         */
        do_action('wicket_portus_register_modules', $this->registry);
    }

    /**
     * Registers the Portus export/import page using HyperFields' built-in UI.
     *
     * Parent menu is the Wicket base plugin top-level slug (`wicket-settings`).
     * If unavailable, falls back to Settings to keep the page reachable.
     *
     * @return void
     */
    public function register_portus_data_tools_page(): void
    {
        if (!class_exists(ExportImportUI::class)) {
            add_action('admin_notices', [$this, 'render_missing_hyperfields_notice']);

            return;
        }

        $options = $this->get_data_tools_options();

        ExportImportUI::registerPage(
            parentSlug: $this->resolve_parent_menu_slug(),
            pageSlug: 'wicket-portus-data-tools',
            options: $options,
            allowedImportOptions: array_keys($options),
            prefix: '',
            title: __('Portus Export / Import', 'wicket-portus'),
            capability: 'manage_options',
            description: __('Export and import Wicket-managed option groups.', 'wicket-portus')
        );
    }

    /**
     * Emits admin warning when HyperFields isn't loadable.
     *
     * @return void
     */
    public function render_missing_hyperfields_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Wicket Portus requires HyperFields to load the export/import admin page.', 'wicket-portus');
        echo '</p></div>';
    }

    /**
     * Returns option names exposed in the first-pass Portus data tools UI.
     *
     * @return array<string, string>
     */
    private function get_data_tools_options(): array
    {
        $options = [
            'wicket_settings' => 'Wicket Base Settings',
            'wicket_membership_plugin_options' => 'Wicket Memberships Plugin Options',
            'wicket_gf_slug_mapping' => 'Wicket GF Slug Mapping',
            'wicket_gf_pagination_sidebar_layout' => 'Wicket GF Pagination Sidebar Layout',
            'wicket_gf_member_fields' => 'Wicket GF Member Fields',
        ];

        /**
         * Allows additional option groups to be added to the Portus data tools UI.
         *
         * @param array<string, string> $options
         */
        $options = apply_filters('wicket_portus_data_tools_options', $options);

        return is_array($options) ? $options : [];
    }

    /**
     * Resolves the admin parent slug for the Portus submenu page.
     *
     * @return string
     */
    private function resolve_parent_menu_slug(): string
    {
        global $menu;

        if (is_array($menu)) {
            foreach ($menu as $menu_item) {
                if (isset($menu_item[2]) && $menu_item[2] === 'wicket-settings') {
                    return 'wicket-settings';
                }
            }
        }

        return 'options-general.php';
    }

    /**
     * Registers the settings adapters owned by Alex:
     * wicket_settings, memberships, and Wicket GF plugin options.
     */
    private function register_modules(): void
    {
        $reader = new WordPressOptionReader();

        $this->registry->register(new WicketSettingsModule($reader));
        $this->registry->register(new MembershipOptionsModule($reader));
        $this->registry->register(new WicketGfOptionsModule($reader));
    }

    /**
     * Returns the module registry.
     *
     * Used by Esteban's ManifestBuilder and admin page controller to iterate
     * all registered modules for export, validate, and import operations.
     *
     * @return ModuleRegistry
     */
    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }
}
