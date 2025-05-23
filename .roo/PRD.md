**Product Requirements Document: AI Translate WordPress Plugin**

**1. Introductie & Doel**

*   **Product:** AI Translate
*   **Doel:** Een WordPress plugin die website content automatisch vertaalt naar meerdere talen met behulp van een externe AI API (standaard OpenAI). De plugin biedt beheerders controle over beschikbare talen, caching en URL-structuur, en toont een language switcher aan bezoekers.

**2. Gebruikers**

*   **Website Beheerder (Admin):** Configureert de plugin-instellingen, beheert talen, API-sleutels, caching en bekijkt logs.
*   **Website Bezoeker:** Kan de website in verschillende talen bekijken via een language switcher of door automatische detectie op basis van browser voorkeur.

**3. Functionele Requirements**

*   **3.1. Automatische Vertaling:**
    *   Vertaalt post/pagina titels, content, excerpts, widget titels, widget content, site titel, site tagline en menu-items.
    *   Maakt gebruik van een configureerbare externe API (standaard OpenAI `chat/completions` endpoint).
    *   Ondersteunt selectie tussen verschillende AI-modellen (standaard GPT-4, GPT-3.5 Turbo) en een optie voor een handmatig ingevoerd model.
    *   Voegt een `<!--aitranslate:translated-->` marker toe aan vertaalde content om her-vertaling te voorkomen.
    *   Verwijdert de marker uit de uiteindelijke output voor SEO-velden (Yoast, Rank Math, SEOPress, Jetpack) en standaard WordPress velden (`the_excerpt`, `get_the_excerpt`, `get_bloginfo`).
*   **3.2. Taalbeheer:**
    *   Instellen van een standaardtaal voor de website.
    *   Selecteren van "Enabled Languages": talen die zichtbaar zijn in de language switcher.
    *   Selecteren van "Detectable Languages": talen die niet in de switcher staan, maar waar de site automatisch naar vertaald wordt als de browser van de bezoeker overeenkomt.
*   **3.3. URL Structuur:**
    *   Gebruikt een pad-gebaseerde taalprefix voor niet-standaard talen (bijv. `/en/pagina-naam/`).
    *   Past interne links (menu-items, content links, permalinks, home URL) aan om de correcte taalprefix te bevatten.
*   **3.4. Language Switcher (Frontend):**
    *   Toont een vlag-icoon (standaard linksonder) met de huidige taal.
    *   Bij klikken opent een popup met de "Enabled Languages" (vlag + naam).
    *   Linkt naar de corresponderende taalversie van de huidige pagina.
*   **3.5. Caching:**
    *   Implementeert memory, transient, en disk caching om API-calls te minimaliseren.
    *   Cache is gebaseerd op content hash en doeltaal.
    *   Configureerbare cache duur.
*   **3.7. Meta Description Vertaling:**
    *   Vertaalt de site tagline of een specifieke homepage meta description.
    *   Integreert met populaire SEO plugins voor correcte output.
*      **3.9. Admin Interface:**
    *   Biedt instellingen voor API, talen, caching, en geavanceerde opties (meta description).
    *   Biedt tools voor het legen van caches (alle, transient, per taal) en logs.
*   **3.10. Automatische Taal Detectie:**
    *   Detecteert browser taal van de bezoeker.
    *   Indien de gedetecteerde taal overeenkomt met een "Detectable Language" (en niet de default is), wordt de bezoeker geredirect naar de URL met taalprefix en wordt een taalcookie gezet.

**4. Technische Requirements**

*   **4.1. Platform & Technologie:**
    *   WordPress Plugin.
    *   PHP (strict_types), JavaScript (jQuery), CSS.
*   **4.2. WordPress Integratie:**
    *   Gebruikt WordPress haken (actions/filters) voor content modificatie, script enqueuing, rewrite rules, admin menu/pagina's.
    *   Gebruikt Settings API voor admin instellingen.
    *   Gebruikt Transients API voor database caching.
    *   Gebruikt WP Filesystem API (impliciet via `wp_upload_dir`, `file_put_contents`, `file_exists`, etc.) voor disk cache en logs.
    *   Gebruikt `wp_remote_request` voor externe API calls.
*   **4.3. API Integratie:**
    *   Interactie met OpenAI Chat Completions API (of configureerbaar alternatief).
    *   Verstuurt content met een system prompt voor vertaling.
    *   Behandelt API responses, inclusief foutafhandeling (rate limits, server errors) en backoff mechanisme.
*   **4.4. Caching Mechanisme:**
    *   Memory cache (statische PHP array) per request.
    *   Transient cache (WordPress database) met vervaltijd.
    *   Disk cache (bestanden in `uploads` map) met vervaltijd.
*   **4.5. URL Herschrijven:**
    *   Gebruikt WordPress Rewrite API (`add_rewrite_rule`, `flush_rewrite_rules`) om taalprefix URL's te mappen naar WordPress query vars (`lang`).
*   **4.6. Code Kwaliteit & Standaarden:**
    *   Volgt WordPress Coding Standards.
    *   Gebruikt PHP namespaces.
    *   Inclusief DocBlocks voor klassen en methoden.
    *   Singleton pattern voor de core class.
*   **4.7. Veiligheid:**
    *   Input sanitization voor alle gebruikersinvoer en opties.
    *   Gebruik van nonces voor admin formulierinzendingen.
    *   Escaping van output (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`).
*   **4.8. Structuur:**
    *   Logische scheiding van functionaliteit: hoofd-plugin bestand, admin interface bestand, core logica klasse, assets.
