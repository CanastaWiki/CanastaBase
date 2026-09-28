"""Tests for _sources/configs/writable-dirs.conf.

Every directory under the docroot that the web server user can write to
must refuse direct requests for PHP files and ignore .htaccess. The set
of directories is derived from the Dockerfile, so a directory that
becomes web-writable without a matching block fails here.
"""

import os
import re

import pytest

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
DOCKERFILE = os.path.join(REPO_ROOT, "Dockerfile")
CONF = os.path.join(REPO_ROOT, "_sources", "configs", "writable-dirs.conf")

MW_HOME = "/var/www/mediawiki/w"
MW_VOLUME = "/mediawiki"
# Host mounts added by the Compose and Helm definitions, not the Dockerfile.
MOUNTED = ["user-extensions", "user-skins"]


def read(path):
    with open(path) as f:
        return f.read()


def conf_blocks():
    """Map each <Directory> path in the conf to the text of its block."""
    return dict(
        re.findall(r"<Directory (\S+)>(.*?)</Directory>", read(CONF), re.S)
    )


def web_writable_dirs():
    dockerfile = read(DOCKERFILE)
    chowned = re.findall(
        r'chown "\$WWW_USER:\$WWW_GROUP" -R "\$MW_HOME/([\w-]+)"', dockerfile
    )
    linked = re.findall(
        r'ln -s "\$MW_VOLUME/([\w-]+)" "\$MW_HOME/\1"', dockerfile
    )
    assert chowned and linked, "Dockerfile patterns no longer match"
    dirs = {f"{MW_HOME}/{d}" for d in chowned + linked + MOUNTED}
    dirs |= {f"{MW_VOLUME}/{d}" for d in linked}
    return sorted(dirs)


@pytest.mark.parametrize("path", web_writable_dirs())
def test_writable_dir_has_block(path):
    blocks = conf_blocks()
    assert path in blocks, f"no <Directory {path}> block in writable-dirs.conf"
    block = blocks[path]
    assert re.search(r"^\s*AllowOverride None\s*$", block, re.M)
    assert re.search(
        r'<FilesMatch "[^"]+">\s*Require all denied\s*</FilesMatch>', block
    )


def php_pattern():
    patterns = set(re.findall(r'<FilesMatch "([^"]+)">', read(CONF)))
    assert len(patterns) == 1, "blocks should share one FilesMatch pattern"
    return re.compile(patterns.pop())


@pytest.mark.parametrize(
    "name",
    ["x.php", "x.PHP", "x.Php", "x.php7", "x.phtml", "x.phar", "x.pht",
     "x.phps", "a.b.php"],
)
def test_pattern_denies_php(name):
    assert php_pattern().search(name)


@pytest.mark.parametrize(
    "name",
    ["x.js", "x.css", "x.less", "x.svg", "x.png", "x.json", "x.txt",
     "php.js", "x.php.txt"],
)
def test_pattern_allows_assets(name):
    assert not php_pattern().search(name)


def test_dockerfile_enables_conf():
    dockerfile = read(DOCKERFILE)
    assert (
        "COPY _sources/configs/writable-dirs.conf /etc/apache2/conf-available/"
        in dockerfile
    )
    assert "a2enconf writable-dirs" in dockerfile
