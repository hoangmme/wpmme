jQuery(document).ready(function($) {
    if (typeof wp === 'undefined' || !wp.media) return;

    var currentTab = 'all';

    function renderTabs() {
        var $container = $('<div class="wpmme-media-tabs"></div>');
        
        $container.append('<div class="wpmme-media-tab active" data-id="all">All</div>');
        
        $.each(wpmme_media_tabs_obj.tabs, function(i, tab) {
            $container.append('<div class="wpmme-media-tab" data-id="'+tab.term_id+'"><span class="tab-name">'+tab.name+'</span><span class="tab-rename dashicons dashicons-edit" title="Rename"></span></div>');
        });

        $container.append('<div class="wpmme-media-tab-add" title="Add New Tab">+</div>');
        return $container;
    }

    // Hook into wp.Uploader (Plupload wrapper in WP) to manage uploads natively
    if (typeof wp !== 'undefined' && typeof wp.Uploader !== 'undefined') {
        var originalUploaderInit = wp.Uploader.prototype.init;
        wp.Uploader.prototype.init = function() {
            if (originalUploaderInit) {
                originalUploaderInit.apply(this, arguments);
            }

            var self = this;
            
            // 1. Ensure multipart_params is sent during upload
            this.uploader.bind('BeforeUpload', function(up, file) {
                if (currentTab !== 'all') {
                    up.settings.multipart_params.wpmme_media_tab = currentTab;
                }
            });

            // 2. Ensure placeholder shows up by adding taxonomy to local model
            this.uploader.bind('FilesAdded', function(up, files) {
                if (currentTab !== 'all' && wp.Uploader.queue) {
                    _.each(files, function(file) {
                        var model = wp.Uploader.queue.get(file.id);
                        if (model) {
                            model.set('wpmme_media_tab', parseInt(currentTab, 10));
                        }
                    });
                }
            });

            // 3. Force refresh grid on upload success to guarantee the new image shows
            this.uploader.bind('FileUploaded', function(up, file, response) {
                if (wp.media.frame && wp.media.frame.content && wp.media.frame.content.get() && wp.media.frame.content.get().collection) {
                    var collection = wp.media.frame.content.get().collection;
                    collection.props.set({ignore: (+ new Date())});
                }
            });
        };
    }

    function updateQueryAndUploader(tabId) {
        currentTab = tabId;

        // Save active tab to User Meta via AJAX
        $.post(wpmme_media_tabs_obj.ajaxurl, {
            action: 'wpmme_set_active_tab',
            nonce: wpmme_media_tabs_obj.nonce,
            tab_id: currentTab
        });

        // Try to update the current media frame collection
        if (wp.media.frame && wp.media.frame.content && wp.media.frame.content.get() && wp.media.frame.content.get().collection) {
            var collection = wp.media.frame.content.get().collection;
            
            // To force a refresh, we need to set the property. 
            // Backbone will only re-fetch if a property changes.
            collection.props.set({wpmme_media_tab: currentTab, ignore: (+ new Date())});
        }
    }

    // Set default tab on load to clear state
    $.post(wpmme_media_tabs_obj.ajaxurl, {
        action: 'wpmme_set_active_tab',
        nonce: wpmme_media_tabs_obj.nonce,
        tab_id: 'all'
    });

    // Modal view injection
    if (wp.media && wp.media.view && wp.media.view.MediaFrame) {
        var originalInit = wp.media.view.MediaFrame.prototype.initialize;
        wp.media.view.MediaFrame.prototype.initialize = function() {
            if (originalInit) {
                originalInit.apply(this, arguments);
            }
            
            this.on('ready', function() {
                // Do not inject in modal view if we are on upload.php grid mode
                if ($('body').hasClass('upload-php')) return;

                var frame = this;
                var $tabs = renderTabs();
                
                // Prepend tabs to the content area
                frame.$el.find('.media-frame-content').prepend($tabs);

                // Need to adjust top positioning for views because we injected tabs
                frame.$el.find('.media-frame-content > .attachments-browser').css('top', '40px');
            });
        };
    }

    // Grid View injection (upload.php)
    if ($('body').hasClass('upload-php') && $('.wrap').length) {
        var $tabs = renderTabs();
        $('.wrap').find('.wp-header-end').after($tabs);
        
        // Force show the uploader correctly via WP's own button to ensure Plupload shim is calculated
        setTimeout(function() {
            if ($('.page-title-action').length && !$('.uploader-inline').is(':visible')) {
                $('.page-title-action').first().click();
            }
        }, 500);

        // Poll for frame ready since it initializes asynchronously
        var gridCheck = setInterval(function() {
            if (wp.media.frame && wp.media.frame.content) {
                clearInterval(gridCheck);
            }
        }, 100);
    }

    // Event Listeners (Delegated so it works for both Modal and Grid)
    $(document).on('click', '.wpmme-media-tab', function() {
        var $tab = $(this);
        var tabId = $tab.data('id');
        
        // Update active class
        $('.wpmme-media-tab').removeClass('active');
        // Select all instances (modal and grid could theoretically both exist)
        $('.wpmme-media-tab[data-id="'+tabId+'"]').addClass('active');

        updateQueryAndUploader(tabId);
    });

    $(document).on('click', '.wpmme-media-tab-add', function() {
        var name = prompt("Enter new tab name:");
        if (name) {
            $.post(wpmme_media_tabs_obj.ajaxurl, {
                action: 'wpmme_add_media_tab',
                nonce: wpmme_media_tabs_obj.nonce,
                tab_name: name
            }, function(res) {
                if (res.success) {
                    wpmme_media_tabs_obj.tabs.push(res.data);
                    var $newTab = $('<div class="wpmme-media-tab" data-id="'+res.data.term_id+'"><span class="tab-name">'+res.data.name+'</span><span class="tab-rename dashicons dashicons-edit" title="Rename"></span></div>');
                    $newTab.insertBefore($('.wpmme-media-tab-add'));
                } else {
                    alert(res.data);
                }
            });
        }
    });

    $(document).on('click', '.tab-rename', function(e) {
        e.stopPropagation();
        var $tab = $(this).parent();
        var id = $tab.data('id');
        var oldName = $tab.find('.tab-name').text();
        var newName = prompt("Rename tab:", oldName);
        
        if (newName && newName !== oldName) {
            $.post(wpmme_media_tabs_obj.ajaxurl, {
                action: 'wpmme_rename_media_tab',
                nonce: wpmme_media_tabs_obj.nonce,
                term_id: id,
                new_name: newName
            }, function(res) {
                if (res.success) {
                    $('.wpmme-media-tab[data-id="'+id+'"]').find('.tab-name').text(newName);
                    $.each(wpmme_media_tabs_obj.tabs, function(i, t) {
                        if (t.term_id == id) t.name = newName;
                    });
                } else {
                    alert(res.data);
                }
            });
        }
    });

});
