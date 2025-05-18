=== AI Translate ===
Contributors: gkanters
Tags: translation, ai, artificial intelligence, multilingual
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
=== AI Translate ===

A powerful WordPress plugin for automatically translating your website using AI.

## Description

This plugin leverages the power of Artificial Intelligence to provide seamless, automatic translation for your WordPress website's content, including posts, pages, titles, taglines, and even menu items and widget titles. It supports multiple languages and includes intelligent caching for improved performance.

## Features

- Automatic translation of pages and posts
- Support for multiple languages
- Intelligent caching for better performance
- Simple language switcher interface
- Extensive logging and debugging options
- Exclusion of specific pages
- Customizable translation models
- Path-based language URLs (e.g., `/en/my-page`)

## Installation

1. Upload the plugin files to the `/wp-content/plugins/ai-translate/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to 'Admin > AI Translate' to configure the plugin settings.
4. Add your OpenAI API key (or compatible API key) and select the desired languages.

## Configuration

Access the plugin settings via the WordPress admin menu under 'AI Translate'.

### API Settings

- **API URL**: The endpoint URL of the AI translation API you are using (e.g., `https://api.openai.com/v1/`).
- **API Key**: Your secret API key for authentication with the translation service.
- **Translation Model**: Select the specific AI model you want to use for translations.

### Language Settings

- **Default Language**: The primary language of your website.
- **Enabled Languages (in Switcher)**: Select the languages that will be available for visitors to choose from in the language switcher.
- **Detectable Languages (Auto-Translate)**: Select languages for which the site will be automatically translated if a visitor's browser preference matches, but these won't appear in the switcher.

### Cache Settings

- **Cache Duration (days)**: How long translated content is stored in the cache before being refreshed.
- **Cache Management**: Options to clear the entire cache, transient cache only, or cache per language.

### Advanced Settings

- **Homepage Meta Description**: Set a specific meta description for the homepage (in the default language) to override the site tagline or generated excerpt.
- **Excluded Pages**: Select pages that should not be translated.
- **Excluded Shortcodes**: List shortcode tags that should be excluded from translation processing.

## Usage

Once configured, the plugin automatically adds a language switcher button to your website (by default, positioned at the bottom left). Visitors can click on it to select their preferred language, and the content will be translated on the fly (or served from cache if available).

## Cache

Translations are cached to improve performance and reduce API calls:

- Cache files are stored in `/wp-content/uploads/ai-translate/cache/`.
- Expired cache is automatically cleaned up periodically.
- Cache can be manually cleared via the plugin's settings page in the WordPress admin area.

## Development

- Path-based language URLs are implemented.
- Ongoing work to support more content types and improve translation accuracy.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- OpenAI or compatible AI API key

## Changelog

### 1.1
- Implemented path-based language URLs for better SEO and user experience.
- Added option to clear transient cache separately.
- Improved handling of widget text translation and link translation within widgets.
- Added homepage meta description setting.
- Enhanced API error handling with backoff mechanism.
- Added detectable languages feature for auto-translation without switcher visibility.
- Improved cache statistics display in admin.
- Fixed various minor bugs and code style issues.

### 1.0
- Initial release with basic AI translation functionality.

## Provided by

NetCare https://netcare.nl
