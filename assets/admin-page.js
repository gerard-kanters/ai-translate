document.addEventListener('DOMContentLoaded', function() {
    var tabLinks = document.querySelectorAll('.nav-tab-wrapper a');
    tabLinks.forEach(function(link) {
        var tab = link.getAttribute('href').split('&tab=')[1];
        if (tab) {
            link.href = aiTranslateAdmin.adminUrl + '&tab=' + tab;
        }
    });

    // Show/hide custom model text field based on selected value
    var selectedModel = document.getElementById('selected_model');
    var customModelDiv = document.getElementById('custom_model_div');
    if (selectedModel) {
        selectedModel.addEventListener('change', function() {
            if (this.value === 'custom') {
                customModelDiv.style.display = 'block';
            } else {
                customModelDiv.style.display = 'none';
            }
        });
    }
    
    // API Validation functionality
    var apiStatusSpan = document.getElementById('ai-translate-api-status');
    var validateApiBtn = document.getElementById('ai-translate-validate-api');
    var apiKeyInput = document.querySelector('input[name="ai_translate_settings[api_key]"]');
    var customModelInput = document.querySelector('input[name="ai_translate_settings[custom_model]"]');
    var apiProviderSelect = document.getElementById('api_provider_select');
    var apiKeyRequestLinkSpan = document.getElementById('api-key-request-link-span');
    var customApiUrlDiv = document.getElementById('custom_api_url_div');
    var customApiUrlInput = document.querySelector('input[name="ai_translate_settings[custom_api_url]"]');

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
        'custom': {
            name: 'Custom URL',
            url: '',
            key_link: ''
        }
    };

    function updateApiKeyRequestLink() {
        if (apiProviderSelect && apiKeyRequestLinkSpan) {
            var selectedProviderKey = apiProviderSelect.value;
            var providerInfo = apiProvidersData[selectedProviderKey];
            if (providerInfo && providerInfo.key_link) {
                apiKeyRequestLinkSpan.innerHTML = '<a href="' + providerInfo.key_link + '" target="_blank">Request Key</a>';
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

    if (apiProviderSelect) {
        apiProviderSelect.addEventListener('change', updateApiKeyRequestLink);
        apiProviderSelect.addEventListener('change', toggleCustomApiUrlField);
        updateApiKeyRequestLink();
        toggleCustomApiUrlField();
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
        var apiKey = apiKeyInput ? apiKeyInput.value : '';

        if (!apiUrl || !apiKey) {
            if (apiStatusSpan) apiStatusSpan.textContent = 'Selecteer API Provider en vul API Key in.';
            return;
        }

        if (apiStatusSpan) apiStatusSpan.textContent = 'Load models ...';

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
        .then(function(resp) {
            if (selectedModel) {
                if (resp.success && resp.data && resp.data.models) {
                    var current = selectedModel.value;
                    selectedModel.innerHTML = '';
                    resp.data.models.forEach(function(modelId) {
                        var opt = document.createElement('option');
                        opt.value = modelId;
                        opt.textContent = modelId;
                        if (modelId === current) opt.selected = true;
                        selectedModel.appendChild(opt);
                    });
                    
                    var customOpt = document.createElement('option');
                    customOpt.value = 'custom';
                    customOpt.textContent = 'Select...';
                    if (current === 'custom') customOpt.selected = true;
                    selectedModel.appendChild(customOpt);
                    if (apiStatusSpan) apiStatusSpan.textContent = 'Modellen succesvol geladen.';
                } else {
                    if (apiStatusSpan) apiStatusSpan.textContent = 'Geen modellen gevonden: ' + (resp.data && resp.data.message ? resp.data.message : 'Onbekende fout');
                }
            }
        })
        .catch(function(e) {
            if (apiStatusSpan) apiStatusSpan.textContent = 'Fout bij laden modellen: ' + e.message;
        });
    }

    if (selectedModel) {
        selectedModel.addEventListener('focus', loadModels);
        selectedModel.addEventListener('change', function() {
            if (customModelDiv) {
                customModelDiv.style.display = (this.value === 'custom') ? 'block' : 'none';
            }
        });
        
        if (customModelDiv) {
            customModelDiv.style.display = (selectedModel.value === 'custom') ? 'block' : 'none';
        }
    }

    if (validateApiBtn) {
        validateApiBtn.addEventListener('click', function() {
            if (apiStatusSpan) apiStatusSpan.innerHTML = 'Valideren...';
            
            var apiUrl = getSelectedApiUrl();
            var apiKey = apiKeyInput ? apiKeyInput.value : '';
            var modelId = selectedModel ? selectedModel.value : '';

            if (modelId === 'custom') {
                modelId = customModelInput ? customModelInput.value : '';
            }

            if (!apiUrl || !apiKey || !modelId) {
                if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Selecteer API Provider, vul API Key in en selecteer een model.</span>';
                return;
            }

            validateApiBtn.disabled = true;

            var data = new FormData();
            data.append('action', 'ai_translate_validate_api');
            data.append('nonce', aiTranslateAdmin.validateApiNonce);
            data.append('api_url', apiUrl);
            data.append('api_key', apiKey);
            data.append('model', modelId);
            
            if (apiProviderSelect) data.append('api_provider', apiProviderSelect.value);
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
            .then(function(resp) {
                if (resp.success) {
                    if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:green;font-weight:bold;">&#10003; Connectie en model OK. API instellingen opgeslagen.</span>';
                } else {
                    if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; ' +
                        (resp.data && resp.data.message ? resp.data.message : 'Fout') + '</span>';
                }
            })
            .catch(function(error) {
                if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Validatie AJAX Fout: ' + error.message + '</span>';
            })
            .finally(function() {
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
            console.log('Updating UI for language:', langCode);

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
                    setTimeout(function() {
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
        quickClearButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
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

                        setTimeout(function() {
                            noticeDiv.style.transition = 'opacity 1s ease-out';
                            noticeDiv.style.opacity = 0;
                            setTimeout(function() {
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
                            throw new Error('Server response niet ok: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function(data) {
                        console.log('AJAX response:', data);

                        if (data.success) {
                            var noticeDiv = document.createElement('div');
                            noticeDiv.className = 'notice notice-success is-dismissible';
                            noticeDiv.innerHTML = '<p>' + data.data.message + '</p>';

                            var cacheTab = document.getElementById('cache');
                            if (cacheTab) {
                                cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                                setTimeout(function() {
                                    noticeDiv.style.transition = 'opacity 1s ease-out';
                                    noticeDiv.style.opacity = 0;
                                    setTimeout(function() {
                                        noticeDiv.remove();
                                    }, 1000);
                                }, 5000);
                            }

                            updateCacheUI(langCode);
                            setTimeout(function() {
                                window.location.reload();
                            }, 3000);
                        } else {
                            var errorMsg = 'Fout bij wissen van cache';

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

                                setTimeout(function() {
                                    noticeDiv.style.transition = 'opacity 1s ease-out';
                                    noticeDiv.style.opacity = 0;
                                    setTimeout(function() {
                                        noticeDiv.remove();
                                    }, 1000);
                                }, 5000);
                            }

                            button.textContent = originalText;
                            button.disabled = false;
                        }
                    })
                    .catch(function(error) {
                        console.error('AJAX Error:', error);
                        
                        var noticeDiv = document.createElement('div');
                        noticeDiv.className = 'notice notice-error is-dismissible';
                        noticeDiv.innerHTML = '<p>Er is een fout opgetreden bij het wissen van de cache: ' + error.message + '</p>';

                        var cacheTab = document.getElementById('cache');
                        if (cacheTab) {
                            cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                            setTimeout(function() {
                                noticeDiv.style.transition = 'opacity 1s ease-out';
                                noticeDiv.style.opacity = 0;
                                setTimeout(function() {
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
    cacheTableRows.forEach(function(row) {
        row.style.cursor = 'pointer';
        row.setAttribute('title', 'Klik om deze taal te selecteren');
        row.addEventListener('click', function(e) {
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
        langSelect.addEventListener('change', function() {
            if (langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
                var selectedOption = langSelect.options[langSelect.selectedIndex];
                if (selectedOption) {
                    var count = selectedOption.getAttribute('data-count');
                    langCountSpan.textContent = count + ' bestanden in cache';
                }
            }
        });

        if (langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
            var initialOption = langSelect.options[langSelect.selectedIndex];
            if (initialOption) {
                var initialCount = initialOption.getAttribute('data-count');
                langCountSpan.textContent = initialCount + ' bestanden in cache';
            }
        }

        var cacheForm = document.getElementById('clear-cache-language-form');
        if (cacheForm) {
            cacheForm.addEventListener('submit', function() {
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
        generalForm.addEventListener('submit', function(e) {
            var apiUrl = document.querySelector('input[name="ai_translate_settings[api_url]"]');
            var apiKey = document.querySelector('input[name="ai_translate_settings[api_key]"]');
            
            var existingErrors = document.querySelectorAll('.aitranslate-error');
            existingErrors.forEach(function(error) {
                error.remove();
            });
            
            if (!apiUrl.value.trim() || !apiKey.value.trim()) {
                var errorMsg = document.createElement('div');
                errorMsg.className = 'error notice aitranslate-error';
                errorMsg.innerHTML = '<p>Let op: Vul zowel API URL als API Key in om vertaalfunctionaliteit te gebruiken.</p>';
                document.querySelector('#general').insertBefore(errorMsg, document.querySelector('#general form'));
            }
        });
    }
});