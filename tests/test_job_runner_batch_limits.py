"""The general job batch in mw_job_runner.sh is bounded by
MW_JOB_RUNNER_MAXTIME, and also by MW_JOB_RUNNER_MAXJOBS when it is set.
runJobs.php reads --maxtime=0 as no time limit."""

import os
import subprocess
import textwrap

import pytest


REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SCRIPT = os.path.join(
    REPO_ROOT, "_sources", "scripts", "maintenance-scripts", "mw_job_runner.sh"
)


def _general_batch_args(tmp_path, maxtime, maxjobs):
    calls = tmp_path / "php_calls"
    env = os.environ.copy()
    env.update({
        "MW_HOME": "/mw",
        "MW_VOLUME": str(tmp_path / "mediawiki"),
        "MW_JOB_RUNNER_PAUSE": "stop",
        "MW_JOB_RUNNER_MAXTIME": maxtime,
        "MW_JOB_RUNNER_MAXJOBS": maxjobs,
        "CALLS": str(calls),
    })
    wrapper = textwrap.dedent("""
        php() { echo "$*" >> "$CALLS"; }
        sleep() { [ "$1" = stop ] && exit 0; :; }
        export -f php sleep
        bash %s
    """) % SCRIPT
    result = subprocess.run(
        ["bash", "-c", wrapper], env=env, capture_output=True, text=True,
        timeout=30,
    )
    assert result.returncode == 0, result.stderr
    general = [c for c in calls.read_text().splitlines() if "--type=" not in c]
    assert len(general) == 1, general
    return general[0].split()[1:]


@pytest.mark.parametrize("maxtime,maxjobs,expected", [
    ("60", "", ["--maxtime=60"]),
    ("30", "500", ["--maxtime=30", "--maxjobs=500"]),
    ("0", "10", ["--maxtime=0", "--maxjobs=10"]),
    ("", "10", ["--maxjobs=10"]),
])
def test_general_batch_limits(tmp_path, maxtime, maxjobs, expected):
    assert _general_batch_args(tmp_path, maxtime, maxjobs) == expected


def test_dockerfile_defaults_bound_batch_by_time():
    with open(os.path.join(REPO_ROOT, "Dockerfile")) as f:
        dockerfile = f.read()
    assert "MW_JOB_RUNNER_MAXTIME=60 \\" in dockerfile
    assert "MW_JOB_RUNNER_MAXJOBS= \\" in dockerfile
