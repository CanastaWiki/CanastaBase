#!/bin/bash

set -x

. /functions.sh

get_docker_gateway () {
  getent hosts "gateway.docker.internal" | awk '{ print $1 }'
}

# Try to fetch gateway IP from extra host
DOCKER_GATEWAY=$(get_docker_gateway)

# Fall back to default 172.x network if unable to fetch gateway
if [ -z "$DOCKER_GATEWAY" ]; then
  DOCKER_GATEWAY="172.17.0.1"
fi

wiki_ids=$(get_wiki_ids)
if [ -n "$wiki_ids" ]; then
    WG_SITE_SERVERS=""
    while IFS= read -r wiki_id; do
        if [ -n "$wiki_id" ]; then
            WG_SITE_SERVERS+="$(php /getMediawikiSettings.php --variable=wgServer --format=string --wiki="$wiki_id")"$'\n'
        fi
    done <<< "$wiki_ids"
else
    WG_SITE_SERVERS=$(get_mediawiki_variable wgServer)
fi

# Map hosts for VisualEditor
cp /etc/hosts ~/hosts.new
sed -i '/# MW_SITE_HOST/d' ~/hosts.new
MAPPED_HOSTS=" "
while IFS= read -r WG_SITE_SERVER; do
    [ -n "$WG_SITE_SERVER" ] || continue
    MW_SITE_HOST=$(echo "$WG_SITE_SERVER" | sed -e 's|^[^/]*//||' -e 's|[:/].*$||')
    if [[ $MAPPED_HOSTS == *" $MW_SITE_HOST "* ]]; then
        continue
    fi
    MAPPED_HOSTS+="$MW_SITE_HOST "
    if ! isTrue "$MW_MAP_DOMAIN_TO_DOCKER_GATEWAY"; then
        echo "MW_MAP_DOMAIN_TO_DOCKER_GATEWAY is not true"
    elif [[ $MW_SITE_HOST =~ ^[0-9]+.[0-9]+.[0-9]+.[0-9]+$ ]]; then
        echo "MW_SITE_HOST is IP address '$MW_SITE_HOST'"
    else
        echo "Adding MW_SITE_HOST '$DOCKER_GATEWAY $MW_SITE_HOST' to /etc/hosts"
        echo "$DOCKER_GATEWAY $MW_SITE_HOST # MW_SITE_HOST" >> ~/hosts.new
    fi
done <<< "$WG_SITE_SERVERS"
cp -f ~/hosts.new /etc/hosts
