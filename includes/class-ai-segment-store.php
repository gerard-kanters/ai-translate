<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent store for translated page segments.
 *
 * Segments used to live only in transients (ai_tr_seg_*). With an external object cache such as
 * memcached those never reach the options table, so they were lost on a cache restart or flush
 * and the "clear cache" actions, which delete options rows, never removed them. This store keeps
 * them in a table of their own: one row per language and segment hash, with the translation and
 * the time it was made. Existing transients are still read once and copied here (see
 * AI_Batch::translate_plan), so upgrading does not retranslate anything.
 */
class AI_Segment_Store
{
    private const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'ai_translate_segments_db_version';
    private const LEGACY_OFF_OPTION = 'ai_translate_seg_legacy_off';
    private const PURGE_HOOK = 'ai_translate_segments_purge';

    /**
     * @return string Table name including the WordPress prefix.
     */
    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ai_translate_segments';
    }

    /**
     * Create the table when it is missing or outdated. Costs one cached get_option() per request.
     */
    public static function ensure_table(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        self::create_table();
    }

    public static function create_table(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lang VARCHAR(20) NOT NULL,
            seg_hash CHAR(32) NOT NULL,
            seg_type VARCHAR(16) NOT NULL DEFAULT 'node',
            translation MEDIUMTEXT NOT NULL,
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lang_hash (lang, seg_hash),
            KEY created_at (created_at),
            KEY seg_type (seg_type)
        ) $charset;";
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
    }

    /**
     * Hash that identifies a segment in the store. Equals the key the old transients used
     * (md5 of "type|md5(text)") unless a glossary rule applies to the text: then the matching
     * rules are part of the hash, so changing the glossary retranslates only the segments it touches.
     */
    public static function hash(string $type, string $text, string $lang): string
    {
        $base = strtolower($type) . '|' . md5($text);
        $signature = AI_Translate_Core::glossary_signature($text, $lang);
        if ($signature !== '') {
            $base .= '|g' . $signature;
        }
        return md5($base);
    }

    /**
     * Name of the legacy transient for a segment hash (same key as before the store existed).
     */
    public static function legacy_transient_key(string $lang, string $hash): string
    {
        return 'ai_tr_seg_' . $lang . '_' . $hash;
    }

    /**
     * Fetch translations for several segment hashes in one query.
     *
     * @param string   $lang    Target language.
     * @param string[] $hashes  Segment hashes.
     * @param int      $maxAge  Maximum age in seconds (the cache duration setting).
     * @return array<string,string> hash => translation (only hits)
     */
    public static function get_many(string $lang, array $hashes, int $maxAge): array
    {
        global $wpdb;
        $hashes = array_values(array_unique(array_filter($hashes, 'is_string')));
        if ($hashes === []) {
            return [];
        }
        self::ensure_table();
        $table = self::table_name();
        $minCreated = $maxAge > 0 ? time() - $maxAge : 0;
        $found = [];
        foreach (array_chunk($hashes, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name and placeholder list are built here
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT seg_hash, translation FROM $table WHERE lang = %s AND created_at >= %d AND seg_hash IN ($placeholders)",
                array_merge([$lang, $minCreated], $chunk)
            ), ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $found[(string) $row['seg_hash']] = (string) $row['translation'];
                }
            }
        }
        return $found;
    }

    /**
     * Store translations.
     *
     * @param string                                         $lang Target language.
     * @param array<string,array{text:string,type?:string}> $rows hash => translation and segment type.
     */
    public static function set_many(string $lang, array $rows): void
    {
        global $wpdb;
        if ($rows === []) {
            return;
        }
        self::ensure_table();
        $table = self::table_name();
        $now = time();
        foreach (array_chunk($rows, 100, true) as $chunk) {
            $values = [];
            foreach ($chunk as $hash => $row) {
                $text = trim((string) ($row['text'] ?? ''));
                if ($text === '' || !is_string($hash)) {
                    continue;
                }
                $type = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($row['type'] ?? 'node'))), 0, 16);
                $values[] = $wpdb->prepare('(%s, %s, %s, %s, %d)', $lang, $hash, $type !== '' ? $type : 'node', $text, $now);
            }
            if ($values === []) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every value is prepared above
            $wpdb->query("INSERT INTO $table (lang, seg_hash, seg_type, translation, created_at) VALUES " . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE translation = VALUES(translation), seg_type = VALUES(seg_type), created_at = VALUES(created_at)');
        }
    }

    /**
     * Delete all segments of one language and stop reading old transients for it.
     */
    public static function delete_lang(string $lang): void
    {
        global $wpdb;
        self::ensure_table();
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE lang = %s", $lang));
        self::disable_legacy($lang);
    }

    /**
     * Delete every stored segment and stop reading old transients.
     */
    public static function delete_all(): void
    {
        global $wpdb;
        self::ensure_table();
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("TRUNCATE TABLE $table");
        self::disable_legacy(null);
    }

    /**
     * Delete the stored segments of one type (e.g. 'menu') in every language.
     */
    public static function delete_type(string $type): void
    {
        global $wpdb;
        self::ensure_table();
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE seg_type = %s", strtolower($type)));
    }

    /**
     * Remove rows older than the cache duration.
     */
    public static function purge_expired(int $maxAge): int
    {
        global $wpdb;
        if ($maxAge <= 0) {
            return 0;
        }
        self::ensure_table();
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < %d", time() - $maxAge));
        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Whether old ai_tr_seg_* transients may still be read for this language. They are until the
     * user clears the cache: after that they must stay ignored, or a "clear" would bring them back.
     */
    public static function legacy_allowed(string $lang): bool
    {
        $off = get_option(self::LEGACY_OFF_OPTION, []);
        if (!is_array($off)) {
            return true;
        }
        return empty($off['*']) && empty($off[$lang]);
    }

    private static function disable_legacy(?string $lang): void
    {
        $off = get_option(self::LEGACY_OFF_OPTION, []);
        if (!is_array($off)) {
            $off = [];
        }
        $off[$lang ?? '*'] = 1;
        update_option(self::LEGACY_OFF_OPTION, $off, true);
    }

    /**
     * Schedule the daily purge of expired rows. Called on init.
     */
    public static function schedule_purge(): void
    {
        if (!wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }
    }

    /**
     * Cron callback.
     */
    public static function run_purge(): void
    {
        $settings = AI_Translate_Core::settings();
        $hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
        self::purge_expired($hours * HOUR_IN_SECONDS);
    }

    public static function unschedule_purge(): void
    {
        $timestamp = wp_next_scheduled(self::PURGE_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::PURGE_HOOK);
        }
    }

    public static function purge_hook(): string
    {
        return self::PURGE_HOOK;
    }
}
