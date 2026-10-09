"""Startup scripts must resolve per-wiki settings for every wiki on a farm,
not just the first wiki that FarmConfigLoader.php falls back to when no
--wiki is given."""

import os
import subprocess
import textwrap


REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SCRIPTS = os.path.join(REPO_ROOT, "_sources", "scripts")

# php stub: answers getMediawikiSettings.php per wiki, keyed by the --wiki
# argument ("" when absent) and logs each call.
PHP_STUB = textwrap.dedent("""
    php() {
        local wiki="" arg
        for arg in "$@"; do
            case $arg in --wiki=*) wiki=${arg#--wiki=} ;; esac
        done
        echo "php $*" >> "$PHP_LOG"
        case " $* " in
            *" --versions "*) echo "hash-${wiki:-default}" ;;
            *"--variable=wgServer"*) server_for "${wiki:-default}" ;;
        esac
    }
""")


def _bash(script, tmp_path):
    env = os.environ.copy()
    env["PHP_LOG"] = str(tmp_path / "php.log")
    return subprocess.run(
        ["bash", "-c", script], env=env, capture_output=True, text=True,
    )


class TestAutoupdateHashPerWiki:

    def _run(self, tmp_path, wiki_ids):
        mw_volume = tmp_path / "mediawiki"
        (mw_volume / "config").mkdir(parents=True)
        script = textwrap.dedent("""
            export MW_VOLUME=%(mw_volume)s
            export MW_VERSION=v MW_CORE_VERSION=c MW_MAINTENANCE_UPDATE=u
            get_mediawiki_db_var() { echo ""; }
            %(php)s
            . %(rms)s
            get_wiki_ids() { printf '%%s' "%(wiki_ids)s"; }
            run_maintenance_script_if_needed() { echo "STAMP $1 $2"; }
            run_autoupdate
        """) % {
            "mw_volume": mw_volume,
            "php": PHP_STUB,
            "rms": os.path.join(SCRIPTS, "run-maintenance-scripts.sh"),
            "wiki_ids": wiki_ids,
        }
        return _bash(script, tmp_path)

    def test_farm_stamps_use_each_wikis_own_hash(self, tmp_path):
        result = self._run(tmp_path, "alpha\nbeta\n")
        assert result.returncode == 0, result.stderr
        assert "STAMP maintenance_update_alpha v-c-u-hash-alpha" in result.stdout
        assert "STAMP maintenance_update_beta v-c-u-hash-beta" in result.stdout

    def test_single_wiki_uses_global_hash(self, tmp_path):
        result = self._run(tmp_path, "")
        assert result.returncode == 0, result.stderr
        assert "STAMP maintenance_update v-c-u-hash-default" in result.stdout


class TestDockerGatewayMapsEveryWiki:

    def _run(self, tmp_path, wiki_ids, servers):
        hosts = tmp_path / "hosts"
        hosts.write_text("127.0.0.1 localhost\n10.0.0.1 old # MW_SITE_HOST\n")
        with open(os.path.join(SCRIPTS, "update-docker-gateway.sh")) as f:
            body = f.read()
        body = body.replace(". /functions.sh", "").replace(
            "/etc/hosts", str(hosts))
        server_cases = " ".join(
            "%s) echo %s ;;" % (k, v) for k, v in servers.items())
        script = textwrap.dedent("""
            export HOME=%(home)s
            export MW_MAP_DOMAIN_TO_DOCKER_GATEWAY=true
            isTrue() { [ "$1" = "true" ]; }
            getent() { :; }
            sed() {
                if [ "$1" = "-i" ]; then shift; command sed -i.bak "$@"
                else command sed "$@"; fi
            }
            get_wiki_ids() { printf '%%s' "%(wiki_ids)s"; }
            get_mediawiki_variable() { php --variable="$1"; }
            server_for() { case $1 in %(servers)s esac; }
            %(php)s
        """) % {
            "home": tmp_path,
            "wiki_ids": wiki_ids,
            "servers": server_cases,
            "php": PHP_STUB,
        } + body
        result = _bash(script, tmp_path)
        return result, hosts.read_text()

    def test_farm_maps_each_distinct_host_once(self, tmp_path):
        result, hosts = self._run(tmp_path, "a\nb\nc\nd\n", {
            "a": "https://one.example.com",
            "b": "https://two.example.com:8443",
            "c": "https://one.example.com/sub",
            "d": "https://10.1.2.3",
        })
        assert result.returncode == 0, result.stderr
        mapped = [l for l in hosts.splitlines() if "# MW_SITE_HOST" in l]
        assert mapped == [
            "172.17.0.1 one.example.com # MW_SITE_HOST",
            "172.17.0.1 two.example.com # MW_SITE_HOST",
        ]
        assert "127.0.0.1 localhost" in hosts

    def test_single_wiki_maps_its_host(self, tmp_path):
        result, hosts = self._run(
            tmp_path, "", {"default": "https://solo.example.com"})
        assert result.returncode == 0, result.stderr
        mapped = [l for l in hosts.splitlines() if "# MW_SITE_HOST" in l]
        assert mapped == ["172.17.0.1 solo.example.com # MW_SITE_HOST"]
