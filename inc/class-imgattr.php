<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPMME_ImgAttr {
    public function __construct() {
        add_filter('content_save_pre', array($this, 'remove_img_attributes'), 20);
    }

    private function clean_url($url) {
        if (empty($url)) {
            return '';
        }
        $url = stripslashes($url);
        $url = str_replace(array('%5C%22', '%22', '\"', '""', "\\'", "''"), '', $url);
        $url = trim($url, "\"'\\ ");
        return $url;
    }

    public function remove_img_attributes($content) {
        if (empty($content)) {
            return $content;
        }

        // WordPress content_save_pre receives slashed data. Unslash before DOMDocument parsing.
        $unslashed_content = wp_unslash($content);

        $dom = new DOMDocument();
        $libxml_previous_state = libxml_use_internal_errors(true);
        
        // Load HTML with UTF-8 encoding
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $unslashed_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        libxml_clear_errors();
        libxml_use_internal_errors($libxml_previous_state);

        $images = $dom->getElementsByTagName('img');
        $images_to_replace = array();

        foreach ($images as $img) {
            $src = '';
            if ($img->hasAttribute('data-src')) {
                $src = $img->getAttribute('data-src');
            } elseif ($img->hasAttribute('src')) {
                $src = $img->getAttribute('src');
            }

            $src = $this->clean_url($src);
            $alt = $img->hasAttribute('alt') ? trim(stripslashes($img->getAttribute('alt')), "\"'\\ ") : '';

            $new_img = $dom->createElement('img');
            if (!empty($src)) {
                $new_img->setAttribute('src', $src);
            }
            if (!empty($alt)) {
                $new_img->setAttribute('alt', $alt);
            }

            $images_to_replace[] = array('old' => $img, 'new' => $new_img);
        }

        foreach ($images_to_replace as $item) {
            $item['old']->parentNode->replaceChild($item['new'], $item['old']);
        }

        $new_content = $dom->saveHTML();
        $new_content = str_replace('<?xml encoding="utf-8" ?>', '', $new_content);

        // Return slashed content to conform with content_save_pre convention
        return wp_slash($new_content);
    }
}
