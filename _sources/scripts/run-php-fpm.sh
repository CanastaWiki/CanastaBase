#!/bin/bash

set -x

echo "starting php-fpm"
# Running php-fpm
mkdir -p /run/php
exec /usr/sbin/php-fpm"${PHP_SERIES:-8.2}"