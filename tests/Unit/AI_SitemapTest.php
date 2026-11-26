<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Lang;
use AITranslate\AI_Sitemap;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

class AI_SitemapTest extends TestCase
{
    /** @var \Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        AI_Lang::_setDefault('nl');
        AI_Lang::_setEnabled(['nl', 'en', 'de']);

        Functions\when('get_option')->alias(static function ($name, $default = false) {
            return $name === 'home' ? 'https://example.com' : $default;
        });
        Functions\when('trailingslashit')->alias(static function ($s) {
            return rtrim((string) $s, '/\\') . '/';
        });
        Functions\when('esc_url_raw')->alias(static function ($u) {
            return (string) $u;
        });
        Functions\when('esc_url')->alias(static function ($u) {
            return (string) $u;
        });
        Functions\when('get_transient')->justReturn('lang');
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page', 'client' => 'client']);
        Functions\when('home_url')->alias(static function ($path = '') {
            return 'https://example.com' . $path;
        });
        Functions\when('ai_translate_cpt_path_prefix')->alias(static function ($type) {
            return $type === 'client' ? 'client/' : '';
        });
        Functions\when('get_permalink')->alias(static function ($id) {
            return [10 => 'https://example.com/over-ons/', 11 => 'https://example.com/client/thomas/'][$id] ?? false;
        });

        $this->wpdb = \Mockery::mock();
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->posts = 'wp_posts';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, $args = []) {
            return $sql . '|' . json_encode($args);
        });
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function slugRows(array $rows): void
    {
        $this->wpdb->shouldReceive('get_results')->andReturn($rows);
    }

    public function test_alternates_list_default_language_translations_and_x_default(): void
    {
        $this->slugRows([
            ['post_id' => 10, 'lang' => 'en', 'translated_slug' => 'about-us', 'post_type' => 'page'],
            ['post_id' => 10, 'lang' => 'de', 'translated_slug' => 'ueber-uns', 'post_type' => 'page'],
        ]);

        $alt = AI_Sitemap::get_alternates([10])[10];

        $this->assertSame('https://example.com/over-ons/', $alt['nl']);
        $this->assertSame('https://example.com/en/about-us/', $alt['en']);
        $this->assertSame('https://example.com/de/ueber-uns/', $alt['de']);
        $this->assertSame('https://example.com/over-ons/', $alt['x-default']);
    }

    public function test_alternates_use_the_post_type_prefix(): void
    {
        $this->slugRows([
            ['post_id' => 11, 'lang' => 'en', 'translated_slug' => 'thomas-from-irdeto', 'post_type' => 'client'],
        ]);

        $alt = AI_Sitemap::get_alternates([11])[11];

        $this->assertSame('https://example.com/en/client/thomas-from-irdeto/', $alt['en']);
    }

    public function test_only_languages_with_a_translated_slug_are_listed(): void
    {
        $this->slugRows([
            ['post_id' => 10, 'lang' => 'en', 'translated_slug' => 'about-us', 'post_type' => 'page'],
        ]);

        $alt = AI_Sitemap::get_alternates([10])[10];

        $this->assertArrayHasKey('en', $alt);
        $this->assertArrayNotHasKey('de', $alt, 'no slug yet: the URL is not known, and building a sitemap must not translate');
    }

    public function test_post_without_any_translation_gets_no_alternates(): void
    {
        $this->slugRows([]);

        $this->assertArrayNotHasKey(10, AI_Sitemap::get_alternates([10]));
    }

    public function test_homepage_alternates_cover_every_language(): void
    {
        $this->slugRows([]);

        $alt = AI_Sitemap::get_alternates([0])[0];

        $this->assertSame('https://example.com/', $alt['nl']);
        $this->assertSame('https://example.com/en/', $alt['en']);
        $this->assertSame('https://example.com/de/', $alt['de']);
        $this->assertSame('https://example.com/', $alt['x-default']);
    }

    public function test_translated_entries_include_the_post_type_prefix_and_post_id(): void
    {
        $this->slugRows([
            ['post_id' => '11', 'translated_slug' => 'thomas-from-irdeto', 'post_type' => 'client', 'post_modified_gmt' => '2026-01-02 03:04:05'],
            ['post_id' => '10', 'translated_slug' => 'about-us', 'post_type' => 'page', 'post_modified_gmt' => '0000-00-00 00:00:00'],
        ]);

        $entries = AI_Sitemap::get_translated_entries('en');

        $this->assertSame('https://example.com/en/client/thomas-from-irdeto/', $entries[0]['loc']);
        $this->assertSame(11, $entries[0]['post_id']);
        $this->assertSame('https://example.com/en/about-us/', $entries[1]['loc']);
        $this->assertArrayNotHasKey('lastmod', $entries[1]);
    }

    public function test_alternates_xml_escapes_and_can_declare_the_namespace(): void
    {
        $xml = AI_Sitemap::alternates_xml(['en' => 'https://example.com/en/a&b/'], true);

        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/a&amp;b/"', $xml);
        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
        $this->assertStringNotContainsString('xmlns:xhtml', AI_Sitemap::alternates_xml(['en' => 'https://example.com/en/'], false));
    }

    public function test_generated_sitemap_is_valid_xml_with_xhtml_links(): void
    {
        $this->slugRows([
            ['post_id' => 10, 'lang' => 'en', 'translated_slug' => 'about-us', 'post_type' => 'page'],
        ]);

        $build = new \ReflectionMethod(AI_Sitemap::class, 'build_sitemap_xml');
        $xml = $build->invoke(null, [
            ['loc' => 'https://example.com/en/about-us/', 'post_id' => 10],
        ]);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'sitemap must be well-formed XML');
        $links = $doc->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link');
        $hreflangs = [];
        foreach ($links as $l) {
            $hreflangs[] = $l->getAttribute('hreflang');
        }
        $this->assertSame(['nl', 'en', 'x-default'], $hreflangs);
    }
}
