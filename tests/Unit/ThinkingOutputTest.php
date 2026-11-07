<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;

final class ThinkingOutputTest extends TestCase
{
    public function test_think_block_is_removed(): void
    {
        $this->assertSame('Hallo wereld', \AITranslate\strip_thinking_blocks("<think>even nadenken\nover vertaling</think>\n\nHallo wereld"));
        $this->assertSame('Hallo', \AITranslate\strip_thinking_blocks('<thinking>x</thinking>Hallo'));
    }

    public function test_closing_tag_without_opening_tag(): void
    {
        $this->assertSame('Hallo', \AITranslate\strip_thinking_blocks("redenering</think>Hallo"));
    }

    public function test_plain_text_is_untouched(): void
    {
        $this->assertSame('A <b>think</b> tank', \AITranslate\strip_thinking_blocks('A <b>think</b> tank'));
    }

    public function test_extract_message_content_strips_thinking(): void
    {
        $data = ['choices' => [['message' => ['content' => '<think>x</think>Bonjour']]]];
        $this->assertSame('Bonjour', \AITranslate\strip_thinking_blocks($data['choices'][0]['message']['content']));
    }

    public function test_mandatory_reasoning_retry(): void
    {
        $err = 'Reasoning is mandatory for this endpoint and cannot be disabled.';
        $this->assertTrue(\AITranslate\is_mandatory_reasoning_error($err));
        $this->assertFalse(\AITranslate\is_mandatory_reasoning_error('rate limit'));
        [$body, $learned] = \AITranslate\mandatory_reasoning_retry(['model' => 'm', 'reasoning' => ['effort' => 'none']]);
        $this->assertSame(['effort' => 'low'], $body['reasoning']);
        $this->assertSame(['reasoning' => ['effort' => 'low']], $learned);
        [$body] = \AITranslate\mandatory_reasoning_retry(['reasoning_effort' => 'none']);
        $this->assertSame('low', $body['reasoning_effort']);
        $this->assertNull(\AITranslate\mandatory_reasoning_retry(['model' => 'm']));
    }
}
