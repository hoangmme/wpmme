<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Clean Up Admin Notices": hides plugin nags and promo notices across wp-admin.
 *
 * Only nags are hidden. Notices that answer something the user just did stay visible:
 * the new Application Password, "Settings saved", form errors, "Post updated",
 * and inline notices inside a page. Screens where those matter are skipped entirely.
 */
class WPMME_Admin_Notices {

    /**
     * Admin screens ($pagenow) where nothing is hidden.
     *
     * profile.php / user-edit.php / authorize-application.php show the new
     * Application Password in a notice; without it the password is lost.
     */
    const SKIP_PAGES = array(
        'plugins.php',
        'plugin-install.php',
        'update-core.php',
        'update.php',
        'profile.php',
        'user-edit.php',
        'authorize-application.php',
        'site-health.php',
    );

    /**
     * Notices that are never hidden (results of a user action, or part of a page).
     */
    const KEEP = array(
        '.inline',
        '.notice-alt',
        '.settings-error',
        '#message',
        '.new-application-password-notice',
        '.wpmme-keep',
    );

    public function __construct() {
        add_action('admin_head', array($this, 'hide_notices_css'));
    }

    public function hide_notices_css() {
        global $pagenow;

        if (in_array($pagenow, self::SKIP_PAGES, true)) {
            return;
        }

        $not = '';
        foreach (self::KEEP as $selector) {
            $not .= ':not(' . $selector . ')';
        }

        $selectors = array();
        foreach (array('div.notice', 'div.updated', 'div.error') as $base) {
            $selectors[] = $base . $not;
        }
        $selectors[] = 'div.update-nag';

        echo '<style id="wpmme-hide-notices">' . implode(",\n", $selectors) . '{display:none !important;}</style>';
    }
}
