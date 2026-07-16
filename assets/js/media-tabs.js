jQuery(document).ready(function($) {
    if (typeof wpmme_media_tabs_obj === 'undefined') return;

    var currentTab = wpmme_media_tabs_obj.active_tab || 'all';
    
    // Inject custom tabs into the Grid View UI
    function injectTabs() {
        if ($('.wpmme-media-tabs').length) return;

        var $tabsContainer = $('<div class="wpmme-media-tabs"></div>');
        
        var isAllActive = (currentTab === 'all') ? ' active' : '';
        var $allTab = $('<button type="button" class="wpmme-media-tab' + isAllActive + '"></button>')
            .text('All')
            .data('id', 'all')
            .data('slug', 'all');
        $tabsContainer.append($allTab);

        $.each(wpmme_media_tabs_obj.tabs, function(index, tab) {
            var isActive = (currentTab == tab.term_id) ? ' active' : '';
            var $tab = $('<button type="button" class="wpmme-media-tab' + isActive + '"></button>')
                .text(tab.name)
                .data('id', tab.term_id)
                .data('slug', tab.slug);
            $tabsContainer.append($tab);
        });

        var $addTabBtn = $('<button type="button" class="wpmme-media-tab-add" title="Add New Tab">+</button>');
        $tabsContainer.append($addTabBtn);

        // For Media Grid only
        // Target the main grid toolbar which is NOT inside a modal
        var $gridToolbar = $('.media-frame:not(.media-modal .media-frame) .media-toolbar').first();
        if ($gridToolbar.length) {
            $tabsContainer.insertBefore($gridToolbar);
        }
    }

    // Global Observer for both Grid View and Modal View
    var observer = new MutationObserver(function(mutations) {
        // 1. Grid View Injection (Tabs as buttons)
        // We only inject if there is a main media frame (not inside a popup modal)
        if ($('.media-frame:not(.media-modal .media-frame) .media-toolbar').length && !$('.wpmme-media-tabs').length) {
            injectTabs();
        }

        // 2. Modal View Injection (Dropdown next to date filter)
        // We strictly target the secondary toolbar inside a popup modal
        $('.media-modal .media-toolbar-secondary').each(function() {
            var $toolbar = $(this);
            
            if (!$toolbar.find('#wpmme-media-tab-filter').length) {
                var $select = $('<select id="wpmme-media-tab-filter" class="attachment-filters" style="max-width:150px; margin-left:10px;"></select>');
                $select.append($('<option value="all">All Tabs</option>'));
                $.each(wpmme_media_tabs_obj.tabs, function(index, tab) {
                    $select.append($('<option value="' + tab.term_id + '">' + tab.name + '</option>'));
                });
                
                $select.val(currentTab);
                
                $select.on('change', function() {
                    updateQueryAndUploader($(this).val());
                });

                // Append after the date filter / spinner
                $toolbar.append($select);
            }
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Intercept XHR to append wpmme_media_tab to all Plupload requests
    var originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(data) {
        if (currentTab !== 'all' && this._wpmme_url && this._wpmme_url.indexOf('async-upload.php') !== -1) {
            if (data instanceof FormData) {
                data.append('wpmme_media_tab', currentTab);
            }
        }
        originalSend.call(this, data);
    };

    var originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url) {
        this._wpmme_url = url;
        originalOpen.apply(this, arguments);
    };

    // Patch wp.media.model.Query to make the validator ignore our custom param
    // This is critical: without this, the Backbone validator rejects newly uploaded
    // attachments because it doesn't know how to compare 'wpmme_media_tab'
    if (typeof wp !== 'undefined' && wp.media && wp.media.model && wp.media.model.Query) {
        var _originalQueryGet = wp.media.model.Query.get;
        wp.media.model.Query.get = function(props, options) {
            // Inject our tab filter into the query props
            if (currentTab !== 'all') {
                props.wpmme_media_tab = parseInt(currentTab, 10);
            } else {
                delete props.wpmme_media_tab;
            }

            var query = _originalQueryGet.call(this, props, options);

            // Patch the validator only once per query instance
            if (!query._wpmme_validator_patched) {
                var originalValidator = query.validator;
                query.validator = function(attachment) {
                    // Temporarily remove wpmme_media_tab from args so
                    // WP's built-in validator doesn't reject the attachment
                    var savedTab = this.args.wpmme_media_tab;
                    delete this.args.wpmme_media_tab;
                    var passed = originalValidator.call(this, attachment);
                    // Restore it
                    if (savedTab !== undefined) {
                        this.args.wpmme_media_tab = savedTab;
                    }

                    // If it passed the basic WP checks, also check our tab filter
                    if (passed && savedTab !== undefined) {
                        var attTab = attachment.get('wpmme_media_tab');
                        // Allow if attachment has matching tab, or if it's still uploading (no tab yet)
                        if (attTab !== undefined && attTab !== savedTab) {
                            return false;
                        }
                    }
                    return passed;
                };
                query._wpmme_validator_patched = true;
            }
            return query;
        };
    }



    function updateQueryAndUploader(tabId) {
        currentTab = tabId;

        // Sync grid buttons if they exist
        if ($('.wpmme-media-tab').length) {
            $('.wpmme-media-tab').removeClass('active');
            $('.wpmme-media-tab[data-id="' + tabId + '"]').addClass('active');
        }

        // Sync modal and grid dropdowns if there are multiple
        if ($('#wpmme-media-tab-filter').length) {
            $('#wpmme-media-tab-filter').val(tabId);
        }

        // Save user preference
        $.post(wpmme_media_tabs_obj.ajaxurl, {
            action: 'wpmme_set_active_tab',
            tab_id: tabId,
            nonce: wpmme_media_tabs_obj.nonce
        });

        // Update wp.media frame props to trigger a re-query
        if (wp.media && wp.media.frame && wp.media.frame.content && wp.media.frame.content.get() && wp.media.frame.content.get().collection) {
            var collection = wp.media.frame.content.get().collection;
            if (currentTab !== 'all') {
                collection.props.set({ wpmme_media_tab: parseInt(currentTab, 10) });
            } else {
                collection.props.unset('wpmme_media_tab');
                // Force refresh
                collection.props.set({ _wpmme_refresh: +new Date() });
            }
        }
    }

    $(document).on('click', '.wpmme-media-tab', function() {
        var $tab = $(this);
        if ($tab.hasClass('active')) return;

        $('.wpmme-media-tab').removeClass('active');
        $tab.addClass('active');

        var tabId = $tab.data('id');
        updateQueryAndUploader(tabId);
    });

    $(document).on('click', '.wpmme-media-tab-add', function() {
        var tabName = prompt('Enter new tab name:');
        if (tabName) {
            $.ajax({
                url: wpmme_media_tabs_obj.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpmme_add_media_tab',
                    tab_name: tabName,
                    nonce: wpmme_media_tabs_obj.nonce
                },
                success: function(response) {
                    if (response.success) {
                        wpmme_media_tabs_obj.tabs.push(response.data);
                        var $newTab = $('<button type="button" class="wpmme-media-tab"></button>')
                            .text(response.data.name)
                            .data('id', response.data.term_id)
                            .data('slug', response.data.slug);
                        $newTab.insertBefore('.wpmme-media-tab-add');
                        $newTab.trigger('click');
                    } else {
                        alert('Error adding tab: ' + response.data);
                    }
                }
            });
        }
    });

    $(document).on('dblclick', '.wpmme-media-tab:not(.active[data-id="all"])', function() {
        var $tab = $(this);
        var tabId = $tab.data('id');
        if (tabId === 'all') return;

        var currentName = $tab.text();
        var newName = prompt('Rename tab:', currentName);
        
        if (newName && newName !== currentName) {
            $.ajax({
                url: wpmme_media_tabs_obj.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpmme_rename_media_tab',
                    term_id: tabId,
                    new_name: newName,
                    nonce: wpmme_media_tabs_obj.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $tab.text(newName);
                    } else {
                        alert('Error renaming tab: ' + response.data);
                    }
                }
            });
        }
    });
});
