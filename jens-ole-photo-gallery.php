<?php
/**
 * Plugin Name: Jens Ole Photo Gallery
 * Description: Custom photo gallery with Lightroom sync, watermarking, WooCommerce sales, and client selection.
 * Version: 1.1.0
 * Author: Jens Ole Photography
 * Text Domain: jopg
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 */

if (!defined('ABSPATH')) exit;

define('JOPG_VERSION', '1.1.0');
define('JOPG_PATH', plugin_dir_path(__FILE__));
define('JOPG_URL', plugin_dir_url(__FILE__));
define('JOPG_DB_VERSION', '1.0');

// Load classes
require_once JOPG_PATH . 'includes/class-db.php';
require_once JOPG_PATH . 'includes/class-lightroom.php';
require_once JOPG_PATH . 'includes/class-watermark.php';
require_once JOPG_PATH . 'includes/class-woocommerce.php';
require_once JOPG_PATH . 'includes/class-download.php';
require_once JOPG_PATH . 'includes/class-client-selection.php';
require_once JOPG_PATH . 'includes/class-admin.php';
require_once JOPG_PATH . 'includes/class-shortcodes.php';
require_once JOPG_PATH . 'includes/class-updater.php';

class Jens_Ole_Photo_Gallery {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, [JOPG_DB::class, 'activate']);
        register_deactivation_hook(__FILE__, [JOPG_DB::class, 'deactivate']);
        
        add_action('init', [$this, 'init']);
    }
    
    public function init() {
        JOPG_Lightroom::instance();
        JOPG_Watermark::instance();
        JOPG_WooCommerce::instance();
        JOPG_Download::instance();
        JOPG_Client_Selection::instance();
        JOPG_Admin::instance();
        JOPG_Shortcodes::instance();
        JOPG_Updater::instance();
    }
}

Jens_Ole_Photo_Gallery::instance();
