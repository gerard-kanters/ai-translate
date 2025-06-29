=== AI Translate ===
Contributors: gkanters  
Tags: translation, artificial intelligence, seo, translate, ai translate  
Requires at least: 5.0  
Tested up to: 6.8  
Stable tag: 1.24  
Requires PHP: 7.4  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  
= AI-powered WordPress plugin for automatic website translation in 21 languages. Boosts traffic and improves your SEO. =

## Short Description

AI-powered WordPress plugin for automatic website translation in 21 languages. Boosts traffic and improves your SEO.

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

### Note on dynamic content

Dynamic elements (like forms with nonces or timestamps) may generate extra cache files. The plugin minimizes this for common cases, but custom dynamic code may need attention for best results.

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

### 1.24
- Improved translation context and freedom for AI
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
