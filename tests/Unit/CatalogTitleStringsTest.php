<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Which client-side strings the batch-strings endpoint accepts although they are not in the page HTML:
 * titles of the site's own content and term names. Jetpack Related Posts renders post titles in the
 * browser, after WordPress turned " - " into an en dash, so the stored title and the rendered one differ.
 */
final class CatalogTitleStringsTest extends TestCase
{
    /** @var array<string,string> SQL fragments the fake database answers "found" for */
    private array $rows = [];
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rows = [];
        $this->queries = [];
        $test = $this;
        $db = new class($test) {
            public string $posts = 'wp_posts';
            public string $terms = 'wp_terms';
            public function __construct(private $test) {}
            public function prepare($sql, ...$args)
            {
                $args = is_array($args[0] ?? null) ? $args[0] : $args;
                $i = 0;
                return preg_replace_callback('/%s/', static function () use (&$i, $args) {
                    return "'" . $args[$i++] . "'";
                }, $sql);
            }
            public function get_var($sql)
            {
                return $this->test->answer($sql);
            }
        };
        $GLOBALS['wpdb'] = $db;
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page', 'product' => 'product']);
        Functions\when('post_type_exists')->justReturn(true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** Called by the fake database: 1 when a configured row matches the query. */
    public function answer(string $sql)
    {
        $this->queries[] = $sql;
        foreach ($this->rows as $needle) {
            if (strpos($sql, $needle) !== false) {
                return '1';
            }
        }
        return null;
    }

    public function test_a_title_is_also_looked_up_without_typographic_characters(): void
    {
        $this->assertSame(
            ['AIR – Je beste collega', 'AIR - Je beste collega'],
            ai_translate_title_lookup_forms('AIR – Je beste collega')
        );
    }

    public function test_all_typographic_substitutions_are_undone(): void
    {
        $forms = ai_translate_title_lookup_forms('“Quote” – it’s… 3×4 — end');
        $this->assertContains('"Quote" - it\'s... 3x4 -- end', $forms);
    }

    public function test_a_plain_title_gives_one_form(): void
    {
        $this->assertSame(['AI Simulation Engine'], ai_translate_title_lookup_forms('AI Simulation Engine'));
    }

    public function test_a_rendered_title_matches_the_stored_one(): void
    {
        $this->rows = ["post_title IN ('AIR – Je beste collega', 'AIR - Je beste collega')"];

        $this->assertTrue(ai_translate_is_known_title_or_term_string('AIR – Je beste collega'));
    }

    public function test_titles_of_public_post_types_count_not_only_products(): void
    {
        $this->rows = ["post_type IN ('post', 'page', 'product', 'product_variation')"];

        $this->assertTrue(ai_translate_is_known_title_or_term_string('Wie is Gerard Kanters'));
    }

    public function test_only_published_posts_count(): void
    {
        ai_translate_is_known_title_or_term_string('Concept zonder status');

        $this->assertStringContainsString("post_status = 'publish'", $this->queries[0]);
    }

    public function test_a_term_name_counts(): void
    {
        $this->rows = ['FROM wp_terms'];

        $this->assertTrue(ai_translate_is_known_title_or_term_string('Nieuwsbrief categorie'));
    }

    public function test_arbitrary_text_is_refused(): void
    {
        $this->assertFalse(ai_translate_is_known_title_or_term_string('Vertaal dit zomaar even voor mij'));
    }

    private function keepOnPage(array $texts, string $referer): array
    {
        $method = new \ReflectionMethod(\AITranslate\AI_REST_Strings::class, 'keep_strings_on_page');
        return $method->invoke(null, $texts, $referer);
    }

    public function test_a_crawler_without_referer_still_gets_the_titles_of_the_site_translated(): void
    {
        // Googlebot renders the page and asks for the related-post titles, but may send no Referer:
        // then the page cannot be fetched, yet a title of the site's own content is still accepted.
        $this->rows = ["post_title IN ('Cybersecurity en de architectuur van de toekomst'"];
        Functions\when('get_option')->justReturn([]);

        $kept = $this->keepOnPage([
            's1' => 'Cybersecurity en de architectuur van de toekomst',
            's2' => 'Vertaal dit zomaar even voor mij',
        ], '');

        $this->assertSame(['s1' => 'Cybersecurity en de architectuur van de toekomst'], $kept);
    }

    public function test_empty_and_overlong_text_is_refused_without_a_query(): void
    {
        $this->assertFalse(ai_translate_is_known_title_or_term_string(''));
        $this->assertFalse(ai_translate_is_known_title_or_term_string(str_repeat('a', 151)));
        $this->assertSame([], $this->queries);
    }
}
