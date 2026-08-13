<?php
if (!defined('ABSPATH')) exit;

class JOPG_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
    }
    
    public function add_menu_pages() {
        add_menu_page(
            'Jens Ole Gallery',
            'Photo Gallery',
            'manage_options',
            'jopg',
            [$this, 'render_albums_page'],
            'dashicons-camera',
            30
        );
        
        add_submenu_page('jopg', 'Albums', 'Albums', 'manage_options', 'jopg', [$this, 'render_albums_page']);
        add_submenu_page('jopg', 'Settings', 'Settings', 'manage_options', 'jopg-settings', [$this, 'render_settings_page']);
        add_submenu_page('jopg', 'Client Selections', 'Client Selections', 'manage_options', 'jopg-selections', [$this, 'render_selections_page']);
        add_submenu_page('jopg', 'Lightroom Sync', 'Lightroom Sync', 'manage_options', 'jopg-sync', [$this, 'render_sync_page']);
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'jopg') === false) return;
        
        wp_enqueue_style('jopg-admin', JOPG_URL . 'assets/css/admin.css', [], JOPG_VERSION);
        wp_enqueue_script('jopg-admin', JOPG_URL . 'assets/js/admin.js', ['jquery'], JOPG_VERSION, true);
        
        wp_localize_script('jopg-admin', 'jopg_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jopg_admin'),
            'rest_url' => rest_url('jopg/v1/'),
        ]);
    }
    
    public function handle_admin_actions() {
        if (!isset($_GET['page']) || strpos($_GET['page'], 'jopg') !== 0) return;
        if (!current_user_can('manage_options')) return;
        
        // Save settings
        if (isset($_POST['jopg_save_settings'])) {
            check_admin_referer('jopg_settings');
            
            $settings = ['adobe_client_id', 'adobe_client_secret', 'watermark_text', 
                         'watermark_opacity', 'watermark_position',
                         'single_price', 'bundle_qty', 'bundle_price',
                         'download_expiry_days', 'max_downloads', 'guest_checkout',
                         'sync_interval'];
            
            foreach ($settings as $key) {
                if (isset($_POST[$key])) {
                    JOPG_DB::set_setting($key, sanitize_text_field($_POST[$key]));
                }
            }
            
            // Reschedule sync
            $interval = intval(JOPG_DB::get_setting('sync_interval', '6'));
            if (!wp_next_scheduled('jopg_sync_lightroom')) {
                wp_schedule_event(time() + 300, "every_{$interval}_hours", 'jopg_sync_lightroom');
            }
            
            // Re-flush rewrite rules — makes sure our custom URLs (OAuth callback, image proxy) work
            // even if the site owner didn't manually save Permalinks after updating credentials
            flush_rewrite_rules();
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
            });
        }
        
        // Create selection link
        if (isset($_POST['jopg_create_selection'])) {
            check_admin_referer('jopg_selection');
            
            $album_id = intval($_POST['album_id']);
            $client_name = sanitize_text_field($_POST['client_name']);
            $client_email = sanitize_email($_POST['client_email']);
            $expires_days = intval($_POST['expires_days'] ?? 30);
            
            $link = JOPG_Client_Selection::create_link($album_id, $client_name, $client_email, $expires_days);
            
            add_action('admin_notices', function() use ($link) {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo 'Client selection link created: <a href="' . esc_url($link['url']) . '" target="_blank">' . esc_url($link['url']) . '</a>';
                echo '</p></div>';
            });
        }
    }
    
    public function render_albums_page() {
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        // Handle "View Photos" for a specific album
        if (isset($_GET['action']) && $_GET['action'] === 'view_album' && isset($_GET['album_id'])) {
            $this->render_album_photos(intval($_GET['album_id']));
            return;
        }
        
        $albums = $wpdb->get_results("SELECT a.*, 
            (SELECT COUNT(*) FROM {$table_photos} p WHERE p.album_id = a.id) as local_photo_count
            FROM $table_albums a ORDER BY a.synced_at DESC");
        
        ?>
        <div class="wrap jopg-admin">
            <h1>Photo Albums</h1>
            <p>Albums synced from Adobe Lightroom Cloud.</p>
            
            <div class="jopg-actions">
                <button class="button button-primary" id="jopg-sync-now">🔄 Sync Albums from Lightroom</button>
            </div>
            
            <?php if (empty($albums)): ?>
                <div class="jopg-empty">
                    <p>No albums found. Click "Sync Albums from Lightroom" to fetch your Lightroom albums.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Album</th>
                            <th>Lightroom Photos</th>
                            <th>Imported</th>
                            <th>Last Synced</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($albums as $album): ?>
                            <tr>
                                <td><strong><?php echo esc_html($album->title); ?></strong></td>
                                <td><?php echo $album->photo_count; ?></td>
                                <td><?php echo $album->local_photo_count; ?></td>
                                <td><?php echo $album->synced_at ?: '—'; ?></td>
                                <td>
                                    <button class="button button-small jopg-import-album" 
                                            data-album-id="<?php echo $album->id; ?>">
                                        📥 Import Photos
                                    </button>
                                    <a class="button button-small" href="<?php echo admin_url('admin.php?page=jopg&action=view_album&album_id=' . $album->id); ?>">
                                        View Photos
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Show all imported photos in an album (admin view)
     */
    public function render_album_photos($album_id) {
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", $album_id));
        if (!$album) {
            echo '<div class="wrap"><p>Album not found.</p></div>';
            return;
        }
        
        $photos = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_photos WHERE album_id = %d ORDER BY filename ASC", $album_id
        ));
        
        ?>
        <div class="wrap jopg-admin">
            <h1><?php echo esc_html($album->title); ?> — <?php echo count($photos); ?> photos</h1>
            <p>
                <a href="<?php echo admin_url('admin.php?page=jopg'); ?>" class="button">← Back to Albums</a>
                <button class="button button-small jopg-import-album" data-album-id="<?php echo $album->id; ?>">🔄 Re-import from Lightroom</button>
            </p>
            
            <?php if (empty($photos)): ?>
                <div class="jopg-empty">
                    <p>No photos imported yet. Click "Import Photos" on the album list to fetch images from Lightroom.</p>
                </div>
            <?php else: ?>
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:12px; margin-top:20px;">
                    <?php foreach ($photos as $photo): 
                        $wm_url = JOPG_Watermark::get_watermarked_url($photo->id);
                    ?>
                        <div style="border:1px solid #ddd; border-radius:6px; overflow:hidden; background:#1a1a1a;">
                            <img src="<?php echo esc_url($wm_url); ?>" 
                                 alt="<?php echo esc_attr($photo->filename); ?>"
                                 loading="lazy"
                                 style="width:100%; aspect-ratio:3/2; object-fit:cover; display:block;">
                            <div style="padding:6px 8px; font-size:11px; color:#999;">
                                <?php echo esc_html($photo->filename); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function render_settings_page() {
        $s = JOPG_DB::get_all_settings();
        ?>
        <div class="wrap jopg-admin">
            <h1>Gallery Settings</h1>
            <form method="post" action="" autocomplete="off">
                <?php wp_nonce_field('jopg_settings'); ?>
                
                <h3>Adobe Lightroom API</h3>
                <table class="form-table">
                    <tr>
                        <th>Client ID</th>
                        <td>
                            <!-- Dummy hidden fields to absorb Chrome's autofill (it targets the first text+password pair it finds) -->
                            <input type="text" style="display:none" autocomplete="username">
                            <input type="password" style="display:none" autocomplete="new-password">
                            <input type="text" name="adobe_client_id" class="regular-text" 
                                autocomplete="off" data-lpignore="true" data-1p-ignore
                                readonly onfocus="this.removeAttribute('readonly');"
                                value="<?php echo esc_attr($s['adobe_client_id'] ?? ''); ?>" placeholder="From Adobe Developer Console"></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td><input type="text" name="adobe_client_secret" class="regular-text" 
                            autocomplete="off" data-lpignore="true" data-1p-ignore
                            readonly onfocus="this.removeAttribute('readonly');"
                            style="font-family:monospace; -webkit-text-security: disc;"
                            value="<?php echo esc_attr($s['adobe_client_secret'] ?? ''); ?>" placeholder="From Adobe Developer Console"></td>
                    </tr>
                    <tr>
                        <th>Auto-sync interval (hours)</th>
                        <td><input type="number" name="sync_interval" min="1" max="24"
                            value="<?php echo esc_attr($s['sync_interval'] ?? '6'); ?>"></td>
                    </tr>
                </table>
                
                <h3>Watermark</h3>
                <table class="form-table">
                    <tr>
                        <th>Watermark text</th>
                        <td><input type="text" name="watermark_text" class="regular-text" 
                            value="<?php echo esc_attr($s['watermark_text'] ?? 'Jens Ole Photography'); ?>"></td>
                    </tr>
                    <tr>
                        <th>Opacity (0-100, lower = more transparent)</th>
                        <td><input type="number" name="watermark_opacity" min="0" max="100"
                            value="<?php echo esc_attr($s['watermark_opacity'] ?? '30'); ?>"></td>
                    </tr>
                    <tr>
                        <th>Position</th>
                        <td>
                            <select name="watermark_position">
                                <?php
                                $positions = ['center' => 'Center', 'bottom-right' => 'Bottom Right', 
                                              'bottom-left' => 'Bottom Left', 'top-right' => 'Top Right', 'top-left' => 'Top Left'];
                                $current = $s['watermark_position'] ?? 'center';
                                foreach ($positions as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php selected($current, $val); ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h3>Pricing</h3>
                <table class="form-table">
                    <tr>
                        <th>Single photo price (kr)</th>
                        <td><input type="number" name="single_price" step="0.01"
                            value="<?php echo esc_attr($s['single_price'] ?? '49'); ?>"></td>
                    </tr>
                    <tr>
                        <th>Bundle quantity</th>
                        <td><input type="number" name="bundle_qty" min="2"
                            value="<?php echo esc_attr($s['bundle_qty'] ?? '5'); ?>"></td>
                    </tr>
                    <tr>
                        <th>Bundle price (kr)</th>
                        <td><input type="number" name="bundle_price" step="0.01"
                            value="<?php echo esc_attr($s['bundle_price'] ?? '200'); ?>"></td>
                    </tr>
                </table>
                
                <h3>Downloads</h3>
                <table class="form-table">
                    <tr>
                        <th>Download link expiry (days)</th>
                        <td><input type="number" name="download_expiry_days" min="1"
                            value="<?php echo esc_attr($s['download_expiry_days'] ?? '30'); ?>"></td>
                    </tr>
                    <tr>
                        <th>Max downloads per photo</th>
                        <td><input type="number" name="max_downloads" min="0" 
                            value="<?php echo esc_attr($s['max_downloads'] ?? '5'); ?>">
                            <small>0 = unlimited</small></td>
                    </tr>
                    <tr>
                        <th>Allow guest checkout</th>
                        <td>
                            <label><input type="checkbox" name="guest_checkout" value="1"
                                <?php checked($s['guest_checkout'] ?? '1', '1'); ?>> Yes, let customers buy without creating an account</label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="jopg_save_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
        <?php
    }
    
    public function render_sync_page() {
        $lr = JOPG_Lightroom::instance();
        $client_id = JOPG_DB::get_setting('adobe_client_id', '');
        $client_secret = JOPG_DB::get_setting('adobe_client_secret', '');
        $is_connected = $lr->is_connected();
        
        if (isset($_GET['connected'])) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Successfully connected to Adobe Lightroom!</p></div>';
        }
        ?>
        <div class="wrap jopg-admin">
            <h1>Lightroom Sync</h1>
            <p>Connect your Adobe Lightroom Cloud account and sync albums to your website.</p>
            
            <div class="jopg-sync-status">
                <h3>Connection Status</h3>
                <?php
                if (empty($client_id) || empty($client_secret)):
                    echo '<div class="notice notice-error"><p>Adobe credentials not configured. Go to <a href="' . admin_url('admin.php?page=jopg-settings') . '">Settings</a> to add your Client ID and Client Secret first.</p></div>';
                elseif (!$is_connected):
                    echo '<div class="notice notice-warning"><p>⚠️ Not connected yet. Click the button below to log in with your Adobe account and grant access (one-time setup).</p></div>';
                    echo '<p><a href="' . esc_url($lr->get_connect_url()) . '" class="button button-primary button-hero">🔗 Connect to Adobe Lightroom</a></p>';
                else:
                    // Test that the refresh token still works
                    $token = $lr->get_access_token();
                    if (is_wp_error($token)):
                        echo '<div class="notice notice-error"><p>❌ Connection expired or invalid: ' . esc_html($token->get_error_message()) . '</p></div>';
                        echo '<p><a href="' . esc_url($lr->get_connect_url()) . '" class="button button-primary">🔗 Reconnect to Adobe Lightroom</a></p>';
                    else:
                        echo '<div class="notice notice-success"><p>✅ Connected to Adobe Lightroom. Tokens refresh automatically — you will not need to log in again.</p></div>';
                        echo '<p><a href="' . esc_url($lr->get_connect_url()) . '" class="button">🔄 Reconnect with a different account</a></p>';
                    endif;
                endif;
                ?>
            </div>
            
            <?php if ($is_connected): ?>
            <div class="jopg-sync-actions">
                <button class="button button-primary button-large" id="jopg-sync-now">
                    🔄 Sync Now
                </button>
                <span id="jopg-sync-result"></span>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function render_selections_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_client_selections';
        $table_albums = $wpdb->prefix . 'jopg_albums';
        
        // View specific selection
        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
            $sel_id = intval($_GET['id']);
            $selection = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $sel_id));
            $album = $selection ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", $selection->album_id)) : null;
            $selected_ids = $selection ? json_decode($selection->selected_photos ?? '[]', true) : [];
            
            ?>
            <div class="wrap jopg-admin">
                <h1>Client Selection: <?php echo esc_html($selection->client_name ?? 'Unknown'); ?></h1>
                <p><strong>Album:</strong> <?php echo esc_html($album->title ?? 'Unknown'); ?></p>
                <p><strong>Email:</strong> <?php echo esc_html($selection->client_email ?? '—'); ?></p>
                <p><strong>Status:</strong> <?php echo esc_html($selection->status ?? 'pending'); ?></p>
                <p><strong>Selected photos:</strong> <?php echo count($selected_ids); ?></p>
                <a href="<?php echo admin_url('admin.php?page=jopg-selections'); ?>" class="button">← Back</a>
            </div>
            <?php
            return;
        }
        
        $selections = $wpdb->get_results("SELECT s.*, a.title as album_title 
            FROM $table s LEFT JOIN $table_albums a ON s.album_id = a.id 
            ORDER BY s.created_at DESC");
        
        // Get albums for create form
        $albums = $wpdb->get_results("SELECT * FROM $table_albums ORDER BY title ASC");
        
        ?>
        <div class="wrap jopg-admin">
            <h1>Client Selections</h1>
            
            <h3>Create Selection Link</h3>
            <form method="post" action="">
                <?php wp_nonce_field('jopg_selection'); ?>
                <table class="form-table">
                    <tr>
                        <th>Album</th>
                        <td>
                            <select name="album_id" required>
                                <option value="">— Select Album —</option>
                                <?php foreach ($albums as $album): ?>
                                    <option value="<?php echo $album->id; ?>"><?php echo esc_html($album->title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Client name</th>
                        <td><input type="text" name="client_name" required></td>
                    </tr>
                    <tr>
                        <th>Client email (optional)</th>
                        <td><input type="email" name="client_email"></td>
                    </tr>
                    <tr>
                        <th>Expires (days)</th>
                        <td><input type="number" name="expires_days" value="30" min="1"></td>
                    </tr>
                </table>
                <button type="submit" name="jopg_create_selection" class="button button-primary">Create Link</button>
            </form>
            
            <h3>Selections</h3>
            <?php if (empty($selections)): ?>
                <p>No client selections yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Album</th>
                            <th>Selected</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Expires</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selections as $sel): 
                            $count = count(json_decode($sel->selected_photos ?? '[]', true));
                        ?>
                            <tr>
                                <td><?php echo esc_html($sel->client_name); ?></td>
                                <td><?php echo esc_html($sel->album_title ?? '—'); ?></td>
                                <td><?php echo $count; ?></td>
                                <td><?php echo esc_html($sel->status); ?></td>
                                <td><?php echo esc_html($sel->created_at); ?></td>
                                <td><?php echo esc_html($sel->expires_at); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=jopg-selections&action=view&id=' . $sel->id); ?>" class="button button-small">View</a>
                                    <a href="<?php echo home_url('/photo-selection/' . $sel->selection_token); ?>" target="_blank" class="button button-small">Open Link</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Add custom cron schedule
add_filter('cron_schedules', function($schedules) {
    $interval = intval(JOPG_DB::get_setting('sync_interval', '6'));
    if (!isset($schedules["every_{$interval}_hours"])) {
        $schedules["every_{$interval}_hours"] = [
            'interval' => $interval * HOUR_IN_SECONDS,
            'display' => "Every {$interval} hours"
        ];
    }
    return $schedules;
});
