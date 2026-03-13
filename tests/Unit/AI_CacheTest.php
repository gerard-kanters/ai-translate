<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Cache;
use AITranslate\AI_Translate_Core;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_Cache::class)]
final class AI_CacheTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->posts = 'wp_posts';
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    // ---------------------------------------------------------------
    //  key()
    // ---------------------------------------------------------------

    public function test_key_returns_v4_format(): void
    {
        $key = AI_Cache::key('de', 'post:42');

        $this->assertStringStartsWith('ait:v4:', $key);
    }

    public function test_key_contains_language_and_route(): void
    {
        $key = AI_Cache::key('de', 'post:42');
        $parts = explode(':', $key);

        // ait : v4 : site_hash : lang : route_id...
        $this->assertSame('ait', $parts[0]);
        $this->assertSame('v4', $parts[1]);
        $this->assertSame('de', $parts[3]);
        $this->assertSame('post', $parts[4]);
        $this->assertSame('42', $parts[5]);
    }

    public function test_key_is_deterministic(): void
    {
        $a = AI_Cache::key('en', 'post:10');
        $b = AI_Cache::key('en', 'post:10');

        $this->assertSame($a, $b);
    }

    public function test_key_differs_for_different_languages(): void
    {
        $a = AI_Cache::key('de', 'post:1');
        $b = AI_Cache::key('fr', 'post:1');

        $this->assertNotSame($a, $b);
    }

    public function test_key_differs_for_different_routes(): void
    {
        $a = AI_Cache::key('de', 'post:1');
        $b = AI_Cache::key('de', 'post:2');

        $this->assertNotSame($a, $b);
    }

    public function test_key_ignores_content_version(): void
    {
        $a = AI_Cache::key('de', 'post:1', '');
        $b = AI_Cache::key('de', 'post:1', 'abc123');

        $this->assertSame($a, $b);
    }

    public function test_key_site_hash_based_on_db_name_and_prefix(): void
    {
        $expected_hash = substr(md5('test_db|wp_'), 0, 8);
        $key = AI_Cache::key('nl', 'post:5');
        $parts = explode(':', $key);

        $this->assertSame($expected_hash, $parts[2]);
    }

    public function test_key_different_prefix_produces_different_hash(): void
    {
        $key1 = AI_Cache::key('nl', 'post:5');

        $this->wpdb->prefix = 'custom_';
        $key2 = AI_Cache::key('nl', 'post:5');

        $this->assertNotSame($key1, $key2);
    }

    // ---------------------------------------------------------------
    //  file_path() via get_file_path()
    // ---------------------------------------------------------------

    public function test_file_path_uses_uploads_basedir(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/var/www/site/wp-content/uploads',
        ]);
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('sanitize_key')->alias(function ($s) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
        });

        $key = 'ait:v4:abcd1234:de:post:42';
        $path = AI_Cache::get_file_path($key);

        $this->assertStringStartsWith('/var/www/site/wp-content/uploads/ai-translate/cache/', $path);
    }

    public function test_file_path_contains_language_directory(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/var/www/uploads',
        ]);
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('sanitize_key')->alias(function ($s) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
        });

        $key = 'ait:v4:abcd1234:fr:post:10';
        $path = AI_Cache::get_file_path($key);

        $this->assertStringContainsString('/fr/pages/', $path);
    }

    public function test_file_path_ends_with_html_extension(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/var/www/uploads',
        ]);
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('sanitize_key')->alias(function ($s) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
        });

        $key = 'ait:v4:abcd1234:nl:post:1';
        $path = AI_Cache::get_file_path($key);

        $this->assertStringEndsWith('.html', $path);
    }

    public function test_file_path_uses_hash_bucket_subdirectory(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/var/www/uploads',
        ]);
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('sanitize_key')->alias(function ($s) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
        });

        $key = 'ait:v4:abcd1234:nl:post:1';
        $hash = md5($key);
        $expected_bucket = substr($hash, 0, 2);
        $path = AI_Cache::get_file_path($key);

        $this->assertStringContainsString('/pages/' . $expected_bucket . '/', $path);
        $this->assertStringContainsString($hash . '.html', $path);
    }

    public function test_file_path_includes_site_cache_dir_when_set(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/var/www/uploads',
        ]);
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('sanitize_key')->alias(function ($s) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
        });

        AI_Translate_Core::_set_site_cache_dir('mysite');

        $key = 'ait:v4:abcd1234:en:post:1';
        $path = AI_Cache::get_file_path($key);

        $this->assertStringContainsString('/cache/mysite/', $path);
    }

    public function test_file_path_no_site_dir_when_empty(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/var/www/uploads',
        ]);
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('sanitize_key')->alias(function ($s) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
        });

        AI_Translate_Core::_set_site_cache_dir('');

        $key = 'ait:v4:abcd1234:de:post:1';
        $path = AI_Cache::get_file_path($key);

        $this->assertStringNotContainsString('/cache/mysite/', $path);
        $this->assertStringContainsString('/cache/de/pages/', $path);
    }

    public function test_file_path_defaults_to_xx_for_unparseable_key(): void
    {
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/var/www/uploads',
        ]);
        Functions\when('trailingslashit')->alias(function ($s) {
            return rtrim($s, '/') . '/';
        });
        Functions\when('sanitize_key')->alias(function ($s) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
        });

        $path = AI_Cache::get_file_path('invalid-key');

        $this->assertStringContainsString('/xx/pages/', $path);
    }
}
