<?php
if (!defined('ABSPATH')) exit;

class JOPG_DB {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {}
    
    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Albums table — synced from Lightroom
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $sql_albums = "CREATE TABLE $table_albums (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            lightroom_album_id varchar(255) NOT NULL,
            lightroom_catalog_id varchar(255) DEFAULT '',
            title varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            parent_id varchar(255) DEFAULT '',
            photo_count int(11) DEFAULT 0,
            cover_asset_id varchar(255) DEFAULT '',
            cover_url varchar(500) DEFAULT '',
            gallery_id bigint(20) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY lightroom_album_id (lightroom_album_id),
            KEY slug (slug),
            KEY gallery_id (gallery_id)
        ) $charset;";
        
        // Photos table — individual images
        $table_photos = $wpdb->prefix . 'jopg_photos';
        $sql_photos = "CREATE TABLE $table_photos (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            album_id bigint(20) NOT NULL,
            lightroom_asset_id varchar(255) NOT NULL,
            filename varchar(255) NOT NULL,
            title varchar(255) DEFAULT '',
            caption text DEFAULT '',
            thumb_url varchar(500) DEFAULT '',
            display_url varchar(500) DEFAULT '',
            original_url varchar(500) DEFAULT '',
            width int(11) DEFAULT 0,
            height int(11) DEFAULT 0,
            file_size bigint(20) DEFAULT 0,
            capture_date datetime DEFAULT NULL,
            exif_data longtext DEFAULT NULL,
            wc_product_id bigint(20) DEFAULT 0,
            price decimal(10,2) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY album_id (album_id),
            KEY lightroom_asset_id (lightroom_asset_id),
            KEY wc_product_id (wc_product_id)
        ) $charset;";
        
        // Client selections — proofing links
        $table_selections = $wpdb->prefix . 'jopg_client_selections';
        $sql_selections = "CREATE TABLE $table_selections (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            album_id bigint(20) NOT NULL,
            client_name varchar(255) DEFAULT '',
            client_email varchar(255) DEFAULT '',
            selection_token varchar(64) NOT NULL,
            selected_photos longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY selection_token (selection_token),
            KEY album_id (album_id)
        ) $charset;";
        
        // Settings stored as key-value
        $table_settings = $wpdb->prefix . 'jopg_settings';
        $sql_settings = "CREATE TABLE $table_settings (
            id int(11) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL,
            setting_value longtext DEFAULT NULL,
            autoload tinyint(1) DEFAULT 1,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset;";
        
        // Download tokens — secure expiring links
        $table_downloads = $wpdb->prefix . 'jopg_downloads';
        $sql_downloads = "CREATE TABLE $table_downloads (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            photo_id bigint(20) NOT NULL,
            download_token varchar(64) NOT NULL,
            download_count int(11) DEFAULT 0,
            max_downloads int(11) DEFAULT 0,
            expires_at datetime NOT NULL,
            last_download_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY download_token (download_token),
            KEY order_id (order_id)
        ) $charset;";
        
        // Galleries table — user-created gallery groups for organizing albums
        $table_galleries = $wpdb->prefix . 'jopg_galleries';
        $sql_galleries = "CREATE TABLE $table_galleries (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_albums);
        dbDelta($sql_photos);
        dbDelta($sql_selections);
        dbDelta($sql_settings);
        dbDelta($sql_downloads);
        dbDelta($sql_galleries);
        
        // Migration: add gallery_id column to existing albums table if missing
        $has_gallery_col = $wpdb->get_results("SHOW COLUMNS FROM {$table_albums} LIKE 'gallery_id'");
        if (empty($has_gallery_col)) {
            $wpdb->query("ALTER TABLE {$table_albums} ADD COLUMN gallery_id bigint(20) DEFAULT 0 AFTER cover_url, ADD KEY gallery_id (gallery_id)");
        }
        
        // Default settings
        self::set_setting('watermark_text', 'Jens Ole Photography');
        self::set_setting('watermark_opacity', '30');
        self::set_setting('watermark_position', 'center');
        self::set_setting('single_price', '49');
        self::set_setting('bundle_qty', '5');
        self::set_setting('bundle_price', '200');
        self::set_setting('download_expiry_days', '30');
        self::set_setting('max_downloads', '5');
        self::set_setting('guest_checkout', '1');
        self::set_setting('sync_interval', '6');
        
        add_option('jopg_db_version', JOPG_DB_VERSION);
        
        // Ensure our custom rewrite rules (image proxy, OAuth callback) are registered immediately
        flush_rewrite_rules();
    }
    
    public static function deactivate() {
        // Keep data on deactivation
        wp_clear_scheduled_hook('jopg_sync_lightroom');
        wp_clear_scheduled_hook('jopg_background_prewarm');
        delete_option('jopg_prewarm_job');
    }
    
    public static function get_setting($key, $default = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_settings';
        $val = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $table WHERE setting_key = %s", $key));
        return $val !== null ? $val : $default;
    }
    
    public static function set_setting($key, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_settings';
        $wpdb->replace($table, [
            'setting_key' => $key,
            'setting_value' => maybe_serialize($value),
            'autoload' => 1
        ], ['%s', '%s', '%d']);
    }
    
    public static function get_all_settings() {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_settings';
        $results = $wpdb->get_results("SELECT setting_key, setting_value FROM $table WHERE autoload = 1");
        $settings = [];
        foreach ($results as $row) {
            $settings[$row->setting_key] = maybe_unserialize($row->setting_value);
        }
        return $settings;
    }
}
