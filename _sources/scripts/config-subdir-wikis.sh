#!/bin/bash
#
# Configure Apache for the wikis registered in wikis.yaml.
#
# For each wiki, generates a per-wiki public_assets rewrite rule keyed
# off %{HTTP_HOST}, so multi-host wiki farms route /public_assets/...
# requests to the right wiki's filesystem directory at
# /mediawiki/public_assets/<wiki_id>/.
#
# For wikis that live under a URL subdirectory (e.g. example.com/docs),
# additionally creates the symlink + rewritten .htaccess + img_auth.php
# aliases that the existing subdir routing relies on.
#
# Public assets are served directly by Apache from the bind-mounted
# filesystem; there is no PHP entry point. The matching <Directory>
# permission grant is set up at build time in the Dockerfile.

set -e

WIKIS_YAML="$MW_VOLUME/config/wikis.yaml"

# Walk wikis.yaml and emit one TAB-separated "id<TAB>url" line per wiki.
# Assumes the canonical canasta wikis.yaml format where each wiki entry
# starts with "- id: <id>" followed by "  url: <url>" (and optionally
# more fields). awk tracks the current wiki and flushes when it sees
# the next entry or end-of-file.
parse_wikis() {
    awk '
        /^- id:/ {
            if (id != "" && url != "") { print id "\t" url }
            id = $3
            url = ""
        }
        /^  url:/ { url = $2 }
        END { if (id != "" && url != "") print id "\t" url }
    ' "$WIKIS_YAML"
}

# Track which subdir paths we have already configured (a single subdir
# could appear in wikis.yaml under more than one host in a farm).
declare -A processed_subdirs

while IFS=$'\t' read -r wiki_id wiki_url; do
    [ -z "$wiki_id" ] && continue
    [ -z "$wiki_url" ] && continue

    # Split the url field into host (with optional port) and path.
    if [[ "$wiki_url" == */* ]]; then
        wiki_host="${wiki_url%%/*}"
        wiki_path="${wiki_url#*/}"
    else
        wiki_host="$wiki_url"
        wiki_path=""
    fi

    # Escape regex metachars in the host for use in RewriteCond
    # (only `.` and `\` matter for the typical host[:port] format).
    host_re="$(printf '%s' "$wiki_host" | sed -e 's/\\/\\\\/g' -e 's/\./\\./g')"

    # Per-wiki public_assets rewrite. The HTTP_HOST condition makes this
    # safe for multi-host farms — only the matching host's request paths
    # get routed to that wiki's storage directory.
    if [ -n "$wiki_path" ]; then
        cat >> /etc/apache2/apache2.conf <<APACHE
RewriteCond %{HTTP_HOST} ^${host_re}\$ [NC]
RewriteRule ^/${wiki_path}/public_assets/(.*)\$ /mediawiki/public_assets/${wiki_id}/\$1 [L]
APACHE
    else
        cat >> /etc/apache2/apache2.conf <<APACHE
RewriteCond %{HTTP_HOST} ^${host_re}\$ [NC]
RewriteRule ^/public_assets/(.*)\$ /mediawiki/public_assets/${wiki_id}/\$1 [L]
APACHE
    fi

    # Subdir-wiki additional plumbing (mirrors the previous behavior of
    # this script for the img_auth.php / .htaccess pieces). Skip when
    # the wiki lives at the root of its host, or when we have already
    # configured this subdir for another host.
    if [ -n "$wiki_path" ] && [ -z "${processed_subdirs[$wiki_path]}" ]; then
        processed_subdirs[$wiki_path]=1

        mkdir -p "$WWW_ROOT/$wiki_path"
        ln -sf "$MW_HOME" "$WWW_ROOT/$wiki_path"

        sed -e "s|w/rest.php/|$wiki_path/w/rest.php/|g" \
            -e "s|w/img_auth.php/|$wiki_path/w/img_auth.php/|g" \
            -e "s|^/*\$ %{DOCUMENT_ROOT}/w/index.php|/*\$ %{DOCUMENT_ROOT}/$wiki_path/w/index.php|" \
            -e "s|^\\(.*\\)\$ %{DOCUMENT_ROOT}/w/index.php|\\1\$ %{DOCUMENT_ROOT}/$wiki_path/w/index.php|" \
            "$WWW_ROOT/.htaccess" > "$WWW_ROOT/$wiki_path/.htaccess"

        echo "Alias /$wiki_path/w/images/ /var/www/mediawiki/w/img_auth.php/" >> /etc/apache2/apache2.conf
        echo "Alias /$wiki_path/w/images /var/www/mediawiki/w/img_auth.php" >> /etc/apache2/apache2.conf
    fi
done < <(parse_wikis)
