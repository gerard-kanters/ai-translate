<?php

namespace AITranslate\Tests\Unit;

use function AITranslate\is_temperature_error;
use AITranslate\Tests\TestCase;

final class TemperatureErrorTest extends TestCase
{
    /**
     * @dataProvider rejections
     */
    public function test_temperature_rejections_are_recognised(string $body): void
    {
        $this->assertTrue(is_temperature_error($body));
    }

    public static function rejections(): array
    {
        return [
            'openai' => ['{"error":{"message":"Unsupported value: temperature does not support 0","param":"temperature"}}'],
            'anthropic wording' => ['{"type":"error","error":{"type":"invalid_request_error","message":"`temperature` may only be set to 1 when thinking is enabled"}}'],
            'anthropic deprecated' => ['{"error":{"message":"temperature is deprecated for this model"}}'],
        ];
    }

    public function test_other_errors_are_not_temperature_errors(): void
    {
        $this->assertFalse(is_temperature_error('{"error":{"message":"invalid x-api-key"}}'));
    }
}
