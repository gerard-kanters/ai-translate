# Cache Management Table

## Doel
Geef administrators inzicht in welke pagina's en posts vertaald zijn, zodat ze gericht cache kunnen beheren (verwijderen of opwarmen) zonder afhankelijk te zijn van bezoekers die de pagina's bezoeken.

## Probleemanalyse
Momenteel is er geen overzicht van:
1. **Welke pagina's zijn vertaald?** Administrators weten niet welke content al in cache staat.
2. **In hoeveel talen?** Er is geen zicht op de compleetheid van vertalingen per pagina.
3. **Hoe cache te beheren?** Er is geen mogelijkheid om selectief cache te verwijderen of proactief op te warmen.

Dit leidt tot:
- Onnodig veel API-calls omdat niet duidelijk is wat al vertaald is
- Geen mogelijkheid om strategisch vertalingen voor te bereiden
- Moeilijk om cache-problemen op te lossen voor specifieke pagina's

### Performance & UI Uitdagingen
Bij het implementeren moet rekening gehouden worden met:
1. **Performance:** Realtime scannen van filesystem per pagina is te traag bij veel content
2. **Overzichtelijkheid:** Vlaggetjes per taal worden onoverzichtelijk bij veel talen (10+)

## Functionaliteit

### Tabel Weergave
In de "Cache" tab van de admin pagina komt onderaan een tabel met de volgende kolommen:

| Kolom | Beschrijving | Bron |
|-------|--------------|------|
| **Type** | "Page" of "Post" | `post_type` |
| **Titel** | De pagina/post titel | `post_title` |
| **URL** | De permalink | `get_permalink()` |
| **Status** | "3 van 5 talen" met kleurcodering | Cache metadata tabel |
| **Acties** | Twee knoppen (zie onder) | - |

**Opmerking:** Geen individuele vlaggetjes per taal - dit wordt onoverzichtelijk bij veel talen. In plaats daarvan een duidelijke status indicator.

### Action Buttons

#### 1. Delete Cache
**Functie:** Verwijdert alle cache bestanden van deze pagina in alle talen.

**Proces:**
1. Scan de cache directory voor bestanden die matchen met het post/page ID
2. Verwijder alle `.cache` bestanden voor deze pagina
3. Log de actie
4. Toon bevestigingsmelding: "Cache verwijderd voor [Titel] in X talen"

**Use cases:**
- Pagina is aangepast en moet opnieuw vertaald worden
- Cache is corrupt of bevat fouten
- Content structuur is veranderd

#### 2. Warm Cache
**Functie:** Vertaalt de pagina proactief in alle geselecteerde talen (zowel selectable als detectable).

**Proces:**
1. Haal lijst van enabled talen op (selectable + detectable)
2. Voor elke taal:
   - Check of cache al bestaat (skip indien ja, tenzij force)
   - Genereer vertaling via normale translate flow
   - Sla op in cache
3. Toon voortgangsmelding: "Cache wordt opgewarmd voor [Titel]... (2/5)"
4. Toon voltooiingsmelding: "Cache opgewarmd voor [Titel] in 5 talen"

**Use cases:**
- Nieuwe pagina publiceren en direct alle vertalingen genereren
- Voor belangrijke pagina's (homepage, contact) cache vooraf vullen
- Na bulk updates cache in één keer regenereren

## Technische Implementatie

### 1. Cache Metadata Database Tabel

**Probleem:** Realtime filesystem scanning is te traag bij veel pagina's.

**Oplossing:** Dedicated database tabel die cache status bijhoudt.

#### Tabel Schema: `wp_ai_translate_cache_meta`
```sql
CREATE TABLE wp_ai_translate_cache_meta (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id BIGINT UNSIGNED NOT NULL,
    language_code VARCHAR(10) NOT NULL,
    cache_file VARCHAR(255) NOT NULL,
    cache_hash VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    file_size INT UNSIGNED DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY post_lang (post_id, language_code),
    KEY post_id (post_id),
    KEY language_code (language_code),
    KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Voordelen:**
- Instant queries zonder filesystem I/O
- Eenvoudig aggregeren: `COUNT(*) GROUP BY post_id`
- Historische data (created_at, file_size)
- Kan uitgebreid worden met extra metadata

#### Wanneer Bijwerken?
De tabel wordt bijgewerkt op drie momenten:

1. **Bij cache creatie:** Na succesvolle vertaling, insert record
2. **Bij cache verwijdering:** Delete corresponderend record
3. **Background sync:** Cron job die filesystem scant en tabel synchroniseert (fallback)

### 2. Data Ophalen Voor Tabel

#### Query Met Cache Status
```php
/**
 * Get posts with cache statistics
 *
 * @return array Array of posts with cache info
 */
function ai_translate_get_posts_with_cache_stats() {
    global $wpdb;
    
    $total_languages = count(ai_translate_get_enabled_languages());
    
    $query = "
        SELECT 
            p.ID,
            p.post_type,
            p.post_title,
            COUNT(c.language_code) as cached_languages
        FROM 
            {$wpdb->posts} p
        LEFT JOIN 
            {$wpdb->prefix}ai_translate_cache_meta c ON p.ID = c.post_id
        WHERE 
            p.post_status = 'publish'
            AND p.post_type IN ('page', 'post')
        GROUP BY 
            p.ID
        ORDER BY 
            p.post_type ASC, p.post_title ASC
    ";
    
    $results = $wpdb->get_results($query);
    
    // Add percentage and URL
    foreach ($results as &$row) {
        $row->total_languages = $total_languages;
        $row->percentage = $total_languages > 0 
            ? round(($row->cached_languages / $total_languages) * 100) 
            : 0;
        $row->url = get_permalink($row->ID);
    }
    
    return $results;
}
```

**Performance:** 
- Single JOIN query voor alle posts
- Geen filesystem I/O
- Pagination vriendelijk
- Resultaat kan in transient gecached worden (5 minuten)

### 3. UI Rendering

#### Tabel HTML
```php
<div class="wrap">
    <h2>Vertaalde Pagina's Overzicht</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 8%;">Type</th>
                <th style="width: 30%;">Titel</th>
                <th style="width: 25%;">URL</th>
                <th style="width: 12%;">Status</th>
                <th style="width: 25%;">Acties</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts as $post): ?>
            <tr>
                <td><?php echo esc_html(ucfirst($post->post_type)); ?></td>
                <td>
                    <strong><?php echo esc_html($post->post_title); ?></strong>
                </td>
                <td>
                    <a href="<?php echo esc_url($post->url); ?>" target="_blank">
                        <?php echo esc_html(wp_trim_words($post->url, 5, '...')); ?>
                    </a>
                </td>
                <td>
                    <span class="ai-translate-status ai-translate-status-<?php echo $post->percentage; ?>">
                        <?php echo esc_html($post->cached_languages . ' van ' . $post->total_languages); ?>
                    </span>
                </td>
                <td>
                    <!-- Action buttons hier -->
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
```

#### Status Kleurcodering CSS
```css
.ai-translate-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-weight: 600;
    font-size: 12px;
}

/* Groen: 100% vertaald */
.ai-translate-status-100 {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

/* Oranje: 50-99% vertaald */
.ai-translate-status[class*="ai-translate-status-"]:not(.ai-translate-status-100):not(.ai-translate-status-0) {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

/* Rood: 0% vertaald */
.ai-translate-status-0 {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
```

#### Action Buttons (Eenvoudig en Duidelijk)
```php
<button 
    class="button button-secondary ai-translate-delete-cache" 
    data-post-id="<?php echo esc_attr($post_id); ?>"
    data-nonce="<?php echo esc_attr(wp_create_nonce('ai_translate_delete_cache')); ?>">
    <span class="dashicons dashicons-trash"></span> Delete Cache
</button>

<button 
    class="button button-primary ai-translate-warm-cache" 
    data-post-id="<?php echo esc_attr($post_id); ?>"
    data-nonce="<?php echo esc_attr(wp_create_nonce('ai_translate_warm_cache')); ?>">
    <span class="dashicons dashicons-update"></span> Warm Cache
</button>
```

### 4. Cache Metadata Management

#### Insert Cache Record
```php
/**
 * Insert cache metadata record
 *
 * @param int $post_id Post ID
 * @param string $language_code Language code (e.g. 'de', 'fr')
 * @param string $cache_file Full path to cache file
 * @param string $cache_hash Hash of the cached content
 * @return bool Success
 */
function ai_translate_insert_cache_meta($post_id, $language_code, $cache_file, $cache_hash) {
    global $wpdb;
    
    $file_size = file_exists($cache_file) ? filesize($cache_file) : 0;
    
    return $wpdb->replace(
        $wpdb->prefix . 'ai_translate_cache_meta',
        array(
            'post_id'       => $post_id,
            'language_code' => $language_code,
            'cache_file'    => $cache_file,
            'cache_hash'    => $cache_hash,
            'created_at'    => current_time('mysql'),
            'file_size'     => $file_size
        ),
        array('%d', '%s', '%s', '%s', '%s', '%d')
    );
}
```

#### Delete Cache Records
```php
/**
 * Delete cache metadata for a post
 *
 * @param int $post_id Post ID
 * @param string|null $language_code Optional language code (null = all languages)
 * @return int Number of records deleted
 */
function ai_translate_delete_cache_meta($post_id, $language_code = null) {
    global $wpdb;
    
    $where = array('post_id' => $post_id);
    $where_format = array('%d');
    
    if ($language_code !== null) {
        $where['language_code'] = $language_code;
        $where_format[] = '%s';
    }
    
    return $wpdb->delete(
        $wpdb->prefix . 'ai_translate_cache_meta',
        $where,
        $where_format
    );
}
```

#### Get Cache Records for Post
```php
/**
 * Get all cache records for a post
 *
 * @param int $post_id Post ID
 * @return array Array of cache records
 */
function ai_translate_get_cache_meta($post_id) {
    global $wpdb;
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ai_translate_cache_meta WHERE post_id = %d",
        $post_id
    ));
}
```

#### Background Sync (Cron Job)
```php
/**
 * Sync cache metadata with filesystem
 * Run via WP Cron every hour
 */
function ai_translate_sync_cache_metadata() {
    global $wpdb;
    
    $cache_dir = WP_CONTENT_DIR . '/uploads/ai-translate/cache/';
    
    if (!is_dir($cache_dir)) {
        return;
    }
    
    // Get all cache files
    $files = glob($cache_dir . '*.cache');
    $file_map = array();
    
    foreach ($files as $file) {
        $basename = basename($file);
        // Parse filename: {lang}_{post_id}_{hash}.cache
        if (preg_match('/^([a-z]{2})_(\d+)_([a-f0-9]+)\.cache$/', $basename, $matches)) {
            $lang = $matches[1];
            $post_id = (int)$matches[2];
            $hash = $matches[3];
            
            $file_map[] = array(
                'post_id' => $post_id,
                'language_code' => $lang,
                'cache_file' => $file,
                'cache_hash' => $hash
            );
        }
    }
    
    // Clear existing records
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ai_translate_cache_meta");
    
    // Insert all records
    foreach ($file_map as $record) {
        ai_translate_insert_cache_meta(
            $record['post_id'],
            $record['language_code'],
            $record['cache_file'],
            $record['cache_hash']
        );
    }
    
    error_log(sprintf('AI Translate: Synced %d cache records', count($file_map)));
}

// Schedule cron job
add_action('ai_translate_sync_cache_metadata', 'ai_translate_sync_cache_metadata');

if (!wp_next_scheduled('ai_translate_sync_cache_metadata')) {
    wp_schedule_event(time(), 'hourly', 'ai_translate_sync_cache_metadata');
}
```

### 5. AJAX Endpoints

#### Delete Cache Endpoint
```php
add_action('wp_ajax_ai_translate_delete_cache', 'ai_translate_handle_delete_cache');

function ai_translate_handle_delete_cache() {
    // 1. Verify nonce
    check_ajax_referer('ai_translate_delete_cache', 'nonce');
    
    // 2. Validate user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    // 3. Get post ID
    $post_id = intval($_POST['post_id']);
    
    // 4. Find and delete cache files
    $deleted_count = ai_translate_delete_post_cache($post_id);
    
    // 5. Return response
    wp_send_json_success(array(
        'message' => sprintf('Cache verwijderd voor %d talen', $deleted_count),
        'deleted' => $deleted_count
    ));
}
```

#### Warm Cache Endpoint
```php
add_action('wp_ajax_ai_translate_warm_cache', 'ai_translate_handle_warm_cache');

function ai_translate_handle_warm_cache() {
    // 1. Verify nonce
    check_ajax_referer('ai_translate_warm_cache', 'nonce');
    
    // 2. Validate user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    // 3. Get post ID and enabled languages
    $post_id = intval($_POST['post_id']);
    $languages = ai_translate_get_enabled_languages();
    
    // 4. Generate translations
    $warmed_count = ai_translate_warm_post_cache($post_id, $languages);
    
    // 5. Return response
    wp_send_json_success(array(
        'message' => sprintf('Cache opgewarmd voor %d talen', $warmed_count),
        'warmed' => $warmed_count
    ));
}
```

### 6. Core Functions

#### Delete Post Cache
```php
/**
 * Delete all cache files for a specific post
 *
 * @param int $post_id The post ID
 * @return int Number of files deleted
 */
function ai_translate_delete_post_cache($post_id) {
    // Get cache records from database
    $records = ai_translate_get_cache_meta($post_id);
    
    $deleted = 0;
    foreach ($records as $record) {
        if (file_exists($record->cache_file) && unlink($record->cache_file)) {
            $deleted++;
        }
    }
    
    // Delete metadata records
    ai_translate_delete_cache_meta($post_id);
    
    // Clear any transients
    delete_transient('ai_translate_cache_table_data');
    
    error_log(sprintf('AI Translate: Deleted %d cache files for post %d', $deleted, $post_id));
    
    return $deleted;
}
```

#### Warm Post Cache
```php
/**
 * Generate cache for a post in all enabled languages
 *
 * @param int $post_id The post ID
 * @param array $languages Array of language codes
 * @return int Number of translations generated
 */
function ai_translate_warm_post_cache($post_id, $languages) {
    global $wpdb;
    
    $warmed = 0;
    $post_url = get_permalink($post_id);
    
    // Get existing cache languages for this post
    $existing = $wpdb->get_col($wpdb->prepare(
        "SELECT language_code FROM {$wpdb->prefix}ai_translate_cache_meta WHERE post_id = %d",
        $post_id
    ));
    
    foreach ($languages as $lang_code) {
        // Skip if cache already exists
        if (in_array($lang_code, $existing, true)) {
            continue;
        }
        
        // Trigger translation via internal request
        $translated_url = home_url("/{$lang_code}" . parse_url($post_url, PHP_URL_PATH));
        $result = ai_translate_generate_translation($translated_url, $post_id, $lang_code);
        
        if ($result) {
            $warmed++;
            // Metadata wordt automatisch toegevoegd door de vertaal functie
        }
    }
    
    // Clear transient cache
    delete_transient('ai_translate_cache_table_data');
    
    return $warmed;
}
```

#### Get Enabled Languages
```php
/**
 * Get all enabled languages (selectable + detectable)
 *
 * @return array Array of language codes
 */
function ai_translate_get_enabled_languages() {
    $selectable = get_option('ai_translate_languages', array());
    $detectable = get_option('ai_translate_detectable_languages', array());
    
    return array_unique(array_merge($selectable, $detectable));
}
```

### 7. JavaScript (admin-cache-table.js)

```javascript
jQuery(document).ready(function($) {
    // Delete Cache Handler
    $('.ai-translate-delete-cache').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Weet je zeker dat je de cache voor deze pagina wilt verwijderen?')) {
            return;
        }
        
        const $button = $(this);
        const postId = $button.data('post-id');
        const nonce = $button.data('nonce');
        
        $button.prop('disabled', true).text('Verwijderen...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ai_translate_delete_cache',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $button.prop('disabled', false).text('Delete Cache');
                }
            },
            error: function() {
                alert('AJAX error');
                $button.prop('disabled', false).text('Delete Cache');
            }
        });
    });
    
    // Warm Cache Handler
    $('.ai-translate-warm-cache').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const postId = $button.data('post-id');
        const nonce = $button.data('nonce');
        
        $button.prop('disabled', true).text('Opwarmen...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ai_translate_warm_cache',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $button.prop('disabled', false).text('Warm Cache');
                }
            },
            error: function() {
                alert('AJAX error');
                $button.prop('disabled', false).text('Warm Cache');
            }
        });
    });
});
```

### 8. Database Tabel Creatie

#### Activation Hook
```php
/**
 * Create cache metadata table on plugin activation
 */
function ai_translate_create_cache_meta_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ai_translate_cache_meta';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        language_code VARCHAR(10) NOT NULL,
        cache_file VARCHAR(255) NOT NULL,
        cache_hash VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        file_size INT UNSIGNED DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY post_lang (post_id, language_code),
        KEY post_id (post_id),
        KEY language_code (language_code),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'ai_translate_create_cache_meta_table');
```

## Performance Overwegingen

### 1. Database Query Performance
**Voordelen van database tabel:**
- Single JOIN query voor alle posts met cache status
- Geen filesystem I/O tijdens page load
- Indexes zorgen voor snelle lookups
- Kan efficiënt gepagineerd worden

**Benchmark (geschat):**
- Realtime filesystem scan: 2-5 seconden voor 100 posts met 5 talen
- Database query: <100ms voor 1000 posts met 10 talen

### 2. Pagination
Voor sites met veel content (>100 posts):
```php
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 50;
$offset = ($paged - 1) * $per_page;

// Add to query: LIMIT $offset, $per_page
```

### 3. Transient Caching (Optioneel)
Hoewel database query snel is, kan extra caching voor zeer grote sites:
```php
$transient_key = 'ai_translate_cache_table_page_' . $paged;
$table_data = get_transient($transient_key);

if (false === $table_data) {
    $table_data = ai_translate_get_posts_with_cache_stats($offset, $per_page);
    set_transient($transient_key, $table_data, 5 * MINUTE_IN_SECONDS);
}
```

**Let op:** Transient moet gewist worden bij cache wijzigingen.

### 4. Async Warm Cache
Voor grote sites kan warm cache lang duren:
- Gebruik WP Cron voor batch processing
- Toon progress bar via JavaScript polling
- Verwerk in batches van 3-5 talen tegelijk

## UI/UX Overwegingen

### Visuele Feedback
1. **Status Indicator:** Simpel getal "3 van 5 talen" met kleurcodering:
   - Groen: 100% vertaald
   - Oranje: Gedeeltelijk vertaald
   - Rood: 0% vertaald
2. **Geen Vlaggetjes:** Te onoverzichtelijk bij veel talen (10+)
3. **Loading States:** Toon spinners tijdens AJAX calls
4. **Success Messages:** WordPress admin notices voor feedback

### Bulk Actions
Toekomstige uitbreiding:
- Checkboxes per row
- "Bulk Delete Cache" optie
- "Bulk Warm Cache" optie
- Filter opties (alleen pages, alleen incomplete vertalingen, etc.)

## Beveiligingsoverwegingen

1. **Nonce Verificatie:** Elke AJAX call moet nonce verificatie hebben
2. **Capability Check:** Alleen gebruikers met `manage_options` capability
3. **Input Sanitization:** Alle POST data moet gesanitized worden
4. **File Path Validation:** Voorkom directory traversal bij cache file operations
5. **Rate Limiting:** Beperk aantal warm cache operaties per minuut

## Risico's & Mitigatie

### Risico 1: Performance Impact
**Probleem:** Realtime filesystem scanning is te traag bij veel pagina's.
**Mitigatie:** 
- ✅ **Gebruik database tabel** voor metadata (instant queries)
- Background sync via cron (elk uur) als fallback
- Optionele transient caching voor zeer grote sites (5 minuten)
- Pagination (50 items per page)

### Risico 2: API Kosten bij Warm Cache
**Probleem:** Warm cache kan veel API calls triggeren.
**Mitigatie:**
- Toon waarschuwing met geschatte kosten
- Implementeer "dry run" optie
- Skip bestaande cache bestanden

### Risico 3: Timeout bij Lange Operaties
**Probleem:** Warm cache voor veel talen kan PHP max_execution_time overschrijden.
**Mitigatie:**
- Gebruik WP Cron voor background processing
- Splits in kleinere batches
- Implementeer resume functionaliteit

### Risico 4: Race Conditions
**Probleem:** Meerdere admins kunnen tegelijk cache manipuleren.
**Mitigatie:**
- Gebruik WordPress transients als locks
- Toon "In progress" status
- Implementeer locking mechanisme

## Implementatie Volgorde

1. **Database tabel creatie** (activation hook)
2. **Metadata functies** (insert, delete, get)
3. **Background sync cron job** (initial population)
4. **Admin tabel UI** (simpel, zonder vlaggetjes)
5. **AJAX endpoints** (delete & warm)
6. **JavaScript handlers** (button clicks)
7. **Integratie met bestaande cache functies** (auto-insert metadata bij nieuwe cache)

## Toekomstige Uitbreidingen

1. **Detailweergave per pagina:**
   - Tooltip of modal met lijst van vertaalde talen
   - Laatst bijgewerkt datum per taal
   - Bestandsgrootte per vertaling

2. **Statistieken Dashboard:**
   - Totaal aantal vertalingen
   - Meest vertaalde pagina's
   - Cache groei over tijd (chart)

3. **Automatisch Warm Cache:**
   - Bij publiceren van nieuwe post (save_post hook)
   - Scheduled cron job voor alle content
   - Prioriteit gebaseerd op pageviews

4. **Bulk Acties:**
   - Checkboxes per rij
   - "Bulk Warm Cache" voor geselecteerde posts
   - "Bulk Delete Cache" voor geselecteerde posts
   - Filter op post type, status percentage

5. **Cache Validatie:**
   - Check of originele post gewijzigd is (post_modified)
   - Auto-refresh bij detectie van wijzigingen
   - Markeer verouderde cache (indicator in tabel)

