<?php

/**
 * Helpers that point the main WP query at a resolved post, term or archive during parse_request.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query vars that select a single post; removed before the resolved target is set.
 */
const AI_TRANSLATE_POST_QUERY_VARS = ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1];

/**
 * Remove the query vars that select a post.
 *
 * @param array $query_vars    Current query vars.
 * @param bool  $with_own_path Also remove the plugin's own path var.
 * @return array
 */
function ai_translate_clear_post_query_vars(array $query_vars, bool $with_own_path = false): array
{
    return array_diff_key($query_vars, $with_own_path ? AI_TRANSLATE_POST_QUERY_VARS + ['ai_translate_path' => 1] : AI_TRANSLATE_POST_QUERY_VARS);
}

/**
 * Route the request to the archive of a post type.
 *
 * @param object $wp        WP object from parse_request.
 * @param string $post_type Post type whose archive is shown.
 */
function ai_translate_route_to_archive($wp, string $post_type): void
{
    $wp->query_vars = ai_translate_clear_post_query_vars($wp->query_vars, true);
    $wp->query_vars['post_type'] = $post_type;
    $wp->is_archive = true;
    $wp->is_singular = false;
    $wp->is_404 = false;
}

/**
 * The one published post among the candidates, or null when none or several qualify.
 * Several posts sharing a slug are ambiguous, so none is picked.
 *
 * @param int[]         $candidate_ids Post IDs sharing a (translated) slug.
 * @param string|null   $post_type     Required post type; null accepts anything except attachments.
 * @return int|null
 */
function ai_translate_unique_published_post(array $candidate_ids, ?string $post_type = null): ?int
{
    $unique = null;
    foreach ($candidate_ids as $cid) {
        $cp = get_post((int) $cid);
        if (!$cp || $cp->post_status !== 'publish') {
            continue;
        }
        if ($post_type !== null ? $cp->post_type !== $post_type : $cp->post_type === 'attachment') {
            continue;
        }
        if ($unique === null) {
            $unique = (int) $cid;
        } elseif ($unique !== (int) $cid) {
            return null;
        }
    }
    return $unique;
}

/**
 * Point the query at a page, the blog index (when the page is the posts page) or a single post.
 *
 * @param object $wp   WP object from parse_request.
 * @param object $post Resolved post.
 */
function ai_translate_route_to_post($wp, $post): void
{
    if ($post->post_type === 'page') {
        $posts_page_id = (int) get_option('page_for_posts');
        if ($posts_page_id > 0 && (int) $post->ID === $posts_page_id) {
            // The posts page is the blog index, so pagination works
            $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
            $wp->is_home = true;
            $wp->is_page = false;
            $wp->is_singular = false;
        } else {
            $wp->query_vars['page_id'] = (int) $post->ID;
            $wp->is_page = true;
            $wp->is_singular = true;
        }
        return;
    }
    $wp->query_vars['p'] = (int) $post->ID;
    $wp->query_vars['post_type'] = $post->post_type;
    $wp->is_single = true;
    $wp->is_singular = true;
}
