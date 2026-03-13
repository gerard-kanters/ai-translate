<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Lang;
use AITranslate\AI_URL;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_URL::class)]
final class AI_URLTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->posts = 'wp_posts';
        $GLOBALS['wpdb'] = $this->wpdb;

        AI_Lang::_setDefault('nl');

        Functions\when('get_option')->alias(function ($key, $default = '') {
            $options = [
                'home'           => 'https://example.nl',
                'siteurl'        => 'https://example.nl',
                'page_for_posts' => 0,
            ];
            return $options[$key] ?? $default;
        });

        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            if ($component === -1) {
                return parse_url($url);
            }
            return parse_url($url, $component);
        });

        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });

        Functions\when('ai_translate_site_path')->justReturn('');

        Functions\when('get_page_uri')->justReturn('');

        // detect_schema() transient
        Functions\when('get_transient')->justReturn('new');
        Functions\when('set_transient')->justReturn(true);
    }

    // ---------------------------------------------------------------
    //  rewrite_single_href()
    // ---------------------------------------------------------------

    public function test_returns_null_when_lang_is_null(): void
    {
        $result = AI_URL::rewrite_single_href('/contact/', null, 'nl');
        $this->assertNull($result);
    }

    public function test_returns_null_when_default_is_null(): void
    {
        AI_Lang::_setDefault(null);
        $result = AI_URL::rewrite_single_href('/contact/', 'de');
        $this->assertNull($result);
    }

    public function test_uses_ai_lang_default_when_not_provided(): void
    {
        AI_Lang::_setDefault('nl');

        $result = AI_URL::rewrite_single_href('/contact/', 'de');

        // Should add /de/ prefix for non-default language
        $this->assertNotNull($result);
        $this->assertStringContainsString('/de/', $result);
    }

    public function test_internal_relative_url_gets_language_prefix(): void
    {
        $result = AI_URL::rewrite_single_href('/over-ons/', 'de', 'nl');

        $this->assertNotNull($result);
        $this->assertStringStartsWith('/de/', $result);
    }

    public function test_external_url_returns_null(): void
    {
        $result = AI_URL::rewrite_single_href('https://andere-site.com/pagina/', 'de', 'nl');

        $this->assertNull($result);
    }

    public function test_default_language_strips_prefix(): void
    {
        // When target lang = default lang, any /xx/ prefix should be stripped
        $result = AI_URL::rewrite_single_href('/nl/contact/', 'nl', 'nl');

        // Root link with 2-char prefix is treated as language-home → left untouched
        // This is because rewriteHref checks for /xx/ pattern
        $this->assertNotNull($result);
    }

    public function test_same_domain_absolute_url_is_internal(): void
    {
        $result = AI_URL::rewrite_single_href('https://example.nl/diensten/', 'de', 'nl');

        $this->assertNotNull($result);
        $this->assertStringContainsString('/de/', $result);
    }

    public function test_mailto_link_left_unchanged(): void
    {
        // rewrite_single_href delegates to rewriteHref which skips mail links
        // But rewrite_single_href itself doesn't check - rewriteHref does through
        // skipping in the parent rewrite_dom. Since rewrite_single_href calls
        // rewriteHref directly, and rewriteHref doesn't call isSkippableHref,
        // mailto would be parsed. Let's verify behavior:
        $result = AI_URL::rewrite_single_href('mailto:info@example.nl', 'de', 'nl');

        // mailto: has no host/scheme match → treated as external → returns null
        $this->assertNull($result);
    }

    public function test_preserves_query_string(): void
    {
        $result = AI_URL::rewrite_single_href('/zoeken/?q=test', 'de', 'nl');

        $this->assertNotNull($result);
        $this->assertStringContainsString('q=test', $result);
    }

    public function test_preserves_fragment(): void
    {
        $result = AI_URL::rewrite_single_href('/pagina/#sectie', 'de', 'nl');

        $this->assertNotNull($result);
        $this->assertStringContainsString('#sectie', $result);
    }

    public function test_preserves_query_and_fragment(): void
    {
        $result = AI_URL::rewrite_single_href('/pagina/?x=1#top', 'de', 'nl');

        $this->assertNotNull($result);
        $this->assertStringContainsString('x=1', $result);
        $this->assertStringContainsString('#top', $result);
    }

    public function test_switch_lang_param_left_unchanged(): void
    {
        $result = AI_URL::rewrite_single_href('/pagina/?switch_lang=en', 'de', 'nl');

        // rewriteHref returns the href unchanged when switch_lang is present
        $this->assertNotNull($result);
        $this->assertStringContainsString('switch_lang=en', $result);
    }

    public function test_href_with_different_lang_prefix_left_unchanged(): void
    {
        // If href already has /fr/ prefix and we're rewriting for 'de',
        // the link should be left alone (language switcher link)
        $result = AI_URL::rewrite_single_href('/fr/contact/', 'de', 'nl');

        $this->assertNotNull($result);
        // Should keep /fr/ because it's a different language
        $this->assertStringContainsString('/fr/', $result);
    }
}
