<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

use function AITranslate\model_is_retired;
use function AITranslate\store_live_models;

final class RetiredModelTest extends TestCase
{
    private function stored(array $ids, int $age = 0): void
    {
        $opt = ['openai' => ['ids' => $ids, 'ts' => time() - $age]];
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($opt) {
            return $name === \AITranslate\LIVE_MODELS_OPTION ? $opt : $default;
        });
    }

    public function test_model_missing_from_fresh_list_is_retired(): void
    {
        $this->stored(['gpt-6-luna', 'gpt-4o']);
        $this->assertTrue(model_is_retired('openai', 'gpt-5-mini'));
        $this->assertFalse(model_is_retired('openai', 'gpt-4o'));
    }

    public function test_stale_or_missing_list_never_retires_a_model(): void
    {
        $this->stored(['gpt-6-luna'], 8 * DAY_IN_SECONDS);
        $this->assertFalse(model_is_retired('openai', 'gpt-4o'));
        Functions\when('get_option')->justReturn([]);
        $this->assertFalse(model_is_retired('openai', 'gpt-4o'));
        $this->assertFalse(model_is_retired('groq', 'x'));
        $this->assertFalse(model_is_retired('openai', ''));
    }

    public function test_store_keeps_ids_per_provider_and_ignores_empty_lists(): void
    {
        $saved = null;
        Functions\when('get_option')->justReturn(['groq' => ['ids' => ['a'], 'ts' => 1]]);
        Functions\when('update_option')->alias(static function ($name, $value) use (&$saved) {
            $saved = $value;
            return true;
        });
        store_live_models('openai', [['id' => 'gpt-4o'], 'gpt-4o', ['id' => 'o1'], ['nope' => 1]]);
        $this->assertSame(['gpt-4o', 'o1'], $saved['openai']['ids']);
        $this->assertSame(['a'], $saved['groq']['ids']);

        $saved = null;
        store_live_models('openai', []);
        $this->assertNull($saved);
    }
}
