<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPMME_Admin_Notices {
    public function __construct() {
        add_action('admin_head', array($this, 'hide_notices_css'));
    }

    public function hide_notices_css() {
        global $pagenow;
        
        // Do not hide notices on the plugins page or updates page
        if ($pagenow === 'plugins.php' || $pagenow === 'update-core.php') {
            return;
        }

        // Print CSS to hide standard admin notices
        echo '<style>
            div.notice, 
            div.updated, 
            div.error, 
            div.update-nag { 
                display: none !important; 
            }
            /* Allow WooCommerce/Core specific important notices if needed, but the user requested cleaning them globally */
        </style>';
    }
}
