<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Lang;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

use function AITranslate\path_is_inside;
use function AITranslate\rate_limit_hit;
use function AITranslate\lang_is_offered;

#[\PHPUnit\Framework\Attributes\CoversFunction('AITranslate\path_is_inside')]
#[\PHPUnit\Framework\Attributes\CoversFunction('AITranslate\rate_limit_hit')]
#[\PHPUnit\Framework\Attributes\CoversFunction('AITranslate\lang_is_offered')]
final class HelpersTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_normalize_path')->alias(static fn($p) => str_replace('\\', '/', (string) $p));
        $this->root = sys_get_temp_dir() . '/ai-translate-helpers-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/cache/sv', 0777, true);
        mkdir($this->root . '/cache2', 0777, true);
        file_put_contents($this->root . '/cache/sv/page.html', 'x');
        file_put_contents($this->root . '/outside.txt', 'x');
        file_put_contents($this->root . '/cache2/other.html', 'x');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isLink() || $f->isFile() ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    //  path_is_inside()
    // ---------------------------------------------------------------

    public function test_a_file_inside_the_base_is_accepted(): void
    {
        $this->assertTrue(path_is_inside($this->root . '/cache/sv/page.html', $this->root . '/cache/'));
    }

    public function test_parent_directory_segments_cannot_escape_the_base(): void
    {
        // The string starts with the base path, but resolves to a file outside it.
        $this->assertTrue(file_exists($this->root . '/cache/../outside.txt'));
        $this->assertFalse(path_is_inside($this->root . '/cache/../outside.txt', $this->root . '/cache/'));
    }

    public function test_a_sibling_directory_with_the_same_prefix_is_rejected(): void
    {
        $this->assertFalse(path_is_inside($this->root . '/cache2/other.html', $this->root . '/cache'));
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->assertFalse(path_is_inside($this->root . '/cache/sv/missing.html', $this->root . '/cache/'));
    }

    public function test_a_symlink_that_points_outside_is_rejected(): void
    {
        if (!@symlink($this->root . '/outside.txt', $this->root . '/cache/sv/link.html')) {
            $this->markTestSkipped('Symlinks are not available here.');
        }
        $this->assertFalse(path_is_inside($this->root . '/cache/sv/link.html', $this->root . '/cache/'));
    }

    // ---------------------------------------------------------------
    //  rate_limit_hit()
    // ---------------------------------------------------------------

    public function test_the_counter_in_the_object_cache_stops_at_the_limit(): void
    {
        $store = [];
        Functions\when('wp_using_ext_object_cache')->justReturn(true);
        // Mimics a drop-in whose add() overwrites an existing key.
        Functions\when('wp_cache_add')->alias(static function ($key, $value) use (&$store) {
            $store[$key] = $value;
            return true;
        });
        Functions\when('wp_cache_incr')->alias(static function ($key, $amount) use (&$store) {
            if (!isset($store[$key])) {
                return false;
            }
            $store[$key] += $amount;
            return $store[$key];
        });

        $this->assertTrue(rate_limit_hit('b', 2, 60));
        $this->assertTrue(rate_limit_hit('b', 2, 60));
        $this->assertFalse(rate_limit_hit('b', 2, 60));
    }

    public function test_an_amount_larger_than_the_room_left_is_refused(): void
    {
        $store = [];
        Functions\when('wp_using_ext_object_cache')->justReturn(true);
        Functions\when('wp_cache_add')->alias(static function ($key, $value) use (&$store) {
            $store[$key] = $value;
            return true;
        });
        Functions\when('wp_cache_incr')->alias(static function ($key, $amount) use (&$store) {
            if (!isset($store[$key])) {
                return false;
            }
            $store[$key] += $amount;
            return $store[$key];
        });

        $this->assertTrue(rate_limit_hit('budget', 10, 3600, 8));
        $this->assertFalse(rate_limit_hit('budget', 10, 3600, 5));
    }

    public function test_without_an_object_cache_a_transient_is_used(): void
    {
        $store = [];
        Functions\when('wp_using_ext_object_cache')->justReturn(false);
        Functions\when('get_transient')->alias(static function ($key) use (&$store) {
            return $store[$key] ?? false;
        });
        Functions\when('set_transient')->alias(static function ($key, $value) use (&$store) {
            $store[$key] = $value;
            return true;
        });

        $this->assertTrue(rate_limit_hit('ip', 2, 60));
        $this->assertTrue(rate_limit_hit('ip', 2, 60));
        $this->assertFalse(rate_limit_hit('ip', 2, 60));
        $this->assertTrue(rate_limit_hit('other-ip', 2, 60), 'each bucket counts on its own');
    }

    // ---------------------------------------------------------------
    //  lang_is_offered()
    // ---------------------------------------------------------------

    public function test_only_offered_languages_are_allowed(): void
    {
        AI_Lang::_setDefault('nl');
        AI_Lang::_setEnabled(['en', 'de']);
        AI_Lang::_setDetectable(['fr']);

        foreach (['nl', 'NL', 'en', ' de ', 'fr'] as $ok) {
            $this->assertTrue(lang_is_offered($ok), "'$ok' should be allowed");
        }
        foreach (['zz', 'es', '', '../x', 'nl-be'] as $bad) {
            $this->assertFalse(lang_is_offered($bad), "'$bad' should be refused");
        }
    }
}
