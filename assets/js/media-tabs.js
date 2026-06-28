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

    function updateQueryAndUploader(tabId) {
        currentTab = tabId;

        // Save active tab to User Meta via AJAX
        $.post(wpmme_media_tabs_obj.ajaxurl, {
            action: 'wpmme_set_active_tab',
            nonce: wpmme_media_tabs_obj.nonce,
            tab_id: currentTab
        });

        // 1. Update wp.Uploader defaults
        if (typeof wp.Uploader !== 'undefined' && wp.Uploader.defaults) {
            wp.Uploader.defaults.multipart_params.wpmme_media_tab = currentTab;
        }

        // 2. Update Media Modal Plupload instance natively
        if (wp.media.frame && wp.media.frame.uploader && wp.media.frame.uploader.uploader && wp.media.frame.uploader.uploader.uploader) {
            var pluploadInst = wp.media.frame.uploader.uploader.uploader;
            if (currentTab !== 'all') {
                pluploadInst.settings.multipart_params.wpmme_media_tab = currentTab;
            } else {
                delete pluploadInst.settings.multipart_params.wpmme_media_tab;
            }
        }
        
        // 3. Update Grid View Plupload instance natively
        if (typeof uploader !== 'undefined' && uploader.settings) {
            if (currentTab !== 'all') {
                uploader.settings.multipart_params.wpmme_media_tab = currentTab;
            } else {
                delete uploader.settings.multipart_params.wpmme_media_tab;
            }
        }

        // 4. Update the Backbone collection filter
        if (wp.media.frame && wp.media.frame.content && wp.media.frame.content.get() && wp.media.frame.content.get().collection) {
            var collection = wp.media.frame.content.get().collection;
            collection.props.set({wpmme_media_tab: currentTab, ignore: (+ new Date())});
        }
    }

    // Set default tab on load to clear state
    $.post(wpmme_media_tabs_obj.ajaxurl, {
        action: 'wpmme_set_active_tab',
        nonce: wpmme_media_tabs_obj.nonce,
        tab_id: 'all'
    });

    // Hook queue add early to ensure placeholders show up in custom tabs
    function hookUploaderQueue() {
        if (typeof wp !== 'undefined' && wp.Uploader && wp.Uploader.queue && !wp.Uploader.queue._wpmme_hooked) {
            wp.Uploader.queue._wpmme_hooked = true;
            wp.Uploader.queue.on('add', function(model) {
                if (currentTab !== 'all') {
                    model.set('wpmme_media_tab', parseInt(currentTab, 10));
                }
            });
        }
    }

    // Modal view injection
    if (wp.media && wp.media.view && wp.media.view.MediaFrame) {
        var originalInit = wp.media.view.MediaFrame.prototype.initialize;
        wp.media.view.MediaFrame.prototype.initialize = function() {
            if (originalInit) {
                originalInit.apply(this, arguments);
            }
            
            this.on('ready', function() {
                hookUploaderQueue();

                if ($('body').hasClass('upload-php')) return;

                var frame = this;
                var $tabs = renderTabs();
                
                frame.$el.find('.media-frame-content').prepend($tabs);
                frame.$el.find('.media-frame-content > .attachments-browser').css('top', '40px');
            });
        };
    }

    // Grid View injection (upload.php)
    if ($('body').hasClass('upload-php') && $('.wrap').length) {
        var $tabs = renderTabs();
        $('.wrap').find('.wp-header-end').after($tabs);
        
        setTimeout(function() {
            if ($('.page-title-action').length && !$('.uploader-inline').is(':visible')) {
                $('.page-title-action').first().click();
            }
        }, 500);

        var gridCheck = setInterval(function() {
            if (wp.media.frame && wp.media.frame.content) {
                hookUploaderQueue();
                clearInterval(gridCheck);
            }
        }, 100);
    }

    // Tab clicks
    $(document).on('click', '.wpmme-media-tab', function() {
        var $tab = $(this);
        if ($tab.hasClass('active')) return;

        $('.wpmme-media-tab').removeClass('active');
        $tab.addClass('active');

        var tabId = $tab.data('id');
        updateQueryAndUploader(tabId);
    });

    // Add new tab
    $(document).on('click', '.wpmme-media-tab-add', function() {
        var tabName = prompt('Enter new tab name:');
        if (tabName) {
            $.post(wpmme_media_tabs_obj.ajaxurl, {
                action: 'wpmme_add_media_tab',
                nonce: wpmme_media_tabs_obj.nonce,
                tab_name: tabName
            }, function(response) {
                if (response.success) {
                    var newTab = $('<div class="wpmme-media-tab" data-id="'+response.data.term_id+'"><span class="tab-name">'+response.data.name+'</span><span class="tab-rename dashicons dashicons-edit" title="Rename"></span></div>');
                    $('.wpmme-media-tab-add').before(newTab);
                    newTab.click();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
    });

    // Rename tab
    $(document).on('click', '.tab-rename', function(e) {
        e.stopPropagation();
        var $tab = $(this).closest('.wpmme-media-tab');
        var termId = $tab.data('id');
        var oldName = $tab.find('.tab-name').text();
        
        var newName = prompt('Enter new name for this tab:', oldName);
        if (newName && newName !== oldName) {
            $.post(wpmme_media_tabs_obj.ajaxurl, {
                action: 'wpmme_rename_media_tab',
                nonce: wpmme_media_tabs_obj.nonce,
                term_id: termId,
                new_name: newName
            }, function(response) {
                if (response.success) {
                    $tab.find('.tab-name').text(newName);
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
    });
});
