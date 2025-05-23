# Analyse Contact Form 7 AJAX Vertaalprobleem

## Probleemomschrijving

Contact Form 7 (CF7) formulieren werken niet correct wanneer een andere taal dan de standaardtaal is geselecteerd via de AI Translate plugin. Het laad-icoon blijft draaien na het verzenden, wat duidt op een ongeldige JSON-respons van de server. Dit gebeurt alleen in vertaalde talen.

## Bevindingen

1.  **Kernoorzaak:** De JSON-respons die CF7 via AJAX terugstuurt, wordt (gedeeltelijk) onbedoeld verwerkt door de vertaalfunctie (`AI_Translate_Core::translate_text`) van de AI Translate plugin.
2.  **Gevolg:** De JSON-structuur raakt beschadigd of bevat onverwachte (vertaalde) inhoud, waardoor de JavaScript-handler van CF7 de respons niet correct kan verwerken.
3.  **Context:** Het probleem treedt specifiek op bij AJAX-requests van CF7 in een niet-standaard taalcontext.

## Wat Werkte Niet / Wat Niet Te Doen

Diverse pogingen zijn ondernomen zonder succes. Deze benaderingen moeten in de toekomst vermeden worden of met grote voorzichtigheid worden benaderd:

1.  **Marker Toevoegen via `wpcf7_ajax_json_echo`:**
    *   Pogingen om de JSON-respons te markeren (zowel met `TRANSLATION_MARKER` als custom markers) binnen deze filter faalden.
    *   **Reden:** CF7 encodeert de *geretourneerde PHP array* opnieuw naar JSON, waardoor markers die aan een *tijdelijke JSON-string* binnen de filter zijn toegevoegd, verloren gaan.
2.  **Algemene AJAX Check in `translate_text` (`wp_doing_ajax()`):**
    *   Het toevoegen van `if (wp_doing_ajax()) { return $text; }` aan het begin van `translate_text` (zelfs als allereerste check) loste het probleem niet op.
    *   **Mogelijke Redenen:** Timing, scope (wordt de *juiste* string wel door `translate_text` gehaald?), onverwachte output buffering, of `gettext` filterinterferentie.
3.  **Specifieke CF7 AJAX Check in `translate_text` (`isset($_REQUEST['_wpcf7'])`):**
    *   Faalde om dezelfde redenen als de algemene AJAX check.
4.  **Filters Verwijderen (`remove_all_filters`) tijdens AJAX:**
    *   Te grof, risico op nevenschade aan andere AJAX-functionaliteit.
    *   Lostte het kernprobleem niet op.
5.  **Output Buffering Leegmaken (`ob_end_clean`) in `init`:**
    *   Een poging om onverwachte output buffers te neutraliseren tijdens AJAX had geen effect.

## Aanpak Hoe Verder (Systematisch Debuggen)

Wanneer dit probleem opnieuw wordt opgepakt, volg dan deze stappen:

1.  **Verificatie `wp_doing_ajax()` in `translate_text`:**
    *   **Actie:** Zorg dat `if (wp_doing_ajax()) { return $text; }` de *absolute eerste* regel code is binnen de `translate_text` methode in `class-ai-translate-core.php`.
    *   **Actie:** Voeg expliciete, opvallende logging *binnen* deze `if`-conditie toe (bijv. met level 'error' of 'warning') om te bevestigen dat deze wordt uitgevoerd tijdens een CF7 AJAX-submit in een vertaalde taal.
    *   **Doel:** Definitief vaststellen of AJAX-requests correct worden gedetecteerd op het juiste punt in de vertaalfunctie. Als de log niet verschijnt, is er een fundamenteler probleem.
2.  **Analyse JSON-respons:**
    *   **Actie:** Gebruik browser developer tools (Network tab). Inspecteer de *exacte* JSON-respons van `admin-ajax.php` voor een CF7-submit in:
        *   De standaardtaal.
        *   Een vertaalde taal.
    *   **Actie:** Vergelijk de inhoud en structuur nauwkeurig. Welke velden (`status`, `message`, `into`, etc.) zijn anders? Wordt HTML of de `TRANSLATION_MARKER` onbedoeld toegevoegd?
    *   **Doel:** Precies identificeren welk deel van de respons corrupt raakt.
3.  **`gettext` Filters Uitsluiten:**
    *   **Doel:** Uitsluiten dat vertalingen via het `gettext`-mechanisme (gebruikt door `__`, `_e` etc.) het probleem veroorzaken.
4.  **Conflict Testen (Standaard Procedure):**
    *   **Actie:** Deactiveer alle plugins behalve AI Translate en Contact Form 7.
    *   **Actie:** Schakel over naar een standaard WordPress-thema (bv. Twenty Twenty-Four).
    *   **Actie:** Test het CF7-formulier opnieuw in een vertaalde taal.
    *   **Doel:** Uitsluiten van conflicten met andere plugins of het thema. Als het werkt, reactiveer één voor één om de boosdoener te vinden.
5.  **Diepere CF7 Hooks Analyse:**
    *   **Actie:** Bestudeer de Contact Form 7 code en documentatie voor hooks die de AJAX-respons kunnen beïnvloeden *naast* `wpcf7_ajax_json_echo`. Denk aan `wpcf7_feedback_response`, `wpcf7_mail_sent`, `wpcf7_submit`.
    *   **Doel:** Identificeren of een andere CF7-hook interfereert met de respons op een onverwachte manier.
6.  **Debugging `translate_text` Input:**
    *   **Actie:** Log de `$text` parameter aan het *begin* van de `translate_text` functie tijdens een CF7 AJAX request.
    *   **Doel:** Vaststellen of de problematische strings (bijv. de `message` uit de JSON) überhaupt door `translate_text` worden verwerkt. Zo niet, dan ligt de oorzaak buiten deze functie.

Door deze stappen systematisch te doorlopen, moet de precieze oorzaak van het falen van de AJAX-check of de bron van de ongewenste vertaling geïdentificeerd kunnen worden.