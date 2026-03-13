<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Slugs;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_Slugs::class)]
final class AI_SlugsTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->posts = 'wp_posts';
        $GLOBALS['wpdb'] = $this->wpdb;

        // detect_schema() uses transient caching; stub to return 'new' schema
        Functions\when('get_transient')->justReturn('new');
        Functions\when('set_transient')->justReturn(true);
    }

    private function stubPrepare(): void
    {
        // $wpdb->prepare() returns the query with placeholders substituted (simplified)
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            $args = func_get_args();
            return $args[0]; // return raw query for matching
        });
        $this->wpdb->shouldReceive('esc_like')->andReturnUsing(function ($s) {
            return addcslashes($s, '\\%_');
        });
    }

    // ---------------------------------------------------------------
    //  resolve_path_to_post()
    // ---------------------------------------------------------------

    public function test_empty_path_returns_null(): void
    {
        $result = AI_Slugs::resolve_path_to_post('de', '');
        $this->assertNull($result);
    }

    public function test_slash_only_returns_null(): void
    {
        $result = AI_Slugs::resolve_path_to_post('de', '/');
        $this->assertNull($result);
    }

    public function test_exact_match_returns_post_id(): void
    {
        $this->stubPrepare();

        // First get_var call: exact match on translated_slug
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('42');

        // get_post should confirm it's not an attachment
        $post = (object) ['post_type' => 'page', 'post_status' => 'publish'];
        Functions\when('get_post')->justReturn($post);

        $result = AI_Slugs::resolve_path_to_post('de', 'kontakt');
        $this->assertSame(42, $result);
    }

    public function test_exact_match_excludes_attachment(): void
    {
        $this->stubPrepare();

        // Exact match finds a post
        $this->wpdb->shouldReceive('get_var')
            ->andReturn('42', null, null, null);
        $this->wpdb->shouldReceive('get_results')
            ->andReturn([]);

        // But it's an attachment
        $attachment = (object) ['post_type' => 'attachment', 'post_status' => 'publish'];
        Functions\when('get_post')->justReturn($attachment);

        $result = AI_Slugs::resolve_path_to_post('de', 'foto');
        $this->assertNull($result);
    }

    public function test_url_encoded_path_is_normalized(): void
    {
        $this->stubPrepare();

        // The method should URL-decode '%C3%A9' to 'é' before matching
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('10');

        $post = (object) ['post_type' => 'page', 'post_status' => 'publish'];
        Functions\when('get_post')->justReturn($post);

        $result = AI_Slugs::resolve_path_to_post('hu', 'fell%C3%A9p%C3%A9s');
        $this->assertSame(10, $result);
    }

    public function test_source_slug_fallback(): void
    {
        $this->stubPrepare();

        // Exact match fails, encoded match fails, fuzzy fails
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(null, null, '55', null);
        $this->wpdb->shouldReceive('get_results')
            ->andReturn([], []);

        Functions\when('get_post')->justReturn(null);

        // Source slug fallback succeeds (3rd get_var call returns 55)
        // But we need to mock get_post for fuzzy fallback...
        // The third get_var is the source slug match
        $result = AI_Slugs::resolve_path_to_post('it', 'blogs');
        // Source slug match returns post_id directly without get_post check
        $this->assertSame(55, $result);
    }

    public function test_wp_posts_fallback(): void
    {
        $this->stubPrepare();

        // All slug map lookups fail
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(null, null, null, '99');
        $this->wpdb->shouldReceive('get_results')
            ->andReturn([], []);

        Functions\when('get_post')->justReturn(null);

        $result = AI_Slugs::resolve_path_to_post('fr', 'contact');
        $this->assertSame(99, $result);
    }

    public function test_no_match_returns_null(): void
    {
        $this->stubPrepare();

        $this->wpdb->shouldReceive('get_var')
            ->andReturn(null);
        $this->wpdb->shouldReceive('get_results')
            ->andReturn([]);

        Functions\when('get_post')->justReturn(null);

        $result = AI_Slugs::resolve_path_to_post('de', 'niet-bestaande-pagina');
        $this->assertNull($result);
    }

    public function test_trims_leading_trailing_slashes(): void
    {
        $this->stubPrepare();

        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('7');

        $post = (object) ['post_type' => 'post', 'post_status' => 'publish'];
        Functions\when('get_post')->justReturn($post);

        $result = AI_Slugs::resolve_path_to_post('de', '/mijn-bericht/');
        $this->assertSame(7, $result);
    }
}
