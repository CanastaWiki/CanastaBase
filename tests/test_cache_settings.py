"""Tests for the cache and session settings in CanastaDefaultSettings.php.

The main cache is APCu unless CANASTA_ENABLE_REDIS is true, and sessions
are stored in the database either way.
"""

import json
import os
import re
import shutil
import subprocess

import pytest

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SETTINGS = os.path.join(
    REPO_ROOT, "_sources", "canasta", "CanastaDefaultSettings.php"
)

pytestmark = pytest.mark.skipif(shutil.which("php") is None, reason="needs php")


def cache_section():
    with open(SETTINGS) as f:
        source = f.read()
    start = source.index("# Main cache:")
    end = source.index("# Varnish cache configuration")
    is_env_true = re.search(
        r"^function isEnvTrue\(.*?^}$", source, re.S | re.M
    ).group(0)
    return source[start:end] + "\n" + is_env_true


def settings(env):
    script = (
        "define('CACHE_DB', 'db'); define('CACHE_ACCEL', 'accel');"
        "$wgObjectCaches = [];"
        + cache_section()
        + "echo json_encode(['main' => $wgMainCacheType,"
        " 'redis' => $wgObjectCaches['redis'] ?? null,"
        " 'session' => $wgSessionCacheType]);"
    )
    full_env = {k: v for k, v in os.environ.items() if k != "CANASTA_ENABLE_REDIS"}
    full_env.update(env)
    result = subprocess.run(
        ["php", "-r", script], env=full_env, capture_output=True, text=True,
        check=True,
    )
    return json.loads(result.stdout)


@pytest.mark.parametrize("env", [{}, {"CANASTA_ENABLE_REDIS": "false"}])
def test_apcu_without_redis(env):
    assert settings(env) == {"main": "accel", "redis": None, "session": "db"}


@pytest.mark.parametrize("value", ["true", "1"])
def test_redis_when_enabled(value):
    assert settings({"CANASTA_ENABLE_REDIS": value}) == {
        "main": "redis",
        "redis": {
            "class": "Wikimedia\\ObjectCache\\RedisBagOStuff",
            "servers": ["redis:6379"],
        },
        "session": "db",
    }
