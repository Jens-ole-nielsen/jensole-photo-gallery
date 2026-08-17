<?php
if (!defined('ABSPATH')) exit;

class JOPG_Lightroom {
    
    private static $instance = null;
    private $client_id = '';
    private $client_secret = '';
    private $api_base = 'https://lr.adobe.io/v2';
    private $ims_auth_base = 'https://ims-na1.adobelogin.com/ims/authorize/v2';
    private $ims_token_base = 'https://ims-na1.adobelogin.com/ims/token/v3';
    
    // Lightroom API requires 3-legged OAuth (user consent) — no server-to-server flow exists.
    const SCOPES = 'openid,lr_partner_apis,offline_access';
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        $this->client_id = JOPG_DB::get_setting('adobe_client_id', '');
        $this->client_secret = JOPG_DB::get_setting('adobe_client_secret', '');
        
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('jopg_sync_lightroom', [$this, 'sync_all_albums']);
        add_action('wp_ajax_jopg_sync_albums', [$this, 'ajax_sync_albums']);
        add_action('wp_ajax_jopg_import_album', [$this, 'ajax_import_album']);
        add_action('wp_ajax_jopg_prewarm_cache', [$this, 'ajax_prewarm_cache']);
        add_action('wp_ajax_jopg_import_album_batch', [$this, 'ajax_import_album_batch']);
        add_action('wp_ajax_jopg_prewarm_background_start', [$this, 'ajax_prewarm_background_start']);
        add_action('wp_ajax_jopg_prewarm_background_status', [$this, 'ajax_prewarm_background_status']);
        add_action('wp_ajax_jopg_prewarm_background_stop', [$this, 'ajax_prewarm_background_stop']);
        add_action('wp_ajax_jopg_set_album_filter', [$this, 'ajax_set_album_filter']);
        add_action('wp_ajax_jopg_cleanup_filtered_photos', [$this, 'ajax_cleanup_filtered_photos']);
        add_action('jopg_background_prewarm', [$this, 'cron_prewarm_batch']);
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action('wp_ajax_jopg_hide_album', [$this, 'ajax_hide_album']);
        add_action('wp_ajax_jopg_restore_album', [$this, 'ajax_restore_album']);
        add_action('wp_ajax_jopg_assign_gallery', [$this, 'ajax_assign_gallery']);
        
        // OAuth connect flow — admin only
        add_action('admin_post_jopg_lightroom_connect', [$this, 'start_oauth']);
        add_action('admin_post_jopg_lightroom_callback', [$this, 'handle_oauth_callback']);
        
        // Public callback route (Adobe redirects here — must not require WP login)
        // Belt-and-suspenders: proper rewrite rule (survives permalink flush) + raw REQUEST_URI fallback
        add_action('init', [$this, 'register_callback_rewrite']);
        add_filter('query_vars', function($vars) { $vars[] = 'jopg_lr_callback'; return $vars; });
        add_action('template_redirect', [$this, 'maybe_handle_public_callback']);
        add_action('init', [$this, 'maybe_handle_public_callback_early'], 1);
    }
    
    public function register_callback_rewrite() {
        add_rewrite_rule('^jopg-lightroom-callback/?$', 'index.php?jopg_lr_callback=1', 'top');
    }
    
    /**
     * Fallback: catch the callback even if rewrite rules haven't been flushed yet
     * (runs very early on 'init', before any 404 logic)
     */
    public function maybe_handle_public_callback_early() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, 'jopg-lightroom-callback') === false) return;
        // Only handle here if there's actually an OAuth response — otherwise let normal routing continue
        if (!isset($_GET['code']) && !isset($_GET['error'])) return;
        $this->process_oauth_callback();
    }
    
    /**
     * Get the redirect URI we registered in Adobe Developer Console
     */
    public function get_redirect_uri() {
        return home_url('/jopg-lightroom-callback/');
    }
    
    /**
     * Step 1: Redirect admin to Adobe's consent screen
     */
    public function start_oauth() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('jopg_lightroom_connect');
        
        if (empty($this->client_id)) {
            wp_die('Please save your Adobe Client ID and Secret first under Photo Gallery → Settings.');
        }
        
        $state = wp_generate_password(24, false);
        set_transient('jopg_oauth_state', $state, 10 * MINUTE_IN_SECONDS);
        
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ];
        
        $auth_url = $this->ims_auth_base . '?' . http_build_query($params);
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Rewrite-based public callback (Adobe redirects unauthenticated browser here).
     * Fires on template_redirect when our query var matches (reliable once permalinks are flushed).
     */
    public function maybe_handle_public_callback() {
        if (!get_query_var('jopg_lr_callback')) {
            // Fallback: also check raw URI in case rewrite rule isn't registered yet
            if (strpos($_SERVER['REQUEST_URI'] ?? '', 'jopg-lightroom-callback') === false) return;
        }
        $this->process_oauth_callback();
    }
    
    /**
     * Shared logic: exchange the Adobe authorization code for tokens and redirect back to admin.
     */
    private function process_oauth_callback() {
        $code = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $error = $_GET['error'] ?? '';
        
        if ($error) {
            wp_die('Adobe authorization failed: ' . esc_html($error) . '. <a href="' . esc_url(admin_url('admin.php?page=jopg-sync')) . '">Try again</a>');
        }
        
        $saved_state = get_transient('jopg_oauth_state');
        if (!$code || !$saved_state || $state !== $saved_state) {
            wp_die('Invalid or expired authorization request. <a href="' . esc_url(admin_url('admin.php?page=jopg-sync')) . '">Try connecting again</a> from Photo Gallery → Lightroom Sync.');
        }
        delete_transient('jopg_oauth_state');
        
        $result = $this->exchange_code_for_tokens($code);
        
        if (is_wp_error($result)) {
            wp_die('Failed to connect to Adobe Lightroom: ' . esc_html($result->get_error_message()) . ' <a href="' . esc_url(admin_url('admin.php?page=jopg-sync')) . '">Try again</a>');
        }
        
        wp_redirect(admin_url('admin.php?page=jopg-sync&connected=1'));
        exit;
    }
    
    /**
     * Step 2: Exchange authorization code for access + refresh tokens
     */
    private function exchange_code_for_tokens($code) {
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $this->get_redirect_uri(),
        ]);
        
        $response = wp_remote_post($this->ims_token_base, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $body,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) return $response;
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($data['access_token'])) {
            return new WP_Error('auth_failed', $data['error_description'] ?? 'Unknown error exchanging code');
        }
        
        JOPG_DB::set_setting('adobe_access_token', $data['access_token']);
        JOPG_DB::set_setting('adobe_token_expires', time() + ($data['expires_in'] ?? 3600));
        
        if (!empty($data['refresh_token'])) {
            JOPG_DB::set_setting('adobe_refresh_token', $data['refresh_token']);
        }
        
        return true;
    }
    
    /**
     * Get a valid access token — refreshes automatically using the refresh_token
     */
    public function get_access_token() {
        $cached = JOPG_DB::get_setting('adobe_access_token', '');
        $expires = intval(JOPG_DB::get_setting('adobe_token_expires', 0));
        
        if ($cached && $expires > (time() + 60)) {
            return $cached;
        }
        
        $refresh_token = JOPG_DB::get_setting('adobe_refresh_token', '');
        if (empty($refresh_token)) {
            return new WP_Error('not_connected', 'Not connected to Adobe Lightroom yet. Go to Photo Gallery → Lightroom Sync and click "Connect to Adobe".');
        }
        
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ]);
        
        $response = wp_remote_post($this->ims_token_base, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $body,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) return $response;
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($data['access_token'])) {
            return new WP_Error('refresh_failed', $data['error_description'] ?? 'Could not refresh access token. Try reconnecting.');
        }
        
        JOPG_DB::set_setting('adobe_access_token', $data['access_token']);
        JOPG_DB::set_setting('adobe_token_expires', time() + ($data['expires_in'] ?? 3600));
        
        // Adobe may rotate the refresh token
        if (!empty($data['refresh_token'])) {
            JOPG_DB::set_setting('adobe_refresh_token', $data['refresh_token']);
        }
        
        return $data['access_token'];
    }
    
    public function is_connected() {
        return !empty(JOPG_DB::get_setting('adobe_refresh_token', ''));
    }
    
    /**
     * Resolve a (possibly relative) href against a base URL, HATEOAS-style.
     * Adobe Lightroom API returns a "base" per resource that may point to a
     * different host/path than the generic https://lr.adobe.io/v2 root —
     * hardcoding paths against the wrong base causes 404 {"code":1000,...} errors.
     */
    private function resolve_url($base, $href) {
        if (empty($href)) return $base;
        if (preg_match('#^https?://#i', $href)) return $href; // already absolute
        if (strpos($href, '/') === 0) {
            // domain-absolute path — keep scheme+host from $base, replace path
            $parts = wp_parse_url($base);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? 'lr.adobe.io';
            return $scheme . '://' . $host . $href;
        }
        // relative to base directory
        return rtrim($base, '/') . '/' . ltrim($href, '/');
    }
    
    /**
     * Get the base URL to use for catalog-nested calls (albums, assets, renditions).
     * This is learned dynamically from the /catalog response and cached — falls back
     * to the generic root if we haven't fetched the catalog yet.
     */
    private function get_catalog_base() {
        return JOPG_DB::get_setting('adobe_catalog_base', $this->api_base);
    }
    
    /**
     * Make an authenticated API call to Lightroom.
     * $endpoint may be a full absolute URL (preferred — from a prior response's "links")
     * or a path relative to the generic API root (used only for /account and /catalog).
     */
    private function api_call($endpoint, $method = 'GET', $body = null) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;
        
        $url = preg_match('#^https?://#i', $endpoint) ? $endpoint : ($this->api_base . $endpoint);
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => $this->client_id,
            'Content-Type' => 'application/json'
        ];
        
        $args = [
            'headers' => $headers,
            'method' => $method,
            'timeout' => 30
        ];
        if ($body) $args['body'] = json_encode($body);
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) return $response;
        
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        
        // Lightroom API prefixes JSON responses with a security header: while (1) {}
        $raw = preg_replace('/^while\s*\(1\)\s*\{\s*\}/', '', $raw);
        $body = json_decode($raw, true);
        
        if ($code >= 400) {
            return new WP_Error('api_error', "API returned $code ($url): " . $raw);
        }
        
        return $body;
    }
    
    /**
     * Get the account/catalog info for the connected user.
     * Persists the catalog's own "base" URL — required for all catalog-nested calls.
     */
    public function get_catalog() {
        $result = $this->api_call('/catalog');
        if (is_wp_error($result)) return $result;
        
        if (!empty($result['base'])) {
            JOPG_DB::set_setting('adobe_catalog_base', rtrim($result['base'], '/'));
        }
        
        return $result;
    }
    
    /**
     * Get all albums in the catalog
     */
    public function get_albums($catalog_id) {
        $all = [];
        $catalog_base = $this->get_catalog_base();
        // Adobe's pagination "next" links are relative to the catalog resource,
        // but they OMIT the "catalogs/{id}/" prefix — so we must always reconstruct
        // the full path ourselves to avoid 404 {"code":1000} errors.
        $albums_path = "catalogs/$catalog_id/albums";
        $next = $this->resolve_url($catalog_base, $albums_path . "?limit=100");
        
        do {
            $result = $this->api_call($next);
            if (is_wp_error($result)) return $result;
            
            if (isset($result['resources'])) {
                $all = array_merge($all, $result['resources']);
            }
            
            $next = null;
            if (isset($result['links']['next']['href'])) {
                $href = $result['links']['next']['href'];
                // Extract just the query string from the next link
                $query = '';
                $qpos = strpos($href, '?');
                if ($qpos !== false) {
                    $query = substr($href, $qpos); // includes the ?
                }
                // Reconstruct with the correct catalog path
                $next = $this->resolve_url($catalog_base, $albums_path . $query);
            }
        } while ($next);
        
        return $all;
    }
    
    /**
     * Count assets in an album — lightweight (no embed=asset, just asset references).
     * Paginates through all pages to get an accurate count.
     * Much faster than get_album_assets() because it doesn't embed full asset data.
     */
    public function count_album_assets($catalog_id, $album_id) {
        // Single API call — no pagination. Fast, won't hang.
        // Returns exact count if API provides it, otherwise count of first page resources.
        $catalog_base = $this->get_catalog_base();
        $assets_path = "catalogs/$catalog_id/albums/$album_id/assets";
        $url = $this->resolve_url($catalog_base, $assets_path . "?limit=100");
        $result = $this->api_call($url);
        if (is_wp_error($result)) return $result;
        
        // Check if API provides a total count
        if (isset($result['total'])) return intval($result['total']);
        if (isset($result['count'])) return intval($result['count']);
        
        // Otherwise count resources in the first page
        $count = isset($result['resources']) ? count($result['resources']) : 0;
        
        // If we got exactly 100, there are probably more — indicate 100+
        if ($count === 100 && isset($result['links']['next']['href'])) {
            return 100; // At least 100, exact count would need pagination
        }
        
        return $count;
    }
    
    /**
     * Get assets (photos) in an album
     */
    public function get_album_assets($catalog_id, $album_id) {
        $all_assets = [];
        $catalog_base = $this->get_catalog_base();
        $assets_path = "catalogs/$catalog_id/albums/$album_id/assets";
        $next = $this->resolve_url($catalog_base, $assets_path . "?limit=100&embed=asset");
        
        do {
            $result = $this->api_call($next);
            if (is_wp_error($result)) return $result;
            
            if (isset($result['resources'])) {
                $all_assets = array_merge($all_assets, $result['resources']);
            }
            
            $next = null;
            if (isset($result['links']['next']['href'])) {
                $href = $result['links']['next']['href'];
                $query = '';
                $qpos = strpos($href, '?');
                if ($qpos !== false) {
                    $query = substr($href, $qpos);
                }
                $next = $this->resolve_url($catalog_base, $assets_path . $query);
            }
        } while ($next);
        
        return $all_assets;
    }
    
    /**
     * Get a specific asset's rendition (image URL) — returns raw binary via redirect link
     */
    public function get_asset_rendition_url($catalog_id, $asset_id, $size = 'fullsize') {
        // Rendition sizes: thumbnail2x, 640, 1280, 2048, fullsize
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;
        
        $catalog_base = $this->get_catalog_base();
        return $this->resolve_url($catalog_base, "catalogs/$catalog_id/assets/$asset_id/renditions/$size");
    }
    
    /**
     * Get asset metadata
     */
    public function get_asset_metadata($catalog_id, $asset_id) {
        $catalog_base = $this->get_catalog_base();
        return $this->api_call($this->resolve_url($catalog_base, "catalogs/$catalog_id/assets/$asset_id"));
    }
    
    /**
     * Sync all albums from Lightroom
     */
    public function sync_all_albums() {
        // Safety net: with many albums, the per-album empty-check loop can take a while.
        // Raise the execution time limit for this request (works unless host disables it).
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        
        $catalog = $this->get_catalog();
        if (is_wp_error($catalog)) return $catalog;
        
        $catalog_id = $catalog['id'] ?? null;
        if (!$catalog_id) return new WP_Error('no_catalog', 'Could not determine catalog ID');
        
        $albums = $this->get_albums($catalog_id);
        if (is_wp_error($albums)) return $albums;
        
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $synced = 0;
        $skipped_empty = 0;
        
        foreach ($albums as $album) {
            $lr_id = $album['id'];
            $payload = $album['payload'] ?? [];
            $title = $payload['name'] ?? 'Untitled';
            
            // Skip non-album types (e.g. folders) — 'set' type is a real album
            $subtype = $payload['userCreated'] ?? true;
            
            // Check if album has any photos — skip empty albums to keep the gallery clean
            $asset_count = $payload['assetCount'] ?? null;
            if ($asset_count !== null && intval($asset_count) === 0) {
                $skipped_empty++;
                continue; // Empty album — skip
            }
            
            // If assetCount is not in the payload, count assets (lightweight — no embed=asset)
            if ($asset_count === null) {
                $count = $this->count_album_assets($catalog_id, $lr_id);
                if (is_wp_error($count)) {
                    $skipped_empty++;
                    continue; // Error — skip
                }
                if ($count === 0) {
                    $skipped_empty++;
                    continue; // Empty album — skip
                }
                $asset_count = $count;
            }
            
            $slug = sanitize_title($title) . '-' . substr($lr_id, -6);
            
            // Check if album already exists — preserve the ID so imported photos keep their reference
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_albums WHERE lightroom_album_id = %s", $lr_id
            ));
            
            $album_data = [
                'lightroom_catalog_id' => $catalog_id,
                'title' => $title,
                'slug' => $slug,
                'photo_count' => intval($asset_count ?? 0),
                'synced_at' => current_time('mysql'),
                'status' => 'active'
            ];
            
            if ($existing_id) {
                // Update existing album — preserves id so jopg_photos.album_id stays valid
                // BUT preserve 'hidden' status if user has manually excluded this album
                $existing_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM $table_albums WHERE id = %d", $existing_id
                ));
                if ($existing_status === 'hidden') {
                    $album_data['status'] = 'hidden';
                }
                $wpdb->update($table_albums, $album_data, ['id' => $existing_id]);
            } else {
                // New album — insert
                $album_data['lightroom_album_id'] = $lr_id;
                $wpdb->insert($table_albums, $album_data);
            }
            
            $synced++;
        }
        
        return ['synced' => $synced, 'skipped' => $skipped_empty, 'total' => count($albums)];
    }
    
    /**
     * Import all photos from a specific album
     */
    public function import_album_photos($album_db_id, $offset = 0, $limit = 0, $skip_prewarm = false) {
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", $album_db_id));
        if (!$album) return new WP_Error('no_album', 'Album not found');
        
        // Fetch all assets — we need the full list to know the total
        // The API paginates at 100 per page, so this is just API calls (fast, no image bytes)
        $assets = $this->get_album_assets($album->lightroom_catalog_id, $album->lightroom_album_id);
        if (is_wp_error($assets)) return $assets;
        
        // Apply this album's sync filter (flag + min star rating) BEFORE counting/slicing,
        // so the progress bar and batching only ever see the photos we actually want.
        $filter_flag = $album->filter_flag ?? 'all';
        $min_rating = intval($album->filter_min_rating ?? 0);
        
        if ($filter_flag !== 'all' || $min_rating > 0) {
            $assets = array_values(array_filter($assets, function($item) use ($filter_flag, $min_rating) {
                $asset = $item['asset'] ?? $item;
                $payload = $asset['payload'] ?? [];
                return $this->photo_matches_filter($payload['flag'] ?? 'unflagged', $payload['rating'] ?? 0, $filter_flag, $min_rating);
            }));
        }
        
        $total_assets = count($assets);
        
        // Apply offset/limit for batched import
        if ($limit > 0) {
            $assets = array_slice($assets, $offset, $limit);
        } else if ($offset > 0) {
            $assets = array_slice($assets, $offset);
        }
        
        $imported = 0;
        $price = floatval(JOPG_DB::get_setting('single_price', '49'));
        
        foreach ($assets as $item) {
            $asset = $item['asset'] ?? $item;
            $asset_id = $asset['id'] ?? ($item['id'] ?? null);
            if (!$asset_id) continue;
            
            $payload = $asset['payload'] ?? [];
            $importSource = $payload['importSource'] ?? [];
            $filename = $importSource['fileName'] ?? ('photo_' . $asset_id . '.jpg');
            
            $dev = $payload['develop'] ?? [];
            $width = $payload['width'] ?? 0;
            $height = $payload['height'] ?? 0;
            $capture_date = $payload['captureDate'] ?? null;
            
            // We proxy renditions through our own endpoint so we can control auth + watermarking server-side
            $thumb_url = $this->get_asset_rendition_url($album->lightroom_catalog_id, $asset_id, 'thumbnail2x');
            $display_url = $this->get_asset_rendition_url($album->lightroom_catalog_id, $asset_id, '2048');
            $original_url = $this->get_asset_rendition_url($album->lightroom_catalog_id, $asset_id, 'fullsize');
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_photos WHERE album_id = %d AND lightroom_asset_id = %s",
                $album_db_id, $asset_id
            ));
            
            $data = [
                'album_id' => $album_db_id,
                'lightroom_asset_id' => $asset_id,
                'filename' => $filename,
                'thumb_url' => $thumb_url,
                'display_url' => $display_url,
                'original_url' => $original_url,
                'width' => intval($width),
                'height' => intval($height),
                'capture_date' => $capture_date ? date('Y-m-d H:i:s', strtotime($capture_date)) : null,
                'exif_data' => json_encode($payload),
                'rating' => intval($payload['rating'] ?? 0),
                'flag' => $payload['flag'] ?? 'unflagged',
                'price' => $price,
            ];
            
            if ($existing) {
                $wpdb->update($table_photos, $data, ['id' => $existing]);
                $photo_id = $existing;
            } else {
                $wpdb->insert($table_photos, $data);
                $photo_id = $wpdb->insert_id;
            }
            
            // Create WooCommerce product for this photo (metadata only, no image fetch)
            if (class_exists('WooCommerce')) {
                $photo_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_photos WHERE id = %d", $photo_id));
                do_action('jopg_photo_imported', $photo_id, $photo_row);
            }
            
            $imported++;
        }
        
        // Only update album counts when the full import is done (not a partial batch)
        $is_final_batch = ($limit > 0 && ($offset + $limit >= $total_assets)) || ($limit === 0);
        if ($is_final_batch) {
            // Count actual photos in DB for this album (handles re-syncs correctly)
            $db_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_photos WHERE album_id = %d", $album_db_id)));
            $wpdb->update($table_albums, ['photo_count' => $db_count, 'synced_at' => current_time('mysql')], ['id' => $album_db_id]);
        }
        
        return [
            'imported' => $imported,
            'total_assets' => $total_assets,
            'offset' => $offset,
            'limit' => $limit,
            'is_final_batch' => $is_final_batch,
        ];
    }
    
    /**
     * Check whether a photo's flag/rating satisfies an album's sync filter.
     * $filter_flag: 'all' (no filter) | 'flagged' (Pick only) | 'not_rejected' (exclude Reject)
     * $min_rating: 0 (no filter) or 1-5 (minimum stars required)
     */
    private function photo_matches_filter($flag, $rating, $filter_flag, $min_rating) {
        $flag = $flag ?: 'unflagged';
        $rating = intval($rating);
        
        if ($filter_flag === 'flagged' && $flag !== 'flagged') return false;
        if ($filter_flag === 'not_rejected' && $flag === 'rejected') return false;
        if ($min_rating > 0 && $rating < $min_rating) return false;
        
        return true;
    }
    
    /**
     * AJAX: Save an album's sync filter (flag requirement + min star rating).
     * Applied on the NEXT import — does not touch already-imported photos.
     */
    public function ajax_set_album_filter() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        
        $album_id = intval($_POST['album_id'] ?? 0);
        $filter_flag = sanitize_text_field($_POST['filter_flag'] ?? 'all');
        $min_rating = intval($_POST['min_rating'] ?? 0);
        
        if (!$album_id) wp_send_json_error('Invalid album ID');
        if (!in_array($filter_flag, ['all', 'flagged', 'not_rejected'], true)) $filter_flag = 'all';
        $min_rating = max(0, min(5, $min_rating));
        
        $wpdb->update($table_albums, [
            'filter_flag' => $filter_flag,
            'filter_min_rating' => $min_rating,
        ], ['id' => $album_id]);
        
        wp_send_json_success([
            'album_id' => $album_id,
            'filter_flag' => $filter_flag,
            'min_rating' => $min_rating,
        ]);
    }
    
    /**
     * AJAX: Delete already-imported photos that don't match the album's
     * CURRENT sync filter. Useful when you tighten a filter after already
     * importing everything (e.g. large albums synced before filters existed).
     * Removes the WooCommerce product and cached files too.
     */
    public function ajax_cleanup_filtered_photos() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $album_id = intval($_POST['album_id'] ?? 0);
        if (!$album_id) wp_send_json_error('Invalid album ID');
        
        $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", $album_id));
        if (!$album) wp_send_json_error('Album not found');
        
        $filter_flag = $album->filter_flag ?? 'all';
        $min_rating = intval($album->filter_min_rating ?? 0);
        
        if ($filter_flag === 'all' && $min_rating === 0) {
            wp_send_json_error('This album has no sync filter set — nothing to clean up.');
        }
        
        $photos = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_photos WHERE album_id = %d", $album_id));
        
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
        $deleted = 0;
        
        foreach ($photos as $photo) {
            if ($this->photo_matches_filter($photo->flag, $photo->rating, $filter_flag, $min_rating)) {
                continue; // matches filter — keep it
            }
            
            // Delete WooCommerce product if one was created
            if (!empty($photo->wc_product_id) && class_exists('WooCommerce')) {
                wp_delete_post($photo->wc_product_id, true);
            }
            
            // Delete cached image files
            foreach ([
                $cache_dir . '/wm_thumb_out_' . $photo->id . '.jpg',
                $cache_dir . '/wm_out_' . $photo->id . '.jpg',
                $cache_dir . '/wm_thumb_' . $photo->id . '.jpg',
            ] as $file) {
                if (file_exists($file)) @unlink($file);
            }
            
            $wpdb->delete($table_photos, ['id' => $photo->id]);
            $deleted++;
        }
        
        $db_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_photos WHERE album_id = %d", $album_id)));
        $wpdb->update($table_albums, ['photo_count' => $db_count], ['id' => $album_id]);
        
        wp_send_json_success([
            'deleted' => $deleted,
            'remaining' => $db_count,
        ]);
    }
    
    /**
     * Pre-generate watermarked thumbnail during import.
     * Fetches the thumbnail rendition from Adobe, applies watermark, saves to disk cache.
     * This makes gallery viewing instant — no need to go through PHP proxy on first view.
     */
    public function pregenerate_thumbnail($photo_id, $thumb_url) {
        if (!$thumb_url) return;
        
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
        if (!file_exists($cache_dir)) wp_mkdir_p($cache_dir);
        
        $out_file = $cache_dir . '/wm_thumb_out_' . $photo_id . '.jpg';
        
        // Skip if already cached and fresh
        if (file_exists($out_file) && (time() - filemtime($out_file)) < (7 * DAY_IN_SECONDS) && filesize($out_file) > 100) {
            return;
        }
        
        // Fetch the thumbnail bytes from Adobe
        $source_file = $cache_dir . '/wm_thumb_' . $photo_id . '.jpg';
        $result = $this->fetch_rendition_bytes($thumb_url);
        if (is_wp_error($result)) return;
        
        // Cache the raw bytes
        file_put_contents($source_file, $result['body']);
        
        // Load into GD, apply watermark, save
        $image = @imagecreatefromstring($result['body']);
        if ($image === false) return;
        
        $wm = JOPG_Watermark::instance();
        $image = $wm->apply_watermark($image);
        
        imagejpeg($image, $out_file, 90);
        imagedestroy($image);
    }
    
    /**
     * Fetch raw image bytes for a rendition (used by watermark proxy)
     */
    public function fetch_rendition_bytes($rendition_url) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;
        
        // IMPORTANT: Adobe's rendition endpoint replies with a 3xx redirect to a
        // pre-signed storage URL (e.g. S3) for the actual image bytes. We must NOT
        // let the HTTP client auto-follow that redirect while re-sending our
        // Authorization/x-api-key headers — the storage backend rejects requests
        // carrying an unexpected Authorization header (signature mismatch).
        // So: disable auto-redirect on the authenticated request, then follow the
        // Location manually with a clean, unauthenticated request.
        $response = wp_remote_get($rendition_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'x-api-key' => $this->client_id,
            ],
            'timeout' => 60,
            'redirection' => 0, // don't auto-follow — handle manually below
        ]);
        
        if (is_wp_error($response)) return $response;
        
        $code = wp_remote_retrieve_response_code($response);
        
        // Follow redirect manually, WITHOUT forwarding auth headers
        if ($code >= 300 && $code < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location) {
                return new WP_Error('fetch_failed', "Rendition redirect ($code) had no Location header");
            }
            $response = wp_remote_get($location, [
                'timeout' => 60,
                'redirection' => 3,
            ]);
            if (is_wp_error($response)) return $response;
            $code = wp_remote_retrieve_response_code($response);
        }
        
        if ($code >= 400) {
            return new WP_Error('fetch_failed', "Rendition fetch returned $code");
        }
        
        return [
            'body' => wp_remote_retrieve_body($response),
            'content_type' => wp_remote_retrieve_header($response, 'content-type') ?: 'image/jpeg'
        ];
    }
    
    /**
     * Register REST routes
     */
    public function register_routes() {
        register_rest_route('jopg/v1', '/lightroom/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_sync'],
            'permission_callback' => function() { return current_user_can('manage_options'); }
        ]);
        
        register_rest_route('jopg/v1', '/lightroom/albums', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_albums'],
            'permission_callback' => function() { return current_user_can('manage_options'); }
        ]);
    }
    
    public function rest_sync() {
        $result = $this->sync_all_albums();
        if (is_wp_error($result)) return $result;
        return ['success' => true, 'synced' => $result['synced'] ?? 0, 'skipped' => $result['skipped'] ?? 0, 'total' => $result['total'] ?? 0];
    }
    
    public function rest_get_albums() {
        global $wpdb;
        $table = $wpdb->prefix . 'jopg_albums';
        return $wpdb->get_results("SELECT * FROM $table ORDER BY title ASC");
    }
    
    public function ajax_sync_albums() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        
        $result = $this->sync_all_albums();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }
    
    public function ajax_import_album() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        
        $album_id = intval($_POST['album_id']);
        $result = $this->import_album_photos($album_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        // Backward compat: return integer if not batched
        if (is_array($result)) {
            wp_send_json_success(['photos_imported' => $result['imported'], 'total_assets' => $result['total_assets']]);
        } else {
            wp_send_json_success(['photos_imported' => $result]);
        }
    }
    
    /**
     * AJAX: Import album photos in batches (metadata only, no thumbnail fetching).
     * JS calls this repeatedly with increasing offset until all photos are imported.
     * After import is complete, JS auto-triggers pre-warm cache.
     */
    public function ajax_import_album_batch() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        
        $album_id = intval($_POST['album_id']);
        $offset = intval($_POST['offset'] ?? 0);
        $batch_size = intval($_POST['batch_size'] ?? 100);
        $batch_size = max(10, min($batch_size, 200)); // 10-200 per batch
        
        $result = $this->import_album_photos($album_id, $offset, $batch_size, true);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'imported' => $result['imported'],
            'total_assets' => $result['total_assets'],
            'offset' => $offset,
            'batch_size' => $batch_size,
            'done' => $offset + $result['imported'],
            'remaining' => max(0, $result['total_assets'] - ($offset + $result['imported'])),
            'is_final_batch' => $result['is_final_batch'],
        ]);
    }
    
    /**
     * AJAX: Pre-warm thumbnail cache in batches.
     * Processes N photos per call, JS loops until all done.
     */
    /**
     * AJAX: Assign album to a gallery
     */
    public function ajax_assign_gallery() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        
        $album_id = intval($_POST['album_id'] ?? 0);
        $gallery_id = intval($_POST['gallery_id'] ?? 0);
        
        if (!$album_id) wp_send_json_error('Invalid album ID');
        
        $wpdb->update($table_albums, ['gallery_id' => $gallery_id], ['id' => $album_id], ['%d'], ['%d']);
        
        wp_send_json_success([
            'album_id' => $album_id,
            'gallery_id' => $gallery_id,
        ]);
    }
    
    /**
     * AJAX: Hide (remove) an album — stops it from syncing and showing in gallery
     */
    public function ajax_hide_album() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $album_id = intval($_POST['album_id'] ?? 0);
        if (!$album_id) wp_send_json_error('Invalid album ID');
        
        // Set album status to hidden
        $wpdb->update($table_albums, ['status' => 'hidden'], ['id' => $album_id]);
        
        // Delete cached images for this album's photos
        $photos = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $table_photos WHERE album_id = %d", $album_id
        ));
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
        $deleted_files = 0;
        foreach ($photos as $photo) {
            foreach ([
                $cache_dir . '/wm_thumb_out_' . $photo->id . '.jpg',
                $cache_dir . '/wm_out_' . $photo->id . '.jpg',
                $cache_dir . '/wm_thumb_' . $photo->id . '.jpg',
            ] as $file) {
                if (file_exists($file)) { @unlink($file); $deleted_files++; }
            }
        }
        
        wp_send_json_success([
            'album_id' => $album_id,
            'deleted_cache_files' => $deleted_files,
        ]);
    }
    
    /**
     * AJAX: Restore a hidden album
     */
    public function ajax_restore_album() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        
        $album_id = intval($_POST['album_id'] ?? 0);
        if (!$album_id) wp_send_json_error('Invalid album ID');
        
        $wpdb->update($table_albums, ['status' => 'active'], ['id' => $album_id]);
        
        wp_send_json_success(['album_id' => $album_id]);
    }
    
    public function ajax_prewarm_cache() {
        check_ajax_referer('jopg_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $batch_size = intval($_POST['batch_size'] ?? 5);
        $offset = intval($_POST['offset'] ?? 0);
        $album_id = intval($_POST['album_id'] ?? 0);
        $batch_size = max(1, min($batch_size, 10)); // Max 10 per batch to avoid timeout
        
        // Filter by album if specified, otherwise cache all
        $where = $album_id > 0 ? $wpdb->prepare("WHERE album_id = %d", $album_id) : '';
        
        // Get photos that need caching
        $photos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, thumb_url, display_url FROM $table_photos $where ORDER BY id ASC LIMIT %d OFFSET %d",
            $batch_size, $offset
        ));
        
        $cached = 0;
        $failed = 0;
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_photos $where"));
        
        foreach ($photos as $photo) {
            // Pre-generate thumbnail
            $this->pregenerate_thumbnail($photo->id, $photo->thumb_url);
            
            // Also pre-generate display-size watermarked image (for lightbox)
            if (!empty($photo->display_url)) {
                $upload_dir = wp_upload_dir();
                $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
                $out_file = $cache_dir . '/wm_out_' . $photo->id . '.jpg';
                
                if (!file_exists($out_file) || (time() - filemtime($out_file)) > (7 * DAY_IN_SECONDS)) {
                    $result = $this->fetch_rendition_bytes($photo->display_url);
                    if (!is_wp_error($result)) {
                        $image = @imagecreatefromstring($result['body']);
                        if ($image !== false) {
                            $wm = JOPG_Watermark::instance();
                            $image = $wm->apply_watermark($image);
                            imagejpeg($image, $out_file, 90);
                            imagedestroy($image);
                        }
                    }
                }
            }
            
            // Verify cache was created
            $thumb_cache = $upload_dir['basedir'] . '/jopg-cache/wm_thumb_out_' . $photo->id . '.jpg';
            if (file_exists($thumb_cache) && filesize($thumb_cache) > 100) {
                $cached++;
            } else {
                $failed++;
            }
        }
        
        $done = $offset + count($photos);
        $remaining = max(0, $total - $done);
        
        wp_send_json_success([
            'cached' => $cached,
            'failed' => $failed,
            'processed' => count($photos),
            'done' => $done,
            'total' => $total,
            'remaining' => $remaining,
        ]);
    }
    
    /**
     * Connect button URL for admin UI
     */
    public function get_connect_url() {
        return wp_nonce_url(admin_url('admin-post.php?action=jopg_lightroom_connect'), 'jopg_lightroom_connect');
    }
    
    /**
     * Add a 2-minute cron interval for background pre-warming.
     */
    public function add_cron_interval($schedules) {
        $schedules['jopg_every_2min'] = [
            'interval' => 120,
            'display' => 'Every 2 Minutes (JOPG Pre-warm)',
        ];
        return $schedules;
    }
    
    /**
     * AJAX: Start background pre-warm for an album (or all albums).
     * Schedules a WP cron job that runs every 2 minutes, processing
     * a batch of images each time. User can close the browser.
     */
    public function ajax_prewarm_background_start() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        $album_id = intval($_POST['album_id'] ?? 0);
        
        // Count how many photos need caching
        global $wpdb;
        $table_photos = $wpdb->prefix . 'jopg_photos';
        $where = $album_id > 0 ? $wpdb->prepare("WHERE album_id = %d", $album_id) : '';
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_photos $where"));
        
        if ($total === 0) {
            wp_send_json_error('No photos to pre-warm');
        }
        
        // Store job state in a WP option
        $job = [
            'album_id' => $album_id,
            'total' => $total,
            'done' => 0,
            'cached' => 0,
            'failed' => 0,
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'last_batch_size' => 0,
        ];
        update_option('jopg_prewarm_job', $job, false);
        
        // Schedule the cron — runs every 2 minutes
        if (!wp_next_scheduled('jopg_background_prewarm')) {
            wp_schedule_event(time() + 10, 'jopg_every_2min', 'jopg_background_prewarm');
        }
        
        wp_send_json_success([
            'message' => 'Background pre-warm started',
            'total' => $total,
            'album_id' => $album_id,
        ]);
    }
    
    /**
     * AJAX: Check background pre-warm status (polled by admin JS).
     */
    public function ajax_prewarm_background_status() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        $job = get_option('jopg_prewarm_job', null);
        if (!$job) {
            wp_send_json_success(['status' => 'idle']);
        }
        
        wp_send_json_success($job);
    }
    
    /**
     * AJAX: Stop background pre-warm.
     */
    public function ajax_prewarm_background_stop() {
        check_ajax_referer('jopg_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        wp_clear_scheduled_hook('jopg_background_prewarm');
        
        $job = get_option('jopg_prewarm_job', null);
        if ($job) {
            $job['status'] = 'stopped';
            $job['updated_at'] = current_time('mysql');
            update_option('jopg_prewarm_job', $job, false);
        }
        
        wp_send_json_success(['message' => 'Background pre-warm stopped']);
    }
    
    /**
     * CRON handler: Process one batch of pre-warm images server-side.
     * Runs every 2 minutes via WP cron. Processes 10 images per run.
     */
    public function cron_prewarm_batch() {
        $job = get_option('jopg_prewarm_job', null);
        if (!$job || $job['status'] !== 'running') {
            // Job is done or stopped — unschedule
            wp_clear_scheduled_hook('jopg_background_prewarm');
            return;
        }
        
        // Double check we're not already running (prevent overlap)
        if (isset($job['lock']) && $job['lock'] > (time() - 300)) {
            return; // Another process is still working (within 5 min)
        }
        
        $job['lock'] = time();
        update_option('jopg_prewarm_job', $job, false);
        
        global $wpdb;
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $album_id = intval($job['album_id']);
        $offset = intval($job['done']);
        $batch_size = 10; // 10 images per cron run
        
        $where = $album_id > 0 ? $wpdb->prepare("WHERE album_id = %d", $album_id) : '';
        $photos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, thumb_url, display_url FROM $table_photos $where ORDER BY id ASC LIMIT %d OFFSET %d",
            $batch_size, $offset
        ));
        
        if (empty($photos)) {
            // No more photos — job is done
            $job['status'] = 'completed';
            $job['updated_at'] = current_time('mysql');
            unset($job['lock']);
            update_option('jopg_prewarm_job', $job, false);
            wp_clear_scheduled_hook('jopg_background_prewarm');
            return;
        }
        
        $cached = 0;
        $failed = 0;
        
        foreach ($photos as $photo) {
            // Pre-generate thumbnail
            $this->pregenerate_thumbnail($photo->id, $photo->thumb_url);
            
            // Pre-generate display-size watermarked image
            if (!empty($photo->display_url)) {
                $upload_dir = wp_upload_dir();
                $cache_dir = $upload_dir['basedir'] . '/jopg-cache';
                $out_file = $cache_dir . '/wm_out_' . $photo->id . '.jpg';
                
                if (!file_exists($out_file) || (time() - filemtime($out_file)) > (7 * DAY_IN_SECONDS)) {
                    $result = $this->fetch_rendition_bytes($photo->display_url);
                    if (!is_wp_error($result)) {
                        $image = @imagecreatefromstring($result['body']);
                        if ($image !== false) {
                            $wm = JOPG_Watermark::instance();
                            $image = $wm->apply_watermark($image);
                            imagejpeg($image, $out_file, 90);
                            imagedestroy($image);
                        }
                    }
                }
            }
            
            // Verify
            $thumb_cache = wp_upload_dir()['basedir'] . '/jopg-cache/wm_thumb_out_' . $photo->id . '.jpg';
            if (file_exists($thumb_cache) && filesize($thumb_cache) > 100) {
                $cached++;
            } else {
                $failed++;
            }
        }
        
        // Update job state
        $job['done'] = $offset + count($photos);
        $job['cached'] = intval($job['cached']) + $cached;
        $job['failed'] = intval($job['failed']) + $failed;
        $job['last_batch_size'] = count($photos);
        $job['updated_at'] = current_time('mysql');
        unset($job['lock']);
        
        // Check if we're done
        if ($job['done'] >= $job['total']) {
            $job['status'] = 'completed';
            wp_clear_scheduled_hook('jopg_background_prewarm');
        }
        
        update_option('jopg_prewarm_job', $job, false);
    }
}
