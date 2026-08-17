/* Jens Ole Photo Gallery — Admin JS */
(function($) {
    'use strict';

    // Sync albums
    $('#jopg-sync-now').on('click', function() {
        var btn = $(this);
        var result = $('#jopg-sync-result');
        btn.prop('disabled', true).text('🔄 Syncing...');
        result.removeClass('success error').addClass('syncing').text('Syncing, please wait...');

        $.post(jopg_admin.ajax_url, {
            action: 'jopg_sync_albums',
            nonce: jopg_admin.nonce
        }, function(resp) {
            btn.prop('disabled', false).text('🔄 Sync Albums from Lightroom');
            if (resp.success) {
                var d = resp.data;
                var msg = '✅ Synced ' + (d.synced || 0) + ' albums';
                if (d.skipped > 0) {
                    msg += ' (skipped ' + d.skipped + ' empty)';
                }
                msg += '!';
                result.removeClass('syncing').addClass('success').text(msg);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                result.removeClass('syncing').addClass('error')
                    .text('❌ ' + (resp.data || 'Sync failed'));
            }
        }).fail(function() {
            btn.prop('disabled', false).text('🔄 Sync Albums from Lightroom');
            result.removeClass('syncing').addClass('error').text('❌ Connection error');
        });
    });

    // Import album photos
    $(document).on('click', '.jopg-import-album', function() {
        var btn = $(this);
        var albumId = btn.data('album-id');
        btn.prop('disabled', true).text('⏳ Importing...');

        $.post(jopg_admin.ajax_url, {
            action: 'jopg_import_album',
            nonce: jopg_admin.nonce,
            album_id: albumId
        }, function(resp) {
            if (resp.success) {
                btn.text('✅ ' + resp.data.photos_imported + ' photos imported!');
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                btn.prop('disabled', false).text('📥 Import Photos');
                alert('Import failed: ' + (resp.data || 'Unknown error'));
            }
        }).fail(function() {
            btn.prop('disabled', false).text('📥 Import Photos');
            alert('Connection error during import');
        });
    });


    // Pre-warm image cache — per album
    $(document).on('click', '.jopg-prewarm-album', function() {
        var btn = $(this);
        var albumId = btn.data('album-id');
        var status = $('#jopg-prewarm-status');
        var originalText = btn.text();
        btn.prop('disabled', true).text('🔥 Warming...');
        status.html('<div style="padding:10px;background:#f0f0f0;border-radius:4px;">Pre-warming album #' + albumId + '...</div>');
        
        var offset = 0;
        var batchSize = 5;
        var totalCached = 0;
        var totalFailed = 0;
        
        function prewarmBatch() {
            $.post(jopg_admin.ajax_url, {
                action: 'jopg_prewarm_cache',
                nonce: jopg_admin.nonce,
                offset: offset,
                batch_size: batchSize,
                album_id: albumId
            }, function(resp) {
                if (!resp.success) {
                    status.html('<div style="color:red;padding:10px;">Error: ' + (resp.data || 'Unknown') + '</div>');
                    btn.prop('disabled', false).text(originalText);
                    return;
                }
                
                var d = resp.data;
                totalCached += d.cached;
                totalFailed += d.failed;
                offset = d.done;
                
                var pct = d.total > 0 ? Math.round((d.done / d.total) * 100) : 100;
                var bar = '<div style="background:#ddd;border-radius:4px;height:24px;overflow:hidden;">' +
                    '<div style="background:#2271b1;height:100%;width:' + pct + '%;transition:width 0.3s;">' +
                    '<span style="color:#fff;font-size:12px;line-height:24px;padding-left:8px;">' + pct + '%</span></div></div>';
                var msg = '<div style="margin-top:8px;">Album #' + albumId + ': ' + d.done + ' of ' + d.total + 
                    ' — ✅ ' + totalCached + ' cached' + (totalFailed > 0 ? ', ❌ ' + totalFailed + ' failed' : '') + '</div>';
                status.html(bar + msg);
                
                if (d.remaining > 0) {
                    setTimeout(prewarmBatch, 200);
                } else {
                    btn.prop('disabled', false).text(originalText);
                    status.append('<div style="margin-top:8px;color:green;font-weight:bold;">✅ Album pre-warmed! ' + 
                        totalCached + ' images cached. Gallery will load instantly for this album.</div>');
                }
            }).fail(function() {
                status.html('<div style="color:red;padding:10px;">Connection error. Try again.</div>');
                btn.prop('disabled', false).text(originalText);
            });
        }
        
        prewarmBatch();
    });

    // Album search filter
    $('#jopg-album-search').on('input', function() {
        var term = $(this).val().toLowerCase().trim();
        var visible = 0;
        $('#jopg-album-table tbody tr').each(function() {
            var name = $(this).data('album-name') || '';
            var match = name.indexOf(term) !== -1;
            $(this).toggle(match);
            if (match) visible++;
        });
        $('#jopg-no-results').toggle(visible === 0);
    });

    // Hide (remove) album — stops sync and gallery display, clears cache
    $(document).on('click', '.jopg-hide-album', function() {
        var btn = $(this);
        var albumId = btn.data('album-id');
        var row = btn.closest('tr');
        var albumName = row.find('strong').first().text();
        
        if (!confirm('Remove album "' + albumName + '"?\n\nThis will:\n• Hide it from the gallery\n• Stop it from being synced\n• Delete cached images\n\nYou can restore it later.')) return;
        
        btn.prop('disabled', true).text('Removing...');
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_hide_album',
            nonce: jopg_admin.nonce,
            album_id: albumId
        }, function(resp) {
            if (resp.success) {
                // Update the row in place — show Restore button, dim it
                row.addClass('jopg-album-hidden');
                row.find('td').first().find('span').remove();
                row.find('td').first().append('<span style="color:#999;font-style:italic;font-size:12px;">(hidden)</span>');
                row.find('td').last().html(
                    '<button class="button button-small jopg-restore-album" data-album-id="' + albumId + '" style="color:#00a32a;">↺ Restore</button>'
                );
                // Move row to bottom of table
                row.detach().appendTo('#jopg-album-table tbody');
                var status = $('#jopg-prewarm-status');
                status.html('<div style="color:#666;padding:8px;">✓ Album "' + albumName + '" hidden. Cache cleared (' + (resp.data.deleted_cache_files || 0) + ' files deleted).</div>');
            } else {
                alert('Failed: ' + (resp.data || 'Unknown error'));
                btn.prop('disabled', false).text('✕ Remove');
            }
        }).fail(function() {
            alert('Connection error');
            btn.prop('disabled', false).text('✕ Remove');
        });
    });
    
    // Restore hidden album
    $(document).on('click', '.jopg-restore-album', function() {
        var btn = $(this);
        var albumId = btn.data('album-id');
        var row = btn.closest('tr');
        var albumName = row.find('strong').first().text();
        
        btn.prop('disabled', true).text('Restoring...');
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_restore_album',
            nonce: jopg_admin.nonce,
            album_id: albumId
        }, function(resp) {
            if (resp.success) {
                // Reload page to re-sort the album into the active list
                location.reload();
            } else {
                alert('Failed: ' + (resp.data || 'Unknown error'));
                btn.prop('disabled', false).text('↺ Restore');
            }
        }).fail(function() {
            alert('Connection error');
            btn.prop('disabled', false).text('↺ Restore');
        });
    });
})(jQuery);
