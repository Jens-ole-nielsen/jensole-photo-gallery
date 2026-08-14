<?php
if (!defined('ABSPATH')) exit;

class JOPG_Watermark {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', [$this, 'register_proxy_route']);
    }
    
    public function register_proxy_route() {
        add_rewrite_rule('^jopg/image/([0-9]+)/wm/thumb/?$', 'index.php?jopg_image_id=$matches[1]&jopg_watermark=1&jopg_thumb=1', 'top');
        add_rewrite_rule('^jopg/image/([0-9]+)/wm/?$', 'index.php?jopg_image_id=$matches[1]&jopg_watermark=1', 'top');
        add_rewrite_rule('^jopg/image/([0-9]+)/?$', 'index.php?jopg_image_id=$matches[1]', 'top');
        
        add_filter('query_vars', function($vars) {
            $vars[] = 'jopg_image_id';
            $vars[] = 'jopg_watermark';
            $vars[] = 'jopg_thumb'; 
            return $vars;
        });
        
        add_action('template_redirect', [$this, 'handle_image_request']);
    }
    
    public function handle_image_request() {
        $image_id = get_query_var('jopg_image_id');
        if (!$image_id) return;
        
        $watermark = get_query_var('jopg_watermark');
        $thumb = get_query_var('jopg_thumb');
        
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_photos';
        $photo = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $image_id));
        if (!$photo) {
            status_header(404);
            exit;
        }
        
        if ($watermark) {
            $this->serve_watermarked($photo, (bool)$thumb);
        } else {
            $this->serve_clean($photo);
        }
    }
    
    /**
     * Fetch the actual image bytes from Lightroom (authenticated) — cached briefly on disk to avoid re-fetching on every view
     */
    private function get_source_bytes($url, $cache_key) {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
        if (!file_exists($cache_dir)) wp_mkdir_p($cache_dir);
        
        $cache_file = $cache_dir . '/' . $cache_key . '.jpg';
        
        // Serve from cache if fresh (24h)
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < (7 * DAY_IN_SECONDS)) {
            $cached_data = file_get_contents($cache_file);
            if ($cached_data && strlen($cached_data) > 100) {
                return ['body' => $cached_data, 'content_type' => 'image/jpeg'];
            }
            // Cache file is too small/empty — probably a failed fetch, ignore it
        }
        
        $lr = JOPG_Lightroom::instance();
        $result = $lr->fetch_rendition_bytes($url);
        
        if (is_wp_error($result)) return $result;
        
        // Cache it
        file_put_contents($cache_file, $result['body']);
        
        return $result;
    }
    
    private function serve_watermarked($photo, $thumb = false) {
        // For thumbnails in the grid, use the small rendition (thumbnail2x) 
        // instead of the 2048px display version — much faster to fetch
        if ($thumb && !empty($photo->thumb_url)) {
            $url = $photo->thumb_url;
        } else {
            $url = $photo->display_url ?: $photo->original_url;
        }
        if (!$url) { $this->error_image('No URL stored for photo #' . $photo->id); exit; }
        
        $cache_key = $thumb ? 'wm_thumb_' . $photo->id : 'wm_' . $photo->id;
        $result = $this->get_source_bytes($url, $cache_key);
        if (is_wp_error($result)) { 
            $this->error_image('Lightroom fetch failed: ' . $result->get_error_message() . '\nURL: ' . substr($url, 0, 100));
            exit; 
        }
        
        $image_data = $result['body'];
        $content_type = $result['content_type'];
        
        $image = @imagecreatefromstring($image_data);
        if ($image === false) {
            header('Content-Type: ' . $content_type);
            echo $image_data;
            exit;
        }
        
        $image = $this->apply_watermark($image);
        
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600');
        imagejpeg($image, null, 90);
        imagedestroy($image);
        exit;
    }
    
    private function serve_clean($photo) {
        $token = $_GET['token'] ?? '';
        if (!$token) { status_header(403); exit; }
        
        global $wpdb;
        $table_dl = $wpdb->prefix . 'jopg_downloads';
        $dl = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_dl WHERE download_token = %s AND photo_id = %d",
            $token, $photo->id
        ));
        
        if (!$dl) { status_header(403); exit; }
        if (strtotime($dl->expires_at) < time()) { status_header(403); exit; }
        if ($dl->max_downloads > 0 && $dl->download_count >= $dl->max_downloads) {
            status_header(403); exit;
        }
        
        $wpdb->update($table_dl, [
            'download_count' => $dl->download_count + 1,
            'last_download_at' => current_time('mysql')
        ], ['id' => $dl->id]);
        
        $url = $photo->original_url ?: $photo->display_url;
        $result = $this->get_source_bytes($url, 'full_' . $photo->id);
        
        if (is_wp_error($result)) { 
            status_header(502); 
            echo 'Could not load image: ' . esc_html($result->get_error_message());
            exit; 
        }
        
        $filename = sanitize_file_name($photo->filename);
        if (!preg_match('/\.(jpg|jpeg)$/i', $filename)) $filename .= '.jpg';
        
        header('Content-Type: ' . $result['content_type']);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($result['body']));
        echo $result['body'];
        exit;
    }
    
    /**
     * Generate an error image with text so the browser shows what went wrong
     * instead of a blank broken-image icon.
     */
    private function error_image($message) {
        $w = 600;
        $h = 200;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, 40, 40, 40);
        $red = imagecolorallocate($img, 255, 80, 80);
        $white = imagecolorallocate($img, 220, 220, 220);
        imagefill($img, 0, 0, $bg);
        
        // Red header
        imagefilledrectangle($img, 0, 0, $w, 30, $red);
        imagestring($img, 3, 10, 10, 'JOPG Image Error', $white);
        
        // Word-wrap the message
        $lines = explode("\n", $message);
        $y = 45;
        foreach ($lines as $line) {
            $words = explode(' ', $line);
            $current = '';
            foreach ($words as $word) {
                $test = $current ? $current . ' ' . $word : $word;
                if (strlen($test) * 6 > $w - 20) {
                    imagestring($img, 2, 10, $y, $current, $white);
                    $y += 18;
                    $current = $word;
                } else {
                    $current = $test;
                }
            }
            if ($current) {
                imagestring($img, 2, 10, $y, $current, $white);
                $y += 18;
            }
        }
        
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store, no-cache');
        imagejpeg($img, null, 80);
        imagedestroy($img);
    }
    
    private function apply_watermark($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        $watermark_text = JOPG_DB::get_setting('watermark_text', 'Jens Ole Photography');
        $opacity = intval(JOPG_DB::get_setting('watermark_opacity', '30'));
        $position = JOPG_DB::get_setting('watermark_position', 'center');
        
        $font_path = JOPG_PATH . 'assets/fonts/Montserrat-Medium.ttf';
        $use_ttf = file_exists($font_path);
        
        $font_size = $use_ttf ? max(14, min($width, $height) / 12) : 5;
        
        if ($use_ttf) {
            $bbox = imagettfbbox($font_size, 0, $font_path, $watermark_text);
            $text_width = $bbox[2] - $bbox[0];
            $text_height = $bbox[1] - $bbox[7];
        } else {
            $text_width = strlen($watermark_text) * imagefontwidth(5);
            $text_height = imagefontheight(5);
        }
        
        $margin = 20;
        switch ($position) {
            case 'top-left':
                $x = $margin; $y = $margin + $text_height; break;
            case 'top-right':
                $x = $width - $text_width - $margin; $y = $margin + $text_height; break;
            case 'bottom-left':
                $x = $margin; $y = $height - $margin; break;
            case 'bottom-right':
                $x = $width - $text_width - $margin; $y = $height - $margin; break;
            case 'center':
            default:
                $x = ($width - $text_width) / 2;
                $y = ($height + $text_height) / 2;
                break;
        }
        
        $overlay = imagecreatetruecolor($width, $height);
        imagesavealpha($overlay, true);
        imagealphablending($overlay, false);
        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);
        imagealphablending($overlay, true);
        
        $text_color = imagecolorallocatealpha($overlay, 255, 255, 255, $opacity);
        
        if ($use_ttf) {
            imagettftext($overlay, $font_size, 0, $x, $y, $text_color, $font_path, $watermark_text);
        } else {
            imagestring($overlay, 5, $x, $y - $text_height, $watermark_text, $text_color);
        }
        
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imagecopy($image, $overlay, 0, 0, 0, 0, $width, $height);
        imagedestroy($overlay);
        
        return $image;
    }
    
    public static function get_watermarked_url($photo_id) {
        return home_url('/jopg/image/' . $photo_id . '/wm/');
    }
    
    public static function get_thumb_url($photo_id) {
        return home_url('/jopg/image/' . $photo_id . '/wm/thumb/');
    }
    
    public static function get_clean_url($photo_id, $token) {
        return home_url('/jopg/image/' . $photo_id . '/?token=' . $token);
    }
}
