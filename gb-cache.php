<?php
/**
 * Plugin Name: GB Cache
 * Description: Server-Level static HTML cache with Admin UI for exclusions, WP-Cron CF7 handling, and Cache Warming.
 * Version: 1.8
 * Author: Tyson
 * License: MIT License
 * License URI: https://opensource.org/licenses/MIT
 * Author URI: https://github.com/tysonchamp/
 */

if (!defined('ABSPATH')) exit;

class GB_Cache
{
    private $cache_dir;

    public function __construct()
    {
        $this->cache_dir = WP_CONTENT_DIR . '/cache/gb-cache';

        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
        $this->protect_cache_dir();

        // Buffer setup
        add_action('template_redirect', [$this, 'start_buffer'], 0);

        // Invalidation hooks
        $invalidation_hooks = [
            'save_post', 'deleted_post', 'comment_post', 'edit_comment',
            'delete_comment', 'wp_update_nav_menu', 'activated_plugin',
            'deactivated_plugin', 'switch_theme', 'customize_save_after'
        ];
        foreach ($invalidation_hooks as $hook) {
            add_action($hook, [$this, 'clear_cache']);
        }

        add_action('updated_option', [$this, 'check_settings_update'], 10, 3);

        // Admin menu & settings setup
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_bar_menu', [$this, 'admin_bar_clear_cache'], 100);
        add_action('admin_init', [$this, 'handle_manual_clear']);

        // WP-Cron Setup
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action('gb_cache_purge_cron', [$this, 'clear_cache']);
        add_action('gb_cache_preload_cron', [$this, 'run_preloader']);
    }

    private function protect_cache_dir()
    {
        $index_file = $this->cache_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden.');
        }
    }

    // --- ADMIN UI METHODS ---

    public function add_admin_menu()
    {
        add_options_page(
            'GB Cache Settings',
            'GB Cache',
            'manage_options',
            'gb-cache-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting('gb_cache_options_group', 'gb_cache_excluded_pages');
        register_setting('gb_cache_options_group', 'gb_cache_custom_uris');
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) return;

        $excluded_pages = get_option('gb_cache_excluded_pages', []);
        $custom_uris = get_option('gb_cache_custom_uris', "/cart/\n/checkout/\n/my-account/\n/wp-json/");
        $all_pages = get_pages();

        echo '<div class="wrap">';
        echo '<h1>GB Cache Settings</h1>';
        echo '<form method="post" action="options.php">';
        
        settings_fields('gb_cache_options_group');
        
        echo '<table class="form-table">';
        
        // 1. Pages Checklist
        echo '<tr valign="top"><th scope="row">Exclude Specific Pages</th><td>';
        echo '<div style="max-height: 200px; overflow-y: scroll; border: 1px solid #ccc; padding: 10px; background: #fff;">';
        foreach ($all_pages as $page) {
            $checked = in_array($page->ID, (array)$excluded_pages) ? 'checked' : '';
            echo '<label style="display:block; margin-bottom:5px;">';
            echo '<input type="checkbox" name="gb_cache_excluded_pages[]" value="' . esc_attr($page->ID) . '" ' . $checked . ' /> ';
            echo esc_html($page->post_title);
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="description">Select the pages you want to bypass the cache.</p>';
        echo '</td></tr>';

        // 2. Custom URIs Textarea
        echo '<tr valign="top"><th scope="row">Exclude Custom URIs</th><td>';
        echo '<textarea name="gb_cache_custom_uris" rows="6" cols="50" class="large-text code">' . esc_textarea($custom_uris) . '</textarea>';
        echo '<p class="description">Enter parts of URLs to exclude (one per line). Examples: <code>/cart/</code> or <code>/wp-json/</code></p>';
        echo '</td></tr>';

        echo '</table>';
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    // --- CACHING LOGIC ---

    private function should_bypass_saving()
    {
        if (is_admin()) return true;
        if (strpos($_SERVER['REQUEST_URI'], '/wp-login.php') !== false) return true;
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') return true;
        if (!empty($_GET)) return true;
        if (defined('DOING_AJAX') && DOING_AJAX) return true;
        if (defined('DOING_CRON') && DOING_CRON) return true;
        if (function_exists('is_user_logged_in') && is_user_logged_in()) return true;
        if (is_search() || is_404() || is_feed() || is_trackback() || is_preview()) return true;

        // 🛑 1. Check if the current page ID is in our excluded checklist
        $excluded_pages = get_option('gb_cache_excluded_pages', []);
        if (!empty($excluded_pages) && is_singular($excluded_pages)) {
            return true;
        }

        // 🛑 2. Check current URL against Custom URIs textarea
        $custom_uris_raw = get_option('gb_cache_custom_uris', "/cart/\n/checkout/\n/my-account/\n/wp-json/");
        $excluded_uris = array_filter(array_map('trim', explode("\n", $custom_uris_raw)));
        
        $current_uri = $_SERVER['REQUEST_URI'];
        foreach ($excluded_uris as $exclusion) {
            if (!empty($exclusion) && stripos($current_uri, $exclusion) !== false) {
                return true; 
            }
        }

        return false;
    }

    private function get_device_type()
    {
        if (empty($_SERVER['HTTP_USER_AGENT'])) return 'desktop';
        
        $ua = $_SERVER['HTTP_USER_AGENT'];
        
        if (preg_match('/(iPhone|iPad|iPod)/i', $ua)) return 'ios';
        if (preg_match('/(Mobile|Android|Phone|BlackBerry|webOS|Opera Mini|Opera Mobi|Kindle|Silk\/)/i', $ua)) return 'mobile';
        
        return 'desktop';
    }

    private function get_cache_path()
    {
        $device = $this->get_device_type();
        $host = $_SERVER['HTTP_HOST'];
        $uri = rtrim($_SERVER['REQUEST_URI'], '/') . '/';
        
        return [
            'dir'    => $this->cache_dir . '/' . $device . '/' . $host . $uri,
            'device' => $device
        ];
    }

    public function start_buffer()
    {
        if ($this->should_bypass_saving()) return;

        header('Vary: User-Agent');
        header('X-GB-Cache: Miss');
        ob_start([$this, 'save_cache']);
    }

    public function save_cache($buffer)
    {
        if (empty($buffer) || strlen($buffer) < 255) return $buffer;
        if (http_response_code() !== 200) return $buffer;
        if (stripos($buffer, '<html') === false) return $buffer;

        $path_data = $this->get_cache_path();
        $dir_path = $path_data['dir'];
        $cache_file = $dir_path . 'index.html';

        if (!file_exists($dir_path)) wp_mkdir_p($dir_path);

        $footer_note = "\n";
        
        $temp_file = $cache_file . '.tmp.' . uniqid();
        if (file_put_contents($temp_file, $buffer . $footer_note) !== false) {
            rename($temp_file, $cache_file);
        }

        return $buffer;
    }

    public function clear_cache(...$args)
    {
        if (!file_exists($this->cache_dir)) return;
        $this->delete_directory_contents($this->cache_dir);
        $this->protect_cache_dir();

        if (!wp_next_scheduled('gb_cache_preload_cron')) {
            wp_schedule_single_event(time() + 5, 'gb_cache_preload_cron');
        }
    }

    public function check_settings_update($option, $old_value, $value)
    {
        $ignored_prefixes = ['_transient', '_site_transient', 'cron', 'action_scheduler', 'wp_session', 'rewrite_rules'];
        
        foreach ($ignored_prefixes as $prefix) {
            if (strpos($option, $prefix) === 0) return;
        }
        $this->clear_cache();
    }

    private function delete_directory_contents($dir)
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->delete_directory_contents($path) : @unlink($path);
            if (is_dir($path)) @rmdir($path);
        }
    }

    public function admin_bar_clear_cache($wp_admin_bar)
    {
        if (!current_user_can('manage_options')) return;

        $wp_admin_bar->add_node([
            'id'    => 'clear-gb-cache',
            'title' => 'Clear GB Cache',
            'href'  => wp_nonce_url(admin_url('?action=clear_gb_cache'), 'clear_gb_cache'),
        ]);
    }

    public function handle_manual_clear()
    {
        if (isset($_GET['action']) && $_GET['action'] === 'clear_gb_cache') {
            check_admin_referer('clear_gb_cache');
            $this->clear_cache();
            wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
            exit;
        }
    }

    // --- WP-CRON METHODS ---

    public function add_cron_interval($schedules)
    {
        $schedules['ten_hours'] = [
            'interval' => 36000, 
            'display'  => esc_html__('Every 10 Hours')
        ];
        return $schedules;
    }

    public function run_preloader()
    {
        $urls = [home_url('/')]; 

        $pages = get_posts(['post_type' => 'page', 'posts_per_page' => 10, 'post_status' => 'publish', 'fields' => 'ids']);
        foreach ($pages as $id) $urls[] = get_permalink($id);

        $posts = get_posts(['post_type' => 'post', 'posts_per_page' => 5, 'post_status' => 'publish', 'fields' => 'ids']);
        foreach ($posts as $id) $urls[] = get_permalink($id);

        $urls = array_unique($urls);

        $desktop_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 GBCacheBot/1.0';
        $mobile_ua  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1 GBCacheBot/1.0';

        foreach ($urls as $url) {
            wp_remote_get($url, ['headers' => ['User-Agent' => $desktop_ua], 'blocking' => false, 'timeout' => 1, 'sslverify' => false]);
            wp_remote_get($url, ['headers' => ['User-Agent' => $mobile_ua], 'blocking' => false, 'timeout' => 1, 'sslverify' => false]);
        }
    }

    // --- ACTIVATION & DEACTIVATION METHODS ---

    public static function activate()
    {
        if (!wp_next_scheduled('gb_cache_purge_cron')) {
            wp_schedule_event(time(), 'ten_hours', 'gb_cache_purge_cron');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $htaccess_file = get_home_path() . '.htaccess';

        if (file_exists($htaccess_file)) {
            $backup_file = get_home_path() . '.htaccess.gb_backup_' . date('Y-m-d_H-i-s');
            copy($htaccess_file, $backup_file);
        }

        $htaccess_content = <<<EOD
# BEGIN GB Cache Server-Level Delivery
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    RewriteCond %{REQUEST_METHOD} !POST
    RewriteCond %{QUERY_STRING} !.*=.*
    RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|wp-postpass_|comment_author_)

    RewriteCond %{HTTP_USER_AGENT} (iPhone|iPad|iPod) [NC]
    RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/gb-cache/ios/%{HTTP_HOST}%{REQUEST_URI}index.html -f
    RewriteRule ^(.*) /wp-content/cache/gb-cache/ios/%{HTTP_HOST}%{REQUEST_URI}index.html [L]

    RewriteCond %{HTTP_USER_AGENT} (Mobile|Android|Phone|BlackBerry|webOS|Opera\ Mini|Opera\ Mobi|Kindle|Silk/) [NC]
    RewriteCond %{HTTP_USER_AGENT} !(iPhone|iPad|iPod) [NC]
    RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/gb-cache/mobile/%{HTTP_HOST}%{REQUEST_URI}index.html -f
    RewriteRule ^(.*) /wp-content/cache/gb-cache/mobile/%{HTTP_HOST}%{REQUEST_URI}index.html [L]

    RewriteCond %{HTTP_USER_AGENT} !(Mobile|Android|Phone|BlackBerry|webOS|Opera\ Mini|Opera\ Mobi|Kindle|Silk/|iPhone|iPad|iPod) [NC]
    RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/gb-cache/desktop/%{HTTP_HOST}%{REQUEST_URI}index.html -f
    RewriteRule ^(.*) /wp-content/cache/gb-cache/desktop/%{HTTP_HOST}%{REQUEST_URI}index.html [L]
</IfModule>
# END GB Cache Server-Level Delivery

# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOD;

        file_put_contents($htaccess_file, $htaccess_content);
    }

    public static function deactivate()
    {
        $timestamp1 = wp_next_scheduled('gb_cache_purge_cron');
        if ($timestamp1) wp_unschedule_event($timestamp1, 'gb_cache_purge_cron');

        $timestamp2 = wp_next_scheduled('gb_cache_preload_cron');
        if ($timestamp2) wp_unschedule_event($timestamp2, 'gb_cache_preload_cron');

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $htaccess_file = get_home_path() . '.htaccess';
        $dir = get_home_path();

        $backups = glob($dir . '.htaccess.gb_backup_*');
        if (!empty($backups)) {
            rsort($backups);
            $latest_backup = $backups[0];
            copy($latest_backup, $htaccess_file);
        }
    }
}

new GB_Cache();

register_activation_hook(__FILE__, ['GB_Cache', 'activate']);
register_deactivation_hook(__FILE__, ['GB_Cache', 'deactivate']);