<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Lang;
use AITranslate\AI_OB;
use AITranslate\Tests\TestCase;

class AI_OBUntranslatedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AI_Lang::_setDefault('nl');
    }

    private function page(string $head = ''): string
    {
        return '<html><head><title>T</title>' . $head . '</head><body>x</body></html>';
    }

    public function test_adds_noindex_to_an_untranslated_page_on_a_language_url(): void
    {
        $out = AI_OB::mark_untranslated($this->page(), 'en');

        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $out);
        $this->assertLessThan(strpos($out, '</head>'), strpos($out, 'noindex'));
    }

    public function test_default_language_is_never_marked(): void
    {
        $page = $this->page();

        $this->assertSame($page, AI_OB::mark_untranslated($page, 'nl'));
        $this->assertSame($page, AI_OB::mark_untranslated($page, 'NL'));
        $this->assertSame($page, AI_OB::mark_untranslated($page, ''));
    }

    public function test_existing_index_directive_becomes_noindex_and_keeps_the_rest(): void
    {
        $out = AI_OB::mark_untranslated(
            $this->page('<meta name="robots" content="index, follow, max-image-preview:large" />'),
            'de'
        );

        $this->assertStringContainsString('content="noindex, follow, max-image-preview:large"', $out);
        $this->assertStringNotContainsString('content="index', $out);
        $this->assertSame(1, substr_count($out, 'name="robots"'), 'no second robots tag');
    }

    public function test_page_that_is_already_noindex_is_left_alone(): void
    {
        $page = $this->page('<meta name="robots" content="noindex, nofollow">');

        $this->assertSame($page, AI_OB::mark_untranslated($page, 'en'));
    }

    public function test_html_without_head_is_returned_unchanged(): void
    {
        $this->assertSame('<p>fragment</p>', AI_OB::mark_untranslated('<p>fragment</p>', 'en'));
    }
}
