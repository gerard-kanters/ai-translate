# AI Translate WordPress Plugin

A powerful WordPress plugin for automatically translating your website using AI.

## Features

- Automatic translation of pages and posts
- Support for multiple languages
- Intelligent caching for better performance
- Simple language switcher interface
- Extensive logging and debugging options
- Exclusion of specific pages
- Customizable translation models

## Installation

1. Upload the plugin
2. Activate the plugin via the 'Plugins' menu in WordPress
3. Go to Admin > AI Translate' to configure the plugin
4. Add your OpenAI API key and select the desired languages

## Configuration

### Basic Settings
- **API URL**: The URL of the API you are using
- **API Key**: Your API key
- **API model**: The model used by the API
- **Default Language**: The main language of your website
- **Enabled Languages**: The languages to which translation is possible
- **Translation Model**: Choose between different AI models
- **Cache Time**: How long translations are stored (in days)

### Advanced Settings
- **Logging**: Enable logging for debugging
- **Debug Mode**: Additional logging for developers

## Usage

The plugin automatically adds a language switcher button to your website (bottom left). Visitors can click on it to select the desired language.

### Cache

Translations are cached to improve performance:
- Cache is stored in `/wp-content/uploads/ai-translate-cache/`
- Expired cache is automatically cleaned up
- Cache can be manually cleared via the ADMIN settings

## Development
- slug translation
- More content types

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- OpenAI or compatible API key

## License

GPLv2 or later
Provided by NetCare https://netcare.nl