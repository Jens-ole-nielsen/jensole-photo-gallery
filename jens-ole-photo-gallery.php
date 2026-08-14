<?php
/**
 * Plugin Name: Jens Ole Photo Gallery
 * Description: Custom photo gallery with Lightroom sync, watermarking, WooCommerce sales, and client selection.
 * Version: 1.1.7
 * Author: Jens Ole Photography
 * Text Domain: jopg
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 */

if (!defined('ABSPATH')) exit;

define('JOPG_VERSION', '1.1.7');
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
        
        // IMPORTANT: instantiate all sub-classes HERE, at plugin-load time —
        // NOT inside an 'init' callback. Each sub-class constructor does its
        // own add_action('init', ...) to register rewrite rules / query vars.
        // If we instead create them from within our own 'init' callback, we'd
        // be adding new callbacks to the 'init' hook WHILE it is already
        // executing — PHP's array pointer during that same do_action() pass
        // may or may not pick up the newly-added callback, so the rewrite
        // rules silently never get registered. Instantiating up-front avoids
        // this entirely: every sub-class's own add_action('init', ...) call
        // is registered well before WordPress fires 'init' for real.
        JOPG_Lightroom::instance();
        JOPG_Watermark::instance();
        JOPG_WooCommerce::instance();
        JOPG_Download::instance();
        JOPG_Client_Selection::instance();
        JOPG_Admin::instance();
        JOPG_Shortcodes::instance();
        JOPG_Updater::instance();
        
        add_action('init', [$this, 'maybe_flush_rewrite_rules']);
    }
    
    public function maybe_flush_rewrite_rules() {
        // WordPress does NOT fire register_activation_hook when a plugin is
        // updated via "Update now" / auto-update — only on manual activation.
        // Our custom rewrite rules (image proxy, OAuth callback, client selection)
        // need flushing whenever the plugin version changes, or the rules can go
        // stale after an update and cause 404s on all image/proxy URLs.
        $flushed_version = get_option('jopg_flushed_version', '');
        if ($flushed_version !== JOPG_VERSION) {
            flush_rewrite_rules();
            update_option('jopg_flushed_version', JOPG_VERSION);
        }
    }
}

Jens_Ole_Photo_Gallery::instance();
