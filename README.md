=== AI Translate ===
Contributors: gkanters  
Tags: translation, artificial intelligence, seo, translate, ai translate  
Requires at least: 5.0  
Tested up to: 6.8  
Stable tag: 2.0.5
Requires PHP: 8 
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html
= AI-powered plugin for automatic website translation in 25 languages. Boosts traffic and improves your SEO. =

## Short Description

🌍 AI Translate maakt je WordPress website automatisch beschikbaar in meer dan 25 talen. Verhoog je bereik en verbeter je SEO zonder handmatig werk.

## Description

AI Translate vertaalt automatisch je volledige website met behulp van geavanceerde kunstmatige intelligentie. De plugin vertaalt pagina's, berichten, titels, menu's en meer in real-time of via slimme caching. 

**Wat maakt AI Translate uniek?**
✨ De AI analyseert eerst je website om te begrijpen wat je doet en hoe je communiceert. Daardoor zijn de vertalingen altijd afgestemd op jouw merk, terminologie en toonzetting.

🚀 Met intelligente caching werkt je site razendsnel, ook met veel vertalingen. Vertalingen worden automatisch bijgewerkt wanneer je originele content wijzigt, zonder extra API-kosten.

## Features

🌐 **Automatische vertaling** - Pagina's, berichten en custom post types worden automatisch vertaald
✨ **Slimme AI** - Genereert een samenvatting van je site voor context-aware vertalingen
🌍 **25+ talen** - Ondersteuning voor alle belangrijke wereldtalen
⚡ **Snelle caching** - Intelligente cache voor betere performance en lagere kosten
🔄 **Automatische updates** - Vertalingen worden automatisch bijgewerkt bij content wijzigingen
🍪 **Onthoudt voorkeuren** - Bewaart de taalvoorkeur van elke bezoeker (via cookies)
🎨 **Makkelijk te gebruiken** - Eenvoudige taalwisselaar in de linkerhoek van je website
🔧 **Flexibel** - Kies je eigen AI model (OpenAI, Deepseek of andere API's)
🔗 **SEO-vriendelijk** - Vertaalt ook URL's voor betere zoekmachine-optimalisatie

## Installation

1. **Installeer de plugin** - Upload naar `/wp-content/plugins/ai-translate/` of installeer via WordPress plugin scherm
2. **Activeer** - Ga naar 'Plugins' menu en activeer AI Translate
3. **Configureer** - Ga naar 'Admin > AI Translate' om de instellingen te configureren
4. **Voeg API key toe** - Voeg je API key toe en selecteer welke talen je wilt ondersteunen
5. **Tip voor beste performance** - Gebruik Memcached of Redis voor nog snellere caching (optioneel)

== Frequently Asked Questions ==

= Wat kost het gebruik van AI Translate? =
Het gebruik van AI Translate zelf is gratis. Je hebt wel een API key nodig van een AI service provider zoals OpenAI of Deepseek. De kosten hangen af van je gebruik en de provider die je kiest. Bij OpenAI kost een gemiddelde pagina van 500 woorden ongeveer €0,01 om te vertalen.

= Welke talen worden ondersteund? =
AI Translate ondersteunt 25+ talen waaronder Nederlands, Engels, Duits, Frans, Spaans, Italiaans, Portugees, Russisch, Chinees, Japans, Koreaans, Arabisch, Hindi, Thai, Georgisch, Zweeds, Noors, Deens, Fins, Pools, Tsjechisch, Grieks, Roemeens en meer.

= Hoe werkt de caching? =
AI Translate gebruikt een slim caching systeem dat vertalingen opslaat in WordPress en bestanden. Vertalingen worden alleen opnieuw gegenereerd wanneer de originele content verandert. Dit bespaart API-kosten en verbetert de snelheid van je website.

= Werkt AI Translate met alle themes? =
Ja! AI Translate is ontworpen om te werken met alle WordPress themes. De plugin gebruikt standaard WordPress functies, dus compatibiliteit is gegarandeerd.

= Hoe wordt mijn privacy beschermd? =
AI Translate stuurt alleen website content voor vertaling naar de AI service. Geen persoonlijke data, IP-adressen of andere privacy-gevoelige informatie wordt gedeeld. Alle vertalingen worden lokaal opgeslagen in de cache.

= Wat gebeurt er als de AI service niet beschikbaar is? =
Als de AI service tijdelijk niet beschikbaar is, toont AI Translate de originele content in de standaard taal. Gecachte vertalingen blijven beschikbaar en je website blijft gewoon werken.

= Hoe kan ik de performance optimaliseren? =
Voor optimale performance raden we aan:
* Een caching plugin te gebruiken zoals Jetpack, WP Rocket of W3 Total Cache
* Memcached of Redis te configureren voor database caching
* De cache duur aan te passen op basis van hoe vaak je content update

= Kan ik AI Translate gebruiken voor een meertalige webshop? =
Ja! AI Translate werkt perfect met WooCommerce en andere e-commerce plugins. Producttitels, beschrijvingen en categorieën worden automatisch vertaald. Let op: prijzen en technische specificaties worden niet vertaald.

= Hoe vaak worden vertalingen bijgewerkt? =
Vertalingen worden alleen bijgewerkt wanneer de originele content verandert. Dit gebeurt automatisch en voorkomt onnodige API-kosten. Je kunt ook handmatig de cache legen via de admin instellingen.

= Is AI Translate SEO-vriendelijk? =
Ja! AI Translate is volledig SEO-vriendelijk. Het genereert automatisch hreflang tags, vertaalt URL slugs, en zorgt ervoor dat zoekmachines alle taalversies correct kunnen indexeren.

= Kan ik de AI prompts aanpassen? =
De AI prompts zijn geoptimaliseerd voor de beste vertalingen door je website te analyseren en de toonzetting aan te passen. Je kunt extra context over je site toevoegen in de admin instellingen, maar dit is meestal niet nodig.

= Wat is het verschil tussen "Enabled Languages" en "Detectable Languages"? =
**Enabled Languages** zijn talen die zichtbaar zijn in de taalwisselaar (de vlaggenknop op je website). Bezoekers kunnen deze talen direct selecteren.

**Detectable Languages** worden automatisch gedetecteerd op basis van de browser taal van de bezoeker, maar zijn niet zichtbaar in de wisselaar. Ideaal voor talen die je wilt ondersteunen zonder de interface te vullen.

## Configuration

Alle plugin instellingen vind je onder 'AI Translate' in je WordPress admin menu.

### API Settings

🔑 **API URL** - Het adres van je AI translation API (bijv. `https://api.openai.com/v1/`)
🔐 **API Key** - Je API authenticatie sleutel
🤖 **Translation Model** - Kies je favoriete AI model

### Language Settings

🌍 **Default Language** - De hoofdtaal van je website  
🎯 **Enabled Languages** - Talen die zichtbaar zijn in de taalwisselaar  
🔍 **Detectable Languages** - Automatische vertaling bij browser match, maar niet in wisselaar

### Cache Settings

⏱️ **Cache Duration (days)** - Hoe lang vertaalde content gecached blijft  
🗑️ **Cache Management** - Leeg alle cache, alleen transient cache, of cache per taal  
🔄 **Automatic cache invalidation** - Cache wordt alleen vernieuwd bij content wijzigingen

### Advanced Settings

📄 **Homepage Meta Description** - Stel een aangepaste meta beschrijving in die automatisch wordt vertaald
✨ **Auto-generate site context** - Laat de AI automatisch je site analyseren voor betere vertalingen

## Usage

Na configuratie voegt AI Translate automatisch een taalwisselaar toe aan je website (standaard linksonder). Bezoekers kunnen hun voorkeurstaal selecteren; content wordt direct vertaald of geladen uit de cache.

De taalvoorkeur van elke bezoeker wordt onthouden voor toekomstige bezoeken.

## Cache

📁 Vertalingen worden gecached in `/wp-content/uploads/ai-translate/cache/`
🧹 Verlopen cache wordt automatisch opgeruimd
🔧 Handmatige cache clearing via plugin instellingen

## Recommended Model Selection

💡 **OpenAI**: gpt-4.1-mini - Gebruik geen GPT 5.1 voor vertalingen (langzaam, duur en onnodig complex)
💰 **Deepseek**: deepseek-chat - Langzamer, maar kosteneffectiever
🔧 **Custom**: Gebruik OpenRouter of DeepInfra en selecteer een model

## Development

🔗 Path-based language URLs voor SEO  
🚀 Ondersteuning voor meer content types en vertaalverbeteringen zijn in ontwikkeling  
⚡ Caching en API optimalisatie worden continu verbeterd

## External Services

AI Translate heeft een API key nodig van een van de ondersteunde providers:

### Supported AI Translation Services

**OpenAI API**
- Wat het is: OpenAI's GPT modellen voor tekst vertaling
- Welke data wordt verzonden: Website content (berichten, pagina's, titels, menu items, widget titels) die vertaald moet worden, samen met bron- en doeltaal informatie
- Wanneer data wordt verzonden: Wanneer een bezoeker je website bezoekt in een andere taal dan de standaard taal, en de content nog niet gecached is
- Service provider: OpenAI
- Terms of service: https://openai.com/terms/
- Privacy policy: https://openai.com/privacy/

**Data Handling:**  
🔒 Alleen website content voor vertaling wordt verzonden—geen bezoeker IP of persoonlijke data  
💾 Alle vertalingen worden lokaal gecached; niets wordt extern gedeeld

## Requirements

✅ WordPress 5.0 of hoger  
✅ PHP 8 of hoger  
🔑 API key voor OpenAI, Deepseek of compatibele service

## Crawler/Spider Best Practices

Wanneer je geautomatiseerde crawlers of spiders gebruikt om de cache voor te verwarmen (bijv. wget, curl), volg deze richtlijnen om race conditions te voorkomen en correcte cache generatie te garanderen:

### Recommended Spider Settings

```bash
# Goed: Sequentiële crawling met adequate vertraging
wget --spider --no-directories --delete-after --recursive --level=10 \
     --wait=3 --random-wait --no-verbose --domains=$SITE --no-parent \
     https://yoursite.com
```

## Changelog

### 2.05
  - Fixed JS issue with speculationrules

### 2.04
   - Fix switching back and forth with default language.
   - Fix race condition causing white pages for spiders/crawlers
   - Fixed hreflang tags for default language.
   - Fix issue SEO engine inject that might mess up already troubled HTML
   - More efficient merge of translated output resulting in faster load 
   - Detect untranslated text in cached pages and translated them.
   - Reduced url length and system prompt to generate slug
   - Placeholder translation improved 

### 2.01
  - Total rework, changing the translation architecture.  
  - Reduced cost of translation (increasing batch).
  - Great performance boost.
  - Better support for third party plugins and themes.

### 1.34
- Fix pagination with pretty URL
- Remove hallucinated html tags from ALT image tags. 
- Fix menu issues with translated URLs  
- imnplemented translated open graph tags
- Better prompting and reduced placeholder translation issues. 

### 1.3
- Added translation of (standard wordpress) translation functionality.
- Improved translation context and more translation freedom for AI
- Improved caching and translation performance
- Stable translated URLs: slugs only change if original changes
- Added Greek and Romanian
- Fixed 404 redirects to correct language homepage
- Fixed cache clearing by language
- Fixed non-Latin language URL translations
- Better AI prompting for tags/placeholders
- Improved contextual translation for single words/slugs

### 1.2
- Reduced API calls
- Dynamic content support without breaking cache
- Added hreflang tags
- Automatic re-translation when original content changes
- Fixed language code issues for Ukrainian
- Improved API settings UI
- Custom post type support
- Improved non-Latin script URL translation

### 1.1
- Path-based language URLs for SEO
- Transient cache clearing option
- Better widget and link translation
- Homepage meta description option
- API error handling with backoff
- Detectable (auto-detect, no switcher) languages
- Improved cache statistics in admin
- Remembers each visitor's language preference
- Bugfixes and style improvements
- Improved hreflang original URL logic

### 1.0
- Initial release with basic AI translation

## Provided by

🌐 [NetCare](https://netcare.nl)
