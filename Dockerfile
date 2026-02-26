FROM debian:12.8 AS base

LABEL maintainers=""
LABEL org.opencontainers.image.source=https://github.com/CanastaWiki/CanastaBase

ENV MW_VERSION=REL1_43 \
	MW_CORE_VERSION=1.43.6 \
	WWW_ROOT=/var/www/mediawiki \
	MW_HOME=/var/www/mediawiki/w \
	MW_LOG=/var/log/mediawiki \
	MW_ORIGIN_FILES=/mw_origin_files \
	MW_VOLUME=/mediawiki \
	WWW_USER=www-data \
    WWW_GROUP=www-data \
    APACHE_LOG_DIR=/var/log/apache2

LABEL wiki.canasta.mediawiki.version="$MW_CORE_VERSION" \
      wiki.canasta.mediawiki.branch="$MW_VERSION"

# System setup
# Pinning system package versions is impractical on Debian
# hadolint ignore=DL3008
RUN set x; \
	apt-get clean \
	&& apt-get update \
	&& apt-get install -y --no-install-recommends aptitude \
	&& aptitude -y upgrade \
	&& aptitude install -y --without-recommends \
	git \
	inotify-tools \
	apache2 \
	software-properties-common \
	gpg \
	apt-transport-https \
	ca-certificates \
	wget \
	lsb-release \
	imagemagick  \
	librsvg2-bin \
	python3-pygments \
	patch \
	vim \
	mc \
	ffmpeg \
	curl \
	iputils-ping \
	unzip \
	gnupg \
	default-mysql-client \
	rsync \
	lynx \
	poppler-utils \
	php \
	php-mysql \
	php-cli \
	php-gd \
	php-mbstring \
	php-xml \
	php-intl \
	php-opcache \
	php-apcu \
	php-redis \
	php-curl \
	php-zip \
	php8.2-fpm \
	php-yaml \
	php-ldap \
	php-bcmath \
	php-luasandbox \
	libapache2-mod-fcgid \
	&& aptitude clean \
	&& rm -rf /var/lib/apt/lists/*

# Post install configuration
RUN set -x; \
	# Remove default config
	rm /etc/apache2/sites-enabled/000-default.conf \
	&& rm /etc/apache2/sites-available/000-default.conf \
	&& rm -rf /var/www/html \
	# Enable rewrite module
    && a2enmod rewrite \
	# enabling mpm_event and php-fpm
	&& a2dismod mpm_prefork \
	&& a2enconf php8.2-fpm \
	&& a2enmod mpm_event \
	&& a2enmod proxy_fcgi \
    # Create directories
    && mkdir -p "$MW_HOME" \
	&& mkdir -p "$MW_LOG" \
    && mkdir -p "$MW_ORIGIN_FILES" \
    && mkdir -p "$MW_VOLUME"

# Composer
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
RUN set -x; \
	curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer self-update 2.1.3

# Pinning system package versions is impractical on Debian
# hadolint ignore=DL3008
RUN set -x; \
	# Preconfigure Postfix to avoid the interactive prompt
	echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections \
    && echo "postfix postfix/mailname string $LOCAL_SMTP_MAILNAME" | debconf-set-selections \
	&& apt-get update \
	&& apt-get install -y --no-install-recommends mailutils postfix \
	&& rm -rf /var/lib/apt/lists/*

COPY main.cf /etc/postfix/main.cf

FROM base AS source

# MediaWiki core
# cd is used within a multi-command && chain
# hadolint ignore=DL3003
RUN set -x; \
	git clone --depth 1 -b "$MW_CORE_VERSION" https://github.com/wikimedia/mediawiki "$MW_HOME" \
	&& cd "$MW_HOME" \
	&& git submodule update --init --recursive

# Patch composer
RUN set -x; \
    sed -i 's="monolog/monolog": "2.2.0",="monolog/monolog": "^2.2",=g' "$MW_HOME/composer.json"

# Other patches

# Generate gitinfo.json for core, extensions, and skins so that
# Special:Version can display git commit hashes after .git is removed
# cd is used within a loop that returns to $MW_HOME
# hadolint ignore=DL3003,SC2164
RUN set -x; \
    cd "$MW_HOME" || exit \
    && for dir in . extensions/*/ skins/*/; do \
        if [ -d "$dir/.git" ] || [ -f "$dir/.git" ]; then \
            cd "$MW_HOME/$dir" \
            && hash=$(git rev-parse HEAD) \
            && date=$(git log -1 --format=%ct HEAD) \
            && branch=$(git rev-parse --abbrev-ref HEAD) \
            && remote=$(git config --get remote.origin.url || echo "") \
            && printf '{"head":"%s","headSHA1":"%s","headCommitDate":"%s","branch":"%s","remoteURL":"%s"}\n' \
                "$hash" "$hash" "$date" "$branch" "$remote" \
                > gitinfo.json \
            && cd "$MW_HOME"; \
        fi; \
    done

# Cleanup all .git leftovers
# cd is used within a multi-command && chain
# hadolint ignore=DL3003
RUN set -x; \
    cd "$MW_HOME" \
    && find . \( -name ".git" -o -name ".gitignore" -o -name ".gitmodules" -o -name ".gitattributes" \) -exec rm -rf -- {} +

# Move files around
RUN set -x; \
	# Move files to $MW_ORIGIN_FILES directory
    mv "$MW_HOME/images" "$MW_ORIGIN_FILES/" \
    && mv "$MW_HOME/cache" "$MW_ORIGIN_FILES/" \
    # Move extensions and skins to prefixed directories not intended to be volumed in
    && mv "$MW_HOME/extensions" "$MW_HOME/canasta-extensions" \
    && mv "$MW_HOME/skins" "$MW_HOME/canasta-skins" \
    # Permissions
    && chown "$WWW_USER:$WWW_GROUP" -R "$MW_HOME/canasta-extensions" \
    && chmod g+w -R "$MW_HOME/canasta-extensions" \
    && chown "$WWW_USER:$WWW_GROUP" -R "$MW_HOME/canasta-skins" \
    && chmod g+w -R "$MW_HOME/canasta-skins" \
    # Create symlinks from $MW_VOLUME to the wiki root for images, cache, and public_assets directories
    && ln -s "$MW_VOLUME/images" "$MW_HOME/images" \
    && ln -s "$MW_VOLUME/cache" "$MW_HOME/cache" \
    && ln -s "$MW_VOLUME/public_assets" "$MW_HOME/public_assets"

# Create place where extensions and skins symlinks will live
RUN set -x; \
    mkdir "$MW_HOME/extensions/" \
    && mkdir "$MW_HOME/skins/" \
	&& chown "$WWW_USER:$WWW_GROUP" -R "$MW_HOME/extensions" \
    && chmod g+w -R "$MW_HOME/extensions" \
	&& chown "$WWW_USER:$WWW_GROUP" -R "$MW_HOME/skins" \
    && chmod g+w -R "$MW_HOME/skins"

FROM base AS final

COPY --from=source $MW_HOME $MW_HOME
COPY --from=source $MW_ORIGIN_FILES $MW_ORIGIN_FILES

# Default values
ENV MW_AUTOUPDATE=true \
	MW_MAINTENANCE_UPDATE=0 \
	MW_MAINTENANCE_CIRRUSSEARCH_UPDATECONFIG=2 \
	MW_MAINTENANCE_CIRRUSSEARCH_FORCEINDEX=2 \
	MW_ENABLE_JOB_RUNNER=true \
	MW_JOB_RUNNER_PAUSE=2 \
	MW_JOB_RUNNER_MEMORY_LIMIT=512M \
	MW_ENABLE_POSTFIX=true \
	MW_ENABLE_TRANSCODER=true \
	MW_ENABLE_LOG_ROTATOR=true \
	MW_JOB_TRANSCODER_PAUSE=60 \
	MW_MAP_DOMAIN_TO_DOCKER_GATEWAY=true \
	MW_SITEMAP_PAUSE_DAYS=1 \
	PHP_UPLOAD_MAX_FILESIZE=10M \
	PHP_POST_MAX_SIZE=10M \
	PHP_MAX_INPUT_VARS=1000 \
	PHP_MAX_EXECUTION_TIME=60 \
	PHP_MAX_INPUT_TIME=60 \
	PM_MAX_CHILDREN=25 \
	PM_START_SERVERS=10 \
	PM_MIN_SPARE_SERVERS=5 \
	PM_MAX_SPARE_SERVERS=15 \
	PM_MAX_REQUESTS=2500 \
	LOG_FILES_COMPRESS_DELAY=3600 \
	LOG_FILES_REMOVE_OLDER_THAN_DAYS=10

COPY _sources/configs/mediawiki.conf /etc/apache2/sites-enabled/
COPY _sources/configs/status.conf /etc/apache2/mods-available/
COPY _sources/configs/php_error_reporting.ini _sources/configs/php_upload_max_filesize.ini /etc/php/8.2/cli/conf.d/
COPY _sources/configs/php_error_reporting.ini _sources/configs/php_upload_max_filesize.ini /etc/php/8.2/fpm/conf.d/
COPY _sources/configs/php_max_input_vars.ini _sources/configs/php_max_input_vars.ini /etc/php/8.2/fpm/conf.d/
COPY _sources/configs/php_timeouts.ini /etc/php/8.2/fpm/conf.d/
COPY _sources/configs/php-fpm-www.conf /etc/php/8.2/fpm/pool.d/www.conf
COPY _sources/scripts/*.sh /
COPY _sources/scripts/maintenance-scripts/*.sh /maintenance-scripts/
COPY _sources/scripts/*.php $MW_HOME/maintenance/
COPY _sources/scripts/extensions-skins.php /tmp/
COPY _sources/configs/robots-main.txt _sources/configs/robots.php $WWW_ROOT/
COPY _sources/configs/.htaccess $WWW_ROOT/
COPY _sources/images/favicon.ico $WWW_ROOT/
COPY _sources/canasta/LocalSettings.php _sources/canasta/CanastaDefaultSettings.php _sources/canasta/FarmConfigLoader.php _sources/canasta/CanastaFarm404.php $MW_HOME/
COPY _sources/canasta/getMediawikiSettings.php /
COPY _sources/canasta/canasta_img.php _sources/canasta/public_assets.php $MW_HOME/
COPY _sources/configs/mpm_event.conf /etc/apache2/mods-available/mpm_event.conf

RUN set -x; \
	chmod -v +x /*.sh \
	&& chmod -v +x /maintenance-scripts/*.sh \
	# Comment out ErrorLog and CustomLog parameters, we use rotatelogs in mediawiki.conf for the log files
	&& sed -i 's/^\(\s*ErrorLog .*\)/# \1/g' /etc/apache2/apache2.conf \
	&& sed -i 's/^\(\s*CustomLog .*\)/# \1/g' /etc/apache2/apache2.conf \
	# Make web installer work with Canasta
	&& cp "$MW_HOME/includes/Output/NoLocalSettings.php" "$MW_HOME/includes/CanastaNoLocalSettings.php" \
	&& sed -i 's/MW_CONFIG_FILE/CANASTA_CONFIG_FILE/g' "$MW_HOME/includes/CanastaNoLocalSettings.php" \
	# Modify config
	&& sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
	&& sed -i '/<Directory \/var\/www\/>/i RewriteCond %{THE_REQUEST} \\s(.*?)\\s\nRewriteRule ^ - [E=ORIGINAL_URL:%{REQUEST_SCHEME}://%{HTTP_HOST}%1]' /etc/apache2/apache2.conf \
	&& echo "Alias /w/images/ /var/www/mediawiki/w/canasta_img.php/" >> /etc/apache2/apache2.conf \
    && echo "Alias /w/images /var/www/mediawiki/w/canasta_img.php" >> /etc/apache2/apache2.conf \
	&& echo "Alias /public_assets/ /var/www/mediawiki/w/public_assets.php/" >> /etc/apache2/apache2.conf \
	&& echo "Alias /public_assets /var/www/mediawiki/w/public_assets.php" >> /etc/apache2/apache2.conf \
	&& a2enmod expires remoteip\
	&& a2disconf other-vhosts-access-log \
	# Enable environment variables for FPM workers
	&& sed -i '/clear_env/s/^;//' /etc/php/8.2/fpm/pool.d/www.conf

COPY _sources/images/Powered-by-Canasta.png /var/www/mediawiki/w/resources/assets/

EXPOSE 80
WORKDIR $MW_HOME

HEALTHCHECK --interval=1m --timeout=10s --start-period=5m \
	CMD wget -q --method=HEAD localhost/server-status

CMD ["/run-all.sh"]
