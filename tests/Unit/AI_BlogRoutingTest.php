<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_URL;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

#[\PHPUnit\Framework\Attributes\CoversClass(AI_URL::class)]
final class AI_BlogRoutingTest extends TestCase
{
    private array $originalGet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGet = $_GET;
        $_GET = [];
        Functions\when('is_admin')->justReturn(false);
        Functions\when('get_option')->alias(static function ($key, $default = '') {
            return [
                'page_for_posts' => 47,
                'home' => 'https://example.test/site',
                'siteurl' => 'https://example.test/site',
                'category_base' => 'topics',
                'tag_base' => 'labels',
            ][$key] ?? $default;
        });
        Functions\when('get_page_uri')->justReturn('journal/news');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('absint')->alias(static fn ($value) => abs((int) $value));
        Functions\when('wp_parse_url')->alias(static fn ($url, $component = -1) => parse_url($url, $component));
        Functions\when('trailingslashit')->alias(static fn ($path) => rtrim($path, '/') . '/');
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        parent::tearDown();
    }

    public function test_maps_theme_pagination_after_posts_page_path_is_resolved(): void
    {
        $_GET['blogpage'] = '3';
        $request = (object) ['query_vars' => ['pagename' => 'journal/news', 'lang' => 'fr']];
        AI_URL::map_blog_pagination($request);
        $this->assertSame(3, $request->query_vars['paged']);
        $this->assertSame('fr', $request->query_vars['lang']);
    }

    public function test_matches_configured_posts_page_by_id_and_accepts_query_vars(): void
    {
        $request = (object) ['query_vars' => ['page_id' => 47, 'blogpage' => '2']];
        AI_URL::map_blog_pagination($request);
        $this->assertSame(2, $request->query_vars['paged']);
    }

    public function test_does_not_change_pagination_of_unrelated_pages(): void
    {
        $_GET['blogpage'] = '3';
        $vars = ['pagename' => 'contact', 'paged' => 2];
        $request = (object) ['query_vars' => $vars];
        AI_URL::map_blog_pagination($request);
        $this->assertSame($vars, $request->query_vars);
    }

    public function test_does_not_override_native_pagination_without_blogpage(): void
    {
        $request = (object) ['query_vars' => ['page_id' => 47, 'paged' => 4]];
        AI_URL::map_blog_pagination($request);
        $this->assertSame(4, $request->query_vars['paged']);
    }

    public function test_ignores_array_input_and_admin_requests(): void
    {
        $_GET['blogpage'] = ['2'];
        $request = (object) ['query_vars' => ['page_id' => 47]];
        AI_URL::map_blog_pagination($request);
        $this->assertArrayNotHasKey('paged', $request->query_vars);
        $_GET['blogpage'] = '2';
        Functions\when('is_admin')->justReturn(true);
        AI_URL::map_blog_pagination($request);
        $this->assertArrayNotHasKey('paged', $request->query_vars);
    }

    public function test_no_posts_page_is_a_noop(): void
    {
        $_GET['blogpage'] = '2';
        Functions\when('get_option')->justReturn(0);
        $request = (object) ['query_vars' => ['page_id' => 47]];
        AI_URL::map_blog_pagination($request);
        $this->assertArrayNotHasKey('paged', $request->query_vars);
    }

    public function test_archive_links_do_not_resolve_as_singular_posts(): void
    {
        Functions\when('get_taxonomies')->justReturn([
            (object) ['name' => 'category', 'rewrite' => false],
            (object) ['name' => 'post_tag', 'rewrite' => false],
            (object) ['name' => 'genre', 'rewrite' => ['slug' => 'library/genres']],
        ]);
        $method = new \ReflectionMethod(AI_URL::class, 'rewriteSingularWithSlugMap');
        foreach (['topics/shared-slug', 'labels/shared-slug', 'library/genres/shared-slug'] as $path) {
            // No post or slug database lookup may run for an archive, even when a
            // landing page has the same leaf slug. Covers subdirectory installs.
            $this->assertNull($method->invoke(null, 'https://example.test/site/de/' . $path . '/', 'de', 'en'));
        }
    }
}
