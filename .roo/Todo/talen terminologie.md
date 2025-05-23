# Taal Terminologie en Refactoring Plan

Dit document beschrijft de gestandaardiseerde terminologie voor talen binnen de AI Translate plugin en de benodigde refactoring om deze consistent toe te passen.

## Definities

1.  **`available_languages`**:
    *   **Wat:** Een `array<string, string>` met **alle** talen die de plugin technisch ondersteunt (key = code, value = naam).
    *   **Bron:** Hardcoded in `AI_Translate_Core::get_available_languages()`.
    *   **Gebruik:**
        *   Basis voor alle taalvalidatie.
        *   Genereren van rewrite rules (`add_language_rewrite_rules` in `ai-translate.php`).
        *   Valideren van taalcode uit URL/cookie in `AI_Translate_Core::get_current_language()`.
        *   Valideren van doeltaal in `AI_Translate_Core::translate_url()`.
        *   Populeren van de selectielijsten in de admin (`admin-page.php`).

2.  **`selected_languages`** (Voorheen vaak `$enabled_languages` genoemd):
    *   **Wat:** Een `array<string>` met de taalcodes die de gebruiker **actief heeft geselecteerd** in de admin-instellingen om te tonen in de language switcher en eventueel voor andere UI-elementen.
    *   **Bron:** Opgeslagen in de `ai_translate_settings` optie, specifiek de `enabled_languages` key.
    *   **Gebruik:**
        *   Bepalen welke talen getoond worden in de language switcher (`AI_Translate_Core::display_language_switcher()`).
        *   (Optioneel) Kan gebruikt worden voor UI-specifieke logica, maar **niet** voor core functionaliteit zoals URL-validatie of vertaling.

3.  **`detectable_languages`**:
    *   **Wat:** Een `array<string>` met taalcodes die gebruikt mogen worden voor automatische taalherkenning via de browser (`Accept-Language` header).
    *   **Bron:** Opgeslagen in de `ai_translate_settings` optie, specifiek de `detectable_languages` key.
    *   **Gebruik:**
        *   Alleen binnen `AI_Translate_Core::get_current_language()` voor de browserdetectie-stap.

4.  **`default_language`**:
    *   **Wat:** Een `string` met de taalcode van de standaardtaal van de site.
    *   **Bron:** Opgeslagen in de `ai_translate_settings` optie, specifiek de `default_language` key.
    *   **Gebruik:**
        *   Fallback taal.
        *   Bron taal voor vertalingen.
        *   Bepalen of een taalprefix nodig is in de URL.

## Refactoring Plan

De volgende bestanden en functies moeten worden nagelopen en aangepast om de bovenstaande terminologie consistent te gebruiken:

**1. `ai-translate.php`**

*   `add_language_rewrite_rules()`:
    *   Hernoem lokale variabele `$enabled_languages` naar `$available_languages_codes` (of iets vergelijkbaars) om duidelijk te maken dat het om *alle* beschikbare talen gaat.
    *   Gebruik `$core->get_available_languages()` correct. (Lijkt al correct te zijn na laatste wijziging).
*   `fix_redirect_loops()`:
    *   Vervang check op `$settings['enabled_languages']` door een check op `array_keys($core->get_available_languages())` als het doel is om loops voor *elke* geldige taalprefix te voorkomen.
*   `register_rewrite_rules()` (Indien nog in gebruik, lijkt redundant met `add_language_rewrite_rules`):
    *   Pas aan om `get_available_languages()` te gebruiken i.p.v. `$settings['enabled_languages']`.
*   `set_language_cookie()`:
    *   De check `!in_array($language_code, $settings['enabled_languages'])` moet waarschijnlijk `!array_key_exists($language_code, $core->get_available_languages())` worden om te valideren tegen *alle* beschikbare talen.
*   Alle andere plekken waar `$settings['enabled_languages']` wordt gebruikt: controleer of het echt om de *geselecteerde* talen moet gaan (waarschijnlijk alleen UI-gerelateerd) of om *alle beschikbare* talen (voor validatie/core logica).

**2. `includes/class-ai-translate-core.php`**

*   `get_current_language()`:
    *   Hernoem lokale variabele `$enabled_languages` naar `$selected_languages` om de intentie (gebruikersselectie) te verduidelijken.
    *   Controleer of de logica correct `available_languages` gebruikt voor URL/cookie validatie en `detectable_languages` voor browserdetectie. (Lijkt al correct).
*   `display_language_switcher()`:
    *   Zorg ervoor dat deze functie `$settings['enabled_languages']` (dus de *geselecteerde* talen) gebruikt om te bepalen welke vlaggen/links getoond worden.
*   `translate_url()`:
    *   Controleer of de validatie aan het begin `$this->get_available_languages()` gebruikt. (Lijkt al correct).
*   Overige functies: Zoek naar `$this->settings['enabled_languages']` en bepaal per geval of dit moet verwijzen naar de *geselecteerde* talen of *alle beschikbare* talen.

**3. `includes/admin-page.php`**

*   Zorg ervoor dat de labels en helpteksten voor de instellingenvelden duidelijk het onderscheid maken tussen "Selecteerbare Talen (voor Switcher)" (`enabled_languages`) en "Detecteerbare Talen (Browser)" (`detectable_languages`).
*   De dropdowns/checkboxes voor het selecteren van talen moeten populeren met `AI_Translate_Core::get_instance()->get_available_languages()`.

## Volgende Stappen

1.  Voer de refactoring door in de genoemde bestanden.
2.  Hernoem variabelen consistent (bv. `$enabled_languages` -> `$selected_languages` waar van toepassing).
3.  Test grondig:
    *   Werken alle taal-URL's (/en/, /pl/, etc.)?
    *   Toont de switcher alleen de *geselecteerde* talen?
    *   Werkt browserdetectie correct met *detecteerbare* talen?
    *   Worden instellingen correct opgeslagen en geladen?
4.  Draai Intelephense om 0 errors/warnings te garanderen.
5.  Flush rewrite rules na wijzigingen in `ai-translate.php`.