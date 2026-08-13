/* Jens Ole Photo Gallery — Frontend JS */
(function($) {
    'use strict';

    // Album card click — navigate to album
    $(document).on('click', '.jopg-album-card', function() {
        var albumId = $(this).data('album-id');
        var url = new URL(window.location.href);
        url.searchParams.set('album', albumId);
        window.location.href = url.toString();
    });

    // Add to cart
    $(document).on('click', '.jopg-add-cart', function(e) {
        e.stopPropagation();
        var btn = $(this);
        var photoId = btn.data('photo-id');
        
        $.post(jopg.ajax_url, {
            action: 'jopg_add_to_cart',
            nonce: jopg.cart_nonce,
            photo_id: photoId
        }, function(resp) {
            if (resp.success) {
                btn.addClass('jopg-added').text('✓ Added!');
                showCartNotice(resp.data.cart_count, resp.data.cart_total);
                setTimeout(function() {
                    btn.removeClass('jopg-added').text('🛒 Add to cart');
                }, 2000);
            } else {
                alert('Could not add to cart: ' + (resp.data || 'Unknown error'));
            }
        });
    });

    // Lightbox
    var currentPhotoId = 0;
    var currentPhotoUrl = '';
    var photoUrls = [];
    
    function collectPhotoUrls() {
        photoUrls = [];
        $('.jopg-photo').each(function() {
            var id = $(this).data('photo-id');
            var url = $(this).find('img').data('full-url') || $(this).find('img').attr('src');
            photoUrls.push({ id: id, url: url });
        });
    }

    $(document).on('click', '.jopg-photo-thumb img', function(e) {
        e.stopPropagation();
        collectPhotoUrls();
        var photo = $(this).closest('.jopg-photo');
        currentPhotoId = photo.data('photo-id');
        currentPhotoUrl = $(this).data('full-url') || $(this).attr('src');
        
        $('#jopg-lightbox-img').attr('src', currentPhotoUrl);
        $('.jopg-lb-cart').data('photo-id', currentPhotoId);
        $('#jopg-lightbox').fadeIn(200);
    });

    $('.jopg-lb-close').on('click', function() {
        $('#jopg-lightbox').fadeOut(200);
    });

    $('.jopg-lb-prev').on('click', function() {
        navigateLightbox(-1);
    });

    $('.jopg-lb-next').on('click', function() {
        navigateLightbox(1);
    });

    $('.jopg-lb-cart').on('click', function(e) {
        e.stopPropagation();
        var photoId = $(this).data('photo-id');
        $('.jopg-add-cart[data-photo-id="' + photoId + '"]').click();
    });

    function navigateLightbox(dir) {
        var idx = photoUrls.findIndex(function(p) { return p.id === currentPhotoId; });
        if (idx === -1) return;
        idx = (idx + dir + photoUrls.length) % photoUrls.length;
        currentPhotoId = photoUrls[idx].id;
        currentPhotoUrl = photoUrls[idx].url;
        $('#jopg-lightbox-img').attr('src', currentPhotoUrl);
        $('.jopg-lb-cart').data('photo-id', currentPhotoId);
    }

    $(document).on('keydown', function(e) {
        if ($('#jopg-lightbox').is(':visible')) {
            if (e.key === 'Escape') $('.jopg-lb-close').click();
            if (e.key === 'ArrowLeft') $('.jopg-lb-prev').click();
            if (e.key === 'ArrowRight') $('.jopg-lb-next').click();
        }
    });

    $('.jopg-lightbox-bg').on('click', function() {
        $('#jopg-lightbox').fadeOut(200);
    });

    // Cart notice
    function showCartNotice(count, total) {
        $('.jopg-cart-notice').remove();
        var notice = $('<div class="jopg-cart-notice">🛒 ' + count + ' item(s) in cart — ' + total + ' <br><a href="' + jopg.cart_url + '" style="color:#fff;text-decoration:underline">View cart</a></div>');
        $('body').append(notice);
        setTimeout(function() { notice.fadeOut(300, function() { $(this).remove(); }); }, 4000);
    }

    // Client selection page
    if ($('#jopg-selection-app').length) {
        initClientSelection();
    }

    function initClientSelection() {
        var container = $('#jopg-selection-app');
        var token = container.data('token') || new URLSearchParams(window.location.search).get('token');
        
        if (!token) {
            container.html('<p>Invalid selection link.</p>');
            return;
        }

        // Fetch selection data
        $.get(jopg.rest_url + 'selection/' + token, function(data) {
            if (data.error) {
                container.html('<p>' + data.error + '</p>');
                return;
            }

            var selected = data.selected || [];
            var html = '<h2>Select Your Favorites</h2>';
            html += '<p class="jopg-album-meta">Click photos to select. When done, click "Submit Selection".</p>';
            html += '<div class="jopg-selection-grid">';
            
            data.watermarked_urls.forEach(function(photo) {
                var isSelected = selected.includes(photo.id);
                html += '<div class="jopg-selection-photo' + (isSelected ? ' selected' : '') + '" data-photo-id="' + photo.id + '">';
                html += '<img src="' + photo.url + '" loading="lazy">';
                html += '<div class="jopg-select-check">' + (isSelected ? '✓' : '') + '</div>';
                html += '</div>';
            });
            
            html += '</div>';
            html += '<div class="jopg-selection-actions">';
            html += '<div class="jopg-selection-count"><span id="jopg-sel-count">' + selected.length + '</span> photos selected</div>';
            html += '<button id="jopg-submit-selection">Submit Selection</button>';
            html += '</div>';
            
            container.html(html);
        }).fail(function() {
            container.html('<p>Could not load selection. The link may have expired.</p>');
        });
    }

    // Toggle selection
    $(document).on('click', '.jopg-selection-photo', function() {
        $(this).toggleClass('selected');
        var check = $(this).find('.jopg-select-check');
        if ($(this).hasClass('selected')) {
            check.html('✓');
        } else {
            check.html('');
        }
        $('#jopg-sel-count').text($('.jopg-selection-photo.selected').length);
    });

    // Submit selection
    $(document).on('click', '#jopg-submit-selection', function() {
        var token = $('#jopg-selection-app').data('token') || new URLSearchParams(window.location.search).get('token');
        var photoIds = $('.jopg-selection-photo.selected').map(function() {
            return $(this).data('photo-id');
        }).get();

        $.post(jopg.ajax_url, {
            action: 'jopg_save_selection',
            token: token,
            photo_ids: photoIds
        }, function(resp) {
            if (resp.success) {
                $('.jopg-selection-actions').html('<p style="color:#fff;font-size:18px;">✅ Thank you! Your selection has been submitted. Jens Ole will get in touch soon.</p>');
            } else {
                alert('Could not save selection. Please try again.');
            }
        });
    });

})(jQuery);
