<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Lang;
use AITranslate\AI_SEO;
use AITranslate\Tests\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_SEO::class)]
final class AI_SEOMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMetaCache();
    }

    private function resetMetaCache(): void
    {
        $prop = new \ReflectionProperty(AI_SEO::class, 'metaTranslationCache');
        $prop->setValue(null, []);
    }

    private function metaCache(): array
    {
        $prop = new \ReflectionProperty(AI_SEO::class, 'metaTranslationCache');
        return $prop->getValue();
    }

    public function test_translated_titles_are_registered_as_already_translated(): void
    {
        $title = 'Information – MyVox';
        AI_SEO::mark_translated(['t1' => $title], 'sv');

        $cache = $this->metaCache();
        $this->assertSame($title, $cache['sv|' . md5($title)] ?? null);
    }

    public function test_short_or_single_word_results_are_not_registered(): void
    {
        AI_SEO::mark_translated(['a' => 'Hem', 'b' => 'Kontakta', 'c' => 'Langwoordzonderspaties'], 'sv');

        $this->assertSame([], $this->metaCache());
    }

    public function test_non_string_values_and_empty_language_are_ignored(): void
    {
        AI_SEO::mark_translated(['a' => ['x'], 'b' => 12345], 'sv');
        AI_SEO::mark_translated(['c' => 'Dit is een lange vertaalde zin'], '');

        $this->assertSame([], $this->metaCache());
    }

    public function test_surrounding_whitespace_variants_are_both_registered(): void
    {
        $value = "  Dit is een lange vertaalde zin \n";
        AI_SEO::mark_translated(['a' => $value], 'de');

        $cache = $this->metaCache();
        $this->assertArrayHasKey('de|' . md5(trim($value)), $cache);
        $this->assertArrayHasKey('de|' . md5($value), $cache);
    }

    public function test_prefetch_is_empty_for_the_default_language(): void
    {
        AI_Lang::_setDefault('nl');

        $this->assertSame([], AI_SEO::prefetch_segments('<html><head><title>Titel</title></head><body></body></html>', 'nl'));
    }

    public function test_prefetch_is_empty_for_empty_html(): void
    {
        AI_Lang::_setDefault('nl');

        $this->assertSame([], AI_SEO::prefetch_segments('', 'sv'));
    }
}
