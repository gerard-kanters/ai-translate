<?php
/**
 * Minimal stand-in for WordPress core's sitemap provider base class,
 * so class-ai-sitemap.php can be loaded in unit tests.
 */

if (!class_exists('WP_Sitemaps_Provider')) {
    abstract class WP_Sitemaps_Provider
    {
        protected $name = '';
        protected $object_type = '';
    }
}
