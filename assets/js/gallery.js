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

    // Open lightbox when clicking anywhere on the photo card (except the cart button)
    $(document).on('click', '.jopg-photo', function(e) {
        // Don't open lightbox if clicking the cart button
        if ($(e.target).hasClass('jopg-add-cart') || $(e.target).closest('.jopg-add-cart').length) return;
        e.preventDefault();
        e.stopPropagation();
        collectPhotoUrls();
        var photo = $(this);
        currentPhotoId = photo.data('photo-id');
        currentPhotoUrl = photo.find('img').data('full-url') || photo.find('img').attr('src');
        
        // Show loading state
        $('#jopg-lightbox-img').attr('src', '').css('opacity', '0.3');
        $('.jopg-lb-cart').data('photo-id', currentPhotoId);
        $('#jopg-lightbox').fadeIn(200);
        
        // Load the full-size watermarked image
        var img = new Image();
        img.onload = function() {
            $('#jopg-lightbox-img').attr('src', currentPhotoUrl).css('opacity', '1');
        };
        img.onerror = function() {
            // Fallback to thumbnail if full-size fails
            $('#jopg-lightbox-img').attr('src', photo.find('img').attr('src')).css('opacity', '1');
        };
        img.src = currentPhotoUrl;
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
    
    // Click image in lightbox to toggle zoom
    $('#jopg-lightbox-img').on('click', function(e) {
        e.stopPropagation();
        $(this).toggleClass('jopg-zoomed');
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

    // Selection lightbox state
    var selLightboxPhotos = [];
    var selLightboxIdx = 0;

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
            selLightboxPhotos = data.watermarked_urls;
            
            var html = '<h2>Select Your Favorites</h2>';
            html += '<p class="jopg-album-meta">Click a photo to view it larger. Click the checkmark to select. When done, click "Submit Selection".</p>';
            html += '<div class="jopg-selection-grid">';
            
            data.watermarked_urls.forEach(function(photo, idx) {
                var isSelected = selected.includes(photo.id);
                var thumbSrc = photo.thumb_url || photo.url;
                var fullSrc = photo.full_url || photo.url;
                html += '<div class="jopg-selection-photo' + (isSelected ? ' selected' : '') + '" data-photo-id="' + photo.id + '" data-idx="' + idx + '">';
                html += '<img src="' + thumbSrc + '" loading="lazy" data-full-url="' + fullSrc + '">';
                html += '<div class="jopg-select-check">' + (isSelected ? '✓' : '') + '</div>';
                html += '<div class="jopg-zoom-hint">🔍</div>';
                html += '</div>';
            });
            
            html += '</div>';
            html += '<div class="jopg-selection-actions">';
            html += '<div class="jopg-selection-count"><span id="jopg-sel-count">' + selected.length + '</span> photos selected</div>';
            html += '<button id="jopg-submit-selection">Submit Selection</button>';
            html += '</div>';
            
            // Lightbox HTML
            html += '<div class="jopg-sel-lightbox" id="jopg-sel-lightbox" style="display:none;">';
            html += '<div class="jopg-sel-lb-bg"></div>';
            html += '<div class="jopg-sel-lb-content">';
            html += '<img src="" id="jopg-sel-lb-img" alt="">';
            html += '</div>';
            html += '<div class="jopg-sel-lb-controls">';
            html += '<button class="jopg-sel-lb-prev">←</button>';
            html += '<button class="jopg-sel-lb-close">✕</button>';
            html += '<button class="jopg-sel-lb-next">→</button>';
            html += '<button class="jopg-sel-lb-toggle" id="jopg-sel-lb-toggle">✓ Select</button>';
            html += '</div>';
            html += '</div>';
            
            container.html(html);
        }).fail(function() {
            container.html('<p>Could not load selection. The link may have expired.</p>');
        });
    }

    // Click selection photo — open lightbox (not on checkmark)
    $(document).on('click', '.jopg-selection-photo', function(e) {
        // Don't open lightbox if clicking the checkmark
        if ($(e.target).hasClass('jopg-select-check') || $(e.target).closest('.jopg-select-check').length) return;
        
        var idx = parseInt($(this).data('idx'));
        selLightboxIdx = idx;
        openSelLightbox();
    });

    // Click checkmark — toggle selection (don't open lightbox)
    $(document).on('click', '.jopg-select-check', function(e) {
        e.stopPropagation();
        var photo = $(this).closest('.jopg-selection-photo');
        photo.toggleClass('selected');
        var check = photo.find('.jopg-select-check');
        if (photo.hasClass('selected')) {
            check.html('✓');
        } else {
            check.html('');
        }
        $('#jopg-sel-count').text($('.jopg-selection-photo.selected').length);
        // Update lightbox toggle button if open
        updateSelLightboxToggle();
    });

    function openSelLightbox() {
        var photo = selLightboxPhotos[selLightboxIdx];
        if (!photo) return;
        var url = photo.full_url || photo.url;
        $('#jopg-sel-lb-img').attr('src', url);
        $('#jopg-sel-lightbox').fadeIn(200);
        updateSelLightboxToggle();
    }

    function updateSelLightboxToggle() {
        var photo = selLightboxPhotos[selLightboxIdx];
        if (!photo) return;
        var photoEl = $('.jopg-selection-photo[data-photo-id="' + photo.id + '"]');
        var isSelected = photoEl.hasClass('selected');
        var btn = $('#jopg-sel-lb-toggle');
        if (isSelected) {
            btn.text('✕ Deselect').removeClass('jopg-sel-selected').addClass('jopg-sel-deselect');
        } else {
            btn.text('✓ Select').removeClass('jopg-sel-deselect').addClass('jopg-sel-selected');
        }
    }

    // Lightbox navigation
    $(document).on('click', '.jopg-sel-lb-prev', function(e) {
        e.stopPropagation();
        selLightboxIdx = (selLightboxIdx - 1 + selLightboxPhotos.length) % selLightboxPhotos.length;
        openSelLightbox();
    });
    
    $(document).on('click', '.jopg-sel-lb-next', function(e) {
        e.stopPropagation();
        selLightboxIdx = (selLightboxIdx + 1) % selLightboxPhotos.length;
        openSelLightbox();
    });

    $(document).on('click', '.jopg-sel-lb-close', function(e) {
        e.stopPropagation();
        $('#jopg-sel-lightbox').fadeOut(200);
    });

    $(document).on('click', '.jopg-sel-lb-bg', function() {
        $('#jopg-sel-lightbox').fadeOut(200);
    });

    // Toggle selection from lightbox
    $(document).on('click', '#jopg-sel-lb-toggle', function(e) {
        e.stopPropagation();
        var photo = selLightboxPhotos[selLightboxIdx];
        if (!photo) return;
        var photoEl = $('.jopg-selection-photo[data-photo-id="' + photo.id + '"]');
        photoEl.toggleClass('selected');
        var check = photoEl.find('.jopg-select-check');
        if (photoEl.hasClass('selected')) {
            check.html('✓');
        } else {
            check.html('');
        }
        $('#jopg-sel-count').text($('.jopg-selection-photo.selected').length);
        updateSelLightboxToggle();
    });

    // Keyboard nav in lightbox
    $(document).on('keydown', function(e) {
        if ($('#jopg-sel-lightbox').is(':visible')) {
            if (e.key === 'Escape') $('.jopg-sel-lb-close').click();
            if (e.key === 'ArrowLeft') $('.jopg-sel-lb-prev').click();
            if (e.key === 'ArrowRight') $('.jopg-sel-lb-next').click();
        }
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
