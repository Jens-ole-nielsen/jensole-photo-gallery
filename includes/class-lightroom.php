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
        $catalog_base = $this->get_catalog_base();
        $assets_path = "catalogs/$catalog_id/albums/$album_id/assets";
        $next = $this->resolve_url($catalog_base, $assets_path . "?limit=100");
        $count = 0;
        $pages = 0;
        $max_pages = 50; // safety limit
        
        do {
            $result = $this->api_call($next);
            if (is_wp_error($result)) return $result;
            
            if (isset($result['resources'])) {
                $count += count($result['resources']);
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
            $pages++;
        } while ($next && $pages < $max_pages);
        
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
    public function import_album_photos($album_db_id) {
        global $wpdb;
        $table_albums = $wpdb->prefix . 'jopg_albums';
        $table_photos = $wpdb->prefix . 'jopg_photos';
        
        $album = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_albums WHERE id = %d", $album_db_id));
        if (!$album) return new WP_Error('no_album', 'Album not found');
        
        $assets = $this->get_album_assets($album->lightroom_catalog_id, $album->lightroom_album_id);
        if (is_wp_error($assets)) return $assets;
        
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
                'price' => $price,
            ];
            
            if ($existing) {
                $wpdb->update($table_photos, $data, ['id' => $existing]);
                $photo_id = $existing;
            } else {
                $wpdb->insert($table_photos, $data);
                $photo_id = $wpdb->insert_id;
            }
            
            // Create WooCommerce product for this photo
            if (class_exists('WooCommerce')) {
                $photo_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_photos WHERE id = %d", $photo_id));
                do_action('jopg_photo_imported', $photo_id, $photo_row);
            }
            
            $imported++;
        }
        
        $wpdb->update($table_albums, ['photo_count' => $imported, 'synced_at' => current_time('mysql')], ['id' => $album_db_id]);
        
        return $imported;
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
        wp_send_json_success(['photos_imported' => $result]);
    }
    
    /**
     * Connect button URL for admin UI
     */
    public function get_connect_url() {
        return wp_nonce_url(admin_url('admin-post.php?action=jopg_lightroom_connect'), 'jopg_lightroom_connect');
    }
}
