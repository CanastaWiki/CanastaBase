#!/bin/bash

# This script is run by GitHub Actions workflow
# It requires MariaDB and Redis to be installed via supercharge/redis-github-action and getong/mariadb-action
# priori to running the script and the `host.docker.internal` to be bind via `--add-host` docker param
# The script is executed within the container

# Setup
echo "Installing system packages.."
apt update -qq > /dev/null 2>&1
apt install -y nodejs npm -qq > /dev/null 2>&1

echo "Installing Composer dependencies..."
rm -rf vendor
composer -n --quiet update > /dev/null 2>&1

echo "Installing test database..."
php maintenance/install.php \
  --scriptpath '' \
  --dbtype mysql \
  --dbuser root \
  --dbname mediawiki \
  --dbpass mediawiki \
  --pass AdminPassword \
  --dbport 3306 \
  --dbserver host.docker.internal \
  --installdbuser root \
  --installdbpass mediawiki \
  --skins Vector \
  WikiName \
  AdminUser > /dev/null 2>&1

echo "Configuring test LocalSettings file..."
echo 'error_reporting(0);' >> LocalSettings.php
#echo 'wfLoadExtension("Bootstrap");' >> LocalSettings.php
echo '$wgShowExceptionDetails = false;' >> LocalSettings.php
echo '$wgShowDBErrorBacktrace = false;' >> LocalSettings.php
echo '$wgDevelopmentWarnings = false;' >> LocalSettings.php
echo '$wgObjectCaches["redis"] = [ "class" => "RedisBagOStuff", "servers" => [ "host.docker.internal:6379" ] ];' >> LocalSettings.php
echo '$wgMainCacheType = "redis";' >> LocalSettings.php
echo '$wgSessionCacheType = "redis";' >> LocalSettings.php
echo '$wgPhpCli = "/usr/bin/php";' >> LocalSettings.php

echo "Running database updates..."
php maintenance/update.php --quick > /dev/null 2>&1

# Lint
# composer run-script test

# PHPUnit
echo "Running tests..."

# PHPUnit core unit tests only (no extensions/skins in base image)
composer run --timeout=0 phpunit -- --testsuite core:unit --exclude-group Broken,ParserFuzz,Stub

#composer run phpunit -- --exclude-group Broken,ParserFuzz,Stub --stop-on-failure --stop-on-error

# Qunit
