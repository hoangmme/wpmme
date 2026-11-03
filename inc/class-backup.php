<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPMME_Backup {

    const CRON_HOOK = 'wpmme_monthly_backup';
    const MAX_BACKUPS = 2;

    public function __construct($options = array()) {
        add_action('wp_ajax_wpmme_manual_backup', array($this, 'handle_manual_backup'));
        add_action('wp_ajax_wpmme_delete_backup', array($this, 'handle_delete_backup'));
        add_action('wp_ajax_wpmme_download_backup', array($this, 'handle_download_backup'));
        add_action(self::CRON_HOOK, array($this, 'run_scheduled_backup'));
    }

    /**
     * Schedule or unschedule the monthly cron based on the toggle.
     */
    public static function sync_cron_schedule($enabled) {
        $next = wp_next_scheduled(self::CRON_HOOK);

        if ($enabled && !$next) {
            // Schedule for the 1st of next month at 03:00 AM server time
            $next_month = strtotime('first day of next month 03:00:00');
            wp_schedule_event($next_month, 'monthly', self::CRON_HOOK);
        } elseif (!$enabled && $next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    /**
     * Register the custom "monthly" cron interval.
     */
    public static function register_cron_interval($schedules) {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once Monthly', 'wpmme'),
            );
        }
        return $schedules;
    }

    /**
     * Cron callback — run backup and enforce retention.
     */
    public function run_scheduled_backup() {
        $this->create_backup();
        $this->enforce_retention();
    }

    /**
     * AJAX: Manual backup trigger.
     */
    public function handle_manual_backup() {
        check_ajax_referer('wpmme_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $result = $this->create_backup();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $this->enforce_retention();

        wp_send_json_success(array(
            'message' => 'Backup created successfully.',
            'backups' => self::get_backup_list(),
        ));
    }

    /**
     * AJAX: Delete a specific backup.
     */
    public function handle_delete_backup() {
        check_ajax_referer('wpmme_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';
        if (empty($filename)) {
            wp_send_json_error('Missing filename.');
        }

        $dir = self::get_backup_dir();
        $filepath = $dir . $filename;

        if (file_exists($filepath)) {
            unlink($filepath);
        }

        wp_send_json_success(array(
            'message' => 'Backup deleted.',
            'backups' => self::get_backup_list(),
        ));
    }

    /**
     * AJAX: Stream download a backup file.
     */
    public function handle_download_backup() {
        check_ajax_referer('wpmme_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        $filename = isset($_GET['filename']) ? sanitize_file_name($_GET['filename']) : '';
        $dir = self::get_backup_dir();
        $filepath = $dir . $filename;

        if (empty($filename) || !file_exists($filepath)) {
            wp_die('File not found.');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }

    /**
     * Create a full backup (database SQL + entire WordPress source, excluding uploads).
     *
     * Includes: WordPress core, wp-config.php, .htaccess, themes, plugins, mu-plugins, etc.
     * Excludes: wp-content/uploads (media files — too large), cache, backup dirs.
     *
     * @return true|\WP_Error
     */
    public function create_backup() {
        $dir = self::get_backup_dir();

        if (!wp_mkdir_p($dir)) {
            return new WP_Error('backup_dir', 'Could not create backup directory.');
        }

        // Secure with .htaccess and index
        if (!file_exists($dir . '.htaccess')) {
            file_put_contents($dir . '.htaccess', "Deny from all\n");
        }
        if (!file_exists($dir . 'index.php')) {
            file_put_contents($dir . 'index.php', "<?php // Silence is golden.\n");
        }

        $site_name = sanitize_file_name(parse_url(home_url(), PHP_URL_HOST));
        $date_str  = date('Y-m-d_His');
        $zip_name  = "backup_{$site_name}_{$date_str}.zip";
        $zip_path  = $dir . $zip_name;

        // Step 1: Export database to a temp SQL file
        $sql_file = $dir . "db_{$date_str}.sql";
        $db_result = $this->export_database($sql_file);

        if (is_wp_error($db_result)) {
            return $db_result;
        }

        // Step 2: Create ZIP archive
        if (!class_exists('ZipArchive')) {
            @unlink($sql_file);
            return new WP_Error('zip_missing', 'ZipArchive extension is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sql_file);
            return new WP_Error('zip_create', 'Could not create ZIP file.');
        }

        // Add SQL dump
        $zip->addFile($sql_file, 'database.sql');

        // Step 3: Add entire WordPress root (ABSPATH), excluding uploads & heavy dirs
        $abspath = untrailingslashit(ABSPATH);

        // Resolve the uploads dir relative to ABSPATH for exclusion
        $upload_dir = wp_upload_dir();
        $uploads_rel = str_replace($abspath . '/', '', $upload_dir['basedir']); // e.g. "wp-content/uploads"

        $exclude_dirs = array(
            $uploads_rel,                           // wp-content/uploads (media — too large)
            'wp-content/cache',
            'wp-content/upgrade',
            'wp-content/wpmme-backups',             // our own backup dir
            'wp-content/ai1wm-backups',
            'wp-content/updraft',
            'wp-content/backup',
            'wp-content/backups',
            'wp-content/debug.log',
        );

        $this->add_directory_to_zip($zip, $abspath, 'wordpress', $exclude_dirs);

        $zip->close();

        // Clean up temp SQL
        @unlink($sql_file);

        // Save metadata
        $backups = get_option('wpmme_backup_list', array());
        $backups[] = array(
            'filename' => $zip_name,
            'date'     => current_time('mysql'),
            'size'     => filesize($zip_path),
            'type'     => 'full',
        );
        update_option('wpmme_backup_list', $backups);

        return true;
    }

    /**
     * Export the database to a SQL file using WordPress $wpdb.
     *
     * @param string $filepath
     * @return true|\WP_Error
     */
    private function export_database($filepath) {
        global $wpdb;

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            return new WP_Error('db_export', 'Could not open file for writing.');
        }

        fwrite($handle, "-- MMe Core Database Backup\n");
        fwrite($handle, "-- Date: " . current_time('mysql') . "\n");
        fwrite($handle, "-- Site: " . home_url() . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET SQL_MODE = '';\n\n");

        // Get all tables with the site prefix
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");

        foreach ($tables as $table) {
            // Table structure
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if ($create) {
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $create[1] . ";\n\n");
            }

            // Table data in chunks
            $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            $offset = 0;
            $chunk = 500;

            while ($offset < $total) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$chunk} OFFSET {$offset}", ARRAY_A);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $values = array_map(function($v) use ($wpdb) {
                            if (is_null($v)) return 'NULL';
                            return "'" . esc_sql($v) . "'";
                        }, array_values($row));

                        $cols = array_map(function($c) {
                            return "`{$c}`";
                        }, array_keys($row));

                        fwrite($handle, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $values) . ");\n");
                    }
                }
                $offset += $chunk;
            }

            fwrite($handle, "\n");
        }

        fclose($handle);
        return true;
    }

    /**
     * Recursively add a directory to a ZipArchive, skipping excluded paths.
     *
     * @param ZipArchive $zip
     * @param string     $dir_path  Absolute path to the directory to add.
     * @param string     $zip_base  Base path inside the ZIP (e.g. "wordpress").
     * @param array      $exclude   Relative paths to exclude (e.g. "wp-content/uploads").
     */
    private function add_directory_to_zip($zip, $dir_path, $zip_base, $exclude = array()) {
        if (!is_dir($dir_path)) {
            return;
        }

        $dir_path = rtrim($dir_path, '/');
        $iterator = new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST);

        // Max backup size guard: 500MB total for files
        $total_size = 0;
        $max_size = 500 * 1024 * 1024; // 500 MB

        foreach ($files as $file) {
            $relative = str_replace($dir_path . '/', '', $file->getPathname());

            // Skip excluded paths — match if relative path starts with any exclusion entry
            $skip = false;
            foreach ($exclude as $ex) {
                if ($relative === $ex || strpos($relative, $ex . '/') === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $entry_name = $zip_base . '/' . $relative;

            if ($file->isDir()) {
                $zip->addEmptyDir($entry_name);
            } else {
                // Skip files > 50MB individually
                if ($file->getSize() > 50 * 1024 * 1024) {
                    continue;
                }
                $total_size += $file->getSize();
                if ($total_size > $max_size) {
                    break;
                }
                $zip->addFile($file->getPathname(), $entry_name);
            }
        }
    }

    /**
     * Keep only the latest MAX_BACKUPS, delete older ones.
     */
    public function enforce_retention() {
        $backups = get_option('wpmme_backup_list', array());
        $dir = self::get_backup_dir();

        // Sort by date descending (newest first)
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Remove excess
        while (count($backups) > self::MAX_BACKUPS) {
            $old = array_pop($backups);
            $old_path = $dir . $old['filename'];
            if (file_exists($old_path)) {
                @unlink($old_path);
            }
        }

        update_option('wpmme_backup_list', $backups);
    }

    /**
     * Get the backup storage directory.
     */
    public static function get_backup_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/wpmme-backups/';
    }

    /**
     * Get the list of existing backups with human-readable sizes.
     */
    public static function get_backup_list() {
        $backups = get_option('wpmme_backup_list', array());
        $dir = self::get_backup_dir();

        // Validate files still exist on disk
        $valid = array();
        foreach ($backups as $b) {
            if (file_exists($dir . $b['filename'])) {
                $b['size_human'] = size_format($b['size'], 1);
                $valid[] = $b;
            }
        }

        // Re-save cleaned list
        if (count($valid) !== count($backups)) {
            update_option('wpmme_backup_list', $valid);
        }

        // Sort newest first
        usort($valid, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $valid;
    }

    /**
     * Get the next scheduled backup timestamp.
     */
    public static function get_next_scheduled() {
        return wp_next_scheduled(self::CRON_HOOK);
    }
}
