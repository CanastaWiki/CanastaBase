# read variables from LocalSettings.php
get_mediawiki_variable() {
    php /getMediawikiSettings.php --variable="$1" --format="${2:-string}"
}

get_mediawiki_db_var() {
    case $1 in
        "wgDBtype")     field="type" ;;
        "wgDBserver")   field="host" ;;
        "wgDBname")     field="dbname" ;;
        "wgDBuser")     field="user" ;;
        "wgDBpassword") field="password" ;;
        *)
            echo "Unexpected variable name passed to the get_mediawiki_db_var() function: $1"
            return
    esac
    # When wgDBservers is configured (load balancing), the scalar wgDB* globals
    # may be unset, so pull the field from the first server entry. Otherwise fall
    # back to the scalar variable.
    servers=$(php /getMediawikiSettings.php --variable=wgDBservers --format=json)
    value=$(php -r '$s = json_decode($argv[1], true); echo (is_array($s) && isset($s[0][$argv[2]])) ? $s[0][$argv[2]] : "";' "$servers" "$field")
    if [ -z "$value" ]; then
        value=$(get_mediawiki_variable "$1")
    fi
    echo "$value"
}

isTrue() {
    case $1 in
        "True" | "TRUE" | "true" | 1)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

isFalse() {
    case $1 in
        "True" | "TRUE" | "true" | 1)
            return 1
            ;;
        *)
            return 0
            ;;
    esac
}

make_dir_writable() {
    find "$@" '(' -type f -o -type d ')' \
       -not '(' '(' -user "$WWW_USER" -perm -u=w ')' -o \
           '(' -group "$WWW_GROUP" -perm -g=w ')' -o \
           '(' -perm -o=w ')' \
         ')' \
         -exec chgrp "$WWW_GROUP" {} \; -exec chmod g=rwX {} \;
}

get_wiki_ids() {
    # Get all wiki IDs from wikis.yaml
    # Returns one wiki ID per line, or empty if wikis.yaml doesn't exist
    local wikis_yaml="$MW_VOLUME/config/wikis.yaml"
    if [ -f "$wikis_yaml" ]; then
        php -r "
            \$config = yaml_parse_file('$wikis_yaml');
            if (\$config && isset(\$config['wikis'])) {
                foreach (\$config['wikis'] as \$wiki) {
                    if (isset(\$wiki['id'])) {
                        echo \$wiki['id'] . \"\\n\";
                    }
                }
            }
        "
    fi
}
