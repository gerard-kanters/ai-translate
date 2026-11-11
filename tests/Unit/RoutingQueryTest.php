<?php

namespace AITranslate\Tests\Unit;

use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Pointing the main query at a resolved post or archive during parse_request.
 */
final class RoutingQueryTest extends TestCase
{
    private function wp(array $vars = []): object
    {
        $wp = new \stdClass();
        $wp->query_vars = $vars;
        return $wp;
    }

    private function post(int $id, string $type, string $status = 'publish', string $name = 'slug'): object
    {
        return (object) ['ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_name' => $name];
    }

    public function test_archive_routing_drops_post_selection_and_own_path(): void
    {
        $wp = $this->wp(['ai_translate_path' => 'x', 'name' => 'a', 'pagename' => 'b', 'page_id' => 3, 'p' => 4, 'post_type' => 'old', 'lang' => 'de']);

        ai_translate_route_to_archive($wp, 'product');

        $this->assertSame(['lang' => 'de', 'post_type' => 'product'], $wp->query_vars);
        $this->assertTrue($wp->is_archive);
        $this->assertFalse($wp->is_singular);
        $this->assertFalse($wp->is_404);
    }

    public function test_clearing_keeps_own_path_unless_asked(): void
    {
        $vars = ['ai_translate_path' => 'x', 'name' => 'a', 'other' => 1];

        $this->assertSame(['ai_translate_path' => 'x', 'other' => 1], ai_translate_clear_post_query_vars($vars));
        $this->assertSame(['other' => 1], ai_translate_clear_post_query_vars($vars, true));
    }

    public function test_the_only_published_candidate_is_chosen(): void
    {
        Functions\when('get_post')->alias(fn($id) => [1 => $this->post(1, 'product', 'draft'), 2 => $this->post(2, 'product')][$id] ?? null);

        $this->assertSame(2, ai_translate_unique_published_post([1, 2, 99], 'product'));
    }

    public function test_the_same_post_listed_twice_is_still_unique(): void
    {
        Functions\when('get_post')->alias(fn($id) => $this->post($id, 'page'));

        $this->assertSame(5, ai_translate_unique_published_post([5, 5]));
    }

    public function test_several_candidates_are_ambiguous(): void
    {
        Functions\when('get_post')->alias(fn($id) => $this->post($id, 'page'));

        $this->assertNull(ai_translate_unique_published_post([5, 6]));
        // Even when a later candidate is a duplicate of the first
        $this->assertNull(ai_translate_unique_published_post([5, 6, 5]));
    }

    public function test_a_required_post_type_filters_candidates(): void
    {
        Functions\when('get_post')->alias(fn($id) => $id === 1 ? $this->post(1, 'page') : $this->post(2, 'product'));

        $this->assertSame(2, ai_translate_unique_published_post([1, 2], 'product'));
        $this->assertNull(ai_translate_unique_published_post([1], 'product'));
    }

    public function test_without_a_post_type_attachments_are_skipped(): void
    {
        Functions\when('get_post')->alias(fn($id) => $id === 1 ? $this->post(1, 'attachment') : $this->post(2, 'post'));

        $this->assertSame(2, ai_translate_unique_published_post([1, 2]));
        $this->assertNull(ai_translate_unique_published_post([1]));
    }

    public function test_no_candidates_gives_null(): void
    {
        $this->assertNull(ai_translate_unique_published_post([]));
    }

    public function test_a_page_is_routed_by_id(): void
    {
        Functions\when('get_option')->justReturn(0);
        $wp = $this->wp();

        ai_translate_route_to_post($wp, $this->post(7, 'page'));

        $this->assertSame(['page_id' => 7], $wp->query_vars);
        $this->assertTrue($wp->is_page);
        $this->assertTrue($wp->is_singular);
    }

    public function test_the_posts_page_becomes_the_blog_index(): void
    {
        Functions\when('get_option')->justReturn(7);
        Functions\when('get_page_uri')->justReturn('blog');
        $wp = $this->wp();

        ai_translate_route_to_post($wp, $this->post(7, 'page'));

        $this->assertSame(['pagename' => 'blog'], $wp->query_vars);
        $this->assertTrue($wp->is_home);
        $this->assertFalse($wp->is_page);
        $this->assertFalse($wp->is_singular);
    }

    public function test_another_page_is_not_the_blog_index_when_a_posts_page_exists(): void
    {
        Functions\when('get_option')->justReturn(9);
        $wp = $this->wp();

        ai_translate_route_to_post($wp, $this->post(7, 'page'));

        $this->assertSame(['page_id' => 7], $wp->query_vars);
    }

    public function test_a_post_is_routed_by_id_and_type(): void
    {
        $wp = $this->wp();

        ai_translate_route_to_post($wp, $this->post(8, 'product'));

        $this->assertSame(['p' => 8, 'post_type' => 'product'], $wp->query_vars);
        $this->assertTrue($wp->is_single);
        $this->assertTrue($wp->is_singular);
    }
}
