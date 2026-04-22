<?php
/**
 * PHPUnit bootstrap for AI Translate unit tests.
 *
 * Defines WordPress constants and loads stub classes before loading
 * the real plugin classes under test. Brain\Monkey handles WP function
 * mocking at runtime inside each test case.
 */

define('ABSPATH', '/tmp/wordpress/');
define('DB_NAME', 'test_db');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Stub plugin classes that are dependencies (not under test themselves)
require_once __DIR__ . '/stubs/class-stubs.php';

// Load the actual plugin classes being tested
$includes = dirname(__DIR__) . '/includes/';
require_once $includes . 'class-ai-dom.php';
require_once $includes . 'class-ai-cache.php';
require_once $includes . 'class-ai-slugs.php';
require_once $includes . 'class-ai-404-recovery.php';
require_once $includes . 'class-ai-url.php';
