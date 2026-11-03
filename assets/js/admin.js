jQuery(document).ready(function($) {
    
    // Toggle expandable bodies
    $('.wpmme-switch input').on('change', function() {
        var id = $(this).attr('id');
        if (id && id.startsWith('toggle-')) {
            var bodyId = '#body-' + id.replace('toggle-', '');
            if ($(this).is(':checked')) {
                $(bodyId).slideDown(200);
            } else {
                $(bodyId).slideUp(200);
            }
        }
    });

    // Update range sliders
    $('.wpmme-range').on('input', function() {
        $(this).siblings('.wpmme-range-val').text($(this).val());
    });

    // Save Settings
    $('#wpmme-save').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.text('Saving...').prop('disabled', true);

        var data = $('#wpmme-settings-form').serialize() + '&action=wpmme_save_settings&nonce=' + wpmme_ajax.nonce;

        $.post(wpmme_ajax.url, data, function(response) {
            $btn.text('Save Settings').prop('disabled', false);
            if (response.success) {
                alert('Settings saved successfully!');
            } else {
                alert('Error: ' + response.data);
            }
        });
    });

    // Reset Settings
    $('#wpmme-reset').on('click', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to reset all settings to defaults?')) {
            var $btn = $(this);
            $btn.text('Resetting...').prop('disabled', true);

            $.post(wpmme_ajax.url, {
                action: 'wpmme_reset_settings',
                nonce: wpmme_ajax.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    $btn.text('Reset').prop('disabled', false);
                    alert('Error: ' + response.data);
                }
            });
        }
    });

    // WordPress Media Uploader
    function setupMediaUploader(btnId, inputId) {
        var file_frame;
        $(btnId).on('click', function(e) {
            e.preventDefault();
            if (file_frame) {
                file_frame.open();
                return;
            }
            file_frame = wp.media.frames.file_frame = wp.media({
                title: 'Select Image',
                button: { text: 'Use this image' },
                multiple: false
            });
            file_frame.on('select', function() {
                var attachment = file_frame.state().get('selection').first().toJSON();
                $(inputId).val(attachment.url);
            });
            file_frame.open();
        });
    }

    setupMediaUploader('#btn-upload-watermark', '#watermark_img');
    setupMediaUploader('#btn-upload-logo', '#login_logo_url');

    // ==============================
    // Backup Feature
    // ==============================

    // Manual Backup
    $('#btn-manual-backup').on('click', function() {
        var $btn = $(this);
        var $status = $('#backup-status');
        if (!confirm('Create a full backup now? This may take a few minutes.')) {
            return;
        }
        $btn.prop('disabled', true).text('⏳ Backing up...');
        $status.text('');

        $.post(wpmme_ajax.url, {
            action: 'wpmme_manual_backup',
            nonce: wpmme_ajax.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('📦 Backup Now');
            if (response.success) {
                $status.css('color', '#46b450').text('✅ ' + response.data.message);
                renderBackupTable(response.data.backups);
            } else {
                $status.css('color', '#d63638').text('❌ ' + response.data);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('📦 Backup Now');
            $status.css('color', '#d63638').text('❌ Server error.');
        });
    });

    // Delete Backup
    $(document).on('click', '.wpmme-delete-backup', function() {
        var filename = $(this).data('filename');
        if (!confirm('Delete backup "' + filename + '"?')) {
            return;
        }

        $.post(wpmme_ajax.url, {
            action: 'wpmme_delete_backup',
            nonce: wpmme_ajax.nonce,
            filename: filename
        }, function(response) {
            if (response.success) {
                renderBackupTable(response.data.backups);
            } else {
                alert('Error: ' + response.data);
            }
        });
    });

    // Re-render backup table from AJAX data
    function renderBackupTable(backups) {
        var $wrap = $('#wpmme-backup-list-wrap');
        var $table = $wrap.find('#wpmme-backup-table');
        var $noMsg = $wrap.find('#no-backup-msg');

        if (!backups || backups.length === 0) {
            $table.remove();
            if ($noMsg.length === 0) {
                $wrap.append('<p class="description" id="no-backup-msg">No backups yet.</p>');
            }
            return;
        }

        $noMsg.remove();

        // Build or update table
        var nonce = wpmme_ajax.nonce;
        var html = '<table class="widefat striped" id="wpmme-backup-table" style="margin-top:8px;">' +
            '<thead><tr><th>Date</th><th>Filename</th><th>Size</th><th style="width:140px;">Actions</th></tr></thead><tbody>';

        $.each(backups, function(i, b) {
            var dlUrl = wpmme_ajax.url + '?action=wpmme_download_backup&nonce=' + nonce + '&filename=' + encodeURIComponent(b.filename);
            html += '<tr data-filename="' + b.filename + '">' +
                '<td>' + b.date + '</td>' +
                '<td><code>' + b.filename + '</code></td>' +
                '<td>' + b.size_human + '</td>' +
                '<td>' +
                '<a href="' + dlUrl + '" class="button button-small">Download</a> ' +
                '<button type="button" class="button button-small button-link-delete wpmme-delete-backup" data-filename="' + b.filename + '">Delete</button>' +
                '</td></tr>';
        });

        html += '</tbody></table>';

        if ($table.length) {
            $table.replaceWith(html);
        } else {
            $wrap.append(html);
        }
    }

    // Bulk Operations Modal
    var currentAction = '';
    
    $('#btn-rename-all').on('click', function() {
        showModal('Rename All Images', 'This will rename all existing attachments in your media library based on your pattern. This process might take a while.', 'wpmme_rename_all');
    });

    $('#btn-webp-all').on('click', function() {
        showModal('Convert All to WEBP', 'This will convert all existing JPEG and PNG images to WEBP. Original images will be kept.', 'wpmme_webp_all');
    });

    $('#btn-watermark-all').on('click', function() {
        showModal('Apply Watermark', 'This will apply the watermark to all existing images. Make sure you have saved your watermark settings first.', 'wpmme_watermark_all');
    });

    $('#btn-force-update').on('click', function() {
        if (confirm('Are you sure you want to download and overwrite the plugin with the latest version from GitHub?')) {
            var $btn = $(this);
            var originalText = $btn.text();
            $btn.text('Downloading & Updating... Please wait').prop('disabled', true);

            $.post(wpmme_ajax.url, {
                action: 'wpmme_force_update',
                nonce: wpmme_ajax.nonce
            }, function(response) {
                if (response.success) {
                    $btn.text('Update successful! Reloading...');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    alert('Update failed: ' + response.data);
                    $btn.text(originalText).prop('disabled', false);
                }
            }).fail(function() {
                alert('Server error during update.');
                $btn.text(originalText).prop('disabled', false);
            });
        }
    });

    function showModal(title, desc, action) {
        currentAction = action;
        $('#wpmme-modal-title').text(title);
        $('#wpmme-modal-desc').text(desc);
        $('#wpmme-modal-progress').css('width', '0%');
        $('#wpmme-modal-text').text('0%');
        $('#wpmme-modal').fadeIn();
    }

    $('#wpmme-modal-close').on('click', function() {
        $('#wpmme-modal').fadeOut();
    });

    $('#wpmme-modal-start').on('click', function() {
        var $btn = $(this);
        var $closeBtn = $('#wpmme-modal-close');
        $btn.prop('disabled', true);
        $closeBtn.prop('disabled', true);
        
        // Bulk Operations AJAX Mock (Real implementation below)
        // Mock for others
        var progress = 0;
        var interval = setInterval(function() {
            progress += 10;
            $('#wpmme-modal-progress').css('width', progress + '%');
            $('#wpmme-modal-text').text(progress + '%');
            
            if (progress >= 100) {
                clearInterval(interval);
                $btn.prop('disabled', false);
                $closeBtn.prop('disabled', false);
                $('#wpmme-modal-desc').text('Process completed!');
            }
        }, 500);
    });
});
