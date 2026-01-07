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
    
    /**
     * Render list of cached URLs for a language.
     *
     * @param {jQuery} $container
     * @param {object} data
     */
    function renderCacheUrlList($container, data) {
        $container.empty();
        const items = Array.isArray(data?.items) ? data.items : [];
        
        if (!items.length) {
            const emptyText = aiTranslateCacheTable?.strings?.empty || 'No cached pages for this language.';
            $container.append($('<p class="cache-url-empty"></p>').text(emptyText));
            return;
        }
        
        if (data.truncated && data.total) {
            const tmpl = aiTranslateCacheTable?.strings?.truncated || 'Showing first %1$s of %2$s entries';
            const msg = tmpl.replace('%1$s', items.length).replace('%2$s', data.total);
            $container.append($('<p class="cache-url-info"></p>').text(msg));
        }
        
        const $list = $('<ul class="cache-url-list"></ul>');
        items.forEach(function(item) {
            const url = item.url || '';
            const title = item.title || url || '';
            const updated = item.updated_at ? new Date(item.updated_at * 1000).toLocaleString() : '';
            const sizeBytes = item.file_size ? parseInt(item.file_size, 10) : 0;
            const sizeMb = sizeBytes > 0 ? (sizeBytes / (1024 * 1024)).toFixed(2) : '';
            
            const $li = $('<li></li>');
            const $link = $('<a></a>')
                .attr('href', url)
                .attr('target', '_blank')
                .attr('rel', 'noopener');
            $link.text(title);
            $li.append($link);
            
            const metaParts = [];
            if (updated) {
                metaParts.push(updated);
            }
            if (sizeMb) {
                metaParts.push(sizeMb + ' MB');
            }
            if (metaParts.length > 0) {
                $li.append($('<span class="cache-url-meta"></span>').text(' (' + metaParts.join(' · ') + ')'));
            }
            
            // Add delete button
            const $deleteBtn = $('<button></button>')
                .addClass('button button-small ai-delete-cache-file')
                .attr('type', 'button')
                .attr('data-cache-file', item.cache_file || '')
                .attr('data-lang', data.language || '')
                .attr('data-post-id', item.post_id || '')
                .text(aiTranslateCacheTable?.strings?.delete_file || 'Delete file');
            $li.append(' ').append($deleteBtn);
            
            $list.append($li);
        });
        
        $container.append($list);
    }
    
    // Lazy-load cached URLs per language (expandable rows)
    $(document).on('click', '.ai-cache-toggle-urls', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const lang = $button.data('lang');
        if (!lang) {
            return;
        }
        
        const $details = $('#cache-details-' + lang);
        if ($details.length === 0) {
            return;
        }
        const $content = $details.find('.cache-language-details__content');
        // Bewaar oorspronkelijke label (taalnaam) zodat we de tekst niet vervangen
        if (!$button.data('origLabel')) {
            $button.data('origLabel', $button.text());
        }
        const originalLabel = $button.data('origLabel');
        
        // Toggle when already loaded
        if ($details.data('loaded') === '1') {
            if ($details.is(':visible')) {
                $details.hide();
                $('[data-lang="' + lang + '"].ai-cache-toggle-urls').attr('aria-expanded', 'false');
            } else {
                $details.show();
                $('[data-lang="' + lang + '"].ai-cache-toggle-urls').attr('aria-expanded', 'true');
            }
            return;
        }
        
        // First load
        $button.prop('disabled', true);
        $details.show();
        const loadingText = aiTranslateCacheTable?.strings?.loading || 'Loading cached URLs...';
        $content.empty().append($('<p class="cache-url-loading"></p>').text(loadingText));
        
        if (!ajaxUrl) {
            const errText = aiTranslateCacheTable?.strings?.error || 'Could not load cached URLs. Please try again.';
            $content.empty().append($('<p class="cache-url-error"></p>').text(errText));
            $button.prop('disabled', false);
            $details.data('loaded', '0');
            return;
        }
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'ai_translate_get_cache_urls_by_language',
                nonce: aiTranslateCacheTable.listNonce,
                lang_code: lang
            },
            success: function(response) {
                $button.prop('disabled', false);
                if (!response || !response.success || !response.data) {
                    const errText = aiTranslateCacheTable?.strings?.error || 'Could not load cached URLs. Please try again.';
                    $content.empty().append($('<p class="cache-url-error"></p>').text(errText));
                    $details.data('loaded', '0');
                    return;
                }
                
                renderCacheUrlList($content, response.data);
                $details.data('loaded', '1');
                $('[data-lang="' + lang + '"].ai-cache-toggle-urls').attr('aria-expanded', 'true');
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AI Translate Cache URL list error:', {
                    status: textStatus,
                    error: errorThrown,
                    response: jqXHR.responseText
                });
                const errText = aiTranslateCacheTable?.strings?.error || 'Could not load cached URLs. Please try again.';
                $content.empty().append($('<p class="cache-url-error"></p>').text(errText));
                $button.prop('disabled', false);
                $details.data('loaded', '0');
                $('[data-lang="' + lang + '"].ai-cache-toggle-urls').attr('aria-expanded', 'false');
            }
        });
    });
    
    // Table sorting functionality for cache language table
    var currentSort = {
        column: 'language',
        direction: 'asc'
    };
    
    // Initialize: sort by language ascending by default
    function initTableSort() {
        var $table = $('#cache-language-table');
        if ($table.length === 0) {
            return;
        }
        
        // Set initial sort indicator
        $table.find('th.sortable[data-sort="language"]').addClass('sort-asc');
        
        // Sort table by language (ascending) on load
        sortTable('language', 'asc');
    }
    
    // Sort table function
    function sortTable(column, direction) {
        var $table = $('#cache-language-table');
        var $tbody = $table.find('tbody');
        // Sorteer alleen hoofd-rijen (taal) en hang bij re-append de detail-rij er direct onder
        var $rows = $tbody.find('tr.cache-language-row').toArray();
        var sortType = $table.find('th.sortable[data-sort="' + column + '"]').data('sort-type') || 'text';
        
        $rows.sort(function(a, b) {
            var $a = $(a);
            var $b = $(b);
            var valA, valB;
            
            switch(column) {
                case 'language':
                    valA = $a.data('language') || '';
                    valB = $b.data('language') || '';
                    break;
                case 'files':
                    valA = parseInt($a.data('files')) || 0;
                    valB = parseInt($b.data('files')) || 0;
                    break;
                case 'size':
                    valA = parseFloat($a.data('size')) || 0;
                    valB = parseFloat($b.data('size')) || 0;
                    break;
                case 'expired':
                    valA = parseInt($a.data('expired')) || 0;
                    valB = parseInt($b.data('expired')) || 0;
                    break;
                case 'lastupdate':
                    valA = parseInt($a.data('lastupdate')) || 0;
                    valB = parseInt($b.data('lastupdate')) || 0;
                    break;
                default:
                    return 0;
            }
            
            var result = 0;
            if (sortType === 'number' || sortType === 'date') {
                result = valA - valB;
            } else {
                // Text comparison
                if (valA < valB) result = -1;
                else if (valA > valB) result = 1;
                else result = 0;
            }
            
            return direction === 'asc' ? result : -result;
        });
        
        // Re-append sorted rows
        $.each($rows, function(index, row) {
            var $row = $(row);
            var lang = $row.attr('id') ? $row.attr('id').replace('cache-row-', '') : '';
            var $detail = lang ? $('#cache-details-' + lang) : null;
            
            $tbody.append($row);
            if ($detail && $detail.length) {
                $tbody.append($detail);
            }
        });
        
        // Update sort indicators
        $table.find('th.sortable').removeClass('sort-asc sort-desc');
        $table.find('th.sortable[data-sort="' + column + '"]').addClass('sort-' + direction);
        
        currentSort.column = column;
        currentSort.direction = direction;
    }
    
    // Handle column header clicks
    $(document).on('click', '#cache-language-table th.sortable', function(e) {
        // Don't sort if clicking on a child element (like a button or link)
        if ($(e.target).closest('button, a, .ai-cache-toggle-urls').length > 0) {
            return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        var $th = $(this);
        var column = $th.data('sort');
        if (!column) {
            return;
        }
        
        var newDirection = 'asc';
        
        // Toggle direction if clicking the same column
        if (currentSort.column === column && currentSort.direction === 'asc') {
            newDirection = 'desc';
        }
        
        sortTable(column, newDirection);
    });
    
    // Handle delete cache file button
    $(document).on('click', '.ai-delete-cache-file', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        
        const $button = $(this);
        const cacheFile = $button.attr('data-cache-file');
        const lang = $button.attr('data-lang');
        const postId = $button.attr('data-post-id');
        
        if (!cacheFile) {
            return;
        }
        
        // Confirm deletion
        const confirmMsg = aiTranslateCacheTable?.strings?.confirm_delete || 'Are you sure you want to delete this cache file?';
        if (!confirm(confirmMsg)) {
            return;
        }
        
        // Disable button
        $button.prop('disabled', true).text(aiTranslateCacheTable?.strings?.deleting || 'Deleting...');
        
        // Send AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ai_translate_delete_cache_file',
                nonce: aiTranslateCacheTable?.delete_nonce || '',
                cache_file: cacheFile,
                language: lang,
                post_id: postId
            },
            success: function(response) {
                if (response && response.success) {
                    // Remove the list item from the DOM
                    $button.closest('li').fadeOut(300, function() {
                        $(this).remove();
                        
                        // Update count in the table
                        const $detailRow = $button.closest('.cache-language-details');
                        const $mainRow = $detailRow.prev('.cache-language-row');
                        const $countCell = $mainRow.find('td').eq(1); // Cache files column
                        const currentCount = parseInt($countCell.text()) || 0;
                        if (currentCount > 0) {
                            $countCell.text((currentCount - 1) + ' bestanden');
                        }
                    });
                } else {
                    alert(response?.data?.message || (aiTranslateCacheTable?.strings?.delete_error || 'Failed to delete cache file'));
                    $button.prop('disabled', false).text(aiTranslateCacheTable?.strings?.delete_file || 'Delete file');
                }
            },
            error: function() {
                alert(aiTranslateCacheTable?.strings?.delete_error || 'Failed to delete cache file');
                $button.prop('disabled', false).text(aiTranslateCacheTable?.strings?.delete_file || 'Delete file');
            }
        });
    });
    
    // Initialize sorting on page load
    initTableSort();
});

