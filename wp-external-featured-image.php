<?php
/**
 * Plugin Name: WP External Featured Image
 * Plugin URI: https://github.com/radialmonster/wp-external-featured-image
 * Description: Use external or Flickr-hosted images as featured images, complete with social meta tags.
 * Version: 1.0.0
 * Author: RadialMonster
 * Text Domain: wp-external-featured-image
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * GitHub Plugin URI: radialmonster/wp-external-featured-image
 * Primary Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'XEFI_PLUGIN_VERSION' ) ) {
    define( 'XEFI_PLUGIN_VERSION', '1.0.0' );
}

define( 'XEFI_PLUGIN_FILE', __FILE__ );
define( 'XEFI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'XEFI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-flickr-resolver.php';
require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-plugin.php';

\XEFI\Plugin::instance()->init();
