# AI Translate (OB-architecture)

AI Translate is a WordPress plugin that translates fully rendered pages using Output Buffering, with deterministic page-level caching and a simple language switcher.

## Features
- Output Buffering translation: parse → bundle translate (batch-providers) → merge → SEO/URL adjust → cache
- Path-based languages: `/en/...` via rewrite tags/rules
- Admin UI for:
  - API provider selection (OpenAI, DeepSeek, Custom base URL)
  - Per-provider API keys and model selection (+ Validate API)
  - Default/Enabled/Detectable languages
  - Website Context and Homepage Meta Description
  - Multilingual search toggle (reserved)
  - Cache management (all, memory/transients, per-language) with slug cache preserved
- Language switcher with flag icons (enabled languages)

## Requirements
- WordPress 6.x
- PHP 8.0+

## Installation
1. Copy the plugin folder `ai-translate` to `wp-content/plugins/`.
2. Activate via WP-CLI:
```bash
wp plugin activate ai-translate
```
3. Save settings in Admin → AI Translate. Selecting a provider and validating API will flush rewrites when languages change.

## Configuration
- Go to Admin → AI Translate (General tab):
  - API Provider: choose OpenAI/DeepSeek/Custom. For Custom, set the Base URL.
  - API Key: stored per provider.
  - Translation Model: stored per provider. Use "Validate API" to fetch/verify.
  - Default Language: canonical/original language.
  - Enabled Languages: appear in the switcher.
  - Detectable Languages: auto-translate on matching browser language (not shown in switcher).
  - Website Context: short site description for better translations.
  - Homepage Meta Description: override for the homepage (default language); translated for target languages.
  - Cache Duration: minimum 14 days (stored as hours).

## How it works
- Rewrites capture `/xx/...` into `ai_lang` and `ai_path`. The request resolves to the original content.
- On `template_redirect`, the Output Buffer starts. After the theme renders HTML, the callback:
  1. Skips translation for default language.
  2. Checks disk cache (page-level artifact). If hit, returns it.
  3. Builds a DOM plan: textual nodes + attributes + meta description.
  4. Calls the selected provider once per page (batch-providers) to translate all segments in one request.
  5. Merges translations back, injects SEO/URL (placeholders currently no-op), writes to cache, returns final HTML.

## Language switcher
- Injected into the footer with flags for Enabled languages.
- Script: `assets/language-switcher.js`, localized with `default_language`, `enabled_languages`, `detectable_languages`.
- Sets a cookie `ai_translate_lang` and adjusts navigation to `/{lang}/...`.

## Caching
- Key: `ait:v2:{site_hash}:{lang}:{route}:{content_version}`
- Stored on disk under uploads `ai-translate/cache/pages/`.
- Content version is derived from the queried object (post modified) or archive signals.
- Admin cache page allows clearing all, memory/transients, or per language. Slug cache is preserved.

## Notes & Roadmap
- Current DOM, SEO, and URL rewriter are minimal; they preserve structure but do not modify scripts/JSON-LD.
- Provider call expects OpenAI-compatible `POST /chat/completions` with JSON response containing `{ "translations": { id: text } }`.
- Multilingual search toggle is reserved for integrating query-time translation of search terms.
- No logging added by default.

### Manual test: anchor spacing around links

Input HTML:

```
<p>Mit NetCare an Ihrer Seite nutzen Sie das volle Potenzial der <a href="/ki/">Künstlichen Intelligenz</a>. Rufen Sie uns an.</p>
```

Na het mergen moeten spaties rond de link intact blijven en geen woorden samensmelten, bijvoorbeeld:

```
<p>Mit NetCare an Ihrer Seite nutzen Sie das volle Potenzial der <a href="/de/ki/">Künstlichen Intelligenz</a>. Rufen Sie uns an.</p>
```

## Uninstall / Deactivation
- Deactivating stops OB. Cached artifacts remain in uploads and can be deleted from Admin → AI Translate.

## Support
- Production is Linux; prefer bash/CLI examples. Do not set environment variables; configuration lives in plugin options.
