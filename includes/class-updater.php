<?php
if (!defined('ABSPATH')) exit;

/**
 * GitHub-based auto-update checker for Jens Ole Photo Gallery.
 * 
 * Checks the GitHub repo for new releases and injects them into WordPress's
 * plugin update system. When a new version is available, the user sees an
 * "Update now" link under Plugins — no manual zip upload needed.
 * 
 * The check runs on WordPress's default transient schedule (every ~12h).
 * Version comparison uses the "Version" header in the main plugin file.
 */
class JOPG_Updater {
    
    private static $instance = null;
    
    const GITHUB_USER = 'Jens-ole-nielsen';
    const GITHUB_REPO = 'jensole-photo-gallery';
    
    // We cache the latest release info for 6 hours to avoid hammering GitHub
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2);
    }
    
    /**
     * Fetch latest release info from GitHub API.
     * Returns array with version + zip_url, or null if no update / on error.
     */
    private function get_latest_release() {
        $cached = get_transient('jopg_github_release');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        
        $url = "https://api.github.com/repos/" . self::GITHUB_USER . "/" . self::GITHUB_REPO . "/releases/latest";
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ],
            'timeout' => 15,
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!$data || !isset($data['tag_name'])) {
            return null;
        }
        
        // tag_name is like "v1.0.1" — strip the "v" prefix
        $version = ltrim($data['tag_name'], 'v');
        
        // Find the zip asset, or fall back to the auto-generated zipball URL
        $zip_url = null;
        if (!empty($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (preg_match('/\.zip$/i', $asset['name'])) {
                    $zip_url = $asset['browser_download_url'];
                    break;
                }
            }
        }
        if (!$zip_url) {
            // GitHub auto-generated zipball — works but the folder structure inside
            // is "repo-tag/" instead of "jens-ole-photo-gallery/". We handle that
            // by using the zipball and telling WP the slug.
            $zip_url = $data['zipball_url'] ?? null;
        }
        
        if (!$zip_url) return null;
        
        $release = [
            'version'   => $version,
            'zip_url'   => $zip_url,
            'url'       => $data['html_url'] ?? '',
            'notes'     => $data['body'] ?? '',
            'date'      => $data['published_at'] ?? '',
        ];
        
        set_transient('jopg_github_release', $release, self::CACHE_TTL);
        return $release;
    }
    
    /**
     * Inject update info into WordPress's update transient.
     */
    public function check_for_update($transient) {
        if (!is_object($transient)) return $transient;
        if (!isset($transient->checked) || empty($transient->checked)) return $transient;
        
        $plugin_slug = plugin_basename(JOPG_PATH . 'jens-ole-photo-gallery.php');
        if (!isset($transient->checked[$plugin_slug])) return $transient;
        
        $current_version = $transient->checked[$plugin_slug];
        $release = $this->get_latest_release();
        
        if (!$release) return $transient;
        if (version_compare($current_version, $release['version'], '>=')) return $transient;
        
        $transient->response[$plugin_slug] = (object) [
            'slug'        => dirname($plugin_slug),
            'plugin'      => $plugin_slug,
            'new_version' => $release['version'],
            'package'     => $release['zip_url'],
            'url'         => $release['url'],
            'icons'       => [],
            'banners'     => [],
        ];
        
        return $transient;
    }
    
    /**
     * Show plugin info in the "View details" / update modal.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== dirname(plugin_basename(JOPG_PATH . 'jens-ole-photo-gallery.php'))) {
            return $result;
        }
        
        $release = $this->get_latest_release();
        if (!$release) return $result;
        
        return (object) [
            'name'          => 'Jens Ole Photo Gallery',
            'slug'          => $args->slug,
            'version'       => $release['version'],
            'author'        => 'Jens Ole Photography',
            'homepage'      => $release['url'],
            'last_updated'  => $release['date'],
            'sections'      => [
                'description' => 'Custom photo gallery with Lightroom sync, watermarking, WooCommerce sales, and client selection.',
                'changelog'   => nl2br(esc_html($release['notes'])),
            ],
            'download_link' => $release['zip_url'],
        ];
    }
    
    /**
     * Clear cache after a successful update so the next check is fresh.
     */
    public function after_update($upgrader, $options) {
        if ($options['action'] !== 'update' || $options['type'] !== 'plugin') return;
        if (empty($options['plugins'])) return;
        
        $plugin_slug = plugin_basename(JOPG_PATH . 'jens-ole-photo-gallery.php');
        if (in_array($plugin_slug, $options['plugins'], true)) {
            delete_transient('jopg_github_release');
        }
    }
}
