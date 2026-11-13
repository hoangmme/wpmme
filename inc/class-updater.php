<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updates MMe Core from its public GitHub repository (same approach as MMe Elementor Kit).
 *
 * The "Version" header of wpmme.php on the main branch is the latest version.
 * When it is newer than the installed one, WordPress shows the usual "update
 * available" notice and installs the branch zip with its own upgrader.
 * "Reinstall from GitHub" stays available to overwrite the files directly.
 */
class WPMME_Updater {

    const REPO = 'hoangmme/wpmme';

    const BRANCH = 'main';

    const SLUG = 'wpmme';

    /**
     * Site transient caching the remote version info.
     */
    const CACHE = 'wpmme_update_info';

    private static $instance = null;

    /**
     * Notice shown on the Updates page after a manual check: array( type, message ).
     *
     * @var array|null
     */
    private $notice = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        add_action('upgrader_process_complete', array($this, 'after_upgrade'), 10, 2);
        add_action('admin_init', array($this, 'handle_check'));
        add_action('wp_ajax_wpmme_force_update', array($this, 'handle_force_update'));
    }

    /* ------------------------------------------------------------------ */
    /* Remote info                                                         */
    /* ------------------------------------------------------------------ */

    private function raw_url($file) {
        return 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/' . $file;
    }

    public function get_package_url() {
        return 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
    }

    public function get_repo_url() {
        return 'https://github.com/' . self::REPO;
    }

    /**
     * "1.1.0 (Build 20261113.1500)" → "1.1.0".
     *
     * @param string $header
     * @return string
     */
    public static function numeric_version($header) {
        return preg_match('/^\s*([0-9]+(?:\.[0-9]+)*)/', (string) $header, $m) ? $m[1] : '';
    }

    /**
     * Latest version info from GitHub, cached 6 hours (1 hour after an error).
     *
     * @param bool $force Skip the cache (and GitHub's raw file cache).
     * @return array { version, build, requires, requires_php, changelog, checked } or { error, checked }
     */
    public function get_remote_info($force = false) {
        $cached = get_site_transient(self::CACHE);
        if (!$force && is_array($cached)) {
            return $cached;
        }

        $bust     = $force ? '?t=' . time() : '';
        $response = wp_remote_get($this->raw_url('wpmme.php') . $bust, array('timeout' => 15));
        $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $headers  = 200 === $code ? $this->parse_headers(wp_remote_retrieve_body($response)) : array();
        $version  = isset($headers['Version']) ? self::numeric_version($headers['Version']) : '';

        if ('' === $version) {
            if (is_wp_error($response)) {
                $error = $response->get_error_message();
            } elseif (404 === $code) {
                $error = 'The GitHub repository or branch was not found. Is the repository public?';
            } else {
                $error = sprintf('Could not read the latest version from GitHub (HTTP %d).', $code);
            }
            $info = array('error' => $error, 'checked' => time());
            set_site_transient(self::CACHE, $info, HOUR_IN_SECONDS);
            if ($force) {
                WPMME_Log::add('error', 'updater', 'Update check failed: ' . $error);
            }
            return $info;
        }

        $readme = wp_remote_get($this->raw_url('README.md') . $bust, array('timeout' => 15));
        $build  = preg_match('/Build\s+([0-9.]+)/i', $headers['Version'], $m) ? $m[1] : '';

        $info = array(
            'version'      => $version,
            'build'        => $build,
            'requires'     => isset($headers['Requires at least']) ? $headers['Requires at least'] : '',
            'requires_php' => isset($headers['Requires PHP']) ? $headers['Requires PHP'] : '',
            'changelog'    => is_wp_error($readme) ? '' : $this->parse_changelog(wp_remote_retrieve_body($readme)),
            'checked'      => time(),
        );
        set_site_transient(self::CACHE, $info, 6 * HOUR_IN_SECONDS);
        return $info;
    }

    public function parse_headers($contents) {
        $headers = array();
        foreach (array('Version', 'Requires at least', 'Requires PHP') as $name) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $contents, $match)) {
                $headers[$name] = trim($match[1]);
            }
        }
        return $headers;
    }

    /**
     * The "## Changelog" section of README.md as simple HTML
     * ("### 1.1.0" headings and "- item" / "* item" lists).
     *
     * @param string $readme
     * @return string
     */
    public function parse_changelog($readme) {
        if (!preg_match('/^##\s+Changelog\s*$/mi', $readme, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $html    = '';
        $in_list = false;
        $lines   = explode("\n", substr($readme, $m[0][1] + strlen($m[0][0])));
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^##\s/', $line)) {
                break;
            }
            if (preg_match('/^[-*]\s+(.+)$/', $line, $item)) {
                if (!$in_list) {
                    $html   .= '<ul>';
                    $in_list = true;
                }
                $text  = esc_html($item[1]);
                $text  = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
                $text  = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
                $html .= '<li>' . $text . '</li>';
                continue;
            }
            if ($in_list) {
                $html   .= '</ul>';
                $in_list = false;
            }
            if (preg_match('/^###\s+(.+)$/', $line, $heading)) {
                $html .= '<h4>' . esc_html($heading[1]) . '</h4>';
            }
        }
        if ($in_list) {
            $html .= '</ul>';
        }
        return $html;
    }

    /**
     * @param bool $fetch False to only look at the cache (no remote request).
     * @return bool
     */
    public function has_update($fetch = true) {
        $info = $fetch ? $this->get_remote_info() : get_site_transient(self::CACHE);
        return !empty($info['version']) && version_compare($info['version'], WPMME_VERSION, '>');
    }

    public function get_update_url() {
        return wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode(WPMME_BASENAME)), 'upgrade-plugin_' . WPMME_BASENAME);
    }

    /* ------------------------------------------------------------------ */
    /* WordPress update integration                                        */
    /* ------------------------------------------------------------------ */

    public function inject_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }
        $info = $this->get_remote_info();
        if (empty($info['version'])) {
            return $transient;
        }

        $item = (object) array(
            'id'           => 'github.com/' . self::REPO,
            'slug'         => self::SLUG,
            'plugin'       => WPMME_BASENAME,
            'new_version'  => $info['version'],
            'url'          => $this->get_repo_url(),
            'package'      => $this->get_package_url(),
            'requires'     => $info['requires'],
            'requires_php' => $info['requires_php'],
        );

        if (version_compare($info['version'], WPMME_VERSION, '>')) {
            $transient->response[WPMME_BASENAME] = $item;
        } else {
            $transient->no_update[WPMME_BASENAME] = $item;
        }
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action || !isset($args->slug) || self::SLUG !== $args->slug) {
            return $result;
        }
        $info = $this->get_remote_info();
        return (object) array(
            'name'          => 'MMe Core',
            'slug'          => self::SLUG,
            'version'       => isset($info['version']) ? $info['version'] : WPMME_VERSION,
            'author'        => '<a href="https://mme.vn">MMe.vn</a>',
            'homepage'      => 'https://mme.vn',
            'requires'      => isset($info['requires']) ? $info['requires'] : '',
            'requires_php'  => isset($info['requires_php']) ? $info['requires_php'] : '',
            'download_link' => $this->get_package_url(),
            'sections'      => array(
                'description' => 'All-in-One Optimization & Security plugin by MMe.',
                'changelog'   => !empty($info['changelog']) ? $info['changelog'] : '',
            ),
        );
    }

    /**
     * GitHub zips extract to "wpmme-main/". Rename it to the installed folder,
     * otherwise WordPress would install the update as a second plugin.
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = array()) {
        if (!isset($hook_extra['plugin']) || WPMME_BASENAME !== $hook_extra['plugin']) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . dirname(WPMME_BASENAME) . '/';
        if (trailingslashit($source) === $desired) {
            return $source;
        }
        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }
        return new WP_Error('wpmme_rename_failed', 'Could not prepare the MMe Core update folder.');
    }

    /**
     * Forget the cached version and log the update.
     */
    public function after_upgrade($upgrader, $hook_extra) {
        if (empty($hook_extra['type']) || 'plugin' !== $hook_extra['type']) {
            return;
        }
        $plugins = isset($hook_extra['plugins']) ? (array) $hook_extra['plugins'] : (isset($hook_extra['plugin']) ? array($hook_extra['plugin']) : array());
        if (!in_array(WPMME_BASENAME, $plugins, true)) {
            return;
        }
        delete_site_transient(self::CACHE);
        $data = get_file_data(WP_PLUGIN_DIR . '/' . WPMME_BASENAME, array('Version' => 'Version'));
        WPMME_Log::add('success', 'updater', sprintf('Updated from %s to %s.', WPMME_VERSION, $data['Version']));
    }

    /* ------------------------------------------------------------------ */
    /* Updates page actions                                                */
    /* ------------------------------------------------------------------ */

    /**
     * "Check for updates" button.
     */
    public function handle_check() {
        if (empty($_POST['wpmme_update_action']) || 'check' !== $_POST['wpmme_update_action']) {
            return;
        }
        if (!current_user_can('update_plugins')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('wpmme_check_updates', '_wpmme_update_nonce');

        $info = $this->get_remote_info(true);

        // Rebuild the WordPress update list so the Plugins page agrees.
        delete_site_transient('update_plugins');
        wp_update_plugins();

        if (!empty($info['error'])) {
            $this->notice = array('error', $info['error']);
        } elseif ($this->has_update()) {
            $this->notice = array('success', sprintf('Version %s is available.', $info['version']));
            WPMME_Log::add('info', 'updater', sprintf('Version %s is available (installed %s).', $info['version'], WPMME_VERSION));
        } else {
            $this->notice = array('success', 'You are using the latest version.');
        }
    }

    public function get_notice() {
        return $this->notice;
    }

    /**
     * "Reinstall from GitHub": download the branch zip and overwrite the plugin
     * files directly. For recovery when the normal update can't run.
     */
    public function handle_force_update() {
        check_ajax_referer('wpmme_nonce', 'nonce');
        if (!current_user_can('update_plugins')) {
            wp_send_json_error('Permission denied.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        WP_Filesystem();
        global $wp_filesystem;

        $fail = function ($message) {
            WPMME_Log::add('error', 'updater', 'Reinstall from GitHub failed: ' . $message);
            wp_send_json_error($message);
        };

        $temp_file = download_url($this->get_package_url());
        if (is_wp_error($temp_file)) {
            $fail('Failed to download from GitHub: ' . $temp_file->get_error_message());
        }

        $temp_dir = get_temp_dir() . 'wpmme_update_' . time() . '/';
        $wp_filesystem->mkdir($temp_dir);

        $unzip_result = unzip_file($temp_file, $temp_dir);
        @unlink($temp_file);
        if (is_wp_error($unzip_result)) {
            $wp_filesystem->delete($temp_dir, true);
            $fail('Failed to unzip the file: ' . $unzip_result->get_error_message());
        }

        // GitHub zips contain one root folder, e.g. "wpmme-main".
        $source_dir = $temp_dir . 'wpmme-main/';
        if (!file_exists($source_dir)) {
            foreach (scandir($temp_dir) as $file) {
                if ('.' !== $file && '..' !== $file && is_dir($temp_dir . $file)) {
                    $source_dir = $temp_dir . $file . '/';
                    break;
                }
            }
        }
        if (!file_exists($source_dir . 'wpmme.php')) {
            $wp_filesystem->delete($temp_dir, true);
            $fail('Could not locate the plugin files inside the extracted zip.');
        }

        $data        = get_file_data($source_dir . 'wpmme.php', array('Version' => 'Version'));
        $copy_result = copy_dir($source_dir, WPMME_DIR);
        $wp_filesystem->delete($temp_dir, true);

        if (is_wp_error($copy_result)) {
            $fail('Failed to copy files: ' . $copy_result->get_error_message());
        } elseif (false === $copy_result) {
            $fail('Failed to copy files due to file system permissions.');
        }

        delete_site_transient(self::CACHE);
        WPMME_Log::add('success', 'updater', sprintf('Reinstalled from GitHub: %s → %s.', WPMME_VERSION, $data['Version']));
        wp_send_json_success(array('version' => $data['Version']));
    }
}
