<?php
/**
 * Cache Warmer page.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wpmme_page  = 'wpmme-cache';
$wpmme_title = 'Cache Warmer';

$settings = WPMME_Cache_Warmer::get_settings();
$last     = $settings['last_run'];
$next     = wp_next_scheduled(WPMME_Cache_Warmer::CRON_HOOK);
$cron_off = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
?>
<div class="wpmme-wrap">
    <?php require WPMME_DIR . 'page/partials/header.php'; ?>

    <section class="wpmme-status <?php echo !empty($settings['enabled']) ? 'is-ok' : 'is-off'; ?>">
        <span class="wpmme-status__icon dashicons <?php echo !empty($settings['enabled']) ? 'dashicons-clock' : 'dashicons-controls-pause'; ?>"></span>
        <div class="wpmme-status__body">
            <span class="wpmme-status__label"><?php echo !empty($settings['enabled']) ? 'Daily warming on' : 'Daily warming off'; ?></span>
            <h2 class="wpmme-status__title">
                <?php if (!empty($last['time'])) : ?>
                    Last run <?php echo esc_html(wp_date('d/m/Y H:i', $last['time'])); ?>
                    (<?php echo esc_html('cron' === $last['trigger'] ? 'scheduled' : 'manual'); ?>):
                    <strong><?php echo (int) $last['total']; ?></strong> URLs,
                    <strong><?php echo (int) $last['hit']; ?></strong> cached,
                    <strong><?php echo (int) $last['warmed']; ?></strong> warmed<?php if (!empty($last['failed'])) : ?>,
                    <strong class="wpmme-text-error"><?php echo (int) $last['failed']; ?> failed</strong><?php endif; ?>
                <?php else : ?>
                    Never run yet
                <?php endif; ?>
            </h2>
            <span class="wpmme-status__meta">
                <span id="wpmme-cache-next"><?php echo $next ? 'Next run: ' . esc_html(wp_date('d/m/Y H:i', $next)) : 'No run scheduled'; ?></span>
                · <span id="wpmme-cache-detect">Checking page cache…</span>
            </span>
        </div>
        <div class="wpmme-status__actions">
            <button type="button" class="button button-primary button-large" id="wpmme-cache-run">
                <span class="dashicons dashicons-controls-play"></span> Run now
            </button>
        </div>
    </section>

    <?php if ($cron_off) : ?>
        <div class="wpmme-alert wpmme-alert--warning">DISABLE_WP_CRON is set in wp-config.php: the daily run only happens if a system cron calls wp-cron.php.</div>
    <?php endif; ?>

    <div class="wpmme-card has-body" id="wpmme-cache-progress-card" style="display:none">
        <div class="wpmme-card-header">
            <div class="wpmme-card-info">
                <h3>Run</h3>
                <p id="wpmme-cache-progress-text">Starting…</p>
            </div>
            <button type="button" class="button" id="wpmme-cache-stop">Stop</button>
        </div>
        <div class="wpmme-card-body">
            <div class="wpmme-progress"><div class="wpmme-progress-bar" id="wpmme-cache-progress"></div></div>
            <table class="widefat striped wpmme-cache-table">
                <thead><tr><th>URL</th><th style="width:110px">Cache</th><th style="width:70px">HTTP</th><th style="width:70px">Time</th></tr></thead>
                <tbody id="wpmme-cache-results"></tbody>
            </table>
        </div>
    </div>

    <form id="wpmme-cache-form" class="wpmme-card has-body" onsubmit="return false;">
        <div class="wpmme-card-header">
            <div class="wpmme-card-info">
                <h3>Schedule</h3>
                <p>Request every URL below once a day, so the page cache is already built when the first visitor arrives.</p>
            </div>
            <label class="wpmme-switch">
                <input type="checkbox" name="enabled" <?php checked(!empty($settings['enabled'])); ?>>
                <span class="wpmme-slider"></span>
            </label>
        </div>
        <div class="wpmme-card-body">
            <div class="wpmme-form-group wpmme-inline">
                <label for="wpmme-cache-time">Run every day at</label>
                <input type="time" id="wpmme-cache-time" name="time" value="<?php echo esc_attr($settings['time']); ?>">
                <span class="description">Site time zone (<?php echo esc_html(wp_timezone_string()); ?>). Pick a quiet hour.</span>
            </div>

            <div class="wpmme-form-group">
                <label class="wpmme-check">
                    <input type="checkbox" name="localhost" <?php checked(!empty($settings['localhost'])); ?>>
                    Send requests via localhost (127.0.0.1)
                </label>
                <p class="description">Recommended on WordOps/VPS behind Cloudflare: the requests reach the nginx page cache on this server directly instead of going out through the CDN. Turn off on shared hosting where the site is not served from this machine.</p>
            </div>

            <div class="wpmme-form-group">
                <label for="wpmme-cache-urls">URLs to warm <span class="wpmme-count" id="wpmme-cache-count"></span></label>
                <textarea id="wpmme-cache-urls" name="urls" rows="12" class="large-text code" placeholder="https://example.com/&#10;/lien-he/"><?php echo esc_textarea(implode("\n", $settings['urls'])); ?></textarea>
                <p class="description">One URL per line, full URL or path (<code>/blog/</code>). Only URLs of this site are kept. Lines starting with <code>#</code> are ignored. Pages that must not be cached (cart, checkout, account) are fine to leave out.</p>
                <button type="button" class="button" id="wpmme-cache-sitemap"><span class="dashicons dashicons-download"></span> Add URLs from sitemap</button>
            </div>
        </div>
    </form>
</div>

<div class="wpmme-footer">
    <button type="button" class="button button-primary button-hero" id="wpmme-cache-save">Save</button>
    <span class="wpmme-footer__msg" id="wpmme-cache-msg"></span>
</div>
