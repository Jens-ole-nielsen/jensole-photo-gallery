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
        add_submenu_page('jopg', 'Diagnostics', 'Diagnostics', 'manage_options', 'jopg-diagnostics', [$this, 'render_diagnostics_page']);
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
                         'watermark_type', 'watermark_size', 'watermark_mode', 'watermark_spacing',
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
            
            // Handle logo upload for watermark
            if (!empty($_FILES['watermark_logo']['tmp_name'])) {
                $allowed = ['image/png', 'image/jpeg', 'image/gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['watermark_logo']['tmp_name']);
                finfo_close($finfo);
                
                if (in_array($mime, $allowed)) {
                    $upload_dir = wp_upload_dir();
                    $logo_dir = $upload_dir['basedir'] . '/jopg-watermark';
                    if (!file_exists($logo_dir)) wp_mkdir_p($logo_dir);
                    
                    // Delete old logo
                    $old_logo = JOPG_DB::get_setting('watermark_logo_path', '');
                    if ($old_logo && file_exists($old_logo)) unlink($old_logo);
                    
                    $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/gif' ? 'gif' : 'jpg');
                    $logo_path = $logo_dir . '/watermark-logo.' . $ext;
                    move_uploaded_file($_FILES['watermark_logo']['tmp_name'], $logo_path);
                    JOPG_DB::set_setting('watermark_logo_path', $logo_path);
                }
            }
            
            // Handle logo removal
            if (!empty($_POST['watermark_remove_logo'])) {
                $old_logo = JOPG_DB::get_setting('watermark_logo_path', '');
                if ($old_logo && file_exists($old_logo)) unlink($old_logo);
                JOPG_DB::set_setting('watermark_logo_path', '');
            }
            
            // Clear watermark cache — settings changed, so cached images are outdated
            $upload_dir = wp_upload_dir();
            $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
            if (file_exists($cache_dir)) {
                foreach (glob($cache_dir . '/wm_*') as $cached_file) {
                    @unlink($cached_file);
                }
            }
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Settings saved. Watermark cache cleared — new watermark will be applied on next view.</p></div>';
            });
        }
        
        // Apply price to all imported photos
        if (isset($_POST['jopg_apply_price'])) {
            check_admin_referer('jopg_settings');
            global $wpdb;
            $table_photos = $wpdb->prefix . 'jopg_photos';
            $price = floatval(JOPG_DB::get_setting('single_price', '49'));
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $table_photos SET price = %f",
                $price
            ));
            add_action('admin_notices', function() use ($updated) {
                echo '<div class="notice notice-success is-dismissible"><p>Applied new price to ' . intval($updated) . ' photos.</p></div>';
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
        
        // Handle "Fix Permalinks" — set a pretty permalink structure and flush
        if (isset($_GET['action']) && $_GET['action'] === 'fix_permalinks' && current_user_can('manage_options')) {
            update_option('permalink_structure', '/%postname%/');
            flush_rewrite_rules();
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Permalink structure set to Post Name and rewrite rules flushed. Check Diagnostics again.</p></div>';
            });
        }
        
        // Handle "Check for Updates"
        if (isset($_GET['action']) && $_GET['action'] === 'check_updates') {
            delete_transient('jopg_github_release');
            // Force WordPress to re-check plugins
            delete_site_transient('update_plugins');
            // Trigger WordPress's update check
            wp_update_plugins();
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Checked for plugin updates. If a new version is available it will appear below — or go to Plugins page.</p></div>';
            });
        }
        
        // Handle "View Photos" for a specific album
        if (isset($_GET['action']) && $_GET['action'] === 'view_album' && isset($_GET['album_id'])) {
            $this->render_album_photos(intval($_GET['album_id']));
            return;
        }
        
        // Sort: active+imported albums first, hidden albums at the bottom
        $albums = $wpdb->get_results("SELECT a.*, 
            (SELECT COUNT(*) FROM {$table_photos} p WHERE p.album_id = a.id) as local_photo_count
            FROM $table_albums a ORDER BY 
            (a.status = 'active') DESC,
            (local_photo_count > 0) DESC,
            a.synced_at DESC");
        
        ?>
        <div class="wrap jopg-admin">
            <h1>Photo Albums</h1>
            <p>Albums synced from Adobe Lightroom Cloud.</p>
            
            <div class="jopg-actions">
                <button class="button button-primary" id="jopg-sync-now">🔄 Sync Albums from Lightroom</button>
                <a href="<?php echo admin_url('admin.php?page=jopg&action=check_updates'); ?>" class="button">🔄 Check for Plugin Updates</a>
            </div>
            
            <div id="jopg-prewarm-status" style="margin-top:15px;"></div>
            
            <?php if (empty($albums)): ?>
                <div class="jopg-empty">
                    <p>No albums found. Click "Sync Albums from Lightroom" to fetch your Lightroom albums.</p>
                </div>
            <?php else: ?>
                <p>
                    <input type="search" id="jopg-album-search" placeholder="Search albums..." 
                        style="width:300px;margin-bottom:10px;" class="regular-text">
                </p>
                <table class="wp-list-table widefat fixed striped" id="jopg-album-table">
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
                            <tr data-album-name="<?php echo esc_attr(strtolower($album->title)); ?>" 
                                class="<?php echo $album->status === 'hidden' ? 'jopg-album-hidden' : ''; ?>"
                                data-album-id="<?php echo $album->id; ?>">
                                <td>
                                    <strong><?php echo esc_html($album->title); ?></strong>
                                    <?php if ($album->status === 'hidden'): ?>
                                        <span style="color:#999;font-style:italic;font-size:12px;">(hidden)</span>
                                    <?php elseif ($album->local_photo_count > 0): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color:#00a32a;font-size:16px;" title="Imported"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $album->photo_count; ?></td>
                                <td><?php echo $album->local_photo_count; ?></td>
                                <td><?php echo $album->synced_at ?: '—'; ?></td>
                                <td>
                                    <?php if ($album->status !== 'hidden'): ?>
                                    <button class="button button-small jopg-import-album" 
                                            data-album-id="<?php echo $album->id; ?>">
                                        📥 Import
                                    </button>
                                    <button class="button button-small jopg-prewarm-album" 
                                            data-album-id="<?php echo $album->id; ?>">
                                        🔥 Pre-warm
                                    </button>
                                    <a class="button button-small" href="<?php echo admin_url('admin.php?page=jopg&action=view_album&album_id=' . $album->id); ?>">
                                        View
                                    </a>
                                    <button class="button button-small jopg-hide-album" 
                                            data-album-id="<?php echo $album->id; ?>"
                                            style="color:#b32d2e;"
                                            title="Hide this album — stops sync and gallery display">
                                        ✕ Remove
                                    </button>
                                    <?php else: ?>
                                    <button class="button button-small jopg-restore-album" 
                                            data-album-id="<?php echo $album->id; ?>"
                                            style="color:#00a32a;">
                                        ↺ Restore
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="jopg-no-results" style="display:none;color:#666;padding:10px;">No albums match your search.</p>
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
            <div id="jopg-prewarm-status" style="margin-top:15px;"></div>
        </div>
        <?php
    }
    
    public function render_settings_page() {
        $s = JOPG_DB::get_all_settings();
        ?>
        <div class="wrap jopg-admin">
            <h1>Gallery Settings</h1>
            <form method="post" action="" autocomplete="off" enctype="multipart/form-data">
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
                        <th>Watermark type</th>
                        <td>
                            <select name="watermark_type" id="jopg-wm-type">
                                <?php
                                $wm_type = $s['watermark_type'] ?? 'text';
                                $types = ['text' => 'Text', 'logo' => 'Logo image', 'pattern' => 'Logo pattern (net)'];
                                foreach ($types as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php selected($wm_type, $val); ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Choose "Logo pattern (net)" to tile a small logo across the entire image.</p>
                        </td>
                    </tr>
                    <tr class="jopg-wm-text-row">
                        <th>Watermark text</th>
                        <td><input type="text" name="watermark_text" class="regular-text" 
                            value="<?php echo esc_attr($s['watermark_text'] ?? 'Jens Ole Photography'); ?>"></td>
                    </tr>
                    <tr class="jopg-wm-logo-row">
                        <th>Logo image</th>
                        <td>
                            <input type="file" name="watermark_logo" accept="image/png,image/jpeg,image/gif">
                            <?php
                            $logo_path = $s['watermark_logo_path'] ?? '';
                            if ($logo_path && file_exists($logo_path)):
                                $upload_dir = wp_upload_dir();
                                $logo_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $logo_path);
                            ?>
                                <p class="description">
                                    Current logo: <img src="<?php echo esc_url($logo_url); ?>" style="max-height:40px;vertical-align:middle;"> 
                                    <label><input type="checkbox" name="watermark_remove_logo" value="1"> Remove logo</label>
                                </p>
                            <?php else: ?>
                                <p class="description">Upload a PNG with transparency for best results.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Opacity (0-100, lower = more transparent)</th>
                        <td><input type="number" name="watermark_opacity" min="0" max="100"
                            value="<?php echo esc_attr($s['watermark_opacity'] ?? '30'); ?>"></td>
                    </tr>
                    <tr class="jopg-wm-size-row">
                        <th>Size (% of image width)</th>
                        <td>
                            <input type="range" name="watermark_size" min="5" max="80" step="5"
                                value="<?php echo esc_attr($s['watermark_size'] ?? '30'); ?>" 
                                oninput="document.getElementById('jopg-wm-size-val').textContent = this.value + '%';">
                            <span id="jopg-wm-size-val" style="font-weight:bold;"><?php echo esc_attr($s['watermark_size'] ?? '30'); ?>%</span>
                            <p class="description">How large the watermark should be relative to the image.</p>
                        </td>
                    </tr>
                    <tr class="jopg-wm-position-row">
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
                            <p class="description">Only used for single text/logo, not pattern mode.</p>
                        </td>
                    </tr>
                    <tr class="jopg-wm-spacing-row">
                        <th>Pattern spacing (px)</th>
                        <td>
                            <input type="number" name="watermark_spacing" min="20" max="500" step="10"
                                value="<?php echo esc_attr($s['watermark_spacing'] ?? '100'); ?>">
                            <p class="description">Distance between repeated logos in pattern mode. Smaller = denser net.</p>
                        </td>
                    </tr>
                </table>
                
                <script>
                jQuery(function($) {
                    function toggleWmRows() {
                        var type = $('#jopg-wm-type').val();
                        $('.jopg-wm-text-row').toggle(type === 'text');
                        $('.jopg-wm-logo-row').toggle(type === 'logo' || type === 'pattern');
                        $('.jopg-wm-position-row').toggle(type !== 'pattern');
                        $('.jopg-wm-spacing-row').toggle(type === 'pattern');
                        $('.jopg-wm-size-row').toggle(type !== 'text');
                    }
                    toggleWmRows();
                    $('#jopg-wm-type').on('change', toggleWmRows);
                });
                </script>
                
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
                    <button type="submit" name="jopg_apply_price" class="button" 
                        onclick="return confirm('Apply the current single photo price to ALL imported photos? This will overwrite per-photo prices.');">
                        Apply price to all photos
                    </button>
                </p>
            </form>
            
            <div class="jopg-help-card" style="margin-top:30px;padding:20px;background:#fff;border:1px solid #ddd;border-radius:8px;max-width:700px;">
                <h3>📖 How to publish your gallery</h3>
                <p style="font-size:14px;line-height:1.6;">
                    <strong>1.</strong> Go to <strong>Pages → Add New</strong> in WordPress<br>
                    <strong>2.</strong> Give the page a title (e.g. "Photos" or "Galleri")<br>
                    <strong>3.</strong> Add this shortcode in the content area:<br>
                    <code style="display:block;background:#f0f0f0;padding:8px 12px;margin:8px 0;border-radius:4px;">[jopg_gallery]</code>
                    <strong>4.</strong> Click <strong>Publish</strong><br><br>
                    The shortcode shows all your albums as a grid. Visitors click an album to see the photos inside.<br><br>
                    <strong>To show a single album directly:</strong><br>
                    <code style="display:block;background:#f0f0f0;padding:8px 12px;margin:8px 0;border-radius:4px;">[jopg_album album="3"]</code>
                    (use the album ID or slug — find it under Photo Gallery → Albums)
                </p>
            </div>
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
            <div id="jopg-prewarm-status" style="margin-top:15px;"></div>
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
            <div id="jopg-prewarm-status" style="margin-top:15px;"></div>
        </div>
        <?php
    }
    
    /**
     * Diagnostics page — shows system status and lets you test image fetching
     */
    public function render_diagnostics_page() {
        global $wpdb;
        $table_photos = $wpdb->prefix . 'jopg_photos';
        $table_albums = $wpdb->prefix . 'jopg_albums';
        
        $diagnostics = [];
        
        // 0. Show plugin version + permalink structure FIRST — these are the
        // two most common reasons rewrite rules silently don't work.
        $diagnostics[] = [
            'label' => 'Plugin Version (active code)',
            'value' => JOPG_VERSION,
            'ok' => true,
        ];
        $permalink_structure = get_option('permalink_structure', '');
        $diagnostics[] = [
            'label' => 'Permalink Structure',
            'value' => $permalink_structure ? $permalink_structure : 'PLAIN (default ?p=123 links) — THIS BREAKS OUR IMAGE URLS!',
            'ok' => (bool)$permalink_structure,
        ];
        
        // 1. Check Adobe connection
        $access_token = JOPG_DB::get_setting('adobe_access_token', '');
        $refresh_token = JOPG_DB::get_setting('adobe_refresh_token', '');
        $token_expires = intval(JOPG_DB::get_setting('adobe_token_expires', 0));
        $catalog_base = JOPG_DB::get_setting('adobe_catalog_base', '');
        $client_id = JOPG_DB::get_setting('adobe_client_id', '');
        
        $diagnostics[] = [
            'label' => 'Adobe Connection',
            'value' => $refresh_token ? 'Connected (refresh token present)' : 'NOT CONNECTED',
            'ok' => (bool)$refresh_token,
        ];
        $diagnostics[] = [
            'label' => 'Access Token',
            'value' => $access_token ? 'Present, expires ' . date('Y-m-d H:i:s', $token_expires) . ' (' . ($token_expires > time() ? 'valid' : 'EXPIRED') . ')' : 'Missing',
            'ok' => $access_token && $token_expires > time(),
        ];
        $diagnostics[] = [
            'label' => 'Client ID',
            'value' => $client_id ?: 'MISSING',
            'ok' => (bool)$client_id,
        ];
        $diagnostics[] = [
            'label' => 'Catalog Base URL',
            'value' => $catalog_base ?: 'NOT SET (will use default lr.adobe.io)',
            'ok' => (bool)$catalog_base,
        ];
        
        // 2. Check database tables
        $album_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_albums"));
        $photo_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_photos"));
        $photos_with_url = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_photos WHERE display_url IS NOT NULL AND display_url != ''"));
        
        $diagnostics[] = [
            'label' => 'Albums in DB',
            'value' => $album_count . ' albums',
            'ok' => $album_count > 0,
        ];
        $diagnostics[] = [
            'label' => 'Photos in DB',
            'value' => $photo_count . ' photos (' . $photos_with_url . ' with URL)',
            'ok' => $photo_count > 0,
        ];
        
        // 3. Check rewrite rules
        $rules = get_option('rewrite_rules');
        $jopg_rules = 0;
        if (is_array($rules)) {
            foreach ($rules as $pattern => $target) {
                if (strpos($pattern, 'jopg') !== false) $jopg_rules++;
            }
        }
        $diagnostics[] = [
            'label' => 'Rewrite Rules',
            'value' => $jopg_rules . ' JOPG rules found (need 2)',
            'ok' => $jopg_rules >= 2,
        ];
        
        // 4. Show a sample photo URL
        $sample_photo = $wpdb->get_row("SELECT * FROM $table_photos ORDER BY id ASC LIMIT 1");
        if ($sample_photo) {
            $wm_url = JOPG_Watermark::get_watermarked_url($sample_photo->id);
            $diagnostics[] = [
                'label' => 'Sample Photo (#' . $sample_photo->id . ')',
                'value' => $sample_photo->filename . ' — display_url: ' . substr($sample_photo->display_url ?: '(empty)', 0, 120),
                'ok' => (bool)$sample_photo->display_url,
            ];
            $diagnostics[] = [
                'label' => 'Watermark Proxy URL',
                'value' => $wm_url,
                'ok' => true,
            ];
        }
        
        // 5. Test image fetch (if we have a photo)
        $fetch_result = '';
        $fetch_ok = false;
        if (isset($_GET['test_fetch']) && $sample_photo) {
            $lr = JOPG_Lightroom::instance();
            $test_url = $sample_photo->display_url ?: $sample_photo->original_url;
            
            // Test token first
            $token = $lr->get_access_token();
            if (is_wp_error($token)) {
                $fetch_result = 'Token error: ' . $token->get_error_message();
            } else {
                $fetch_result = 'Token OK (expires ' . date('H:i:s', intval(JOPG_DB::get_setting('adobe_token_expires', 0))) . '). ';
                $result = $lr->fetch_rendition_bytes($test_url);
                if (is_wp_error($result)) {
                    $fetch_result .= 'Fetch FAILED: ' . $result->get_error_message();
                } else {
                    $size = strlen($result['body']);
                    $fetch_result .= 'Fetch OK — ' . $size . ' bytes, content-type: ' . $result['content_type'];
                    $fetch_ok = $size > 1000;
                }
            }
        }
        
        ?>
        <div class="wrap jopg-admin">
            <h1>🔧 Diagnostics</h1>
            
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:25%;">Check</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($diagnostics as $diag): ?>
                        <tr>
                            <td><strong><?php echo esc_html($diag['label']); ?></strong></td>
                            <td style="color: <?php echo $diag['ok'] ? '#0a8028' : '#c00'; ?>;">
                                <?php echo esc_html($diag['value']); ?>
                                <?php echo $diag['ok'] ? ' ✓' : ' ✗'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($sample_photo): ?>
                <h2 style="margin-top:30px;">Test Image Fetch</h2>
                <p>Click the button below to test fetching an image from Adobe Lightroom.</p>
                <a href="<?php echo admin_url('admin.php?page=jopg-diagnostics&test_fetch=1'); ?>" class="button button-primary">Test Fetch First Photo</a>
                
                <?php if ($fetch_result): ?>
                    <div style="margin-top:15px; padding:15px; background:<?php echo $fetch_ok ? '#d4edda' : '#f8d7da'; ?>; border-radius:4px; font-family:monospace; font-size:13px;">
                        <?php echo esc_html($fetch_result); ?>
                    </div>
                <?php endif; ?>
                
                <h2 style="margin-top:30px;">Sample Watermarked Image</h2>
                <p>If the proxy works, you should see a watermarked image below. If you see an error image, the error text will tell you what is wrong.</p>
                <img src="<?php echo esc_url(JOPG_Watermark::get_watermarked_url($sample_photo->id)); ?>" 
                     style="max-width:400px; border:1px solid #ddd; border-radius:4px;" 
                     alt="Test image">
            <?php else: ?>
                <p>No photos found in the database. Import photos from an album first.</p>
            <?php endif; ?>
            
            <h2 style="margin-top:30px;">Actions</h2>
            <p>
                <?php if (!$permalink_structure): ?>
                    <a href="<?php echo admin_url('admin.php?page=jopg&action=fix_permalinks'); ?>" class="button button-primary" style="background:#c00;border-color:#a00;">⚠️ Fix Permalinks Now (sets Post Name + flushes rules)</a>
                <?php endif; ?>
                <a href="<?php echo admin_url('admin.php?page=jopg&action=check_updates'); ?>" class="button">🔄 Check for Plugin Updates</a>
                <a href="<?php echo admin_url('options-permalink.php'); ?>" class="button">📝 Go to Permalink Settings (just click Save to flush rewrite rules)</a>
            </p>
        </div>
        <?php
    }
}