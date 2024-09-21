<?php

namespace GpcDev\Includes;

use GpcDev\Admin\PageCreatePage;
use GpcDev\Admin\PageCreatePost;
use GpcDev\Admin\PageCreateProduct;
use GpcDev\Admin\PageCreateTerm;

// Block direct access to file
defined( 'ABSPATH' ) or die( 'Not Authorized!' );

class PluginInit
{
    public function __construct() {

        // Plugin uninstall hook
        register_uninstall_hook( GPC_DEV_FILE, array('Gpc_Dev_Tools', 'plugin_uninstall') );

        // Plugin activation/deactivation hooks
        register_activation_hook( GPC_DEV_FILE, array($this, 'plugin_activate') );
        register_deactivation_hook( GPC_DEV_FILE, array($this, 'plugin_deactivate') );

        // Plugin Actions
        add_action( 'plugins_loaded', array($this, 'plugin_init') );
        add_action( 'wp_enqueue_scripts', array($this, 'plugin_enqueue_scripts') );
        add_action( 'admin_enqueue_scripts', array($this, 'plugin_enqueue_admin_scripts') );
        add_action( 'admin_menu', array($this, 'plugin_admin_menu_function') );

    }

    public static function plugin_uninstall() { }

    /**
     * Plugin activation function
     * called when the plugin is activated
     * @method plugin_activate
     */
    public function plugin_activate() { }

    /**
     * Plugin deactivate function
     * is called during plugin deactivation
     * @method plugin_deactivate
     */
    public function plugin_deactivate() { }

    /**
     * Plugin init function
     * init the polugin textDomain
     * @method plugin_init
     */
    function plugin_init() {
        // before all load plugin text domain
        load_plugin_textDomain( GPC_DEV_TEXT_DOMAIN, false, dirname(GPC_DEV_DIRECTORY_BASENAME) . '/languages' );

        new PageCreateTerm;
        new PageCreatePage;
        new PageCreatePost;

        if ( class_exists( 'woocommerce' ) ) {
            new PageCreateProduct;
        }
    }

    function plugin_admin_menu_function() {

        //create main top-level menu with empty content
        add_menu_page(
            __('GPC Tools', GPC_DEV_TEXT_DOMAIN), // Page title
            __('GPC Tools', GPC_DEV_TEXT_DOMAIN), // Menu title
            'administrator', // capability
            'gpc-dev-general', //menu-slug
            null, // callback
            'dashicons-admin-generic', // icon
            4 // position
        );

        // create top level submenu page which point to main menu page
        add_submenu_page(
            'gpc-dev-general', // parent slug
            __('General', GPC_DEV_TEXT_DOMAIN), // Page title
            __('General', GPC_DEV_TEXT_DOMAIN), // Menu title
            'manage_options', // capability
            'gpc-dev-general', // menu slug
            array($this, 'plugin_settings_page') // callback
        );

    	//call register settings function
    	add_action( 'admin_init', array($this, 'plugin_register_settings') );

    }

    /**
     * Register the main Plugin Settings
     * @method plugin_register_settings
     */
    function plugin_register_settings() {
        register_setting( 'gpc-dev-settings-group', 'example_option' );
    	register_setting( 'gpc-dev-settings-group', 'another_example_option' );
    }

    /**
     * Enqueue the main Plugin admin scripts and styles
     * @method plugin_enqueue_scripts
     */
    function plugin_enqueue_admin_scripts($hook)
    {
        $pages = apply_filters('gpc_dev_admin_pages', []);
        if (! in_array($hook, $pages) ) return;

        wp_enqueue_script('gpc-alpine', '//cdnjs.cloudflare.com/ajax/libs/alpinejs/3.14.1/cdn.js', [], '3.14.1', ['strategy' => 'defer']);

        wp_enqueue_script('gpc-slimselect', GPC_DEV_DIRECTORY_URL . '/assets/js/slimselect/slimselect.min.js', array(), null, true );
        wp_enqueue_style('gpc-slimselect', GPC_DEV_DIRECTORY_URL . '/assets/js/slimselect/slimselect.css', array(), null, 'all' );

        wp_register_style( 'gpc-dev-admin-style', GPC_DEV_DIRECTORY_URL . '/assets/css/admin-style.css', array(), filemtime(GPC_DEV_DIRECTORY_PATH . '/assets/css/admin-style.css') );
        wp_register_script( 'gpc-dev-admin-script', GPC_DEV_DIRECTORY_URL . '/assets/js/admin-script.js', array(), null, true );

        wp_enqueue_media();

        wp_enqueue_script('jquery');
        wp_enqueue_style('gpc-dev-admin-style');
        wp_enqueue_script('gpc-dev-admin-script');
    }

    /**
     * Enqueue the main Plugin user scripts and styles
     * @method plugin_enqueue_scripts
     */
    function plugin_enqueue_scripts() {
        wp_register_style( 'gpc-dev-user-style', GPC_DEV_DIRECTORY_URL . '/assets/css/user-style.css', array(), null );
        wp_register_script( 'gpc-dev-user-script', GPC_DEV_DIRECTORY_URL . '/assets/js/user-script.min.js', array(), null, true );
        wp_enqueue_script('jquery');
        wp_enqueue_style('gpc-dev-user-style');
        wp_enqueue_script('gpc-dev-user-script');
    }

    /**
     * Plugin main settings page
     * @method plugin_settings_page
     */
    function plugin_settings_page() { ?>

        <div class="wrap card">
            <h1><?php _e( 'WordPress Plugin Starter', GPC_DEV_TEXT_DOMAIN ); ?></h1>

            <p><?php _e( 'Welcome to WordPress Plugin Starter, a fast way to start building your plugins without create every time the project structure.', GPC_DEV_TEXT_DOMAIN ); ?></p>

        </div>

    <?php }

    /**
     * Plugin support page
     * in this page there are listed some useful debug informations
     * and a quick link to write a mail to the plugin author
     * @method plugin_support_page
     */
    function plugin_support_page() {

        global $wpdb, $wp_version;
        $plugin = get_plugin_data( GPC_DEV_FILE, true, true );
        $wptheme = wp_get_theme();
        $current_user = wp_get_current_user();

        // set the user full name for the support request
        $user_fullname = ($current_user->user_firstname || $current_user->user_lastname) ?
        	($current_user->user_lastname . ' ' . $current_user->user_firstname) : $current_user->display_name;    ?>

        <div class="wrap card">

			<!-- support page title -->
			<h1><?php _e( 'Plugin Starter Support', GPC_DEV_TEXT_DOMAIN ); ?></h1>

            <!-- support page description -->
			<p><?php _e( 'Please report this information when requesting support via mail.', GPC_DEV_TEXT_DOMAIN ); ?></p>

			<div class="support-debug">

				<div class="plugin">

					<ul>
						<li class="support-plugin-version"><strong><?php _e($plugin['Name']); ?></strong> version: <?php _e($plugin['Version']); ?></li>
						<li class="support-credits"><?php _e( 'Plugin author:', GPC_DEV_TEXT_DOMAIN ); ?> <a href="<?php echo $plugin['AuthorURI']; ?>"><?php echo $plugin['AuthorName']; ?></a></li>
					</ul>

				</div>

				<div class="theme">

					<ul>
						<li class="support-theme-version"><?php printf( _('Active theme %s version: %s', GPC_DEV_TEXT_DOMAIN), $wptheme->Name, $wptheme->Version ); ?></li>
					</ul>

				</div>

				<div class="system">

					<ul>
						<li class="support-php-version"><?php _e( 'PHP version:', GPC_DEV_TEXT_DOMAIN ); ?> <?php _e(PHP_VERSION); ?></li>
						<li class="support-mysql-version"><?php _e( 'MySQL version:', GPC_DEV_TEXT_DOMAIN ); ?> <?php _e( mysqli_get_server_info( $wpdb->dbh ) ); ?></li>
						<li class="support-wp-version"><?php _e( 'WordPress version:', GPC_DEV_TEXT_DOMAIN ); ?> <?php _e($wp_version); ?></li>
					</ul>

				</div>

			</div>

            <div class="support-action">
                <button type="button" class="button" name="Send Mail">
                    <a style="text-decoration: none" href="mailto:someone@example.com?Subject=Plugin%20Support">Mail Me</a>
                </button>
            </div>

        </div>

    <?php }

}


