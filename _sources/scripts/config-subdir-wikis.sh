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

# Paths are env-var driven for testability. In production they fall
# back to the canonical Canasta locations.
WIKIS_YAML="${WIKIS_YAML:-$MW_VOLUME/config/wikis.yaml}"
APACHE_CONF="${APACHE_CONF:-/etc/apache2/apache2.conf}"

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
# could appear in wikis.yaml under more than one host in a farm). Uses
# a delimited string instead of an associative array for bash 3.x
# compatibility (macOS ships bash 3.2, which can't `declare -A`).
processed_subdirs=":"

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
        cat >> "$APACHE_CONF" <<APACHE
RewriteCond %{HTTP_HOST} ^${host_re}\$ [NC]
RewriteRule ^/${wiki_path}/public_assets/(.*)\$ /mediawiki/public_assets/${wiki_id}/\$1 [L]
APACHE
    else
        cat >> "$APACHE_CONF" <<APACHE
RewriteCond %{HTTP_HOST} ^${host_re}\$ [NC]
RewriteRule ^/public_assets/(.*)\$ /mediawiki/public_assets/${wiki_id}/\$1 [L]
APACHE
    fi

    # Subdir-wiki additional plumbing (mirrors the previous behavior of
    # this script for the img_auth.php / .htaccess pieces). Skip when
    # the wiki lives at the root of its host, or when we have already
    # configured this subdir for another host.
    if [ -n "$wiki_path" ] && [[ "$processed_subdirs" != *":$wiki_path:"* ]]; then
        processed_subdirs="${processed_subdirs}${wiki_path}:"

        mkdir -p "$WWW_ROOT/$wiki_path"
        ln -sf "$MW_HOME" "$WWW_ROOT/$wiki_path"

        # Generate the subdirectory .htaccess from the root .htaccess.
        #
        # The rest.php and img_auth.php passthrough rules are NOT
        # prefixed with the wiki path. Apache has already stripped
        # the subdirectory prefix by the time it evaluates rules
        # inside the subdirectory's .htaccess, so the paths match
        # as-is (e.g. w/img_auth.php/, not docs/w/img_auth.php/).
        #
        # Both rest.php and img_auth.php rules use [END] instead of
        # [L] to prevent the rewritten URL from re-entering the
        # ruleset. After the passthrough, REQUEST_URI contains
        # PATH_INFO (e.g. /w/img_auth.php/a/ab/File.png) which
        # doesn't exist as a filesystem path, so the catch-all
        # "not a file" rule would otherwise redirect to index.php.
        #
        # The catch-all index.php rules DO need the prefix because
        # the symlink routes $wiki_path/w/ → the real MW /w/.
        sed -e "s|rest.php/ - \[L\]|rest.php/ - [END]|" \
            -e "s|img_auth.php/ - \[L\]|img_auth.php/ - [END]|" \
            -e "s|^/*\$ %{DOCUMENT_ROOT}/w/index.php|/*\$ %{DOCUMENT_ROOT}/$wiki_path/w/index.php|" \
            -e "s|^\\(.*\\)\$ %{DOCUMENT_ROOT}/w/index.php|\\1\$ %{DOCUMENT_ROOT}/$wiki_path/w/index.php|" \
            "$WWW_ROOT/.htaccess" > "$WWW_ROOT/$wiki_path/.htaccess"

        echo "Alias /$wiki_path/w/images/ /var/www/mediawiki/w/img_auth.php/" >> "$APACHE_CONF"
        echo "Alias /$wiki_path/w/images /var/www/mediawiki/w/img_auth.php" >> "$APACHE_CONF"
    fi
done < <(parse_wikis)
