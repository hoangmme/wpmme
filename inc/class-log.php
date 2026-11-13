<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MMe Core activity log.
 *
 * JSON lines in uploads/wpmme-logs/. The file is a .php file that starts with
 * "exit", so it can't be read over the web. PHP fatal errors are recorded at
 * shutdown (one cheap check per request).
 */
class WPMME_Log {

    const HEADER = "<?php exit; ?>\n";

    /**
     * Rotate above this size (bytes). One older file is kept.
     */
    const MAX_SIZE = 524288;

    const LEVELS = array('info', 'success', 'warning', 'error', 'fatal');

    const FATAL_TYPES = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);

    /**
     * Notice shown on the Logs page after a form action.
     *
     * @var array|null
     */
    private static $notice = null;

    public static function init() {
        register_shutdown_function(array(__CLASS__, 'record_fatal'));
        add_action('admin_init', array(__CLASS__, 'handle_form'));
    }

    /**
     * Log folder, created on first use. Empty when uploads is not writable.
     *
     * @return string
     */
    private static function dir() {
        $uploads = wp_upload_dir(null, false);
        if (empty($uploads['basedir'])) {
            return '';
        }
        $dir = trailingslashit($uploads['basedir']) . 'wpmme-logs';
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return '';
            }
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $dir;
    }

    /**
     * @return string Empty when uploads is not writable.
     */
    public static function file() {
        $dir = self::dir();
        return $dir ? $dir . '/log-' . substr(wp_hash('wpmme-log'), 0, 12) . '.php' : '';
    }

    /**
     * Add an entry.
     *
     * @param string $level   info|success|warning|error|fatal
     * @param string $source  Feature, e.g. updater, cache, backup, php
     * @param string $message
     * @param array  $context Extra data shown on the Logs page
     */
    public static function add($level, $source, $message, $context = array()) {
        $file = self::file();
        if (!$file) {
            return;
        }
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        if (file_exists($file) && filesize($file) > self::MAX_SIZE) {
            @rename($file, substr($file, 0, -4) . '-old.php');
        }

        $entry = array(
            't'   => time(),
            'lvl' => $level,
            'src' => (string) $source,
            'msg' => (string) $message,
        );
        if (!empty($context)) {
            $entry['ctx'] = $context;
        }
        if (function_exists('get_current_user_id') && get_current_user_id()) {
            $entry['user'] = get_current_user_id();
        }

        $line = wp_json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (!file_exists($file)) {
            $line = self::HEADER . $line;
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Newest entries first.
     *
     * @param int    $limit
     * @param string $source Only this source ('' for all)
     * @param string $level  Only this level ('' for all)
     * @return array
     */
    public static function read($limit = 300, $source = '', $level = '') {
        $file = self::file();
        if (!$file || !file_exists($file)) {
            return array();
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return array();
        }

        $entries = array();
        for ($i = count($lines) - 1; $i >= 0 && count($entries) < $limit; $i--) {
            $entry = json_decode($lines[$i], true);
            if (!is_array($entry) || empty($entry['t'])) {
                continue;
            }
            if ($source && (!isset($entry['src']) || $entry['src'] !== $source)) {
                continue;
            }
            if ($level && (!isset($entry['lvl']) || $entry['lvl'] !== $level)) {
                continue;
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Sources present in the log, for the filter.
     *
     * @return string[]
     */
    public static function sources() {
        $sources = array();
        foreach (self::read(2000) as $entry) {
            if (!empty($entry['src'])) {
                $sources[$entry['src']] = true;
            }
        }
        $sources = array_keys($sources);
        sort($sources);
        return $sources;
    }

    public static function clear() {
        $file = self::file();
        if ($file) {
            @unlink($file);
            @unlink(substr($file, 0, -4) . '-old.php');
        }
    }

    /**
     * Record a PHP fatal error at shutdown.
     */
    public static function record_fatal() {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], self::FATAL_TYPES, true)) {
            return;
        }
        $file = str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($error['file']));
        $message = strtok((string) $error['message'], "\n");
        self::add('fatal', 'php', $message, array(
            'file' => $file . ':' . $error['line'],
            'url'  => isset($_SERVER['REQUEST_URI']) ? substr(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), 0, 200) : '',
        ));
    }

    /**
     * "Clear log" button on the Logs page.
     */
    public static function handle_form() {
        if (empty($_POST['wpmme_log_action']) || 'clear' !== $_POST['wpmme_log_action']) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('wpmme_clear_log', '_wpmme_log_nonce');
        self::clear();
        self::add('info', 'log', 'Log cleared.');
        self::$notice = array('success', 'Log cleared.');
    }

    /**
     * @return array|null array( type, message )
     */
    public static function get_notice() {
        return self::$notice;
    }
}

WPMME_Log::init();
