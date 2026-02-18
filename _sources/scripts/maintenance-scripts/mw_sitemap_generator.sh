#!/bin/bash

. /functions.sh

SCRIPT=$MW_HOME/maintenance/generateSitemap.php
logfileName=mwsitemapgen_log

# Verify the delay is >= 1, otherwise fall back to 1
if [ "$MW_SITEMAP_PAUSE_DAYS" -lt "1" ]; then
    MW_SITEMAP_PAUSE_DAYS=1
fi
# Convert to seconds (suffixed sleep command has issues on OSX)
SLEEP_DAYS=$((MW_SITEMAP_PAUSE_DAYS * 60 * 60 * 24))

SCRIPT_PATH=$(get_mediawiki_variable wgScriptPath)

# Get the URL scheme from MW_SITE_SERVER (e.g. "https://")
URL_SCHEME=$(echo "$MW_SITE_SERVER" | grep -oP '^https?://')
if [ -z "$URL_SCHEME" ]; then
    URL_SCHEME="https://"
fi

# Generate sitemap for a single wiki
generate_sitemap_for_wiki() {
    local wiki_id="$1"
    local server="$2"
    local log="$3"
    local fspath="$MW_HOME/public_assets/$wiki_id/sitemap"

    mkdir -p "$fspath"
    chown "$WWW_USER:$WWW_GROUP" "$fspath"

    echo "Generating sitemap for wiki: $wiki_id (server: $server)" >> "$log"
    php "$SCRIPT" \
      --wiki="$wiki_id" \
      --fspath="$fspath" \
      --urlpath="$SCRIPT_PATH/public_assets/sitemap" \
      --compress yes \
      --server="$server" \
      --skip-redirects \
      --identifier="$wiki_id" \
      >> "$log" 2>&1
}

echo "Starting sitemap generator (in 30 seconds)..."
# Wait after the server starts up to give other processes time to get started
sleep 30
echo "Sitemap generator started."
while true; do
    logFilePrev="$logfileNow"
    logfileNow="$MW_LOG/$logfileName"_$(date +%Y%m%d)
    if [ -n "$logFilePrev" ] && [ "$logFilePrev" != "$logfileNow" ]; then
        /rotatelogs-compress.sh "$logfileNow" "$logFilePrev" &
    fi

    date >> "$logfileNow"

    # Get wiki IDs and URLs from wikis.yaml
    WIKIS_YAML="$MW_VOLUME/config/wikis.yaml"
    if [ -f "$WIKIS_YAML" ]; then
        # Wiki farm: regenerate sitemaps for wikis that already have sitemap files
        wiki_data=$(php -r "
            \$config = yaml_parse_file('$WIKIS_YAML');
            if (\$config && isset(\$config['wikis'])) {
                foreach (\$config['wikis'] as \$wiki) {
                    \$id = \$wiki['id'] ?? '';
                    \$url = \$wiki['url'] ?? '';
                    \$dir = '$MW_HOME/public_assets/' . \$id . '/sitemap';
                    if (\$id !== '' && is_dir(\$dir) && count(glob(\$dir . '/*')) > 0) {
                        echo \$id . \"\t\" . \$url . \"\n\";
                    }
                }
            }
        ")
        if [ -z "$wiki_data" ]; then
            echo "No wikis have sitemaps, skipping." >> "$logfileNow"
        fi
        while IFS=$'\t' read -r wiki_id wiki_url; do
            if [ -z "$wiki_id" ]; then
                continue
            fi
            # Build server URL from wiki's url field and the configured scheme
            if [ -n "$wiki_url" ]; then
                server="${URL_SCHEME}${wiki_url}"
            else
                server="$MW_SITE_SERVER"
            fi
            generate_sitemap_for_wiki "$wiki_id" "$server" "$logfileNow"
        done <<< "$wiki_data"
    else
        # Single wiki (legacy): generate one sitemap
        SITE_SERVER=$(get_mediawiki_variable wgServer)
        if [[ $SITE_SERVER == "//"* ]]; then
            SITE_SERVER="https:$SITE_SERVER"
        fi
        echo "Generating sitemap..." >> "$logfileNow"
        php "$SCRIPT" \
          --fspath="$MW_HOME/sitemap" \
          --urlpath="$SCRIPT_PATH/sitemap" \
          --compress yes \
          --server="$SITE_SERVER" \
          --skip-redirects \
          --identifier="${MW_SITEMAP_IDENTIFIER:-mediawiki}" \
          >> "$logfileNow" 2>&1
    fi

    echo "mwsitemapgen waits for $SLEEP_DAYS seconds..." >> "$logfileNow"
    sleep "$SLEEP_DAYS"
done
