<?php
if (!defined('ABSPATH')) exit;

class JOPG_Client_Selection {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        // AJAX for saving selections
        add_action('wp_ajax_jopg_save_selection', [$this, 'ajax_save_selection']);
        add_action('wp_ajax_nopriv_jopg_save_selection', [$this, 'ajax_save_selection']);
        
        // REST endpoint
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Create a client selection link
     */
    public static function create_link($album_id, $client_name, $client_email = '', $expires_days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_client_selections';
        
        $token = wp_generate_uuid4();
        $expires = date('Y-m-d H:i:s', time() + ($expires_days * DAY_IN_SECONDS));
        
        $wpdb->insert($table, [
            'album_id' => $album_id,
            'client_name' => $client_name,
            'client_email' => $client_email,
            'selection_token' => $token,
            'status' => 'pending',
            'expires_at' => $expires
        ], ['%d', '%s', '%s', '%s', '%s', '%s']);
        
        return [
            'token' => $token,
            'url' => home_url('/photo-selection/' . $token)
        ];
    }
    
    /**
     * Get a selection by token
     */
    public static function get_by_token($token) {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_client_selections';
        
        $selection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE selection_token = %s", $token
        ));
        
        if (!$selection) return null;
        if (strtotime($selection->expires_at) < time()) return null;
        
        return $selection;
    }
    
    /**
     * Save selected photos
     */
    public static function save_selection($token, $photo_ids) {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_client_selections';
        
        $selection = self::get_by_token($token);
        if (!$selection) return false;
        
        $wpdb->update($table, [
            'selected_photos' => json_encode($photo_ids),
            'status' => 'completed',
        ], ['id' => $selection->id], ['%s', '%s']);
        
        // Notify photographer
        self::notify_photographer($selection, $photo_ids);
        
        return true;
    }
    
    /**
     * Notify photographer of selection
     */
    private static function notify_photographer($selection, $photo_ids) {
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", $selection->album_id));
        
        $admin_email = get_option('admin_email');
        $subject = 'New photo selection from ' . $selection->client_name;
        
        $selection_url = admin_url('admin.php?page=jopg-selections&action=view&id=' . $selection->id);
        
        $message = '<html><body style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">';
        $message .= '<h2>New Photo Selection</h2>';
        $message .= '<p><strong>Client:</strong> ' . esc_html($selection->client_name) . '</p>';
        if ($selection->client_email) {
            $message .= '<p><strong>Email:</strong> ' . esc_html($selection->client_email) . '</p>';
        }
        $message .= '<p><strong>Album:</strong> ' . esc_html($album ? $album->title : 'Unknown') . '</p>';
        $message .= '<p><strong>Photos selected:</strong> ' . count($photo_ids) . '</p>';
        $message .= '<p><a href="' . $selection_url . '">View selection in admin →</a></p>';
        $message .= '</body></html>';
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($admin_email, $subject, $message, $headers);
    }
    
    /**
     * Register REST routes
     */
    public function register_routes() {
        register_rest_route('jopg/v1', '/selection/(?P<token>[a-f0-9-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_selection'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('jopg/v1', '/selection/save', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_save_selection'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    public function rest_get_selection($request) {
        $token = $request['token'];
        $selection = self::get_by_token($token);
        
        if (!$selection) {
            return new WP_REST_Response(['error' => 'Invalid or expired link'], 404);
        }
        
        global $wpdb;
        $table_photos = $wpdb->prefix . 'jopg_photos';
        $photos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, filename, title, thumb_url, display_url FROM $table_photos WHERE album_id = %d ORDER BY filename ASC",
            $selection->album_id
        ));
        
        $selected = json_decode($selection->selected_photos ?? '[]', true);
        
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
        
        $watermarked_urls = array_map(function($p) use ($upload_dir, $cache_dir) {
            // Use cached thumbnail if available (instant load)
            $thumb_cache_file = $cache_dir . '/wm_thumb_out_' . $p->id . '.jpg';
            if (file_exists($thumb_cache_file) && filesize($thumb_cache_file) > 100) {
                $thumb_url = $upload_dir['baseurl'] . '/jopg-cache/wm_thumb_out_' . $p->id . '.jpg';
            } else {
                $thumb_url = JOPG_Watermark::get_thumb_url($p->id);
            }
            
            // Full-size watermarked URL for lightbox (also check cache)
            $full_cache_file = $cache_dir . '/wm_out_' . $p->id . '.jpg';
            if (file_exists($full_cache_file) && filesize($full_cache_file) > 100) {
                $full_url = $upload_dir['baseurl'] . '/jopg-cache/wm_out_' . $p->id . '.jpg';
            } else {
                $full_url = JOPG_Watermark::get_watermarked_url($p->id);
            }
            
            return [
                'id' => $p->id,
                'thumb_url' => $thumb_url,
                'full_url' => $full_url,
                'url' => $thumb_url,  // backward compat
            ];
        }, $photos);
        
        return [
            'selection' => $selection,
            'photos' => $photos,
            'selected' => $selected,
            'watermarked_urls' => $watermarked_urls
        ];
    }
    
    public function rest_save_selection($request) {
        $token = sanitize_text_field($request->get_param('token'));
        $photo_ids = $request->get_param('photo_ids');
        
        if (!is_array($photo_ids)) {
            $photo_ids = array_map('intval', explode(',', $photo_ids));
        } else {
            $photo_ids = array_map('intval', $photo_ids);
        }
        
        $success = self::save_selection($token, $photo_ids);
        
        if ($success) {
            return ['success' => true, 'selected_count' => count($photo_ids)];
        }
        
        return new WP_REST_Response(['error' => 'Could not save selection'], 400);
    }
    
    /**
     * AJAX save selection
     */
    public function ajax_save_selection() {
        $token = sanitize_text_field($_POST['token'] ?? '');
        $photo_ids = isset($_POST['photo_ids']) ? array_map('intval', (array)$_POST['photo_ids']) : [];
        
        $success = self::save_selection($token, $photo_ids);
        
        if ($success) {
            wp_send_json_success(['selected_count' => count($photo_ids)]);
        } else {
            wp_send_json_error('Could not save selection');
        }
    }
}
