<?php
if (!defined('ABSPATH')) exit;

class JOPG_Download {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        // AJAX: check download status
        add_action('wp_ajax_jopg_download_status', [$this, 'ajax_download_status']);
        add_action('wp_ajax_nopriv_jopg_download_status', [$this, 'ajax_download_status']);
    }
    
    /**
     * Get all download links for an order
     */
    public static function get_order_downloads($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_downloads';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, p.filename, p.width, p.height 
             FROM $table d 
             JOIN $table_photos p ON d.photo_id = p.id 
             WHERE d.order_id = %d 
             ORDER BY p.filename ASC", $order_id
        ));
    }
    
    /**
     * Check if a download token is valid
     */
    public static function is_token_valid($token, $photo_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_downloads';
        
        $dl = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE download_token = %s AND photo_id = %d",
            $token, $photo_id
        ));
        
        if (!$dl) return false;
        if (strtotime($dl->expires_at) < time()) return false;
        if ($dl->max_downloads > 0 && $dl->download_count >= $dl->max_downloads) return false;
        
        return true;
    }
    
    /**
     * AJAX: Get download status
     */
    public function ajax_download_status() {
        $token = sanitize_text_field($_GET['token'] ?? '');
        $photo_id = intval($_GET['photo_id'] ?? 0);
        
        $valid = self::is_token_valid($token, $photo_id);
        
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_downloads';
        $dl = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE download_token = %s", $token
        ));
        
        wp_send_json([
            'valid' => $valid,
            'downloads_used' => $dl ? $dl->download_count : 0,
            'max_downloads' => $dl ? $dl->max_downloads : 0,
            'expires' => $dl ? $dl->expires_at : null,
            'expired' => $dl ? (strtotime($dl->expires_at) < time()) : true
        ]);
    }
    
    /**
     * Clean up expired download tokens (run via cron)
     */
    public static function cleanup_expired() {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_downloads';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE expires_at < %s",
            current_time('mysql')
        ));
    }
}
