<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_DOM;
use AITranslate\Tests\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_DOM::class)]
final class AI_DOMTest extends TestCase
{
    // ---------------------------------------------------------------
    //  plan()
    // ---------------------------------------------------------------

    public function test_plan_extracts_paragraph_text(): void
    {
        $html = '<html><body><p>Welkom op onze website</p></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        $this->assertContains('Welkom op onze website', $texts);
    }

    public function test_plan_extracts_multiple_segments(): void
    {
        $html = '<html><body><h1>Titel</h1><p>Paragraaf tekst</p></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        $this->assertContains('Titel', $texts);
        $this->assertContains('Paragraaf tekst', $texts);
    }

    public function test_plan_extracts_title_attribute(): void
    {
        $html = '<html><body><a href="/" title="Naar home">Link</a></body></html>';
        $plan = AI_DOM::plan($html);

        $attrs = array_filter($plan['segments'], fn($s) => ($s['type'] ?? '') === 'attr' && ($s['attr'] ?? '') === 'title');
        $attrTexts = array_column($attrs, 'text');
        $this->assertContains('Naar home', $attrTexts);
    }

    public function test_plan_extracts_aria_label_attribute(): void
    {
        $html = '<html><body><button aria-label="Menu openen">☰</button></body></html>';
        $plan = AI_DOM::plan($html);

        $attrs = array_filter($plan['segments'], fn($s) => ($s['attr'] ?? '') === 'aria-label');
        $attrTexts = array_column($attrs, 'text');
        $this->assertContains('Menu openen', $attrTexts);
    }

    public function test_plan_extracts_img_alt_attribute(): void
    {
        $html = '<html><body><div><img alt="Logo afbeelding" src="logo.png"></div></body></html>';
        $plan = AI_DOM::plan($html);

        $attrs = array_filter($plan['segments'], fn($s) => ($s['attr'] ?? '') === 'alt');
        $attrTexts = array_column($attrs, 'text');
        $this->assertContains('Logo afbeelding', $attrTexts);
    }

    public function test_plan_skips_img_alt_inside_notranslate(): void
    {
        $html = '<html><body><div class="notranslate"><img alt="Skip dit" src="x.png"></div><img alt="Vertaal dit" src="y.png"></body></html>';
        $plan = AI_DOM::plan($html);

        $attrs = array_filter($plan['segments'], fn($s) => ($s['attr'] ?? '') === 'alt');
        $attrTexts = array_column($attrs, 'text');
        $this->assertNotContains('Skip dit', $attrTexts);
        $this->assertContains('Vertaal dit', $attrTexts);
    }

    public function test_plan_skips_img_alt_shorter_than_2_chars(): void
    {
        $html = '<html><body><img alt="X" src="x.png"><img alt="Goede tekst" src="y.png"></body></html>';
        $plan = AI_DOM::plan($html);

        $attrs = array_filter($plan['segments'], fn($s) => ($s['attr'] ?? '') === 'alt');
        $attrTexts = array_column($attrs, 'text');
        $this->assertNotContains('X', $attrTexts);
        $this->assertContains('Goede tekst', $attrTexts);
    }

    public function test_plan_extracts_placeholder_attribute(): void
    {
        $html = '<html><body><input type="text" placeholder="Voer uw naam in"></body></html>';
        $plan = AI_DOM::plan($html);

        $attrs = array_filter($plan['segments'], fn($s) => ($s['attr'] ?? '') === 'placeholder');
        $attrTexts = array_column($attrs, 'text');
        $this->assertContains('Voer uw naam in', $attrTexts);
    }

    public function test_plan_extracts_submit_button_value(): void
    {
        $html = '<html><body><input type="submit" value="Verzenden"></body></html>';
        $plan = AI_DOM::plan($html);

        $attrs = array_filter($plan['segments'], fn($s) => ($s['attr'] ?? '') === 'value');
        $attrTexts = array_column($attrs, 'text');
        $this->assertContains('Verzenden', $attrTexts);
    }

    public function test_plan_excludes_script_content(): void
    {
        $html = '<html><head></head><body><p>Zichtbaar</p><script>var x = "niet vertalen";</script></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        foreach ($texts as $t) {
            $this->assertStringNotContainsString('niet vertalen', $t);
        }
    }

    public function test_plan_excludes_style_content(): void
    {
        $html = '<html><head></head><body><p>Zichtbaar</p><style>.red { color: red; }</style></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        foreach ($texts as $t) {
            $this->assertStringNotContainsString('color', $t);
        }
    }

    public function test_plan_preserves_scripts_as_placeholders(): void
    {
        $html = '<html><head></head><body><p>Tekst</p><script>alert("hi");</script></body></html>';
        $plan = AI_DOM::plan($html);

        $this->assertNotEmpty($plan['placeholders']);
        $placeholderValues = array_values($plan['placeholders']);
        $this->assertStringContainsString('alert("hi")', $placeholderValues[0]);
    }

    public function test_plan_excludes_code_and_pre_tags(): void
    {
        $html = '<html><body><pre>code block</pre><code>inline code</code><p>Normaal</p></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        foreach ($texts as $t) {
            $this->assertStringNotContainsString('code block', $t);
            $this->assertStringNotContainsString('inline code', $t);
        }
        $this->assertContains('Normaal', $texts);
    }

    public function test_plan_skips_data_ai_trans_skip(): void
    {
        $html = '<html><body><p data-ai-trans-skip>Niet vertalen</p><p>Wel vertalen</p></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        $this->assertNotContains('Niet vertalen', $texts);
        $this->assertContains('Wel vertalen', $texts);
    }

    public function test_plan_skips_notranslate_class(): void
    {
        $html = '<html><body><div class="notranslate"><p>Overslaan</p></div><p>Vertalen</p></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        $this->assertNotContains('Overslaan', $texts);
        $this->assertContains('Vertalen', $texts);
    }

    public function test_plan_detects_menu_context(): void
    {
        $html = '<html><body><nav><ul><li><a href="/">Home</a></li></ul></nav></body></html>';
        $plan = AI_DOM::plan($html);

        $menuSegments = array_filter($plan['segments'], fn($s) => ($s['type'] ?? '') === 'menu');
        $this->assertNotEmpty($menuSegments, 'Expected at least one menu-type segment inside <nav>');
    }

    public function test_plan_extracts_og_meta_tags(): void
    {
        $html = '<html><head><meta property="og:title" content="Pagina Titel"><meta property="og:description" content="Omschrijving"></head><body></body></html>';
        $plan = AI_DOM::plan($html);

        $metaSegments = array_filter($plan['segments'], fn($s) => str_starts_with($s['id'], 'm'));
        $metaTexts = array_column($metaSegments, 'text');
        $this->assertContains('Pagina Titel', $metaTexts);
        $this->assertContains('Omschrijving', $metaTexts);
    }

    public function test_plan_removes_speculationrules(): void
    {
        $html = '<html><head></head><body><p>Tekst</p><script type="speculationrules">{"prefetch": []}</script></body></html>';
        $plan = AI_DOM::plan($html);

        $allText = implode(' ', array_column($plan['segments'], 'text'));
        $this->assertStringNotContainsString('prefetch', $allText);

        foreach ($plan['placeholders'] as $original) {
            $this->assertStringNotContainsString('speculationrules', $original);
        }
    }

    public function test_plan_returns_correct_structure(): void
    {
        $html = '<html><body><p>Test</p></body></html>';
        $plan = AI_DOM::plan($html);

        $this->assertArrayHasKey('doc', $plan);
        $this->assertArrayHasKey('segments', $plan);
        $this->assertArrayHasKey('nodeIndex', $plan);
        $this->assertArrayHasKey('originalHTML', $plan);
        $this->assertArrayHasKey('placeholders', $plan);
        $this->assertInstanceOf(\DOMDocument::class, $plan['doc']);
    }

    public function test_plan_empty_body_returns_no_segments(): void
    {
        $html = '<html><body></body></html>';
        $plan = AI_DOM::plan($html);

        $this->assertEmpty($plan['segments']);
    }

    public function test_plan_skips_wp_admin_bar(): void
    {
        $html = '<html><body><div id="wpadminbar"><span>Admin tekst</span></div><p>Pagina tekst</p></body></html>';
        $plan = AI_DOM::plan($html);

        $texts = array_column($plan['segments'], 'text');
        $this->assertNotContains('Admin tekst', $texts);
        $this->assertContains('Pagina tekst', $texts);
    }

    // ---------------------------------------------------------------
    //  merge()
    // ---------------------------------------------------------------

    public function test_merge_replaces_text_content(): void
    {
        $html = '<html><body><p>Hello world</p></body></html>';
        $plan = AI_DOM::plan($html);

        $translations = [];
        foreach ($plan['segments'] as $seg) {
            if ($seg['text'] === 'Hello world') {
                $translations[$seg['id']] = 'Hallo wereld';
            }
        }

        $result = AI_DOM::merge($plan, $translations);
        $this->assertStringContainsString('Hallo wereld', $result);
        $this->assertStringNotContainsString('Hello world', $result);
    }

    public function test_merge_replaces_attribute(): void
    {
        $html = '<html><body><a href="/" title="Go home">Link</a></body></html>';
        $plan = AI_DOM::plan($html);

        $translations = [];
        foreach ($plan['segments'] as $seg) {
            if (($seg['attr'] ?? '') === 'title') {
                $translations[$seg['id']] = 'Ga naar huis';
            }
        }

        $result = AI_DOM::merge($plan, $translations);
        $this->assertStringContainsString('Ga naar huis', $result);
    }

    public function test_merge_preserves_leading_trailing_whitespace(): void
    {
        $html = '<html><body><p> Hello </p></body></html>';
        $plan = AI_DOM::plan($html);

        $translations = [];
        foreach ($plan['segments'] as $seg) {
            if (trim($seg['text']) === 'Hello') {
                $translations[$seg['id']] = 'Hallo';
            }
        }

        $result = AI_DOM::merge($plan, $translations);
        $this->assertMatchesRegularExpression('/\s+Hallo\s+/', $result);
    }

    public function test_merge_skips_missing_translations(): void
    {
        $html = '<html><body><p>Keep this</p><p>Also keep</p></body></html>';
        $plan = AI_DOM::plan($html);

        // Provide no translations at all
        $result = AI_DOM::merge($plan, []);
        $this->assertStringContainsString('Keep this', $result);
        $this->assertStringContainsString('Also keep', $result);
    }

    public function test_merge_restores_script_placeholders(): void
    {
        $html = '<html><head></head><body><p>Tekst</p><script>var x = 1;</script></body></html>';
        $plan = AI_DOM::plan($html);

        $this->assertNotEmpty($plan['placeholders']);

        $result = AI_DOM::merge($plan, []);
        $this->assertStringContainsString('<script>var x = 1;</script>', $result);
    }

    public function test_merge_restores_style_placeholders(): void
    {
        $html = '<html><head></head><body><p>Tekst</p><style>.red { color: red; }</style></body></html>';
        $plan = AI_DOM::plan($html);

        $result = AI_DOM::merge($plan, []);
        $this->assertStringContainsString('<style>.red { color: red; }</style>', $result);
    }

    public function test_merge_preserves_doctype(): void
    {
        $html = '<!DOCTYPE html><html><body><p>Test</p></body></html>';
        $plan = AI_DOM::plan($html);

        $result = AI_DOM::merge($plan, []);
        $this->assertStringContainsString('<!DOCTYPE html>', $result);
    }

    public function test_merge_handles_multiple_translations(): void
    {
        $html = '<html><body><h1>Title</h1><p>Body text</p></body></html>';
        $plan = AI_DOM::plan($html);

        $translations = [];
        foreach ($plan['segments'] as $seg) {
            if ($seg['text'] === 'Title') {
                $translations[$seg['id']] = 'Titel';
            }
            if ($seg['text'] === 'Body text') {
                $translations[$seg['id']] = 'Broodtekst';
            }
        }

        $result = AI_DOM::merge($plan, $translations);
        $this->assertStringContainsString('Titel', $result);
        $this->assertStringContainsString('Broodtekst', $result);
    }

    public function test_plan_and_merge_roundtrip_preserves_structure(): void
    {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><div><p>Paragraaf</p></div></body></html>';
        $plan = AI_DOM::plan($html);

        // Translate nothing - structure should remain intact
        $result = AI_DOM::merge($plan, []);
        $this->assertStringContainsString('<div>', $result);
        $this->assertStringContainsString('</div>', $result);
        $this->assertStringContainsString('Paragraaf', $result);
    }

    public function test_merge_handles_unicode_text(): void
    {
        $html = '<html><body><p>English text</p></body></html>';
        $plan = AI_DOM::plan($html);

        $translations = [];
        foreach ($plan['segments'] as $seg) {
            if ($seg['text'] === 'English text') {
                $translations[$seg['id']] = 'Текст на русском';
            }
        }

        $result = AI_DOM::merge($plan, $translations);
        // DOMDocument encodes non-ASCII as HTML entities; decode to verify content
        $decoded = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Текст на русском', $decoded);
    }

    public function test_merge_replaces_og_meta_content(): void
    {
        $html = '<html><head><meta property="og:title" content="Original Title"></head><body></body></html>';
        $plan = AI_DOM::plan($html);

        $translations = [];
        foreach ($plan['segments'] as $seg) {
            if ($seg['text'] === 'Original Title') {
                $translations[$seg['id']] = 'Vertaalde Titel';
            }
        }

        $result = AI_DOM::merge($plan, $translations);
        $this->assertStringContainsString('Vertaalde Titel', $result);
    }
}
