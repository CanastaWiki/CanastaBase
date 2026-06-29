"""Tests for the composer.local.json merge + dependency self-test helpers
added for issue #186.

merge-composer-local.php merges the user's volume config/composer.local.json
ON TOP OF the build-time baked copy, so a stale or hand-edited volume copy can
add entries but can never drop a bundled one. verify-composer-deps.php warns
when a merged-in extension requires a package that is absent from vendor/.
"""

import json
import os
import shutil
import subprocess

import pytest


REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
MERGE = os.path.join(REPO_ROOT, "_sources", "scripts", "merge-composer-local.php")
VERIFY = os.path.join(REPO_ROOT, "_sources", "scripts", "verify-composer-deps.php")

pytestmark = pytest.mark.skipif(
    shutil.which("php") is None, reason="php not available"
)


def _write_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data))


def _include(*paths):
    return {"extra": {"merge-plugin": {"include": list(paths)}}}


def _run_merge(baked, user, out):
    return subprocess.run(
        ["php", MERGE, str(baked), str(user), str(out)],
        capture_output=True, text=True,
    )


# ---------------------------------------------------------------------------
# merge-composer-local.php
# ---------------------------------------------------------------------------


def test_stale_user_cannot_drop_bundled_include(tmp_path):
    """The regression: a frozen volume copy missing Elastica must NOT remove
    it from the merged result — the baked list is authoritative."""
    baked = tmp_path / "baked.json"
    user = tmp_path / "user.json"
    out = tmp_path / "out.json"
    _write_json(baked, _include(
        "extensions/Elastica/composer.json",
        "extensions/SemanticMediaWiki/composer.json",
    ))
    # Stale user copy predates Elastica/SMW being bundled.
    _write_json(user, _include("extensions/SomethingOld/composer.json"))

    result = _run_merge(baked, user, out)
    assert result.returncode == 0, result.stderr

    merged = json.loads(out.read_text())["extra"]["merge-plugin"]["include"]
    assert "extensions/Elastica/composer.json" in merged
    assert "extensions/SemanticMediaWiki/composer.json" in merged
    # User additions are preserved too.
    assert "extensions/SomethingOld/composer.json" in merged


def test_user_only_when_baked_missing(tmp_path):
    """If the baked copy is absent (pre-unified-composer image), fall back to
    the user copy."""
    user = tmp_path / "user.json"
    out = tmp_path / "out.json"
    _write_json(user, _include("extensions/Foo/composer.json"))

    result = _run_merge(tmp_path / "nope.json", user, out)
    assert result.returncode == 0, result.stderr
    merged = json.loads(out.read_text())["extra"]["merge-plugin"]["include"]
    assert merged == ["extensions/Foo/composer.json"]


def test_no_user_yields_baked(tmp_path):
    baked = tmp_path / "baked.json"
    out = tmp_path / "out.json"
    _write_json(baked, _include("extensions/Elastica/composer.json"))

    result = _run_merge(baked, tmp_path / "absent.json", out)
    assert result.returncode == 0, result.stderr
    merged = json.loads(out.read_text())["extra"]["merge-plugin"]["include"]
    assert merged == ["extensions/Elastica/composer.json"]


def test_no_duplicate_includes(tmp_path):
    baked = tmp_path / "baked.json"
    user = tmp_path / "user.json"
    out = tmp_path / "out.json"
    _write_json(baked, _include("extensions/Elastica/composer.json"))
    _write_json(user, _include("extensions/Elastica/composer.json"))

    _run_merge(baked, user, out)
    merged = json.loads(out.read_text())["extra"]["merge-plugin"]["include"]
    assert merged == ["extensions/Elastica/composer.json"]


def test_require_and_repositories_preserved(tmp_path):
    baked = tmp_path / "baked.json"
    user = tmp_path / "user.json"
    out = tmp_path / "out.json"
    _write_json(baked, _include("extensions/Elastica/composer.json"))
    user_data = _include("extensions/Mine/composer.json")
    user_data["require"] = {"vendor/pkg": "^1.0"}
    user_data["repositories"] = [{"type": "vcs", "url": "https://example.test/repo"}]
    _write_json(user, user_data)

    _run_merge(baked, user, out)
    merged = json.loads(out.read_text())
    assert merged["require"] == {"vendor/pkg": "^1.0"}
    assert merged["repositories"][0]["url"] == "https://example.test/repo"
    assert "extensions/Elastica/composer.json" in \
        merged["extra"]["merge-plugin"]["include"]


# ---------------------------------------------------------------------------
# verify-composer-deps.php
# ---------------------------------------------------------------------------


def _setup_mw_home(tmp_path, includes, ext_requires, installed_names):
    """Build a fake MW_HOME with composer.local.json, extension composer.json
    files, and vendor/composer/installed.json."""
    mw_home = tmp_path / "w"
    (mw_home).mkdir()
    _write_json(mw_home / "composer.local.json", _include(*includes))
    for rel, require in ext_requires.items():
        _write_json(mw_home / rel, {"require": require})
    installed = {"packages": [{"name": n} for n in installed_names]}
    _write_json(mw_home / "vendor" / "composer" / "installed.json", installed)
    return mw_home


def _run_verify(mw_home):
    return subprocess.run(
        ["php", VERIFY, str(mw_home)], capture_output=True, text=True
    )


def test_verify_warns_on_missing_package(tmp_path):
    mw_home = _setup_mw_home(
        tmp_path,
        includes=["extensions/Elastica/composer.json"],
        ext_requires={"extensions/Elastica/composer.json":
                      {"ruflin/elastica": "^7.0", "php": ">=8.1"}},
        installed_names=["some/other-package"],
    )
    result = _run_verify(mw_home)
    assert result.returncode == 0
    assert "ruflin/elastica" in result.stderr
    assert "Elastica" in result.stderr


def test_verify_silent_when_satisfied(tmp_path):
    mw_home = _setup_mw_home(
        tmp_path,
        includes=["extensions/Elastica/composer.json"],
        ext_requires={"extensions/Elastica/composer.json":
                      {"ruflin/elastica": "^7.0", "php": ">=8.1",
                       "ext-curl": "*"}},
        installed_names=["ruflin/elastica"],
    )
    result = _run_verify(mw_home)
    assert result.returncode == 0
    assert result.stderr.strip() == ""


def test_verify_accepts_provided_package(tmp_path):
    """A virtual package satisfied via another package's 'provide' must not
    read as missing."""
    mw_home = tmp_path / "w"
    mw_home.mkdir()
    _write_json(mw_home / "composer.local.json",
                _include("extensions/Foo/composer.json"))
    _write_json(mw_home / "extensions" / "Foo" / "composer.json",
                {"require": {"psr/log": "^3.0"}})
    _write_json(
        mw_home / "vendor" / "composer" / "installed.json",
        {"packages": [{"name": "monolog/monolog", "provide": {"psr/log": "*"}}]},
    )
    result = _run_verify(mw_home)
    assert result.returncode == 0
    assert result.stderr.strip() == ""
