=== AI Translate ===
Contributors: gkanters  
Tags: translation, artificial intelligence, seo, translate, ai translate  
Requires at least: 5.0  
Tested up to: 6.9  
Stable tag: 2.2.0
Requires PHP: 8.0.0
License: GPLv2 or later  
License URI: <https://www.gnu.org/licenses/gpl-2.0.html>
Plugin homepage: https://wordpress.org/plugins/ai-translate 
= AI-powered plugin for automatic website translation in 35 languages. Boosts traffic and improves your SEO. =

## Short Description

AI Translate automatically makes your WordPress website available in 35+ languages. Increase your reach and improve your SEO without manual work.

## Description

AI Translate automatically translates your entire website using advanced artificial intelligence. The plugin translates pages, posts, titles, menus, and more in real time while customers and bots visit your website in their perferred language. Use cache warming to improve performance for first visitors. 

### What makes AI Translate unique?

**🎯 Smart AI Analysis**
The AI has an option to analyze your website to understand what you do and how you communicate. This ensures translations are tailored to your brand, terminology, and tone of voice.

**⚡ Intelligent Caching**
With intelligent caching, your site runs fast, even with many translations. Translations are automatically updated when you change original content, without extra API costs.

**🌍 SEO-Friendly**
Automatic hreflang tags, translated URL slugs, and proper indexing ensure search engines can properly index all language versions of your site.

## Features

- **🌐 Automatic Translation** - Pages, posts, and custom post types are automatically translated
- **✨ Smart AI** - Generates a summary of your site for context-aware translations.
- **🌍 35+ Languages** - Support for all major world languages and much more.
- **⚡ Fast Caching** - Intelligent cache for better performance and lower costs.
- **🔄 Automatic Updates** - Translations are automatically updated when content changes.
- **🍪 Remembers Preferences** - Saves each visitor's language preference (via cookies).
- **🎨 Easy to Use** - Simple language switcher in the left corner of your website.
- **🔧 Flexible** - Choose your own AI model (OpenAI, Deepseek, or other APIs).
- **🔗 SEO-Friendly** - Also translates URLs for better search engine optimization.

## Installation

1. **Install the plugin** - Upload to `/wp-content/plugins/ai-translate/` or install directly via  WordPress (plugin screen)
2. **Activate** - Go to the 'Plugins' menu and activate AI Translate
3. **Configure** - Go to 'Admin > AI Translate' to configure settings
4. **Add API key** - Add your API key and select which languages you want to support
5. **Tip for best performance** - Use Memcached or Redis for even faster caching (optional)

### Permalinks

AI Translate requires **friendly permalinks** to function properly. The plugin automatically sets your permalinks to the "Post name" structure (`/%postname%/`) during activation if they are currently set to "Plain". This is necessary for the language-prefixed URLs (e.g., `/de/`, `/en/`) to work correctly.

If you manually change permalinks to "Plain" after activation, you will see a warning in the WordPress admin, and the language switching will not work as expected.

## Frequently Asked Questions

### What are the costs for using AI Translate?

AI Translate is free to use, but you need an API key from an AI service provider like OpenAI or Deepseek. Costs depend on your usage and the provider you choose. At OpenAI, translating an average 500-word page costs approximately €0.01.

### Which languages are supported?

AI Translate supports 35+ languages including Dutch, English, German, French, Spanish, Italian, Portuguese, Russian, Chinese, Japanese, Korean, Arabic, Hindi, Thai, Georgian, Swedish, Norwegian, Danish, Finnish, Polish, Czech, Greek, Romanian, and more.

### How does caching work?

AI Translate uses a smart caching system that stores translations in WordPress and files. Translations are only regenerated when the original content changes. This saves API costs and improves your website speed.

### Does AI Translate work with all themes?

Yes! AI Translate is designed to work with all WordPress themes. The plugin uses standard WordPress functions, so compatibility is guaranteed.

### How is my privacy protected?

AI Translate only sends website content for translation to the AI service. No personal data, IP addresses, or other privacy-sensitive information is shared. All translations are stored locally in the cache.

### What happens if the AI service is unavailable?

If the AI service is temporarily unavailable, AI Translate displays the original content in the default language. Cached translations remain available and your website continues to work normally.

### How can I optimize performance?

For optimal performance, we recommend:

- Using a caching plugin like Jetpack, WP Rocket, or W3 Total Cache
- Configuring Memcached or Redis for database caching
- Adjusting cache duration based on how often you update content

### Can I use AI Translate for a multilingual webshop?

Yes! AI Translate works with WooCommerce and other e-commerce plugins. Product titles, descriptions, and categories are automatically translated. Note: prices and technical specifications are not translated.

### How often are translations updated?

Translations are only updated when the original content changes. This happens automatically and prevents unnecessary API costs. You can also manually clear the cache via the admin settings.

### Is AI Translate SEO-friendly?

Yes! AI Translate is fully SEO-friendly. It automatically generates hreflang tags, meta tags,  translates URL slugs, and ensures search engines can properly index all language versions. You do not even need additional SEO plugins anymore

### What do I need to do if a language is not translated ?

Not all languages can be translated with all models. So first make sure you use a model that supports the language. Another reason can be that the rewrite rules must be flushed when adding an new language. Disable and enable the plugin will do this. 

### Can I customize the AI prompts?

The AI prompts are optimized for the best translations by analyzing your website and adjusting the tone. You can add extra context about your site in the admin settings, but this should not be necessary.

### What's the difference between "Enabled Languages" and "Detectable Languages"?

**Enabled Languages** are languages visible in the language switcher (the flag button on your website). Visitors can directly select these languages.

**Detectable Languages** are automatically detected based on the visitor's browser language, but are not visible in the switcher. Ideal for languages you want to support without cluttering the interface.

## Configuration

All plugin settings can be found under 'AI Translate' in your WordPress admin menu.

### API Settings

- **🔑 API Provider** - Select a provider of your AI translation API (e.g. OpenAI) 
- **🔐 API Key** - Your API authentication key
- **🤖 Translation Model** - Choose your preferred AI model

### Language Settings

- **🌍 Default Language** - The main language of your website
- **🎯 Enabled Languages** - Languages visible in the language switcher
- **🔍 Detectable Languages** - Automatic translation on browser match, but not in switcher

### Cache Settings

- **⏱️ Cache Duration (days)** - How long translated content stays cached
- **🗑️ Cache Management** - Clear all cache, only transient cache, or cache per language
- **🔄 Automatic cache invalidation** - Cache is only refreshed on content changes

### Advanced Settings

- **📄 Homepage Meta Description** - Set a custom meta description that will be automatically translated.
- **✨ Auto-generate site context** - Let the AI automatically analyze your site for better translations

## Usage

After configuration, AI Translate automatically adds a language switcher to your website (default: bottom left). Visitors can select their preferred language; content is translated instantly or loaded from cache. 

Each visitor's language preference is remembered for future visits.

## Cache

- **📁 Location** - Translations are cached in `/wp-content/uploads/ai-translate/cache/`
- **🧹 Auto-cleanup** - Expired cache is automatically cleaned up
- **🔧 Manual clearing** - Clear cache manually via plugin settings

## Recommended Model Selection

- **💡 OpenAI**: `gpt-4.1-mini` 
- **💰 Deepseek**: `deepseek-chat` - Slower, but more cost-effective
- **🔧 OpenRouter**: Select google/gemini-2.5-flash-lite which has the best price/performance
- **💡 Groq**: Select LLama 3.1/8b which is fast and the cheapest option. It does not support all languages.
  

Gemini flash is the best price performance model available now. It is fast, support all languages and has low pricing. 

## Development

- 🔗 Path-based language URLs for SEO
- 🚀 Support for more content types and translation improvements are in development
- ⚡ Caching and API optimization are continuously improved

## External Services

AI Translate requires an API key from one of the supported providers:

#### Data Handling

- 🔒 Only website content for translation is sent—no visitor IP or personal data
- 💾 All translations are cached locally; nothing is shared externally

## Requirements

- ✅ WordPress 5.0 or higher
- ✅ PHP 8 or higher
- 🔑 API key for OpenAI, Deepseek, or compatible service

## Changelog

### 2.2.1

- Added cache warming for pages to admin.
- Admin user language preferred over site language for admin page translation.
- Support for Wordpress 6.9
- Added NAV menu item for language switcher.
- Issue with language parameter on home page fixed.
- Issue with search not keeping language code fixed.
- Improved validation of models, checking account and provider information.
- Dual caching strategy for object oriented caching implemented.
- Support for multi domain caching.
- Added options for placing the Language switcher button on your site. 
- Implemented stop API calls in admin to avoid cost.
- Improved caching and reduce API calls of UI elements.
  
### 2.1.2

- Fixed JS issue with speculationrules.
- Removed debug logging.
- Fix browser language detection.
- Fix admin setting selecting default language.
- Set permalink structure on initialization to post-name.
- Fixed issue with existing meta tags in default language.
- Exclude xml files from processing.
- Added 10 languages.

### 2.0.4

- Fix switching back and forth with default language
- Fix race condition causing white pages for spiders/crawlers
- Fixed hreflang tags for default language
- Fix issue SEO engine inject that might mess up already troubled HTML
- More efficient merge of translated output resulting in faster load
- Detect untranslated text in cached pages and translated them
- Reduced url length and system prompt to generate slug
- Placeholder translation improved

### 2.0.1

- Total rework, changing the translation architecture
- Reduced cost of translation (increasing batch)
- Great performance boost
- Better support for third party plugins and themes

### 1.34

- Fix pagination with pretty URL
- Remove hallucinated html tags from ALT image tags
- Fix menu issues with translated URLs
- Implemented translated open graph tags
- Better prompting and reduced placeholder translation issues

### 1.3

- Added translation of (standard wordpress) translation functionality
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

