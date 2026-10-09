<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPMME_Security {
    private $options;

    public function __construct($options) {
        $this->options = $options;

        if (!empty($this->options['disable_xmlrpc'])) {
            $this->disable_xmlrpc();
        }
        if (!empty($this->options['remove_version'])) {
            $this->remove_version();
        }
        if (!empty($this->options['disable_rest_users'])) {
            $this->disable_rest_users();
        }
        if (!empty($this->options['disable_author'])) {
            $this->disable_author_archive();
        }
        if (!empty($this->options['disable_password_reset'])) {
            $this->disable_password_reset();
        }
        if (!empty($this->options['block_php_uploads'])) {
            self::apply_block_php_uploads();
        }
    }

    private function disable_password_reset() {
        // Disable the core functionality
        add_filter('allow_password_reset', '__return_false');
        
        // Hide "Lost your password?" link on the login page via CSS
        add_action('login_head', array($this, 'hide_lost_password_css'));

        // Remove from DOM via JS as well to prevent tab focus/accessibility leak
        add_action('login_footer', array($this, 'hide_lost_password_js'));

        // Remove link separator if registration is enabled (avoids dangling " | ")
        add_filter('login_link_separator', '__return_empty_string');

        // Remove "Lost your password?" text/links from login error messages (supports Vietnamese and all languages)
        add_filter('login_errors', function($error) {
            if (!empty($error)) {
                $error = preg_replace('/<a[^>]*lostpassword[^>]*>.*?<\/a>/iu', '', $error);
                $error = preg_replace('/<a[^>]+action=lostpassword[^>]*>.*?<\/a>/iu', '', $error);
            }
            return trim($error);
        });

        // Block access to all password reset endpoints
        $redirect_to_login = function() {
            wp_safe_redirect(wp_login_url());
            exit;
        };

        add_action('login_form_lostpassword', $redirect_to_login);
        add_action('login_form_retrievepassword', $redirect_to_login);
        add_action('login_form_resetpass', $redirect_to_login);
        add_action('login_form_rp', $redirect_to_login);
    }

    public function hide_lost_password_css() {
        ?>
        <style type="text/css">
            #login #nav a[href*="lostpassword"],
            #login #nav a[href*="action=lostpassword"],
            .login a[href*="action=lostpassword"],
            .login a[href*="lostpassword"],
            .login .lost-password {
                display: none !important;
            }
            <?php if (!get_option('users_can_register')) : ?>
            #login #nav {
                display: none !important;
            }
            <?php endif; ?>
        </style>
        <?php
    }

    public function hide_lost_password_js() {
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                var links = document.querySelectorAll('#login #nav a[href*="lostpassword"], #login #nav a[href*="action=lostpassword"], .login a[href*="action=lostpassword"], .login a[href*="lostpassword"]');
                links.forEach(function(el) {
                    if (el.previousSibling && el.previousSibling.nodeType === 3) {
                        el.previousSibling.textContent = el.previousSibling.textContent.replace(/\|\s*$/, '');
                    }
                    el.remove();
                });
                var nav = document.getElementById('nav');
                if (nav && !nav.innerText.trim()) {
                    nav.style.display = 'none';
                }
            });
        </script>
        <?php
    }

    private function disable_xmlrpc() {
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('pings_open', '__return_false', 9999);
        
        add_filter('wp_headers', function ($headers) {
            unset($headers['X-Pingback']);
            return $headers;
        });

        add_action('init', function () {
            if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
                wp_die('XML-RPC services are disabled on this site.', 'Forbidden', array('response' => 403));
            }
        });
    }

    private function remove_version() {
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');

        add_filter('style_loader_src', array($this, 'remove_version_from_url'), 9999);
        add_filter('script_loader_src', array($this, 'remove_version_from_url'), 9999);
    }

    public function remove_version_from_url($src) {
        if (strpos($src, 'ver=') !== false) {
            $src = remove_query_arg('ver', $src);
        }
        return $src;
    }

    private function disable_rest_users() {
        // Block user endpoints for unauthenticated requests
        add_filter('rest_endpoints', function ($endpoints) {
            if (!is_user_logged_in()) {
                if (isset($endpoints['/wp/v2/users'])) {
                    unset($endpoints['/wp/v2/users']);
                }
                if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
                    unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
                }
                if (isset($endpoints['/wp/v2/users/me'])) {
                    unset($endpoints['/wp/v2/users/me']);
                }
            }
            return $endpoints;
        });

        // Require authentication for any user-related REST API request
        add_filter('rest_pre_dispatch', function ($result, $server, $request) {
            $route = $request->get_route();
            if (strpos($route, '/wp/v2/users') === 0 && !is_user_logged_in()) {
                return new WP_Error(
                    'rest_forbidden',
                    __('You are not authorized to access user data.', 'wpmme'),
                    array('status' => 401)
                );
            }
            return $result;
        }, 10, 3);
    }

    private function disable_author_archive() {
        add_action('template_redirect', function () {
            if (is_author()) {
                wp_redirect(home_url(), 301);
                exit;
            }
        });

        add_action('init', function () {
            if (isset($_REQUEST['author']) && !is_admin()) {
                wp_redirect(home_url(), 301);
                exit;
            }
        });

        add_filter('oembed_response_data', function ($data) {
            if (isset($data['author_name'])) unset($data['author_name']);
            if (isset($data['author_url'])) unset($data['author_url']);
            return $data;
        });
    }

    /**
     * Detect if the server is Nginx.
     */
    public static function is_nginx() {
        $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower($_SERVER['SERVER_SOFTWARE']) : '';
        // Also check for common Nginx indicators
        if (strpos($server_software, 'nginx') !== false) {
            return true;
        }
        // WordOps and EasyEngine always use Nginx
        if (defined('RT_WP_NGINX_HELPER_CACHE_PATH') || file_exists('/usr/local/bin/wo') || file_exists('/usr/local/bin/ee')) {
            return true;
        }
        // Check for global $is_nginx set by WordPress
        global $is_nginx;
        return !empty($is_nginx);
    }

    /**
     * Get the WordOps custom Nginx config path for the current site.
     * WordOps stores per-site custom configs at: /var/www/{domain}/conf/nginx/custom.conf
     */
    private static function get_wordops_config_path() {
        $domain = parse_url(home_url(), PHP_URL_HOST);
        $domain = preg_replace('/^www\./', '', $domain);

        // WordOps path
        $wo_path = '/var/www/' . $domain . '/conf/nginx/';
        if (is_dir($wo_path) && is_writable($wo_path)) {
            return $wo_path . 'wpmme-security.conf';
        }

        // EasyEngine v4 path
        $ee_path = '/opt/easyengine/sites/' . $domain . '/config/nginx/custom/';
        if (is_dir($ee_path) && is_writable($ee_path)) {
            return $ee_path . 'wpmme-security.conf';
        }

        return false;
    }

    /**
     * Get the Nginx config snippet content for blocking PHP in uploads.
     */
    public static function get_nginx_snippet() {
        $upload_dir = wp_upload_dir();
        $upload_path = str_replace(ABSPATH, '/', $upload_dir['basedir']);
        // Normalize path for Nginx location
        $upload_path = rtrim($upload_path, '/');

        $snippet  = "# BEGIN MMe Core - Block PHP in Uploads\n";
        $snippet .= "location ~* " . preg_quote($upload_path, null) . "/.*\\.php\$ {\n";
        $snippet .= "    deny all;\n";
        $snippet .= "    return 403;\n";
        $snippet .= "}\n";
        $snippet .= "# END MMe Core - Block PHP in Uploads\n";
        return $snippet;
    }

    /**
     * Create rules to block PHP execution in uploads directory.
     * Supports Apache (.htaccess) and Nginx (WordOps custom config).
     * Static so it can be called from activation hook and settings save.
     *
     * @return array Status info: ['server' => 'apache'|'nginx', 'applied' => bool, 'method' => string, 'manual_snippet' => string|false]
     */
    public static function apply_block_php_uploads() {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $index_path = $base_dir . '/index.php';
        $status = array('server' => 'apache', 'applied' => false, 'method' => '', 'manual_snippet' => false);

        // Create index.php to prevent directory listing (works on all servers)
        if (!file_exists($index_path)) {
            @file_put_contents($index_path, "<?php\n// Silence is golden.\n");
        }

        if (self::is_nginx()) {
            $status['server'] = 'nginx';
            $snippet = self::get_nginx_snippet();

            // Try to write to WordOps/EasyEngine custom config
            $config_path = self::get_wordops_config_path();
            if ($config_path) {
                if (!file_exists($config_path) || strpos(file_get_contents($config_path), 'MMe Core - Block PHP') === false) {
                    $written = @file_put_contents($config_path, $snippet, FILE_APPEND);
                    if ($written !== false) {
                        $status['applied'] = true;
                        $status['method'] = 'wordops_auto';
                        // Try to reload Nginx
                        @exec('sudo nginx -t 2>&1 && sudo systemctl reload nginx 2>&1', $output, $return_code);
                        if ($return_code !== 0) {
                            // Config test failed, remove our changes
                            $content = file_get_contents($config_path);
                            $content = preg_replace('/# BEGIN MMe Core - Block PHP in Uploads.*?# END MMe Core - Block PHP in Uploads\n?/s', '', $content);
                            @file_put_contents($config_path, $content);
                            $status['applied'] = false;
                            $status['method'] = 'wordops_failed';
                            $status['manual_snippet'] = $snippet;
                        }
                    }
                } else {
                    $status['applied'] = true;
                    $status['method'] = 'wordops_existing';
                }
            }

            // If WordOps auto-config didn't work, save snippet for manual use
            if (!$status['applied']) {
                $status['manual_snippet'] = $snippet;
                $status['method'] = 'manual_required';
                // Save snippet to plugin directory for reference
                $snippet_path = WPMME_DIR . 'nginx-uploads-security.conf';
                @file_put_contents($snippet_path, $snippet);
            }

            // Save status to option for admin display
            update_option('wpmme_nginx_php_block_status', $status);

        } else {
            // Apache / LiteSpeed - use .htaccess
            $htaccess_path = $base_dir . '/.htaccess';

            $htaccess_content  = "# BEGIN MMe Core - Block PHP in Uploads\n";
            $htaccess_content .= "<Files *.php>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<Files *.phtml>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<Files *.php3>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<Files *.php4>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<Files *.php5>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<Files *.php7>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<Files *.phps>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<Files *.shtml>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "# Also block via handler\n";
            $htaccess_content .= "<IfModule mod_php.c>\n";
            $htaccess_content .= "  php_flag engine off\n";
            $htaccess_content .= "</IfModule>\n";
            $htaccess_content .= "<IfModule mod_php7.c>\n";
            $htaccess_content .= "  php_flag engine off\n";
            $htaccess_content .= "</IfModule>\n";
            $htaccess_content .= "<IfModule mod_php8.c>\n";
            $htaccess_content .= "  php_flag engine off\n";
            $htaccess_content .= "</IfModule>\n";
            $htaccess_content .= "# END MMe Core - Block PHP in Uploads\n";

            if (!file_exists($htaccess_path) || strpos(file_get_contents($htaccess_path), 'MMe Core - Block PHP') === false) {
                @file_put_contents($htaccess_path, $htaccess_content, FILE_APPEND);
            }
            $status['applied'] = true;
            $status['method'] = 'htaccess';
            delete_option('wpmme_nginx_php_block_status');
        }

        return $status;
    }

    /**
     * Remove the MMe Core PHP block rules.
     */
    public static function remove_block_php_uploads() {
        $upload_dir = wp_upload_dir();

        // Remove from .htaccess (Apache)
        $htaccess_path = $upload_dir['basedir'] . '/.htaccess';
        if (file_exists($htaccess_path)) {
            $content = file_get_contents($htaccess_path);
            if (strpos($content, 'MMe Core - Block PHP') !== false) {
                $content = preg_replace('/# BEGIN MMe Core - Block PHP in Uploads.*?# END MMe Core - Block PHP in Uploads\n?/s', '', $content);
                @file_put_contents($htaccess_path, $content);
            }
        }

        // Remove from Nginx (WordOps custom config)
        $config_path = self::get_wordops_config_path();
        if ($config_path && file_exists($config_path)) {
            $content = file_get_contents($config_path);
            if (strpos($content, 'MMe Core - Block PHP') !== false) {
                $content = preg_replace('/# BEGIN MMe Core - Block PHP in Uploads.*?# END MMe Core - Block PHP in Uploads\n?/s', '', $content);
                @file_put_contents($config_path, $content);
                // Reload Nginx
                @exec('sudo nginx -t 2>&1 && sudo systemctl reload nginx 2>&1');
            }
        }

        // Remove saved snippet file
        $snippet_path = WPMME_DIR . 'nginx-uploads-security.conf';
        if (file_exists($snippet_path)) {
            @unlink($snippet_path);
        }

        delete_option('wpmme_nginx_php_block_status');
    }
}
