# Live Merging van Dynamische Elementen

## Related documentation

- See `docs/language-switching-and-caching.md` for URL handling, cookies, switcher behaviour and redirect caching (incognito issues).

## Probleemanalyse
De `ai-translate` plugin slaat momenteel de volledige HTML van een vertaalde pagina op in een statische cache. Dit leidt tot problemen bij elementen die afhankelijk zijn van de status van andere plugins of de gebruiker (zoals de Complianz cookie banner of de WordPress admin bar):

1.  **Missing Elements:** Als een plugin (zoals Complianz) uitstaat tijdens het genereren van de cache, ontbreekt de HTML/JS volledig in het cachebestand. Na het aanzetten van de plugin blijft de banner weg op gecachte pagina's.
2.  **Outdated States:** Wijzigingen in plugin-instellingen worden niet weerspiegeld in de cache.
3.  **Hoge Kosten:** De enige huidige oplossing is het wissen van de cache, wat leidt tot duizenden nieuwe API-calls naar de LLM.

## De Oplossing: Live Merging
In plaats van de cache als een onveranderlijk blok te zien, gebruiken we de live door WordPress gegenereerde HTML als bron voor specifieke "dynamische" onderdelen. Omdat `ai-translate` de WordPress locale al correct zet (bijv. op `de_DE`), genereert WordPress deze onderdelen live in de juiste taal.

### Procesflow in `AI_OB::callback`
Wanneer een pagina uit de cache wordt geserveerd, voeren we de volgende stappen uit:

1.  **Live Generatie:** WordPress genereert de volledige pagina (`$live_html`).
2.  **Extractie:** We extraheren specifieke selectors uit de `$live_html`:
    *   `#cmplz-cookiebanner-container` (De banner zelf)
    *   `#cmplz-manage-consent` (Het 'beheer' icoontje)
    *   Scripts met de tekst `complianz` (Configuratie en logica)
3.  **Cache Laden:** We laden de vertaalde HTML uit de cache (`$cached_html`).
4.  **Injectie:** 
    *   We verwijderen eventuele oude versies van deze selectors uit `$cached_html`.
    *   We voegen de verse onderdelen uit `$live_html` toe aan `$cached_html`.
5.  **Output:** De gecombineerde HTML wordt naar de browser gestuurd.

## Technische Implementatie

### 1. Protected Elements Configuratie
We introduceren een lijst met selectors die nooit in de cache moeten worden "bevroren":
*   `#cmplz-cookiebanner-container`
*   `#cmplz-manage-consent`
*   `#wpadminbar`

### 2. Afhandeling van Scripts
Complianz en andere plugins injecteren inline scripts (bijv. `var complianz = { ... };`). Deze moeten ook live uit de `$live_html` worden gehaald en in de `$cached_html` worden geplaatst om ervoor te zorgen dat de JS-logica altijd over de juiste instellingen beschikt.

## Voordelen
1.  **0 API Kosten:** Er worden geen nieuwe vertalingen aangevraagd. De live gegenereerde onderdelen zijn al in de juiste taal door de WP locale instelling.
2.  **Altijd Actueel:** Als Complianz wordt geüpdatet of de instellingen wijzigen, is dit direct zichtbaar.
3.  **Generiek:** Werkt voor alle plugins die elementen injecteren via hooks (`wp_footer`, `wp_head`).

## Risico's & Mitigatie
*   **Performance:** DOM-parsing op elke request.
    *   *Mitigatie:* We gebruiken snelle string-extractie voor scripts en beperken DOM-manipulatie tot alleen de noodzakelijke containers.
*   **Dubbele elementen:** Voorkomen dat er twee banners in de pagina komen.
    *   *Mitigatie:* De merging logica verwijdert eerst de betreffende selectors uit de gecachte HTML voordat de live versies worden toegevoegd.

