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

    // ---------------------------------------------------------------
    //  find_post_ids_by_translated_slug()
    // ---------------------------------------------------------------

    public function test_find_post_ids_returns_unique_ids(): void
    {
        $this->stubPrepare();

        // Two get_results calls: first the slug-map lookup, second the history lookup.
        $this->wpdb->shouldReceive('get_results')
            ->andReturn(
                [['post_id' => '12'], ['post_id' => '34'], ['post_id' => '12']],
                []
            );

        $result = AI_Slugs::find_post_ids_by_translated_slug('over-ons');
        $this->assertSame([12, 34], $result);
    }

    public function test_find_post_ids_empty_when_nothing_matches(): void
    {
        $this->stubPrepare();
        $this->wpdb->shouldReceive('get_results')->andReturn([], []);

        $result = AI_Slugs::find_post_ids_by_translated_slug('onbekend');
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    //  discover_post_id_for_404_slug() – fuzzy fallback strategies
    // ---------------------------------------------------------------

    /**
     * Helper: stub the three calls discover_post_id_for_404_slug performs:
     *  1. get_col() — exact source_slug match
     *  2. get_col() — exact translated_slug match in any language
     *  3. get_results() — full list for fuzzy scoring
     *
     * @param array<int> $exactSourceIds
     * @param array<int> $exactTranslatedIds
     * @param array<array{post_id:int|string,src:string,tsl:string}> $allRows
     */
    private function stubDiscover(array $exactSourceIds, array $exactTranslatedIds, array $allRows): void
    {
        $this->stubPrepare();
        $this->wpdb->shouldReceive('get_col')
            ->andReturn(
                array_map('strval', $exactSourceIds),
                array_map('strval', $exactTranslatedIds)
            );
        $this->wpdb->shouldReceive('get_results')->andReturn($allRows);
    }

    public function test_discover_returns_unique_source_slug_match(): void
    {
        $this->stubDiscover([861], [], []);
        $this->assertSame(861, AI_Slugs::discover_post_id_for_404_slug('air-je-beste-collega', 'ka'));
    }

    public function test_discover_returns_unique_translated_slug_match_in_other_language(): void
    {
        $this->stubDiscover([], [836], []);
        $this->assertSame(836, AI_Slugs::discover_post_id_for_404_slug('dernieres-avancees-en-ia-generative', 'ro'));
    }

    public function test_discover_fuzzy_with_extra_leading_word_matches_via_jaccard(): void
    {
        // Visitor: /ro/les-dernieres-avancees-en-ia-generative/
        // Existing fr translated_slug for post 836: 'dernieres-avancees-en-ia-generative'
        $this->stubDiscover([], [], [
            ['post_id' => '836', 'src' => 'de-laatste-ontwikkelingen-in-generatieve-ai', 'tsl' => 'dernieres-avancees-en-ia-generative'],
            ['post_id' => '500', 'src' => 'iets-anders', 'tsl' => 'iets-anders'],
        ]);
        $result = AI_Slugs::discover_post_id_for_404_slug('les-dernieres-avancees-en-ia-generative', 'ro');
        $this->assertSame(836, $result);
    }

    public function test_discover_fuzzy_with_subset_slug_matches_via_containment(): void
    {
        // Visitor: /hi/product/ai-sahayak/
        // Existing hi translated_slug for post 1514: 'ai-sahayak-gyan-pranali'
        $this->stubDiscover([], [], [
            ['post_id' => '1514', 'src' => 'een-intern-kennissysteem-met-ai', 'tsl' => 'ai-sahayak-gyan-pranali'],
        ]);
        $result = AI_Slugs::discover_post_id_for_404_slug('ai-sahayak', 'hi');
        $this->assertSame(1514, $result);
    }

    public function test_discover_fuzzy_with_partial_source_overlap_matches(): void
    {
        // Visitor: /ka/product/ai-beste-collega/
        // source_slug is 'air-je-beste-collega' (typo in original); 2/3 word overlap with discount = 0.567 ≥ 0.55
        $this->stubDiscover([], [], [
            ['post_id' => '861', 'src' => 'air-je-beste-collega', 'tsl' => 'ai-sheni-sauketeso-megobari'],
        ]);
        $result = AI_Slugs::discover_post_id_for_404_slug('ai-beste-collega', 'ka');
        $this->assertSame(861, $result);
    }

    public function test_discover_fuzzy_below_threshold_returns_null(): void
    {
        // Single-word overlap on a short common token must NOT match.
        $this->stubDiscover([], [], [
            ['post_id' => '1', 'src' => 'totaal-iets-anders', 'tsl' => 'volkomen-onbekend'],
        ]);
        $result = AI_Slugs::discover_post_id_for_404_slug('ai-sahayak', 'hi');
        $this->assertNull($result);
    }

    public function test_discover_returns_null_when_two_different_posts_tie(): void
    {
        // Two different posts share the same top score → ambiguous → null.
        $this->stubDiscover([], [], [
            ['post_id' => '10', 'src' => 'foo-bar-baz', 'tsl' => 'foo-bar-baz'],
            ['post_id' => '20', 'src' => 'foo-bar-baz', 'tsl' => 'foo-bar-baz'],
        ]);
        $result = AI_Slugs::discover_post_id_for_404_slug('foo-bar-qux', 'en');
        $this->assertNull($result);
    }
}
