=== AI Translate ===
Contributors: gkanters  
Tags: translation, artificial intelligence, seo, translate, ai translate  
Requires at least: 5.0  
Tested up to: 6.8  
Stable tag: 1.32 
Requires PHP: 7.4  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  
= AI-powered WordPress plugin for automatic website translation in 23 languages. Boosts traffic and improves your SEO. =

## Short Description

AI-powered WordPress plugin for automatic website translation in 23 languages. Boosts traffic and improves your SEO.

## Description

AI Translate leverages advanced AI to provide seamless, automatic translation of your WordPress website content—including posts, pages, titles, taglines, menus, and widget titles. Translations are fast, SEO-friendly, and highly customizable. Intelligent caching ensures top performance. The plugin generates a smart summary of your website and provides it to itself while translating, so translations are always tailored to your brand, terminology, and topic.

## Features

- Automatic translation of pages, posts, and custom post types.  
- AI generates summary of your site for itself to understand the goal of the website.
- Support for all major languages of the world.  
- Intelligent caching for better performance.  
- Automatic re-translation when original content changes.  
- Remembers each visitor’s language preference.  
- Simple language switcher interface.  
- Customizable translation models (OpenAI, Deepseek, or compatible API).  
- Translates URLs for SEO (e.g., `/over-ons` to `/en/about-us`).  

## Installation

1. Upload the plugin files to `/wp-content/plugins/ai-translate/` or install directly via the WordPress plugin screen.
2. Activate the plugin via the 'Plugins' menu.
3. Go to 'Admin > AI Translate' to configure settings.
4. Add your API key and select languages.
5. For best performance, use memcached or Redis (plugin uses heavy caching).

== Frequently Asked Questions ==

= What are the costs for using AI Translate? =
AI Translate is free to use, but you need an API key from an AI service provider like OpenAI or Deepseek. Costs depend on your usage and chosen provider. OpenAI charges approximately €0.0015 per 1K tokens, meaning an average 500-word page costs about €0.01 to translate.

= Which languages are supported? =
AI Translate supports 23+ languages including Dutch, English, German, French, Spanish, Italian, Portuguese, Russian, Chinese, Japanese, Korean, Arabic, Hindi, Thai, Vietnamese, Swedish, Norwegian, Danish, Finnish, Polish, Czech, Greek, Romanian and more.

= How does caching work? =
AI Translate uses an intelligent caching system that stores translations in WordPress transients and files. Translations are only regenerated when the original content changes, which saves API costs and improves performance.

= Does AI Translate work with all themes? =
Yes, AI Translate is designed to work with all WordPress themes. The plugin uses standard WordPress hooks and filters, so it should be compatible with most themes.

= How is my privacy protected? =
AI Translate only sends website content for translation to the AI service. No visitor personal data, IP addresses, or other privacy-sensitive information is shared. All translations are cached locally.

= What happens if the AI service is unavailable? =
If the AI service is temporarily unavailable, AI Translate displays the original content in the default language. Cached translations remain available and the website continues to function.

= How can I optimize performance? =
For optimal performance, we recommend:
* Using a caching plugin like Jetpack, WP Rocket or W3 Total Cache
* Configuring Memcached or Redis for database caching
* Adjusting cache duration based on your content update frequency

= Can I use AI Translate for a multilingual webshop? =
Yes, AI Translate works with WooCommerce and other e-commerce plugins. Product titles, descriptions, and categories are automatically translated. Note that prices and technical specifications are not translated.

= How often are translations updated? =
Translations are only updated when the original content changes. This happens automatically and prevents unnecessary API costs. You can also manually clear the cache via admin settings.

= Is AI Translate SEO-friendly? =
Yes, AI Translate is fully SEO-friendly. It automatically generates hreflang tags, translates URL slugs, and ensures search engines can properly index the different language versions.

= Can I customize the AI prompts? =
Currently, the AI prompts are optimized for the best translations. You can improve influence the prompt in the admin settings providing more context about your site.

= What's the difference between "Enabled Languages" and "Detectable Languages"? =
"Enabled Languages" are languages visible in the language switcher. "Detectable Languages" are automatically detected based on the visitor's browser language, but are not visible in the switcher.


## Configuration

Find all plugin settings under 'AI Translate' in your WordPress admin menu.

### API Settings

- **API URL**: Endpoint for your AI translation API (e.g., `https://api.openai.com/v1/`)
- **API Key**: Your API authentication key
- **Translation Model**: Choose your preferred AI model

### Language Settings

- **Default Language**: Main language of your website  
- **Enabled Languages**: Languages shown in the language switcher  
- **Detectable Languages**: Auto-translate if visitor's browser matches, but not in switcher

### Cache Settings

- **Cache Duration (days)**: How long translated content stays cached  
- **Cache Management**: Clear all cache, only transient cache, or cache per language  
- **Automatic cache invalidation**: Cache refreshes only when original content changes

### Advanced Settings

- Homepage Meta Description: Set and auto-translate a custom meta description for your homepage
- Generate context of site to be translated automatically and use it for translation "tone of voice and content"

## Usage

Once configured, AI Translate adds a language switcher button to your website (default: bottom left). Visitors can select their preferred language; content is translated instantly or loaded from cache.  
Each visitor’s language choice is remembered for future visits.

## Cache

- Translations are cached in `/wp-content/uploads/ai-translate/cache/`
- Expired cache is cleaned up automatically
- Manual cache clearing via plugin settings

## Recommended Model Selection

- OpenAI: gpt-4.1-mini  
- Deepseek: deepseek-chat (slower, but cost-effective)

## Development

- Path-based language URLs for SEO  
- Support for more content types and translation improvements is ongoing  
- Caching and API optimization are continuously improved

## External Services

AI Translate requires an API key for one of the supported providers:

### Supported AI Translation Services

**OpenAI API**
- What it is: OpenAI's GPT models for text translation
- What data is sent: Website content (posts, pages, titles, menu items, widget titles) that needs to be translated, along with source and target language information
- When data is sent: When a visitor accesses your website in a language different from the default language, and the content is not already cached
- Service provider: OpenAI
- Terms of service: https://openai.com/terms/
- Privacy policy: https://openai.com/privacy/


**Data Handling:**  
- Only website content for translation is sent—no visitor IP or personal data  
- All translations cached locally; nothing is shared externally

## Requirements

- WordPress 5.0 or higher  
- PHP 7.4 or higher  
- API key for OpenAI, Deepseek, or compatible service

## Changelog

### 1.32
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
- Remembers each visitor’s language preference
- Bugfixes and style improvements
- Improved hreflang original URL logic

### 1.0
- Initial release with basic AI translation

## Provided by

[NetCare](https://netcare.nl)
