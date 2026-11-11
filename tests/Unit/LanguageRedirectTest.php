<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * The decisions behind the language redirects: where a visitor is sent, in which language a page is
 * served and which language/path combinations are refused.
 */
final class LanguageRedirectTest extends TestCase
{
    public function test_languages_are_compared_case_insensitively(): void
    {
        $this->assertTrue(ai_translate_same_lang('NL', 'nl'));
        $this->assertFalse(ai_translate_same_lang('nl', 'de'));
    }

    public function test_the_root_gets_a_language_root_and_other_paths_keep_their_path(): void
    {
        $this->assertSame('/de/', ai_translate_lang_prefixed_path('de', '/'));
        $this->assertSame('/de/over-ons/', ai_translate_lang_prefixed_path('de', '/over-ons/'));
    }

    public function test_stripping_a_language_prefix_keeps_the_rest_with_a_trailing_slash(): void
    {
        $this->assertSame('/', ai_translate_strip_lang_prefix('/nl', 'nl'));
        $this->assertSame('/', ai_translate_strip_lang_prefix('/nl/', 'nl'));
        $this->assertSame('/product/x/', ai_translate_strip_lang_prefix('/nl/product/x', 'nl'));
        $this->assertSame('/product/x/', ai_translate_strip_lang_prefix('/NL/product/x/', 'nl'));
    }

    public function test_only_the_whole_first_segment_is_stripped(): void
    {
        // "/nld/..." does not start with the segment "nl"
        $this->assertSame('/nld/x/', ai_translate_strip_lang_prefix('/nld/x', 'nl'));
        $this->assertSame('/over/nl/', ai_translate_strip_lang_prefix('/over/nl', 'nl'));
    }

    public function test_a_lang_parameter_on_a_prefixed_path_keeps_that_path(): void
    {
        $this->assertSame('/es/', ai_translate_lang_param_target_path('/es', 'es', 'fr', 'nl'));
        $this->assertSame('/es/over/', ai_translate_lang_param_target_path('/es/over/', 'es', 'fr', 'nl'));
    }

    public function test_a_lang_parameter_without_a_prefix_builds_the_language_path(): void
    {
        $this->assertSame('/es/', ai_translate_lang_param_target_path('/', null, 'ES', 'nl'));
    }

    public function test_the_default_language_parameter_goes_to_the_unprefixed_path(): void
    {
        $this->assertSame('/', ai_translate_lang_param_target_path('/', null, 'nl', 'nl'));
        $this->assertSame('/over-ons/', ai_translate_lang_param_target_path('/over-ons', null, 'NL', 'nl'));
    }

    public function test_a_blank_lang_parameter_changes_nothing_but_the_trailing_slash(): void
    {
        $this->assertSame('/over/', ai_translate_lang_param_target_path('/over', null, '', 'nl'));
    }

    public function test_the_language_in_the_url_decides_the_served_language(): void
    {
        Functions\when('sanitize_key')->alias(static fn($k) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)));

        $this->assertSame('de', ai_translate_served_lang(null, 'de', 'nl'));
    }

    public function test_without_a_url_language_the_default_is_served(): void
    {
        Functions\when('sanitize_key')->alias(static fn($k) => strtolower((string) $k));

        $this->assertSame('nl', ai_translate_served_lang(null, null, 'nl'));
    }

    public function test_a_language_decided_earlier_beats_the_url(): void
    {
        Functions\when('sanitize_key')->alias(static fn($k) => strtolower((string) $k));

        $this->assertSame('fr', ai_translate_served_lang('FR', 'de', 'nl'));
    }

    public function test_an_empty_decided_language_falls_back_to_the_default(): void
    {
        Functions\when('sanitize_key')->alias(static fn($k) => strtolower((string) $k));

        $this->assertSame('nl', ai_translate_served_lang('', 'de', 'nl'));
    }

    public function test_non_latin_scripts_are_recognised_after_decoding(): void
    {
        $this->assertTrue(ai_translate_path_has_non_latin_script('/ru/' . rawurlencode('привет') . '/'));
        $this->assertTrue(ai_translate_path_has_non_latin_script('/ja/ほん/'));
        $this->assertTrue(ai_translate_path_has_non_latin_script('/el/γειά/'));
    }

    public function test_latin_extended_is_not_a_foreign_script(): void
    {
        $this->assertFalse(ai_translate_path_has_non_latin_script('/mt/' . rawurlencode('ħobż') . '/'));
        $this->assertFalse(ai_translate_path_has_non_latin_script('/fr/café/'));
    }

    public function test_a_path_without_language_segment_is_not_judged(): void
    {
        $this->assertFalse(ai_translate_path_has_non_latin_script('/привет/'));
        $this->assertFalse(ai_translate_path_has_non_latin_script('/'));
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->assertTrue(ai_translate_lang_request_refused('xx', '/xx/a/', ['de'], ['fr'], false));
    }

    public function test_an_enabled_or_detectable_language_is_allowed(): void
    {
        $this->assertFalse(ai_translate_lang_request_refused('DE', '/de/a/', ['de'], [], false));
        $this->assertFalse(ai_translate_lang_request_refused('fr', '/fr/a/', [], ['FR'], false));
    }

    public function test_a_foreign_script_is_refused_for_a_latin_language_only(): void
    {
        $this->assertTrue(ai_translate_lang_request_refused('de', '/de/привет/', ['de'], [], false));
        $this->assertFalse(ai_translate_lang_request_refused('ru', '/ru/привет/', ['ru'], [], true));
    }

    public function test_the_locale_filter_maps_language_to_locale(): void
    {
        $filter = null;
        Functions\when('add_filter')->alias(function ($hook, $cb) use (&$filter) {
            $filter = $cb;
        });

        ai_translate_use_locale_of('de');
        $this->assertSame('de_DE', $filter('en_US'));

        ai_translate_use_locale_of('');
        $this->assertSame('en_US', $filter('en_US'));
    }
}
