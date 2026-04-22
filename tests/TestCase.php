<?php
/**
 * Base test case with Brain\Monkey lifecycle management.
 */

namespace AITranslate\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        \AITranslate\AI_Translate_Core::_reset();
        \AITranslate\AI_Batch::_reset();
        \AITranslate\AI_Lang::_reset();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
