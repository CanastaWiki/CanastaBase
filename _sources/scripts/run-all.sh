#!/bin/bash

set -x

. /functions.sh

# Symlink all extensions and skins (both bundled and user)
/create-symlinks.sh

# Unified composer autoloader: check if composer dependencies need updating.
# This detects changes to composer.local.json (user edits) and new
# user-extensions/user-skins with composer.json files.
if [ -f "$MW_VOLUME/config/composer.local.json" ]; then
  cp "$MW_VOLUME/config/composer.local.json" "$MW_HOME/composer.local.json"
fi
if [ -f "$MW_HOME/composer.local.json" ]; then
  CURRENT_HASH=$(php -r '
    $files = ["'"$MW_HOME"'/composer.local.json"];
    foreach (glob("'"$MW_HOME"'/extensions/*/composer.json") as $f) $files[] = $f;
    foreach (glob("'"$MW_HOME"'/skins/*/composer.json") as $f) $files[] = $f;
    sort($files);
    $h = "";
    foreach ($files as $f) $h .= md5_file($f);
    echo md5($h);
  ')
  SAVED_HASH=""
  if [ -f "$MW_HOME/.composer-deps-hash" ]; then
    SAVED_HASH=$(cat "$MW_HOME/.composer-deps-hash" | tr -d '[:space:]')
  fi
  if [ "$CURRENT_HASH" != "$SAVED_HASH" ]; then
    echo "Composer dependencies changed, running composer update..."
    composer update --working-dir="$MW_HOME" --no-dev --no-interaction
    echo "$CURRENT_HASH" > "$MW_HOME/.composer-deps-hash"
  else
    echo "Composer dependencies unchanged, skipping update."
  fi
fi

# Soft sync contents from $MW_ORIGIN_FILES directory to $MW_VOLUME
# The goal of this operation is to copy over all the files generated
# by the image to bind-mount points on host which are bind to
# $MW_VOLUME (./extensions, ./skins, ./config, ./images),
# note that this command will also set all the necessary permissions
echo "Syncing files..."
rsync -ah --inplace --ignore-existing \
  -og --chown="$WWW_GROUP:$WWW_USER" --chmod=Fg=rw,Dg=rwx \
  "$MW_ORIGIN_FILES"/ "$MW_VOLUME"/

# Create needed directories
mkdir -p "$MW_VOLUME"/l10n_cache

/update-docker-gateway.sh

# Permissions
# Note: this part if checking for root directories permissions
# assuming that if the root directory has correct permissions set
# it's in result of previous success run of this code or this code
# was executed by another container (in case mount points are shared)
# hence it does not perform any recursive checks and may lead to files
# or directories down the tree having incorrect permissions left untouched

# Write log files to $MW_VOLUME/log directory if target folders are not mounted
echo "Checking permissions of Apache log dir $APACHE_LOG_DIR..."
if ! mountpoint -q -- "$APACHE_LOG_DIR/"; then
    mkdir -p "$MW_VOLUME/log/httpd"
    rsync -avh --ignore-existing "$APACHE_LOG_DIR/" "$MW_VOLUME/log/httpd/"
    mv "$APACHE_LOG_DIR" "${APACHE_LOG_DIR}_old"
    ln -s "$MW_VOLUME/log/httpd" "$APACHE_LOG_DIR"
else
    chgrp -R "$WWW_GROUP" "$APACHE_LOG_DIR"
    chmod -R g=rwX "$APACHE_LOG_DIR"
fi

echo "Checking permissions of PHP-FPM log dir $PHP_LOG_DIR..."
if ! mountpoint -q -- "$PHP_LOG_DIR/"; then
    mkdir -p "$MW_VOLUME/log/php-fpm"
    rsync -avh --ignore-existing "$PHP_LOG_DIR/" "$MW_VOLUME/log/php-fpm/"
    mv "$PHP_LOG_DIR" "${PHP_LOG_DIR}_old"
    ln -s "$MW_VOLUME/log/php-fpm" "$PHP_LOG_DIR"
else
    chgrp -R "$WWW_GROUP" "$PHP_LOG_DIR"
    chmod -R g=rwX "$PHP_LOG_DIR"
fi

config_subdir_wikis() {
    echo "Configuring subdirectory wikis..."
    /config-subdir-wikis.sh
    echo "Configured subdirectory wikis..."
}

create_storage_dirs() {
    echo "Creating cache, images, and public_assets dirs..."
    /create-storage-dirs.sh
    echo "Created cache, images, and public_assets dirs..."
}

check_mount_points () {
  # Check for $MW_HOME/user-extensions presence and bow out if it's not in place
  if [ ! -d "$MW_HOME/user-extensions" ]; then
    echo "WARNING! As of Canasta 1.2.0, $MW_HOME/user-extensions is the correct mount point! Please update your Docker Compose stack to 1.2.0, which will re-mount to $MW_HOME/user-extensions."
    exit 1
  fi

  # Check for $MW_HOME/user-skins presence and bow out if it's not in place
  if [ ! -d "$MW_HOME/user-skins" ]; then
    echo "WARNING! As of Canasta 1.2.0, $MW_HOME/user-skins is the correct mount point! Please update your Docker Compose stack to 1.2.0, which will re-mount to $MW_HOME/user-skins."
    exit 1
  fi
}

# Check for `user-` prefixed mounts and bow out if not found
check_mount_points

sleep 1
cd "$MW_HOME" || exit

# Check and update permissions of wiki images in background.
# It can take a long time and should not block Apache from starting.
/update-images-permissions.sh &

########## Run maintenance scripts ##########
# Create storage directories early (before LocalSettings check) since wikis.yaml
# exists before install.php runs, and we need these directories to exist for uploads
if [ -e "$MW_VOLUME/config/wikis.yaml" ]; then
  create_storage_dirs
fi

echo "Checking for MediaWiki configuration..."
if [ -n "$MW_SECRET_KEY" ] || [ -e "$MW_VOLUME/config/LocalSettings.php" ] || [ -e "$MW_VOLUME/config/CommonSettings.php" ]; then
  # Run auto-update (LocalSettings.php/CommonSettings.php checks are for backward compatibility)
  . /run-maintenance-scripts.sh
  run_autoupdate
fi

# Configure Apache rewrites for path-based wikis (e.g., localhost/wiki2)
if [ -e "$MW_VOLUME/config/wikis.yaml" ]; then
  config_subdir_wikis
fi

echo "Starting services..."

# Run maintenance scripts in background.
touch "$WWW_ROOT/.maintenance"
/run-maintenance-scripts.sh &

echo "Checking permissions of Mediawiki log dir $MW_LOG..."
if ! mountpoint -q -- "$MW_LOG"; then
    mkdir -p "$MW_VOLUME/log/mediawiki"
    rsync -avh --ignore-existing "$MW_LOG/" "$MW_VOLUME/log/mediawiki/"
    mv "$MW_LOG" "${MW_LOG}_old"
    ln -s "$MW_VOLUME/log/mediawiki" "$MW_LOG"
    chmod -R o=rwX "$MW_VOLUME/log/mediawiki"
else
    chgrp -R "$WWW_GROUP" "$MW_LOG"
    chmod -R go=rwX "$MW_LOG"
fi

echo "Checking permissions of MediaWiki volume dir $MW_VOLUME except $MW_VOLUME/images..."
make_dir_writable "$MW_VOLUME" -not '(' -path "$MW_VOLUME/images" -prune ')' &

# Running php-fpm
/run-php-fpm.sh &

echo "root: $LOCAL_SMTP_USERNAME" >> /etc/aliases
echo "$LOCAL_SMTP_MAILNAME" >> /etc/mailname
echo "[$LOCAL_SMTP_DOMAIN]:$LOCAL_SMTP_PORT $LOCAL_SMTP_USERNAME:$LOCAL_SMTP_PASSWORD" >> /etc/postfix/sasl_passwd
chmod 0600 /etc/postfix/sasl_passwd
postmap /etc/postfix/sasl_passwd
service postfix start

############### Run Apache ###############
# Make sure we're not confused by old, incompletely-shutdown httpd
# context after restarting the container.  httpd won't start correctly
# if it thinks it is already running.
rm -rf /run/apache2/* /tmp/apache2*

exec /usr/sbin/apachectl -DFOREGROUND
