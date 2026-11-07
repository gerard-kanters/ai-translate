<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;

use function AITranslate\api_key_for;
use function AITranslate\store_api_key;

final class ApiKeysTest extends TestCase
{
    public function test_other_providers_use_their_own_key(): void
    {
        $this->assertSame('K', api_key_for(['api_keys' => ['openai' => 'K']], 'openai'));
        $this->assertSame('', api_key_for([], 'openai'));
    }

    public function test_legacy_custom_key_is_used_until_a_host_has_its_own(): void
    {
        $s = ['custom_api_url' => 'https://openrouter.ai/api/v1/', 'api_keys' => ['custom' => 'OR']];
        $this->assertSame('OR', api_key_for($s, 'custom'));
        $this->assertSame('OR', api_key_for($s, 'custom', 'https://api.anthropic.com/v1/'));
    }

    public function test_every_custom_host_keeps_its_own_key(): void
    {
        $s = ['custom_api_url' => 'https://openrouter.ai/api/v1/', 'api_keys' => ['custom' => 'OR', 'openai' => 'OAI']];
        store_api_key($s, 'custom', 'ANT', 'https://api.anthropic.com/v1/');
        $this->assertArrayNotHasKey('custom', $s['api_keys']);
        $this->assertSame('OAI', $s['api_keys']['openai']);
        $this->assertSame('OR', api_key_for($s, 'custom', 'https://openrouter.ai/api/v1/'));
        $this->assertSame('ANT', api_key_for($s, 'custom', 'https://API.anthropic.com/v1'));
        $this->assertSame('', api_key_for($s, 'custom', 'https://api.perplexity.ai'));
        $s['custom_api_url'] = 'https://api.anthropic.com/v1/';
        $this->assertSame('ANT', api_key_for($s, 'custom'));
    }
}
