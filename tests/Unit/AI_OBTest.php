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
}
