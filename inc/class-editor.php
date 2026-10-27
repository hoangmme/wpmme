<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPMME_Editor {
    public function __construct() {
        // Force classic editor
        add_filter('use_block_editor_for_post', '__return_false');
        add_filter('use_block_editor_for_post_type', '__return_false');

        // Remove Gutenberg styles
        add_action('wp_enqueue_scripts', array($this, 'remove_block_styles'), 100);
        add_action('wp_head', array($this, 'frontend_alignment_styles'));

        // Add editor styles
        add_filter('mce_css', array($this, 'add_editor_style'));

        // Add body class to editor
        add_filter('tiny_mce_before_init', array($this, 'add_body_class'));
    }

    public function remove_block_styles() {
        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('wp-block-library-theme');
        wp_dequeue_style('wc-blocks-style');
        wp_dequeue_style('global-styles');
    }

    public function frontend_alignment_styles() {
        echo '<style id="wpmme-align-styles">
.aligncenter, img.aligncenter, figure.aligncenter { display: block; margin-left: auto; margin-right: auto; }
.alignleft, img.alignleft { float: left; margin: 0 1.5em 1em 0; }
.alignright, img.alignright { float: right; margin: 0 0 1em 1.5em; }
.alignnone, img.alignnone { float: none; margin: 0; }
</style>' . "\n";
    }

    public function add_editor_style($mce_css) {
        if (!empty($mce_css)) {
            $mce_css .= ',';
        }
        $mce_css .= WPMME_URL . 'assets/css/editor-style.css?v=' . time();
        return $mce_css;
    }

    public function add_body_class($settings) {
        if (isset($settings['body_class'])) {
            $settings['body_class'] .= ' wpmme-editor';
        } else {
            $settings['body_class'] = 'wpmme-editor';
        }
        return $settings;
    }
}
