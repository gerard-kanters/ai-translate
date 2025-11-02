<?php

namespace AITranslate;

/**
 * Batch translation abstraction (bundelt segmenten binnen één request).
 */
final class AI_Batch
{
    /**
     * Translate a plan's segments using configured provider.
     *
     * @param array $plan
     * @param string|null $source
     * @param string $target
     * @param array $context
     * @return array
     */
    public static function translate_plan(array $plan, $source, $target, array $context)
    {
        $segments = $plan['segments'] ?? [];

        $timeLimit = (int) ini_get('max_execution_time');
        $elapsed = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $remaining = $timeLimit > 0 ? ($timeLimit - $elapsed) : 60;
        if ($remaining < 15) {
            return ['segments' => [], 'map' => []];
        }

        $settings = get_option('ai_translate_settings', []);
        $provider = isset($settings['api_provider']) ? (string)$settings['api_provider'] : '';
        $models = isset($settings['models']) && is_array($settings['models']) ? $settings['models'] : [];
        $model = $provider !== '' ? ($models[$provider] ?? '') : '';
        $apiKeys = isset($settings['api_keys']) && is_array($settings['api_keys']) ? $settings['api_keys'] : [];
        $apiKey = $provider !== '' ? ($apiKeys[$provider] ?? '') : '';

        // Block GPT-5 models (gpt-5*, o1-*, o3-*) - they have 3-5x higher latency due to complex reasoning
        if ($model !== '' && preg_match('/^(gpt-5|o1-|o3-)/i', $model)) {
            return ['segments' => [], 'map' => []];
        }

        if (empty($segments)) {
            return ['segments' => [], 'map' => []];
        }

        if ($provider === '' || $model === '' || $apiKey === '') {
            return ['segments' => [], 'map' => []];
        }

        $baseUrl = \AITranslate\AI_Translate_Core::get_api_url_for_provider($provider);
        if ($provider === 'custom') {
            $baseUrl = isset($settings['custom_api_url']) ? (string)$settings['custom_api_url'] : '';
        }
        if ($baseUrl === '') {
            return ['segments' => [], 'map' => []];
        }

        // Build prompt with minimal deterministic context
        $system = self::buildSystemPrompt($source, $target, $context);
        $endpoint = rtrim($baseUrl, '/') . '/chat/completions';

        // Build primary-id set by de-duplicating equal texts per type; cache per string to reduce calls
        $targetLang = (string) $target;
        $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14*24);
        $expiry = max(1, $expiry_hours) * HOUR_IN_SECONDS;

        $primaryByKey = [];
        $idsByPrimary = [];
        $primarySegById = [];
        $cachedPrimary = [];
        $workSegments = [];
        foreach ($segments as $seg) {
            $type = isset($seg['type']) ? (string)$seg['type'] : 'node';
            $text = (string) ($seg['text'] ?? '');
            $trimmed = trim($text);
            if ($trimmed === '' || mb_strlen($trimmed) < 2) {
                continue; // skip ultra-short
            }
            $key = strtolower($type) . '|' . md5($trimmed);
            if (!isset($primaryByKey[$key])) {
                $primaryByKey[$key] = (string)$seg['id'];
                $idsByPrimary[$seg['id']] = [ (string)$seg['id'] ];
                $primarySegById[$seg['id']] = [ 'id' => (string)$seg['id'], 'text' => $trimmed, 'type' => $type ];
                // check cache and validate for non-Latin target languages
                $ckey = 'ai_tr_seg_' . $targetLang . '_' . md5($key);
                $cached = get_transient($ckey);
                if ($cached !== false) {
                    $cachedText = (string) $cached;
                    $srcLen = mb_strlen($trimmed);
                    $cachedLen = mb_strlen($cachedText);
                    $cacheInvalid = false;
                    
                    // For ALL languages and ALL lengths: check if translation is exactly identical
                    // This catches untranslated placeholders like "Naam", "Email", "Telefoon" etc.
                    // Only skip very short words (≤3 chars) like "van" → "van" which can be valid
                    if ($srcLen > 3 && trim($cachedText) === trim($trimmed)) {
                        $cacheInvalid = true;
                    }
                    
                    // For non-Latin target languages: additional check for Latin characters
                    // Only for longer texts to avoid false positives with brand names
                    if (!$cacheInvalid && $cachedLen > 100) {
                        $nonLatinLangs = ['zh', 'ja', 'ko', 'ar', 'he', 'th', 'ka'];
                        if (in_array($targetLang, $nonLatinLangs, true)) {
                            $latinCount = preg_match_all('/[a-zA-Z]/', $cachedText);
                            $latinRatio = $cachedLen > 0 ? ($latinCount / $cachedLen) : 0;
                            if ($latinRatio > 0.4) {
                                $cacheInvalid = true;
                            }
                        }
                    }
                    
                    if ($cacheInvalid) {
                        $workSegments[] = [ 'id' => (string)$seg['id'], 'text' => $trimmed, 'type' => $type ];
                    } else {
                        $cachedPrimary[$seg['id']] = $cachedText;
                    }
                } else {
                    $workSegments[] = [ 'id' => (string)$seg['id'], 'text' => $trimmed, 'type' => $type ];
                }
            } else {
                $primary = $primaryByKey[$key];
                if (!isset($idsByPrimary[$primary])) { $idsByPrimary[$primary] = []; }
                $idsByPrimary[$primary][] = (string)$seg['id'];
            }
        }

        // Use smaller chunk sizes to prevent API response truncation issues
        $chunkSize = ($provider === 'deepseek') ? 8 : 5;
        $batches = ($chunkSize < count($workSegments)) ? array_chunk($workSegments, $chunkSize) : [ $workSegments ];

        // Concurrency: for DeepSeek, parallel chunks using Requests::request_multiple if available
        $translationsPrimary = [];
        $canMulti = false;
        $reqClass = null;
        if (in_array($provider, ['deepseek','openai','custom'], true)) {
            if (class_exists('\\WpOrg\\Requests\\Requests')) { $reqClass = '\\WpOrg\\Requests\\Requests'; $canMulti = true; }
            elseif (class_exists('\\Requests')) { $reqClass = '\\Requests'; $canMulti = true; }
        }
        if ($canMulti) {
            $concurrency = 2;
            $groups = array_chunk($batches, $concurrency);
            $timeoutSeconds = ($provider === 'deepseek') ? 90 : 45;
            foreach ($groups as $gIdx => $group) {
                $requests = [];
                $metas = [];
                foreach ($group as $batchSegs) {
                    $userPayload = self::buildUserPayload($batchSegs);
                    $body = [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $userPayload],
                        ],
                    ];
                    // Newer models (gpt-5.x, o1-series) use max_completion_tokens instead of max_tokens
                    // These models also don't support temperature != 1, so we omit it
                    if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-')) {
                        $body['max_completion_tokens'] = 4096;
                    } else {
                        $body['max_tokens'] = 4096;
                        $body['temperature'] = 0;
                    }
                    $requests[] = [
                        'url' => $endpoint,
                        'headers' => [ 'Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json' ],
                        'data' => wp_json_encode($body),
                        'type' => 'POST',
                        'options' => [ 'timeout' => $timeoutSeconds, 'verify' => true ],
                    ];
                    $metas[] = $batchSegs;
                }
                // Single attempt + optional one retry for failures
                $doAttempt = function(array $requests) use ($reqClass) {
                    try { return $reqClass::request_multiple($requests); } catch (\Throwable $e) { return $e; }
                };
                $responses = $doAttempt($requests);
                if ($responses instanceof \Throwable) {
                    // Fallback to sequential handling for this group
                    foreach ($group as $idx => $batchSegs) {
                        // Reuse sequential path below by emulating one-batch run
                        $batchesSingle = [$batchSegs];
                        foreach ($batchesSingle as $i => $batchSegs2) {
                            $userPayload = self::buildUserPayload($batchSegs2);
                            $body = [ 'model' => $model, 'messages' => [ ['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userPayload] ] ];
                            if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-')) {
                                $body['max_completion_tokens'] = 4096;
                            } else {
                                $body['max_tokens'] = 4096;
                                $body['temperature'] = 0;
                            }
                            $resp = wp_remote_post($endpoint, [ 'headers' => [ 'Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json' ], 'timeout' => $timeoutSeconds, 'sslverify' => true, 'body' => wp_json_encode($body) ]);
                            if (is_wp_error($resp)) { continue; }
                            $code = (int) wp_remote_retrieve_response_code($resp);
                            if ($code !== 200) { continue; }
                            $respBody = (string) wp_remote_retrieve_body($resp);
                            $data = json_decode($respBody, true);
                            $choices = is_array($data) ? ($data['choices'] ?? []) : [];
                            if (!$choices || !isset($choices[0]['message']['content'])) { continue; }
                            $content = (string) $choices[0]['message']['content'];
                            $parsed = json_decode($content, true);
                            if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                                $candidate = str_replace(["```json","```JSON","```"], '', $content);
                                $s = strpos($candidate, '{'); $e = strrpos($candidate, '}');
                                if ($s !== false && $e !== false && $e >= $s) { $candidate = substr($candidate, $s, $e - $s + 1); }
                                $parsed = json_decode(trim($candidate), true);
                            }
                            if (is_array($parsed) && isset($parsed['translations']) && is_array($parsed['translations'])) {
                                foreach ($parsed['translations'] as $k => $v) { $translationsPrimary[(string)$k] = (string)$v; }
                            }
                        }
                    }
                    continue;
                }
                // Process responses; retry failures once
                $failedIndexes = [];
                foreach ($responses as $idx => $resp) {
                    $ok = is_object($resp) && property_exists($resp, 'status_code') ? ((int)$resp->status_code === 200) : false;
                    if (!$ok) { 
                        $failedIndexes[] = (int)$idx; 
                        continue; 
                    }
                    $content = '';
                    if (is_object($resp) && property_exists($resp, 'body')) { $content = (string) $resp->body; }
                    $data = json_decode($content, true);
                    $choices = is_array($data) ? ($data['choices'] ?? []) : [];
                    if (!$choices || !isset($choices[0]['message']['content'])) { 
                        $failedIndexes[] = (int)$idx; 
                        continue; 
                    }
                    $msg = (string)$choices[0]['message']['content'];
                    $parsed = json_decode($msg, true);
                    if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                        $candidate = str_replace(["```json","```JSON","```"], '', $msg);
                        $s = strpos($candidate, '{'); $e = strrpos($candidate, '}');
                        if ($s !== false && $e !== false && $e >= $s) { $candidate = substr($candidate, $s, $e - $s + 1); }
                        $parsed = json_decode(trim($candidate), true);
                    }
                    if (is_array($parsed) && isset($parsed['translations']) && is_array($parsed['translations'])) {
                        foreach ($parsed['translations'] as $k => $v) { $translationsPrimary[(string)$k] = (string)$v; }
                    } else {
                        $failedIndexes[] = (int)$idx;
                    }
                }
                if (!empty($failedIndexes)) {
                    $retryReqs = [];
                    $retryMetas = [];
                    foreach ($failedIndexes as $fi) { $retryReqs[] = $requests[$fi]; $retryMetas[] = $metas[$fi]; }
                    $retryRes = $doAttempt($retryReqs);
                    if (!($retryRes instanceof \Throwable)) {
                        foreach ($retryRes as $rIdx => $resp) {
                            $ok = is_object($resp) && property_exists($resp, 'status_code') ? ((int)$resp->status_code === 200) : false;
                            if (!$ok) { continue; }
                            $content = is_object($resp) && property_exists($resp, 'body') ? (string)$resp->body : '';
                            $data = json_decode($content, true);
                            $choices = is_array($data) ? ($data['choices'] ?? []) : [];
                            if (!$choices || !isset($choices[0]['message']['content'])) { continue; }
                            $msg = (string)$choices[0]['message']['content'];
                            $parsed = json_decode($msg, true);
                            if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                                $candidate = str_replace(["```json","```JSON","```"], '', $msg);
                                $s = strpos($candidate, '{'); $e = strrpos($candidate, '}');
                                if ($s !== false && $e !== false && $e >= $s) { $candidate = substr($candidate, $s, $e - $s + 1); }
                                $parsed = json_decode(trim($candidate), true);
                            }
                            if (is_array($parsed) && isset($parsed['translations']) && is_array($parsed['translations'])) {
                                foreach ($parsed['translations'] as $k => $v) { $translationsPrimary[(string)$k] = (string)$v; }
                            }
                        }
                    }
                }
            }
            // Validate coverage and quality; retry missing/unchanged primaries once
            $needsRetry = [];
            foreach ($primarySegById as $pid => $meta) {
                if (isset($translationsPrimary[$pid])) {
                    $tr = (string)$translationsPrimary[$pid];
                    $src = (string)$meta['text'];
                    $srcLen = mb_strlen($src);
                    $trLen = mb_strlen($tr);
                    
                    // For ALL languages and ALL lengths: check if translation is exactly identical
                    // This catches untranslated placeholders like "Naam", "Email", "Telefoon" etc.
                    // Only skip very short words (≤3 chars) like "van" → "van" which can be valid
                    if ($srcLen > 3 && trim($tr) === trim($src)) {
                        $needsRetry[] = $pid;
                    }
                    // For non-Latin target languages with longer texts: additional check for Latin ratio
                    // Only trigger if >40% Latin to avoid false positives with brand names
                    elseif ($trLen > 100 && in_array($targetLang, ['zh', 'ja', 'ko', 'ar', 'he', 'th', 'ka'], true)) {
                        $latinChars = preg_match_all('/[A-Za-z]/', $tr);
                        $latinRatio = $trLen > 0 ? ($latinChars / $trLen) : 0;
                        if ($latinRatio > 0.4) {
                            $needsRetry[] = $pid;
                        }
                    }
                } elseif (!isset($cachedPrimary[$pid])) {
                    $needsRetry[] = $pid;
                }
            }
            if (!empty($needsRetry)) {
                $strictSystem = $system . "\n\nSTRICT: Do not copy the source text. ALWAYS translate into the target language. Never return the source text unchanged unless it's a proper noun or brand name.";
                $retrySegs = [];
                foreach ($needsRetry as $pid) { $retrySegs[] = $primarySegById[$pid]; }
                $retryChunks = array_chunk($retrySegs, 5);
                foreach ($retryChunks as $rc) {
                    $userPayload = self::buildUserPayload($rc);
                    $body = [ 'model' => $model, 'messages' => [ ['role' => 'system', 'content' => $strictSystem], ['role' => 'user', 'content' => $userPayload] ] ];
                    if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-')) {
                        $body['max_completion_tokens'] = 4096;
                    } else {
                        $body['max_tokens'] = 4096;
                        $body['temperature'] = 0;
                    }
                    $resp = wp_remote_post($endpoint, [ 'headers' => [ 'Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json' ], 'timeout' => $timeoutSeconds, 'sslverify' => true, 'body' => wp_json_encode($body) ]);
                    if (is_wp_error($resp)) { continue; }
                    if ((int)wp_remote_retrieve_response_code($resp) !== 200) { continue; }
                    $rb = (string) wp_remote_retrieve_body($resp);
                    $d = json_decode($rb, true);
                    $choices = is_array($d) ? ($d['choices'] ?? []) : [];
                    if (!$choices || !isset($choices[0]['message']['content'])) { continue; }
                    $msg = (string) $choices[0]['message']['content'];
                    $parsed = json_decode($msg, true);
                    if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                        $cand = str_replace(["```json","```JSON","```"], '', $msg);
                        $s = strpos($cand, '{'); $e = strrpos($cand, '}');
                        if ($s !== false && $e !== false && $e >= $s) { $cand = substr($cand, $s, $e - $s + 1); }
                        $parsed = json_decode(trim($cand), true);
                    }
                    if (is_array($parsed) && isset($parsed['translations']) && is_array($parsed['translations'])) {
                        foreach ($parsed['translations'] as $k => $v) { $translationsPrimary[(string)$k] = (string)$v; }
                    }
                }
            }

            // Write cache for primaries and expand to full id map
            foreach ($translationsPrimary as $pid => $tr) {
                if (isset($primarySegById[$pid])) {
                    $key = strtolower($primarySegById[$pid]['type']) . '|' . md5($primarySegById[$pid]['text']);
                    set_transient('ai_tr_seg_' . $targetLang . '_' . md5($key), (string)$tr, $expiry);
                }
            }
            $final = [];
            // include cached primaries as well
            foreach ($cachedPrimary as $pid => $tr) {
                $translationsPrimary[$pid] = $tr;
            }
            foreach ($idsByPrimary as $pid => $ids) {
                $tr = isset($translationsPrimary[$pid]) ? (string)$translationsPrimary[$pid] : null;
                if ($tr === null) { continue; }
                foreach ($ids as $oid) { $final[$oid] = $tr; }
            }
            return ['segments' => $final, 'map' => []];
        }

        foreach ($batches as $i => $batchSegs) {
            $userPayload = self::buildUserPayload($batchSegs);
            $body = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userPayload],
                ],
            ];
            // Newer models (gpt-5.x, o1-series) use max_completion_tokens instead of max_tokens
            // These models also don't support temperature != 1, so we omit it
            if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-')) {
                $body['max_completion_tokens'] = 4096;
            } else {
                $body['max_tokens'] = 4096;
                $body['temperature'] = 0;
            }

            $timeoutSeconds = ($provider === 'deepseek') ? 90 : 45;
            $attempts = 0;
            $maxAttempts = 1;
            $response = null;
            do {
                $attempts++;
                $timeRemaining = $timeLimit > 0 ? ($timeLimit - (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)))) : 60;
                $safeTimeout = min($timeoutSeconds, max(10, (int)($timeRemaining - 10)));
                
                $response = wp_remote_post($endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'timeout' => $safeTimeout,
                    'connect_timeout' => 10,
                    'sslverify' => true,
                    'body' => wp_json_encode($body),
                ]);
                if (is_wp_error($response)) {
                    if ($attempts < $maxAttempts) { usleep(300000); }
                    continue;
                }
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code !== 200) {
                    if ($attempts < $maxAttempts) { usleep(300000); continue; }
                }
                break;
            } while ($attempts < $maxAttempts);

            if (is_wp_error($response)) {
                break;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                break;
            }

            $respBody = (string) wp_remote_retrieve_body($response);
            $data = json_decode($respBody, true);
            $choices = is_array($data) ? ($data['choices'] ?? []) : [];
            if (!$choices || !isset($choices[0]['message']['content'])) {
                break;
            }
            $content = (string) $choices[0]['message']['content'];
            $parsed = json_decode($content, true);
            if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                $candidate = $content;
                $candidate = str_replace(["```json", "```JSON", "```"], '', $candidate);
                $start = strpos($candidate, '{');
                $end = strrpos($candidate, '}');
                if ($start !== false && $end !== false && $end >= $start) {
                    $candidate = substr($candidate, $start, $end - $start + 1);
                }
                $parsed = json_decode(trim($candidate), true);
            }
            if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                break;
            }
            foreach ($parsed['translations'] as $k => $v) {
                $translationsPrimary[(string) $k] = (string) $v;
            }
        }
        // Cache and expand to all ids
        foreach ($translationsPrimary as $pid => $tr) {
            if (isset($primarySegById[$pid])) {
                $key = strtolower($primarySegById[$pid]['type']) . '|' . md5($primarySegById[$pid]['text']);
                set_transient('ai_tr_seg_' . $targetLang . '_' . md5($key), (string)$tr, $expiry);
            }
        }
        $final = [];
        foreach ($cachedPrimary as $pid => $tr) { $translationsPrimary[$pid] = $tr; }
        foreach ($idsByPrimary as $pid => $ids) {
            $tr = isset($translationsPrimary[$pid]) ? (string)$translationsPrimary[$pid] : null;
            if ($tr === null) { continue; }
            foreach ($ids as $oid) { $final[$oid] = $tr; }
        }
        return ['segments' => $final, 'map' => []];
    }

    private static function buildSystemPrompt($source, $target, array $ctx)
    {
        return \AITranslate\AI_Translate_Core::build_translation_system_prompt($source, $target, $ctx);
    }

    private static function buildUserPayload(array $segments)
    {
        $payload = [ 'segments' => [] ];
        foreach ($segments as $seg) {
            $payload['segments'][] = [
                'id' => (string) $seg['id'],
                'text' => (string) $seg['text'],
                'type' => (string) $seg['type'],
                'attr' => isset($seg['attr']) ? (string) $seg['attr'] : null,
            ];
        }
        $payload['instruction'] = 'Return JSON: {"translations": {"<id>": "<translated>"}}. No extra text.';
        return wp_json_encode($payload);
    }
}


