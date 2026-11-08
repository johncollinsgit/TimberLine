"""Protocol-client contracts only; these tests do not run or substitute ClamAV."""

import datetime
import importlib.util
import os
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location("teams_clamav_health", ROOT / "scripts/ops/check-teams-clamav.py")
CLIENT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CLIENT)


def version(age=0):
    stamp = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=age)
    return "ClamAV 1.4.3/12345/" + stamp.strftime("%a %b %d %H:%M:%S %Y")


class ScannerClientContract(unittest.TestCase):
    def test_eicar_must_be_detected_not_merely_pingable(self):
        with patch.object(CLIENT, "command", side_effect=["PONG", version()]), patch.object(CLIENT, "scan", side_effect=["stream: OK", "stream: OK"]):
            with self.assertRaisesRegex(RuntimeError, "not detected"):
                CLIENT.check(3310)

    def test_stale_signatures_fail_before_any_scan(self):
        with patch.object(CLIENT, "command", side_effect=["PONG", version(8)]), patch.object(CLIENT, "scan") as scan:
            with self.assertRaisesRegex(RuntimeError, "stale"):
                CLIENT.check(3310)
            scan.assert_not_called()

    def test_an_oversize_clean_reply_never_passes_boundaries(self):
        with patch.object(CLIENT, "command", side_effect=["PONG", version()]), patch.object(CLIENT, "scan", side_effect=["stream: OK", "stream: Win.Test.EICAR_HDB-1 FOUND", "stream: OK", "stream: OK"]):
            with self.assertRaisesRegex(RuntimeError, "not explicitly enforced"):
                CLIENT.check(3310, boundaries=True)

    def test_truncated_reply_does_not_count_as_clean(self):
        class TruncatedSocket:
            def __init__(self):
                self.parts = iter([b"stream: OK", b""])

            def recv(self, _):
                return next(self.parts)

        with self.assertRaisesRegex(RuntimeError, "NUL-terminated"):
            CLIENT.receive(TruncatedSocket())

    def test_installer_plan_is_read_only_and_shell_is_valid(self):
        script = ROOT / "scripts/ops/install-teams-clamav.sh"
        subprocess.run(["bash", "-n", str(script)], check=True, capture_output=True)
        result = subprocess.run(["bash", str(script), "--plan"], check=True, capture_output=True, text=True)
        self.assertIn("no changes in --plan mode", result.stdout)
        self.assertIn("127.0.0.1:3310", result.stdout)

    @unittest.skipIf(os.geteuid() == 0, "Root guard requires an unprivileged test process")
    def test_unprivileged_apply_refuses_before_installing_anything(self):
        script = ROOT / "scripts/ops/install-teams-clamav.sh"
        result = subprocess.run(["bash", str(script), "--apply"], capture_output=True, text=True)
        self.assertEqual(result.returncode, 77)
        self.assertIn("does not elevate privileges", result.stderr)


class InstallerPreflightContract(unittest.TestCase):
    """Run the production shell functions with inert package/service commands."""

    def function(self, marker):
        source = (ROOT / "scripts/ops/install-teams-clamav.sh").read_text()
        return source.split("# BEGIN " + marker + "\n", 1)[1].split("# END " + marker, 1)[0]

    def preflight(self, candidate="1.4.3+dfsg-0ubuntu1", compare=0, simulate=0, update=0):
        fake = r'''
set -euo pipefail
apt-cache() { printf '  Candidate: %s\n' "$CANDIDATE"; }
dpkg() { printf 'dpkg %s\n' "$*"; return "$COMPARE_STATUS"; }
apt-get() {
  printf 'apt-get %s\n' "$*"
  if [[ "$1" == update ]]; then return "$UPDATE_STATUS"; fi
  [[ "$1" == --simulate ]] || { echo 'Unexpected mutating apt invocation' >&2; return 99; }
  return "$SIMULATE_STATUS"
}
'''
        code = fake + self.function("reviewed package preflight") + "\nprepare_packages\nprintf 'PREFLIGHT_ACCEPTED %s\\n' \"${install_specs[*]}\"\n"
        return subprocess.run(["bash", "-c", code], capture_output=True, text=True, env={**os.environ, "CANDIDATE": candidate, "COMPARE_STATUS": str(compare), "SIMULATE_STATUS": str(simulate), "UPDATE_STATUS": str(update)})

    def test_supported_candidates_are_pinned_and_dependencies_simulated(self):
        result = self.preflight()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("dpkg --compare-versions 1.4.3+dfsg-0ubuntu1 ge 1.4.0", result.stdout)
        self.assertIn("--simulate --no-install-recommends --no-remove install", result.stdout)
        for package in ["clamav", "clamav-daemon", "clamav-freshclam"]:
            self.assertIn(package + "=1.4.3+dfsg-0ubuntu1", result.stdout)

    def test_unsupported_or_missing_candidate_stops_before_dependency_install(self):
        for candidate, compare in [("1.3.1+dfsg-0ubuntu1", 1), ("(none)", 0), ("", 0)]:
            with self.subTest(candidate=candidate):
                result = self.preflight(candidate, compare)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("--simulate", result.stdout)
                self.assertNotIn("PREFLIGHT_ACCEPTED", result.stdout)

    def test_epoch_does_not_make_old_upstream_engine_pass(self):
        result = self.preflight("9:1.3.9+dfsg-0ubuntu1", compare=1)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("dpkg --compare-versions 1.3.9+dfsg-0ubuntu1 ge 1.4.0", result.stdout)
        self.assertNotIn("PREFLIGHT_ACCEPTED", result.stdout)

    def test_metadata_or_simulation_failure_blocks_installation(self):
        for settings in [{"update": 100}, {"simulate": 100}]:
            result = self.preflight(**settings)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn("PREFLIGHT_ACCEPTED", result.stdout)

    def test_bootstrap_is_unprivileged_bounded_and_failure_stops_startup(self):
        fake = r'''
set -euo pipefail
timeout() { printf 'timeout %s\n' "$*"; shift 2; "$@"; }
systemd-run() { printf 'systemd-run %s\n' "$*"; return "$BOOTSTRAP_STATUS"; }
'''
        code = fake + self.function("bounded signature bootstrap") + "\nbootstrap_signatures\necho BOOTSTRAP_ACCEPTED\n"
        for outcome in [0, 1]:
            result = subprocess.run(["bash", "-c", code], capture_output=True, text=True, env={**os.environ, "BOOTSTRAP_STATUS": str(outcome)})
            self.assertEqual(result.returncode, outcome)
            for required in ["--uid=clamav --gid=clamav", "MemoryMax=2G", "CPUQuota=50%", "RuntimeMaxSec=15min", "NoNewPrivileges=yes", "--wait --pipe --collect"]:
                self.assertIn(required, result.stdout)
            self.assertEqual("BOOTSTRAP_ACCEPTED" in result.stdout, outcome == 0)


class AppArmorLocalPolicyContract(unittest.TestCase):
    def setUp(self):
        source = (ROOT / "scripts/ops/install-teams-clamav.sh").read_text()
        helper = source.split("# BEGIN AppArmor local merge helper\n", 1)[1].split("# END AppArmor local merge helper", 1)[0]
        namespace = {}
        exec(compile(helper, "installer-apparmor-merge", "exec"), namespace)
        self.merge = namespace["merge_local_policy"]
        self.rules = ["/etc/clamav/everbranch-freshclam.conf r,", "/etc/clamav/everbranch-clamd.conf r,"]

    def test_existing_local_rules_survive_and_rerun_is_idempotent(self):
        original = "# Existing operator rule\n/srv/operator-extra.conf r,\n"
        merged = self.merge(original, self.rules)
        self.assertTrue(merged.startswith(original))
        self.assertEqual(self.merge(merged, self.rules), merged)
        self.assertEqual(merged.count("# BEGIN Everbranch Teams"), 1)
        for rule in self.rules:
            self.assertIn(rule, merged)
        self.assertNotIn("/etc/clamav/**", merged)

    def test_only_the_managed_block_is_replaced(self):
        old = self.merge("# Before\n", ["/etc/clamav/old-teams.conf r,"]) + "# After\n/srv/unrelated.conf r,\n"
        merged = self.merge(old, self.rules)
        self.assertNotIn("old-teams.conf", merged)
        self.assertTrue(merged.startswith("# Before\n"))
        self.assertTrue(merged.endswith("# After\n/srv/unrelated.conf r,\n"))

    def test_malformed_or_duplicate_markers_refuse_changes(self):
        start = "# BEGIN Everbranch Teams scanner config access v1"
        end = "# END Everbranch Teams scanner config access v1"
        for malformed in [start, end, end + "\n" + start, start + "\n" + start + "\n" + end]:
            with self.assertRaisesRegex(ValueError, "Review"):
                self.merge(malformed, self.rules)


if __name__ == "__main__":
    unittest.main()
