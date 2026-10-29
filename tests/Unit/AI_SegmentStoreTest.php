<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Segment_Store;
use AITranslate\AI_Translate_Core;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_Segment_Store::class)]
final class AI_SegmentStoreTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function options(array $values): void
    {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($values) {
            return $values[$name] ?? $default;
        });
    }

    public function test_hash_equals_the_old_transient_key_when_no_glossary_applies(): void
    {
        $text = 'Dit is een zin.';
        $this->assertSame(md5('node|' . md5($text)), AI_Segment_Store::hash('node', $text, 'sv'));
        $this->assertSame(md5('menu|' . md5($text)), AI_Segment_Store::hash('MENU', $text, 'sv'), 'type is lower-cased');
    }

    public function test_hash_includes_the_glossary_signature(): void
    {
        $text = 'Ik fiets';
        $plain = AI_Segment_Store::hash('node', $text, 'de');
        AI_Translate_Core::_set_glossary_signature('abc12345');
        $withGlossary = AI_Segment_Store::hash('node', $text, 'de');

        $this->assertNotSame($plain, $withGlossary);
        $this->assertSame(md5('node|' . md5($text) . '|gabc12345'), $withGlossary);
    }

    public function test_legacy_transient_key_keeps_the_old_format(): void
    {
        $this->assertSame('ai_tr_seg_fr_deadbeef', AI_Segment_Store::legacy_transient_key('fr', 'deadbeef'));
    }

    public function test_legacy_transients_are_read_by_default(): void
    {
        $this->options([]);
        $this->assertTrue(AI_Segment_Store::legacy_allowed('sv'));
    }

    public function test_legacy_transients_are_ignored_after_clearing_everything(): void
    {
        $this->options(['ai_translate_seg_legacy_off' => ['*' => 1]]);
        $this->assertFalse(AI_Segment_Store::legacy_allowed('sv'));
        $this->assertFalse(AI_Segment_Store::legacy_allowed('de'));
    }

    public function test_legacy_transients_are_ignored_only_for_the_cleared_language(): void
    {
        $this->options(['ai_translate_seg_legacy_off' => ['sv' => 1]]);
        $this->assertFalse(AI_Segment_Store::legacy_allowed('sv'));
        $this->assertTrue(AI_Segment_Store::legacy_allowed('de'));
    }

    public function test_get_many_without_hashes_does_not_query(): void
    {
        $this->wpdb->shouldNotReceive('get_results');
        $this->assertSame([], AI_Segment_Store::get_many('sv', [], 3600));
    }

    public function test_get_many_maps_rows_by_hash(): void
    {
        $this->options(['ai_translate_segments_db_version' => '1']);
        $this->wpdb->shouldReceive('prepare')->andReturn('SELECT 1');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([
            ['seg_hash' => 'aaa', 'translation' => 'Hej'],
            ['seg_hash' => 'bbb', 'translation' => 'Hem'],
        ]);

        $this->assertSame(['aaa' => 'Hej', 'bbb' => 'Hem'], AI_Segment_Store::get_many('sv', ['aaa', 'bbb', 'ccc'], 3600));
    }

    public function test_set_many_writes_one_upsert_and_skips_empty_translations(): void
    {
        $this->options(['ai_translate_segments_db_version' => '1']);
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(static function (...$args) {
            return '(' . $args[1] . ',' . $args[2] . ')'; // lang, hash
        });
        $captured = null;
        $this->wpdb->shouldReceive('query')->once()->with(Mockery::on(static function ($sql) use (&$captured) {
            $captured = $sql;
            return true;
        }))->andReturn(1);

        AI_Segment_Store::set_many('sv', [
            'h1' => ['text' => 'Hem', 'type' => 'node'],
            'h2' => ['text' => '   ', 'type' => 'node'],
        ]);

        $this->assertStringContainsString('(sv,h1)', (string) $captured);
        $this->assertStringNotContainsString('h2', (string) $captured);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', (string) $captured);
    }

    public function test_set_many_with_nothing_to_store_does_not_query(): void
    {
        $this->wpdb->shouldNotReceive('query');
        AI_Segment_Store::set_many('sv', []);
        $this->addToAssertionCount(1);
    }
}
