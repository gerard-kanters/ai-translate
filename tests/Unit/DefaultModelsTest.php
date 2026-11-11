<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;

use Brain\Monkey\Functions;

use const AITranslate\DEFAULT_MODELS_OPTION;
use function AITranslate\bundled_default_models;
use function AITranslate\default_model_lists;
use function AITranslate\refresh_default_models;

final class DefaultModelsTest extends TestCase
{
    public function test_every_provider_has_a_list_of_valid_ids(): void
    {
        $this->option([]);
        $lists = default_model_lists();
        foreach (['openai', 'openrouter', 'deepinfra', 'deepseek', 'groq', 'anthropic'] as $provider) {
            $this->assertNotEmpty($lists[$provider] ?? [], $provider);
        }
        $this->assertSame('deepseek-flash', $lists['deepseek'][0]);
        $this->assertSame('gpt-6-luna', $lists['openai'][0]);
    }

    private function option(array $value): void
    {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($value) {
            return $name === DEFAULT_MODELS_OPTION ? $value : $default;
        });
    }

    public function test_downloaded_list_wins_per_provider_when_not_older_than_bundled(): void
    {
        $bundled = bundled_default_models();
        $this->option(['lists' => ['groq' => ['x/new-model']], 'updated' => '2999-01-01']);
        $lists = default_model_lists();
        $this->assertSame(['x/new-model'], $lists['groq']);
        $this->assertSame($bundled['lists']['openai'], $lists['openai']);
    }

    public function test_downloaded_list_older_than_bundled_is_ignored(): void
    {
        $this->option(['lists' => ['groq' => ['x/old-model']], 'updated' => '2000-01-01']);
        $this->assertSame(bundled_default_models()['lists']['groq'], default_model_lists()['groq']);
    }

    private function fetch(string $body, int $code, ?array &$saved, int $checked = 0): void
    {
        Functions\when('apply_filters')->alias(static fn ($tag, $value) => $value);
        $this->option(['checked' => $checked]);
        Functions\when('wp_remote_get')->justReturn(['body' => $body]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('update_option')->alias(static function ($name, $value) use (&$saved) {
            $saved = $value;
            return true;
        });
    }

    public function test_refresh_stores_a_valid_download(): void
    {
        $saved = null;
        $this->fetch(json_encode(['updated' => '2999-01-01', 'providers' => ['groq' => ['x/new-model']]]), 200, $saved);
        refresh_default_models();
        $this->assertSame(['groq' => ['x/new-model']], $saved['lists']);
        $this->assertSame('2999-01-01', $saved['updated']);
    }

    public function test_refresh_keeps_the_old_list_on_garbage_and_skips_when_checked_recently(): void
    {
        $saved = null;
        $this->fetch('<html>', 200, $saved);
        refresh_default_models();
        $this->assertArrayNotHasKey('lists', $saved);
        $this->assertGreaterThan(0, $saved['checked']);

        $saved = null;
        $this->fetch(json_encode(['updated' => '2999-01-01', 'providers' => ['groq' => ['x/y']]]), 200, $saved, time());
        refresh_default_models();
        $this->assertNull($saved);
    }

    public function test_damaged_file_gives_no_lists_and_bad_ids_are_dropped(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dm');
        file_put_contents($file, '{not json');
        $this->assertSame([], default_model_lists($file));
        file_put_contents($file, json_encode(['providers' => ['groq' => ['ok/model-1', '../x y', 5], 'bad' => 'text']]));
        $this->assertSame(['groq' => ['ok/model-1']], default_model_lists($file));
        unlink($file);
    }
}
