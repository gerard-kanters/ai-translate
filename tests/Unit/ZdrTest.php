<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Translate_Core;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

use function AITranslate\apply_zdr;
use function AITranslate\is_openrouter_url;
use function AITranslate\is_zdr_error;
use function AITranslate\zdr_provider_is_openrouter;

final class ZdrTest extends TestCase
{
    private const OPENROUTER = 'https://openrouter.ai/api/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
    }

    public function test_openrouter_hosts_are_recognised(): void
    {
        $this->assertTrue(is_openrouter_url(self::OPENROUTER));
        $this->assertTrue(is_openrouter_url('https://eu.openrouter.ai/api/v1'));
        $this->assertFalse(is_openrouter_url('https://api.openai.com/v1/chat/completions'));
        $this->assertFalse(is_openrouter_url('https://openrouter.ai.evil.example/v1'));
    }

    public function test_off_setting_leaves_the_body_alone(): void
    {
        $body = ['model' => 'a/b', 'messages' => []];

        $this->assertSame($body, apply_zdr(self::OPENROUTER, $body));
    }

    public function test_on_setting_adds_the_flag_to_an_array_body(): void
    {
        AI_Translate_Core::_set_setting('openrouter_zdr', true);

        $out = apply_zdr(self::OPENROUTER, ['model' => 'a/b']);

        $this->assertTrue($out['provider']['zdr']);
        $this->assertSame('deny', $out['provider']['data_collection']);
        $this->assertSame('a/b', $out['model']);
    }

    public function test_on_setting_adds_the_flag_to_a_json_body_and_keeps_other_provider_options(): void
    {
        AI_Translate_Core::_set_setting('openrouter_zdr', true);

        $out = json_decode((string) apply_zdr(self::OPENROUTER, '{"model":"a/b","provider":{"sort":"price"}}'), true);

        $this->assertTrue($out['provider']['zdr']);
        $this->assertSame('price', $out['provider']['sort']);
    }

    public function test_other_providers_never_get_the_flag(): void
    {
        AI_Translate_Core::_set_setting('openrouter_zdr', true);
        $body = ['model' => 'gpt-4o-mini'];

        $this->assertSame($body, apply_zdr('https://api.openai.com/v1/chat/completions', $body));
    }

    public function test_force_applies_the_flag_before_the_setting_is_saved(): void
    {
        $out = apply_zdr(self::OPENROUTER, ['model' => 'a/b'], true);

        $this->assertTrue($out['provider']['zdr']);
    }

    public function test_a_body_that_is_not_json_is_returned_unchanged(): void
    {
        AI_Translate_Core::_set_setting('openrouter_zdr', true);

        $this->assertSame('not json', apply_zdr(self::OPENROUTER, 'not json'));
    }

    public function test_zdr_errors_are_recognised(): void
    {
        $this->assertTrue(is_zdr_error('{"error":{"message":"No endpoints found matching your data policy (Zero data retention)."}}'));
        $this->assertFalse(is_zdr_error('{"error":{"message":"Invalid API key"}}'));
    }

    public function test_provider_is_openrouter_directly_or_through_a_custom_url(): void
    {
        $this->assertTrue(zdr_provider_is_openrouter(['api_provider' => 'openrouter']));
        $this->assertTrue(zdr_provider_is_openrouter(['api_provider' => 'custom', 'custom_api_url' => 'https://openrouter.ai/api/v1']));
        $this->assertFalse(zdr_provider_is_openrouter(['api_provider' => 'custom', 'custom_api_url' => 'https://example.com/v1']));
        $this->assertFalse(zdr_provider_is_openrouter(['api_provider' => 'openai']));
    }
}
