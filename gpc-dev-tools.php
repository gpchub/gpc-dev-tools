<?php
/**
 * GPC Dev tools
 *
 * @package       GPCDT
 * @author        Jon Doe
 * @version       1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:   GPC Dev tools
 * Plugin URI:    https://mydomain.com
 * Description:   This is some demo short description...
 * Version:       1.0.0
 * Author:        Jon Doe
 * Author URI:    https://your-author-domain.com
 * Text Domain:   gpc-dev-tools
 * Domain Path:   /languages
 */

use GpcDev\Includes\PluginInit;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin Defines
define( "GPC_DEV_FILE", __FILE__ );
define( "GPC_DEV_DIRECTORY", dirname(__FILE__) );
define( "GPC_DEV_TEXT_DOMAIN", dirname(__FILE__) );
define( "GPC_DEV_DIRECTORY_BASENAME", plugin_basename( GPC_DEV_FILE ) );
define( "GPC_DEV_DIRECTORY_PATH", plugin_dir_path( GPC_DEV_FILE ) );
define( "GPC_DEV_DIRECTORY_URL", plugins_url( null, GPC_DEV_FILE ) );

// Require the main class file
require_once( __DIR__.'/vendor/autoload.php' );
new PluginInit;