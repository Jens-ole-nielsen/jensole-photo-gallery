<?php
if (!defined('ABSPATH')) exit;

class JOPG_WooCommerce {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        // Create WooCommerce products when photos are imported
        add_action('jopg_photo_imported', [$this, 'create_product_for_photo'], 10, 2);
        
        // Handle order completion — generate download tokens
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_completed']);
        add_action('woocommerce_payment_complete', [$this, 'handle_order_completed']);
        
        // Add photo to cart via AJAX
        add_action('wp_ajax_jopg_add_to_cart', [$this, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_jopg_add_to_cart', [$this, 'ajax_add_to_cart']);
        
        // Custom cart item data for photos
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        
        // Display photo info in cart
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        
        // Bundle pricing — discount when buying multiple
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_bundle_discount'], 10, 1);
        
        // Customize checkout for guest
        add_filter('woocommerce_checkout_fields', [$this, 'simplify_checkout'], 10, 1);
    }
    
    /**
     * Create a WooCommerce virtual product for a photo
     */
    public function create_product_for_photo($photo_id, $photo_data) {
        if (!class_exists('WooCommerce')) return 0;
        
        // Check if product already exists
        if (!empty($photo_data->wc_product_id)) {
            $product = wc_get_product($photo_data->wc_product_id);
            if ($product) return $photo_data->wc_product_id;
        }
        
        $price = floatval(JOPG_DB::get_setting('single_price', '49'));
        
        $product = new WC_Product_Simple();
        $product->set_name('Photo: ' . $photo_data->filename);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_price($price);
        $product->set_regular_price($price);
        $product->set_virtual(true);
        $product->set_downloadable(true);
        $product->set_sold_individually(false);
        
        // Downloadable file
        $download = new WC_Product_Download();
        $download_file_url = home_url('/jopg/image/' . $photo_id . '/?token=PLACEHOLDER');
        $download->set_id(md5($download_file_url));
        $download->set_name($photo_data->filename);
        $download->set_file($download_file_url);
        $product->set_downloads([$download]);
        
        // Download limit and expiry
        $max_dl = intval(JOPG_DB::get_setting('max_downloads', '5'));
        $product->set_download_limit($max_dl);
        
        $expiry_days = intval(JOPG_DB::get_setting('download_expiry_days', '30'));
        $product->set_download_expiry($expiry_days);
        
        $product_id = $product->save();
        
        // Link product to photo
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_photos';
        $wpdb->update($table, ['wc_product_id' => $product_id], ['id' => $photo_id]);
        
        return $product_id;
    }
    
    /**
     * Handle completed order — generate secure download tokens
     */
    public function handle_order_completed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $expiry_days = intval(JOPG_DB::get_setting('download_expiry_days', '30'));
        $max_downloads = intval(JOPG_DB::get_setting('max_downloads', '5'));
        $expires_at = date('Y-m-d H:i:s', time() + ($expiry_days * DAY_IN_SECONDS));
        
        global $wpdb;
        $table_dl = $wpdb->prefix . 'jopg_downloads';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            
            // Find the photo linked to this product
            $photo = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_photos WHERE wc_product_id = %d", $product_id
            ));
            if (!$photo) continue;
            
            // Generate a secure download token
            $token = wp_generate_uuid4();
            
            $wpdb->insert($table_dl, [
                'order_id' => $order_id,
                'photo_id' => $photo->id,
                'download_token' => $token,
                'max_downloads' => $max_downloads,
                'expires_at' => $expires_at
            ], ['%d', '%d', '%s', '%d', '%s']);
            
            // Update the WooCommerce download URL with the real token
            $product = wc_get_product($product_id);
            if ($product) {
                $downloads = $product->get_downloads();
                foreach ($downloads as $download) {
                    $download->set_file(home_url('/jopg/image/' . $photo->id . '/?token=' . $token));
                }
                $product->set_downloads($downloads);
                $product->save();
            }
        }
        
        // Send email with download links
        $this->send_download_email($order_id);
    }
    
    /**
     * Send email with download links to customer
     */
    private function send_download_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        global $wpdb;
        $table_dl = $wpdb->prefix . 'jopg_downloads';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $downloads = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, p.filename FROM $table_dl d 
             JOIN $table_photos p ON d.photo_id = p.id 
             WHERE d.order_id = %d", $order_id
        ));
        
        if (empty($downloads)) return;
        
        $email = $order->get_billing_email();
        $subject = 'Your photo downloads from Jens Ole Photography';
        
        $links_html = '';
        foreach ($downloads as $dl) {
            $url = home_url('/jopg/image/' . $dl->photo_id . '/?token=' . $dl->download_token);
            $links_html .= '<li><a href="' . esc_url($url) . '">' . esc_html($dl->filename) . '</a></li>';
        }
        
        $expiry_days = JOPG_DB::get_setting('download_expiry_days', '30');
        
        $message = '<html><body style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">';
        $message .= '<h2>Your photos are ready!</h2>';
        $message .= '<p>Thank you for your purchase. You can download your photos using the links below:</p>';
        $message .= '<ul>' . $links_html . '</ul>';
        $message .= '<p><small>Download links expire in ' . $expiry_days . ' days. ';
        $message .= 'Each photo can be downloaded up to ' . JOPG_DB::get_setting('max_downloads', '5') . ' times.</small></p>';
        $message .= '<p>Enjoy your photos!</p>';
        $message .= '<p>— Jens Ole Photography</p>';
        $message .= '</body></html>';
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * AJAX: Add photo to cart
     */
    public function ajax_add_to_cart() {
        check_ajax_referer('jopg_cart', 'nonce');
        
        $photo_id = intval($_POST['photo_id']);
        
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_photos';
        $photo = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $photo_id));
        
        if (!$photo) wp_send_json_error('Photo not found');
        if (!$photo->wc_product_id) wp_send_json_error('Product not available');
        
        WC()->cart->add_to_cart($photo->wc_product_id);
        
        wp_send_json_success([
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total()
        ]);
    }
    
    /**
     * Add custom data to cart item
     */
    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_photos';
        $photo = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE wc_product_id = %d", $product_id));
        
        if ($photo) {
            $cart_item_data['jopg_photo_id'] = $photo->id;
            $cart_item_data['jopg_photo_filename'] = $photo->filename;
        }
        
        return $cart_item_data;
    }
    
    /**
     * Display photo info in cart
     */
    public function display_cart_item_data($item_data, $cart_item) {
        if (!empty($cart_item['jopg_photo_filename'])) {
            $item_data[] = [
                'name' => 'Photo',
                'value' => $cart_item['jopg_photo_filename']
            ];
        }
        return $item_data;
    }
    
    /**
     * Apply bundle discount — e.g. 5 photos for 200 (currency follows WooCommerce settings)
     */
    public function apply_bundle_discount($cart) {
        $bundle_qty = intval(JOPG_DB::get_setting('bundle_qty', '5'));
        $bundle_price = floatval(JOPG_DB::get_setting('bundle_price', '200'));
        $single_price = floatval(JOPG_DB::get_setting('single_price', '49'));
        
        // Count JOPG photo items in cart
        $photo_count = 0;
        foreach ($cart->get_cart() as $item) {
            if (!empty($item['jopg_photo_id'])) {
                $photo_count += $item['quantity'];
            }
        }
        
        if ($photo_count < $bundle_qty) return;
        
        // Calculate how many bundles
        $bundles = intdiv($photo_count, $bundle_qty);
        $photos_in_bundles = $bundles * $bundle_qty;
        
        // Discount = (individual price total) - (bundle price total)
        $individual_total = $photos_in_bundles * $single_price;
        $bundle_total = $bundles * $bundle_price;
        $discount = $individual_total - $bundle_total;
        
        if ($discount > 0) {
            $cart->add_fee(sprintf('%d× bundle of %d photos', $bundles, $bundle_qty), -$discount, true, '');
        }
    }
    
    /**
     * Simplify checkout — remove account field if guest checkout
     */
    public function simplify_checkout($fields) {
        if (JOPG_DB::get_setting('guest_checkout', '1') === '1') {
            // Remove company field
            unset($fields['billing']['billing_company']);
        }
        return $fields;
    }
}
