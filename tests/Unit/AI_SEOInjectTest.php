<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Lang;
use AITranslate\AI_Translate_Core;
use AITranslate\AI_SEO;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_SEO::class)]
final class AI_SEOInjectTest extends TestCase
{
    private const HOME = 'https://example.com';

    protected function setUp(): void
    {
        parent::setUp();
        AI_Lang::_setDefault('nl');

        $prop = new \ReflectionProperty(AI_SEO::class, 'metaTranslationCache');
        $prop->setValue(null, []);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return ($key === 'home' || $key === 'siteurl') ? self::HOME : $default;
        });
        Functions\when('trailingslashit')->alias(fn($s) => rtrim((string) $s, '/') . '/');
        // Mimic the plugin's home_url filter: on a translated request '/' gets the /en prefix.
        Functions\when('home_url')->alias(function ($path = '') {
            if ($path === '/' || $path === '') {
                return self::HOME . '/en/';
            }
            return self::HOME . '/' . ltrim((string) $path, '/');
        });
        Functions\when('wp_parse_url')->alias(fn($u, $c = -1) => parse_url((string) $u, $c));
        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        Functions\when('is_home')->justReturn(true);
        Functions\when('is_singular')->justReturn(false);
        Functions\when('is_archive')->justReturn(false);
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_search')->justReturn(false);
        Functions\when('get_queried_object_id')->justReturn(0);
        Functions\when('get_bloginfo')->justReturn('');
        Functions\when('get_theme_mod')->justReturn(0);
        Functions\when('sanitize_key')->alias(fn($s) => strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $s)));
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('apply_filters')->alias(fn($tag, $value = null) => $value);
    }

    /** @return array{0:\DOMDocument,1:\DOMXPath} */
    private function render(string $head, string $lang): array
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><html><head>' . $head . '</head><body></body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xpath = new \DOMXPath($doc);

        AI_SEO::inject_dom($doc, $xpath, $lang);

        return [$doc, new \DOMXPath($doc)];
    }

    private function attr(\DOMXPath $x, string $query, string $attr): ?string
    {
        $n = $x->query($query);
        return ($n && $n->length > 0) ? $n->item(0)->getAttribute($attr) : null;
    }

    public function test_home_root_ignores_the_language_prefix_filter(): void
    {
        $this->assertSame('https://example.com/', AI_SEO::homeRoot());
    }

    public function test_hreflang_default_and_x_default_point_to_site_root_on_translated_homepage(): void
    {
        [, $x] = $this->render('<title>Home</title>', 'en');

        $this->assertSame('https://example.com/', $this->attr($x, '//link[@hreflang="nl"]', 'href'));
        $this->assertSame('https://example.com/', $this->attr($x, '//link[@hreflang="x-default"]', 'href'));
        $this->assertSame('https://example.com/en/', $this->attr($x, '//link[@hreflang="en"]', 'href'));
        $this->assertSame('https://example.com/de/', $this->attr($x, '//link[@hreflang="de"]', 'href'));
    }

    public function test_translated_url_for_homepage_in_default_language_is_site_root(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'page_on_front') {
                return 0;
            }
            return ($key === 'home' || $key === 'siteurl') ? self::HOME : $default;
        });

        $this->assertSame('https://example.com/', AI_SEO::get_translated_url(0, 'nl'));
        $this->assertSame('https://example.com/en/', AI_SEO::get_translated_url(0, 'en'));
    }

    public function test_double_slash_in_canonical_is_collapsed(): void
    {
        [, $x] = $this->render('<link rel="canonical" href="https://example.com/en//">', 'en');

        $this->assertSame('https://example.com/en/', $this->attr($x, '//link[@rel="canonical"]', 'href'));
    }

    public function test_canonical_with_query_keeps_query_and_scheme_slashes(): void
    {
        [, $x] = $this->render('<link rel="canonical" href="https://example.com/a//b/?next=//x">', 'nl');

        $this->assertSame('https://example.com/a/b/?next=//x', $this->attr($x, '//link[@rel="canonical"]', 'href'));
    }

    public function test_valid_canonical_is_left_untouched(): void
    {
        [, $x] = $this->render('<link rel="canonical" href="https://example.com/en/page/">', 'en');

        $this->assertSame('https://example.com/en/page/', $this->attr($x, '//link[@rel="canonical"]', 'href'));
    }

    public function test_og_locale_matches_the_html_lang_region(): void
    {
        [, $x] = $this->render('<title>Home</title>', 'en');
        $this->assertSame('en_GB', $this->attr($x, '//meta[@property="og:locale"]', 'content'));

        [, $x] = $this->render('<title>Home</title>', 'nl');
        $this->assertSame('nl_NL', $this->attr($x, '//meta[@property="og:locale"]', 'content'));
    }

    public function test_og_locale_falls_back_to_map_for_languages_without_html_region(): void
    {
        [, $x] = $this->render('<title>Home</title>', 'ja');
        $this->assertSame('ja_JP', $this->attr($x, '//meta[@property="og:locale"]', 'content'));
    }

    public function test_meta_description_is_not_double_escaped(): void
    {
        AI_Translate_Core::_set_setting('homepage_meta_description', "Tom & Jerry's \"AI\" <advies>");

        [$doc, $x] = $this->render('<title>Home</title>', 'nl');

        $this->assertSame("Tom & Jerry's \"AI\" <advies>", $this->attr($x, '//meta[@name="description"]', 'content'));
        $html = $doc->saveHTML();
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;#039;', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
    }

    public function test_generated_description_is_limited_to_160_characters(): void
    {
        AI_Translate_Core::_set_setting('homepage_meta_description', str_repeat('woord ', 60));

        [, $x] = $this->render('<title>Home</title>', 'nl');

        $content = $this->attr($x, '//meta[@name="description"]', 'content');
        $this->assertNotNull($content);
        $this->assertLessThanOrEqual(163, mb_strlen($content));
        $this->assertStringEndsWith('...', $content);
    }

    public function test_description_is_replaced_in_translated_language_without_seo_plugin(): void
    {
        AI_Translate_Core::_set_setting('homepage_meta_description', 'Instelling van de plugin');

        [, $x] = $this->render('<meta name="description" content="Zelf geschreven tekst">', 'en');

        $this->assertSame('Instelling van de plugin', $this->attr($x, '//meta[@name="description"]', 'content'));
    }

    public function test_existing_description_is_kept_when_an_seo_plugin_is_active(): void
    {
        Functions\when('apply_filters')->alias(fn($tag, $value = null) => $tag === 'ai_translate_seo_plugin_active' ? true : $value);
        AI_Translate_Core::_set_setting('homepage_meta_description', 'Instelling van de plugin');

        [, $x] = $this->render(
            '<meta name="description" content="Zelf geschreven tekst, langer dan honderdzestig tekens: ' . str_repeat('uitleg ', 40) . '">' .
            '<meta property="og:description" content="Zelf geschreven og-tekst">',
            'en'
        );

        $desc = $this->attr($x, '//meta[@name="description"]', 'content');
        $this->assertStringStartsWith('Zelf geschreven tekst', $desc);
        $this->assertStringNotContainsString('...', $desc, 'SEO plugin text must not be truncated');
        $this->assertSame('Zelf geschreven og-tekst', $this->attr($x, '//meta[@property="og:description"]', 'content'));
    }

    public function test_missing_canonical_is_added_as_self_reference_on_translated_page(): void
    {
        [, $x] = $this->render('<title>Home</title>', 'en');

        $this->assertSame('https://example.com/en/', $this->attr($x, '//link[@rel="canonical"]', 'href'));
    }

    public function test_missing_canonical_is_added_on_default_language_homepage(): void
    {
        [, $x] = $this->render('<title>Home</title>', 'nl');

        $this->assertSame('https://example.com/', $this->attr($x, '//link[@rel="canonical"]', 'href'));
    }

    public function test_canonical_pointing_to_default_language_is_replaced_on_translated_page(): void
    {
        [, $x] = $this->render('<link rel="canonical" href="https://example.com/">', 'de');

        $this->assertSame('https://example.com/de/', $this->attr($x, '//link[@rel="canonical"]', 'href'));
    }

    public function test_no_canonical_is_added_on_not_found_pages(): void
    {
        Functions\when('is_404')->justReturn(true);

        [, $x] = $this->render('<title>Niet gevonden</title>', 'en');

        $this->assertNull($this->attr($x, '//link[@rel="canonical"]', 'href'));
    }

    /** Make the current request an archive such as /en/product/. */
    private function onArchive(string $requestUri): void
    {
        $_SERVER['REQUEST_URI'] = $requestUri;
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('wp_unslash')->returnArg();
        Functions\when('ai_translate_strip_site_path')->returnArg();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    public function test_archive_hreflang_uses_the_original_path_for_every_language(): void
    {
        $this->onArchive('/en/product/');

        [, $x] = $this->render('<title>Producten</title>', 'en');

        $this->assertSame('https://example.com/product/', $this->attr($x, '//link[@hreflang="nl"]', 'href'));
        $this->assertSame('https://example.com/en/product/', $this->attr($x, '//link[@hreflang="en"]', 'href'));
        $this->assertSame('https://example.com/de/product/', $this->attr($x, '//link[@hreflang="de"]', 'href'));
        $this->assertSame('https://example.com/product/', $this->attr($x, '//link[@hreflang="x-default"]', 'href'));
    }

    public function test_archive_hreflang_is_the_same_on_the_default_language_archive(): void
    {
        $this->onArchive('/product/');

        [, $x] = $this->render('<title>Producten</title>', 'nl');

        $this->assertSame('https://example.com/product/', $this->attr($x, '//link[@hreflang="nl"]', 'href'));
        $this->assertSame('https://example.com/de/product/', $this->attr($x, '//link[@hreflang="de"]', 'href'));
    }

    public function test_archive_canonical_is_self_referencing_on_translated_archive(): void
    {
        $this->onArchive('/de/product/');

        [, $x] = $this->render('<title>Produkte</title>', 'de');

        $this->assertSame('https://example.com/de/product/', $this->attr($x, '//link[@rel="canonical"]', 'href'));
    }
}
