<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Lang;
use AITranslate\AI_REST_Strings;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_REST_Strings::class)]
final class AI_RESTStringsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_parse_url')->alias(static fn($url, $component = -1) => parse_url((string) $url, $component));
    }

    // ---------------------------------------------------------------
    //  prepare_texts()
    // ---------------------------------------------------------------

    public function test_texts_are_trimmed_collapsed_and_deduplicated(): void
    {
        $out = AI_REST_Strings::prepare_texts(["Naam ", "Naam", "Zoek   in\n de  winkel", '   ']);

        $this->assertSame(['Naam' => 'Naam', 'Zoek in de winkel' => "Zoek   in\n de  winkel"], $out['originals']);
    }

    public function test_the_first_posted_form_is_kept_for_the_answer(): void
    {
        $out = AI_REST_Strings::prepare_texts(['Zoek  veld', 'Zoek veld']);

        $this->assertSame(['Zoek veld' => 'Zoek  veld'], $out['originals']);
    }

    public function test_only_the_first_fifty_strings_are_used(): void
    {
        $strings = [];
        for ($i = 1; $i <= 80; $i++) {
            $strings[] = "tekst $i";
        }

        $this->assertCount(50, AI_REST_Strings::prepare_texts($strings)['originals']);
    }

    public function test_long_and_non_string_values_are_dropped(): void
    {
        $out = AI_REST_Strings::prepare_texts([str_repeat('a', 151), str_repeat('b', 150), 42, null, ['x'], 'ok']);

        $this->assertSame([str_repeat('b', 150), 'ok'], array_keys($out['originals']));
    }

    public function test_anything_but_an_array_gives_nothing(): void
    {
        foreach ([null, 'text', 5, false] as $input) {
            $this->assertSame([], AI_REST_Strings::prepare_texts($input)['originals']);
        }
    }

    // ---------------------------------------------------------------
    //  normalize_referer()
    // ---------------------------------------------------------------

    public function test_double_slashes_in_the_referer_path_are_collapsed(): void
    {
        $this->assertSame(
            'https://example.com:8443/de/pagina/?a=1#top',
            AI_REST_Strings::normalize_referer('https://example.com:8443//de///pagina//?a=1#top')
        );
    }

    public function test_a_referer_without_path_or_empty_is_left_alone(): void
    {
        $this->assertSame('', AI_REST_Strings::normalize_referer(''));
        $this->assertSame('https://example.com', AI_REST_Strings::normalize_referer('https://example.com'));
    }

    // ---------------------------------------------------------------
    //  resolve_language()
    // ---------------------------------------------------------------

    public function test_the_language_parameter_wins(): void
    {
        $this->assertSame('de', AI_REST_Strings::resolve_language('de', 'https://example.com/fr/x/'));
    }

    public function test_without_a_parameter_the_referer_prefix_is_used(): void
    {
        $this->assertSame('fr', AI_REST_Strings::resolve_language('', 'https://example.com/FR/x/'));
        $this->assertSame('de', AI_REST_Strings::resolve_language('', 'https://example.com/de'));
    }

    public function test_without_parameter_or_prefix_the_current_language_is_used(): void
    {
        // The stub returns null for the current language
        $this->assertNull(AI_REST_Strings::resolve_language('', 'https://example.com/'));
        $this->assertNull(AI_REST_Strings::resolve_language('', ''));
    }

    // ---------------------------------------------------------------
    //  translation_is_usable()
    // ---------------------------------------------------------------

    public function test_a_long_latin_text_in_a_non_latin_language_is_rejected(): void
    {
        $latin = str_repeat('This is still English text. ', 6);

        $this->assertFalse(AI_REST_Strings::translation_is_usable($latin, 'ru'));
        $this->assertTrue(AI_REST_Strings::translation_is_usable($latin, 'de'), 'Latin languages are not checked');
    }

    public function test_short_texts_and_real_translations_are_accepted(): void
    {
        $this->assertTrue(AI_REST_Strings::translation_is_usable('MyVox', 'ru'));
        $this->assertTrue(AI_REST_Strings::translation_is_usable(str_repeat('Привет мир. ', 15), 'ru'));
    }

    // ---------------------------------------------------------------
    //  strip_model_annotation()
    // ---------------------------------------------------------------

    public function test_a_language_annotation_added_by_the_model_is_removed(): void
    {
        $this->assertSame('Envoyer', AI_REST_Strings::strip_model_annotation('Envoyer (français)', 'Verstuur'));
        $this->assertSame('Tapez un message', AI_REST_Strings::strip_model_annotation('Tapez un message (NL : typ een bericht)', 'Typ een bericht'));
    }

    public function test_stacked_annotations_are_all_removed(): void
    {
        $this->assertSame('Envoyer', AI_REST_Strings::strip_model_annotation('Envoyer (fr) (français)', 'Verstuur'));
    }

    public function test_brackets_that_are_part_of_the_text_stay(): void
    {
        $this->assertSame('Total (incl. VAT)', AI_REST_Strings::strip_model_annotation('Total (incl. VAT)', 'Totaal (incl. btw)'));
        $this->assertSame('Envoyer (français)', AI_REST_Strings::strip_model_annotation('Envoyer (français)', 'Verstuur (français)'));
    }

    // ---------------------------------------------------------------
    //  segment_type() / occurs_in_html()
    // ---------------------------------------------------------------

    public function test_sentences_are_nodes_and_labels_are_meta(): void
    {
        $this->assertSame('meta', AI_REST_Strings::segment_type('Zoek in de winkel'));
        $this->assertSame('node', AI_REST_Strings::segment_type('Zoek in de hele winkel nu'));
    }

    public function test_strings_are_found_in_the_page_html_regardless_of_case_and_regex_characters(): void
    {
        $html = '<input placeholder="Search (all) products?" aria-label="Zoek NU">';

        $this->assertTrue(AI_REST_Strings::occurs_in_html('Search (all) products?', $html));
        $this->assertTrue(AI_REST_Strings::occurs_in_html('zoek nu', $html));
        $this->assertFalse(AI_REST_Strings::occurs_in_html('Search .* products', $html), 'regex characters are literal');
        $this->assertFalse(AI_REST_Strings::occurs_in_html('Something else', $html));
    }

    // ---------------------------------------------------------------
    //  handle(): the guards before anything is looked up or translated
    // ---------------------------------------------------------------

    private function request(array $params): \WP_REST_Request
    {
        $request = \Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_param')->andReturnUsing(static fn($key) => $params[$key] ?? null);
        return $request;
    }

    private function allowRequests(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_REFERER'] = 'https://example.com/de/';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_key')->alias(static fn($k) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)));
        Functions\when('wp_using_ext_object_cache')->justReturn(false);
    }

    /** Transients kept in memory, for the request counter. */
    private function memoryTransients(): void
    {
        $store = [];
        Functions\when('get_transient')->alias(static function ($key) use (&$store) {
            return $store[$key] ?? false;
        });
        Functions\when('set_transient')->alias(static function ($key, $value) use (&$store) {
            $store[$key] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_REFERER']);
        parent::tearDown();
    }

    public function test_a_language_the_site_does_not_offer_gets_an_empty_map(): void
    {
        $this->allowRequests();
        $this->memoryTransients();
        AI_Lang::_setDefault('nl');
        AI_Lang::_setEnabled(['en', 'de']);

        $response = AI_REST_Strings::handle($this->request(['lang' => 'zz', 'strings' => ['Zoek']]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $response->get_data()['data']['map']);
    }

    public function test_the_default_language_answers_with_the_texts_as_posted(): void
    {
        $this->allowRequests();
        $this->memoryTransients();
        AI_Lang::_setDefault('nl');

        $response = AI_REST_Strings::handle($this->request(['lang' => 'nl', 'strings' => ['Zoek  veld ', 'Naam']]));

        $this->assertSame(['Zoek  veld' => 'Zoek  veld', 'Naam' => 'Naam'], $response->get_data()['data']['map']);
    }

    public function test_the_thirty_first_request_in_a_minute_is_refused(): void
    {
        $this->allowRequests();
        $this->memoryTransients();
        AI_Lang::_setDefault('nl');

        $statuses = [];
        for ($i = 0; $i < 31; $i++) {
            $statuses[] = AI_REST_Strings::handle($this->request(['lang' => 'nl', 'strings' => ['Naam']]))->get_status();
        }

        $this->assertSame(array_fill(0, 30, 200), array_slice($statuses, 0, 30));
        $this->assertSame(429, $statuses[30]);
    }
}
