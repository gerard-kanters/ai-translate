/**
 * AI Translate Async JavaScript
 * Handles asynchronous translation of content on the frontend
 */

(function($) {
    'use strict';

    // Configuration
    const config = {
        batchSize: 10,
        retryAttempts: 3,
        retryDelay: 1000,
        processingClass: 'ai-translate-processing',
        errorClass: 'ai-translate-error',
        loadedClass: 'ai-translate-loaded'
    };

    // State management
    let isProcessing = false;
    let processedItems = new Set();

    /**
     * Initialize async translation when DOM is ready
     */
    $(document).ready(function() {
        if (typeof aiTranslateAsync === 'undefined') {
            console.warn('AI Translate: aiTranslateAsync object not found');
            return;
        }

        if (aiTranslateAsync.debug) {
            console.log('AI Translate: Initializing async translation with', aiTranslateAsync.queueCount, 'items');
        }

        // Find and process all async translation elements
        processAsyncTranslations();
    });

    /**
     * Find and process all elements that need async translation
     */
    function processAsyncTranslations() {
        if (isProcessing) {
            return;
        }

        // Find span placeholders
        const spanPlaceholders = $('.ai-translate-async-placeholder').not('.' + config.loadedClass);
        
        // Find HTML comment markers
        const htmlComments = findHtmlCommentMarkers();

        const totalItems = spanPlaceholders.length + htmlComments.length;

        if (totalItems === 0) {
            if (aiTranslateAsync.debug) {
                console.log('AI Translate: No async translation items found');
            }
            return;
        }

        if (aiTranslateAsync.debug) {
            console.log('AI Translate: Found', totalItems, 'items to translate');
        }

        // Process in batches
        const allItems = [];
        
        // Add span placeholders
        spanPlaceholders.each(function() {
            const $element = $(this);
            const asyncId = $element.data('async-translate');
            if (asyncId && !processedItems.has(asyncId)) {
                allItems.push({
                    id: asyncId,
                    element: $element,
                    type: 'span',
                    sourceLang: $element.data('source-lang'),
                    targetLang: $element.data('target-lang'),
                    isTitle: $element.data('is-title') === 'true',
                    originalText: $element.text()
                });
            }
        });

        // Add HTML comment items
        htmlComments.forEach(function(item) {
            if (!processedItems.has(item.id)) {
                allItems.push({
                    id: item.id,
                    element: null,
                    type: 'html',
                    sourceLang: item.sourceLang,
                    targetLang: item.targetLang,
                    isTitle: item.isTitle,
                    originalText: item.content,
                    startComment: item.startComment,
                    endComment: item.endComment
                });
            }
        });

        if (allItems.length === 0) {
            return;
        }

        // Process items in batches
        processBatch(allItems, 0);
    }

    /**
     * Find HTML comment markers in the page content
     */
    function findHtmlCommentMarkers() {
        const items = [];
        const htmlContent = document.documentElement.outerHTML;
        
        // Regex to find AI_TRANSLATE_ASYNC comment pairs
        const regex = /<!-- AI_TRANSLATE_ASYNC_START:([^:]+):([^:]+):([^:]+):([^:]+) -->(.*?)<!-- AI_TRANSLATE_ASYNC_END:\1 -->/gs;
        let match;

        while ((match = regex.exec(htmlContent)) !== null) {
            items.push({
                id: match[1],
                sourceLang: match[2],
                targetLang: match[3],
                isTitle: match[4] === 'true',
                content: match[5],
                startComment: match[0].substring(0, match[0].indexOf('-->') + 3),
                endComment: '<!-- AI_TRANSLATE_ASYNC_END:' + match[1] + ' -->'
            });
        }

        return items;
    }

    /**
     * Process a batch of translation items
     */
    function processBatch(allItems, startIndex) {
        if (startIndex >= allItems.length) {
            if (aiTranslateAsync.debug) {
                console.log('AI Translate: All batches processed');
            }
            return;
        }

        isProcessing = true;
        const batch = allItems.slice(startIndex, startIndex + config.batchSize);
        
        if (aiTranslateAsync.debug) {
            console.log('AI Translate: Processing batch', Math.floor(startIndex / config.batchSize) + 1, 'with', batch.length, 'items');
        }

        // Show loading indicators
        batch.forEach(function(item) {
            if (item.type === 'span' && item.element) {
                item.element.addClass(config.processingClass);
                showLoadingIndicator(item.element);
            }
        });

        // Prepare batch data for AJAX
        const batchData = {};
        batch.forEach(function(item) {
            batchData[item.id] = {
                text: item.originalText,
                source_language: item.sourceLang,
                target_language: item.targetLang,
                is_title: item.isTitle ? 'true' : 'false'
            };
        });

        // Send AJAX request
        sendBatchTranslation(batchData, batch, function(success) {
            isProcessing = false;
            if (success) {
                // Process next batch
                setTimeout(function() {
                    processBatch(allItems, startIndex + config.batchSize);
                }, 100);
            } else {
                // Retry current batch or skip on final failure
                retryBatch(batch, allItems, startIndex);
            }
        });
    }

    /**
     * Send batch translation AJAX request
     */
    function sendBatchTranslation(batchData, batch, callback) {
        $.ajax({
            url: aiTranslateAsync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ai_translate_async_batch',
                nonce: aiTranslateAsync.nonce,
                batch_data: JSON.stringify(batchData)
            },
            timeout: 30000,
            success: function(response) {
                if (response.success && response.data.translations) {
                    handleTranslationSuccess(response.data.translations, batch);
                    callback(true);
                } else {
                    console.error('AI Translate: Translation failed:', response.data?.message || 'Unknown error');
                    handleTranslationError(batch);
                    callback(false);
                }
            },
            error: function(xhr, status, error) {
                console.error('AI Translate: AJAX error:', status, error);
                handleTranslationError(batch);
                callback(false);
            }
        });
    }

    /**
     * Handle successful translation response
     */
    function handleTranslationSuccess(translations, batch) {
        batch.forEach(function(item) {
            if (translations[item.id]) {
                const translatedText = translations[item.id];
                
                if (item.type === 'span' && item.element) {
                    // Update span element
                    item.element
                        .removeClass(config.processingClass)
                        .addClass(config.loadedClass)
                        .html(translatedText);
                    hideLoadingIndicator(item.element);
                } else if (item.type === 'html') {
                    // Replace HTML comment markers with translated content
                    replaceHtmlCommentContent(item, translatedText);
                }
                
                processedItems.add(item.id);
                
                if (aiTranslateAsync.debug) {
                    console.log('AI Translate: Translated item', item.id);
                }
            }
        });
    }

    /**
     * Handle translation error
     */
    function handleTranslationError(batch) {
        batch.forEach(function(item) {
            if (item.type === 'span' && item.element) {
                item.element
                    .removeClass(config.processingClass)
                    .addClass(config.errorClass);
                hideLoadingIndicator(item.element);
                
                // Keep original text on error
                item.element.text(item.originalText);
            }
            
            processedItems.add(item.id); // Mark as processed to avoid retry loops
        });
    }

    /**
     * Replace HTML comment markers with translated content
     */
    function replaceHtmlCommentContent(item, translatedText) {
        try {
            // Find the start and end comment nodes
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT, null, false);
            let startCommentNode = null;
            let endCommentNode = null;

            while (walker.nextNode()) {
                const node = walker.currentNode;
                if (node.nodeValue && node.nodeValue.trim() === item.startComment.replace('<!--', '').replace('-->', '').trim()) {
                    startCommentNode = node;
                } else if (node.nodeValue && node.nodeValue.trim() === item.endComment.replace('<!--', '').replace('-->', '').trim()) {
                    endCommentNode = node;
                }
                if (startCommentNode && endCommentNode) {
                    break; // Found both, no need to continue
                }
            }

            if (startCommentNode && endCommentNode) {
                // Create a temporary div to parse the translated HTML
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = translatedText;

                // Insert the new content before the end comment node
                while (tempDiv.firstChild) {
                    endCommentNode.parentNode.insertBefore(tempDiv.firstChild, endCommentNode);
                }

                // Remove the original comment nodes
                startCommentNode.parentNode.removeChild(startCommentNode);
                endCommentNode.parentNode.removeChild(endCommentNode);
            } else {
                console.warn('AI Translate: Could not find comment nodes for item:', item.id);
            }
        } catch (error) {
            console.error('AI Translate: Error replacing HTML comment content:', error);
        }
    }

    /**
     * Retry failed batch
     */
    function retryBatch(batch, allItems, startIndex) {
        // For now, just continue to next batch on error
        // Could implement retry logic here if needed
        setTimeout(function() {
            processBatch(allItems, startIndex + config.batchSize);
        }, config.retryDelay);
    }

    /**
     * Show loading indicator for span elements
     */
    function showLoadingIndicator($element) {
        if (!$element.find('.ai-translate-spinner').length) {
            $element.append('<span class="ai-translate-spinner"></span>');
        }
    }

    /**
     * Hide loading indicator for span elements
     */
    function hideLoadingIndicator($element) {
        $element.find('.ai-translate-spinner').remove();
    }

})(jQuery);