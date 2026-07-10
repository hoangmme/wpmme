<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPMME_Media_Tabs {
    public function __construct() {
        // Register taxonomy
        add_action('init', array($this, 'register_taxonomy'));

        // Enqueue scripts and styles for Media Library screen (upload.php)
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Ensure scripts are loaded in media modal when enqueued on post edit screen
        add_action('wp_enqueue_media', array($this, 'enqueue_assets_for_modal'));

        // Handle AJAX for tabs
        add_action('wp_ajax_wpmme_add_media_tab', array($this, 'ajax_add_tab'));
        add_action('wp_ajax_wpmme_rename_media_tab', array($this, 'ajax_rename_tab'));
        add_action('wp_ajax_wpmme_set_active_tab', array($this, 'ajax_set_active_tab'));

        // Filter attachments for media grid/modal
        add_filter('ajax_query_attachments_args', array($this, 'filter_attachments_by_tab'));
        add_filter('wp_prepare_attachment_for_js', array($this, 'prepare_attachment_for_js'), 10, 2);
        
        // Intercept uploads to assign them to the current tab
        add_action('add_attachment', array($this, 'assign_tab_on_upload'));
    }

    public function register_taxonomy() {
        register_taxonomy('wpmme_media_tab', 'attachment', array(
            'labels' => array(
                'name' => 'Media Tabs',
                'singular_name' => 'Media Tab',
            ),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'hierarchical' => false,
            'update_count_callback' => '_update_generic_term_count',
        ));
    }

    public function enqueue_assets($hook) {
        if ($hook === 'upload.php') {
            $this->load_assets();
        }
    }

    public function enqueue_assets_for_modal() {
        $this->load_assets();
    }

    private function load_assets() {
        wp_enqueue_style('wpmme-media-tabs-css', WPMME_URL . 'assets/css/media-tabs.css', array(), time());
        wp_enqueue_script('wpmme-media-tabs-js', WPMME_URL . 'assets/js/media-tabs.js', array('jquery', 'media-views', 'media-models'), time(), true);

        $tabs = get_terms(array(
            'taxonomy' => 'wpmme_media_tab',
            'hide_empty' => false,
        ));

        $tabs_data = array();
        if (!is_wp_error($tabs)) {
            foreach ($tabs as $tab) {
                $tabs_data[] = array(
                    'term_id' => $tab->term_id,
                    'name'    => $tab->name,
                    'slug'    => $tab->slug,
                );
            }
        }
        $active_tab = get_user_meta(get_current_user_id(), 'wpmme_active_media_tab', true);
        if (empty($active_tab)) {
            $active_tab = 'all';
        }

        wp_localize_script('wpmme-media-tabs-js', 'wpmme_media_tabs_obj', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wpmme_media_tabs_nonce'),
            'tabs'    => $tabs_data,
            'active_tab' => $active_tab,
        ));
    }

    public function ajax_add_tab() {
        check_ajax_referer('wpmme_media_tabs_nonce', 'nonce');
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Permission denied.');
        }

        $tab_name = sanitize_text_field($_POST['tab_name']);
        if (empty($tab_name)) {
            wp_send_json_error('Tab name is required.');
        }

        $term = wp_insert_term($tab_name, 'wpmme_media_tab');
        
        if (is_wp_error($term)) {
            wp_send_json_error($term->get_error_message());
        }

        $new_term = get_term($term['term_id'], 'wpmme_media_tab');
        
        wp_send_json_success(array(
            'term_id' => $new_term->term_id,
            'name'    => $new_term->name,
            'slug'    => $new_term->slug,
        ));
    }

    public function ajax_rename_tab() {
        check_ajax_referer('wpmme_media_tabs_nonce', 'nonce');
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Permission denied.');
        }

        $term_id = intval($_POST['term_id']);
        $new_name = sanitize_text_field($_POST['new_name']);
        
        if (empty($term_id) || empty($new_name)) {
            wp_send_json_error('Invalid parameters.');
        }

        $term = wp_update_term($term_id, 'wpmme_media_tab', array(
            'name' => $new_name
        ));

        if (is_wp_error($term)) {
            wp_send_json_error($term->get_error_message());
        }

        wp_send_json_success('Tab renamed successfully.');
    }

    public function ajax_set_active_tab() {
        check_ajax_referer('wpmme_media_tabs_nonce', 'nonce');
        if (is_user_logged_in()) {
            $tab_id = sanitize_text_field($_POST['tab_id']);
            update_user_meta(get_current_user_id(), 'wpmme_active_media_tab', $tab_id);
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    public function filter_attachments_by_tab($query) {
        if (isset($_REQUEST['query']) && is_array($_REQUEST['query']) && isset($_REQUEST['query']['wpmme_media_tab']) && $_REQUEST['query']['wpmme_media_tab'] !== 'all') {
            $term_id = intval($_REQUEST['query']['wpmme_media_tab']);
            if ($term_id > 0) {
                if (isset($query['wpmme_media_tab'])) {
                    unset($query['wpmme_media_tab']);
                }
                $query['tax_query'] = array(
                    array(
                        'taxonomy' => 'wpmme_media_tab',
                        'field'    => 'term_id',
                        'terms'    => $term_id,
                    )
                );
            }
        }
        return $query;
    }

    public function prepare_attachment_for_js($response, $attachment) {
        $terms = wp_get_object_terms($attachment->ID, 'wpmme_media_tab', array('fields' => 'ids'));
        if (!empty($terms) && !is_wp_error($terms)) {
            // WordPress collections filter on exact match if we use props.set({wpmme_media_tab: ID})
            $response['wpmme_media_tab'] = $terms[0];
        }
        return $response;
    }

    public function assign_tab_on_upload($post_id) {
        $tab_id = isset($_REQUEST['wpmme_media_tab']) ? sanitize_text_field($_REQUEST['wpmme_media_tab']) : '';
        
        if (!empty($tab_id) && $tab_id !== 'all') {
            $term_id = intval($tab_id);
            if ($term_id > 0) {
                wp_set_object_terms($post_id, $term_id, 'wpmme_media_tab', true);
            }
        }
    }
}
