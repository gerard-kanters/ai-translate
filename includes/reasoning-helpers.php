<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remove reasoning blocks (<think>…</think>, <thinking>…</thinking>) some models put in the
 * message text. Without this the thinking would end up in the translated page.
 */
function strip_thinking_blocks(string $text): string
{
    if (stripos($text, '</think') === false) {
        return $text;
    }
    $clean = preg_replace('#<(think|thinking)\b[^>]*>.*?</\1>#is', '', $text);
    // Some models omit the opening tag and only close it.
    $clean = preg_replace('#^.*?</(think|thinking)>#is', '', (string) $clean, 1);
    return ltrim((string) $clean);
}

/**
 * Whether an HTTP error body says the model cannot run with thinking disabled.
 */
function is_mandatory_reasoning_error(string $body): bool
{
    return (bool) preg_match('/reasoning\s+is\s+mandatory|cannot\s+be\s+disabled/i', $body);
}

/**
 * Rewrite a request body for a model that cannot switch thinking off: lowest effort instead.
 *
 * @param array $data Request body that was rejected.
 * @return array{0:array,1:array}|null New body and the parameters to remember, or null when nothing can change.
 */
function mandatory_reasoning_retry(array $data): ?array
{
    if (isset($data['reasoning']['effort']) || isset($data['reasoning']['enabled'])) {
        $data['reasoning'] = ['effort' => 'low'];
        return [$data, ['reasoning' => ['effort' => 'low']]];
    }
    if (isset($data['reasoning_effort'])) {
        $data['reasoning_effort'] = 'low';
        return [$data, ['reasoning_effort' => 'low']];
    }
    return null;
}
