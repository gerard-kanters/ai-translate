# Plan: slimme 404-herstelredirect op basis van gewijzigde vertaalde URL's

## Doel

Bij een echte 404 moet de plugin proberen te herkennen welke content de bezoeker waarschijnlijk bedoelde, en alleen dan een `301` redirect uitvoeren naar de canonieke URL. Het doel is om SEO-verlies en bezoekersuitval te beperken wanneer een vertaalde slug is veranderd.

Deze uitbreiding moet aansluiten op de bestaande URL-resolutie van de plugin, zonder extra rewrite-regels of brede "gok-redirects" die verkeerde pagina's kunnen tonen.

## Wat er al aanwezig is

De plugin heeft nu al meerdere lagen die URL's herstellen of normaliseren:

- Oude bron-slugs via `_wp_old_slug` worden al vroeg omgeleid in `parse_request` en ook nog in `template_redirect`.
- Taalgeprefixte URL's zoals `/{lang}/{slug}/` worden in `parse_request` via `AI_Slugs::resolve_path_to_post()` terugvertaald naar het juiste bericht of de juiste pagina.
- Er bestaat al fallback-logica voor:
  - exacte vertaalde slug-match
  - URL-decoding van slugs
  - prefix/truncated match
  - fallback naar bronslug
  - fallback naar `wp_posts.post_name`
- Als niets matcht, zet de plugin bewust een echte 404 op taal-URL's via `$wp->query_vars['error'] = '404'`.

Conclusie: de basis is er al. De nieuwe functionaliteit hoeft dus geen volledig nieuw routeringssysteem te worden, maar een extra, streng gecontroleerde herstelstap nádat de bestaande resolutie heeft gefaald.

## Complexiteit

Dit is niet "heel ingewikkeld", maar ook niet triviaal.

De reden:

- de plugin heeft al veel routinglogica; een extra redirectlaag moet daar precies op aansluiten
- een verkeerde redirect is erger dan een 404, zeker voor SEO en crawlgedrag
- de oplossing moet onderscheid maken tussen:
  - echte verouderde URL's
  - typo's
  - verkeerde taalprefixen
  - taxonomie-URL's
  - custom post type URL's

Praktisch ingeschat: **middelmatige complexiteit**. De moeilijkheid zit minder in de codegrootte en meer in de veiligheidsregels en correcte positionering in de WordPress request-flow.

## Gewenste functionele werking

### Hoofdregel

Alleen als de request na de bestaande plugin-resolutie en WordPress core-resolutie nog steeds een echte 404 is, mag een herstelpoging starten.

### Gewenst gedrag

1. Bezoeker vraagt een URL op die 404 geeft.
2. De plugin analyseert de URL:
   - taalprefix
   - padsegmenten
   - eventueel CPT-prefix
   - basename van de slug
3. De plugin zoekt kandidaten die sterk lijken op de gevraagde URL.
4. Alleen bij een voldoende hoge betrouwbaarheid:
   - redirect naar exact 1 canonieke doel-URL
   - met `301`
5. Bij twijfel:
   - geen redirect
   - normale 404 laten staan

## Waar dit in de request-flow moet komen

### Bestaande flow

De belangrijkste relevante punten zitten nu in:

- `add_filter('request', ...)`
- meerdere `add_action('parse_request', ...)`
- bestaande redirects in `template_redirect`

### Aanbevolen haakpunt

De nieuwe herstelredirect moet in een **nieuwe `template_redirect` hook** komen die:

- pas draait als de query al volledig is opgebouwd
- alleen werkt als `is_404()` echt waar is
- ná de bestaande parse-fase draait
- vóór template-rendering redirect kan doen

### Waarom niet in rewrite of vroege parse_request

Daar is de kans groter dat we:

- WordPress core-resolutie doorkruisen
- bestaande plugin-fallbacks dubbel uitvoeren
- te vroeg redirecten terwijl de URL later nog normaal opgelost zou worden

Kortom: pas ingrijpen wanneer vaststaat dat de request anders op een echte 404 uitkomt.

## Scope van de eerste versie

De eerste versie moet bewust beperkt blijven.

### Wel meenemen

- taalgeprefixte content-URL's: `/{lang}/{slug}/`
- taalgeprefixte CPT-URL's: `/{lang}/{post-type}/{slug}/`
- URL's waarvan de laatste slug is veranderd door vertaling
- URL's waarbij de oude slug nog duidelijk naar dezelfde content wijst

### Niet meenemen in v1

- zoekpagina's
- datumarchieven
- paginering
- media/attachments
- admin, REST, AJAX, feeds, XML, sitemap
- taxonomie-URL's tenzij er al een betrouwbare bestaande mapping aanwezig is

Dat houdt de eerste versie controleerbaar en voorkomt brede neveneffecten.

## Technisch ontwerp

## Stap 1: request normaliseren

Maak een interne flow die, op basis van `REQUEST_URI`, exact dezelfde normalisatie gebruikt als de rest van de plugin:

- `ai_translate_strip_site_path()`
- taalprefix uitlezen
- `%`-encoded slugs decoderen als UTF-8 geldig is
- trailing slash negeren voor matching

Belangrijk: geen alternatieve parsinglogica bouwen als de bestaande helpers al hetzelfde doen.

## Stap 2: harde uitsluitingen

Voer direct een `return` uit voor requests die niet in aanmerking komen:

- geen `is_404()`
- admin/AJAX/REST/feed/XML
- lege path of `/`
- taalcode niet geldig of niet relevant
- request met queryvarianten die functioneel iets anders betekenen
- requests naar bestanden of bekende technische endpoints

## Stap 3: kandidaatselectie opbouwen

De herstelstap moet niet blind zoeken in alle content, maar gecontroleerd kandidaten opbouwen.

### Kandidaatbron A: bestaande slug-map

Gebruik eerst de bestaande slug-infrastructuur als primaire bron:

- exacte match op oudere bekende vorm als die beschikbaar is
- match op basename van de huidige URL
- match op bronslug als vertaalde slug niet meer klopt

Hiervoor is uitbreiding in of rond `AI_Slugs` logisch, zolang bestaande functies hergebruikt worden en er geen parallelle slug-resolver ontstaat.

### Kandidaatbron B: WordPress oude slugs

Gebruik `_wp_old_slug` opnieuw, maar nu ook voor taal-URL's als de basename overeenkomt met een oude bronslug en het doel daarna netjes kan worden vertaald naar de canonieke taal-URL.

### Kandidaatbron C: gecontroleerde gelijkenis op slug

Alleen als A en B niets opleveren, een beperkte fuzzy stap:

- vergelijk alleen relevante slug-kandidaten binnen dezelfde taal
- vergelijk bij voorkeur alleen de laatste slugcomponent
- geef voorkeur aan content met:
  - dezelfde taal
  - hetzelfde CPT-prefix
  - vrijwel identieke slug
  - exact hetzelfde pad-diepteprofiel

### Kandidaatbron D: optionele redirect-historie

Voor een latere fase kan een aparte tabel of postmeta worden overwogen waarin oude vertaalde slugs worden bewaard zodra `translated_slug` verandert. Dat levert betrouwbaardere redirects op dan fuzzy matching.

Voor v1 is dit een uitbreidingsoptie, geen vereiste.

## Stap 4: scoremodel

Gebruik een eenvoudig en uitlegbaar scoremodel. Geen "best effort" redirect zonder drempel.

Voorbeeld van score-opbouw:

- `+100` exact bekende oude slug voor deze taal
- `+80` exacte match op oude bronslug plus geldige vertaalde canonieke URL
- `+60` zelfde taal en exact dezelfde basename, maar andere padcontext
- `+40` zelfde CPT-prefix en sterke slug-gelijkenis
- `-40` andere post type context
- `-50` meerdere bijna gelijke kandidaten
- `-100` attachment, draft, private of niet-publiceerbare content

Redirect alleen als:

- er precies 1 beste kandidaat is
- de score boven een vaste drempel ligt
- de runner-up voldoende lager scoort

Zo voorkom je redirects naar de verkeerde pagina.

## Stap 5: canonieke doel-URL bepalen

Na kandidaatkeuze nooit handmatig een URL in elkaar zetten als daar al bestaande logica voor is.

Voorkeursvolgorde:

1. `AI_SEO::get_translated_url($post_id, $lang)` als die voor frontend-canonieke URLs de bron van waarheid is
2. anders bestaande URL-rewrite helpers gebruiken
3. alleen als laatste redmiddel handmatig opbouwen, consistent met de pluginstructuur

Voorwaarden:

- doel-URL moet verschillen van huidige URL
- doel-URL mag geen 404 zijn
- doel-URL moet binnen dezelfde site vallen

## Stap 6: redirect-uitvoering

Alleen uitvoeren met:

- `nocache_headers()`
- `wp_safe_redirect($target, 301)`
- `exit`

Geen tijdelijke 302 in productiegedrag, omdat dit juist bedoeld is als structureel herstel van oude of gewijzigde URL's.

## Nodige codewijzigingen

## 1. Nieuwe resolver voor 404-herstel

Voeg een kleine, gerichte resolver toe die een 404-path vertaalt naar:

- `post_id`
- `confidence score`
- `redirect_url`
- `match_reason`

Advies: dit niet verspreiden over meerdere anonieme callbacks, maar in een bestaande class onderbrengen waar slug-resolutie al thuishoort, waarschijnlijk `AI_Slugs` of een kleine gerichte companion-class als uitbreiding echt niet netjes past.

Belangrijk: alleen een nieuwe functie toevoegen als hergebruik van bestaande functies onvoldoende is.

## 2. Nieuwe `template_redirect` callback

Deze callback:

- checkt of de request echt 404 is
- roept de resolver aan
- redirect alleen bij voldoende zekerheid

## 3. Optioneel: opslag van oude vertaalde slugs

Voor hoge betrouwbaarheid is dit inhoudelijk de beste uitbreiding:

- bij wijziging van een vertaalde slug oude waarde bewaren
- per `post_id + lang`
- die historie eerst raadplegen vóór fuzzy matching

Dit kan in:

- bestaande slug-tabel met extra historie-tabel
- of een aparte redirect-tabel

Voor een eerste oplevering hoeft dit niet direct, maar het is de beste route als de 404's echt vooral ontstaan door latere slug-wijzigingen.

## Veiligheidsregels

De volgende regels moeten hard zijn:

- nooit redirecten als meerdere kandidaten ongeveer even goed zijn
- nooit redirecten naar homepage als "fallback"
- nooit redirecten bij onbekende taal
- nooit redirecten naar concepten, revisions, attachments of niet-publieke content
- nooit redirecten buiten de eigen host
- geen redirectloop toestaan
- alleen redirecten naar canonieke plugin-URL's

## Performance-aanpak

De herstelstap draait alleen op 404's, dus de runtime-impact blijft beperkt. Toch moeten brede queries worden voorkomen.

Aanpak:

- eerst goedkope checks
- dan bestaande exact-match methoden
- pas daarna beperkte fuzzy lookup
- resultaten cachen per request-path + taal, bij voorkeur kortlevend

Geen zware scan over alle posts op iedere 404.

## SEO-implicaties

Deze feature is SEO-technisch zinvol, mits strikt uitgevoerd.

Positief:

- behoud van linkwaarde bij gewijzigde vertaalde slugs
- minder crawlverlies
- minder soft-dead-ends voor bezoekers

Risico:

- verkeerde redirect naar semantisch andere content
- kettingredirects als doel-URL niet canoniek is

Daarom moet de oplossing liever soms een 404 laten staan dan agressief gokken.

## Testplan

## Unit tests

Breid de bestaande tests rond `AI_Slugs` uit met gevallen zoals:

- oude vertaalde slug wijst naar juiste post
- bronslug op taal-URL wordt naar juiste vertaalde URL gestuurd
- meerdere vergelijkbare kandidaten geven geen redirect
- attachment wordt uitgesloten
- CPT-prefix mismatch wordt uitgesloten
- unicode slug blijft correct werken
- ongeldige taal geeft geen redirect

## Integratietests / handmatige verificatie

Minimaal testen:

1. pagina met gewijzigde vertaalde slug
2. bericht met gewijzigde vertaalde slug
3. CPT met gewijzigde vertaalde slug
4. onbekende typo-URL moet echte 404 blijven
5. oude bronslug zonder taal moet nog via bestaande `_wp_old_slug` werken
6. oude taal-URL moet naar actuele taal-URL gaan
7. doel-URL mag geen extra redirectketen veroorzaken

## Logging

Niet standaard toevoegen. Alleen als tijdelijke debughulp tijdens ontwikkeling en daarna weer verwijderen, conform projectregels.

## Gefaseerde uitvoering

### Fase 1

- alleen exact en zeer betrouwbare matches
- geen historie-opslag
- geen taxonomie-redirects

### Fase 2

- oude vertaalde slug-historie opslaan
- historie vóór fuzzy logic raadplegen

### Fase 3

- eventueel gecontroleerde taxonomie-ondersteuning als daar een aantoonbare behoefte voor is

## Aanbevolen implementatievolgorde

1. Bestaande 404- en slug-resolutiepaden inventariseren in code en voorbeelden verzamelen.
2. Bepalen welke bestaande functies direct herbruikbaar zijn.
3. Resolver voor 404-herstel bouwen met een streng scoremodel.
4. Nieuwe `template_redirect` callback toevoegen die alleen op echte 404's werkt.
5. Unit tests toevoegen voor positieve en negatieve scenario's.
6. Handmatig testen met echte oude en gewijzigde URL's.
7. Pas daarna beoordelen of slug-historie nodig is.

## Eindoordeel

Ja, dit is goed maakbaar binnen deze plugin.

De slimste route is niet om "bij iedere 404 te raden", maar om een **conservatieve 404-herstelstap** toe te voegen die:

- pas na mislukte normale resolutie draait
- bestaande slug- en URL-logica hergebruikt
- alleen redirect bij hoge zekerheid

Als de meeste 404's ontstaan doordat vertaalde slugs later veranderen, dan is de structureel beste oplossing uiteindelijk: **oude vertaalde slugs bewaren en die eerst raadplegen**. De 404-herstelredirect kan daarna fungeren als vangnet, niet als hoofdmechanisme.
