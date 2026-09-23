<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_OB;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_OB::class)]
final class AI_OBTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_attr')->returnArg();
    }

    // ---------------------------------------------------------------
    //  apply_html_lang_dir()
    // ---------------------------------------------------------------

    public function test_replaces_existing_lang_attribute(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'de');

        $this->assertStringContainsString('<html lang="de-DE">', $result);
        $this->assertStringNotContainsString('nl-NL', $result);
    }

    public function test_adds_lang_attribute_when_missing(): void
    {
        $html = '<html class="no-js"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'fr');

        $this->assertStringContainsString('lang="fr-FR"', $result);
        // Existing attributes must be preserved
        $this->assertStringContainsString('class="no-js"', $result);
    }

    public function test_adds_dir_rtl_for_arabic(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertStringContainsString('lang="ar"', $result);
        $this->assertStringContainsString('dir="rtl"', $result);
    }

    public function test_adds_dir_rtl_for_hebrew(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'he');

        $this->assertStringContainsString('lang="he"', $result);
        $this->assertStringContainsString('dir="rtl"', $result);
    }

    public function test_replaces_existing_dir_ltr_for_rtl_target(): void
    {
        $html = '<html lang="nl-NL" dir="ltr" class="wp"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertStringContainsString('dir="rtl"', $result);
        $this->assertStringNotContainsString('dir="ltr"', $result);
        $this->assertMatchesRegularExpression('/<html\b[^>]*\bclass="rtl wp"/', $result);
    }

    public function test_does_not_add_dir_for_ltr_target(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'de');

        $this->assertStringContainsString('lang="de-DE"', $result);
        $this->assertStringNotContainsString('dir=', $result);
    }

    public function test_leaves_existing_dir_untouched_for_ltr_target(): void
    {
        $html = '<html lang="nl-NL" dir="ltr"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'de');

        $this->assertStringContainsString('dir="ltr"', $result);
        $this->assertStringNotContainsString('dir="rtl"', $result);
    }

    public function test_handles_single_quoted_attributes(): void
    {
        $html = "<html lang='nl-NL' dir='ltr'><head></head><body>tekst</body></html>";

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertStringContainsString('lang="ar"', $result);
        $this->assertStringContainsString('dir="rtl"', $result);
    }

    public function test_adds_rtl_class_to_html_and_body_for_arabic(): void
    {
        $html = '<html lang="nl-NL"><head></head><body class="home">tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertMatchesRegularExpression('/<html\b[^>]*\bclass="rtl"/', $result);
        $this->assertMatchesRegularExpression('/<body\b[^>]*\bclass="rtl home"/', $result);
    }

    public function test_adds_rtl_class_when_body_has_no_class_attr(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'he');

        $this->assertMatchesRegularExpression('/<body\b[^>]*\bclass="rtl"/', $result);
    }

    public function test_does_not_duplicate_existing_rtl_class(): void
    {
        $html = '<html lang="ar" class="rtl" dir="rtl"><head></head><body class="rtl home">tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertSame(1, preg_match_all('/<html\b[^>]*\bclass="rtl"/', $result));
        $this->assertSame(1, preg_match_all('/<body\b[^>]*\bclass="rtl home"/', $result));
        $this->assertDoesNotMatchRegularExpression('/\brtl\s+rtl\b/', $result);
    }

    public function test_does_not_add_rtl_class_for_ltr_target(): void
    {
        $html = '<html lang="nl-NL"><head></head><body class="home">tekst</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'de');

        $this->assertStringNotContainsString('rtl', $result);
    }

    public function test_is_idempotent(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>tekst</body></html>';

        $once = AI_OB::apply_html_lang_dir($html, 'ar');
        $twice = AI_OB::apply_html_lang_dir($once, 'ar');

        $this->assertSame($once, $twice);
    }

    public function test_strips_nested_dir_ltr_for_rtl_target(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>'
            . '<h2 dir="ltr">ما هو ASA؟</h2>'
            . '<p dir="ltr" class="lead">نص</p>'
            . '<p class="note" dir=\'ltr\'>نص</p>'
            . '<ul dir="rtl"><li>نص</li></ul>'
            . '</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertStringContainsString('<html', $result);
        $this->assertMatchesRegularExpression('/<html\b[^>]*\sdir="rtl"/', $result);
        $this->assertStringNotContainsString('dir="ltr"', $result);
        $this->assertStringNotContainsString("dir='ltr'", $result);
        $this->assertStringContainsString('<h2>', $result);
        $this->assertStringContainsString('<p class="lead">', $result);
        $this->assertStringContainsString('<p class="note">', $result);
        $this->assertStringContainsString('<ul dir="rtl">', $result);

        $twice = AI_OB::apply_html_lang_dir($result, 'ar');
        $this->assertSame($result, $twice);
    }

    public function test_keeps_nested_dir_ltr_for_ltr_target(): void
    {
        $html = '<html lang="nl-NL"><head></head><body><h2 dir="ltr">Wat is ASA?</h2></body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'de');

        $this->assertStringContainsString('<h2 dir="ltr">', $result);
    }

    public function test_flips_inline_text_align_left_to_right_for_rtl(): void
    {
        $html = '<html lang="nl-NL"><head></head><body><p style="text-align: left;">tekst</p></body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertStringContainsString('text-align: right', $result);
        $this->assertStringNotContainsString('text-align: left', $result);
    }

    public function test_inline_text_align_flip_is_idempotent_on_rtl_reserver(): void
    {
        $html = '<html lang="nl-NL"><head></head><body><p style="text-align: left; color: red;">tekst</p></body></html>';

        $once = AI_OB::apply_html_lang_dir($html, 'ar');
        $twice = AI_OB::apply_html_lang_dir($once, 'ar');

        $this->assertSame($once, $twice);
        $this->assertStringContainsString('text-align: right', $twice);
        $this->assertSame(1, substr_count(strtolower($twice), 'text-align: right'));
    }

    public function test_does_not_flip_inline_text_align_for_ltr_target(): void
    {
        $html = '<html lang="nl-NL"><head></head><body><p style="text-align: left;">tekst</p></body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'de');

        $this->assertStringContainsString('text-align: left', $result);
        $this->assertStringNotContainsString('text-align: right', $result);
    }

    public function test_rewrites_stylesheet_href_to_rtl_when_file_exists(): void
    {
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', sys_get_temp_dir() . '/ai-tr-rtl-test-content');
        }
        $dir = rtrim((string) WP_CONTENT_DIR, '/\\') . '/themes/testrtl';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $ltr = $dir . '/style.css';
        $rtl = $dir . '/style-rtl.css';
        file_put_contents($ltr, 'body{direction:ltr}');
        file_put_contents($rtl, 'body{direction:rtl}');

        Functions\when('content_url')->justReturn('https://example.test/wp-content/');
        Functions\when('wp_parse_url')->alias(static function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('trailingslashit')->alias(static function ($s) {
            return rtrim((string) $s, '/\\') . '/';
        });

        $html = '<html lang="nl"><head>'
            . '<link rel="stylesheet" href="https://example.test/wp-content/themes/testrtl/style.css?ver=1" />'
            . '</head><body>x</body></html>';

        $result = AI_OB::apply_html_lang_dir($html, 'ar');

        $this->assertStringContainsString('style-rtl.css?ver=1', $result);
        $this->assertStringNotContainsString('themes/testrtl/style.css?', $result);

        @unlink($ltr);
        @unlink($rtl);
        @rmdir($dir);
    }

    public function test_returns_html_unchanged_for_empty_lang(): void
    {
        $html = '<html lang="nl-NL"><head></head><body>tekst</body></html>';

        $this->assertSame($html, AI_OB::apply_html_lang_dir($html, ''));
        $this->assertSame($html, AI_OB::apply_html_lang_dir($html, null));
    }

    public function test_returns_html_unchanged_without_html_tag(): void
    {
        $html = '<p>geen html-tag</p>';

        $this->assertSame($html, AI_OB::apply_html_lang_dir($html, 'ar'));
    }

    // ---------------------------------------------------------------
    //  post_process_cached_content() — admin bar on cached pages
    // ---------------------------------------------------------------

    public function test_injects_admin_bar_class_and_html_margin_for_logged_in_admin(): void
    {
        Functions\when('is_admin_bar_showing')->justReturn(true);

        $html = '<html lang="en-GB"><body class="home hestia"><nav class="navbar navbar-fixed-top">Menu</nav></body></html>';
        $result = $this->postProcessCached($html);

        $this->assertMatchesRegularExpression('/<body\b[^>]*\bclass="[^"]*\badmin-bar\b/', $result);
        $this->assertStringContainsString('id="wpadminbar"', $result);
        $this->assertStringContainsString('html{margin-top:32px!important}', $result);
        $this->assertStringContainsString('.navbar-fixed-top,.fixed-top{top:32px!important;margin-top:0!important}', $result);
        $this->assertStringNotContainsString('body{margin-top:32px', $result);
    }

    public function test_adds_admin_bar_class_when_body_has_no_class(): void
    {
        Functions\when('is_admin_bar_showing')->justReturn(true);

        $html = '<html><body><p>tekst</p></body></html>';
        $result = $this->postProcessCached($html);

        $this->assertMatchesRegularExpression('/<body\b[^>]*\bclass="admin-bar"/', $result);
        $this->assertStringContainsString('id="wpadminbar"', $result);
    }

    public function test_does_not_duplicate_admin_bar_class(): void
    {
        Functions\when('is_admin_bar_showing')->justReturn(true);

        $html = '<html><body class="admin-bar home"><p>tekst</p></body></html>';
        $result = $this->postProcessCached($html);

        $this->assertSame(1, preg_match_all('/\badmin-bar\b/', $result));
        $this->assertStringContainsString('id="wpadminbar"', $result);
    }

    public function test_does_not_inject_admin_bar_for_visitors(): void
    {
        Functions\when('is_admin_bar_showing')->justReturn(false);

        $html = '<html><body class="home hestia"><nav class="navbar">Menu</nav></body></html>';
        $result = $this->postProcessCached($html);

        $this->assertSame($html, $result);
        $this->assertStringNotContainsString('wpadminbar', $result);
        $this->assertStringNotContainsString('admin-bar', $result);
    }

    private function postProcessCached(string $html): string
    {
        $method = new \ReflectionMethod(AI_OB::class, 'post_process_cached_content');
        return (string) $method->invoke(AI_OB::instance(), $html);
    }
}
