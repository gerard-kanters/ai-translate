<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional background warm-up after a page is published or updated.
 *
 * Without it the first visitor in each language waits for the translation (several seconds).
 * When the setting is on, saving a published post schedules a WP-Cron event that requests the
 * page in every enabled language, a few at a time, like the Warm Cache button does. Visitors
 * and the editor are not held up. Off by default: it uses the translation API for every
 * language right after each save.
 */
class AI_Prewarm
{
    public const HOOK = 'ai_translate_prewarm_post';
    private const BATCH = 4;
    private const BUDGET_SECONDS = 200;

    public static function init(): void
    {
        add_action('save_post', [self::class, 'schedule'], 99, 2);
        add_action(self::HOOK, [self::class, 'run'], 10, 2);
    }

    public static function enabled(): bool
    {
        return (bool) AI_Translate_Core::get_setting('prewarm_on_publish', false)
            && !(bool) AI_Translate_Core::get_setting('stop_translations_except_cache_invalidation', false);
    }

    /**
     * save_post callback: queue the warm-up (once per post until it has run).
     *
     * @param int           $post_id
     * @param \WP_Post|null $post
     */
    public static function schedule($post_id, $post = null): void
    {
        if (!self::enabled() || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $post = $post instanceof \WP_Post ? $post : get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_type === 'attachment' || !is_post_type_viewable($post->post_type)) {
            return;
        }
        $args = [(int) $post_id, 0];
        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time() + 30, self::HOOK, $args);
        }
    }

    /**
     * Languages to warm for a post: enabled and detectable, without the default language.
     *
     * @return string[]
     */
    public static function languages(): array
    {
        $default = AI_Lang::default();
        $all = array_unique(array_merge(AI_Lang::enabled(), AI_Lang::detectable()));
        $langs = [];
        foreach ($all as $lang) {
            $lang = sanitize_key((string) $lang);
            if ($lang !== '' && ($default === null || strtolower($lang) !== strtolower($default))) {
                $langs[] = $lang;
            }
        }
        return array_values(array_unique($langs));
    }

    /**
     * Cron callback. Works through the languages within a time budget; when time runs out it
     * schedules itself again from where it stopped.
     *
     * @param int $post_id
     * @param int $offset  Index in languages() to continue from.
     */
    public static function run($post_id, $offset = 0): void
    {
        $post_id = (int) $post_id;
        $offset = max(0, (int) $offset);
        if (!self::enabled() || !function_exists(__NAMESPACE__ . '\\warm_cache_batch')) {
            return;
        }
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }
        if (in_array($post_id, AI_Cache_Meta::get_never_cached_post_ids(), true)) {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::BUDGET_SECONDS + 90);
        }

        $langs = self::languages();
        $existing = AI_Cache_Meta::get_cached_languages($post_id);
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $i = $offset;
        $total = count($langs);
        for (; $i < $total; $i += self::BATCH) {
            if (microtime(true) > $deadline) {
                break;
            }
            $todo = array_values(array_diff(array_slice($langs, $i, self::BATCH), $existing));
            if ($todo !== []) {
                warm_cache_batch($post_id, '', $todo);
            }
        }
        if ($i < $total) {
            wp_schedule_single_event(time() + 10, self::HOOK, [$post_id, $i]);
        }
    }
}
