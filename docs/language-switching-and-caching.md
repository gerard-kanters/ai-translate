# Language switching, URL handling, cookies, caching and incognito

This document explains how AI Translate handles language detection and switching, how language-prefixed URLs are routed, and why caching of **redirects (302)** can cause “stuck on /de/” behaviour (often observed in incognito).

## Goals and rules (behavioural contract)

- **Rule A (URL wins)**: If the URL contains a language prefix (e.g. `/de/...`), that language is used for rendering.
- **Rule B (no prefix = default language)**: URLs without a language prefix (e.g. `/diensten/`) render in the **default language**, regardless of any stored preference.
- **Rule C (root `/` is special)**: The root URL is allowed to redirect to a language home (`/{lang}/`) for a first-time visitor based on browser language.
- **Rule D (switcher has highest priority)**: Clicking the language switcher must take effect immediately and persist by setting the cookie.
- **Rule E (no cached redirects)**: Redirects that depend on browser language/cookie must not be cached by browsers or proxies.

## Key components

### 1) Rewrite rules (language prefixes)

Language-prefixed URLs are routed via WordPress rewrite rules using the `lang` query var, so WordPress can still resolve pages/posts by `pagename`/`name`.

Examples:

- `/{lang}/` → `index.php?lang=$matches[1]`
- `/{lang}/{pagename}/` → `index.php?lang=$matches[1]&pagename=$matches[2]`

These rules are registered on `init` and are intentionally added with priority `top` to avoid conflicts.

### 2) The language cookie (`ai_translate_lang`)

The cookie stores the user’s selected language preference:

- Name: `ai_translate_lang`
- Path: `/`
- Domain: `.{host}` (e.g. `.myvox.nl`)
- SameSite: `Lax`
- Secure: `true` on HTTPS

Important:

- The cookie is **not** used to translate arbitrary non-prefixed pages. It is primarily a “preference” used at `/` and by the switcher.

### 3) Switching language (`?switch_lang=xx`)

The switcher uses `/?switch_lang=xx` to trigger a server-side handler early in `init`:

- Sets the cookie to the requested language.
- Redirects to a “clean” URL (without `switch_lang`).

**Default language special-case**

For the default language (e.g. `nl`) the switch handler redirects directly to `/` (not to `/nl/`). This removes an entire class of race conditions and redirect loops in browsers.

### 4) `template_redirect` and “current language”

On the front-end, the plugin determines the language in this order:

1. **Language from URL prefix** (`/{lang}/...`) if present.
2. Otherwise, uses the default language for normal pages.
3. Root `/` may redirect to `/{lang}/` on first visit (browser language) or if a non-default preference cookie exists.

## Redirect caching: why incognito can get “stuck”

### The problem

Some sites returned a 302 like:

- Request: `GET /` (no cookie, browser language = `de`)
- Response: `302 Location: /de/`

But the response also contained cache headers like:

- `Cache-Control: max-age=3600`
- `Expires: ...`

Browsers are allowed to cache **redirect responses**. If a 302 is cached for `/`, the browser can keep redirecting `/ → /de/` even after you switch to NL, because it does not revalidate the decision.

This is often noticed in incognito because:

- The session is short, so you repeatedly hit “first visit” flows.
- You may quickly reproduce the cached 302 within the same private session.

### The fix (mandatory)

All redirects whose target depends on **cookies** or **Accept-Language** must be sent with *non-cacheable* headers. WordPress provides:

- `nocache_headers()`

This ensures the response uses `Cache-Control: no-store, private, max-age=0` and an old `Expires`, preventing browsers/proxies from caching the redirect.

**Where to apply `nocache_headers()`**

- Root “first visit” redirect `/ → /{lang}/`
- Root “returning visitor” redirect `/ → /{cookieLang}/`
- Any search-redirects that inject a language prefix
- Switcher redirects (already uses `nocache_headers()` by design)

## Operational checklist (when this resurfaces)

1. **Confirm switch handler response**
   - `GET /?switch_lang=nl` must return:
     - `Set-Cookie: ai_translate_lang=nl`
     - `Location: /`
     - no-cache headers

2. **Confirm root redirect headers**
   - `GET /` with `Accept-Language: de` must return:
     - `302 Location: /de/`
     - `Cache-Control: no-cache, no-store, private, max-age=0` (via `nocache_headers()`)

3. **Confirm no “/nl/ hop” on switch**
   - Switching to default language should not rely on `/nl/` as an intermediate.

## Notes about site-specific differences

If a site still behaves differently, check for:

- Additional caching layers (CDN / reverse proxy) that override cache headers.
- MU-plugins or other plugins that modify redirects or caching headers.
- Theme code altering `wp_redirect()` behaviour or caching headers.


