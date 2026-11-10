<?php

/**
 * Plugin Name: Wicket Portus
 * Plugin URI:  https://wicket.io
 * Description: Makes Wicket site configuration portable, reviewable, and repeatable.
 * Version:     0.1.9
 * Author:      Wicket Inc.
 * Author URI:  https://wicket.io
 * Text Domain: wicket-portus
 * Requires at least: 6.6
 * Requires PHP: 8.3.
 * Requires Plugins: wicket-wp-account-centre
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WICKET_PORTUS_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('WICKET_PORTUS_DIR', plugin_dir_path(__FILE__));
define('WICKET_PORTUS_URL', plugin_dir_url(__FILE__));
define('WICKET_PORTUS_FILE', __FILE__);

/*
|--------------------------------------------------------------------------
| Dependency: wicket-wp-account-centre
|--------------------------------------------------------------------------
|
| This plugin requires wicket-wp-account-centre to be active. That plugin
| provides the HyperFields\ namespace (Transfer\Manager, Validation\SchemaValidator)
| that Portus relies on. This plugin does not vendor the library itself.
|
| On activation we abort with a clear message if the dependency is missing.
| After activation we bail out of the bootstrap and show an admin notice if
| the dependency is deactivated while Portus is still active.
|
*/

if (!function_exists('wicket_portus_check_account_centre_dependency')) {
    /**
     * Returns true when wicket-wp-account-centre is active.
     */
    function wicket_portus_check_account_centre_dependency(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('wicket-wp-account-centre/class-wicket-acc-main.php');
    }
}

if (!function_exists('wicket_portus_dependency_missing_notice')) {
    /**
     * Renders the admin notice shown when the required plugin is inactive.
     */
    function wicket_portus_dependency_missing_notice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Wicket Portus requires the Wicket Account Centre plugin to be installed and activated.',
            'wicket-portus'
        );
        echo '</p></div>';
    }
}

register_activation_hook(__FILE__, static function (): void {
    if (!wicket_portus_check_account_centre_dependency()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__(
                'Wicket Portus requires the Wicket Account Centre plugin to be installed and activated. Please activate it before enabling this plugin.',
                'wicket-portus'
            ),
            esc_html__('Plugin activation failed', 'wicket-portus'),
            ['back_link' => true]
        );
    }
});

add_action(
    'plugins_loaded',
    static function (): void {
        if (!wicket_portus_check_account_centre_dependency()) {
            add_action('admin_notices', 'wicket_portus_dependency_missing_notice');

            return;
        }

        Wicket_Portus_Bootstrap::get_instance()->plugin_setup();
    },
    99
);

add_filter('hyperfields/export/filename_prefix', fn (): string => 'wicket-portus-export');

final class Wicket_Portus_Bootstrap
{
    /**
     * Plugin instance.
     *
     * @var self|null
     */
    protected static ?self $instance = null;

    /**
     * URL to this plugin directory.
     *
     * @var string
     */
    public string $plugin_url = '';

    /**
     * Absolute path to this plugin directory.
     *
     * @var string
     */
    public string $plugin_path = '';

    /**
     * Intentionally empty constructor.
     */
    public function __construct() {}

    /**
     * Access this plugin's working instance.
     *
     * @return self
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Regular plugin setup.
     *
     * @return void
     */
    public function plugin_setup(): void
    {
        $this->plugin_url = plugins_url('/', __FILE__);
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->load_language('wicket-portus');

        $autoload = $this->plugin_path . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        if (class_exists('\HyperFields\LibraryBootstrap')) {
            HyperFields\LibraryBootstrap::init([
                'plugin_file' => WICKET_PORTUS_FILE,
                'plugin_url'  => WICKET_PORTUS_URL . 'vendor/estebanforge/hyperfields/',
            ]);
        }

        if (class_exists('\WicketPortus\Plugin')) {
            WicketPortus\Plugin::get_instance();
        }
    }

    /**
     * Loads translation file.
     *
     * @param string $domain
     * @return void
     */
    public function load_language(string $domain): void
    {
        load_plugin_textdomain(
            $domain,
            false,
            $this->plugin_path . 'languages'
        );
    }
}
