jQuery(document).ready(function($) {
    if (typeof wpmme_media_tabs_obj === 'undefined') return;

    var currentTab = wpmme_media_tabs_obj.active_tab || 'all';
    
    // Global Observer for both Grid View and Modal View to inject Dropdown
    var observer = new MutationObserver(function(mutations) {
        var $secondaryToolbar = $('.media-toolbar-secondary');
        
        $secondaryToolbar.each(function() {
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

                $toolbar.append($select);

                // Add Manage buttons ONLY in upload.php Grid View
                if ($('body').hasClass('upload-php') && $('.wrap').length) {
                    var $addBtn = $('<button type="button" class="button" style="margin-left:5px;" title="Add New Tab">+</button>');
                    $addBtn.on('click', function() {
                        var tabName = prompt('Enter new tab name:');
                        if (tabName) {
                            $.ajax({
                                url: wpmme_media_tabs_obj.ajaxurl,
                                type: 'POST',
                                data: { action: 'wpmme_add_media_tab', tab_name: tabName, nonce: wpmme_media_tabs_obj.nonce },
                                success: function(response) {
                                    if (response.success) {
                                        wpmme_media_tabs_obj.tabs.push(response.data);
                                        // Update all dropdowns
                                        $('select#wpmme-media-tab-filter').append($('<option value="' + response.data.term_id + '">' + response.data.name + '</option>'));
                                        $('select#wpmme-media-tab-filter').val(response.data.term_id).trigger('change');
                                    } else {
                                        alert('Error adding tab: ' + response.data);
                                    }
                                }
                            });
                        }
                    });

                    var $renameBtn = $('<button type="button" class="button" style="margin-left:5px;" title="Rename Current Tab">✏️</button>');
                    $renameBtn.on('click', function() {
                        if (currentTab === 'all') return alert('Cannot rename All Tabs');
                        var currentName = $('select#wpmme-media-tab-filter option:selected').text();
                        var newName = prompt('Rename tab:', currentName);
                        if (newName && newName !== currentName) {
                            $.ajax({
                                url: wpmme_media_tabs_obj.ajaxurl,
                                type: 'POST',
                                data: { action: 'wpmme_rename_media_tab', term_id: currentTab, new_name: newName, nonce: wpmme_media_tabs_obj.nonce },
                                success: function(response) {
                                    if (response.success) {
                                        // Update the name in all dropdowns
                                        $('select#wpmme-media-tab-filter option[value="' + currentTab + '"]').text(newName);
                                    } else {
                                        alert('Error renaming tab: ' + response.data);
                                    }
                                }
                            });
                        }
                    });

                    $toolbar.append($addBtn).append($renameBtn);
                }
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
});
