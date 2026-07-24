import os


REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))


def _read(path):
    with open(os.path.join(REPO_ROOT, path)) as f:
        return f.read()


def test_dockerfile_uses_debian_13_trixie():
    content = _read("Dockerfile")
    assert "FROM debian:13 AS base" in content
    assert "FROM debian:12.8 AS base" not in content


def test_php_fpm_version_is_configured_via_php_series():
    dockerfile = _read("Dockerfile")
    run_php_fpm = _read("_sources/scripts/run-php-fpm.sh")
    php_fpm_pool = _read("_sources/configs/php-fpm-www.conf")

    assert "ARG PHP_SERIES=8.2" in dockerfile
    assert "https://packages.sury.org/debsuryorg-archive-keyring.deb" in dockerfile
    assert "https://packages.sury.org/php/ trixie main" in dockerfile
    assert "php${PHP_SERIES}-mysql" in dockerfile
    assert "php${PHP_SERIES}-fpm" in dockerfile
    assert "/etc/php/${PHP_SERIES}/fpm" in dockerfile
    assert 'php-fpm"${PHP_SERIES:-8.2}"' in run_php_fpm
    assert "/run/php/php${PHP_SERIES}-fpm.sock" in php_fpm_pool


def test_luasandbox_is_built_for_configured_php_series():
    dockerfile = _read("Dockerfile")

    assert "php${PHP_SERIES}-luasandbox" not in dockerfile
    assert "ARG LUASANDBOX_VERSION=4.1.3" in dockerfile
    assert "mediawiki-php-luasandbox/archive/refs/tags/${LUASANDBOX_VERSION}.tar.gz" in dockerfile
    assert '${LUASANDBOX_SHA256}  /tmp/luasandbox.tar.gz' in dockerfile
    assert "phpize${PHP_SERIES}" in dockerfile
    assert "--with-php-config=/usr/bin/php-config${PHP_SERIES}" in dockerfile
    assert "phpenmod -v \"${PHP_SERIES}\" luasandbox" in dockerfile
