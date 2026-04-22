<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_404_Recovery;
use AITranslate\AI_Lang;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_404_Recovery::class)]
final class AI_404_RecoveryTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->posts = 'wp_posts';
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            $args = func_get_args();
            return $args[0];
        });
        $this->wpdb->shouldReceive('get_col')->andReturn([])->byDefault();
        $this->wpdb->shouldReceive('get_results')->andReturn([])->byDefault();

        Functions\when('get_transient')->justReturn('new');
        Functions\when('set_transient')->justReturn(true);
        Functions\when('home_url')->alias(function ($p = '/') {
            return 'https://example.test' . $p;
        });
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('get_option')->justReturn(0);
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('get_post_type_object')->alias(function ($type) {
            return (object) ['public' => true, 'rewrite' => false];
        });
        Functions\when('get_permalink')->justReturn('https://example.test/contact/');

        AI_Lang::_setDefault('nl');
        AI_Lang::_setEnabled(['nl', 'en', 'de', 'fr']);
        AI_Lang::_setDetectable([]);
    }

    /**
     * Stub the slug-map and history lookups with the same set of post_id rows.
     *
     * @param array<int|string> $ids
     */
    private function stubFindByTranslatedSlug(array $ids): void
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['post_id' => (string) $id];
        }
        // First call: slug-map. Second call: history. We stub both with the same set;
        // tests that need a different shape can override locally.
        $this->wpdb->shouldReceive('get_results')->andReturn($rows, []);
    }

    public function test_paths_without_language_or_unknown_language_return_null(): void
    {
        $this->assertNull(AI_404_Recovery::resolve('/'));
        $this->assertNull(AI_404_Recovery::resolve('/contact/'));
        $this->assertNull(AI_404_Recovery::resolve('/zz/contact/'));
        $this->assertNull(AI_404_Recovery::resolve('/en/page/3/'));
        $this->assertNull(AI_404_Recovery::resolve('/en/feed/'));
    }

    public function test_no_candidates_returns_null(): void
    {
        $this->stubFindByTranslatedSlug([]);
        $this->assertNull(AI_404_Recovery::resolve('/en/onbekend/'));
    }

    public function test_single_publish_match_redirects_to_translated_url(): void
    {
        $this->stubFindByTranslatedSlug([42]);

        Functions\when('get_post')->alias(function ($id) {
            return (object) [
                'ID' => 42,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => 'over-ons',
            ];
        });
        $this->wpdb->shouldReceive('get_row')->andReturn([
            'translated_slug' => 'about-us',
            'source_slug' => 'over-ons',
            'source_version' => md5('over-ons'),
            'lang' => 'en',
        ]);

        $url = AI_404_Recovery::resolve('/en/over-ons/');
        $this->assertSame('https://example.test/en/about-us/', $url);
    }

    public function test_multiple_candidates_or_attachment_returns_null(): void
    {
        // Two valid posts → ambiguous.
        $this->stubFindByTranslatedSlug([10, 20]);
        Functions\when('get_post')->alias(function ($id) {
            return (object) [
                'ID' => (int) $id,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => 'foo',
            ];
        });
        $this->assertNull(AI_404_Recovery::resolve('/en/over-ons/'));
    }

    public function test_no_existing_target_translation_returns_null(): void
    {
        $this->stubFindByTranslatedSlug([42]);

        Functions\when('get_post')->alias(function ($id) {
            return (object) [
                'ID' => 42,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => 'over-ons',
            ];
        });
        AI_Lang::_setEnabled(['nl', 'en', 'it']);
        $this->wpdb->shouldReceive('get_row')->andReturn(null);

        $this->assertNull(AI_404_Recovery::resolve('/it/over-ons/'));
    }

    public function test_self_redirect_returns_null(): void
    {
        $this->stubFindByTranslatedSlug([42]);

        Functions\when('get_post')->alias(function ($id) {
            return (object) [
                'ID' => 42,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => 'over-ons',
            ];
        });
        $this->wpdb->shouldReceive('get_row')->andReturn([
            'translated_slug' => 'about-us',
            'source_slug' => 'over-ons',
            'source_version' => md5('over-ons'),
            'lang' => 'en',
        ]);

        $this->assertNull(AI_404_Recovery::resolve('/en/about-us/'));
    }
}
