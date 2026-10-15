<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_OB;
use AITranslate\Tests\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(AI_OB::class)]
final class AI_CachedStylesheetsTest extends TestCase
{
    private function refresh(string $cached, string $source): string
    {
        return (new \ReflectionMethod(AI_OB::class, 'refresh_cached_stylesheets'))->invoke(null, $cached, $source);
    }

    public function test_reuses_current_styles_in_order_and_preserves_translated_markup(): void
    {
        $cached = '<html><head><link rel="stylesheet" id="old-css" href="/removed.css"><style>.hero{color:blue}</style></head><body><nav>Preise</nav></body></html>';
        $source = "<html><head><link id='base-css' rel='stylesheet' href='/base.css?ver=2'><link rel='stylesheet' id='menu-css' href='/menu.css?ver=2'></head><body>Pricing</body></html>";
        $result = $this->refresh($cached, $source);
        $this->assertStringNotContainsString('/removed.css', $result);
        $this->assertStringContainsString("href='/menu.css?ver=2'", $result);
        $this->assertLessThan(strpos($result, "id='menu-css'"), strpos($result, "id='base-css'"));
        $this->assertStringContainsString('<style>.hero{color:blue}</style>', $result);
        $this->assertStringContainsString('<nav>Preise</nav>', $result);
        $this->assertSame(1, substr_count($result, "id='menu-css'"));
        $this->assertSame($result, $this->refresh($result, $source));
    }

    public function test_empty_source_and_incomplete_documents_are_left_unchanged(): void
    {
        $cached = '<html><head><link rel="stylesheet" id="menu-css" href="/menu.css"></head><body>Inhalt</body></html>';
        $this->assertSame($cached, $this->refresh($cached, ''));
        $fragment = '<nav>Preise</nav>';
        $this->assertSame($fragment, $this->refresh($fragment, $cached));
    }
}
