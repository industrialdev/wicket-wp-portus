<?php

/**
 * Plugin Name: Wicket Portus
 * Plugin URI:  https://wicket.io
 * Description: Makes Wicket site configuration portable, reviewable, and repeatable.
 * Version:     0.1.0
 * Author:      Wicket Inc.
 * Author URI:  https://wicket.io
 * Text Domain: wicket-portus
 * Requires PHP: 8.2.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WICKET_PORTUS_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('WICKET_PORTUS_DIR', plugin_dir_path(__FILE__));
define('WICKET_PORTUS_URL', plugin_dir_url(__FILE__));
define('WICKET_PORTUS_FILE', __FILE__);

add_action(
    'plugins_loaded',
    [Wicket_Portus_Bootstrap::get_instance(), 'plugin_setup'],
    99
);

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
