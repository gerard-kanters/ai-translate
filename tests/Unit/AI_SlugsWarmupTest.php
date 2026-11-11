<?php

namespace AITranslate\Tests\Unit;

use AITranslate\AI_Slugs;
use AITranslate\Tests\TestCase;
use Brain\Monkey\Functions;

#[\PHPUnit\Framework\Attributes\CoversClass(\AITranslate\AI_Slugs::class)]
final class AI_SlugsWarmupTest extends TestCase
{
    public function test_nothing_is_queued_once_the_warmup_is_done(): void
    {
        Functions\when('get_option')->alias(static fn($name, $default = false) => $name === 'ai_translate_slug_warmup_done' ? 1 : $default);
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->never();

        AI_Slugs::schedule_warmup();
        $this->addToAssertionCount(1);
    }

    public function test_nothing_is_queued_while_a_batch_is_running(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_transient')->justReturn(1);
        Functions\expect('wp_schedule_single_event')->never();

        AI_Slugs::schedule_warmup();
        $this->addToAssertionCount(1);
    }

    public function test_the_warmup_is_queued_once_in_the_background(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_single_event')
            ->once()
            ->with(\Mockery::type('int'), AI_Slugs::WARMUP_HOOK, [0]);

        AI_Slugs::schedule_warmup();
        $this->addToAssertionCount(1);
    }

    public function test_it_is_not_queued_a_second_time(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_next_scheduled')->justReturn(time() + 10);
        Functions\expect('wp_schedule_single_event')->never();

        AI_Slugs::schedule_warmup();
        $this->addToAssertionCount(1);
    }
}
