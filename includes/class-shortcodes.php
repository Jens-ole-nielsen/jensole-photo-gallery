<?php
if (!defined('ABSPATH')) exit;

class JOPG_Shortcodes {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        add_shortcode('jopg_gallery', [$this, 'render_gallery']);
        add_shortcode('jopg_album', [$this, 'render_single_album']);
        add_shortcode('jopg_selection', [$this, 'render_selection_page']);
        
        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Selection page rewrite
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('template_include', [$this, 'selection_template']);
    }
    
    public function add_rewrite_rules() {
        add_rewrite_rule('^photo-selection/([a-f0-9-]+)/?$', 'index.php?jopg_selection_token=$matches[1]', 'top');
        add_filter('query_vars', function($vars) {
            $vars[] = 'jopg_selection_token';
            return $vars;
        });
    }
    
    public function selection_template($template) {
        if (get_query_var('jopg_selection_token')) {
            return JOPG_PATH . 'templates/client-selection.php';
        }
        return $template;
    }
    
    public function enqueue_assets() {
        wp_enqueue_style('jopg-frontend', JOPG_URL . 'assets/css/gallery.css', [], JOPG_VERSION);
        wp_enqueue_script('jopg-frontend', JOPG_URL . 'assets/js/gallery.js', ['jquery'], JOPG_VERSION, true);
        
        wp_localize_script('jopg-frontend', 'jopg', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'cart_nonce' => wp_create_nonce('jopg_cart'),
            'selection_nonce' => wp_create_nonce('jopg_selection'),
            'rest_url' => rest_url('jopg/v1/'),
            'cart_url' => wc_get_cart_url() ?? '',
            'checkout_url' => wc_get_checkout_url() ?? '',
            'currency' => get_woocommerce_currency() ?? 'DKK',
            'single_price' => JOPG_DB::get_setting('single_price', '49'),
            'bundle_qty' => JOPG_DB::get_setting('bundle_qty', '5'),
            'bundle_price' => JOPG_DB::get_setting('bundle_price', '200'),
        ]);
    }
    
    /**
     * Render gallery overview — all albums, OR a single album if ?album=X is in the URL
     */
    public function render_gallery($atts) {
        // If an album is requested via URL param, delegate to single album view
        if (isset($_GET['album'])) {
            return $this->render_single_album($atts);
        }
        
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $albums = $wpdb->get_results("SELECT a.*, 
            (SELECT COUNT(*) FROM {$table_photos} p WHERE p.album_id = a.id) as photo_count,
            (SELECT thumb_url FROM {$table_photos} p WHERE p.album_id = a.id ORDER BY p.id DESC LIMIT 1) as cover_thumb
            FROM $table_albums a WHERE a.status = 'active' 
            AND EXISTS (SELECT 1 FROM {$table_photos} p WHERE p.album_id = a.id)
            ORDER BY a.synced_at DESC");
        
        ob_start();
        ?>
        <div class="jopg-gallery-header">
            <h1>Photo Galleries</h1>
            <p>Click an album to browse photos. Watermarked previews are free to view — purchase for full resolution downloads.</p>
        </div>
        <div class="jopg-gallery-grid">
            <?php if (empty($albums)): ?>
                <p class="jopg-empty">No galleries available yet.</p>
            <?php else: foreach ($albums as $album): 
                $cover = $album->cover_thumb ?: ($album->cover_url ?: '');
                if (!$cover && $album->photo_count > 0) {
                    // Try to get first photo's thumbnail URL (small, fast)
                    $first = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$table_photos} WHERE album_id = %d ORDER BY id ASC LIMIT 1", $album->id
                    ));
                    if ($first) $cover = JOPG_Watermark::get_thumb_url($first->id);
                }
            ?>
                <div class="jopg-album-card" data-album-id="<?php echo $album->id; ?>">
                    <div class="jopg-album-cover">
                        <?php if ($cover): ?>
                            <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($album->title); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="jopg-no-cover">📷</div>
                        <?php endif; ?>
                    </div>
                    <div class="jopg-album-info">
                        <h3><?php echo esc_html($album->title); ?></h3>
                        <span class="jopg-photo-count"><?php echo $album->photo_count; ?> photos</span>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render single album — grid of photos
     */
    public function render_single_album($atts) {
        $atts = shortcode_atts(['album' => ''], $atts);
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        // Find album by slug or ID
        $album = null;
        if (is_numeric($atts['album'])) {
            $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", intval($atts['album'])));
        } else {
            $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE slug = %s", sanitize_title($atts['album'])));
        }
        
        // Or use URL param (gallery.js sends numeric album ID)
        if (!$album && isset($_GET['album'])) {
            $album_param = $_GET['album'];
            if (is_numeric($album_param)) {
                $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", intval($album_param)));
            } else {
                $slug = sanitize_title($album_param);
                $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE slug = %s", $slug));
            }
        }
        
        if (!$album) {
            return '<p class="jopg-error">Album not found.</p>';
        }
        
        $photos = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_photos WHERE album_id = %d AND status = 'active' ORDER BY filename ASC", $album->id
        ));
        
        $single_price = JOPG_DB::get_setting('single_price', '49');
        $bundle_qty = JOPG_DB::get_setting('bundle_qty', '5');
        $bundle_price = JOPG_DB::get_setting('bundle_price', '200');
        
        ob_start();
        ?>
        <div class="jopg-album" data-album-id="<?php echo $album->id; ?>">
            <p class="jopg-back-link"><a href="<?php echo esc_url(remove_query_arg('album')); ?>">← All galleries</a></p>
            <h2 class="jopg-album-title"><?php echo esc_html($album->title); ?></h2>
            <p class="jopg-album-meta"><?php echo count($photos); ?> photos — <?php echo esc_html($single_price); ?> kr each or <?php echo esc_html($bundle_qty); ?> for <?php echo esc_html($bundle_price); ?> kr</p>
            
            <div class="jopg-photos-grid">
                <?php foreach ($photos as $photo): 
                    $wm_url = JOPG_Watermark::get_watermarked_url($photo->id);
                    $thumb_url = JOPG_Watermark::get_thumb_url($photo->id);
                ?>
                <div class="jopg-photo" data-photo-id="<?php echo $photo->id; ?>">
                    <div class="jopg-photo-thumb">
                        <img src="<?php echo esc_url(JOPG_Watermark::get_thumb_url($photo->id)); ?>" 
                             alt="<?php echo esc_attr($photo->title ?: $photo->filename); ?>" 
                             loading="lazy"
                             data-full-url="<?php echo esc_url($wm_url); ?>">
                    </div>
                    <div class="jopg-photo-overlay">
                        <button class="jopg-add-cart" data-photo-id="<?php echo $photo->id; ?>">
                            🛒 Add to cart
                        </button>
                        <span class="jopg-price"><?php echo esc_html($single_price); ?> kr</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Lightbox -->
        <div class="jopg-lightbox" id="jopg-lightbox" style="display:none;">
            <div class="jopg-lightbox-bg"></div>
            <div class="jopg-lightbox-content">
                <img src="" id="jopg-lightbox-img" alt="">
                <div class="jopg-lightbox-controls">
                    <button class="jopg-lb-prev">←</button>
                    <button class="jopg-lb-close">✕</button>
                    <button class="jopg-lb-next">→</button>
                    <button class="jopg-lb-cart" data-photo-id="">🛒 Add to cart</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render client selection page (shortcode for embedding)
     */
    public function render_selection_page($atts) {
        return '<div id="jopg-selection-app">Loading...</div>';
    }
}
