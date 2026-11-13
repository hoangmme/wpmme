<?php
/**
 * Updates page: installed version vs the main branch on GitHub.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wpmme_page  = 'wpmme-updates';
$wpmme_title = 'MMe Core Updates';

$updater    = WPMME_Updater::instance();
$info       = $updater->get_remote_info();
$has_update = $updater->has_update();
$latest     = !empty($info['version']) ? $info['version'] : '—';
$notice     = $updater->get_notice();
$state      = $has_update ? 'is-update' : (empty($info['error']) ? 'is-ok' : 'is-error');
$updates    = array_slice(WPMME_Log::read(200, 'updater'), 0, 8);
?>
<div class="wpmme-wrap">
    <?php require WPMME_DIR . 'page/partials/header.php'; ?>

    <section class="wpmme-status <?php echo esc_attr($state); ?>">
        <span class="wpmme-status__icon dashicons <?php echo $has_update ? 'dashicons-update' : (empty($info['error']) ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
        <div class="wpmme-status__body">
            <span class="wpmme-status__label"><?php echo $has_update ? 'Update available' : (empty($info['error']) ? 'Up to date' : 'Could not check'); ?></span>
            <h2 class="wpmme-status__title">
                Installed <strong><?php echo esc_html(WPMME_VERSION); ?></strong>
                · Latest on GitHub <strong><?php echo esc_html($latest); ?></strong>
                <?php if (!empty($info['build'])) : ?><small>(Build <?php echo esc_html($info['build']); ?>)</small><?php endif; ?>
            </h2>
            <?php if (!empty($info['checked'])) : ?>
                <span class="wpmme-status__meta">Checked <?php echo esc_html(wp_date('d/m/Y H:i', (int) $info['checked'])); ?></span>
            <?php endif; ?>
        </div>
        <div class="wpmme-status__actions">
            <form method="post">
                <?php wp_nonce_field('wpmme_check_updates', '_wpmme_update_nonce'); ?>
                <input type="hidden" name="wpmme_update_action" value="check">
                <button type="submit" class="button button-large"><span class="dashicons dashicons-image-rotate"></span> Check for updates</button>
            </form>
            <?php if ($has_update) : ?>
                <a class="button button-primary button-large" href="<?php echo esc_url($updater->get_update_url()); ?>">
                    <span class="dashicons dashicons-download"></span> Update to <?php echo esc_html($latest); ?>
                </a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($notice) : ?>
        <div class="wpmme-alert wpmme-alert--<?php echo esc_attr($notice[0]); ?>"><?php echo esc_html($notice[1]); ?></div>
    <?php elseif (!empty($info['error'])) : ?>
        <div class="wpmme-alert wpmme-alert--error"><?php echo esc_html($info['error']); ?></div>
    <?php endif; ?>

    <div class="wpmme-columns">
        <div class="wpmme-columns__main">
            <div class="wpmme-card has-body">
                <div class="wpmme-card-header">
                    <div class="wpmme-card-info"><h3>Changelog</h3></div>
                </div>
                <div class="wpmme-card-body wpmme-changelog">
                    <?php
                    if (!empty($info['changelog'])) {
                        echo wp_kses_post($info['changelog']);
                    } else {
                        echo '<p class="wpmme-empty">No changelog available.</p>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <aside class="wpmme-columns__aside">
            <div class="wpmme-card has-body">
                <div class="wpmme-card-header"><div class="wpmme-card-info"><h3>Update source</h3></div></div>
                <div class="wpmme-card-body">
                    <ul class="wpmme-meta">
                        <li><span>Repository</span><a href="<?php echo esc_url($updater->get_repo_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(WPMME_Updater::REPO); ?></a></li>
                        <li><span>Branch</span><strong><?php echo esc_html(WPMME_Updater::BRANCH); ?></strong></li>
                        <li><span>Folder</span><code><?php echo esc_html(dirname(WPMME_BASENAME)); ?></code></li>
                    </ul>
                    <p class="description">WordPress also checks automatically every few hours; the update then shows on the Plugins page too, and auto-updates can be turned on there.</p>
                </div>
            </div>

            <div class="wpmme-card has-body">
                <div class="wpmme-card-header"><div class="wpmme-card-info"><h3>Recent updates</h3></div></div>
                <div class="wpmme-card-body">
                    <?php if (empty($updates)) : ?>
                        <p class="wpmme-empty">Nothing logged yet.</p>
                    <?php else : ?>
                        <ul class="wpmme-timeline">
                            <?php foreach ($updates as $e) : ?>
                                <li class="is-<?php echo esc_attr($e['lvl']); ?>">
                                    <span><?php echo esc_html(wp_date('d/m/Y H:i', $e['t'])); ?></span>
                                    <?php echo esc_html($e['msg']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a class="wpmme-link" href="<?php echo esc_url(admin_url('admin.php?page=wpmme-logs&source=updater')); ?>">All update logs →</a>
                </div>
            </div>

            <div class="wpmme-card has-body">
                <div class="wpmme-card-header"><div class="wpmme-card-info"><h3>Reinstall</h3></div></div>
                <div class="wpmme-card-body">
                    <p class="description">Downloads the main branch from GitHub and overwrites the plugin files, even when the version number did not change. Use it if a normal update fails.</p>
                    <button type="button" class="button" id="btn-force-update">Reinstall from GitHub</button>
                </div>
            </div>
        </aside>
    </div>
</div>
