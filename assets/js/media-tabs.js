jQuery(document).ready(function($) {
    if (typeof wp === 'undefined' || !wp.media) return;

    var currentTab = 'all';

    // Intercept all XHR requests to async-upload.php to guarantee wpmme_media_tab is sent
    var originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url) {
        this._wpmme_url = url;
        originalOpen.apply(this, arguments);
    };

    var originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(data) {
        if (currentTab !== 'all' && this._wpmme_url && this._wpmme_url.indexOf('async-upload.php') !== -1) {
            if (data instanceof FormData) {
                data.append('wpmme_media_tab', currentTab);
            }
        }
        originalSend.call(this, data);
    };

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

        // Save active tab to User Meta via AJAX as backup
        $.post(wpmme_media_tabs_obj.ajaxurl, {
            action: 'wpmme_set_active_tab',
            nonce: wpmme_media_tabs_obj.nonce,
            tab_id: currentTab
        });

        // Update the Backbone collection filter to show only this tab's files
        if (wp.media && wp.media.frame && wp.media.frame.content && wp.media.frame.content.get() && wp.media.frame.content.get().collection) {
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
            
            // Override queue add to inject taxonomy BEFORE Backbone triggers add events
            var originalAdd = wp.Uploader.queue.add;
            wp.Uploader.queue.add = function(models, options) {
                var items = Array.isArray(models) ? models : [models];
                for (var i = 0; i < items.length; i++) {
                    if (currentTab !== 'all' && items[i] && typeof items[i].set === 'function') {
                        items[i].set('wpmme_media_tab', parseInt(currentTab, 10));
                    }
                }
                return originalAdd.apply(this, arguments);
            };
        }
    }
    
    // Aggressively attempt to hook the uploader queue since it may initialize late
    var queueHookInterval = setInterval(function() {
        if (typeof wp !== 'undefined' && wp.Uploader && wp.Uploader.queue) {
            hookUploaderQueue();
            clearInterval(queueHookInterval);
        }
    }, 50);

    // Modal view injection
    if (wp.media && wp.media.view && wp.media.view.MediaFrame) {
        var originalInit = wp.media.view.MediaFrame.prototype.initialize;
        wp.media.view.MediaFrame.prototype.initialize = function() {
            if (originalInit) {
                originalInit.apply(this, arguments);
            }
            
            this.on('ready', function() {
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
