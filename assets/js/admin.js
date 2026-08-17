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

    // Import album photos — batched with progress bar + auto pre-warm
    $(document).on('click', '.jopg-import-album', function() {
        var btn = $(this);
        var albumId = btn.data('album-id');
        var status = $('#jopg-prewarm-status');
        var originalText = btn.text();
        
        // Phase 1: Import metadata in batches
        var importOffset = 0;
        var importBatchSize = 100;
        var totalImported = 0;
        var totalAssets = 0;
        
        btn.prop('disabled', true).text('⏳ Importing...');
        status.html('<div style="padding:10px;background:#f0f0f0;border-radius:4px;">Importing photos...</div>');
        
        function importBatch() {
            $.post(jopg_admin.ajax_url, {
                action: 'jopg_import_album_batch',
                nonce: jopg_admin.nonce,
                album_id: albumId,
                offset: importOffset,
                batch_size: importBatchSize
            }, function(resp) {
                if (!resp.success) {
                    status.html('<div style="color:red;padding:10px;">Import error: ' + (resp.data || 'Unknown') + '</div>');
                    btn.prop('disabled', false).text(originalText);
                    return;
                }
                
                var d = resp.data;
                totalImported += d.imported;
                totalAssets = d.total_assets;
                importOffset = d.done;
                
                var pct = d.total_assets > 0 ? Math.round((d.done / d.total_assets) * 100) : 100;
                var phase1Pct = Math.round(pct * 0.5); // Phase 1 is 50% of total progress
                var bar = '<div style="background:#ddd;border-radius:4px;height:24px;overflow:hidden;">' +
                    '<div style="background:#2271b1;height:100%;width:' + phase1Pct + '%;transition:width 0.3s;">' +
                    '<span style="color:#fff;font-size:12px;line-height:24px;padding-left:8px;">Import: ' + pct + '% (' + d.done + '/' + d.total_assets + ')</span></div></div>';
                status.html(bar);
                
                if (d.remaining > 0) {
                    // Continue importing next batch
                    setTimeout(importBatch, 100);
                } else {
                    // Import done — start auto pre-warm
                    status.append('<div style="margin-top:8px;color:green;">✅ ' + totalImported + ' photos imported. Starting cache pre-warm...</div>');
                    startPrewarm();
                }
            }).fail(function() {
                status.html('<div style="color:red;padding:10px;">Connection error during import.</div>');
                btn.prop('disabled', false).text(originalText);
            });
        }
        
        // Phase 2: Auto pre-warm cache after import
        function startPrewarm() {
            var pwOffset = 0;
            var pwBatchSize = 5;
            var pwCached = 0;
            var pwFailed = 0;
            var pwTotal = totalImported;
            
            btn.text('🔥 Pre-warming...');
            
            function prewarmBatch() {
                $.post(jopg_admin.ajax_url, {
                    action: 'jopg_prewarm_cache',
                    nonce: jopg_admin.nonce,
                    offset: pwOffset,
                    batch_size: pwBatchSize,
                    album_id: albumId
                }, function(resp) {
                    if (!resp.success) {
                        status.append('<div style="color:red;padding:5px;">Pre-warm error: ' + (resp.data || 'Unknown') + '</div>');
                        btn.prop('disabled', false).text(originalText);
                        return;
                    }
                    
                    var d = resp.data;
                    pwCached += d.cached;
                    pwFailed += d.failed;
                    pwOffset = d.done;
                    
                    // Combined progress: import is 50%, pre-warm is 50%
                    var pwPct = d.total > 0 ? Math.round((d.done / d.total) * 100) : 100;
                    var overallPct = 50 + Math.round(pwPct * 0.5);
                    var bar = '<div style="background:#ddd;border-radius:4px;height:24px;overflow:hidden;">' +
                        '<div style="background:#2271b1;height:100%;width:' + overallPct + '%;transition:width 0.3s;">' +
                        '<span style="color:#fff;font-size:12px;line-height:24px;padding-left:8px;">Cache: ' + pwPct + '% (' + d.done + '/' + d.total + ')</span></div></div>';
                    var msg = '<div style="margin-top:8px;">Pre-warming: ' + d.done + ' of ' + d.total + 
                        ' — ✅ ' + pwCached + ' cached' + (pwFailed > 0 ? ', ❌ ' + pwFailed + ' failed' : '') + '</div>';
                    status.html(bar + msg);
                    
                    if (d.remaining > 0) {
                        setTimeout(prewarmBatch, 200);
                    } else {
                        btn.prop('disabled', false).text(originalText);
                        status.append('<div style="margin-top:8px;color:green;font-weight:bold;">✅ Done! ' + 
                            totalImported + ' photos imported, ' + pwCached + ' images cached. Gallery will load instantly.</div>');
                        // Update the album row photo count without full reload
                        setTimeout(function() { location.reload(); }, 3000);
                    }
                }).fail(function() {
                    status.append('<div style="color:red;padding:5px;">Connection error during pre-warm. You can manually pre-warm later.</div>');
                    btn.prop('disabled', false).text(originalText);
                });
            }
            
            prewarmBatch();
        }
        
        importBatch();
    });


    // Pre-warm image cache — BACKGROUND mode (WP cron, survives page close)
    $(document).on('click', '.jopg-prewarm-album', function() {
        var btn = $(this);
        var albumId = btn.data('album-id');
        var status = $('#jopg-prewarm-status');
        var originalText = btn.text();
        
        if (!confirm('Start background pre-warm?\n\nYou can close this page — it runs server-side every 2 minutes (10 images per run). Status is shown when you return.')) return;
        
        btn.prop('disabled', true).text('⏳ Starting...');
        
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_prewarm_background_start',
            nonce: jopg_admin.nonce,
            album_id: albumId
        }, function(resp) {
            if (!resp.success) {
                status.html('<div style="color:red;padding:10px;">Error: ' + (resp.data || 'Unknown') + '</div>');
                btn.prop('disabled', false).text(originalText);
                return;
            }
            
            btn.text('⏹ Stop Pre-warm').prop('disabled', false);
            btn.addClass('jopg-prewarm-stop').removeClass('jopg-prewarm-album');
            
            // Start polling for status
            startPrewarmPolling(albumId, btn, originalText);
        }).fail(function() {
            status.html('<div style="color:red;padding:10px;">Connection error.</div>');
            btn.prop('disabled', false).text(originalText);
        });
    });
    
    // Stop background pre-warm
    $(document).on('click', '.jopg-prewarm-stop', function() {
        var btn = $(this);
        var status = $('#jopg-prewarm-status');
        var originalText = '🔥 Pre-warm';
        
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_prewarm_background_stop',
            nonce: jopg_admin.nonce
        }, function(resp) {
            btn.removeClass('jopg-prewarm-stop').addClass('jopg-prewarm-album');
            btn.text(originalText);
            status.append('<div style="margin-top:8px;color:#666;">⏹ Pre-warm stopped.</div>');
        }).fail(function() {
            alert('Connection error');
        });
    });
    
    // Poll background pre-warm status every 5 seconds
    var prewarmPollTimer = null;
    function startPrewarmPolling(albumId, btn, originalText) {
        var status = $('#jopg-prewarm-status');
        
        function poll() {
            $.post(jopg_admin.ajax_url, {
                action: 'jopg_prewarm_background_status',
                nonce: jopg_admin.nonce
            }, function(resp) {
                if (!resp.success) return;
                
                var d = resp.data;
                if (d.status === 'idle') return;
                
                var pct = d.total > 0 ? Math.round((d.done / d.total) * 100) : 0;
                var bar = '<div style="background:#ddd;border-radius:4px;height:24px;overflow:hidden;">' +
                    '<div style="background:#2271b1;height:100%;width:' + pct + '%;transition:width 0.3s;">' +
                    '<span style="color:#fff;font-size:12px;line-height:24px;padding-left:8px;">' + pct + '%</span></div></div>';
                var msg = '<div style="margin-top:8px;">Background pre-warm: ' + d.done + ' of ' + d.total + 
                    ' — ✅ ' + (d.cached || 0) + ' cached' + ((d.failed || 0) > 0 ? ', ❌ ' + d.failed + ' failed' : '') + 
                    ' <span style="color:#666;font-size:11px;">(runs every 2 min, you can close this page)</span></div>';
                status.html(bar + msg);
                
                if (d.status === 'completed' || d.status === 'stopped') {
                    clearInterval(prewarmPollTimer);
                    prewarmPollTimer = null;
                    btn.removeClass('jopg-prewarm-stop').addClass('jopg-prewarm-album');
                    btn.text(originalText);
                    if (d.status === 'completed') {
                        status.append('<div style="margin-top:8px;color:green;font-weight:bold;">✅ Pre-warm complete! ' + 
                            (d.cached || 0) + ' images cached. Gallery will load instantly.</div>');
                    }
                }
            }).fail(function() {
                // Silent fail on polling — don't spam errors
            });
        }
        
        poll(); // Immediate first poll
        prewarmPollTimer = setInterval(poll, 5000);
    }
    
    // On page load: check if a background pre-warm is already running
    $(document).ready(function() {
        var status = $('#jopg-prewarm-status');
        if (!status.length) return;
        
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_prewarm_background_status',
            nonce: jopg_admin.nonce
        }, function(resp) {
            if (!resp.success) return;
            var d = resp.data;
            if (d.status === 'idle' || d.status === undefined) return;
            
            // A background job is running or completed — show status
            var pct = d.total > 0 ? Math.round((d.done / d.total) * 100) : 0;
            var stateLabel = d.status === 'running' ? '🔄 Running' : (d.status === 'completed' ? '✅ Completed' : '⏹ Stopped');
            var bar = '<div style="background:#ddd;border-radius:4px;height:24px;overflow:hidden;">' +
                '<div style="background:#2271b1;height:100%;width:' + pct + '%;transition:width 0.3s;">' +
                '<span style="color:#fff;font-size:12px;line-height:24px;padding-left:8px;">' + pct + '%</span></div></div>';
            var msg = '<div style="margin-top:8px;">' + stateLabel + ' — Background pre-warm: ' + d.done + ' of ' + d.total + 
                ' — ✅ ' + (d.cached || 0) + ' cached' + ((d.failed || 0) > 0 ? ', ❌ ' + d.failed + ' failed' : '') + '</div>';
            status.html(bar + msg);
            
            if (d.status === 'running') {
                // Update the pre-warm button to show stop state
                $('.jopg-prewarm-album').each(function() {
                    if (parseInt($(this).data('album-id')) === parseInt(d.album_id)) {
                        $(this).text('⏹ Stop Pre-warm');
                        $(this).addClass('jopg-prewarm-stop').removeClass('jopg-prewarm-album');
                    }
                });
                // Start polling
                startPrewarmPolling(d.album_id, $('.jopg-prewarm-stop'), '🔥 Pre-warm');
            }
        }).fail(function() {});
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

    // Assign album to gallery — instant save on dropdown change
    $(document).on('change', '.jopg-album-gallery-select', function() {
        var select = $(this);
        var albumId = select.data('album-id');
        var galleryId = select.val();
        var originalColor = select.css('background');
        
        select.css('background', '#fff8e1');
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_assign_gallery',
            nonce: jopg_admin.nonce,
            album_id: albumId,
            gallery_id: galleryId
        }, function(resp) {
            if (resp.success) {
                select.css('background', '#d4edda');
                setTimeout(function() { select.css('background', originalColor); }, 1000);
            } else {
                alert('Failed: ' + (resp.data || 'Unknown error'));
                select.css('background', originalColor);
            }
        }).fail(function() {
            alert('Connection error');
            select.css('background', originalColor);
        });
    });

    // Save album sync filter (flag requirement + min star rating) — instant save
    $(document).on('change', '.jopg-album-flag-filter, .jopg-album-rating-filter', function() {
        var select = $(this);
        var row = select.closest('tr');
        var albumId = select.data('album-id');
        var flagFilter = row.find('.jopg-album-flag-filter').val();
        var minRating = row.find('.jopg-album-rating-filter').val();
        var originalColor = select.css('background');
        
        select.css('background', '#fff8e1');
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_set_album_filter',
            nonce: jopg_admin.nonce,
            album_id: albumId,
            filter_flag: flagFilter,
            min_rating: minRating
        }, function(resp) {
            if (resp.success) {
                select.css('background', '#d4edda');
                // Reload so the "🧹 Ryd ikke-matchende" button appears/disappears correctly
                setTimeout(function() { location.reload(); }, 600);
            } else {
                alert('Failed: ' + (resp.data || 'Unknown error'));
                select.css('background', originalColor);
            }
        }).fail(function() {
            alert('Connection error');
            select.css('background', originalColor);
        });
    });

    // Clean up already-imported photos that no longer match the album's sync filter
    $(document).on('click', '.jopg-cleanup-filtered', function() {
        var btn = $(this);
        var albumId = btn.data('album-id');
        var originalText = btn.text();
        
        if (!confirm('Delete already-imported photos in this album that don\'t match its current sync filter?\n\nThis removes their WooCommerce products and cached images too. Cannot be undone.')) return;
        
        btn.prop('disabled', true).text('Cleaning...');
        $.post(jopg_admin.ajax_url, {
            action: 'jopg_cleanup_filtered_photos',
            nonce: jopg_admin.nonce,
            album_id: albumId
        }, function(resp) {
            if (resp.success) {
                alert('Removed ' + resp.data.deleted + ' photos that didn\'t match. ' + resp.data.remaining + ' remain.');
                location.reload();
            } else {
                alert('Failed: ' + (resp.data || 'Unknown error'));
                btn.prop('disabled', false).text(originalText);
            }
        }).fail(function() {
            alert('Connection error');
            btn.prop('disabled', false).text(originalText);
        });
    });
})(jQuery);
