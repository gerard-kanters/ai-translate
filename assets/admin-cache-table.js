/**
 * AI Translate Cache Management Table JavaScript
 * 
 * Handles AJAX interactions for the cache management table
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Debug: Check if ajaxurl is available
    var ajaxUrl = (typeof aiTranslateCacheTable !== 'undefined' && aiTranslateCacheTable.ajaxurl) ? aiTranslateCacheTable.ajaxurl : (typeof ajaxurl !== 'undefined' ? ajaxurl : null);
    if (!ajaxUrl) {
        console.error('AI Translate Cache Table: ajaxurl not found!');
    }

    // Delete Cache Handler
    $(document).on('click', '.ai-translate-delete-cache', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to delete the cache for this page?')) {
            return;
        }
        
        const $button = $(this);
        const postId = $button.data('post-id');
        const nonce = $button.data('nonce');
        const originalText = $button.html();
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Deleting...');
        
        if (!ajaxUrl) {
            alert('Error: AJAX URL not found. Please refresh the page and try again.');
            $button.prop('disabled', false).html(originalText);
            return;
        }
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'ai_translate_delete_cache',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    const $row = $button.closest('tr');
                    $row.css('background-color', '#d4edda');
                    
                    // Update status indicator
                    const statusText = $row.find('.ai-translate-status').text();
                    const totalMatch = statusText.match(/of (\d+)/);
                    const total = totalMatch ? totalMatch[1] : '0';
                    $row.find('.ai-translate-status').removeClass('ai-translate-status-100 ai-translate-status-partial')
                        .addClass('ai-translate-status-0')
                        .text('0 of ' + total);
                    
                    // Show alert
                    alert(response.data.message);
                    
                    // Reload page after short delay to update UI
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    const errorMsg = (response.data && response.data.message) ? response.data.message : (response.data ? response.data : 'Unknown error');
                    alert('Error: ' + errorMsg);
                    $button.prop('disabled', false).html(originalText);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AI Translate Delete Cache Error:', {
                    status: textStatus,
                    error: errorThrown,
                    response: jqXHR.responseText
                });
                alert('AJAX error: ' + textStatus + ' - ' + errorThrown);
                $button.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Warm Cache Handler
    $(document).on('click', '.ai-translate-warm-cache', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const postId = $button.data('post-id');
        const nonce = $button.data('nonce');
        const originalText = $button.html();
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Warming...');
        
        if (!ajaxUrl) {
            alert('Error: AJAX URL not found. Please refresh the page and try again.');
            $button.prop('disabled', false).html(originalText);
            return;
        }
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'ai_translate_warm_cache',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    const $row = $button.closest('tr');
                    $row.css('background-color', '#d4edda');
                    
                    // Show alert
                    alert(response.data.message);
                    
                    // Reload page after short delay to update UI
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    const errorMsg = (response.data && response.data.message) ? response.data.message : (response.data ? response.data : 'Unknown error');
                    alert('Error: ' + errorMsg);
                    $button.prop('disabled', false).html(originalText);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AI Translate Warm Cache Error:', {
                    status: textStatus,
                    error: errorThrown,
                    response: jqXHR.responseText
                });
                alert('AJAX error: ' + textStatus + ' - ' + errorThrown);
                $button.prop('disabled', false).html(originalText);
            }
        });
    });
});

