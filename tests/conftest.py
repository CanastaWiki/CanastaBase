"""Shared fixtures for CanastaBase tests."""

import os
import shutil
import subprocess

import pytest


REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SCRIPT = os.path.join(REPO_ROOT, "_sources", "scripts", "config-subdir-wikis.sh")


@pytest.fixture
def workspace(tmp_path):
    """Per-test temp dir tree mirroring the canasta-image layout that
    config-subdir-wikis.sh interacts with:

        <workspace>/
            mediawiki/
                config/
                    wikis.yaml          <- WIKIS_YAML
            www/                         <- WWW_ROOT
                .htaccess               <- seeded with the production .htaccess
                w/                       <- MW_HOME
            apache2.conf                 <- APACHE_CONF (initially empty)
    """
    mw_volume = tmp_path / "mediawiki"
    mw_config = mw_volume / "config"
    mw_config.mkdir(parents=True)

    www_root = tmp_path / "www"
    www_root.mkdir()
    mw_home = www_root / "w"
    mw_home.mkdir()

    # Seed a sample .htaccess so the subdir-wiki branch has something
    # to rewrite. Mirrors the real _sources/configs/.htaccess shape.
    (www_root / ".htaccess").write_text(
        "RewriteEngine On\n"
        "RewriteRule ^/?w/rest.php/ - [L]\n"
        "RewriteRule ^/?w/img_auth.php/ - [L]\n"
        "RewriteRule ^/*$ %{DOCUMENT_ROOT}/w/index.php [L]\n"
        "RewriteRule ^/?[^/]+/w/(load|api|rest|index|img_auth)\\.php(.*)$"
        " %{DOCUMENT_ROOT}/w/$1.php$2 [L,QSA]\n"
        "RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-f\n"
        "RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-d\n"
        "RewriteRule ^(.*)$ %{DOCUMENT_ROOT}/w/index.php [L]\n"
    )

    apache_conf = tmp_path / "apache2.conf"
    apache_conf.touch()

    return {
        "tmp_path": tmp_path,
        "mw_volume": mw_volume,
        "wikis_yaml": mw_config / "wikis.yaml",
        "www_root": www_root,
        "mw_home": mw_home,
        "apache_conf": apache_conf,
    }


def write_wikis(wikis_yaml, wikis):
    """Write a wikis list to wikis.yaml in the canonical canasta format.

    `wikis` is a list of dicts with at least `id` and `url` keys.
    """
    lines = ["wikis:"]
    for w in wikis:
        lines.append("- id: %s" % w["id"])
        lines.append("  url: %s" % w["url"])
        if "name" in w:
            lines.append("  name: %s" % w["name"])
    wikis_yaml.write_text("\n".join(lines) + "\n")


def run_script(workspace):
    """Run config-subdir-wikis.sh against the workspace fixture and
    return the resulting (apache_conf_text, completed_process)."""
    env = os.environ.copy()
    env.update({
        "WIKIS_YAML": str(workspace["wikis_yaml"]),
        "APACHE_CONF": str(workspace["apache_conf"]),
        "WWW_ROOT": str(workspace["www_root"]),
        "MW_HOME": str(workspace["mw_home"]),
        "MW_VOLUME": str(workspace["mw_volume"]),
    })
    result = subprocess.run(
        ["bash", SCRIPT],
        env=env,
        capture_output=True,
        text=True,
    )
    return workspace["apache_conf"].read_text(), result


@pytest.fixture
def script_runner(workspace):
    """Convenience: returns a callable that writes a wikis.yaml and runs
    the script in one step. The callable returns
    (apache_conf_text, CompletedProcess) so tests can assert on both."""
    def _run(wikis):
        write_wikis(workspace["wikis_yaml"], wikis)
        return run_script(workspace)
    _run.workspace = workspace
    return _run
