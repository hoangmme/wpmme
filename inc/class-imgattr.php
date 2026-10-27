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

        // WordPress content_save_pre receives slashed data. Unslash before processing.
        $unslashed_content = wp_unslash($content);

        // Safely replace all img tags with clean ones using regex to avoid DOMDocument mangling whitespace
        $new_content = preg_replace_callback('/<img[^>]+>/i', function($matches) {
            $img_tag = $matches[0];
            
            // Extract src or data-src
            $src = '';
            if (preg_match('/data-src=["\']([^"\']+)["\']/i', $img_tag, $src_matches)) {
                $src = $src_matches[1];
            } elseif (preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_matches)) {
                $src = $src_matches[1];
            }
            $src = $this->clean_url($src);

            // Extract alt
            $alt = '';
            if (preg_match('/alt=["\']([^"\']*)["\']/i', $img_tag, $alt_matches)) {
                $alt = trim(stripslashes($alt_matches[1]), "\"'\\ ");
            }

            // Preserve essential WordPress alignment and attachment classes
            $class_attr = '';
            if (preg_match('/class=["\']([^"\']*)["\']/i', $img_tag, $class_matches)) {
                $raw_classes = preg_split('/\s+/', trim($class_matches[1]));
                $preserved_classes = array();
                foreach ($raw_classes as $cls) {
                    $cls = trim($cls);
                    if (empty($cls)) {
                        continue;
                    }
                    if (in_array($cls, array('aligncenter', 'alignleft', 'alignright', 'alignnone'), true) || preg_match('/^wp-image-\d+$/', $cls)) {
                        $preserved_classes[] = $cls;
                    }
                }
                if (!empty($preserved_classes)) {
                    $class_attr = implode(' ', array_unique($preserved_classes));
                }
            }

            // Rebuild img tag
            $new_img = '<img';
            if (!empty($class_attr)) {
                $new_img .= ' class="' . esc_attr($class_attr) . '"';
            }
            if (!empty($src)) {
                $new_img .= ' src="' . esc_attr($src) . '"';
            }
            if (!empty($alt)) {
                $new_img .= ' alt="' . esc_attr($alt) . '"';
            }
            $new_img .= ' />';

            return $new_img;
        }, $unslashed_content);

        // Return slashed content to conform with content_save_pre convention
        return wp_slash($new_content);
    }
}
