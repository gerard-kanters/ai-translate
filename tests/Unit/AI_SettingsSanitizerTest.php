<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Settings_Sanitizer;
use AITranslate\AI_Translate_Core;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

use const AITranslate\KEY_MASK;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_Settings_Sanitizer::class)]
final class AI_SettingsSanitizerTest extends TestCase
{
    /** @var array<int,array{0:string,1:string,2:string}> Messages sent to add_settings_error: code, message, type */
    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->errors = [];
        Functions\when('sanitize_text_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('sanitize_textarea_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('__')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('wp_parse_url')->alias(static fn($url, $component = -1) => parse_url((string) $url, $component));
        Functions\when('add_settings_error')->alias(function ($setting, $code, $message, $type = 'error') {
            $this->errors[] = [$code, $message, $type];
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
        parent::tearDown();
    }

    private function stored(array $settings): void
    {
        foreach ($settings as $key => $value) {
            AI_Translate_Core::_set_setting($key, $value);
        }
    }

    private function errorCodes(): array
    {
        return array_column($this->errors, 0);
    }

    // ---------------------------------------------------------------
    //  What is kept
    // ---------------------------------------------------------------

    public function test_stored_settings_the_input_does_not_mention_are_kept(): void
    {
        $this->stored(['api_provider' => 'openai', 'glossary' => 'a=b', 'cache_expiration' => 336, 'unknown_key' => 'x']);

        $out = AI_Settings_Sanitizer::sanitize(['default_language' => 'nl']);

        $this->assertSame('openai', $out['api_provider']);
        $this->assertSame('a=b', $out['glossary']);
        $this->assertSame('x', $out['unknown_key']);
        $this->assertSame('nl', $out['default_language']);
    }

    public function test_input_that_is_not_an_array_changes_nothing(): void
    {
        $this->stored(['api_provider' => 'deepseek', 'cache_expiration' => 336]);

        $this->assertSame('deepseek', AI_Settings_Sanitizer::sanitize(null)['api_provider']);
    }

    public function test_probe_results_written_by_the_plugin_survive(): void
    {
        $out = AI_Settings_Sanitizer::sanitize([
            'temperature_supported' => ['deepseek:v4' => false],
            'reasoning_params' => ['deepseek:v4' => ['thinking' => 'off']],
            'temperature_supported_x' => 'ignored',
        ]);

        $this->assertSame(['deepseek:v4' => false], $out['temperature_supported']);
        $this->assertSame(['deepseek:v4' => ['thinking' => 'off']], $out['reasoning_params']);
        $this->assertArrayNotHasKey('temperature_supported_x', $out);
    }

    // ---------------------------------------------------------------
    //  Provider, URL, model and key
    // ---------------------------------------------------------------

    public function test_an_unknown_provider_is_refused_and_the_old_one_stays(): void
    {
        $this->stored(['api_provider' => 'openai']);

        $out = AI_Settings_Sanitizer::sanitize(['api_provider' => 'nope']);

        $this->assertSame('openai', $out['api_provider']);
        $this->assertSame(['invalid_api_provider'], $this->errorCodes());
    }

    public function test_endpoint_paths_are_stripped_from_the_custom_url(): void
    {
        $out = AI_Settings_Sanitizer::sanitize(['api_provider' => 'custom', 'custom_api_url' => 'https://api.example.com/v1/chat/completions/']);

        $this->assertSame('https://api.example.com/v1', $out['custom_api_url']);
        $this->assertSame([['custom_url_auto_corrected', $this->errors[0][1], 'updated']], $this->errors);
    }

    public function test_the_custom_provider_needs_a_url_that_is_not_google(): void
    {
        AI_Settings_Sanitizer::sanitize(['api_provider' => 'custom']);
        $this->assertSame(['custom_url_required'], $this->errorCodes());

        $this->errors = [];
        AI_Settings_Sanitizer::sanitize(['api_provider' => 'custom', 'custom_api_url' => 'https://generativelanguage.googleapis.com/v1']);
        $this->assertSame(['custom_url_not_openai_compatible'], $this->errorCodes());
    }

    public function test_the_model_is_stored_for_the_provider(): void
    {
        $out = AI_Settings_Sanitizer::sanitize(['api_provider' => 'openai', 'selected_model' => ' gpt-x ']);

        $this->assertSame('gpt-x', $out['models']['openai']);
        $this->assertSame('gpt-x', $out['selected_model']);
    }

    public function test_a_custom_model_needs_a_name(): void
    {
        $out = AI_Settings_Sanitizer::sanitize(['api_provider' => 'openai', 'selected_model' => 'custom', 'custom_model' => '']);

        $this->assertSame(['custom_model_required'], $this->errorCodes());
        $this->assertArrayNotHasKey('openai', $out['models']);

        $out = AI_Settings_Sanitizer::sanitize(['api_provider' => 'openai', 'selected_model' => 'custom', 'custom_model' => ' my-model ']);
        $this->assertSame('my-model', $out['models']['openai']);
    }

    public function test_a_typed_key_replaces_the_stored_key(): void
    {
        $this->stored(['api_provider' => 'openai', 'api_keys' => ['openai' => 'old', 'deepseek' => 'other']]);

        $out = AI_Settings_Sanitizer::sanitize(['api_key' => ' sk-new ']);

        $this->assertSame(['openai' => 'sk-new', 'deepseek' => 'other'], $out['api_keys']);
    }

    public function test_the_mask_keeps_the_stored_key(): void
    {
        $this->stored(['api_provider' => 'openai', 'api_keys' => ['openai' => 'secret']]);

        $out = AI_Settings_Sanitizer::sanitize(['api_key' => KEY_MASK]);

        $this->assertSame('secret', $out['api_keys']['openai']);
        $this->assertSame([], $this->errors);
    }

    public function test_an_empty_key_is_reported(): void
    {
        $this->stored(['api_provider' => 'openai', 'api_keys' => ['openai' => 'secret']]);

        $out = AI_Settings_Sanitizer::sanitize(['api_key' => '   ']);

        $this->assertSame('', $out['api_keys']['openai']);
        $this->assertSame(['api_key_missing'], $this->errorCodes());
    }

    // ---------------------------------------------------------------
    //  Cache duration
    // ---------------------------------------------------------------

    public function test_the_form_posts_days_and_they_are_stored_as_hours(): void
    {
        $out = AI_Settings_Sanitizer::sanitize(['_form_submit' => '1', 'cache_expiration' => '30']);

        $this->assertSame(720, $out['cache_expiration']);
    }

    public function test_fewer_than_fourteen_days_becomes_fourteen_with_a_warning(): void
    {
        $out = AI_Settings_Sanitizer::sanitize(['_form_submit' => '1', 'cache_expiration' => '3']);

        $this->assertSame(336, $out['cache_expiration']);
        $this->assertSame([['cache_duration_too_low', $this->errors[0][1], 'warning']], $this->errors);
    }

    public function test_outside_the_form_the_value_is_already_in_hours(): void
    {
        $this->assertSame(500, AI_Settings_Sanitizer::sanitize(['cache_expiration' => '500'])['cache_expiration']);
    }

    public function test_a_stored_value_out_of_range_is_reset(): void
    {
        foreach ([0, 100, 365 * 24 + 1, 60000] as $bad) {
            AI_Translate_Core::_reset();
            $this->stored(['cache_expiration' => $bad]);
            $this->assertSame(336, AI_Settings_Sanitizer::sanitize(['default_language' => 'nl'])['cache_expiration'], "stored $bad");
        }
    }

    // ---------------------------------------------------------------
    //  Checkboxes
    // ---------------------------------------------------------------

    public function test_a_checkbox_missing_from_the_form_is_off(): void
    {
        $this->stored(['prewarm_on_publish' => true, 'auto_clear_pages_on_menu_update' => true]);

        $out = AI_Settings_Sanitizer::sanitize(['_form_submit' => '1', 'cache_expiration' => '14', 'multi_domain_caching' => '1']);

        $this->assertFalse($out['prewarm_on_publish']);
        $this->assertFalse($out['auto_clear_pages_on_menu_update']);
        $this->assertTrue($out['multi_domain_caching']);
    }

    public function test_outside_the_form_a_checkbox_changes_only_when_present(): void
    {
        $this->stored(['prewarm_on_publish' => true, 'keep_slugs_in_english' => true]);

        $out = AI_Settings_Sanitizer::sanitize(['keep_slugs_in_english' => false]);

        $this->assertTrue($out['prewarm_on_publish'], 'not mentioned: unchanged');
        $this->assertFalse($out['keep_slugs_in_english']);
    }

    public function test_checkboxes_never_saved_get_their_defaults(): void
    {
        $out = AI_Settings_Sanitizer::sanitize([]);

        $this->assertTrue($out['auto_clear_pages_on_menu_update']);
        foreach (['stop_translations_except_cache_invalidation', 'prewarm_on_publish', 'multi_domain_caching', 'keep_slugs_in_english', 'woocommerce_keep_product_names'] as $key) {
            $this->assertFalse($out[$key], $key);
        }
    }

    // ---------------------------------------------------------------
    //  Texts per domain
    // ---------------------------------------------------------------

    public function test_a_single_domain_stores_one_text(): void
    {
        $out = AI_Settings_Sanitizer::sanitize(['website_context' => ' Webshop <b>voor</b> fietsen ', 'homepage_meta_description' => 'Fietsen']);

        $this->assertSame('Webshop voor fietsen', $out['website_context']);
        $this->assertSame('Fietsen', $out['homepage_meta_description']);
        $this->assertArrayNotHasKey('website_context_per_domain', $out);
    }

    public function test_with_multi_domain_caching_the_text_is_stored_for_the_current_host(): void
    {
        $_SERVER['HTTP_HOST'] = 'shop.example.com:8443';
        $this->stored(['multi_domain_caching' => true, 'website_context_per_domain' => ['other.com' => 'Other']]);

        $out = AI_Settings_Sanitizer::sanitize(['website_context' => 'Shop']);

        $this->assertSame(['other.com' => 'Other', 'shop.example.com' => 'Shop'], $out['website_context_per_domain']);
        $this->assertArrayNotHasKey('website_context', $out);
    }

    public function test_the_per_domain_array_can_be_replaced_whole(): void
    {
        $this->stored(['website_context_per_domain' => ['old.com' => 'Old']]);

        $out = AI_Settings_Sanitizer::sanitize(['website_context_per_domain' => ['a.com' => ' A ', 'b.com' => 'B']]);

        $this->assertSame(['a.com' => 'A', 'b.com' => 'B'], $out['website_context_per_domain']);
    }

    public function test_the_active_domain_falls_back_step_by_step(): void
    {
        $_SERVER['HTTP_HOST'] = 'a.example.com:80';
        $this->assertSame('a.example.com', AI_Settings_Sanitizer::active_domain());

        unset($_SERVER['HTTP_HOST']);
        $_SERVER['SERVER_NAME'] = 'server.example.com';
        $this->assertSame('server.example.com', AI_Settings_Sanitizer::active_domain());

        unset($_SERVER['SERVER_NAME']);
        $this->assertSame('example.com', AI_Settings_Sanitizer::active_domain(), 'from home_url()');

        Functions\when('home_url')->justReturn('');
        $this->assertSame('default', AI_Settings_Sanitizer::active_domain());
    }

    // ---------------------------------------------------------------
    //  Languages and switcher
    // ---------------------------------------------------------------

    public function test_the_form_replaces_the_language_lists_and_empty_means_none(): void
    {
        $this->stored(['enabled_languages' => ['en', 'de'], 'detectable_languages' => ['fr']]);

        $out = AI_Settings_Sanitizer::sanitize(['_form_submit' => '1', 'cache_expiration' => '14', 'enabled_languages' => ['en', 'en', 'es']]);

        $this->assertSame(['en', 'es'], $out['enabled_languages']);
        $this->assertSame([], $out['detectable_languages']);
    }

    public function test_outside_the_form_a_missing_language_list_is_kept(): void
    {
        $this->stored(['enabled_languages' => ['en', 'de']]);

        $this->assertSame(['en', 'de'], AI_Settings_Sanitizer::sanitize(['default_language' => 'nl'])['enabled_languages']);
    }

    public function test_the_switcher_position_must_be_a_known_one(): void
    {
        $this->assertSame('top-right', AI_Settings_Sanitizer::sanitize(['switcher_position' => 'top-right'])['switcher_position']);
        $this->assertSame('bottom-left', AI_Settings_Sanitizer::sanitize(['switcher_position' => 'middle'])['switcher_position']);
        $this->assertSame('bottom-left', AI_Settings_Sanitizer::sanitize([])['switcher_position']);
    }

    public function test_the_glossary_is_sanitized_and_kept(): void
    {
        $this->assertSame('a=b', AI_Settings_Sanitizer::sanitize(['glossary' => ' a=b '])['glossary']);
        $this->stored(['glossary' => 'x=y']);
        $this->assertSame('x=y', AI_Settings_Sanitizer::sanitize([])['glossary']);
    }
}
