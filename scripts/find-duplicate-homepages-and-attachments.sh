#!/bin/bash

# Script to find duplicate homepages and attachments in AI Translate cache
# Usage: ./find-duplicate-homepages-and-attachments.sh

set -euo pipefail

# Configuration - Change this to match your website
CACHE_DIR="${CACHE_DIR:-/var/www/vioolles.net/wp-content/uploads/ai-translate/cache}"
DB_NAME="${DB_NAME:-vioolles_db}"
DB_USER="${DB_USER:-vioolles_admin}"
DB_PASS="${DB_PASS:-eequ1Eezongeiyi}"
DB_HOST="${DB_HOST:-localhost}"
TABLE_PREFIX="${TABLE_PREFIX:-wnjin_}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Check if cache directory exists
if [ ! -d "$CACHE_DIR" ]; then
    echo -e "${RED}Error: Cache directory not found: $CACHE_DIR${NC}"
    exit 1
fi

echo -e "${BLUE}=== AI Translate Cache Cleanup Tool ===${NC}"
echo -e "Cache directory: ${GREEN}$CACHE_DIR${NC}"
echo -e "Database: ${GREEN}$DB_NAME${NC}"
echo ""

# Function to escape MySQL strings
mysql_escape() {
    echo "$1" | sed "s/'/''/g"
}

# Function to get post info from database
get_post_info() {
    local post_id="$1"
    if [ "$post_id" -eq 0 ]; then
        echo "Homepage|homepage"
        return
    fi
    
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e \
        "SELECT CONCAT(COALESCE(post_title, ''), '|', COALESCE(post_type, '')) FROM ${TABLE_PREFIX}posts WHERE ID = $post_id LIMIT 1" 2>/dev/null || echo "Unknown|unknown"
}

# Function to generate cache hash
generate_cache_hash() {
    local site_hash="$1"
    local lang="$2"
    local route_id="$3"
    local cache_key="ait:v4:${site_hash}:${lang}:${route_id}"
    echo -n "$cache_key" | md5sum | cut -d' ' -f1
}

# Check database connection
if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
    echo -e "${RED}Error: Cannot connect to database${NC}"
    exit 1
fi

# Get site hash
SITE_HASH=$(echo -n "${DB_NAME}|${TABLE_PREFIX}" | md5sum | cut -c1-8)
echo -e "${BLUE}Site hash: ${GREEN}$SITE_HASH${NC}"
echo ""

# Arrays to store results
declare -a DUPLICATE_HOMEPAGES=()
declare -a ATTACHMENTS=()
declare -A HOMEPAGE_FILES_BY_LANG=()

# Scan filesystem for cache files
echo -e "${BLUE}Scanning filesystem for cache files...${NC}"

# Find all language directories
for LANG_DIR in "$CACHE_DIR"/*; do
    if [ ! -d "$LANG_DIR" ]; then
        continue
    fi
    
    LANG=$(basename "$LANG_DIR")
    
    # Skip if not a 2-letter language code
    if ! [[ "$LANG" =~ ^[a-z]{2}$ ]]; then
        continue
    fi
    
    echo -e "  Scanning language: ${GREEN}$LANG${NC}"
    
    # Find pages directory
    PAGES_DIR="$LANG_DIR/pages"
    if [ ! -d "$PAGES_DIR" ]; then
        PAGES_DIR="$LANG_DIR"
    fi
    
    if [ ! -d "$PAGES_DIR" ]; then
        continue
    fi
    
    # Find all .html files
    while IFS= read -r -d '' CACHE_FILE; do
        FILENAME=$(basename "$CACHE_FILE" .html)
        
        # Generate homepage route_id variants and check if file matches any
        # Test common homepage patterns:
        # 1. path:md5(/)
        # 2. path:md5(/en) or similar
        # 3. post:0
        
        HOMEPAGE_ROUTE_1="path:$(echo -n '/' | md5sum | cut -d' ' -f1)"
        HASH_1=$(generate_cache_hash "$SITE_HASH" "$LANG" "$HOMEPAGE_ROUTE_1")
        
        HOMEPAGE_ROUTE_2="path:$(echo -n "/$LANG" | md5sum | cut -d' ' -f1)"
        HASH_2=$(generate_cache_hash "$SITE_HASH" "$LANG" "$HOMEPAGE_ROUTE_2")
        
        HOMEPAGE_ROUTE_3="post:0"
        HASH_3=$(generate_cache_hash "$SITE_HASH" "$LANG" "$HOMEPAGE_ROUTE_3")
        
        if [ "$FILENAME" = "$HASH_1" ] || [ "$FILENAME" = "$HASH_2" ] || [ "$FILENAME" = "$HASH_3" ]; then
            # This is a homepage cache file
            if [ -z "${HOMEPAGE_FILES_BY_LANG[$LANG]:-}" ]; then
                HOMEPAGE_FILES_BY_LANG[$LANG]="$CACHE_FILE"
            else
                HOMEPAGE_FILES_BY_LANG[$LANG]+=$'\n'"$CACHE_FILE"
            fi
        fi
        
        # Check if it's an attachment by checking database
        DB_INFO=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e \
            "SELECT CONCAT(m.post_id, '|', p.post_type, '|', COALESCE(p.post_title, '')) 
             FROM ${TABLE_PREFIX}ai_translate_cache_meta m 
             LEFT JOIN ${TABLE_PREFIX}posts p ON m.post_id = p.ID 
             WHERE m.cache_file = '$(mysql_escape "$CACHE_FILE")' 
             LIMIT 1" 2>/dev/null || echo "")
        
        if [ -n "$DB_INFO" ]; then
            IFS='|' read -r POST_ID POST_TYPE POST_TITLE <<< "$DB_INFO"
            if [ "$POST_TYPE" = "attachment" ]; then
                ATTACHMENTS+=("$CACHE_FILE|$LANG|$POST_ID|$POST_TITLE|$POST_TYPE")
            fi
        fi
    done < <(find "$PAGES_DIR" -name "*.html" -type f -print0 2>/dev/null)
done

# Find duplicate homepages
for LANG in "${!HOMEPAGE_FILES_BY_LANG[@]}"; do
    FILES="${HOMEPAGE_FILES_BY_LANG[$LANG]}"
    FILE_COUNT=$(echo "$FILES" | wc -l)
    
    if [ "$FILE_COUNT" -gt 1 ]; then
        # Keep first one, mark others as duplicates
        FIRST=true
        while IFS= read -r FILE; do
            if [ -n "$FILE" ]; then
                if [ "$FIRST" = true ]; then
                    FIRST=false
                else
                    DUPLICATE_HOMEPAGES+=("$FILE|$LANG")
                fi
            fi
        done <<< "$FILES"
    fi
done

echo ""
echo -e "${BLUE}=== Scan Results ===${NC}"
echo ""

# Show duplicate homepages
DUPLICATE_COUNT=0
if [ "${DUPLICATE_HOMEPAGES[*]+x}" ]; then
    DUPLICATE_COUNT=${#DUPLICATE_HOMEPAGES[@]}
fi

if [ "$DUPLICATE_COUNT" -eq 0 ]; then
    echo -e "${GREEN}✓ No duplicate homepages found${NC}"
else
    echo -e "${YELLOW}Found $DUPLICATE_COUNT duplicate homepage cache files:${NC}"
    echo ""
    COUNT=1
    for ENTRY in "${DUPLICATE_HOMEPAGES[@]}"; do
        IFS='|' read -r FILE LANG <<< "$ENTRY"
        SIZE=$(du -h "$FILE" 2>/dev/null | cut -f1 || echo "unknown")
        MTIME=$(stat -c "%y" "$FILE" 2>/dev/null | cut -d' ' -f1,2 | cut -d'.' -f1 || echo "unknown")
        echo -e "  ${COUNT}. ${YELLOW}Duplicate Homepage${NC}"
        echo -e "     File: ${RED}$(basename "$FILE")${NC}"
        echo -e "     Path: $FILE"
        echo -e "     Language: $LANG"
        echo -e "     Size: $SIZE | Modified: $MTIME"
        echo ""
        COUNT=$((COUNT + 1))
    done
fi

# Show attachments
ATTACHMENT_COUNT=0
if [ "${ATTACHMENTS[*]+x}" ]; then
    ATTACHMENT_COUNT=${#ATTACHMENTS[@]}
fi

if [ "$ATTACHMENT_COUNT" -eq 0 ]; then
    echo -e "${GREEN}✓ No attachment cache files found${NC}"
else
    echo -e "${YELLOW}Found $ATTACHMENT_COUNT attachment cache files:${NC}"
    echo ""
    COUNT=1
    for ENTRY in "${ATTACHMENTS[@]}"; do
        IFS='|' read -r FILE LANG POST_ID TITLE POST_TYPE <<< "$ENTRY"
        SIZE=$(du -h "$FILE" 2>/dev/null | cut -f1 || echo "unknown")
        MTIME=$(stat -c "%y" "$FILE" 2>/dev/null | cut -d' ' -f1,2 | cut -d'.' -f1 || echo "unknown")
        echo -e "  ${COUNT}. ${YELLOW}Attachment${NC}"
        echo -e "     File: ${RED}$(basename "$FILE")${NC}"
        echo -e "     Path: $FILE"
        echo -e "     Language: $LANG | Post ID: $POST_ID"
        echo -e "     Type: $POST_TYPE | Title: ${TITLE:-Unknown}"
        echo -e "     Size: $SIZE | Modified: $MTIME"
        echo ""
        COUNT=$((COUNT + 1))
    done
fi

# Summary
TOTAL_PROBLEMS=$((DUPLICATE_COUNT + ATTACHMENT_COUNT))
echo -e "${BLUE}=== Summary ===${NC}"
echo -e "Duplicate homepages: ${YELLOW}$DUPLICATE_COUNT${NC}"
echo -e "Attachments: ${YELLOW}$ATTACHMENT_COUNT${NC}"
echo -e "Total problems: ${RED}$TOTAL_PROBLEMS${NC}"
echo ""

# Ask for deletion
if [ $TOTAL_PROBLEMS -gt 0 ]; then
    echo -e "${RED}WARNING: This will delete $TOTAL_PROBLEMS cache files!${NC}"
    echo -n "Do you want to delete these files? (yes/no): "
    read -r CONFIRM
    
    if [ "$CONFIRM" = "yes" ]; then
        DELETED=0
        FAILED=0
        
        # Delete duplicate homepages
        for ENTRY in "${DUPLICATE_HOMEPAGES[@]+"${DUPLICATE_HOMEPAGES[@]}"}"; do
            IFS='|' read -r FILE LANG <<< "$ENTRY"
            if rm -f "$FILE" 2>/dev/null; then
                echo -e "${GREEN}Deleted: $(basename "$FILE")${NC}"
                DELETED=$((DELETED + 1))
            else
                echo -e "${RED}Failed to delete: $FILE${NC}"
                FAILED=$((FAILED + 1))
            fi
        done
        
        # Delete attachments
        for ENTRY in "${ATTACHMENTS[@]+"${ATTACHMENTS[@]}"}"; do
            IFS='|' read -r FILE LANG POST_ID TITLE POST_TYPE <<< "$ENTRY"
            if rm -f "$FILE" 2>/dev/null; then
                echo -e "${GREEN}Deleted: $(basename "$FILE")${NC}"
                DELETED=$((DELETED + 1))
            else
                echo -e "${RED}Failed to delete: $FILE${NC}"
                FAILED=$((FAILED + 1))
            fi
        done
        
        echo ""
        echo -e "${GREEN}Deleted: $DELETED files${NC}"
        if [ $FAILED -gt 0 ]; then
            echo -e "${RED}Failed: $FAILED files${NC}"
        fi
        
        # Also delete from database metadata table
        if [ $DELETED -gt 0 ]; then
            echo ""
            echo -n "Do you want to also remove these entries from the database metadata table? (yes/no): "
            read -r CONFIRM_DB
            
            if [ "$CONFIRM_DB" = "yes" ]; then
                DB_DELETED=0
                
                # Delete duplicate homepages from DB
                for ENTRY in "${DUPLICATE_HOMEPAGES[@]+"${DUPLICATE_HOMEPAGES[@]}"}"; do
                    IFS='|' read -r FILE LANG <<< "$ENTRY"
                    ESCAPED_FILE=$(mysql_escape "$FILE")
                    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
                        "DELETE FROM ${TABLE_PREFIX}ai_translate_cache_meta WHERE cache_file = '$ESCAPED_FILE'" 2>/dev/null; then
                        DB_DELETED=$((DB_DELETED + 1))
                    fi
                done
                
                # Delete attachments from DB
                for ENTRY in "${ATTACHMENTS[@]+"${ATTACHMENTS[@]}"}"; do
                    IFS='|' read -r FILE LANG POST_ID TITLE POST_TYPE <<< "$ENTRY"
                    ESCAPED_FILE=$(mysql_escape "$FILE")
                    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
                        "DELETE FROM ${TABLE_PREFIX}ai_translate_cache_meta WHERE cache_file = '$ESCAPED_FILE'" 2>/dev/null; then
                        DB_DELETED=$((DB_DELETED + 1))
                    fi
                done
                
                echo -e "${GREEN}Removed $DB_DELETED entries from database${NC}"
            fi
        fi
    else
        echo -e "${BLUE}Deletion cancelled${NC}"
    fi
else
    echo -e "${GREEN}No problems found. Cache is clean!${NC}"
fi
