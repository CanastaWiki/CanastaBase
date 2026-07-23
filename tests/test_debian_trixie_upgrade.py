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

    assert "ARG PHP_SERIES=8.4" in dockerfile
    assert "php${PHP_SERIES}-fpm" in dockerfile
    assert "/etc/php/${PHP_SERIES}/fpm" in dockerfile
    assert 'php-fpm"${PHP_SERIES:-8.4}"' in run_php_fpm
    assert "/run/php/php${PHP_SERIES}-fpm.sock" in php_fpm_pool
