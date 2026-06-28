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

    // Intercept XHR to guarantee wpmme_media_tab is sent on all Plupload uploads to async-upload.php
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

    // Override Attachment creation to guarantee the local model has the taxonomy attribute immediately (fixes placeholder issue)
    if (typeof wp !== 'undefined' && wp.media && wp.media.model && wp.media.model.Attachment) {
        var originalCreate = wp.media.model.Attachment.create;
        wp.media.model.Attachment.create = function(attrs) {
            if (currentTab !== 'all') {
                attrs.wpmme_media_tab = parseInt(currentTab, 10);
            }
            return originalCreate.apply(this, arguments);
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
