document.addEventListener('DOMContentLoaded', function () {
    var tabLinks = document.querySelectorAll('.nav-tab-wrapper a');
    tabLinks.forEach(function (link) {
        var tab = link.getAttribute('href').split('&tab=')[1];
        if (tab) {
            link.href = aiTranslateAdmin.adminUrl + '&tab=' + tab;
        }
    });

    // Show/hide custom model text field based on selected value
    var selectedModel = document.getElementById('selected_model');
    var customModelDiv = document.getElementById('custom_model_div');

    // API Validation functionality
    var apiStatusSpan = document.getElementById('ai-translate-api-status');
    var validateApiBtn = document.getElementById('ai-translate-validate-api');
    var apiKeyInput = document.querySelector('input[name="ai_translate_settings[api_key]"]');
    var customModelInput = document.querySelector('input[name="ai_translate_settings[custom_model]"]');
    var apiProviderSelect = document.getElementById('api_provider_select');
    var apiKeyRequestLinkSpan = document.getElementById('api-key-request-link-span');
    var customApiUrlDiv = document.getElementById('custom_api_url_div');
    var customApiUrlInput = document.querySelector('input[name="ai_translate_settings[custom_api_url]"]');
    
    // Get stored API keys
    var apiKeys = aiTranslateAdmin.apiKeys || {};

    // API Provider data (mirrors PHP for client-side use)
    const apiProvidersData = {
        'openai': {
            name: 'OpenAI',
            url: 'https://api.openai.com/v1/',
            key_link: 'https://platform.openai.com/'
        },
        'deepseek': {
            name: 'Deepseek',
            url: 'https://api.deepseek.com/v1/',
            key_link: 'https://platform.deepseek.com/'
        },
        'openrouter': {
            name: 'OpenRouter',
            url: 'https://openrouter.ai/api/v1/',
            key_link: 'https://openrouter.ai/docs/api-keys'
        },
        'groq': {
            name: 'Groq',
            url: 'https://api.groq.com/openai/v1',
            key_link: 'https://console.groq.com/keys/'
        },
        'deepinfra': {
            name: 'DeepInfra',
            url: 'https://api.deepinfra.com/v1/openai',
            key_link: 'https://deepinfra.com/dashboard/api_keys'
        },
        'custom': {
            name: 'Custom URL',
            url: '',
            key_link: ''
        }
    };

    var modelsPerProvider = aiTranslateAdmin.models || {};

    function updateApiKeyRequestLink() {
        // Re-fetch elements in case they weren't available on initial load
        if (!apiKeyRequestLinkSpan) {
            apiKeyRequestLinkSpan = document.getElementById('api-key-request-link-span');
        }
        if (!apiProviderSelect) {
            apiProviderSelect = document.getElementById('api_provider_select');
        }
        
        if (apiProviderSelect && apiKeyRequestLinkSpan) {
            var selectedProviderKey = apiProviderSelect.value;
            if (selectedProviderKey && selectedProviderKey !== '') {
                var providerInfo = apiProvidersData[selectedProviderKey];
                if (providerInfo && providerInfo.key_link && providerInfo.key_link !== '') {
                    apiKeyRequestLinkSpan.innerHTML = '<a href="' + providerInfo.key_link + '" target="_blank">Request Key</a>';
                } else {
                    apiKeyRequestLinkSpan.innerHTML = '';
                }
            } else {
                apiKeyRequestLinkSpan.innerHTML = '';
            }
        }
    }

    function toggleCustomApiUrlField() {
        if (apiProviderSelect && customApiUrlDiv) {
            if (apiProviderSelect.value === 'custom') {
                customApiUrlDiv.style.display = 'block';
            } else {
                customApiUrlDiv.style.display = 'none';
            }
        }
    }

    function toggleGpt5Warning() {
        var gpt5Warning = document.getElementById('openai_gpt5_warning');
        if (gpt5Warning && apiProviderSelect) {
            if (apiProviderSelect.value === 'openai') {
                gpt5Warning.style.display = 'block';
            } else {
                gpt5Warning.style.display = 'none';
            }
        }
    }

    function updateModelField() {
        if (!selectedModel) return;
        var selectedProvider = apiProviderSelect ? apiProviderSelect.value : '';
        if (!selectedProvider) return;
        
        var model = modelsPerProvider[selectedProvider] || '';
        
        // Hide custom model field for all providers except custom
        toggleCustomModelField();
        
        // Clear dropdown first
        selectedModel.innerHTML = '';
        
        // For OpenAI, Deepseek, OpenRouter, Groq, and DeepInfra: load models via AJAX
        if (selectedProvider === 'openai' || selectedProvider === 'deepseek' || selectedProvider === 'openrouter' || selectedProvider === 'groq' || selectedProvider === 'deepinfra') {
            loadModelsForProvider(selectedProvider, model);
        } else if (selectedProvider === 'custom') {
            // For custom provider: show only the stored model
            if (model) {
                var opt = document.createElement('option');
                opt.value = model;
                opt.textContent = model;
                opt.selected = true;
                selectedModel.appendChild(opt);
            }
            var customOpt = document.createElement('option');
            customOpt.value = 'custom';
            customOpt.textContent = 'Select...';
            if (!model) customOpt.selected = true;
            selectedModel.appendChild(customOpt);
            
            // Trigger change event
            var event = new Event('change');
            selectedModel.dispatchEvent(event);
        } else {
            // No provider selected
            var strings = aiTranslateAdmin.strings || {};
            var placeholderOpt = document.createElement('option');
            placeholderOpt.value = '';
            placeholderOpt.textContent = strings.selectApiProviderFirst || 'Select API Provider first...';
            placeholderOpt.disabled = true;
            placeholderOpt.selected = true;
            selectedModel.appendChild(placeholderOpt);
        }
    }

    function loadModelsForProvider(provider, currentModel) {
        var apiUrl = getSelectedApiUrl();
        var apiKey = apiKeyInput ? apiKeyInput.value.trim() : '';
        var strings = aiTranslateAdmin.strings || {};

        if (!apiUrl || !apiKey) {
            if (selectedModel) {
                selectedModel.innerHTML = '';
                var placeholderOpt = document.createElement('option');
                placeholderOpt.value = '';
                placeholderOpt.textContent = strings.enterApiKeyFirst || 'Enter API Key first...';
                placeholderOpt.disabled = true;
                placeholderOpt.selected = true;
                selectedModel.appendChild(placeholderOpt);
            }
            if (apiStatusSpan) apiStatusSpan.textContent = strings.enterApiKeyToLoadModels || 'Enter API Key first to load models.';
            // Hide custom model field when no API key
            if (customModelDiv) {
                customModelDiv.style.display = 'none';
            }
            return;
        }

        if (apiStatusSpan) apiStatusSpan.textContent = strings.loadingModels || 'Loading models...';

        var data = new FormData();
        data.append('action', 'ai_translate_get_models');
        data.append('nonce', aiTranslateAdmin.getModelsNonce);
        data.append('api_key', apiKey);
        data.append('api_provider', provider);

        if (provider === 'custom' && customApiUrlInput) {
            data.append('custom_api_url_value', customApiUrlInput.value);
        }

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
            .then(r => r.json())
            .then(function (resp) {
                if (selectedModel) {
                    if (resp.success && resp.data && resp.data.models) {
                        // Clear dropdown again (to be sure)
                        selectedModel.innerHTML = '';
                        
                        resp.data.models.forEach(function (modelId) {
                            var opt = document.createElement('option');
                            opt.value = modelId;
                            opt.textContent = modelId;
                            if (modelId === currentModel) opt.selected = true;
                            selectedModel.appendChild(opt);
                        });

                        // Only add "custom" option for custom provider
                        if (provider === 'custom') {
                            var customOpt = document.createElement('option');
                            customOpt.value = 'custom';
                            customOpt.textContent = 'Select...';
                            if (currentModel === 'custom') customOpt.selected = true;
                            selectedModel.appendChild(customOpt);
                        }
                        
                        if (apiStatusSpan) apiStatusSpan.textContent = strings.modelsLoadedSuccessfully || 'Models loaded successfully.';
                    } else {
                        // Check if error is due to invalid API key (401, 403, or unauthorized message)
                        var isInvalidKey = false;
                        var errorMessage = '';
                        if (resp.data && resp.data.message) {
                            errorMessage = resp.data.message;
                            // Check for common invalid key indicators
                            if (errorMessage.toLowerCase().indexOf('unauthorized') !== -1 ||
                                errorMessage.toLowerCase().indexOf('invalid') !== -1 ||
                                errorMessage.toLowerCase().indexOf('authentication') !== -1 ||
                                errorMessage.toLowerCase().indexOf('401') !== -1 ||
                                errorMessage.toLowerCase().indexOf('403') !== -1 ||
                                errorMessage.toLowerCase().indexOf('forbidden') !== -1) {
                                isInvalidKey = true;
                            }
                        }
                        
                        if (selectedModel) {
                            selectedModel.innerHTML = '';
                            var errorOpt = document.createElement('option');
                            errorOpt.value = '';
                            errorOpt.textContent = isInvalidKey ? (strings.noModelsOrInvalidKey || 'No models/Invalid key') : (strings.noModelsFound || 'No models found');
                            errorOpt.disabled = true;
                            errorOpt.selected = true;
                            selectedModel.appendChild(errorOpt);
                        }
                        var statusMessage = isInvalidKey ? (strings.noModelsOrInvalidKey || 'No models/Invalid key') : (strings.noModelsFound || 'No models found');
                        if (errorMessage) {
                            statusMessage += ': ' + errorMessage;
                        } else {
                            statusMessage += ': ' + (strings.unknownError || 'Unknown error');
                        }
                        if (apiStatusSpan) apiStatusSpan.textContent = statusMessage;
                    }
                }
            })
            .catch(function (e) {
                if (selectedModel) {
                    selectedModel.innerHTML = '';
                    var errorOpt = document.createElement('option');
                    errorOpt.value = '';
                    errorOpt.textContent = strings.errorLoadingModels || 'Error loading models';
                    errorOpt.disabled = true;
                    errorOpt.selected = true;
                    selectedModel.appendChild(errorOpt);
                }
                if (apiStatusSpan) apiStatusSpan.textContent = (strings.errorLoadingModels || 'Error loading models') + ': ' + e.message;
            });
    }

    if (apiProviderSelect) {
        apiProviderSelect.addEventListener('change', updateApiKeyRequestLink);
        apiProviderSelect.addEventListener('change', toggleCustomApiUrlField);
        apiProviderSelect.addEventListener('change', toggleGpt5Warning);
        apiProviderSelect.addEventListener('change', updateApiKeyField); // Update API key field when provider changes
        apiProviderSelect.addEventListener('change', updateModelField);
        // Also update when select is clicked/focused (in case change event doesn't fire)
        apiProviderSelect.addEventListener('focus', function() {
            setTimeout(updateApiKeyRequestLink, 50);
        });
        // Call update functions on initial load
        updateApiKeyRequestLink();
        toggleCustomApiUrlField();
        toggleGpt5Warning();
        // Don't call updateApiKeyField() on initial load - let PHP value stay
        // Only update when user actively changes provider
        updateModelField(); // Initial on load
    } else {
        // If apiProviderSelect doesn't exist yet, try again after a short delay
        setTimeout(function() {
            apiProviderSelect = document.getElementById('api_provider_select');
            if (apiProviderSelect) {
                apiProviderSelect.addEventListener('change', updateApiKeyRequestLink);
                apiProviderSelect.addEventListener('focus', function() {
                    setTimeout(updateApiKeyRequestLink, 50);
                });
                updateApiKeyRequestLink();
            }
        }, 100);
    }
    
    // Additional fallback: try to update link after page is fully loaded
    setTimeout(function() {
        updateApiKeyRequestLink();
    }, 500);

    // Function to update the API key field
    function updateApiKeyField() {
        if (apiProviderSelect && apiKeyInput) {
            var selectedProvider = apiProviderSelect.value;
            apiKeyInput.value = apiKeys[selectedProvider] || ''; // Update field with stored key
        }
        if (apiStatusSpan) {
            apiStatusSpan.textContent = ''; // Clear API status message
        }
    }

    function getSelectedApiUrl() {
        if (apiProviderSelect) {
            var selectedProviderKey = apiProviderSelect.value;
            if (selectedProviderKey === 'custom' && customApiUrlInput) {
                return customApiUrlInput.value;
            }
            var providerInfo = apiProvidersData[selectedProviderKey];
            return providerInfo ? providerInfo.url : '';
        }
        return '';
    }

    function loadModels() {
        var apiUrl = getSelectedApiUrl();
        var apiKey = apiKeyInput ? apiKeyInput.value.trim() : '';
        var strings = aiTranslateAdmin.strings || {};

        if (!apiUrl || !apiKey) {
            if (selectedModel) {
                selectedModel.innerHTML = '';
                var placeholderOpt = document.createElement('option');
                placeholderOpt.value = '';
                placeholderOpt.textContent = strings.enterApiKeyFirst || 'Enter API Key first...';
                placeholderOpt.disabled = true;
                placeholderOpt.selected = true;
                selectedModel.appendChild(placeholderOpt);
            }
            if (apiStatusSpan) apiStatusSpan.textContent = strings.enterApiKeyToLoadModels || 'Enter API Key first to load models.';
            // Hide custom model field when no API key
            if (customModelDiv) {
                customModelDiv.style.display = 'none';
            }
            return;
        }

        if (apiStatusSpan) apiStatusSpan.textContent = strings.loadingModels || 'Loading models...';

        var data = new FormData();
        data.append('action', 'ai_translate_get_models');
        data.append('nonce', aiTranslateAdmin.getModelsNonce);
        data.append('api_key', apiKey);

        if (apiProviderSelect) {
            data.append('api_provider', apiProviderSelect.value);
            if (apiProviderSelect.value === 'custom' && customApiUrlInput) {
                data.append('custom_api_url_value', customApiUrlInput.value);
            }
        }

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
            .then(r => r.json())
            .then(function (resp) {
                if (selectedModel) {
                    if (resp.success && resp.data && resp.data.models) {
                        var current = selectedModel.value;
                        selectedModel.innerHTML = '';
                        resp.data.models.forEach(function (modelId) {
                            var opt = document.createElement('option');
                            opt.value = modelId;
                            opt.textContent = modelId;
                            if (modelId === current) opt.selected = true;
                            selectedModel.appendChild(opt);
                        });

                        // Only add "custom" option for custom provider
                        var selectedProvider = apiProviderSelect ? apiProviderSelect.value : '';
                        if (selectedProvider === 'custom') {
                            var customOpt = document.createElement('option');
                            customOpt.value = 'custom';
                            customOpt.textContent = 'Select...';
                            if (current === 'custom') customOpt.selected = true;
                            selectedModel.appendChild(customOpt);
                        }
                        if (apiStatusSpan) apiStatusSpan.textContent = strings.modelsLoadedSuccessfully || 'Models loaded successfully.';
                    } else {
                        // Check if error is due to invalid API key
                        var isInvalidKey = false;
                        var errorMessage = '';
                        if (resp.data && resp.data.message) {
                            errorMessage = resp.data.message;
                            if (errorMessage.toLowerCase().indexOf('unauthorized') !== -1 ||
                                errorMessage.toLowerCase().indexOf('invalid') !== -1 ||
                                errorMessage.toLowerCase().indexOf('authentication') !== -1 ||
                                errorMessage.toLowerCase().indexOf('401') !== -1 ||
                                errorMessage.toLowerCase().indexOf('403') !== -1 ||
                                errorMessage.toLowerCase().indexOf('forbidden') !== -1) {
                                isInvalidKey = true;
                            }
                        }
                        var statusMessage = isInvalidKey ? (strings.noModelsOrInvalidKey || 'No models/Invalid key') : (strings.noModelsFound || 'No models found');
                        if (errorMessage) {
                            statusMessage += ': ' + errorMessage;
                        } else {
                            statusMessage += ': ' + (strings.unknownError || 'Unknown error');
                        }
                        if (apiStatusSpan) apiStatusSpan.textContent = statusMessage;
                    }
                }
            })
            .catch(function (e) {
                if (apiStatusSpan) apiStatusSpan.textContent = (strings.errorLoadingModels || 'Error loading models') + ': ' + e.message;
            });
    }

    function toggleCustomModelField() {
        if (!customModelDiv || !apiProviderSelect) return;
        var selectedProvider = apiProviderSelect.value;
        var selectedModelValue = selectedModel ? selectedModel.value : '';
        // Only show custom model field for custom provider AND when model is 'custom'
        if (selectedProvider === 'custom' && selectedModelValue === 'custom') {
            customModelDiv.style.display = 'block';
        } else {
            customModelDiv.style.display = 'none';
        }
    }

    if (selectedModel) {
        selectedModel.addEventListener('focus', loadModels);
        selectedModel.addEventListener('change', function () {
            toggleCustomModelField();
        });

        // Initial state
        toggleCustomModelField();
    }
    
    // Also update when provider changes
    if (apiProviderSelect) {
        apiProviderSelect.addEventListener('change', function() {
            toggleCustomModelField();
        });
    }
    
    // Auto-load models when API key is entered (with debounce)
    var apiKeyTimeout;
    if (apiKeyInput) {
        apiKeyInput.addEventListener('input', function() {
            clearTimeout(apiKeyTimeout);
            apiKeyTimeout = setTimeout(function() {
                var selectedProvider = apiProviderSelect ? apiProviderSelect.value : '';
                if (selectedProvider && (selectedProvider === 'openai' || selectedProvider === 'deepseek' || selectedProvider === 'openrouter' || selectedProvider === 'groq' || selectedProvider === 'deepinfra')) {
                    var model = modelsPerProvider[selectedProvider] || '';
                    loadModelsForProvider(selectedProvider, model);
                }
            }, 500); // Wait 500ms after user stops typing
        });
    }

    if (validateApiBtn) {
        validateApiBtn.addEventListener('click', function () {
            if (apiStatusSpan) apiStatusSpan.innerHTML = 'Validating...';

            var apiUrl = getSelectedApiUrl();
            var apiKey = apiKeyInput ? apiKeyInput.value : '';
            var modelId = selectedModel ? selectedModel.value : '';

            if (modelId === 'custom') {
                modelId = customModelInput ? customModelInput.value : '';
            }

            if (!apiUrl || !apiKey || !modelId) {
                if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Select API Provider, enter API Key and select a model.</span>';
                return;
            }

            validateApiBtn.disabled = true;

            var data = new FormData();
            data.append('action', 'ai_translate_validate_api');
            data.append('nonce', aiTranslateAdmin.validateApiNonce);
            data.append('api_url', apiUrl);
            data.append('api_key', apiKey);
            data.append('model', modelId);

            if (apiProviderSelect) {
                data.append('api_provider', apiProviderSelect.value);
                if (apiProviderSelect.value === 'custom' && customApiUrlInput) {
                    data.append('custom_api_url_value', customApiUrlInput.value);
                }
            }
            data.append('save_settings', '1');

            if (modelId === 'custom' && customModelInput) {
                data.append('custom_model_value', customModelInput.value);
            }

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
                .then(r => r.json())
                .then(function (resp) {
                    if (resp.success) {
                        // Update locally stored apiKeys object after successful validation
                        if (apiProviderSelect) {
                            var selectedProvider = apiProviderSelect.value;
                            apiKeys[selectedProvider] = apiKey;
                        }
                        if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:green;font-weight:bold;">&#10003; Connection and model OK. API settings saved.</span>';
                    } else {
                        if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; ' +
                            (resp.data && resp.data.message ? resp.data.message : 'Error') + '</span>';
                    }
                })
                .catch(function (error) {
                    if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Validation AJAX Error: ' + error.message + '</span>';
                })
                .finally(function () {
                    validateApiBtn.disabled = false;
                });
        });
    }

    /**
     * Updates the UI elements after cache clearing
     * @param {string} langCode - The language code that was cleared
     */
    function updateCacheUI(langCode) {
        try {            
            // Update table row for this language
            var row = document.getElementById('cache-row-' + langCode);
            if (row) {
                // Find and update the count cell
                var countElement = row.querySelector('.cache-count[data-lang="' + langCode + '"]');
                if (countElement) {
                    var oldCount = parseInt(countElement.textContent) || 0;
                    countElement.textContent = '0';

                    // Update totals
                    var totalElement = document.getElementById('total-cache-count');
                    var tableTotal = document.getElementById('table-total-count');

                    if (totalElement) {
                        var currentTotal = parseInt(totalElement.textContent) || 0;
                        totalElement.textContent = Math.max(0, currentTotal - oldCount);
                    }

                    if (tableTotal) {
                        var currentTableTotal = parseInt(tableTotal.textContent) || 0;
                        tableTotal.textContent = Math.max(0, currentTableTotal - oldCount);
                    }

                    // Update row classes and last column
                    row.classList.remove('has-cache');
                    row.classList.add('no-cache');

                    // Replace the button with a checkmark
                    var actionCell = row.querySelector('td:last-child');
                    if (actionCell) {
                        actionCell.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="No cache files"></span>';
                    }

                    // Highlight the row
                    row.style.backgroundColor = '#e7f7ed';
                    setTimeout(function () {
                        row.style.transition = 'background-color 1s ease-in-out';
                        row.style.backgroundColor = '';
                    }, 1500);
                }
            }
        } catch (error) {
            console.error('Error in updateCacheUI:', error);
        }
    }

    // Cache per language functionality
    var langSelect = document.getElementById('cache_language');
    var langCountSpan = document.getElementById('selected-lang-count');

    // Quick cache clear buttons in the table
    var quickClearButtons = document.querySelectorAll('.quick-clear-cache');
    if (quickClearButtons.length > 0) {
        quickClearButtons.forEach(function (button) {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var langCode = button.getAttribute('data-lang');
                if (!langCode) return;

                if (langSelect && langSelect.options) {
                    for (var i = 0; i < langSelect.options.length; i++) {
                        if (langSelect.options[i].value === langCode) {
                            langSelect.selectedIndex = i;
                            break;
                        }
                    }
                }

                var nonce = document.querySelector('input[name="clear_cache_language_nonce"]').value;
                if (!nonce) {
                    console.error('Nonce field not found');

                    var noticeDiv = document.createElement('div');
                    noticeDiv.className = 'notice notice-error is-dismissible';
                    noticeDiv.innerHTML = '<p>Security token not found. Refresh the page and try again.</p>';

                    var cacheTab = document.getElementById('cache');
                    if (cacheTab) {
                        cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                        setTimeout(function () {
                            noticeDiv.style.transition = 'opacity 1s ease-out';
                            noticeDiv.style.opacity = 0;
                            setTimeout(function () {
                                noticeDiv.remove();
                            }, 1000);
                        }, 5000);
                    }
                    return;
                }

                var originalText = button.textContent;
                button.textContent = 'Processing...';
                button.disabled = true;

                // AJAX request
                var formData = new FormData();
                formData.append('action', 'ai_translate_clear_cache_language');
                formData.append('lang_code', langCode);
                formData.append('nonce', nonce);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                }).then(response => {
                    if (!response.ok) {
                        throw new Error('Server response not ok: ' + response.status);
                    }
                    return response.json();
                })
                    .then(function (data) {                   

                        if (data.success) {
                            var noticeDiv = document.createElement('div');
                            noticeDiv.className = 'notice notice-success is-dismissible';
                            noticeDiv.innerHTML = '<p>' + data.data.message + '</p>';

                            var cacheTab = document.getElementById('cache');
                            if (cacheTab) {
                                cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                                setTimeout(function () {
                                    noticeDiv.style.transition = 'opacity 1s ease-out';
                                    noticeDiv.style.opacity = 0;
                                    setTimeout(function () {
                                        noticeDiv.remove();
                                    }, 1000);
                                }, 5000);
                            }

                            updateCacheUI(langCode);
                            setTimeout(function () {
                                window.location.reload();
                            }, 3000);
                        } else {
                            var errorMsg = 'Error clearing cache';

                            if (data.data && data.data.message) {
                                errorMsg += ': ' + data.data.message;
                            } else if (data.message) {
                                errorMsg += ': ' + data.message;
                            }

                            var noticeDiv = document.createElement('div');
                            noticeDiv.className = 'notice notice-error is-dismissible';
                            noticeDiv.innerHTML = '<p>' + errorMsg + '</p>';

                            var cacheTab = document.getElementById('cache');
                            if (cacheTab) {
                                cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                                setTimeout(function () {
                                    noticeDiv.style.transition = 'opacity 1s ease-out';
                                    noticeDiv.style.opacity = 0;
                                    setTimeout(function () {
                                        noticeDiv.remove();
                                    }, 1000);
                                }, 5000);
                            }

                            button.textContent = originalText;
                            button.disabled = false;
                        }
                    })
                    .catch(function (error) {
                        console.error('AJAX Error:', error);

                        var noticeDiv = document.createElement('div');
                        noticeDiv.className = 'notice notice-error is-dismissible';
                        noticeDiv.innerHTML = '<p>An error occurred while clearing the cache: ' + error.message + '</p>';

                        var cacheTab = document.getElementById('cache');
                        if (cacheTab) {
                            cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                            setTimeout(function () {
                                noticeDiv.style.transition = 'opacity 1s ease-out';
                                noticeDiv.style.opacity = 0;
                                setTimeout(function () {
                                    noticeDiv.remove();
                                }, 1000);
                            }, 5000);
                        }

                        button.textContent = originalText;
                        button.disabled = false;
                    });
            });
        });
    }

    // Make table rows clickable to select a language
    var cacheTableRows = document.querySelectorAll('tr[id^="cache-row-"]');
    cacheTableRows.forEach(function (row) {
        row.style.cursor = 'pointer';
        row.setAttribute('title', 'Click to select this language');
        row.addEventListener('click', function (e) {
            if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                return;
            }
            var langCode = row.id.replace('cache-row-', '');

            if (langSelect && langSelect.options) {
                for (var i = 0; i < langSelect.options.length; i++) {
                    if (langSelect.options[i].value === langCode) {
                        langSelect.selectedIndex = i;
                        var changeEvent = new Event('change');
                        langSelect.dispatchEvent(changeEvent);
                        break;
                    }
                }
            }
        });
    });

    if (langSelect && langCountSpan) {
        langSelect.addEventListener('change', function () {
            if (langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
                var selectedOption = langSelect.options[langSelect.selectedIndex];
                if (selectedOption) {
                    var count = selectedOption.getAttribute('data-count');
                    langCountSpan.textContent = count + ' files in cache';
                }
            }
        });

        if (langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
            var initialOption = langSelect.options[langSelect.selectedIndex];
            if (initialOption) {
                var initialCount = initialOption.getAttribute('data-count');
                langCountSpan.textContent = initialCount + ' files in cache';
            }
        }

        var cacheForm = document.getElementById('clear-cache-language-form');
        if (cacheForm) {
            cacheForm.addEventListener('submit', function () {
                if (langSelect && langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
                    var selectedOption = langSelect.options[langSelect.selectedIndex];
                    if (selectedOption) {
                        var langCode = selectedOption.value;
                        updateCacheUI(langCode);
                    }
                }
            });
        }
    }

    // Validate API fields before form submission
    var generalForm = document.querySelector('#general form');
    if (generalForm) {
        generalForm.addEventListener('submit', function (e) {
            var apiUrl = document.querySelector('input[name="ai_translate_settings[api_url]"]');
            var apiKey = document.querySelector('input[name="ai_translate_settings[api_key]"]');

            var existingErrors = document.querySelectorAll('.aitranslate-error');
            existingErrors.forEach(function (error) {
                error.remove();
            });

            if (!apiUrl.value.trim() || !apiKey.value.trim()) {
                var errorMsg = document.createElement('div');
                errorMsg.className = 'error notice aitranslate-error';
                errorMsg.innerHTML = '<p>Note: Enter both API URL and API Key to use translation functionality.</p>';
                document.querySelector('#general').insertBefore(errorMsg, document.querySelector('#general form'));
            }
        });
    }

    // Generate Website Context functionality
    var generateContextBtn = document.getElementById('generate-context-btn');
    var generateContextStatus = document.getElementById('generate-context-status');
    var websiteContextField = document.getElementById('website_context_field');

    if (generateContextBtn && generateContextStatus && websiteContextField) {
        generateContextBtn.addEventListener('click', function () {
            // Check if API is configured
            var apiKey = apiKeyInput ? apiKeyInput.value : '';
            if (!apiKey) {
                generateContextStatus.innerHTML = '<span style="color:red;">Please configure API key first</span>';
                return;
            }

            // Check if context field is empty
            if (websiteContextField.value.trim()) {
                if (!confirm('The context field already has content. Do you want to replace it with a generated suggestion?')) {
                    return;
                }
            }

            generateContextBtn.disabled = true;
            generateContextStatus.innerHTML = '<span style="color:blue;">Generating context from homepage...</span>';

            var formData = new FormData();
            formData.append('action', 'ai_translate_generate_website_context');
            formData.append('nonce', aiTranslateAdmin.generateContextNonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Server response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (data.success && data.data && data.data.context) {
                    websiteContextField.value = data.data.context;
                    generateContextStatus.innerHTML = '<span style="color:green;">✓ Context generated successfully!</span>';
                    
                    // Auto-save the form
                    var form = websiteContextField.closest('form');
                    if (form) {
                        var submitBtn = form.querySelector('input[type="submit"]');
                        if (submitBtn) {
                            submitBtn.click();
                        }
                    }
                } else {
                    var errorMsg = 'Failed to generate context';
                    if (data.data && data.data.message) {
                        errorMsg += ': ' + data.data.message;
                    } else if (data.message) {
                        errorMsg += ': ' + data.message;
                    }
                    generateContextStatus.innerHTML = '<span style="color:red;">✗ ' + errorMsg + '</span>';
                }
            })
            .catch(function (error) {
                console.error('AJAX Error:', error);
                generateContextStatus.innerHTML = '<span style="color:red;">✗ Error generating context: ' + error.message + '</span>';
            })
            .finally(function () {
                generateContextBtn.disabled = false;
                
                // Clear status message after 5 seconds
                setTimeout(function () {
                    if (generateContextStatus.innerHTML.includes('✓')) {
                        generateContextStatus.innerHTML = '';
                    }
                }, 5000);
            });
        });
    }
});