<?php
/**
 * Shared header and navigation of the MMe Core admin pages.
 *
 * @var string $wpmme_page Current page slug.
 * @var string $wpmme_title Page title.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wpmme_tabs = array(
    'wpmme-settings' => array('dashicons-admin-generic', 'Settings'),
    'wpmme-cache'    => array('dashicons-performance', 'Cache Warmer'),
    'wpmme-updates'  => array('dashicons-update', 'Updates'),
    'wpmme-logs'     => array('dashicons-list-view', 'Logs'),
);
$wpmme_has_update = class_exists('WPMME_Updater') && WPMME_Updater::instance()->has_update(false);
?>
<div class="wrap">
    <!-- WordPress will automatically inject admin notices here -->
</div>
<div class="wpmme-header">
    <h1>
        <?php echo esc_html($wpmme_title); ?>
        <span class="wpmme-version">v<?php echo esc_html(WPMME_VERSION); ?></span>
        <span class="wpmme-build">Build <?php echo esc_html(WPMME_BUILD); ?></span>
    </h1>
    <nav class="wpmme-nav">
        <?php foreach ($wpmme_tabs as $slug => $tab) : ?>
            <a class="wpmme-nav__item<?php echo $slug === $wpmme_page ? ' is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>">
                <span class="dashicons <?php echo esc_attr($tab[0]); ?>"></span>
                <?php echo esc_html($tab[1]); ?>
                <?php if ('wpmme-updates' === $slug && $wpmme_has_update) : ?>
                    <span class="wpmme-nav__badge">1</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
