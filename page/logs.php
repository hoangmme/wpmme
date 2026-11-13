<?php
/**
 * Logs page: MMe Core activity (updates, cache warmer, backups) and PHP fatal errors.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wpmme_page  = 'wpmme-logs';
$wpmme_title = 'MMe Core Logs';

$source  = isset($_GET['source']) ? sanitize_key(wp_unslash($_GET['source'])) : '';
$level   = isset($_GET['level']) ? sanitize_key(wp_unslash($_GET['level'])) : '';
$entries = WPMME_Log::read(300, $source, $level);
$sources = WPMME_Log::sources();
$notice  = WPMME_Log::get_notice();
$base    = admin_url('admin.php?page=wpmme-logs');
?>
<div class="wpmme-wrap wpmme-wrap--wide">
    <?php require WPMME_DIR . 'page/partials/header.php'; ?>

    <?php if ($notice) : ?>
        <div class="wpmme-alert wpmme-alert--<?php echo esc_attr($notice[0]); ?>"><?php echo esc_html($notice[1]); ?></div>
    <?php endif; ?>

    <div class="wpmme-card has-body">
        <div class="wpmme-card-header">
            <div class="wpmme-card-info">
                <h3>Activity log</h3>
                <p>Updates, cache warming, backups and PHP fatal errors. Newest first, last 300 entries. Stored in <code>uploads/wpmme-logs/</code> (not readable from the web).</p>
            </div>
            <form method="post" onsubmit="return confirm('Clear the whole log?');">
                <?php wp_nonce_field('wpmme_clear_log', '_wpmme_log_nonce'); ?>
                <input type="hidden" name="wpmme_log_action" value="clear">
                <button type="submit" class="button">Clear log</button>
            </form>
        </div>
        <div class="wpmme-card-body">
            <div class="wpmme-filters">
                <span>Source:</span>
                <a class="wpmme-chip<?php echo '' === $source ? ' is-active' : ''; ?>" href="<?php echo esc_url(remove_query_arg('source', add_query_arg('level', $level ?: false, $base))); ?>">All</a>
                <?php foreach ($sources as $s) : ?>
                    <a class="wpmme-chip<?php echo $s === $source ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('source' => $s, 'level' => $level ?: false), $base)); ?>"><?php echo esc_html($s); ?></a>
                <?php endforeach; ?>
                <span class="wpmme-filters__sep"></span>
                <span>Level:</span>
                <a class="wpmme-chip<?php echo '' === $level ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('source' => $source ?: false), $base)); ?>">All</a>
                <?php foreach (array('error', 'fatal', 'warning') as $l) : ?>
                    <a class="wpmme-chip<?php echo $l === $level ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('source' => $source ?: false, 'level' => $l), $base)); ?>"><?php echo esc_html($l); ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($entries)) : ?>
                <p class="wpmme-empty">No entries<?php echo ($source || $level) ? ' for this filter' : ''; ?>.</p>
            <?php else : ?>
                <table class="widefat striped wpmme-log-table">
                    <thead>
                        <tr><th style="width:150px">Time</th><th style="width:80px">Level</th><th style="width:90px">Source</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $e) : ?>
                            <tr>
                                <td title="<?php echo esc_attr(wp_date('Y-m-d H:i:s', $e['t'])); ?>">
                                    <?php echo esc_html(wp_date('d/m/Y H:i:s', $e['t'])); ?>
                                </td>
                                <td><span class="wpmme-badge wpmme-badge--<?php echo esc_attr($e['lvl']); ?>"><?php echo esc_html($e['lvl']); ?></span></td>
                                <td><?php echo esc_html($e['src']); ?></td>
                                <td>
                                    <?php echo esc_html($e['msg']); ?>
                                    <?php if (!empty($e['ctx'])) : ?>
                                        <details class="wpmme-log-ctx">
                                            <summary>Details</summary>
                                            <pre><?php echo esc_html(wp_json_encode($e['ctx'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
