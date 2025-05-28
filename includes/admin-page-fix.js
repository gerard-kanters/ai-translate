// Dit is een tijdelijk bestand met de gecorrigeerde JavaScript code
document.addEventListener('DOMContentLoaded', function() {
    // Update tab links om de tab-parameter toe te voegen
    var tabLinks = document.querySelectorAll('.nav-tab-wrapper a');
    tabLinks.forEach(function(link) {
        var tab = link.getAttribute('href').replace('#', '');
        link.href = admin_url + '&tab=' + tab;
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
    
    // Cache per taal functionaliteit
    // Veilig ophalen van de elementen, controleer of ze bestaan
    var langSelect = document.getElementById('cache_language');
    var langCountSpan = document.getElementById('selected-lang-count');

    // Snelle cache wissen knoppen in de tabel
    var quickClearButtons = document.querySelectorAll('.quick-clear-cache');
    if (quickClearButtons.length > 0) {
        quickClearButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation(); // Voorkom dat de rij-click ook getriggerd wordt

                var langCode = button.getAttribute('data-lang');
                if (!langCode) return;

                // Veilig controleren of langSelect bestaat en opties heeft
                if (langSelect && langSelect.options) {
                    // Selecteer de juiste taal in de dropdown
                    for (var i = 0; i < langSelect.options.length; i++) {
                        if (langSelect.options[i].value === langCode) {
                            langSelect.selectedIndex = i;
                            break;
                        }
                    }
                }

                // Haal nonce veilig op
                var nonceField = document.querySelector('input[name="clear_cache_language_nonce"]');
                if (!nonceField) {
                    // Probeer andere mogelijke namen voor het nonce veld
                    nonceField = document.querySelector('input[name="_wpnonce"]');
                    if (!nonceField) {
                        console.error('Nonce veld niet gevonden');
                        alert('Beveiligingstoken niet gevonden. Vernieuw de pagina en probeer het opnieuw.');
                        return;
                    }
                }
                var nonce = nonceField.value;

                // Voeg een loading indicator toe aan de knop
                var originalText = button.textContent;
                button.textContent = 'Bezig...';
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
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Server response niet ok: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function(data) {
                        console.log('AJAX response:', data); // Debug output
                        
                        if (data.success) {
                            // Update de UI
                            updateCacheUI(langCode);
                            
                            // Toon een succes bericht
                            var noticeDiv = document.createElement('div');
                            noticeDiv.className = 'notice notice-success is-dismissible';
                            
                            // Veilig benaderen van message property
                            var message = '';
                            if (data.data && typeof data.data.message === 'string') {
                                message = data.data.message;
                            } else if (typeof data.message === 'string') {
                                message = data.message;
                            } else {
                                message = 'Cache succesvol gewist';
                            }
                            
                            noticeDiv.innerHTML = '<p>' + message + '</p>';
                            
                            // Voeg de notice toe bovenaan de tab
                            var cacheTab = document.getElementById('cache');
                            if (cacheTab) {
                                cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);
                                
                                // Na 5 seconden automatisch verwijderen
                                setTimeout(function() {
                                    noticeDiv.style.transition = 'opacity 1s ease-out';
                                    noticeDiv.style.opacity = 0;
                                    setTimeout(function() {
                                        noticeDiv.remove();
                                    }, 1000);
                                }, 5000);
                            }
                            
                            // Herlaad de pagina na een korte pauze om de statistieken bij te werken
                            setTimeout(function() {
                                window.location.reload();
                            }, 2000);
                        } else {
                            // Toon een foutmelding
                            var errorMsg = 'Fout bij wissen van cache';
                            
                            if (data.data && data.data.message) {
                                errorMsg += ': ' + data.data.message;
                            } else if (data.message) {
                                errorMsg += ': ' + data.message;
                            }
                            
                            alert(errorMsg);
                            
                            // Reset de knop
                            button.textContent = originalText;
                            button.disabled = false;
                        }
                    })
                    .catch(function(error) {
                        console.error('AJAX Error:', error);
                        alert('Er is een fout opgetreden bij het wissen van de cache: ' + error.message);
                        
                        // Reset de knop
                        button.textContent = originalText;
                        button.disabled = false;
                    });
            });
        });
    }

    // Maak tabelrijen klikbaar om een taal te selecteren
    var cacheTableRows = document.querySelectorAll('tr[id^="cache-row-"]');
    cacheTableRows.forEach(function(row) {
        row.style.cursor = 'pointer';
        row.setAttribute('title', 'Klik om deze taal te selecteren');
        row.addEventListener('click', function(e) {
            // Voorkom dat rij klikbaar is als op een knop is geklikt
            if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                return;
            }
            
            var langCode = row.id.replace('cache-row-', '');
            
            // Veilig controleren of langSelect bestaat en opties heeft
            if (langSelect && langSelect.options) {
                // Vind en selecteer de bijbehorende optie in de dropdown
                for (var i = 0; i < langSelect.options.length; i++) {
                    if (langSelect.options[i].value === langCode) {
                        langSelect.selectedIndex = i;
                        // Trigger een change event zodat de UI wordt bijgewerkt
                        var changeEvent = new Event('change');
                        langSelect.dispatchEvent(changeEvent);
                        break;
                    }
                }
            }
        });
    });

    // Controleer eerst of de elementen bestaan
    if (langSelect && langCountSpan) {
        // Update count display when dropdown changes
        langSelect.addEventListener('change', function() {
            var selectedOption = langSelect.options[langSelect.selectedIndex];
            var count = selectedOption.getAttribute('data-count');
            langCountSpan.textContent = count + ' bestanden in cache';
        });
        
        // Trigger initial update
        var initialOption = langSelect.options[langSelect.selectedIndex];
        if (initialOption) {
            var initialCount = initialOption.getAttribute('data-count');
            langCountSpan.textContent = initialCount + ' bestanden in cache';
        }
    }

    /**
     * Updates the UI elements after cache clearing
     * @param {string} langCode - The language code that was cleared
     */
    function updateCacheUI(langCode) {
        try {
            console.log('UI bijwerken voor taal:', langCode);
            
            // Controleer of de nodige variabelen beschikbaar zijn
            var langSelect = document.getElementById('cache_language');
            
            // Update the dropdown option for this language
            var selectedOption = null;
            
            // Veilig controleren of langSelect bestaat en opties heeft
            if (langSelect && langSelect.options) {
                for (var i = 0; i < langSelect.options.length; i++) {
                    if (langSelect.options[i].value === langCode) {
                        selectedOption = langSelect.options[i];
                        break;
                    }
                }
                
                if (selectedOption) {
                    selectedOption.setAttribute('data-count', '0');
                    var langName = selectedOption.textContent.split('(')[0].trim();
                    selectedOption.textContent = langName + ' (0 bestanden)';
                }
            }

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
                        actionCell.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="Geen cache bestanden"></span>';
                    }
                    
                    // Highlight the row
                    row.style.backgroundColor = '#e7f7ed';
                    setTimeout(function() {
                        row.style.transition = 'background-color 1s ease-in-out';
                        row.style.backgroundColor = '';
                    }, 1500);
                } else {
                    console.warn('Count element niet gevonden voor taal:', langCode);
                }
            } else {
                console.warn('Cache rij niet gevonden voor taal:', langCode);
            }
        } catch (error) {
            console.error('Fout in updateCacheUI:', error);
        }
    }

    // Handle form submission to update count to zero
    var cacheForm = document.getElementById('clear-cache-language-form');
    if (cacheForm) {
        cacheForm.addEventListener('submit', function() {
            // Controleer of langSelect bestaat en opties heeft
            if (langSelect && langSelect.options) {
                // Haal de geselecteerde taalcode op
                var selectedOption = langSelect.options[langSelect.selectedIndex];
                if (selectedOption) {
                    var langCode = selectedOption.value;
                    updateCacheUI(langCode);
                }
            }
        });
    }

    // Validate API fields before form submission
    var generalForm = document.querySelector('#general form');
    if (generalForm) {
        generalForm.addEventListener('submit', function(e) {
            var apiUrl = document.querySelector('input[name="ai_translate_settings[api_url]"]');
            var apiKey = document.querySelector('input[name="ai_translate_settings[api_key]"]');
            
            // Remove existing error messages
            var existingErrors = document.querySelectorAll('.aitranslate-error');
            existingErrors.forEach(function(error) {
                error.remove();
            });
            
            // Versoepeld: alleen waarschuwing tonen, niet blokkeren
            if (!apiUrl.value.trim() || !apiKey.value.trim()) {
                var errorMsg = document.createElement('div');
                errorMsg.className = 'error notice aitranslate-error';
                errorMsg.innerHTML = '<p>Let op: Vul zowel API URL als API Key in om vertaalfunctionaliteit te gebruiken.</p>';
                document.querySelector('#general').insertBefore(errorMsg, document.querySelector('#general form'));
                // Niet meer: e.preventDefault();
            }
        });
    }
});
