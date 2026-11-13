<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache Warmer: requests a chosen list of URLs once a day (and on demand) so
 * the page cache is ready before visitors arrive.
 *
 * Made for WordOps (nginx FastCGI / Redis page cache) but works with any page
 * cache that answers plain GET requests. "Via localhost" sends the requests to
 * 127.0.0.1 with the site's host name, so they skip Cloudflare/CDN and reach
 * the nginx cache directly.
 */
class WPMME_Cache_Warmer {

    const OPTION = 'wpmme_cache_warmer';

    const CRON_HOOK = 'wpmme_cache_warm';

    const MAX_URLS = 300;

    const USER_AGENT = 'MMe-Cache-Warmer';

    /**
     * Response headers that report the page cache status, in order of preference.
     */
    const CACHE_HEADERS = array('x-fastcgi-cache', 'x-srcache-fetch-status', 'x-cache', 'x-litespeed-cache', 'x-proxy-cache', 'cf-cache-status');

    private static $instance = null;

    /**
     * Host used for CURLOPT_RESOLVE while a localhost request runs.
     *
     * @var string
     */
    private $resolve_host = '';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(self::CRON_HOOK, array($this, 'run_scheduled'));
        add_action('wp_ajax_wpmme_cache_save', array($this, 'ajax_save'));
        add_action('wp_ajax_wpmme_cache_sitemap', array($this, 'ajax_sitemap'));
        add_action('wp_ajax_wpmme_cache_warm_url', array($this, 'ajax_warm_url'));
        add_action('wp_ajax_wpmme_cache_finish', array($this, 'ajax_finish'));
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    public static function defaults() {
        return array(
            'enabled'   => false,
            'time'      => '05:00',
            'localhost' => true,
            'urls'      => array(),
            'last_run'  => array(),
        );
    }

    public static function get_settings() {
        $settings = get_option(self::OPTION, array());
        return wp_parse_args(is_array($settings) ? $settings : array(), self::defaults());
    }

    private static function save_settings($settings) {
        update_option(self::OPTION, $settings, false);
    }

    /**
     * Keep only http(s) URLs of this site, one per entry, without duplicates.
     *
     * @param string|array $input
     * @return array array( urls, rejected )
     */
    public static function clean_urls($input) {
        $lines    = is_array($input) ? $input : preg_split('/[\r\n]+/', (string) $input);
        $home     = wp_parse_url(home_url('/'));
        $urls     = array();
        $rejected = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || 0 === strpos($line, '#')) {
                continue;
            }
            if (0 === strpos($line, '/')) {
                $line = home_url($line);
            }
            $url = esc_url_raw($line, array('http', 'https'));
            $parts = $url ? wp_parse_url($url) : false;
            if (!$parts || empty($parts['host']) || strtolower($parts['host']) !== strtolower($home['host'])) {
                $rejected[] = $line;
                continue;
            }
            $urls[$url] = true;
            if (count($urls) >= self::MAX_URLS) {
                break;
            }
        }
        return array(array_keys($urls), $rejected);
    }

    /* ------------------------------------------------------------------ */
    /* Schedule                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Next timestamp for "HH:MM" in the site time zone.
     *
     * @param string $time
     * @return int
     */
    public static function next_timestamp($time) {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) $time, $m)) {
            $m = array('', '5', '00');
        }
        $tz  = wp_timezone();
        $now = new DateTime('now', $tz);
        $run = new DateTime('now', $tz);
        $run->setTime((int) $m[1], (int) $m[2], 0);
        if ($run <= $now) {
            $run->modify('+1 day');
        }
        return $run->getTimestamp();
    }

    public static function sync_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $settings = self::get_settings();
        if (!empty($settings['enabled']) && !empty($settings['urls'])) {
            wp_schedule_event(self::next_timestamp($settings['time']), 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* ------------------------------------------------------------------ */
    /* Warming                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Request one URL and report the cache status.
     *
     * @param string $url
     * @param bool   $localhost Send the request to 127.0.0.1 (skips Cloudflare/CDN).
     * @return array { url, status, code, time, error }
     */
    public function warm_url($url, $localhost) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if ($localhost && $host) {
            $this->resolve_host = $host;
            add_action('http_api_curl', array($this, 'curl_resolve_localhost'), 10, 3);
        }

        $start    = microtime(true);
        $response = wp_remote_get($url, array(
            'timeout'     => 45,
            'redirection' => 3,
            'user-agent'  => self::USER_AGENT . '/' . WPMME_VERSION . '; ' . home_url('/'),
            'headers'     => array('Accept' => 'text/html'),
            'cookies'     => array(),
        ));
        $time = round(microtime(true) - $start, 2);

        if ($localhost && $host) {
            remove_action('http_api_curl', array($this, 'curl_resolve_localhost'), 10);
            $this->resolve_host = '';
        }

        if (is_wp_error($response)) {
            return array('url' => $url, 'status' => 'ERROR', 'code' => 0, 'time' => $time, 'error' => $response->get_error_message());
        }

        $status = '-';
        foreach (self::CACHE_HEADERS as $header) {
            $value = wp_remote_retrieve_header($response, $header);
            if ('' !== $value && null !== $value) {
                $status = strtoupper(trim(is_array($value) ? end($value) : $value));
                break;
            }
        }

        return array(
            'url'    => $url,
            'status' => $status,
            'code'   => (int) wp_remote_retrieve_response_code($response),
            'time'   => $time,
            'error'  => '',
        );
    }

    /**
     * Point the site's host name at 127.0.0.1 for this cURL request only.
     * The TLS certificate still matches because the host name is unchanged.
     */
    public function curl_resolve_localhost($handle, $args, $url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$this->resolve_host || $host !== $this->resolve_host || !defined('CURLOPT_RESOLVE')) {
            return;
        }
        curl_setopt($handle, CURLOPT_RESOLVE, array($host . ':443:127.0.0.1', $host . ':80:127.0.0.1'));
    }

    /**
     * Summary of a run, stored as last_run and written to the log.
     *
     * @param array  $results  warm_url() results
     * @param string $trigger  cron|manual
     * @param float  $duration seconds
     * @return array
     */
    private function finish($results, $trigger, $duration) {
        $summary = array('time' => time(), 'trigger' => $trigger, 'total' => count($results), 'hit' => 0, 'warmed' => 0, 'bypass' => 0, 'failed' => 0, 'duration' => round($duration, 1));
        $failed  = array();
        foreach ($results as $r) {
            $status = isset($r['status']) ? $r['status'] : '-';
            $code   = isset($r['code']) ? (int) $r['code'] : 0;
            if ('ERROR' === $status || $code >= 400 || 0 === $code) {
                $summary['failed']++;
                $failed[] = (isset($r['url']) ? $r['url'] : '?') . ' (' . ($code ? $code : (isset($r['error']) ? $r['error'] : 'error')) . ')';
            } elseif ('HIT' === $status) {
                $summary['hit']++;
            } elseif (in_array($status, array('MISS', 'EXPIRED', 'STALE', 'UPDATING', 'DYNAMIC'), true)) {
                $summary['warmed']++;
            } else {
                $summary['bypass']++;
            }
        }

        $settings             = self::get_settings();
        $settings['last_run'] = $summary;
        self::save_settings($settings);

        $message = sprintf('%s run: %d URLs, %d already cached, %d warmed, %d not cacheable, %d failed (%ss).', 'cron' === $trigger ? 'Scheduled' : 'Manual', $summary['total'], $summary['hit'], $summary['warmed'], $summary['bypass'], $summary['failed'], $summary['duration']);
        WPMME_Log::add($summary['failed'] ? 'warning' : 'success', 'cache', $message, $failed ? array('failed' => array_slice($failed, 0, 20)) : array());

        return $summary;
    }

    /**
     * Daily cron: warm every saved URL.
     */
    public function run_scheduled() {
        $settings = self::get_settings();
        if (empty($settings['enabled']) || empty($settings['urls'])) {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);

        $start   = microtime(true);
        $results = array();
        foreach ($settings['urls'] as $url) {
            $results[] = $this->warm_url($url, !empty($settings['localhost']));
            usleep(200000);
        }
        $this->finish($results, 'cron', microtime(true) - $start);
    }

    /* ------------------------------------------------------------------ */
    /* Sitemap                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * URLs listed in the site's sitemap (Rank Math / Yoast index, or the WordPress core sitemap).
     *
     * @return string[]
     */
    public function sitemap_urls() {
        $settings = self::get_settings();
        $indexes  = array(home_url('/sitemap_index.xml'), home_url('/wp-sitemap.xml'), home_url('/sitemap.xml'));
        $seen     = array();
        $pages    = array();

        foreach ($indexes as $index) {
            $locs = $this->fetch_locs($index, !empty($settings['localhost']));
            if (empty($locs)) {
                continue;
            }
            foreach ($locs as $loc) {
                if (preg_match('/\.xml(\.gz)?$/i', wp_parse_url($loc, PHP_URL_PATH))) {
                    if (isset($seen[$loc]) || count($seen) > 50) {
                        continue;
                    }
                    $seen[$loc] = true;
                    foreach ($this->fetch_locs($loc, !empty($settings['localhost'])) as $page) {
                        $pages[] = $page;
                    }
                } else {
                    $pages[] = $loc;
                }
            }
            break;
        }

        $pages = array_filter($pages, function ($url) {
            return !preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', (string) wp_parse_url($url, PHP_URL_PATH));
        });
        array_unshift($pages, home_url('/'));
        list($urls) = self::clean_urls($pages);
        return $urls;
    }

    /**
     * <loc> values of a sitemap file.
     *
     * @param string $url
     * @param bool   $localhost
     * @return string[]
     */
    private function fetch_locs($url, $localhost) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if ($localhost && $host) {
            $this->resolve_host = $host;
            add_action('http_api_curl', array($this, 'curl_resolve_localhost'), 10, 3);
        }
        $response = wp_remote_get($url, array('timeout' => 20, 'user-agent' => self::USER_AGENT . '/' . WPMME_VERSION));
        if ($localhost && $host) {
            remove_action('http_api_curl', array($this, 'curl_resolve_localhost'), 10);
            $this->resolve_host = '';
        }
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return array();
        }
        if (!preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#is', wp_remote_retrieve_body($response), $m)) {
            return array();
        }
        return array_map('html_entity_decode', $m[1]);
    }

    /* ------------------------------------------------------------------ */
    /* AJAX                                                                */
    /* ------------------------------------------------------------------ */

    private function check_ajax() {
        check_ajax_referer('wpmme_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
    }

    public function ajax_save() {
        $this->check_ajax();

        list($urls, $rejected) = self::clean_urls(isset($_POST['urls']) ? wp_unslash($_POST['urls']) : '');
        $time = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '05:00';
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $time = '05:00';
        }

        $settings              = self::get_settings();
        $settings['enabled']   = !empty($_POST['enabled']);
        $settings['localhost'] = !empty($_POST['localhost']);
        $settings['time']      = $time;
        $settings['urls']      = $urls;
        self::save_settings($settings);
        self::sync_schedule();

        $next = wp_next_scheduled(self::CRON_HOOK);
        WPMME_Log::add('info', 'cache', sprintf('Settings saved: %d URLs, daily run %s at %s.', count($urls), $settings['enabled'] ? 'on' : 'off', $time));

        wp_send_json_success(array(
            'urls'     => $urls,
            'rejected' => $rejected,
            'next'     => $next ? wp_date('d/m/Y H:i', $next) : '',
        ));
    }

    public function ajax_sitemap() {
        $this->check_ajax();
        $urls = $this->sitemap_urls();
        if (count($urls) <= 1) {
            wp_send_json_error('No sitemap found (tried sitemap_index.xml, wp-sitemap.xml, sitemap.xml).');
        }
        wp_send_json_success(array('urls' => $urls));
    }

    public function ajax_warm_url() {
        $this->check_ajax();
        list($urls) = self::clean_urls(array(isset($_POST['url']) ? wp_unslash($_POST['url']) : ''));
        if (empty($urls)) {
            wp_send_json_error('Not a URL of this site.');
        }
        $localhost = isset($_POST['localhost']) ? !empty($_POST['localhost']) : !empty(self::get_settings()['localhost']);
        wp_send_json_success($this->warm_url($urls[0], $localhost));
    }

    public function ajax_finish() {
        $this->check_ajax();
        $results = isset($_POST['results']) ? json_decode(wp_unslash($_POST['results']), true) : array();
        if (!is_array($results)) {
            $results = array();
        }
        $clean = array();
        foreach (array_slice($results, 0, self::MAX_URLS) as $r) {
            $clean[] = array(
                'url'    => isset($r['url']) ? esc_url_raw($r['url']) : '',
                'status' => isset($r['status']) ? sanitize_text_field($r['status']) : '-',
                'code'   => isset($r['code']) ? (int) $r['code'] : 0,
                'error'  => isset($r['error']) ? sanitize_text_field($r['error']) : '',
            );
        }
        $duration = isset($_POST['duration']) ? (float) $_POST['duration'] : 0;
        wp_send_json_success($this->finish($clean, 'manual', $duration));
    }
}
