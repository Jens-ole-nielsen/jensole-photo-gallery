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
                result.removeClass('syncing').addClass('success')
                    .text('✅ Synced ' + resp.data.albums_synced + ' albums!');
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

})(jQuery);
