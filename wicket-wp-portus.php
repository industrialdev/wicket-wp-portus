<?php
/**
 * Plugin Name: Wicket Portus
 * Plugin URI:  https://wicket.io
 * Description: Makes Wicket site configuration portable, reviewable, and repeatable.
 * Version:     0.1.0
 * Author:      Wicket Inc.
 * Author URI:  https://wicket.io
 * Text Domain: wicket-portus
 * Requires PHP: 8.2
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WICKET_PORTUS_VERSION', '0.1.0' );
define( 'WICKET_PORTUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WICKET_PORTUS_URL', plugin_dir_url( __FILE__ ) );
define( 'WICKET_PORTUS_FILE', __FILE__ );

if ( file_exists( WICKET_PORTUS_DIR . 'vendor/autoload.php' ) ) {
	require_once WICKET_PORTUS_DIR . 'vendor/autoload.php';
}

add_action( 'plugins_loaded', [ \WicketPortus\Plugin::class, 'get_instance' ], 99 );
