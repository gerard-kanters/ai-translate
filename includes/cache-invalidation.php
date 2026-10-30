<?php

/**
 * Clearing cached pages when posts, comments and products change.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keep translated slugs in sync when the original permalink (post_name) changes.
 * Only regenerates when the source slug actually changed to minimize API calls.
 */
add_action('save_post', function ($post_id, $post, $update) {
    static $processing = false;
    if ($processing) return;
    if (wp_is_post_revision($post_id)) return;
    if (!is_object($post) || $post->post_status !== 'publish') return;
    $current_slug = (string) $post->post_name;
    $stored = get_post_meta($post_id, '_ai_translate_original_slug', true);
    if ($stored === $current_slug && $stored !== '') return;
    $processing = true;
    update_post_meta($post_id, '_ai_translate_original_slug', $current_slug);
    $default = \AITranslate\AI_Lang::default();
    $enabled = \AITranslate\AI_Translate_Core::enabled_languages();
    $detectable = \AITranslate\AI_Translate_Core::detectable_languages();
    // Limit synchronous slug translation to enabled+detectable languages. Translating to all
    // available languages here fired ~35 sequential API calls in the saving request (slow
    // post saves, painful for WooCommerce bulk imports); other languages generate on demand.
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    // Translate the titles for all languages in parallel first; the loop below then reads them
    // from the cache instead of waiting for one API round trip per language.
    \AITranslate\AI_Slugs::prime((int) $post_id, $langs);
    foreach ($langs as $lang) {
        $lang = sanitize_key((string) $lang);
        if ($default !== null && strtolower($lang) === strtolower($default)) continue;
        \AITranslate\AI_Slugs::get_or_generate((int) $post_id, $lang);
    }
    $processing = false;
}, 10, 3);

add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (!is_object($post_before) || !is_object($post_after)) return;
    if ((string) $post_before->post_name === (string) $post_after->post_name) return;
    // Re-fire core 'save_post' so language switcher / meta handlers run when slug-only updates occur.
    $cb = function_exists('get_post') ? get_post($post_id) : $post_after;
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional: re-trigger WordPress core hook
    do_action_ref_array('save_post', array($post_id, $cb, true));
}, 10, 3);

/**
 * Clear translation caches affected by a post change.
 *
 * Always clears the post's own cache. When the front page is configured to show
 * the latest blog posts (show_on_front = 'posts') and the changed post is a
 * regular 'post', also clears the homepage cache (post_id = 0) so the latest
 * posts list on the translated homepage no longer serves stale content.
 *
 * @param int                       $post_id Post ID.
 * @param \WP_Post|object|null      $post    Optional post object (avoids extra get_post call).
 * @return void
 */
function ai_translate_clear_caches_for_post_change($post_id, $post = null)
{
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return;
    }

    \AITranslate\AI_Cache_Meta::clear_post_cache($post_id);

    if (!is_object($post)) {
        $post = get_post($post_id);
    }
    if (!is_object($post) || empty($post->post_type)) {
        return;
    }
    if ($post->post_type !== 'post') {
        return;
    }
    if (get_option('show_on_front') !== 'posts') {
        return;
    }

    \AITranslate\AI_Cache_Meta::clear_post_cache(0);
}

/**
 * On content, title, or password changes of an existing published post: clear
 * translation cache for this post (and the homepage when it lists blog posts)
 * so the next visit triggers a fresh translation. Password changes must be
 * included because route_should_be_translated()/route_has_valid_content() skip
 * translation/caching entirely while a post is password-protected; removing the
 * password without this check would leave a stale (untranslated) cache in place.
 */
add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!is_object($post_before) || !is_object($post_after)) {
        return;
    }
    if ($post_after->post_status !== 'publish') {
        return;
    }
    if ((string) $post_before->post_content === (string) $post_after->post_content
        && (string) $post_before->post_title === (string) $post_after->post_title
        && (string) $post_before->post_password === (string) $post_after->post_password) {
        return;
    }
    ai_translate_clear_caches_for_post_change($post_id, $post_after);
}, 10, 3);

/**
 * Publish/unpublish/trash/restore transitions: post_updated only fires when an
 * existing post is updated, so first-time publication and status changes that
 * affect blog listings (publish<->trash, publish<->draft) need a separate hook.
 * Only acts when 'publish' is entered or left to avoid unrelated transitions
 * (e.g. draft -> auto-draft).
 */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if (!is_object($post) || empty($post->ID)) {
        return;
    }
    if ($new_status === $old_status) {
        return;
    }
    if ($new_status !== 'publish' && $old_status !== 'publish') {
        return;
    }
    if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
        return;
    }
    ai_translate_clear_caches_for_post_change((int) $post->ID, $post);
}, 10, 3);

/**
 * Clear translation cache for a single post when its publicly visible comments change.
 * Only the affected post is flushed (all languages of that post), never the whole site.
 *
 * @param int $comment_id Comment ID
 * @return void
 */
function ai_translate_clear_caches_for_comment_change($comment_id, $comment = null)
{
    $comment_id = (int) $comment_id;
    if ($comment_id <= 0) {
        return;
    }
    // On a force delete, core fires transition_comment_status AFTER the row is gone,
    // so get_comment() would return null; callers in that path pass the object along.
    if (!is_object($comment)) {
        $comment = get_comment($comment_id);
    }
    if (!is_object($comment) || empty($comment->comment_post_ID)) {
        return;
    }
    \AITranslate\AI_Cache_Meta::clear_post_cache((int) $comment->comment_post_ID);
    // Product reviews also change star ratings on listings (shop page, category/tag
    // archives). Flush those too; bounded because reviews are rare and listing sets small.
    if (get_post_type((int) $comment->comment_post_ID) === 'product') {
        ai_translate_flush_product_listing_caches((int) $comment->comment_post_ID);
    }
}

/**
 * Flush listing caches affected by a product change (e.g. review star ratings).
 * The shop page is a post-scoped route and flushes via clear_post_cache(). Product
 * category/tag archives are path-based routes without cache-meta rows, so their cache
 * files are deleted directly per enabled/detectable language. Paginated archive views
 * (/page/2/) and "related products" on other product pages refresh via normal expiry.
 *
 * @param int $product_id Product post ID.
 * @return void
 */
function ai_translate_flush_product_listing_caches($product_id)
{
    if (function_exists('wc_get_page_id')) {
        $shop_id = wc_get_page_id('shop');
        if ($shop_id > 0) {
            \AITranslate\AI_Cache_Meta::clear_post_cache($shop_id);
        }
    }
    $langs = array_values(array_unique(array_merge(
        \AITranslate\AI_Translate_Core::enabled_languages(),
        \AITranslate\AI_Translate_Core::detectable_languages()
    )));
    if (empty($langs)) {
        return;
    }
    foreach (['product_cat', 'product_tag'] as $taxonomy) {
        $terms = wp_get_post_terms($product_id, $taxonomy);
        if (!is_array($terms)) {
            continue;
        }
        foreach ($terms as $term) {
            $link = get_term_link($term);
            if (!is_string($link) || $link === '') {
                continue;
            }
            $path = (string) wp_parse_url($link, PHP_URL_PATH);
            if ($path === '') {
                continue;
            }
            $path = ai_translate_strip_site_path($path);
            // Same route formula as AI_OB::current_route_id()'s taxonomy-archive branch.
            $route = 'path:' . md5(untrailingslashit($path) . '/');
            foreach ($langs as $lang) {
                $lang = sanitize_key((string) $lang);
                if ($lang === '') {
                    continue;
                }
                \AITranslate\AI_Cache::delete(\AITranslate\AI_Cache::key($lang, $route));
            }
        }
    }
}

/**
 * Newly inserted comment: only flush when it was inserted as approved. Pending/spam
 * are skipped so spammers can never invalidate the cache by submitting comments.
 * Hooked on 'wp_insert_comment' rather than 'comment_post': wp_insert_comment()
 * itself never fires 'comment_post' (only wp_new_comment() does), so reviews added
 * directly (WP-CLI, review importers, programmatic insertion) would never flush.
 * wp_new_comment() internally calls wp_insert_comment(), so this hook covers both.
 */
add_action('wp_insert_comment', function ($comment_id, $comment) {
    if (!is_object($comment) || (string) $comment->comment_approved !== '1') {
        return;
    }
    ai_translate_clear_caches_for_comment_change((int) $comment_id);
}, 10, 2);

/**
 * Moderation transitions: flush whenever a comment enters or leaves 'approved'
 * (moderator approves a pending comment, marks an approved comment as spam/trash,
 * or restores one). Transitions between non-public states don't affect visitors.
 */
add_action('transition_comment_status', function ($new_status, $old_status, $comment) {
    if ($new_status === $old_status) {
        return;
    }
    if ($new_status !== 'approved' && $old_status !== 'approved') {
        return;
    }
    if (!is_object($comment) || empty($comment->comment_ID)) {
        return;
    }
    ai_translate_clear_caches_for_comment_change((int) $comment->comment_ID, $comment);
}, 10, 3);

/**
 * Editing an already-approved comment: content/author may have changed, so the
 * cached translation is stale. Edits on non-approved comments don't affect visitors.
 */
add_action('edit_comment', function ($comment_id) {
    $comment = get_comment($comment_id);
    if (!is_object($comment)) {
        return;
    }
    if ((string) $comment->comment_approved !== '1') {
        return;
    }
    ai_translate_clear_caches_for_comment_change((int) $comment_id);
}, 10, 1);
