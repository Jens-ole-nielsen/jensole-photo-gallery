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
        
        // Check watermarked output cache first — serve directly without GD processing
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
        if (!file_exists($cache_dir)) wp_mkdir_p($cache_dir);
        
        $out_key = $thumb ? 'wm_thumb_out_' . $photo->id : 'wm_out_' . $photo->id;
        $out_file = $cache_dir . '/' . $out_key . '.jpg';
        
        if (file_exists($out_file) && (time() - filemtime($out_file)) < (7 * DAY_IN_SECONDS) && filesize($out_file) > 100) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=3600');
            header('Content-Length: ' . filesize($out_file));
            readfile($out_file);
            exit;
        }
        
        // Not in output cache — fetch source bytes (also cached)
        $cache_key = $thumb ? 'wm_thumb_' . $photo->id : 'wm_' . $photo->id;
        $result = $this->get_source_bytes($url, $cache_key);
        if (is_wp_error($result)) { 
            $this->error_image('Lightroom fetch failed: ' . $result->get_error_message() . '\nURL: ' . substr($url, 0, 80));
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
        
        // Save to output cache so subsequent requests skip GD processing entirely
        imagejpeg($image, $out_file, 90);
        
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . filesize($out_file));
        readfile($out_file);
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
    
    public function apply_watermark($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        $wm_type = JOPG_DB::get_setting('watermark_type', 'text');
        $opacity = intval(JOPG_DB::get_setting('watermark_opacity', '30'));
        // Convert opacity (0-100, lower=transparent) to GD alpha (0=opaque, 127=transparent)
        $alpha = 127 - intval($opacity * 1.27);
        
        if ($wm_type === 'logo' || $wm_type === 'pattern') {
            $logo_path = JOPG_DB::get_setting('watermark_logo_path', '');
            if ($logo_path && file_exists($logo_path)) {
                return $this->apply_logo_watermark($image, $logo_path, $wm_type, $alpha);
            }
            // No logo uploaded — fall through to text
        }
        
        return $this->apply_text_watermark($image, $alpha);
    }
    
    /**
     * Apply text watermark
     */
    private function apply_text_watermark($image, $alpha) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        $watermark_text = JOPG_DB::get_setting('watermark_text', 'Jens Ole Photography');
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
        $positions = [
            'top-left'     => [$margin, $margin + $text_height],
            'top-right'    => [$width - $text_width - $margin, $margin + $text_height],
            'bottom-left'  => [$margin, $height - $margin],
            'bottom-right' => [$width - $text_width - $margin, $height - $margin],
            'center'       => [($width - $text_width) / 2, ($height + $text_height) / 2],
        ];
        $pos = $positions[$position] ?? $positions['center'];
        
        $overlay = imagecreatetruecolor($width, $height);
        imagesavealpha($overlay, true);
        imagealphablending($overlay, false);
        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);
        imagealphablending($overlay, true);
        
        $text_color = imagecolorallocatealpha($overlay, 255, 255, 255, $alpha);
        
        if ($use_ttf) {
            imagettftext($overlay, $font_size, 0, $pos[0], $pos[1], $text_color, $font_path, $watermark_text);
        } else {
            imagestring($overlay, 5, $pos[0], $pos[1] - $text_height, $watermark_text, $text_color);
        }
        
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imagecopy($image, $overlay, 0, 0, 0, 0, $width, $height);
        imagedestroy($overlay);
        
        return $image;
    }
    
    /**
     * Apply logo watermark — single or pattern (net)
     */
    private function apply_logo_watermark($image, $logo_path, $mode, $alpha) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Load logo
        $logo_info = @getimagesize($logo_path);
        if (!$logo_info) return $image;
        
        $mime = $logo_info['mime'];
        if ($mime === 'image/png') {
            $logo = @imagecreatefrompng($logo_path);
        } elseif ($mime === 'image/jpeg') {
            $logo = @imagecreatefromjpeg($logo_path);
        } elseif ($mime === 'image/gif') {
            $logo = @imagecreatefromgif($logo_path);
        } else {
            return $image;
        }
        if (!$logo) return $image;
        
        imagealphablending($logo, true);
        imagesavealpha($logo, true);
        $logo_w = imagesx($logo);
        $logo_h = imagesy($logo);
        
        // Scale logo to desired size (% of image width)
        $size_pct = intval(JOPG_DB::get_setting('watermark_size', '30'));
        $target_w = max(20, intval($width * $size_pct / 100));
        $scale = $target_w / $logo_w;
        $target_h = intval($logo_h * $scale);
        
        $scaled_logo = imagecreatetruecolor($target_w, $target_h);
        imagesavealpha($scaled_logo, true);
        imagealphablending($scaled_logo, false);
        $transparent = imagecolorallocatealpha($scaled_logo, 0, 0, 0, 127);
        imagefill($scaled_logo, 0, 0, $transparent);
        imagealphablending($scaled_logo, true);
        imagecopyresampled($scaled_logo, $logo, 0, 0, 0, 0, $target_w, $target_h, $logo_w, $logo_h);
        imagedestroy($logo);
        
        // Apply opacity to the scaled logo
        $opacity_pct = intval(JOPG_DB::get_setting('watermark_opacity', '30'));
        
        if ($mode === 'pattern') {
            // Tile the logo across the entire image
            $spacing = max($target_w + 20, intval(JOPG_DB::get_setting('watermark_spacing', '100')));
            $step_x = $spacing;
            $step_y = intval($spacing * $target_h / $target_w);
            if ($step_y < 20) $step_y = $spacing;
            
            // Offset rows for a more organic pattern
            $row = 0;
            for ($y = 0; $y < $height; $y += $step_y) {
                $x_offset = ($row % 2) ? intval($step_x / 2) : 0;
                for ($x = -$target_w + $x_offset; $x < $width; $x += $step_x) {
                    $this->stamp_logo($image, $scaled_logo, $x, $y, $target_w, $target_h, $opacity_pct);
                }
                $row++;
            }
        } else {
            // Single logo — position it
            $position = JOPG_DB::get_setting('watermark_position', 'center');
            $margin = 20;
            $positions = [
                'top-left'     => [$margin, $margin],
                'top-right'    => [$width - $target_w - $margin, $margin],
                'bottom-left'  => [$margin, $height - $target_h - $margin],
                'bottom-right' => [$width - $target_w - $margin, $height - $target_h - $margin],
                'center'       => [intval(($width - $target_w) / 2), intval(($height - $target_h) / 2)],
            ];
            $pos = $positions[$position] ?? $positions['center'];
            $this->stamp_logo($image, $scaled_logo, $pos[0], $pos[1], $target_w, $target_h, $opacity_pct);
        }
        
        imagedestroy($scaled_logo);
        return $image;
    }
    
    /**
     * Stamp a logo onto the target image with opacity
     */
    private function stamp_logo($image, $logo, $x, $y, $w, $h, $opacity_pct) {
        // NOTE: imagecopymerge() does NOT respect a PNG's real alpha channel —
        // it treats transparent pixels' leftover RGB data (often black) as
        // opaque color, painting solid black rectangles instead of blending
        // through. Fixed here with manual per-pixel alpha compositing, which
        // correctly reads the logo's true transparency and only paints pixels
        // that actually have visible content.
        $img_w = imagesx($image);
        $img_h = imagesy($image);
        $logo_w = imagesx($logo);
        $logo_h = imagesy($logo);
        
        // Clamp position to image bounds, adjusting source offset if we clip the left/top
        $src_x_offset = 0;
        $src_y_offset = 0;
        if ($x < 0) { $src_x_offset = -$x; $w += $x; $x = 0; }
        if ($y < 0) { $src_y_offset = -$y; $h += $y; $y = 0; }
        if ($x + $w > $img_w) $w = $img_w - $x;
        if ($y + $h > $img_h) $h = $img_h - $y;
        if ($src_x_offset + $w > $logo_w) $w = $logo_w - $src_x_offset;
        if ($src_y_offset + $h > $logo_h) $h = $logo_h - $src_y_offset;
        if ($w <= 0 || $h <= 0) return;
        
        $opacity_factor = max(0, min(100, $opacity_pct)) / 100;
        if ($opacity_factor <= 0) return;
        
        imagealphablending($image, false);
        
        for ($j = 0; $j < $h; $j++) {
            $sy = $j + $src_y_offset;
            $dy = $y + $j;
            for ($i = 0; $i < $w; $i++) {
                $sx = $i + $src_x_offset;
                
                $src_rgba = imagecolorat($logo, $sx, $sy);
                $src_alpha = ($src_rgba >> 24) & 0x7F; // GD alpha: 0 = opaque, 127 = fully transparent
                if ($src_alpha >= 127) continue; // fully transparent pixel — skip, leave photo untouched
                
                $src_r = ($src_rgba >> 16) & 0xFF;
                $src_g = ($src_rgba >> 8) & 0xFF;
                $src_b = $src_rgba & 0xFF;
                
                // Combine the logo's own transparency with the configured watermark opacity
                $blend = (1 - $src_alpha / 127) * $opacity_factor;
                if ($blend <= 0.004) continue; // negligible, skip for speed
                
                $dx = $x + $i;
                $dst_rgba = imagecolorat($image, $dx, $dy);
                $dst_r = ($dst_rgba >> 16) & 0xFF;
                $dst_g = ($dst_rgba >> 8) & 0xFF;
                $dst_b = $dst_rgba & 0xFF;
                
                $new_r = (int)round($dst_r * (1 - $blend) + $src_r * $blend);
                $new_g = (int)round($dst_g * (1 - $blend) + $src_g * $blend);
                $new_b = (int)round($dst_b * (1 - $blend) + $src_b * $blend);
                
                $color = imagecolorallocate($image, $new_r, $new_g, $new_b);
                imagesetpixel($image, $dx, $dy, $color);
            }
        }
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
