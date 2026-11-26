<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * The request URI as the plugin reads it everywhere: sanitized, decoded once more only when it
 * was double-encoded, and for the path also stripped of the WordPress sub-directory.
 */
final class RequestPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('wp_parse_url')->alias(static fn($url, $component = -1) => parse_url((string) $url, $component));
        // The real function removes the install directory (e.g. "/moretti") from the path.
        Functions\when('ai_translate_strip_site_path')->alias(static fn($path) => preg_replace('#^/moretti#', '', (string) $path) ?: '/');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    public function test_the_uri_is_returned_as_it_came(): void
    {
        $_SERVER['REQUEST_URI'] = '/de/over-ons/?x=1';

        $this->assertSame('/de/over-ons/?x=1', ai_translate_request_uri());
    }

    public function test_a_double_encoded_uri_is_decoded_once_more(): void
    {
        $_SERVER['REQUEST_URI'] = '/de/caf%25C3%25A9/';

        $this->assertSame('/de/caf%C3%A9/', ai_translate_request_uri());
    }

    public function test_a_single_encoded_uri_is_left_alone(): void
    {
        $_SERVER['REQUEST_URI'] = '/de/caf%C3%A9/';

        $this->assertSame('/de/caf%C3%A9/', ai_translate_request_uri());
    }

    public function test_without_a_request_uri_the_given_default_is_used(): void
    {
        $this->assertSame('', ai_translate_request_uri());
        $this->assertSame('/', ai_translate_request_uri('/'));
    }

    public function test_the_path_has_no_query_string_and_no_site_directory(): void
    {
        $_SERVER['REQUEST_URI'] = '/moretti/de/over-ons/?x=1#top';

        $this->assertSame('/de/over-ons/', ai_translate_request_path());
    }

    public function test_the_path_of_a_double_encoded_uri_is_decoded(): void
    {
        $_SERVER['REQUEST_URI'] = '/de/caf%25C3%25A9/?s=1';

        $this->assertSame('/de/caf%C3%A9/', ai_translate_request_path());
    }

    public function test_an_empty_path_is_empty_unless_the_root_is_asked_for(): void
    {
        // A URI without a path part, such as a bare query string
        $_SERVER['REQUEST_URI'] = '?s=test';
        Functions\when('ai_translate_strip_site_path')->alias(static fn($path) => (string) $path);

        $this->assertSame('', ai_translate_request_path());
        $this->assertSame('/', ai_translate_request_path(true));
    }

    public function test_the_language_is_the_first_two_letter_segment(): void
    {
        Functions\when('sanitize_key')->alias(static fn($k) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)));

        $this->assertSame('de', ai_translate_lang_in_path('/de/over-ons/'));
        $this->assertSame('de', ai_translate_lang_in_path('/DE'));
        $this->assertSame('fr', ai_translate_lang_in_path('/fr/'));
    }

    public function test_a_path_without_a_language_segment_gives_null(): void
    {
        Functions\when('sanitize_key')->alias(static fn($k) => strtolower((string) $k));

        foreach (['', '/', '/over-ons/', '/d/', '/deu/', 'de/over-ons', '/de-be/'] as $path) {
            $this->assertNull(ai_translate_lang_in_path($path), "'$path'");
        }
    }
}
